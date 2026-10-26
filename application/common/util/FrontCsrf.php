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

use think\Session;

/** Session-bound native frontend form tokens; never place token responses in page caches. */
final class FrontCsrf
{
    public static function response()
    {
        return json(['code'=>1, '__token__'=>Request()->token()])
            ->header(['Cache-Control'=>'no-store, private', 'Pragma'=>'no-cache', 'X-Content-Type-Options'=>'nosniff']);
    }

    public static function check(array $posted)
    {
        $given = $posted['__token__'] ?? null;
        $expected = Session::get('__token__');
        if (!is_string($given) || !is_string($expected) || $given === '' || $expected === '' || !hash_equals($expected, $given)) {
            return false;
        }
        Session::delete('__token__');
        return true;
    }
}
