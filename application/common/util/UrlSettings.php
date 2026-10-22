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

/** URL-settings validation and configured-host URLs; never accesses the database. */
class UrlSettings
{
    public static function parseRoutes($text)
    {
        if (!is_string($text)) {
            throw new \InvalidArgumentException('admin/urlsetup/invalid_form');
        }
        $routes = [];
        $seen = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $index => $line) {
            if (trim($line) === '') { continue; }
            if (substr_count($line, '=>') !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $line)) {
                throw new \InvalidArgumentException('admin/urlsetup/route_invalid', $index + 1);
            }
            list($expression, $target) = array_map('trim', explode('=>', $line, 2));
            if ($expression === '' || $target === '') {
                throw new \InvalidArgumentException('admin/urlsetup/route_invalid', $index + 1);
            }
            // ThinkPHP treats these keys as configuration rather than ordinary routes.
            $identity = trim($expression, '/');
            if ($identity === '' || substr($identity, 0, 2) === '__' || $identity[0] === '[' || is_numeric($identity)) {
                throw new \InvalidArgumentException('admin/urlsetup/route_reserved', $index + 1);
            }
            if (isset($seen[$identity])) {
                throw new \InvalidArgumentException('admin/urlsetup/route_duplicate', $index + 1);
            }
            $routes[$expression] = [$target, [], []];
            $seen[$identity] = true;
        }
        // Text saves cannot represent options. Keep native action suffix parameters
        // working even when the global router uses complete matching.
        if (isset($routes['topic/<id>']) && $routes['topic/<id>'][0] === 'topic/detail') {
            foreach (['search', 'ajax_search', 'ajax_detail', 'rss'] as $action) {
                $path = 'topic/' . $action;
                if (isset($routes[$path]) && $routes[$path][0] === $path) {
                    $routes[$path][1]['complete_match'] = false;
                }
            }
        }
        return self::prioritizeRoutes($routes);
    }

    /** Keep literal public subpaths ahead of a catch-all category path.
     * Preserve every configured expression, target, option and pattern.
     * Only the native public category controllers participate; API rules,
     * groups, domain definitions and other custom targets are left alone.
     */
    public static function prioritizeRoutes(array $routes)
    {
        $ordered = [];
        foreach ($routes as $expression => $value) {
            $target = is_array($value) && isset($value[0]) ? $value[0] : $value;
            if (!is_string($expression) || !is_string($target)
                || !preg_match('~^(vod|art|actor|topic)/(?:show|search|detail|play|down)$~D', $target, $match)) {
                $ordered[$expression] = $value;
                continue;
            }
            $before = null;
            foreach ($ordered as $existing => $definition) {
                $existingTarget = is_array($definition) && isset($definition[0]) ? $definition[0] : $definition;
                if ($existingTarget !== $match[1] . '/type' || !is_string($existing)
                    || !preg_match('~^([^<:]+/)<id>(?:-<page\?>)?$~D', $existing, $category)) { continue; }
                $prefix = $category[1];
                if (substr($expression, 0, strlen($prefix)) !== $prefix) { continue; }
                $remainder = substr($expression, strlen($prefix));
                if (preg_match('~^[a-zA-Z][a-zA-Z0-9_-]*/~', $remainder)) { $before = $existing; break; }
            }
            if ($before === null) { $ordered[$expression] = $value; continue; }
            $next = [];
            foreach ($ordered as $key => $definition) {
                if ($key === $before) { $next[$expression] = $value; }
                $next[$key] = $definition;
            }
            $ordered = $next;
        }
        return self::nativeTopicRoutes($ordered);
    }

    /** A topic/<id> catch-all also consumes the native topic controller actions.
     * Register those actions before that one catch-all, keeping every configured
     * route and respecting any existing custom mapping of the same path.
     */
    private static function nativeTopicRoutes(array $routes)
    {
        $result = [];
        foreach ($routes as $expression => $value) {
            $target = is_array($value) && isset($value[0]) ? $value[0] : $value;
            if ($expression === 'topic/<id>' && $target === 'topic/detail') {
                foreach (['search', 'ajax_search', 'ajax_detail', 'rss'] as $action) {
                    $path = 'topic/' . $action;
                    if (!array_key_exists($path, $routes)) {
                        $result[$path] = [$path, ['complete_match'=>false], []];
                    }
                }
            }
            $result[$expression] = $value;
        }
        return $result;
    }

    public static function canonicalUrl($host, $path, $scheme)
    {
        if (!is_string($host) || !is_string($path) || !in_array($scheme, ['http://', 'https://'], true)) { return ''; }
        // site_url is a bare configured host, never an HTTP Host header or request URI.
        if ($host === '' || preg_match('/[\s\x00-\x1f\x7f\/@?#\\\\]/', $host)) { return ''; }
        $parts = parse_url($scheme . $host);
        if (!$parts || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['path'])) { return ''; }
        $hostname = $parts['host'];
        $ipv6 = trim($hostname, '[]');
        if (!filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/iD', $hostname)) { return ''; }
        if ($path === '' || $path[0] !== '/' || substr($path, 0, 2) === '//'
            || preg_match('/[\x00-\x20\x7f\\\\<>"\']/', $path)) { return ''; }
        return $scheme . $host . $path;
    }

    /** One protected, exact file snapshot per intentional URL-settings save. */
    public static function backupFiles(array $paths, $directory)
    {
        $files = [];
        $originals = [];
        foreach ($paths as $path) {
            $bytes = @file_get_contents($path);
            if ($bytes === false) { return false; }
            $files[basename($path)] = base64_encode($bytes);
            $originals[$path] = $bytes;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) { return false; }
        try { $nonce = bin2hex(random_bytes(8)); }
        catch (\Throwable $error) { return false; }
        $path = rtrim($directory, '/\\') . '/url-settings-' . gmdate('Ymd-His') . '-' . $nonce . '.php';
        // If requested over HTTP, PHP exits before the base64 configuration bytes.
        $bytes = "<?php http_response_code(404); exit; ?>\n" . json_encode(['created_at' => gmdate('c'), 'files' => $files]);
        $handle = @fopen($path, 'xb');
        if (!$handle) { return false; }
        $written = fwrite($handle, $bytes);
        $flushed = fflush($handle);
        fclose($handle);
        return $written === strlen($bytes) && $flushed ? ['path' => $path, 'files' => $originals] : false;
    }

    public static function restoreBackup(array $backup)
    {
        $restored = true;
        foreach ($backup['files'] as $path => $bytes) {
            $written = @file_put_contents($path, $bytes, LOCK_EX);
            $restored = $written === strlen($bytes) && $restored;
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
        }
        return $restored;
    }

    public static function matchesConfigFile($path, array $config)
    {
        // mac_arr2file returns a byte count; a positive short write is still a failure.
        return @file_get_contents($path) === "<?php\nreturn " . var_export($config, true) . ';';
    }
}
