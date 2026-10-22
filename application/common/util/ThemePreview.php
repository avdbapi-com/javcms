<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

/**
 * Per-browser theme preview. A browser holding a valid signed cookie renders
 * another installed theme; every other visitor keeps the configured theme.
 * The signature key is derived from this install's private database settings,
 * so a preview cookie cannot be forged without server-side access.
 */
class ThemePreview
{
    const COOKIE = 'jv_theme_preview';

    private static function secret()
    {
        $file = APP_PATH . 'database.php';
        return hash('sha256', 'javcms-theme-preview|' . (is_file($file) ? (string)file_get_contents($file) : ''));
    }

    public static function validName($theme)
    {
        return is_string($theme) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $theme) === 1;
    }

    public static function sign($theme)
    {
        return $theme . '.' . substr(hash_hmac('sha256', 'theme-preview|' . $theme, self::secret()), 0, 32);
    }

    /** Returns the previewed theme directory, or '' when no valid preview applies. */
    public static function current()
    {
        $value = isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? $_COOKIE[self::COOKIE] : '';
        $dot = strrpos($value, '.');
        if ($value === '' || strlen($value) > 120 || $dot === false) {
            return '';
        }
        $theme = substr($value, 0, $dot);
        if (!self::validName($theme) || !hash_equals(self::sign($theme), $value)) {
            return '';
        }
        $root = ROOT_PATH . 'template' . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR;
        return is_file($root . 'info.ini') && is_file($root . 'html' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'index.html')
            ? $theme : '';
    }
}
