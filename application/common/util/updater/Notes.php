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
namespace app\common\util\updater;

/**
 * Release notes for the Updates page: a small, safe Markdown subset turned into HTML.
 *
 * Every character of the notes is escaped first; only these forms become markup again: headings (#, ##, ###, ####; the
 * highest level the notes use becomes h3, under the h2 of the page's card, and the others follow in order), lists
 * (-, *, 1.), paragraphs, fenced code blocks (```), `code`, **bold**, *italic* and links [text](https://...) whose address
 * starts with https:// (rel="noopener noreferrer nofollow", new tab). Raw HTML in the notes stays visible text. The notes
 * come from the signed manifest (or GitHub's release text when the manifest has none) and are cut to 60,000 characters.
 */
final class Notes
{
    public static function render($md)
    {
        $md = str_replace(array("\r\n", "\r"), "\n", Util::cut((string)$md, 60000));
        $lines = explode("\n", $md);
        // heading levels: the highest one in the notes becomes h3 (headings never skip a level below the card's h2)
        $min = 9;
        $fenced = false;
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line)) {
                $fenced = !$fenced;
                continue;
            }
            if (!$fenced && preg_match('/^(#{1,4})\s+\S/', rtrim($line), $m)) {
                $min = min($min, strlen($m[1]));
            }
        }
        $html = array();
        $para = array();
        $list = '';
        $code = null;
        $flushPara = function () use (&$para, &$html) {
            if ($para) {
                $html[] = '<p>' . implode(' ', $para) . '</p>';
                $para = array();
            }
        };
        $closeList = function () use (&$list, &$html) {
            if ($list !== '') {
                $html[] = '</' . $list . '>';
                $list = '';
            }
        };
        foreach ($lines as $line) {
            if ($code !== null) {
                if (preg_match('/^\s*```\s*$/', $line)) {
                    $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                    $code = null;
                } elseif (count($code) < 400) {
                    $code[] = $line;
                }
                continue;
            }
            $t = rtrim($line);
            if (preg_match('/^\s*```/', $t)) {
                $flushPara();
                $closeList();
                $code = array();
                continue;
            }
            if (trim($t) === '') {
                $flushPara();
                $closeList();
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.*)$/', $t, $m)) {
                $flushPara();
                $closeList();
                $level = min(6, 3 + strlen($m[1]) - ($min > 4 ? 1 : $min));
                $html[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }
            if (preg_match('/^\s*[-*+]\s+(.*)$/', $t, $m) || preg_match('/^\s*\d{1,3}[.)]\s+(.*)$/', $t, $m2)) {
                $flushPara();
                $type = isset($m[1]) ? 'ul' : 'ol';
                $text = isset($m[1]) ? $m[1] : $m2[1];
                if ($list !== $type) {
                    $closeList();
                    $html[] = '<' . $type . '>';
                    $list = $type;
                }
                $html[] = '<li>' . self::inline($text) . '</li>';
                continue;
            }
            $closeList();
            $para[] = self::inline(trim($t));
        }
        if ($code !== null) {
            // a code block that was never closed is shown as it is
            $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $code), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        }
        $flushPara();
        $closeList();
        return implode("\n", $html);
    }

    /** One line: escape everything, then re-create code, bold, italic and https links. */
    private static function inline($text)
    {
        $codes = array();
        $text = preg_replace_callback('/`([^`]{1,300})`/', function ($m) use (&$codes) {
            $codes[] = '<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>';
            return "\x01" . (count($codes) - 1) . "\x02";
        }, (string)$text);
        $links = array();
        // an image ("![alt](https://...)") becomes a link to it (the notes show no pictures): never "!" before a link
        $text = preg_replace_callback('/!?\[([^\]]{0,200})\]\((https:\/\/[^\s)"\'<>]{1,500})\)/', function ($m) use (&$links) {
            $label = $m[1] !== '' ? $m[1] : basename((string)parse_url($m[2], PHP_URL_PATH));
            $links[] = '<a href="' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer nofollow">'
                . htmlspecialchars($label !== '' ? $label : $m[2], ENT_QUOTES, 'UTF-8') . '</a>';
            return "\x03" . (count($links) - 1) . "\x04";
        }, $text);
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\*\*([^*]{1,300})\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(^|[\s(])\*([^*\s][^*]{0,200})\*(?=$|[\s).,;:!?])/', '$1<em>$2</em>', $text);
        $text = preg_replace_callback("/\x01(\d+)\x02/", function ($m) use ($codes) {
            return $codes[(int)$m[1]] ?? '';
        }, $text);
        $text = preg_replace_callback("/\x03(\d+)\x04/", function ($m) use ($links) {
            return $links[(int)$m[1]] ?? '';
        }, $text);
        return (string)$text;
    }
}
