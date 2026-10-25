<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

/** Rich article presentation only; stored content and trusted site analytics are unchanged. */
class TemplateContent
{
    private static function uri($value)
    {
        $value = trim((string)$value);
        if ($value === '' || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $value)) { return ''; }
        if (preg_match('~^https?://~i', $value)) { return filter_var($value, FILTER_VALIDATE_URL) ? $value : ''; }
        if (substr($value,0,1)==='/' && substr($value,0,2)!=='//') { return $value; }
        if (substr($value,0,1)==='#') { return $value; }
        return strpos($value, ':') === false && substr($value,0,2)!=='//' ? $value : '';
    }

    public static function html($source)
    {
        if (!is_scalar($source)) { return ''; }
        $source = substr((string)$source, 0, 1048576);
        if (!class_exists('DOMDocument')) {
            return nl2br(htmlspecialchars(strip_tags($source), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        $document = new \DOMDocument('1.0','UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $source . '</body></html>', LIBXML_NONET);
            if (!$loaded) { return ''; }
            $body = $document->getElementsByTagName('body')->item(0);
            if (!$body) { return ''; }
            self::clean($body);
            $html = '';
            foreach ($body->childNodes as $child) { $html .= $document->saveHTML($child); }
            return $html;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    private static function clean(\DOMNode $parent)
    {
        $allow = ['p','br','ul','ol','li','blockquote','strong','b','em','i','u','s','del','h2','h3','h4','h5','h6',
            'table','thead','tbody','tfoot','tr','th','td','a','img','figure','figcaption','pre','code','span','div','hr'];
        $drop = ['script','style','iframe','object','embed','link','meta','base','form','input','button','textarea','select',
            'option','svg','math','video','audio','source','track','template','noscript','title'];
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof \DOMComment || $node instanceof \DOMProcessingInstruction) { $parent->removeChild($node); continue; }
            if (!($node instanceof \DOMElement)) { continue; }
            $name = strtolower($node->tagName);
            if (in_array($name,$drop,true) || strpos($name,':')!==false) { $parent->removeChild($node); continue; }
            self::clean($node);
            if (!in_array($name,$allow,true)) {
                while ($node->firstChild) { $parent->insertBefore($node->firstChild,$node); }
                $parent->removeChild($node); continue;
            }
            $attributes = [];
            foreach ($node->attributes as $attribute) { $attributes[$attribute->name]=$attribute->value; }
            foreach (array_keys($attributes) as $attribute) { $node->removeAttribute($attribute); }
            foreach (['title','alt'] as $attribute) {
                if (isset($attributes[$attribute]) && ($attribute!=='alt' || $name==='img')) { $node->setAttribute($attribute,substr($attributes[$attribute],0,1000)); }
            }
            // Language and direction of a block (legal pages mark their English text, core-polish): strict values only.
            if (isset($attributes['dir']) && in_array(strtolower($attributes['dir']), ['ltr','rtl','auto'], true)) { $node->setAttribute('dir', strtolower($attributes['dir'])); }
            if (isset($attributes['lang']) && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8}){0,3}$/D', $attributes['lang'])) { $node->setAttribute('lang', $attributes['lang']); }
            if ($name==='a') {
                $url=self::uri($attributes['href']??'');
                if ($url!=='') { $node->setAttribute('href',$url); $node->setAttribute('rel','noopener noreferrer'); }
            }
            if ($name==='img') {
                $url=self::uri($attributes['src']??'');
                if ($url==='' || substr($url,0,1)==='#') { $parent->removeChild($node); continue; }
                $node->setAttribute('src',$url);$node->setAttribute('loading','lazy');$node->setAttribute('decoding','async');$node->setAttribute('referrerpolicy','no-referrer');
            }
            foreach (['width','height','colspan','rowspan'] as $attribute) {
                if (isset($attributes[$attribute]) && preg_match('/^[1-9][0-9]{0,3}$/D',$attributes[$attribute])) { $node->setAttribute($attribute,$attributes[$attribute]); }
            }
        }
    }
}
