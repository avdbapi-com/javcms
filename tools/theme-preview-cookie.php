<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
// Prints the signed preview cookie for one installed theme.
// Usage: php tools/theme-preview-cookie.php <theme-directory>
// Then set cookie jv_theme_preview=<printed value> for the site to preview that theme in this browser only.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ROOT_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('APP_PATH', ROOT_PATH . 'application' . DIRECTORY_SEPARATOR);
require APP_PATH . 'common' . DIRECTORY_SEPARATOR . 'util' . DIRECTORY_SEPARATOR . 'ThemePreview.php';

$theme = isset($argv[1]) ? (string)$argv[1] : '';
if (!\app\common\util\ThemePreview::validName($theme) || !is_file(ROOT_PATH . 'template' . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . 'info.ini')) {
    fwrite(STDERR, "Unknown theme: {$theme}\n");
    exit(2);
}
echo \app\common\util\ThemePreview::sign($theme), PHP_EOL;
