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
namespace app\admin\util;

/**
 * Decides which template/<dir> folders the admin may activate.
 *
 * A folder is selectable when:
 *  - its name and html directory are safe and stay inside template/ (no traversal, no symlink escape);
 *  - it has info.ini and <html_dir>/index/index.html;
 *  - info.ini is not marked `status = draft`;
 *  - a static safety scan finds no server-side executable or server-configuration files and
 *    no dangerous PHP inside template code ({php} blocks, <?php blocks and ThinkPHP tags).
 *
 * Folders listed in deployment/reviewed-themes.json are always selectable (operator
 * allow-list), so an operator-reviewed theme is never locked out by a scanner false
 * positive or a forgotten draft flag. The registry lives outside template/, so an uploaded
 * theme cannot approve itself.
 *
 * The scan result is cached per theme in runtime/theme-gate/<dir>.json, keyed by a
 * fingerprint of every file's path, size and mtime: it is recomputed only after a change.
 * Compatible with PHP 7.4 - 8.5.
 */
class ThemeGate
{
    const REGISTRY_SCHEMA = 'javcms.reviewed-themes.v1';
    const SCAN_VERSION = 6;
    const MAX_FILES = 5000;
    const MAX_TEXT_BYTES = 2097152;
    /** ThinkPHP Cx tags and template-level tags whose attributes are compiled to PHP. */
    const TAGS = 'volist|foreach|if|elseif|switch|case|compare|eq|equal|neq|notequal|gt|lt|egt|elt|heq|nheq|range|in|notin|between|notbetween|empty|notempty|present|notpresent|defined|notdefined|load|import|css|js|assign|define|for|url|function|include|extend|block|layout|taglib';

    /** @var string|null test seam: alternative project root (with trailing separator) */
    private static $root = null;
    /** @var array per-request memo */
    private static $memo = [];

    /** Server-side executable or server-configuration files (last extension). */
    private static $executableExtensions = [
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'pht', 'phtm', 'phtml', 'phar', 'phps', 'inc',
        'cgi', 'pl', 'py', 'pyc', 'rb', 'sh', 'bash', 'ksh', 'zsh', 'exe', 'dll', 'so', 'dylib', 'msi', 'bat', 'cmd',
        'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'wsh', 'hta', 'jar', 'jsp', 'jspx', 'asp', 'aspx', 'ascx', 'ashx', 'asmx',
        'asa', 'cer', 'cdx', 'shtml', 'shtm', 'stm', 'scr',
    ];
    /** Extensions that some servers execute even in the middle of a name (shell.php.jpg). */
    private static $phpFamily = ['php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'pht', 'phtm', 'phtml', 'phar', 'phps'];
    private static $serverConfigNames = ['.htaccess', '.htpasswd', '.user.ini', 'php.ini', 'web.config', 'nginx.conf'];
    /** Binary assets that the template engine never compiles. */
    private static $binaryExtensions = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'tif', 'tiff', 'psd', 'woff', 'woff2', 'ttf', 'otf',
        'eot', 'mp4', 'webm', 'm4v', 'mov', 'mp3', 'm4a', 'ogg', 'oga', 'wav', 'flac', 'zip', 'gz', '7z', 'rar', 'pdf',
        'swf', 'map',
    ];
    /** Text files compiled by the template engine anywhere in the theme. */
    private static $templateExtensions = ['html', 'htm', 'tpl', 'xml', 'xhtml', 'txt', 'wml'];

    private static $dangerousCalls = [
        'eval', 'assert', 'system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec',
        'create_function', 'file_put_contents', 'fopen', 'fwrite', 'fputs', 'unlink', 'rmdir', 'move_uploaded_file',
        'symlink', 'chmod', 'fsockopen', 'pfsockopen', 'curl_exec', 'curl_multi_exec', 'putenv', 'dl',
        'register_shutdown_function', 'register_tick_function', 'call_user_func', 'call_user_func_array',
        'copy', 'rename', 'mkdir', 'tempnam', 'forward_static_call', 'forward_static_call_array', 'iterator_apply',
        // The CMS's own file and network helpers (application/common.php) wrap the built-ins above.
        'mac_write_file', 'mac_arr2file', 'mac_mkdirss', 'mac_rmdirs', 'mac_safe_unlink_upload', 'mac_read_file',
        'mac_curl_get', 'mac_curl_post',
    ];
    /** Functions that take a callback, with the position (0-based) of the callback argument. */
    private static $callbackTakers = [
        'array_map' => 0, 'array_filter' => 1, 'array_walk' => 1, 'array_walk_recursive' => 1, 'array_reduce' => 1,
        'usort' => 1, 'uasort' => 1, 'uksort' => 1, 'preg_replace_callback' => 1,
    ];
    private static $decoders = ['base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'convert_uudecode', 'hex2bin'];

    /** Test seam: point the gate at another project root (null restores ROOT_PATH). */
    public static function useRoot($root)
    {
        self::$root = $root === null ? null : rtrim((string)$root, '/\\') . DIRECTORY_SEPARATOR;
        self::$memo = [];
    }

    public static function root()
    {
        if (self::$root !== null) {
            return self::$root;
        }
        return defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    public static function nameIsSafe($value)
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $value) === 1;
    }

    public static function htmlDirIsSafe($value)
    {
        return is_string($value) && $value !== ''
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*(?:\/[A-Za-z0-9][A-Za-z0-9_-]*)*\z/D', $value) === 1;
    }

    /** Names listed in deployment/reviewed-themes.json (operator allow-list). */
    public static function reviewed()
    {
        if (isset(self::$memo['reviewed'])) {
            return self::$memo['reviewed'];
        }
        $names = [];
        $registry = self::root() . 'deployment' . DIRECTORY_SEPARATOR . 'reviewed-themes.json';
        if (is_file($registry)) {
            $data = json_decode((string)@file_get_contents($registry), true);
            if (is_array($data) && ($data['schema'] ?? '') === self::REGISTRY_SCHEMA && isset($data['themes']) && is_array($data['themes'])) {
                foreach ($data['themes'] as $name) {
                    if (self::nameIsSafe($name)) {
                        $names[] = $name;
                    }
                }
            }
        }
        return self::$memo['reviewed'] = $names;
    }

    public static function isReviewed($dir)
    {
        return in_array($dir, self::reviewed(), true);
    }

    /**
     * Resolve template/<dir> and template/<dir>/<htmlDir>; null when unsafe or incomplete.
     * @return array|null [themeRoot, htmlRoot]
     */
    public static function structure($dir, $htmlDir = 'html')
    {
        if (!self::nameIsSafe($dir) || !self::htmlDirIsSafe($htmlDir)) {
            return null;
        }
        $templatesRoot = realpath(self::root() . 'template');
        $themeRoot = realpath(self::root() . 'template' . DIRECTORY_SEPARATOR . $dir);
        if ($templatesRoot === false || $themeRoot === false || !is_dir($themeRoot)
            || strpos($themeRoot, $templatesRoot . DIRECTORY_SEPARATOR) !== 0
            || strpos(substr($themeRoot, strlen($templatesRoot) + 1), DIRECTORY_SEPARATOR) !== false) {
            return null;
        }
        $htmlRoot = realpath($themeRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $htmlDir));
        if ($htmlRoot === false || strpos($htmlRoot, $themeRoot . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        if (!is_file($themeRoot . DIRECTORY_SEPARATOR . 'info.ini')
            || !is_file($htmlRoot . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'index.html')) {
            return null;
        }
        return [$themeRoot, $htmlRoot];
    }

    /**
     * info.ini as a flat key => string map. A tolerant line parser: unlike parse_ini_file it
     * never fails on ( ) ! ; characters and never turns values like "none" or "yes" into
     * other strings. Sections are ignored.
     */
    public static function info($dir)
    {
        $key = 'info:' . $dir;
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        $values = [];
        if (self::nameIsSafe($dir)) {
            $file = self::root() . 'template' . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . 'info.ini';
            $text = is_file($file) && filesize($file) <= 65536 ? (string)@file_get_contents($file) : '';
            if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
                $text = substr($text, 3);
            }
            foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
                if (!preg_match('/\A\s*([A-Za-z0-9_.-]+)\s*=\s*(.*?)\s*\z/D', $line, $m)) {
                    continue;
                }
                $value = $m[2];
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                    $value = substr($value, 1, -1);
                } else {
                    $value = preg_replace('/\s+;.*\z/s', '', $value);
                }
                $values[strtolower($m[1])] = $value;
            }
        }
        return self::$memo[$key] = $values;
    }

    public static function displayName($dir)
    {
        $info = self::info($dir);
        $name = isset($info['name']) ? trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$info['name'])) : '';
        if ($name === '' || !preg_match('//u', $name)) {
            return (string)$dir;
        }
        return function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
    }

    public static function isDraft($dir)
    {
        $info = self::info($dir);
        // Fail-safe: `status = "draft" ; note` and `status=draft;x` count as draft too.
        return isset($info['status']) && preg_match('/\A[\'"]?\s*draft\b/i', trim($info['status'])) === 1;
    }

    /** The ads directory declared by info.ini (adsdir), validated; defaults to "ads". */
    public static function adsDir($dir)
    {
        $info = self::info($dir);
        $ads = isset($info['adsdir']) ? trim($info['adsdir']) : '';
        return self::nameIsSafe($ads) ? $ads : 'ads';
    }

    /**
     * Full decision for one folder.
     * @return array ['ready'=>bool, 'reason'=>string, 'findings'=>string[]]
     *   reason: ready | reviewed | invalid | incomplete | draft | unsafe
     */
    public static function check($dir, $htmlDir = 'html')
    {
        if (!self::nameIsSafe($dir) || !self::htmlDirIsSafe($htmlDir)) {
            return ['ready' => false, 'reason' => 'invalid', 'findings' => []];
        }
        if (self::structure($dir, $htmlDir) === null) {
            return ['ready' => false, 'reason' => 'incomplete', 'findings' => []];
        }
        if (self::isReviewed($dir)) {
            return ['ready' => true, 'reason' => 'reviewed', 'findings' => []];
        }
        if (self::isDraft($dir)) {
            return ['ready' => false, 'reason' => 'draft', 'findings' => []];
        }
        $scan = self::scan($dir);
        if (!$scan['safe']) {
            return ['ready' => false, 'reason' => 'unsafe', 'findings' => $scan['findings']];
        }
        return ['ready' => true, 'reason' => 'ready', 'findings' => []];
    }

    public static function ready($dir, $htmlDir = 'html')
    {
        $result = self::check($dir, $htmlDir);
        return $result['ready'];
    }

    /** Every directory name under template/ that passes the name check. */
    public static function directories()
    {
        $dirs = [];
        foreach (glob(self::root() . 'template' . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $path) {
            $name = basename($path);
            if (self::nameIsSafe($name)) {
                $dirs[] = $name;
            }
        }
        sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
        return $dirs;
    }

    /** Selectable directory names, natural order. */
    public static function available()
    {
        $ready = [];
        foreach (self::directories() as $name) {
            if (self::ready($name, 'html')) {
                $ready[] = $name;
            }
        }
        return $ready;
    }

    /**
     * Every folder with its decision, for the admin selector.
     * @return array list of ['dir','name','ready','reason','findings']
     */
    public static function overview()
    {
        $rows = [];
        foreach (self::directories() as $name) {
            $check = self::check($name, 'html');
            $rows[] = [
                'dir' => $name,
                'name' => self::displayName($name),
                'ready' => $check['ready'],
                'reason' => $check['reason'],
                'findings' => array_slice($check['findings'], 0, 5),
            ];
        }
        return $rows;
    }

    /**
     * Static safety scan of template/<dir>, cached by a fingerprint of all files.
     * @return array ['safe'=>bool, 'findings'=>string[]]
     */
    public static function scan($dir)
    {
        $key = 'scan:' . $dir;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $themeRoot = self::nameIsSafe($dir) ? realpath(self::root() . 'template' . DIRECTORY_SEPARATOR . $dir) : false;
        if ($themeRoot === false || !is_dir($themeRoot)) {
            return self::$memo[$key] = ['safe' => false, 'findings' => ['missing']];
        }
        $files = self::listFiles($themeRoot);
        $fingerprint = hash('sha256', self::SCAN_VERSION . "\n" . json_encode($files));
        $cacheFile = self::cacheDir() . $dir . '.json';
        $cached = is_file($cacheFile) ? json_decode((string)@file_get_contents($cacheFile), true) : null;
        if (is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint && isset($cached['safe'], $cached['findings'])
            && is_bool($cached['safe']) && is_array($cached['findings'])) {
            return self::$memo[$key] = ['safe' => $cached['safe'], 'findings' => $cached['findings']];
        }
        $findings = self::scanFiles($themeRoot, $files);
        $result = ['safe' => $findings === [], 'findings' => $findings];
        self::writeCache($cacheFile, ['fingerprint' => $fingerprint, 'safe' => $result['safe'], 'findings' => $findings, 'scanned' => date('c')]);
        return self::$memo[$key] = $result;
    }

    /**
     * Uncached scan of any directory laid out like a theme (for tests and audits).
     * @return array ['safe'=>bool, 'findings'=>string[]]
     */
    public static function scanPath($path)
    {
        $root = realpath((string)$path);
        if ($root === false || !is_dir($root)) {
            return ['safe' => false, 'findings' => ['missing']];
        }
        $findings = self::scanFiles($root, self::listFiles($root));
        return ['safe' => $findings === [], 'findings' => $findings];
    }

    /**
     * Collect the names of variables assigned a closure (function/fn) anywhere in the given
     * template texts. A call through such a variable ($name(...)) is the theme's own helper,
     * not an indirect call to a blocked function.
     * @param string[] $texts
     * @return array name => true
     */
    public static function closureVars(array $texts)
    {
        $vars = [];
        foreach ($texts as $text) {
            if (preg_match_all('/\$([A-Za-z_]\w*)\s*=\s*(?:static\s+)?(?:function|fn)\b/i', (string)$text, $m)) {
                foreach ($m[1] as $name) {
                    $vars[$name] = true;
                }
            }
        }
        return $vars;
    }

    /**
     * Scan template text (exposed for tests).
     * @param bool $full true for template files (all rules), false for other text assets
     * @param array|null $closures variables bound to a closure anywhere in the theme; when null,
     *        they are collected from this text alone (single-file / test use).
     * @return string[] rule names
     */
    public static function scanTemplateText($text, $full = true, ?array $closures = null)
    {
        $text = (string)$text;
        if ($closures === null) {
            $closures = self::closureVars([$text]);
        }
        $hits = [];
        foreach (self::phpSegments($text, $full) as $segment) {
            foreach (self::segmentFindings($segment[0], $segment[1], $closures) as $hit) {
                $hits[$hit] = true;
            }
        }
        // {include file="..."} may only name theme templates: no extension, no traversal, no absolute path.
        if ($full && preg_match_all('/\{include\b[^}]*?\bfile\s*=\s*(["\'])(.*?)\1/i', $text, $m)) {
            foreach ($m[2] as $list) {
                foreach (explode(',', $list) as $path) {
                    $path = trim($path);
                    if ($path === '' || $path[0] === '$') {
                        continue;
                    }
                    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    if (strpos($path, '..') !== false || strpos($path, ':') !== false || $path[0] === '/' || $path[0] === '\\'
                        || ($extension !== '' && !in_array($extension, self::$templateExtensions, true))) {
                        $hits['include_path'] = true;
                    }
                }
            }
        }
        return array_keys($hits);
    }

    /** Is this relative path a server-side executable or server configuration file? */
    public static function isExecutableName($relative)
    {
        $base = strtolower(basename(str_replace('\\', '/', (string)$relative)));
        if (in_array($base, self::$serverConfigNames, true)) {
            return true;
        }
        $parts = explode('.', $base);
        array_shift($parts);
        if (!$parts) {
            return false;
        }
        if (in_array(end($parts), self::$executableExtensions, true)) {
            return true;
        }
        foreach ($parts as $part) {
            if (in_array($part, self::$phpFamily, true)) {
                return true;
            }
        }
        return false;
    }

    private static function cacheDir()
    {
        if (self::$root === null && defined('RUNTIME_PATH')) {
            $base = RUNTIME_PATH;
        } else {
            $base = self::root() . 'runtime' . DIRECTORY_SEPARATOR;
        }
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'theme-gate' . DIRECTORY_SEPARATOR;
    }

    private static function writeCache($file, array $data)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $temp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false) {
            if (!@rename($temp, $file)) {
                @unlink($file);
                @rename($temp, $file);
            }
        }
        if (is_file($temp)) {
            @unlink($temp);
        }
    }

    /** @return array relative path => [size, mtime] or [marker] (symlinks are listed, never followed) */
    private static function listFiles($themeRoot)
    {
        $files = [];
        $stack = [''];
        while ($stack) {
            $relativeDir = array_pop($stack);
            $absoluteDir = $themeRoot . ($relativeDir === '' ? '' : DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir));
            $entries = @scandir($absoluteDir);
            if ($entries === false) {
                $files[$relativeDir . '/'] = ['unreadable'];
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $relative = $relativeDir === '' ? $entry : $relativeDir . '/' . $entry;
                $absolute = $absoluteDir . DIRECTORY_SEPARATOR . $entry;
                if (is_link($absolute)) {
                    $files[$relative] = ['symlink'];
                } elseif (is_dir($absolute)) {
                    $stack[] = $relative;
                } else {
                    $files[$relative] = [(int)@filesize($absolute), (int)@filemtime($absolute)];
                }
                if (count($files) > self::MAX_FILES) {
                    $files['*'] = ['too-many-files'];
                    break 2;
                }
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private static function scanFiles($themeRoot, array $files)
    {
        $findings = [];
        // First pass: collect every closure-bound variable across the theme's template files, so a
        // call through such a variable ($helper(...)) is not mistaken for an indirect blocked call.
        $closureTexts = [];
        foreach ($files as $relative => $meta) {
            if (!isset($meta[0]) || is_string($meta[0]) || $meta[0] > self::MAX_TEXT_BYTES) {
                continue;
            }
            $ext = strtolower(pathinfo((string)$relative, PATHINFO_EXTENSION));
            if (!in_array($ext, self::$templateExtensions, true)) {
                continue;
            }
            $text = @file_get_contents($themeRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$relative));
            if (is_string($text) && strpos($text, 'function') !== false) {
                $closureTexts[] = $text;
            }
        }
        $closures = self::closureVars($closureTexts);
        foreach ($files as $relative => $meta) {
            $relative = (string)$relative;
            if (isset($meta[0]) && is_string($meta[0])) {
                $findings[] = $meta[0] . ':' . $relative;
                continue;
            }
            if (self::isExecutableName($relative)) {
                $findings[] = 'server-file:' . $relative;
                continue;
            }
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            $binary = in_array($extension, self::$binaryExtensions, true);
            if ($meta[0] > ($binary ? 4 * self::MAX_TEXT_BYTES : self::MAX_TEXT_BYTES)) {
                if (!$binary) {
                    $findings[] = 'oversize:' . $relative;
                }
                continue;
            }
            $text = @file_get_contents($themeRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
            if (!is_string($text)) {
                $findings[] = 'unreadable:' . $relative;
                continue;
            }
            if ($binary) {
                // An image or font that carries PHP code can be pulled in by an explicit-path include.
                if (stripos($text, '<?php') !== false) {
                    $findings[] = 'php-in-binary:' . $relative;
                }
                continue;
            }
            // Template files get every rule; other text assets (js, css, json, svg) get the rules that
            // cannot match ordinary JavaScript: native PHP blocks, {php} blocks and {$ / {: / {~ tags.
            foreach (self::scanTemplateText($text, in_array($extension, self::$templateExtensions, true), $closures) as $rule) {
                $findings[] = $rule . ':' . $relative;
            }
            if (count($findings) >= 50) {
                break;
            }
        }
        return $findings;
    }

    /**
     * PHP-executing regions of a ThinkPHP template.
     * @return array list of [kind, code]; kind php = native or {php} code, expr = template tag
     */
    private static function phpSegments($text, $full = true)
    {
        $segments = [];
        if (preg_match_all('/\{php\}(.*?)(?:\{\/php\}|\z)/is', $text, $m)) {
            foreach ($m[1] as $code) {
                $segments[] = ['php', $code];
            }
        }
        if (preg_match_all('/<\?(?:php\b|=)(.*?)(?:\?>|\z)/is', $text, $m)) {
            foreach ($m[1] as $code) {
                $segments[] = ['php', $code];
            }
        }
        // Variable and expression tags as ThinkPHP recognises them: {$var|filter}, {:expr}, {~expr}, {++$i}.
        if (preg_match_all('/\{((?:\${1,2}[A-Za-z_]|[:~][$A-Za-z_\\\\]|\+\+\$|--\$)[^}]*)\}/', $text, $m)) {
            foreach ($m[1] as $code) {
                $segments[] = ['expr', $code];
            }
        }
        // Tag-library tags whose attributes compile to PHP: {if ...}, {assign ...}, {volist ...}, {maccms:vod ...}.
        if ($full && preg_match_all('/\{(?:maccms:\w+|' . self::TAGS . ')\b([^}]*)\}/i', $text, $m)) {
            foreach ($m[1] as $code) {
                $segments[] = ['expr', $code];
            }
        }
        return $segments;
    }

    private static function segmentFindings($kind, $code, array $closures = [])
    {
        $hits = [];
        $calls = implode('|', self::$dangerousCalls);
        // Direct calls, including \system(...) and 'a'.system(...); method calls such as ->exec( are ignored.
        if (preg_match_all('/(?<![\w$])(?<!->)(?<!::)(' . $calls . ')\s*\(/i', $code, $m)) {
            foreach ($m[1] as $name) {
                $hits[] = strtolower($name);
            }
        }
        // ThinkPHP filters: {$x|system} or {$x|file_put_contents=...,###}.
        if ($kind === 'expr' && preg_match_all('/\|\s*\\\\?(' . $calls . ')\b/i', $code, $m)) {
            foreach ($m[1] as $name) {
                $hits[] = strtolower($name);
            }
        }
        // preg_replace with the /e modifier (code execution on old PHP): inspect the literal pattern's modifiers.
        if (preg_match_all('/preg_replace\s*\(\s*(?:\'([^\']*)\'|"([^"]*)")/i', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $pattern = isset($match[2]) && $match[2] !== '' ? $match[2] : $match[1];
                if ($pattern === '') {
                    continue;
                }
                $close = strtr($pattern[0], '({[<', ')}]>');
                $end = strrpos($pattern, $close);
                if ($end !== false && $end > 0 && stripos(substr($pattern, $end + 1), 'e') !== false) {
                    $hits[] = 'preg_replace_e';
                }
            }
        }
        // Decoded strings that get executed: a decoder plus a dynamic call or callback in the same block.
        $decoders = implode('|', self::$decoders);
        if (preg_match('/\b(?:' . $decoders . ')\s*\(/i', $code)
            && preg_match('/\$[A-Za-z_]\w*\s*\(|\)\s*\(|\b(?:array_map|array_filter|array_walk|usort|preg_replace_callback|call_user_func\w*|include|require)\b/i', $code)) {
            $hits[] = 'decode_exec';
        }
        // Sensitive capabilities a theme never needs: reflection, raw SQL execution, and reads of the
        // database configuration. (The CMS file/network helpers are covered by $dangerousCalls.)
        if (preg_match('/(?<![\w$>])Reflection[A-Za-z]*\b|(?<![\w$>])Closure::|::fromCallable\b/', $code)) {
            $hits[] = 'reflection';
        }
        if (preg_match('/(?<![\w$>:])Db\s*::\s*(?:execute|query)\b|->\s*(?:execute|query)\s*\(/i', $code)) {
            $hits[] = 'raw_sql';
        }
        if (preg_match('/(?:config|Config\s*::\s*get|Env\s*::\s*get)\s*\(\s*[\'"]database|\$Think\.config\.database|getenv\s*\(/i', $code)) {
            $hits[] = 'config_read';
        }
        // A call through a callback-taking function whose callback argument is a concatenated string
        // (sys'.'tem) or names a blocked function. Covers {:array_map(...)} and the {$x|array_map=...}
        // filter form. The callback is the 1st or 2nd argument depending on the function.
        $takers = implode('|', array_keys(self::$callbackTakers));
        if (preg_match('/(?<![\w$])(?<!->)(?<!::)(' . $takers . ')\s*[(=]\s*(?:[^,()]*,\s*)?([\'"])[^\'"]*\2\s*\./i', $code, $m)) {
            $hits[] = 'callback_concat';
        }
        if (preg_match('/(?<![\w$])(?<!->)(?<!::)(' . $takers . ')\s*[(=]\s*(?:[^,()]*,\s*)?([\'"])\s*\\\\?(?:' . $calls . ')\s*\2/i', $code, $m)) {
            $hits[] = 'callback_danger';
        }
        // Dynamic instantiation and dynamic calls. A call through a variable that the theme never binds
        // to a closure reaches a blocked function without naming it (e.g. $f='sys'.'tem'; $f('id')).
        if (preg_match('/new\s+\$[A-Za-z_]/', $code)) {
            $hits[] = 'new_dynamic';
        }
        if (preg_match_all('/(?<![\w$])(?<!->)(?<!::)\$([A-Za-z_]\w*)\s*(?:\[[^\]]*\]\s*)*\(/', $code, $m)) {
            foreach ($m[1] as $name) {
                if (!isset($closures[$name])) {
                    $hits[] = 'dynamic_call';
                }
            }
        }
        if ($kind === 'php') {
            if (preg_match('/(?<![\w$])(?:include|include_once|require|require_once)\b/i', $code)) {
                $hits[] = 'include';
            }
            if (preg_match('/`[^`]*`/', $code)) {
                $hits[] = 'backtick';
            }
            if (preg_match('/\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)\s*\[[^\]]*\]\s*\(/i', $code)) {
                $hits[] = 'input_call';
            }
        }
        return array_values(array_unique($hits));
    }
}
