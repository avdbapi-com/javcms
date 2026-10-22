<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */

/** Emit the site's baseline browser security headers once per request. */
final class JavcmsSecurityHeaders
{
    /**
     * HSTS (Strict-Transport-Security) default for every install: one week, this host only.
     * - Sent only on HTTPS responses (browsers ignore it on plain HTTP anyway).
     * - A short max-age means a site that loses its certificate, or moves back to HTTP, recovers for returning
     *   visitors within 7 days instead of a year.
     * - No includeSubDomains and no preload: on a bare domain (example.com) includeSubDomains would force HTTPS on
     *   every sub-domain (webmail., cpanel., old HTTP-only sub-sites), and preload is a browser list that takes months
     *   to leave. Both break sites, so neither is on by default.
     * When your whole domain and every sub-domain work on HTTPS, you can make it stronger by editing the line below:
     *   'max-age=31536000; includeSubDomains'            (one year, all sub-domains)
     *   'max-age=63072000; includeSubDomains; preload'   (two years; only before you submit the domain to hstspreload.org)
     * Do not also add an HSTS header in your server or hosting panel: browsers would see two headers.
     */
    const HSTS = 'max-age=604800';

    public static function send(): void
    {
        if (headers_sent()) return;
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        $https = (isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
        if ($https) header('Strict-Transport-Security: ' . self::HSTS);
    }
}
