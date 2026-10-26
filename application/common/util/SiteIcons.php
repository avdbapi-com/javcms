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

/**
 * Site icon (favicon) set and share picture (SEO-ADMIN-BRIEF B.1-B.3, A.6).
 *
 * One square PNG / JPEG / WebP upload becomes favicon.ico (PNG-in-ICO 16/32/48), icon-32 / 48 / 180 (apple-touch, opaque) /
 * 192 / 512 / 512-maskable PNG in upload/site/icon/<sha10>/ (content-hashed folder, written to a temp folder and renamed).
 * SVG, ICO, GIF and BMP are refused. Without GD (B.2) only a PNG is accepted and stored as-is (icon-source.png).
 * The default set (nothing uploaded) lives in static/site-icons/default/ (B.3).
 *
 * Callers: System::siteicon() (seo-admin: check() + generate() / share()), System::config() on Save (writeManifest(),
 * syncRoot()), SiteHead (links()). Error / warning values are admin lang keys under admin/ux/seo/.
 * Nothing here reads the database or prints HTML.
 */
class SiteIcons
{
    const ICON_MAX_BYTES = 2097152;
    const SHARE_MAX_BYTES = 5242880;
    const ICON_MIN = 144;
    const ICON_MAX = 4096;
    const SHARE_MIN_W = 600;
    const SHARE_MIN_H = 315;
    const SHARE_W = 1200;
    const SHARE_H = 630;
    /** Decoded-image memory budget (B.1 step 2: w * h * 5 < 96 MB). */
    const MEMORY_BUDGET = 100663296;
    /** Generations kept of icon folders, share pictures and manifests (B.1 steps 4, 7). */
    const KEEP = 3;
    const ICON_FILES = ['favicon.ico', 'icon-32.png', 'icon-48.png', 'icon-180.png', 'icon-192.png', 'icon-512.png', 'icon-512-maskable.png'];
    const DEFAULT_DIR = 'static/site-icons/default/';
    const SHIPPED_ICO = 'static/template-ui/favicon-javcms.ico';
    const MIMES = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
    const EXTENSIONS = ['png' => 'png', 'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'webp' => 'webp'];

    /** Test hook (deployment/tests/seo-icons.php): behave as if GD were missing. Never set by the product. */
    public static $forceNoGd = false;

    /** B.2: GD can decode and write PNG. */
    public static function available()
    {
        return !self::$forceNoGd && function_exists('imagecreatefrompng') && function_exists('imagepng')
            && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
    }

    public static function root()
    {
        return defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    public static function iconRoot() { return self::root() . 'upload/site/icon/'; }

    public static function shareRoot() { return self::root() . 'upload/site/share/'; }

    public static function siteRoot() { return self::root() . 'upload/site/'; }

    // ------------------------------------------------------------------ checks (B.1 step 2)

    /** File type from the magic bytes: 'png' | 'jpeg' | 'webp' | ''. */
    public static function magic($bytes)
    {
        $bytes = (string)$bytes;
        if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) { return 'png'; }
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) { return 'jpeg'; }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') { return 'webp'; }
        return '';
    }

    /**
     * Script payload scan: the first / last 512 bytes with the rule of Upload.php (`<% ( ) %>`, `<? ( ) ?>`, script
     * tags), plus the whole file for "<?php" and "<script" (a polyglot can hide them in a middle chunk).
     */
    public static function payload($file)
    {
        $data = (string)@file_get_contents($file, false, null, 0, self::SHARE_MAX_BYTES + 1);
        $edges = strlen($data) > 1024 ? substr($data, 0, 512) . substr($data, -512) : $data;
        if (preg_match('~<%.*?\(.*?\).*?%>|<\?.*?\(.*?\).*?\?>|<script|/script>~is', $edges)) { return true; }
        return stripos($data, '<?php') !== false || stripos($data, '<script') !== false;
    }

    /**
     * B.1 step 2 for an uploaded temp file. $kind 'icon' | 'share'; $name = the client file name (extension must match the
     * content; '' skips that rule). Returns ['ok', 'error' (lang key or ''), 'type' (png|jpeg|webp), 'w', 'h'].
     */
    public static function check($file, $kind = 'icon', $name = '')
    {
        $kind = $kind === 'share' ? 'share' : 'icon';
        $fail = function ($error, $type = '', $w = 0, $h = 0) {
            return ['ok' => false, 'error' => $error, 'type' => $type, 'w' => $w, 'h' => $h];
        };
        if (!is_string($file) || $file === '' || !is_file($file) || !is_readable($file)) { return $fail('icon_type_err'); }
        clearstatcache(true, $file);
        $size = (int)filesize($file);
        if ($size <= 0) { return $fail('icon_broken_err'); }
        if ($size > ($kind === 'share' ? self::SHARE_MAX_BYTES : self::ICON_MAX_BYTES)) {
            return $fail($kind === 'share' ? 'share_size_err' : 'icon_size_err');
        }
        $type = self::magic((string)file_get_contents($file, false, null, 0, 16));
        if ($type === '') {
            // Named like a picture but not one (damaged, or another file renamed): say so (N8); SVG / ICO / GIF keep the type text.
            $ext = strtolower((string)pathinfo((string)$name, PATHINFO_EXTENSION));
            return $fail(isset(self::EXTENSIONS[$ext]) ? 'icon_broken_err' : 'icon_type_err');
        }
        if ($name !== '') {
            $ext = strtolower((string)pathinfo((string)$name, PATHINFO_EXTENSION));
            if (!isset(self::EXTENSIONS[$ext]) || self::EXTENSIONS[$ext] !== $type) { return $fail('icon_type_err', $type); }
        }
        // fileinfo is required by the installer (AC 3.3); without it nothing is accepted.
        if (!function_exists('finfo_open')) { return $fail('icon_type_err', $type); }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $file) : '';
        if ($finfo) { finfo_close($finfo); }
        if ($mime !== self::MIMES[$type]) { return $fail('icon_broken_err', $type); }
        $info = @getimagesize($file);
        if (!is_array($info) || ($info['mime'] ?? '') !== self::MIMES[$type]) { return $fail('icon_broken_err', $type); }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($kind === 'icon' && ($w < self::ICON_MIN || $h < self::ICON_MIN || $w > self::ICON_MAX || $h > self::ICON_MAX)) {
            return $fail('icon_size_err', $type, $w, $h);
        }
        if ($kind === 'share' && ($w < self::SHARE_MIN_W || $h < self::SHARE_MIN_H)) { return $fail('share_size_err', $type, $w, $h); }
        if ($w * $h * 5 >= self::MEMORY_BUDGET) { return $fail($kind === 'share' ? 'share_size_err' : 'icon_size_err', $type, $w, $h); }
        if (self::payload($file)) { return $fail('icon_broken_err', $type, $w, $h); }
        if (!self::available()) {
            if ($kind === 'icon' && $type !== 'png') { return $fail('icon_nogd', $type, $w, $h); }
            if ($kind === 'share' && $type === 'webp') { return $fail('icon_webp_err', $type, $w, $h); }
        } elseif ($type === 'webp' && !function_exists('imagecreatefromwebp')) {
            return $fail('icon_webp_err', $type, $w, $h);
        }
        return ['ok' => true, 'error' => '', 'type' => $type, 'w' => $w, 'h' => $h];
    }

    // ------------------------------------------------------------------ icon set (B.1 steps 3-5, B.2)

    /**
     * B.1 steps 2-5: check, generate, publish. $themeColor = the background of the opaque apple-touch / maskable icons
     * ('' = #000000). Returns ['ok', 'error', 'path' (upload/site/icon/<sha10>/), 'previews' => [16, 32, 180 => URL path
     * relative to the site root], 'w', 'h', 'warn' (lang key or ''), 'gd' => bool]. $protect = folder paths never pruned.
     */
    public static function generate($tmp, $themeColor = '', $name = '', array $protect = [])
    {
        $check = self::check($tmp, 'icon', $name);
        if (!$check['ok']) { return self::answer(false, $check['error']); }
        $w = $check['w'];
        $h = $check['h'];
        if (!self::available()) {
            // B.2: PNG only, stored as-is; SiteHead prints one icon link and one apple-touch link to it.
            $data = (string)file_get_contents($tmp);
            $sha = substr(sha1($data), 0, 10);
            $published = self::publish($sha, ['icon-source.png' => $data]);
            if ($published === '') { return self::answer(false, 'icon_write_err'); }
            self::prune(self::iconRoot(), 'dir', array_merge($protect, [$published]));
            $path = 'upload/site/icon/' . $sha . '/';
            $src = $path . 'icon-source.png';
            return self::answer(true, '', $path, [16 => $src, 32 => $src, 180 => $src], $w, $h, 'icon_nogd', false);
        }
        $source = self::decode($tmp, $check['type']);
        if (!$source) { return self::answer(false, 'icon_broken_err'); }
        $side = min($w, $h);
        $crop = [(int)floor(($w - $side) / 2), (int)floor(($h - $side) / 2), $side];
        $big = $side >= 512;
        $warn = $big ? '' : 'icon_small_warn';
        $bg = self::color($themeColor);
        $files = [];
        try {
            $png = [];
            foreach ([16, 32, 48, 192] as $size) { $png[$size] = self::png(self::resize($source, $crop, $size)); }
            $png[180] = self::png(self::resize($source, $crop, 180, $bg));
            $png[512] = $big ? self::png(self::resize($source, $crop, 512)) : $png[192];
            $maskSize = $big ? 512 : 192;
            $png['mask'] = self::png(self::resize($source, $crop, $maskSize, $bg, 0.8));
            $files = [
                'favicon.ico' => self::ico([16 => $png[16], 32 => $png[32], 48 => $png[48]]),
                'icon-32.png' => $png[32],
                'icon-48.png' => $png[48],
                'icon-180.png' => $png[180],
                'icon-192.png' => $png[192],
                'icon-512.png' => $png[512],
                'icon-512-maskable.png' => $png['mask'],
            ];
        } finally {
            self::free($source);
        }
        foreach ($files as $file => $data) {
            if (!self::valid($file, $data)) { return self::answer(false, 'icon_write_err'); }
        }
        $sha = substr(sha1($files['icon-512.png']), 0, 10);
        $published = self::publish($sha, $files);
        if ($published === '') { return self::answer(false, 'icon_write_err'); }
        self::prune(self::iconRoot(), 'dir', array_merge($protect, [$published]));
        $path = 'upload/site/icon/' . $sha . '/';
        return self::answer(true, '', $path, [16 => $path . 'favicon.ico', 32 => $path . 'icon-32.png', 180 => $path . 'icon-180.png'], $w, $h, $warn, true);
    }

    private static function answer($ok, $error, $path = '', array $previews = [], $w = 0, $h = 0, $warn = '', $gd = true)
    {
        return ['ok' => $ok, 'error' => $error, 'path' => $path, 'previews' => $previews, 'w' => $w, 'h' => $h, 'warn' => $warn, 'gd' => $gd];
    }

    private static function decode($file, $type)
    {
        $image = false;
        if ($type === 'png') { $image = @imagecreatefrompng($file); }
        if ($type === 'jpeg' && function_exists('imagecreatefromjpeg')) { $image = @imagecreatefromjpeg($file); }
        if ($type === 'webp' && function_exists('imagecreatefromwebp')) { $image = @imagecreatefromwebp($file); }
        if (!$image) { return false; }
        if (function_exists('imageistruecolor') && !imageistruecolor($image) && function_exists('imagepalettetotruecolor')) {
            imagepalettetotruecolor($image);
        }
        return $image;
    }

    /** '#rgb' / '#rrggbb' → [r, g, b]; anything else → black. */
    public static function color($value)
    {
        $value = ltrim(trim((string)$value), '#');
        if (preg_match('/^[0-9a-fA-F]{3}$/D', $value)) { $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2]; }
        if (!preg_match('/^[0-9a-fA-F]{6}$/D', $value)) { return [0, 0, 0]; }
        return [hexdec(substr($value, 0, 2)), hexdec(substr($value, 2, 2)), hexdec(substr($value, 4, 2))];
    }

    /**
     * Square $size image from the centre crop. $bg = [r,g,b] gives an opaque background (apple-touch, maskable);
     * $scale < 1 puts the icon centred at that share of the canvas (maskable safe zone).
     */
    private static function resize($source, array $crop, $size, ?array $bg = null, $scale = 1.0)
    {
        $canvas = imagecreatetruecolor($size, $size);
        if ($bg === null) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        } else {
            imagealphablending($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, $bg[0], $bg[1], $bg[2]));
        }
        $inner = max(1, (int)round($size * $scale));
        $offset = (int)floor(($size - $inner) / 2);
        imagecopyresampled($canvas, $source, $offset, $offset, $crop[0], $crop[1], $inner, $inner, $crop[2], $crop[2]);
        if ($bg !== null) { imagealphablending($canvas, false); imagesavealpha($canvas, false); }
        return $canvas;
    }

    /** imagedestroy() frees nothing since PHP 8.0 and is deprecated in 8.5 (a deprecation would become an exception). */
    private static function free($image)
    {
        if (PHP_VERSION_ID < 80000 && $image) { imagedestroy($image); }
    }

    private static function png($image)
    {
        ob_start();
        try {
            imagepng($image, null, 9);
            return (string)ob_get_contents();
        } finally {
            ob_end_clean();
            self::free($image);
        }
    }

    /** PNG-in-ICO (RS 6.2): [size => png bytes]. */
    public static function ico(array $pngs)
    {
        $count = count($pngs);
        $head = pack('vvv', 0, 1, $count);
        $offset = 6 + 16 * $count;
        $dir = '';
        $data = '';
        foreach ($pngs as $size => $png) {
            $size = (int)$size;
            $dir .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($png), $offset);
            $offset += strlen($png);
            $data .= $png;
        }
        return $head . $dir . $data;
    }

    /** ICO directory: [['w','h','bytes','offset','png' => bool], ...] or [] when the header does not parse. */
    public static function icoEntries($bytes)
    {
        $bytes = (string)$bytes;
        if (strlen($bytes) < 6) { return []; }
        $head = unpack('vreserved/vtype/vcount', substr($bytes, 0, 6));
        if ($head['reserved'] !== 0 || $head['type'] !== 1 || $head['count'] < 1 || strlen($bytes) < 6 + 16 * $head['count']) { return []; }
        $out = [];
        for ($i = 0; $i < $head['count']; $i++) {
            $e = unpack('Cw/Ch/Ccolors/Creserved/vplanes/vbpp/Vbytes/Voffset', substr($bytes, 6 + 16 * $i, 16));
            if ($e['offset'] + $e['bytes'] > strlen($bytes)) { return []; }
            $out[] = ['w' => $e['w'] ?: 256, 'h' => $e['h'] ?: 256, 'bytes' => $e['bytes'], 'offset' => $e['offset'],
                'png' => self::magic(substr($bytes, $e['offset'], 8)) === 'png'];
        }
        return $out;
    }

    /** Every output is checked before publish (B.1 step 3). */
    private static function valid($file, $data)
    {
        if ($file === 'favicon.ico') {
            $entries = self::icoEntries($data);
            if (count($entries) !== 3) { return false; }
            foreach ($entries as $e) {
                $info = @getimagesizefromstring(substr($data, $e['offset'], $e['bytes']));
                if (!$e['png'] || !is_array($info) || $info[0] !== $e['w']) { return false; }
            }
            return true;
        }
        $info = @getimagesizefromstring($data);
        return is_array($info) && ($info['mime'] ?? '') === 'image/png';
    }

    /**
     * Atomic publish of a content-hashed folder: temp folder + rename(). Returns the folder path, or '' on failure.
     * An existing folder with the same hash is reused (same content).
     */
    private static function publish($sha, array $files)
    {
        if (!preg_match('/^[0-9a-f]{10}$/D', $sha)) { return ''; }
        $root = self::iconRoot();
        if (!is_dir($root) && !@mkdir($root, 0755, true)) { return ''; }
        $realRoot = realpath($root);
        if ($realRoot === false) { return ''; }
        $final = $realRoot . DIRECTORY_SEPARATOR . $sha;
        if (is_dir($final)) {
            foreach (array_keys($files) as $file) { if (!is_file($final . DIRECTORY_SEPARATOR . $file)) { return ''; } }
            @touch($final);
            return $final;
        }
        $temp = $realRoot . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(6));
        if (!@mkdir($temp, 0755)) { return ''; }
        foreach ($files as $file => $data) {
            if (!preg_match('/^[a-z0-9-]+\.(?:png|ico)$/D', $file) || file_put_contents($temp . DIRECTORY_SEPARATOR . $file, $data) !== strlen($data)) {
                self::removeDir($temp, $realRoot);
                return '';
            }
        }
        if (!@rename($temp, $final)) {
            self::removeDir($temp, $realRoot);
            return is_dir($final) ? $final : '';
        }
        return $final;
    }

    /** Delete a folder made by this class (flat; realpath must stay under $realRoot). */
    private static function removeDir($dir, $realRoot)
    {
        $real = realpath($dir);
        $realRoot = rtrim((string)$realRoot, '\\/') . DIRECTORY_SEPARATOR;
        if ($real === false || strpos($real . DIRECTORY_SEPARATOR, $realRoot) !== 0 || $real . DIRECTORY_SEPARATOR === $realRoot) { return; }
        foreach (glob($real . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && preg_match('/^[a-z0-9.-]+$/D', basename($file))) { @unlink($file); }
        }
        @rmdir($real);
    }

    /**
     * Keep the KEEP newest entries of a folder: 'dir' = icon folders (<sha10>/), 'share' = <sha10>.jpg|png,
     * 'manifest' = manifest-<sha8>.json. $protect = paths (absolute, or relative to the site root) never deleted.
     */
    public static function prune($root, $what, array $protect = [])
    {
        $realRoot = realpath($root);
        if ($realRoot === false) { return; }
        $keep = [];
        foreach ($protect as $path) {
            $path = (string)$path;
            if ($path === '') { continue; }
            $real = realpath(preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) ? $path : self::root() . $path);
            if ($real !== false) { $keep[$real] = true; }
        }
        $patterns = ['dir' => '/^[0-9a-f]{10}$/D', 'share' => '/^[0-9a-f]{10}\.(?:jpg|png)$/D', 'manifest' => '/^manifest-[0-9a-f]{8}\.json$/D'];
        if (!isset($patterns[$what])) { return; }
        $items = [];
        foreach (scandir($realRoot) ?: [] as $name) {
            if (!preg_match($patterns[$what], $name)) { continue; }
            $path = $realRoot . DIRECTORY_SEPARATOR . $name;
            if ($what === 'dir' ? !is_dir($path) : !is_file($path)) { continue; }
            $items[$path] = (int)filemtime($path);
        }
        arsort($items);
        $i = 0;
        foreach ($items as $path => $time) {
            if (++$i <= self::KEEP || isset($keep[$path])) { continue; }
            if ($what === 'dir') { self::removeDir($path, $realRoot); } else { @unlink($path); }
        }
    }

    // ------------------------------------------------------------------ share picture (A.6)

    /**
     * A.6: JPEG / PNG / WebP ≤ 5 MB, ≥ 600 × 315, cover-cropped to 1200:630 and re-encoded at most 1200 × 630
     * (JPEG q85; PNG when the source PNG has an alpha channel), stored upload/site/share/<sha10>.<ext>.
     * Without GD: PNG / JPEG stored as-is (no crop). Returns ['ok','error','path','w','h'].
     */
    public static function share($tmp, $name = '', array $protect = [])
    {
        $check = self::check($tmp, 'share', $name);
        if (!$check['ok']) { return ['ok' => false, 'error' => $check['error'], 'path' => '', 'w' => 0, 'h' => 0]; }
        $w = $check['w'];
        $h = $check['h'];
        if (!self::available()) {
            $data = (string)file_get_contents($tmp);
            $ext = $check['type'] === 'png' ? 'png' : 'jpg';
            $outW = $w;
            $outH = $h;
        } else {
            $source = self::decode($tmp, $check['type']);
            if (!$source) { return ['ok' => false, 'error' => 'icon_broken_err', 'path' => '', 'w' => 0, 'h' => 0]; }
            $ratio = self::SHARE_W / self::SHARE_H;
            if ($w / $h > $ratio) { $cropH = $h; $cropW = (int)round($h * $ratio); } else { $cropW = $w; $cropH = (int)round($w / $ratio); }
            $cropX = (int)floor(($w - $cropW) / 2);
            $cropY = (int)floor(($h - $cropH) / 2);
            $outW = min(self::SHARE_W, $cropW);
            $outH = (int)round($outW / $ratio);
            $alpha = $check['type'] === 'png' && self::pngHasAlpha($tmp);
            $canvas = imagecreatetruecolor($outW, $outH);
            if ($alpha) {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            } else {
                imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            }
            imagecopyresampled($canvas, $source, 0, 0, $cropX, $cropY, $outW, $outH, $cropW, $cropH);
            self::free($source);
            ob_start();
            if ($alpha) { imagepng($canvas, null, 9); $ext = 'png'; } else { imagejpeg($canvas, null, 85); $ext = 'jpg'; }
            $data = (string)ob_get_clean();
            self::free($canvas);
            $info = @getimagesizefromstring($data);
            if (!is_array($info)) { return ['ok' => false, 'error' => 'icon_write_err', 'path' => '', 'w' => 0, 'h' => 0]; }
        }
        $root = self::shareRoot();
        if (!is_dir($root) && !@mkdir($root, 0755, true)) { return ['ok' => false, 'error' => 'icon_write_err', 'path' => '', 'w' => 0, 'h' => 0]; }
        $sha = substr(sha1($data), 0, 10);
        $file = realpath($root) . DIRECTORY_SEPARATOR . $sha . '.' . $ext;
        if (!self::atomicWrite($file, $data)) { return ['ok' => false, 'error' => 'icon_write_err', 'path' => '', 'w' => 0, 'h' => 0]; }
        self::prune($root, 'share', array_merge($protect, [$file]));
        return ['ok' => true, 'error' => '', 'path' => 'upload/site/share/' . $sha . '.' . $ext, 'w' => $outW, 'h' => $outH];
    }

    /** PNG colour type with alpha (4, 6) or a tRNS chunk before IDAT. */
    private static function pngHasAlpha($file)
    {
        $data = (string)file_get_contents($file, false, null, 0, 65536);
        $type = strlen($data) > 25 ? ord($data[25]) : 0;
        if ($type === 4 || $type === 6) { return true; }
        $trns = strpos($data, 'tRNS');
        $idat = strpos($data, 'IDAT');
        return $trns !== false && ($idat === false || $trns < $idat);
    }

    private static function atomicWrite($file, $data)
    {
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $data) !== strlen($data)) { @unlink($tmp); return false; }
        if (!@rename($tmp, $file)) {
            // Windows: rename() over an existing file can fail; the content-hashed name means the same bytes.
            @unlink($tmp);
            return is_file($file) && sha1_file($file) === sha1($data);
        }
        return true;
    }

    // ------------------------------------------------------------------ what the site prints (B.3, B.4)

    /** URL prefix of the site (MAC_PATH, '/' when not defined), always ending in '/'. */
    public static function base()
    {
        $base = defined('MAC_PATH') ? (string)MAC_PATH : '/';
        $base = '/' . trim(str_replace('\\', '/', $base), '/');
        return $base === '/' ? '/' : $base . '/';
    }

    /**
     * The icon set to print (B.4). $site = decoded overrides for previews / tests (keys site_icon_mode, site_icon).
     * Returns ['mode' => default|custom|theme, 'set' => 'default'|'custom'|'nogd'|'theme', 'dir' => folder relative to the root,
     * 'links' => [[rel, href(relative to the root), type, sizes], ...]]. A custom mode whose folder is missing falls back
     * to the default set.
     */
    public static function active(array $site = [])
    {
        $mode = array_key_exists('site_icon_mode', $site) ? (string)$site['site_icon_mode'] : (string)SeoSettings::get('site.site_icon_mode');
        if (!in_array($mode, ['default', 'custom', 'theme'], true)) { $mode = 'default'; }
        if ($mode === 'theme') { return ['mode' => 'theme', 'set' => 'theme', 'dir' => '', 'links' => []]; }
        if ($mode === 'custom') {
            $dir = array_key_exists('site_icon', $site) ? (string)$site['site_icon'] : (string)SeoSettings::get('site.site_icon');
            if (preg_match('~^upload/site/icon/[0-9a-f]{10}/$~D', $dir)) {
                if (is_file(self::root() . $dir . 'favicon.ico') && is_file(self::root() . $dir . 'icon-180.png')) {
                    return ['mode' => 'custom', 'set' => 'custom', 'dir' => $dir, 'links' => [
                        ['icon', $dir . 'favicon.ico', '', '32x32'],
                        ['icon', $dir . 'icon-48.png', 'image/png', '48x48'],
                        ['apple-touch-icon', $dir . 'icon-180.png', '', ''],
                    ]];
                }
                if (is_file(self::root() . $dir . 'icon-source.png')) {
                    return ['mode' => 'custom', 'set' => 'nogd', 'dir' => $dir, 'links' => [
                        ['icon', $dir . 'icon-source.png', 'image/png', ''],
                        ['apple-touch-icon', $dir . 'icon-source.png', '', ''],
                    ]];
                }
            }
        }
        $dir = self::DEFAULT_DIR;
        return ['mode' => $mode, 'set' => 'default', 'dir' => $dir, 'links' => [
            ['icon', $dir . 'favicon.ico', '', '32x32'],
            ['icon', $dir . 'icon.svg', 'image/svg+xml', ''],
            ['apple-touch-icon', $dir . 'icon-180.png', '', ''],
        ]];
    }

    /** The stored manifest path when its file exists ('' otherwise). */
    public static function manifest(array $site = [])
    {
        $path = array_key_exists('site_manifest', $site) ? (string)$site['site_manifest'] : (string)SeoSettings::get('site.site_manifest');
        return preg_match('~^upload/site/manifest-[0-9a-f]{8}\.json$~D', $path) && is_file(self::root() . $path) ? $path : '';
    }

    // ------------------------------------------------------------------ manifest (B.1 step 7)

    /**
     * Write upload/site/manifest-<sha8>.json for the active set. $site = the site values about to be saved (raw
     * maccms.php form: text HTML-encoded once; missing keys come from the stored settings). Returns ['ok', 'path', 'error'].
     * Keeps the KEEP newest manifests. The admin stores 'path' in site.site_manifest.
     */
    public static function writeManifest(array $site = [], array $protect = [])
    {
        $value = function ($key, $path) use ($site) {
            if (array_key_exists($key, $site)) { return SeoSettings::decode(is_scalar($site[$key]) ? $site[$key] : ''); }
            if ($path !== '') { return (string)SeoSettings::get($path); }
            return SeoSettings::decode($GLOBALS['config']['site'][$key] ?? '');
        };
        $siteName = trim(preg_replace('/\s+/u', ' ', $value('site_name', '')));
        $home = trim(preg_replace('/\s+/u', ' ', $value('site_home_title', 'site.site_home_title')));
        $color = $value('site_theme_color', 'site.site_theme_color');
        if (!preg_match('~^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$~D', $color)) { $color = '#111111'; }
        $active = self::active(['site_icon_mode' => $value('site_icon_mode', 'site.site_icon_mode'), 'site_icon' => $value('site_icon', 'site.site_icon')]);
        $base = self::base();
        $icons = [];
        if ($active['set'] === 'custom' || $active['set'] === 'default' || $active['set'] === 'theme') {
            $dir = $active['set'] === 'custom' ? $active['dir'] : self::DEFAULT_DIR;
            foreach (['icon-192.png' => '', 'icon-512.png' => '', 'icon-512-maskable.png' => 'maskable'] as $file => $purpose) {
                $info = is_file(self::root() . $dir . $file) ? @getimagesize(self::root() . $dir . $file) : false;
                if (!is_array($info)) { continue; }
                $icon = ['src' => $base . $dir . $file, 'sizes' => $info[0] . 'x' . $info[1], 'type' => 'image/png'];
                if ($purpose !== '') { $icon['purpose'] = $purpose; }
                $icons[] = $icon;
            }
        }
        $manifest = [
            'name' => $home !== '' ? $home : ($siteName !== '' ? $siteName : 'JavCMS'),
            'short_name' => self::shortName($siteName !== '' ? $siteName : 'JavCMS'),
            'start_url' => $base,
            'scope' => $base,
            'display' => 'standalone',
            'background_color' => $color,
            'theme_color' => $color,
            'icons' => $icons,
        ];
        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) { return ['ok' => false, 'path' => '', 'error' => 'icon_write_err']; }
        $json .= "\n";
        $root = self::siteRoot();
        if (!is_dir($root) && !@mkdir($root, 0755, true)) { return ['ok' => false, 'path' => '', 'error' => 'icon_write_err']; }
        $name = 'manifest-' . substr(sha1($json), 0, 8) . '.json';
        $file = realpath($root) . DIRECTORY_SEPARATOR . $name;
        if (!self::atomicWrite($file, $json)) { return ['ok' => false, 'path' => '', 'error' => 'icon_write_err']; }
        @touch($file);
        self::prune($root, 'manifest', array_merge($protect, [$file]));
        return ['ok' => true, 'path' => 'upload/site/' . $name, 'error' => ''];
    }

    /** ≤ 12 characters, cut on a word when possible (B.1 step 7). */
    public static function shortName($name)
    {
        $name = trim((string)$name);
        $len = function ($s) { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); };
        $sub = function ($s, $n) { return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n); };
        if ($len($name) <= 12) { return $name; }
        $cut = $sub($name, 13);
        $space = strrpos($cut, ' ');
        $short = $space !== false && $space > 0 ? substr($cut, 0, $space) : $sub($name, 12);
        return rtrim($short, " \t-|,.:;");
    }

    // ------------------------------------------------------------------ root /favicon.ico (B.1 step 6)

    /**
     * On Save: copy the custom favicon.ico (mode custom) or the shipped JavCMS one (mode default) to ROOT/favicon.ico
     * only when the current root file is the shipped one (md5 computed now, CF20) or our last write ($storedMd5).
     * Returns ['md5' => value for site.site_icon_root_md5, 'notice' => '' | 'icon_root_foreign' | 'icon_root_ro', 'changed' => bool].
     */
    public static function syncRoot($mode, $iconDir = '', $storedMd5 = '')
    {
        $root = self::root();
        $target = $root . 'favicon.ico';
        $shipped = $root . self::SHIPPED_ICO;
        $storedMd5 = preg_match('/^[0-9a-f]{32}$/D', (string)$storedMd5) ? (string)$storedMd5 : '';
        $same = ['md5' => $storedMd5, 'notice' => '', 'changed' => false];
        if ($mode === 'custom') {
            if (!preg_match('~^upload/site/icon/[0-9a-f]{10}/$~D', (string)$iconDir) || !is_file($root . $iconDir . 'favicon.ico')) { return $same; }
            $source = $root . $iconDir . 'favicon.ico';
        } elseif ($mode === 'default') {
            if (!is_file($shipped)) { return $same; }
            $source = $shipped;
        } else {
            return $same;
        }
        $current = is_file($target) ? (string)md5_file($target) : '';
        $shippedMd5 = is_file($shipped) ? (string)md5_file($shipped) : '';
        $ours = $current === '' || ($shippedMd5 !== '' && $current === $shippedMd5) || ($storedMd5 !== '' && $current === $storedMd5);
        if (!$ours) { return ['md5' => $storedMd5, 'notice' => 'icon_root_foreign', 'changed' => false]; }
        $new = (string)md5_file($source);
        if ($new === $current) { return ['md5' => $new, 'notice' => '', 'changed' => false]; }
        $data = (string)file_get_contents($source);
        $tmp = $root . '.favicon-' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $data) !== strlen($data)) {
            @unlink($tmp);
            return ['md5' => $storedMd5, 'notice' => 'icon_root_ro', 'changed' => false];
        }
        if (!@rename($tmp, $target)) {
            $ok = @copy($tmp, $target);
            @unlink($tmp);
            if (!$ok) { return ['md5' => $storedMd5, 'notice' => 'icon_root_ro', 'changed' => false]; }
        }
        return ['md5' => $new, 'notice' => '', 'changed' => true];
    }
}
