<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
// Admin module language pack. ThinkPHP loads it after application/lang/<lang>.php, so its keys win.
// The strings live in part files under application/admin/lang/<lang>/*.php, merged in file-name order
// (a later file wins). New admin strings use keys under "admin/ux/"; until such a key is translated, the
// other languages show its English text instead of the raw key. The closure keeps the loader's
// variables out of think\Lang::load(). This file is identical for every language.
return (function ($base, $lang) {
    $strings = [];
    if ($lang !== 'en-us') {
        foreach (glob($base . '/en-us/*.php') ?: [] as $part) {
            $values = include $part;
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    if (strncmp((string) $key, 'admin/ux/', 9) === 0) {
                        $strings[$key] = $value;
                    }
                }
            }
        }
    }
    foreach (glob($base . '/' . $lang . '/*.php') ?: [] as $part) {
        $values = include $part;
        if (is_array($values)) {
            $strings = array_merge($strings, $values);
        }
    }
    return $strings;
})(__DIR__, basename(__FILE__, '.php'));
