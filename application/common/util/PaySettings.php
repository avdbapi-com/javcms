<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\util;

/** Settings and local prerequisites only; never initiates a payment. */
class PaySettings
{
    const PROVIDERS = ['alipay','codepay','weixin','zhapay'];

    private static function invalid() { throw new \InvalidArgumentException('admin/paysetup/invalid'); }

    private static function mergeNode(array $current, array $posted, $depth = 0)
    {
        if ($depth > 8 || count($posted) > 100) { self::invalid(); }
        foreach ($posted as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $key)) { self::invalid(); }
            if (is_array($value)) {
                if (isset($current[$key]) && !is_array($current[$key])) { self::invalid(); }
                $current[$key] = self::mergeNode((array)($current[$key] ?? []), $value, $depth + 1);
            } else {
                if (!is_string($value) || strlen($value) > 16384 || preg_match('/[\x00-\x1f\x7f]/', $value)) { self::invalid(); }
                if (isset($current[$key]) && is_array($current[$key])) { self::invalid(); }
                $current[$key] = $value;
            }
        }
        return $current;
    }

    public static function merge(array $current, array $posted)
    {
        foreach (['min','scale'] as $field) {
            if (isset($posted[$field]) && is_string($posted[$field]) && isset($current[$field]) && $posted[$field] === (string)$current[$field]) { continue; }
            if (array_key_exists($field, $posted) && (!is_string($posted[$field]) || !preg_match('/^(?:[0-9]+)(?:\.[0-9]+)?$/D', $posted[$field]) || !is_finite((float)$posted[$field]) || (float)$posted[$field] <= 0)) { self::invalid(); }
        }
        foreach (['card','alipay','codepay','weixin','zhapay'] as $field) {
            if (isset($posted[$field]) && !is_array($posted[$field])) { self::invalid(); }
        }
        $fields = ['card'=>['url'], 'alipay'=>['account','appid','appkey'], 'codepay'=>['appid','appkey','type','act','ac'], 'weixin'=>['appid','mchid','appkey'], 'zhapay'=>['appid','appkey','type','act']];
        foreach ($fields as $provider=>$names) {
            foreach ($names as $name) {
                if (array_key_exists($name, $posted[$provider] ?? []) && !is_string($posted[$provider][$name])) { self::invalid(); }
            }
        }
        // The old form exposed act while the adapter consumes ac. Never alias the two.
        if (($posted['codepay']['ac'] ?? null) === '' && !array_key_exists('ac', $current['codepay'] ?? [])) { unset($posted['codepay']['ac']); }
        $next = self::mergeNode($current, $posted);
        if (isset($posted['card']['url']) && $posted['card']['url'] !== '') {
            $url = $posted['card']['url'];
            // Preserve previously configured custom paths unchanged; validate intentional URL edits.
            if ($url !== ($current['card']['url'] ?? '')) {
                $parts = parse_url($url);
                if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) { self::invalid(); }
            }
        }
        return $next;
    }

    public static function callbackPreview($host, $scheme, $provider)
    {
        if (!in_array($provider, self::PROVIDERS, true)) { return ''; }
        $root = defined('MAC_PATH') ? MAC_PATH : '/';
        if (!is_string($root)) { return ''; }
        if ($root === '') { $root = '/'; }
        if ($root[0] !== '/' || substr($root,0,2)==='//' || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $root) || strpbrk($root, '?#!\\') !== false) { return ''; }
        $path = rtrim($root, '/') . '/index.php/payment/notify/pay_type/' . $provider;
        return UrlSettings::canonicalUrl($host, $path, $scheme);
    }

    public static function availability()
    {
        $result = [];
        foreach (self::PROVIDERS as $provider) {
            $missing = [];
            if (!class_exists('app\\common\\extend\\pay\\' . ucfirst($provider))) { $missing[] = 'adapter'; }
            if ($provider === 'weixin') {
                foreach (['curl','simplexml','gd'] as $extension) { if (!extension_loaded($extension)) { $missing[] = $extension; } }
                if (!class_exists('app\\common\\util\\Qrcode')) { $missing[] = 'Qrcode'; }
            }
            $result[$provider] = ['available'=>!$missing, 'missing'=>$missing];
        }
        return $result;
    }
}
