<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\admin\common;

/**
 * CSRF check for the write actions of the member / log / card / order / withdrawal lists
 * (delete, delete all, status switch, approve withdrawal) and the member editor save. The views send the
 * session admin token (mac_admin_csrf_token()) as __token__ or in the X-CSRF-Token header.
 */
trait ListCsrf
{
    protected function listCsrfOk()
    {
        $sent = input('__token__', '');
        $sent = is_string($sent) ? trim($sent) : '';
        if ($sent === '') {
            $sent = trim((string)request()->header('X-CSRF-Token'));
        }
        $expected = function_exists('mac_admin_csrf_token') ? (string)mac_admin_csrf_token() : '';
        return $sent !== '' && $expected !== '' && hash_equals($expected, $sent);
    }

    /** Deletes and editor saves: POST only (a GET link never changes data), plus the token. */
    protected function listPostOk()
    {
        return request()->isPost() && $this->listCsrfOk();
    }
}
