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

/**
 * IndexNow pings (SEO-ADMIN-BRIEF E.3). Off by default (seo.indexnow = 0); the owner switches it on.
 *
 * - Key: seo.indexnow_key (32 hex) in maccms.php, the key file ROOT_PATH/<key>.txt holds the key. A file of that name
 *   with other content is never overwritten ("foreign"); a read-only root gives "download" (upload it by hand).
 * - Queue: runtime/seo/indexnow-queue.txt, canonical default-language video URLs (C.3) of videos changed after
 *   seo.indexnow_since, deleted videos too (queued before deletion). Deduplicated, at most 50,000 lines (oldest dropped).
 * - Send: flush(), under a file lock, at most one POST per 60 s, at most 10,000 URLs per POST and seo.indexnow_daily
 *   URLs per UTC day; 200 / 202 drop the sent lines, 429 backs off one hour, 403 / 422 stop until the key changes.
 * - Status: runtime/seo/indexnow.json (never maccms.php: a write there re-keys every cached page).
 * The only outbound call of the CMS PHP code: one curl POST to api.indexnow.org, 5 s timeout, no retry in the request.
 */
class SeoPing
{
    const ENDPOINT = 'https://api.indexnow.org/indexnow';
    const MIN_INTERVAL = 60;
    const MAX_PER_POST = 10000;
    const QUEUE_MAX = 50000;
    const BACKOFF = 3600;
    const TIMEOUT = 5;

    /** Test hook (CLI only, 127.0.0.1 only): see useTestEndpoint(). */
    private static $endpoint = self::ENDPOINT;

    /**
     * Point the sender at a local mock endpoint for the acceptance check (S-C8). Accepted only from the command line
     * and only for http://127.0.0.1:<port>/..., so a web request can never redirect the pings.
     */
    public static function useTestEndpoint($url)
    {
        if (PHP_SAPI !== 'cli' || !is_string($url) || !preg_match('~^http://127\.0\.0\.1:[0-9]{2,5}/[A-Za-z0-9/_.-]*$~D', $url)) { return false; }
        self::$endpoint = $url;
        return true;
    }

    // ------------------------------------------------------------------ settings and files

    public static function key()
    {
        $key = (string) SeoSettings::get('seo.indexnow_key');
        return preg_match('/^[a-f0-9]{32}$/D', $key) ? $key : '';
    }

    /** seo.indexnow = 1 with a valid key. */
    public static function enabled()
    {
        return SeoSettings::bool('seo.indexnow') && self::key() !== '';
    }

    /** A new key for "Make a new key" / switching on (the admin stores it in seo.indexnow_key). */
    public static function newKey()
    {
        return bin2hex(random_bytes(16));
    }

    public static function dir()
    {
        return (defined('RUNTIME_PATH') ? RUNTIME_PATH : ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR) . 'seo' . DIRECTORY_SEPARATOR;
    }

    public static function queueFile() { return self::dir() . 'indexnow-queue.txt'; }

    public static function statusFile() { return self::dir() . 'indexnow.json'; }

    private static function lockFile() { return self::dir() . 'indexnow.lock'; }

    public static function keyFile($key)
    {
        return ROOT_PATH . $key . '.txt';
    }

    /** Absolute URL of the key file (keyLocation), '' without a site domain. */
    public static function keyUrl($key)
    {
        return Sitemap::absolute((defined('MAC_PATH') ? MAC_PATH : '/') . $key . '.txt');
    }

    /** 'ok' (the file holds the key), 'missing', or 'foreign' (a file of that name with other content). */
    public static function keyFileState($key)
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', (string) $key)) { return 'missing'; }
        $file = self::keyFile($key);
        if (is_link($file)) { return 'foreign'; }
        if (!is_file($file)) { return file_exists($file) ? 'foreign' : 'missing'; }
        $content = @file_get_contents($file, false, null, 0, 1024);
        return is_string($content) && trim($content) === $key ? 'ok' : 'foreign';
    }

    /**
     * Write ROOT_PATH/<key>.txt containing the key. Never overwrites an existing file with other content.
     * Returns ['ok' => bool, 'error' => '' | 'bad_key' | 'foreign' | 'readonly', 'download' => bool (offer the file)].
     */
    public static function writeKeyFile($key)
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', (string) $key)) { return ['ok' => false, 'error' => 'bad_key', 'download' => false]; }
        $state = self::keyFileState($key);
        if ($state === 'ok') { return ['ok' => true, 'error' => '', 'download' => false]; }
        if ($state === 'foreign') { return ['ok' => false, 'error' => 'foreign', 'download' => false]; }
        $handle = @fopen(self::keyFile($key), 'x'); // exclusive create: never replaces a file that appeared meanwhile
        if ($handle === false) {
            return self::keyFileState($key) === 'foreign' ? ['ok' => false, 'error' => 'foreign', 'download' => false]
                : ['ok' => false, 'error' => 'readonly', 'download' => true];
        }
        $written = fwrite($handle, $key);
        fclose($handle);
        return $written === strlen($key) ? ['ok' => true, 'error' => '', 'download' => false] : ['ok' => false, 'error' => 'readonly', 'download' => true];
    }

    /** Remove the key file of an old key, only when it holds exactly that key. */
    public static function removeKeyFile($key)
    {
        return self::keyFileState($key) === 'ok' && @unlink(self::keyFile($key));
    }

    // ------------------------------------------------------------------ status

    private static function readStatus()
    {
        $data = is_file(self::statusFile()) ? json_decode((string) @file_get_contents(self::statusFile()), true) : null;
        $status = is_array($data) ? $data : [];
        return array_merge(['last_post' => 0, 'last_code' => 0, 'last_count' => 0, 'day' => '', 'sent_today' => 0,
            'backoff_until' => 0, 'stop_key' => '', 'error' => ''], $status);
    }

    private static function writeStatus(array $status)
    {
        self::ensureDir();
        $file = self::statusFile();
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($status, JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            @file_put_contents($file, json_encode($status, JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
    }

    /**
     * For the admin tab "IndexNow": last send time and HTTP code, URLs sent today, queue length, back-off end,
     * error ('' | key_err | host_err | rate | network | http_<code>), key file state.
     */
    public static function status()
    {
        $status = self::readStatus();
        if ($status['day'] !== gmdate('Y-m-d')) { $status['sent_today'] = 0; }
        $key = self::key();
        $status['queue'] = self::queueLength();
        $status['enabled'] = self::enabled();
        $status['key_file'] = $key === '' ? 'missing' : self::keyFileState($key);
        $status['stopped'] = $key !== '' && $status['stop_key'] === $key;
        unset($status['stop_key']);
        return $status;
    }

    private static function ensureDir()
    {
        if (!is_dir(self::dir())) { @mkdir(self::dir(), 0755, true); }
    }

    // ------------------------------------------------------------------ queue

    /** @return string[] queued URLs, oldest first */
    private static function readQueue()
    {
        if (!is_file(self::queueFile())) { return []; }
        $lines = preg_split('/\r\n|\n/', (string) @file_get_contents(self::queueFile()));
        return array_values(array_filter($lines, function ($line) { return $line !== ''; }));
    }

    public static function queueLength()
    {
        return count(self::readQueue());
    }

    /** Run $fn(array $lines): array under an exclusive lock of the queue file and store what it returns. */
    private static function editQueue(callable $fn)
    {
        self::ensureDir();
        $handle = @fopen(self::queueFile(), 'c+');
        if ($handle === false) { return false; }
        try {
            if (!flock($handle, LOCK_EX)) { return false; }
            $raw = stream_get_contents($handle);
            $lines = array_values(array_filter(preg_split('/\r\n|\n/', (string) $raw), function ($line) { return $line !== ''; }));
            $lines = array_values($fn($lines));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $lines ? implode("\n", $lines) . "\n" : '');
            fflush($handle);
            flock($handle, LOCK_UN);
            return true;
        } finally {
            fclose($handle);
        }
    }

    /** Append URLs (absolute http(s) on this site), deduplicated, at most QUEUE_MAX lines (the oldest are dropped). */
    public static function queueUrls(array $urls)
    {
        $host = self::host();
        $clean = [];
        foreach ($urls as $url) {
            if (!is_string($url) || strlen($url) > 2000 || !preg_match('~^https?://[^\s<>"\']+$~i', $url)) { continue; }
            if ($host === '' || strcasecmp((string) parse_url($url, PHP_URL_HOST), $host) !== 0) { continue; }
            $clean[$url] = true;
        }
        if (!$clean) { return 0; }
        $added = 0;
        self::editQueue(function (array $lines) use ($clean, &$added) {
            $present = array_flip($lines);
            foreach (array_keys($clean) as $url) {
                if (isset($present[$url])) { continue; }
                $lines[] = $url;
                $present[$url] = true;
                $added++;
            }
            return count($lines) > self::QUEUE_MAX ? array_slice($lines, -self::QUEUE_MAX) : $lines;
        });
        return $added;
    }

    /**
     * Queue the canonical default-language URLs of videos (C.3). No-op while IndexNow is off. Only videos changed
     * since IndexNow was switched on (vod_time >= seo.indexnow_since), unless $force: what the owner saves, hides or
     * deletes right now in the admin is always queued (a deleted video is queued before deletion; its URL then answers
     * 404, which IndexNow wants). Language URLs are not pinged. Returns the number of URLs added.
     */
    public static function queueVods(array $ids, $force = false)
    {
        if (!self::enabled() || !$ids) { return 0; }
        $since = SeoSettings::int('seo.indexnow_since');
        $urls = [];
        foreach (Sitemap::videoUrls(array_slice($ids, 0, self::QUEUE_MAX)) as $row) {
            if ($force || $row['time'] >= $since) { $urls[] = $row['url']; }
        }
        return self::queueUrls($urls);
    }

    /** Host of the canonical site URLs ('' without a configured domain). */
    private static function host()
    {
        $base = Sitemap::absolute(defined('MAC_PATH') ? MAC_PATH : '/');
        return $base === '' ? '' : strtolower((string) parse_url($base, PHP_URL_HOST));
    }

    // ------------------------------------------------------------------ send

    /**
     * Send the oldest queued URLs when allowed. Never throws. Returns ['sent' => n, 'code' => HTTP code or 0,
     * 'skipped' => '' | off | busy | stopped | backoff | interval | daily | empty | no_host].
     */
    public static function flush()
    {
        $result = ['sent' => 0, 'code' => 0, 'skipped' => ''];
        try {
            if (!self::enabled()) { $result['skipped'] = 'off'; return $result; }
            self::ensureDir();
            $lock = @fopen(self::lockFile(), 'c');
            if ($lock === false) { $result['skipped'] = 'busy'; return $result; }
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) { $result['skipped'] = 'busy'; return $result; }
                $result = self::send();
                flock($lock, LOCK_UN);
            } finally {
                fclose($lock);
            }
        } catch (\Throwable $error) {
            $result['skipped'] = 'error';
        }
        return $result;
    }

    private static function send()
    {
        $result = ['sent' => 0, 'code' => 0, 'skipped' => ''];
        $key = self::key();
        $now = time();
        $status = self::readStatus();
        if ($status['stop_key'] !== '' && $status['stop_key'] === $key) { $result['skipped'] = 'stopped'; return $result; }
        if ((int) $status['backoff_until'] > $now) { $result['skipped'] = 'backoff'; return $result; }
        if ($now - (int) $status['last_post'] < self::MIN_INTERVAL) { $result['skipped'] = 'interval'; return $result; }
        $today = gmdate('Y-m-d', $now);
        if ($status['day'] !== $today) { $status['day'] = $today; $status['sent_today'] = 0; }
        $allowed = min(self::MAX_PER_POST, SeoSettings::int('seo.indexnow_daily') - (int) $status['sent_today']);
        if ($allowed <= 0) { $result['skipped'] = 'daily'; return $result; }
        $host = self::host();
        $keyUrl = self::keyUrl($key);
        if ($host === '' || $keyUrl === '') { $result['skipped'] = 'no_host'; return $result; }
        $batch = [];
        foreach (self::readQueue() as $url) {
            // URLs of another host (the domain changed) would make the whole POST fail (422): they are dropped below.
            if (strcasecmp((string) parse_url($url, PHP_URL_HOST), $host) === 0) { $batch[] = $url; }
            if (count($batch) >= $allowed) { break; }
        }
        if (!$batch) {
            self::editQueue(function (array $lines) use ($host) {
                return array_filter($lines, function ($url) use ($host) { return strcasecmp((string) parse_url($url, PHP_URL_HOST), $host) === 0; });
            });
            $result['skipped'] = 'empty';
            return $result;
        }
        $code = self::post(['host' => $host, 'key' => $key, 'keyLocation' => $keyUrl, 'urlList' => $batch]);
        $status['last_post'] = $now;
        $status['last_code'] = $code;
        $status['last_count'] = count($batch);
        $drop = false;
        if ($code === 200 || $code === 202) {
            $drop = true;
            $status['sent_today'] = (int) $status['sent_today'] + count($batch);
            $status['error'] = '';
            $result['sent'] = count($batch);
        } elseif ($code === 429) {
            $status['backoff_until'] = $now + self::BACKOFF;
            $status['error'] = 'rate';
        } elseif ($code === 403 || $code === 422) {
            $status['stop_key'] = $key;
            $status['error'] = $code === 403 ? 'key_err' : 'host_err';
        } elseif ($code === 400) {
            $drop = true; // a malformed batch never succeeds: do not resend it every minute
            $status['error'] = 'http_400';
        } else {
            $status['error'] = $code === 0 ? 'network' : 'http_' . $code;
        }
        if ($drop) {
            $sent = array_flip($batch);
            self::editQueue(function (array $lines) use ($sent, $host) {
                return array_filter($lines, function ($url) use ($sent, $host) {
                    return !isset($sent[$url]) && strcasecmp((string) parse_url($url, PHP_URL_HOST), $host) === 0;
                });
            });
        }
        self::writeStatus($status);
        $result['code'] = $code;
        return $result;
    }

    /** The one outbound request: POST JSON to the IndexNow endpoint. Returns the HTTP code, 0 on a network error. */
    private static function post(array $body)
    {
        if (!function_exists('curl_init')) { return 0; }
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $curl = curl_init(self::$endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT => 'JavCMS-IndexNow/1.0',
        ]);
        curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80000) { curl_close($curl); }
        return $code;
    }
}
