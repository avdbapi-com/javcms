<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

/** Original project portrait-only upload boundary. No editor/remote/base64 handling. */
class PortraitUpload
{
    public static function validToken($submitted, $stored)
    {
        return is_string($submitted) && is_string($stored) && $stored !== ''
            && strlen($submitted) <= 256 && hash_equals($stored, $submitted);
    }

    private static function failure($key)
    {
        throw new \RuntimeException($key);
    }

    private static function normalized($path)
    {
        $value = rtrim(str_replace('\\', '/', $path), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
    }

    private static function directory($base, $parts)
    {
        $path = realpath($base);
        if (!$path || is_link($base)) self::failure('admin/upload/upload_faild');
        foreach ($parts as $part) {
            $next = $path . DIRECTORY_SEPARATOR . $part;
            if (is_link($next)) self::failure('admin/upload/upload_faild');
            if (!is_dir($next) && !@mkdir($next, 0755) && !is_dir($next)) self::failure('admin/upload/upload_faild');
            $resolved = realpath($next);
            if (!$resolved || self::normalized($resolved) !== self::normalized($next)) self::failure('admin/upload/upload_faild');
            $path = $resolved;
        }
        return $path;
    }

    private static function temporary($directory)
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = $directory . DIRECTORY_SEPARATOR . '.portrait-' . bin2hex(random_bytes(16)) . '.tmp';
            $handle = @fopen($path, 'x+b');
            if ($handle) { @chmod($path, 0600); fclose($handle); return $path; }
        }
        self::failure('admin/upload/upload_faild');
    }

    private static function memoryBytes()
    {
        $raw = trim((string)ini_get('memory_limit'));
        if ($raw === '-1') return 512 * 1024 * 1024;
        $number = (float)$raw;
        $suffix = strtolower(substr($raw, -1));
        if ($suffix === 'g') $number *= 1024 * 1024 * 1024;
        elseif ($suffix === 'm') $number *= 1024 * 1024;
        elseif ($suffix === 'k') $number *= 1024;
        return $number > 0 ? $number : 128 * 1024 * 1024;
    }

    /**
     * $transaction(relativePath, publish) must update the authenticated row in
     * a DB transaction, invoke publish once, then commit; throw/false on failure.
     * Previous avatar is retained on validation/update/commit failure. A storage
     * failure that also prevents restoration is surfaced, never hidden.
     */
    public static function save(array $upload, $userId, $portraitSize, $applicationRoot, callable $transaction)
    {
        $prepared = null; $backup = null; $lock = null; $source = null; $output = null;
        $canonical = null; $published = false; $hadOld = false;
        try {
            if (!is_int($userId) || $userId < 1) self::failure('index/portrait_err');
            if (!isset($upload['error'], $upload['tmp_name'], $upload['name']) || (int)$upload['error'] !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name']) || !is_string($upload['name']) || !is_uploaded_file($upload['tmp_name'])) self::failure('admin/upload/no_input_file');
            $bytes = filesize($upload['tmp_name']);
            if ($bytes === false || $bytes < 1 || $bytes > 8 * 1024 * 1024) self::failure('admin/upload/forbidden_ext');
            $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
            $formats = [IMAGETYPE_JPEG => ['jpg','jpeg'], IMAGETYPE_PNG => ['png'], IMAGETYPE_GIF => ['gif']];
            $decoders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_GIF => 'imagecreatefromgif'];
            if (defined('IMAGETYPE_WEBP')) { $formats[IMAGETYPE_WEBP] = ['webp']; $decoders[IMAGETYPE_WEBP] = 'imagecreatefromwebp'; }
            $dimensions = @getimagesize($upload['tmp_name']);
            if (!$dimensions || !isset($formats[$dimensions[2]]) || !in_array($extension, $formats[$dimensions[2]], true)
                || !function_exists($decoders[$dimensions[2]]) || !function_exists('imagejpeg')) self::failure('admin/upload/forbidden_ext');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            $expected = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_GIF => 'image/gif'];
            if (defined('IMAGETYPE_WEBP')) $expected[IMAGETYPE_WEBP] = 'image/webp';
            if ($mime !== $expected[$dimensions[2]]) self::failure('admin/upload/forbidden_ext');
            $width = (int)$dimensions[0]; $height = (int)$dimensions[1];
            $size = preg_match('/^([1-9][0-9]{0,3})(?:x([1-9][0-9]{0,3}))?$/iD', (string)$portraitSize, $match) ? [(int)$match[1], isset($match[2]) ? (int)$match[2] : (int)$match[1]] : [150,150];
            if ($size[0] > 1024 || $size[1] > 1024) self::failure('index/portrait_err');
            $estimated = $width * $height * 8 + $size[0] * $size[1] * 8 + $bytes * 2 + 16 * 1024 * 1024;
            if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000 || $width * $height > 32000000
                || $estimated + memory_get_usage(true) > self::memoryBytes()) self::failure('admin/upload/forbidden_ext');
            $source = @$decoders[$dimensions[2]]($upload['tmp_name']);
            if (!$source) self::failure('admin/upload/make_thumb_faild');
            $output = imagecreatetruecolor($size[0], $size[1]);
            if (!$output) self::failure('admin/upload/make_thumb_faild');
            imagefill($output, 0, 0, imagecolorallocate($output, 255, 255, 255));
            $ratio = max($size[0] / $width, $size[1] / $height);
            $cropWidth = $size[0] / $ratio; $cropHeight = $size[1] / $ratio;
            if (!imagecopyresampled($output, $source, 0, 0, (int)(($width-$cropWidth)/2), (int)(($height-$cropHeight)/2), $size[0], $size[1], (int)$cropWidth, (int)$cropHeight)) self::failure('admin/upload/make_thumb_faild');
            // No public/canonical write until every input/decoder limit has passed.
            $directory = self::directory($applicationRoot, ['upload','user',(string)($userId % 10)]);
            $canonical = $directory . DIRECTORY_SEPARATOR . $userId . '.jpg';
            if (is_link($canonical)) self::failure('admin/upload/upload_faild');
            $lockPath = $directory . DIRECTORY_SEPARATOR . '.portrait-' . $userId . '.lock';
            if (is_link($lockPath)) self::failure('admin/upload/upload_faild');
            $lock = @fopen($lockPath, 'c+b');
            if (!$lock || !flock($lock, LOCK_EX)) self::failure('admin/upload/upload_faild');
            $prepared = self::temporary($directory);
            if (!imagejpeg($output, $prepared, 85)) self::failure('admin/upload/make_thumb_faild');
            $verified = @getimagesize($prepared);
            if (!$verified || $verified[2] !== IMAGETYPE_JPEG || $verified[0] !== $size[0] || $verified[1] !== $size[1]) self::failure('admin/upload/make_thumb_faild');
            @chmod($prepared, 0644);
            $hadOld = is_file($canonical);
            if ($hadOld) {
                $backup = self::temporary($directory);
                if (!copy($canonical, $backup)) self::failure('admin/upload/upload_faild');
                @chmod($backup, fileperms($canonical) & 0777);
            }
            $relative = 'upload/user/' . ($userId % 10) . '/' . $userId . '.jpg';
            $publish = function () use (&$prepared, &$published, $canonical) {
                if ($published || !$prepared || is_link($canonical)) self::failure('admin/upload/upload_faild');
                // Windows and Linux replacement behavior is exercised by tests.
                // Never delete canonical first; failed replacement preserves it.
                if (!@rename($prepared, $canonical)) self::failure('admin/upload/upload_faild');
                $prepared = null; $published = true;
            };
            if ($transaction($relative, $publish) !== true || !$published) self::failure('index/portrait_err');
            return ['code'=>1, 'message_key'=>'admin/upload/upload_success', 'file'=>$relative];
        } catch (\Throwable $error) {
            if ($published) {
                if ($hadOld) {
                    if (!$backup || !@rename($backup, $canonical)) {
                        // Retain the owned backup for recovery; never discard the previous bytes.
                        $backup = null;
                        return ['code'=>0, 'message_key'=>'index/portrait_err', 'file'=>''];
                    }
                    $backup = null;
                } elseif (is_file($canonical)) { @unlink($canonical); }
            }
            $allowed = ['index/portrait_err','admin/upload/no_input_file','admin/upload/forbidden_ext','admin/upload/upload_faild','admin/upload/make_thumb_faild'];
            $key = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'index/portrait_err';
            return ['code'=>0, 'message_key'=>$key, 'file'=>''];
        } finally {
            if (is_resource($source)) imagedestroy($source);
            if (is_resource($output)) imagedestroy($output);
            $source = null; $output = null;
            foreach ([$prepared,$backup] as $owned) if ($owned && is_file($owned)) @unlink($owned);
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}
