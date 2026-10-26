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
namespace app\common\util\updater;

/**
 * HTTPS client of the updater: allow-listed hosts only, certificates always verified, redirects followed hop by hop.
 *
 * Transports, in this order (UPDATER-SCOUT-HOST rule 8): ext-curl with the CA bundle shipped next to this file (Windows
 * PHP has no default CA for curl; hosts often carry an outdated one), then a TLS socket with an own small HTTP/1.1 client
 * (works when allow_url_fopen is off), then PHP's https:// stream wrapper. Plain http only in test mode, on loopback.
 *
 * Every hop of a redirect chain must be https and on Config::HOSTS (GitHub sends release files through
 * release-assets.githubusercontent.com). Logs and errors carry host and path only: the query of an asset link is a
 * signed credential.
 *
 * download() is resumable: it appends to a .part file with "Range: bytes=<size>-", accepts only 206 for a resume (a 200
 * restarts from zero), stops at the step's deadline and is called again by the next step.
 */
final class Http
{
    /** Largest answer kept in memory (release JSON is 25 KB, a manifest about 650 KB). */
    const MAX_BODY = 8388608;
    const MAX_HOPS = 6;
    const CONNECT_TIMEOUT = 10;
    /** Header lines of one answer at most (GitHub sends about 30; anything far beyond is not a real server). */
    const MAX_HEADER_LINES = 200;

    /** @var string forced transport for tests: '', 'curl', 'socket', 'stream' */
    public static $force = '';
    /** @var array transport names tried by the last request */
    public static $tried = array();

    /** Path of the CA bundle shipped with the updater. */
    public static function caBundle()
    {
        $cfg = Config::endpoints();
        if ($cfg['cafile'] !== '' && is_file($cfg['cafile'])) {
            return $cfg['cafile'];
        }
        return __DIR__ . '/cacert.pem';
    }

    /** True when the URL may be requested: https (http only in test mode) and an allowed host[:port]. */
    public static function allowed($url)
    {
        $p = @parse_url((string)$url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return false;
        }
        $cfg = Config::endpoints();
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $cfg['http'])) {
            return false;
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        $host = strtolower($p['host']);
        $port = isset($p['port']) ? (int)$p['port'] : ($scheme === 'https' ? 443 : 80);
        foreach ($cfg['hosts'] as $h) {
            $h = strtolower((string)$h);
            if ($h === $host && ($port === 443 || $port === 80 && $scheme === 'http' || $cfg['test'])) {
                return true;
            }
            if ($h === $host . ':' . $port) {
                return true;
            }
        }
        return false;
    }

    /** Host + path of a URL for logs (no query: asset links carry a signed credential). */
    public static function show($url)
    {
        $p = @parse_url((string)$url);
        if (!is_array($p) || empty($p['host'])) {
            return '(invalid address)';
        }
        return strtolower($p['host']) . (isset($p['port']) ? ':' . (int)$p['port'] : '') . ($p['path'] ?? '/');
    }

    /**
     * GET $url and follow redirects. Returns ['ok' => bool (2xx/304), 'status', 'headers' (lower-case names), 'body',
     * 'url' (last hop), 'error', 'transport', 'hops'].
     */
    public static function get($url, array $headers = array(), $timeout = 20.0)
    {
        $deadline = microtime(true) + max(1.0, (float)$timeout);
        $hops = array();
        $current = (string)$url;
        for ($i = 0; $i < self::MAX_HOPS; $i++) {
            if (!self::allowed($current)) {
                return self::fail('address not allowed: ' . self::show($current), $hops);
            }
            $hops[] = self::show($current);
            $r = self::once('GET', $current, $headers, $deadline, null, 0);
            if ($r['error'] !== '' && $r['status'] === 0) {
                $r['hops'] = $hops;
                return $r;
            }
            if (in_array($r['status'], array(301, 302, 303, 307, 308), true)) {
                $loc = (string)($r['headers']['location'] ?? '');
                if ($loc === '') {
                    return self::fail('redirect without a location', $hops);
                }
                $current = self::resolve($current, $loc);
                if ($current === '') {
                    return self::fail('unsupported redirect (not http or https)', $hops);
                }
                // the next hop goes to another host: conditional headers and credentials never travel along
                unset($headers['If-None-Match'], $headers['Authorization']);
                continue;
            }
            $r['ok'] = ($r['status'] >= 200 && $r['status'] < 300) || $r['status'] === 304;
            $r['url'] = $current;
            $r['hops'] = $hops;
            return $r;
        }
        return self::fail('too many redirects', $hops);
    }

    /**
     * One resumable download step of $url into $part (appended). Returns ['state' => 'done'|'more'|'error', 'bytes'
     * (size of $part now), 'total' (expected size or 0), 'error', 'status', 'transport'].
     * 'done' = the server sent everything up to $expect (or the end of the file when $expect is 0).
     */
    public static function download($url, $part, $deadline, $expect = 0)
    {
        $expect = (int)$expect;
        $have = is_file($part) ? (int)filesize($part) : 0;
        if ($expect > 0 && $have === $expect) {
            return array('state' => 'done', 'bytes' => $have, 'total' => $expect, 'error' => '', 'status' => 0, 'transport' => '');
        }
        if ($expect > 0 && $have > $expect) {
            @unlink($part);
            $have = 0;
        }
        // every transport stops writing the moment the part would grow past the signed size (an over-long answer never
        // fills the disk until the step's deadline)
        self::$cap = $expect > 0 ? $expect : 0;
        try {
            return self::downloadHops($url, $part, $deadline, $expect, $have);
        } finally {
            self::$cap = 0;
        }
    }

    /** Most bytes the file of the download in progress may have (the signed size), 0 = no limit. */
    private static $cap = 0;

    /** The hops of one download step (download() set the size cap). */
    private static function downloadHops($url, $part, $deadline, $expect, $have)
    {
        $hops = array();
        $current = (string)$url;
        for ($i = 0; $i < self::MAX_HOPS; $i++) {
            if (!self::allowed($current)) {
                return array('state' => 'error', 'bytes' => $have, 'total' => $expect, 'error' => 'address not allowed: ' . self::show($current), 'status' => 0, 'transport' => '');
            }
            $hops[] = self::show($current);
            $h = array();
            if ($have > 0) {
                $h['Range'] = 'bytes=' . $have . '-';
            }
            $r = self::once('GET', $current, $h, $deadline, $part, $have);
            if (in_array($r['status'], array(301, 302, 303, 307, 308), true)) {
                $loc = (string)($r['headers']['location'] ?? '');
                if ($loc === '') {
                    return array('state' => 'error', 'bytes' => $have, 'total' => $expect, 'error' => 'redirect without a location', 'status' => $r['status'], 'transport' => $r['transport']);
                }
                $current = self::resolve($current, $loc);
                if ($current === '') {
                    return array('state' => 'error', 'bytes' => $have, 'total' => $expect, 'error' => 'unsupported redirect (not http or https)', 'status' => $r['status'], 'transport' => $r['transport']);
                }
                continue;
            }
            clearstatcache(true, $part);
            $now = is_file($part) ? (int)filesize($part) : 0;
            $total = $expect;
            if ($r['status'] === 416 && $expect > 0 && $now === $expect) {
                return array('state' => 'done', 'bytes' => $now, 'total' => $expect, 'error' => '', 'status' => 416, 'transport' => $r['transport']);
            }
            if ($r['status'] !== 200 && $r['status'] !== 206) {
                $err = $r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['status'];
                return array('state' => 'error', 'bytes' => $now, 'total' => $total, 'error' => $err, 'status' => $r['status'], 'transport' => $r['transport']);
            }
            if ($r['error'] === 'unexpected range') {
                // the server answered the resume with another range than asked: the part is not trusted any more, the
                // caller starts this source again from zero (without a Range header)
                @unlink($part);
                return array('state' => 'error', 'bytes' => 0, 'total' => $total, 'error' => 'unexpected range', 'status' => $r['status'],
                    'transport' => $r['transport'], 'range_bad' => true);
            }
            if ($r['error'] === 'more data than expected') {
                // the transport stopped at the signed size: the answer is longer than the release file can be
                @unlink($part);
                return array('state' => 'error', 'bytes' => 0, 'total' => $total, 'error' => 'more data than expected', 'status' => $r['status'], 'transport' => $r['transport']);
            }
            if ($total === 0) {
                $total = (int)($r['total'] ?? 0);
            }
            if ($total > 0 && $now > $total) {
                @unlink($part);
                return array('state' => 'error', 'bytes' => 0, 'total' => $total, 'error' => 'more data than expected', 'status' => $r['status'], 'transport' => $r['transport']);
            }
            $finished = $total > 0 ? $now === $total : ($r['complete'] ?? false);
            if ($finished) {
                return array('state' => 'done', 'bytes' => $now, 'total' => $total, 'error' => '', 'status' => $r['status'], 'transport' => $r['transport']);
            }
            // cut by the step's deadline (or a dropped connection): the next step resumes from $now
            return array('state' => 'more', 'bytes' => $now, 'total' => $total, 'error' => $r['error'], 'status' => $r['status'], 'transport' => $r['transport']);
        }
        return array('state' => 'error', 'bytes' => $have, 'total' => $expect, 'error' => 'too many redirects', 'status' => 0, 'transport' => '');
    }

    /**
     * Absolute URL of a Location header (or a link). '' for an address with another scheme than http / https
     * ("file:///...", "gopher://...", "mailto:..."): such a redirect is refused, never read as a path on this host.
     */
    public static function resolve($base, $loc)
    {
        $loc = (string)$loc;
        if (preg_match('#^https?://#i', $loc)) {
            return $loc;
        }
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*):#', $loc)) {
            return '';
        }
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (strncmp($loc, '//', 2) === 0) {
            return $p['scheme'] . ':' . $loc;
        }
        if ($loc !== '' && $loc[0] === '/') {
            return $origin . $loc;
        }
        $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
        return $origin . $dir . $loc;
    }

    private static function fail($error, array $hops)
    {
        return array('ok' => false, 'status' => 0, 'headers' => array(), 'body' => '', 'url' => '', 'error' => $error, 'transport' => '', 'hops' => $hops);
    }

    // ------------------------------------------------------------------ one request, transport chain

    /**
     * One request without redirects. $file: append the body there (download) starting at offset $have; null = body in
     * memory. Returns ['status', 'headers', 'body', 'error', 'transport', 'total' (full size from Content-Range /
     * Content-Length), 'complete' (body ended normally)].
     */
    private static function once($method, $url, array $headers, $deadline, $file, $have)
    {
        $headers = array_merge(array(
            'User-Agent' => Config::userAgent(),
            'Accept' => '*/*',
        ), $headers);
        $order = array('curl', 'socket', 'stream');
        if (self::$force !== '') {
            $order = array(self::$force);
        }
        self::$tried = array();
        $last = null;
        foreach ($order as $t) {
            if (!self::transportUsable($t, $url)) {
                continue;
            }
            self::$tried[] = $t;
            $r = $t === 'curl' ? self::viaCurl($method, $url, $headers, $deadline, $file, $have)
                : ($t === 'socket' ? self::viaSocket($method, $url, $headers, $deadline, $file, $have)
                    : self::viaStream($method, $url, $headers, $deadline, $file, $have));
            $r['transport'] = $t;
            // a transport that could not even connect (missing TLS support, refused by a host firewall) gives way to the
            // next one; an answer of the server (any status) is final
            if ($r['status'] > 0 || $r['error'] === '' || !empty($r['final'])) {
                return $r;
            }
            $last = $r;
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        return $last ?: array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'no HTTPS transport available (curl or openssl)', 'transport' => '', 'total' => 0, 'complete' => false);
    }

    private static function transportUsable($t, $url)
    {
        $https = stripos($url, 'https://') === 0;
        if ($t === 'curl') {
            return function_exists('curl_init') && Util::fnAvailable('curl_exec');
        }
        if ($t === 'socket') {
            return Util::fnAvailable('stream_socket_client') && (!$https || extension_loaded('openssl'));
        }
        if ($t === 'stream') {
            return (bool)ini_get('allow_url_fopen') && (!$https || extension_loaded('openssl'));
        }
        return false;
    }

    // ------------------------------------------------------------------ curl

    private static function viaCurl($method, $url, array $headers, $deadline, $file, $have)
    {
        $ch = curl_init();
        $respHeaders = array();
        $status = 0;
        $body = '';
        $fh = null;
        $written = 0;
        $cut = false;
        $tooBig = false;
        $bad = '';
        $lines = array();
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $remaining = max(1, (int)ceil($deadline - microtime(true)));
        $headerLines = 0;
        $opts = array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, $remaining),
            CURLOPT_TIMEOUT => $file !== null ? $remaining + 5 : $remaining,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => self::caBundle(),
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders, &$status, &$headerLines) {
                $len = strlen($line);
                if (++$headerLines > self::MAX_HEADER_LINES) {
                    return 0;
                }
                $t = trim($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $t, $m)) {
                    $status = (int)$m[1];
                    $respHeaders = array();
                } elseif (($p = strpos($t, ':')) !== false) {
                    $name = strtolower(trim(substr($t, 0, $p)));
                    $val = trim(substr($t, $p + 1));
                    $respHeaders[$name] = isset($respHeaders[$name]) ? $respHeaders[$name] . ', ' . $val : $val;
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$body, &$fh, &$written, &$cut, &$tooBig, &$bad, $deadline, &$status, &$respHeaders, $file, $have) {
                $len = strlen($data);
                if ($file !== null) {
                    // a redirect or error page is never written into the download file
                    if ($status !== 200 && $status !== 206) {
                        $body .= strlen($body) < 65536 ? $data : '';
                        return $len;
                    }
                    if ($fh === null) {
                        // first byte of the body: a resume must continue exactly at $have; a 200 restarts the file
                        if ($status === 206 && !self::rangeOk($respHeaders, $have)) {
                            $bad = 'unexpected range';
                            return 0;
                        }
                        $fh = @fopen($file, ($status === 206 && $have > 0) ? 'ab' : 'wb');
                        if (!$fh) {
                            $bad = 'cannot write the download file';
                            return 0;
                        }
                    }
                    $drop = (int)Config::fault('net_drop_after');
                    if ($drop > 0 && $written + $len > $drop) {
                        $cut = true;
                        return 0;
                    }
                    // never past the signed size (a 206 continues after the $have bytes already in the file)
                    if (self::$cap > 0 && (($status === 206 && $have > 0) ? $have : 0) + $written + $len > self::$cap) {
                        $bad = 'more data than expected';
                        return 0;
                    }
                    $w = @fwrite($fh, $data);
                    if ($w !== $len) {
                        $bad = 'write failed (disk full?)';
                        return 0;
                    }
                    $written += $len;
                    if (microtime(true) >= $deadline) {
                        $cut = true;
                        return 0;
                    }
                    return $len;
                }
                if (strlen($body) + $len > self::MAX_BODY) {
                    $tooBig = true;
                    return 0;
                }
                $body .= $data;
                return $len;
            },
        );
        if ($file === null) {
            $opts[CURLOPT_ENCODING] = '';
        }
        curl_setopt_array($ch, $opts);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            @curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https,http');
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            @curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        }
        $ok = @curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : '';
        $sslFail = in_array($errno, array(35, 51, 53, 54, 58, 59, 60, 77, 80, 82, 83, 90, 91), true);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($fh) {
            @fflush($fh);
            Util::fsync($fh);
            @fclose($fh);
        }
        if ($sslFail && $status === 0) {
            // the shipped bundle did not verify the server: try the system's own CA store once (never without checks)
            return self::curlSystemCa($method, $url, $headers, $deadline, $file, $have, Util::redact($error));
        }
        $total = self::totalSize($respHeaders, $have, $status);
        $complete = $ok !== false && !$cut && $errno === 0 && $bad === '';
        if ($tooBig) {
            return array('status' => $status, 'headers' => $respHeaders, 'body' => '', 'error' => 'answer too large', 'final' => true, 'total' => $total, 'complete' => false);
        }
        $err = $bad !== '' ? $bad : ($cut ? '' : ($errno ? 'curl ' . $errno . ': ' . Util::redact($error) : ''));
        return array('status' => $status, 'headers' => $respHeaders, 'body' => $file !== null ? substr($body, 0, 65536) : $body,
            'error' => $err, 'total' => $total, 'complete' => $complete, 'final' => $status > 0);
    }

    /** A 206 answer continues exactly where the part file ends ("Content-Range: bytes <have>-..."). */
    private static function rangeOk(array $h, $have)
    {
        if (!isset($h['content-range'])) {
            return false;
        }
        return (bool)preg_match('#^bytes\s+' . (int)$have . '-\d+/(\d+|\*)\s*$#i', trim($h['content-range']));
    }

    /** Second curl attempt with the CA store PHP / libcurl use by default (+ the native store on PHP 8.2+). */
    private static function curlSystemCa($method, $url, array $headers, $deadline, $file, $have, $firstError)
    {
        if (Config::endpoints()['test']) {
            return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'TLS: ' . $firstError, 'final' => false, 'total' => 0, 'complete' => false);
        }
        $ch = curl_init();
        $respHeaders = array();
        $status = 0;
        $body = '';
        $fh = null;
        $bad = '';
        $got = 0;
        $headerLines = 0;
        $lines = array();
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $remaining = max(1, (int)ceil($deadline - microtime(true)));
        $opts = array(
            CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $lines, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, $remaining), CURLOPT_TIMEOUT => $remaining,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders, &$status, &$headerLines) {
                if (++$headerLines > self::MAX_HEADER_LINES) {
                    return 0;
                }
                $t = trim($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $t, $m)) {
                    $status = (int)$m[1];
                    $respHeaders = array();
                } elseif (($p = strpos($t, ':')) !== false) {
                    $respHeaders[strtolower(trim(substr($t, 0, $p)))] = trim(substr($t, $p + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$body, &$fh, &$bad, &$got, $deadline, &$status, &$respHeaders, $file, $have) {
                if ($file !== null && ($status === 200 || $status === 206)) {
                    if ($fh === null) {
                        // the same rule as the first attempt: a resume continues exactly at $have, a 200 starts again
                        if ($status === 206 && !self::rangeOk($respHeaders, $have)) {
                            $bad = 'unexpected range';
                            return 0;
                        }
                        $fh = @fopen($file, ($status === 206 && $have > 0) ? 'ab' : 'wb');
                        if (!$fh) {
                            $bad = 'cannot write the download file';
                            return 0;
                        }
                    }
                    if (self::$cap > 0 && (($status === 206 && $have > 0) ? $have : 0) + $got + strlen($data) > self::$cap) {
                        $bad = 'more data than expected';
                        return 0;
                    }
                    $got += strlen($data);
                    $w = @fwrite($fh, $data);
                    return ($w === strlen($data) && microtime(true) < $deadline) ? $w : 0;
                }
                if (strlen($body) + strlen($data) > self::MAX_BODY) {
                    return 0;
                }
                $body .= $data;
                return strlen($data);
            },
        );
        if ($file === null) {
            $opts[CURLOPT_ENCODING] = '';
        }
        if (PHP_VERSION_ID >= 80200 && defined('CURLSSLOPT_NATIVE_CA')) {
            $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($ch, $opts);
        $ok = @curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : '';
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        if ($fh) {
            @fflush($fh);
            @fclose($fh);
        }
        $total = self::totalSize($respHeaders, $have, $status);
        $err = $bad !== '' ? $bad : ($errno && $status === 0 ? 'TLS: ' . $firstError . '; system CA: ' . Util::redact($error) : '');
        return array('status' => $status, 'headers' => $respHeaders, 'body' => $body, 'error' => $err,
            'total' => $total, 'complete' => $ok !== false && $errno === 0 && $bad === '', 'final' => false);
    }

    /** Full size from Content-Range ("bytes a-b/total") or Content-Length (plus the resume offset). */
    private static function totalSize(array $h, $have, $status)
    {
        if ($status === 206 && isset($h['content-range']) && preg_match('#/(\d+)\s*$#', $h['content-range'], $m)) {
            return (int)$m[1];
        }
        if ($status === 200 && isset($h['content-length']) && ctype_digit(trim($h['content-length'])) && empty($h['content-encoding'])) {
            return (int)trim($h['content-length']);
        }
        return 0;
    }

    // ------------------------------------------------------------------ socket (own HTTP/1.1, works with allow_url_fopen=0)

    private static function viaSocket($method, $url, array $headers, $deadline, $file, $have)
    {
        $p = parse_url($url);
        $https = strtolower($p['scheme']) === 'https';
        $host = $p['host'];
        $port = isset($p['port']) ? (int)$p['port'] : ($https ? 443 : 80);
        $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        $ctx = stream_context_create(array('ssl' => array(
            'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'cafile' => self::caBundle(), 'peer_name' => $host, 'SNI_enabled' => true, 'disable_compression' => true,
        )));
        $timeout = max(1.0, min((float)self::CONNECT_TIMEOUT, $deadline - microtime(true)));
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client(($https ? 'tls://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'connect: ' . Util::redact($errstr !== '' ? $errstr : 'failed'), 'total' => 0, 'complete' => false);
        }
        stream_set_timeout($sock, max(1, (int)ceil($deadline - microtime(true))));
        $hostHeader = $host . ((($https && $port !== 443) || (!$https && $port !== 80)) ? ':' . $port : '');
        $req = $method . ' ' . $path . " HTTP/1.1\r\nHost: " . $hostHeader . "\r\nConnection: close\r\nAccept-Encoding: identity\r\n";
        foreach ($headers as $k => $v) {
            $req .= $k . ': ' . $v . "\r\n";
        }
        $req .= "\r\n";
        if (@fwrite($sock, $req) === false) {
            @fclose($sock);
            return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'send failed', 'total' => 0, 'complete' => false);
        }
        // status line and headers
        $status = 0;
        $respHeaders = array();
        $line = fgets($sock, 8192);
        if ($line === false || !preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            @fclose($sock);
            return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'no HTTP answer', 'total' => 0, 'complete' => false);
        }
        $status = (int)$m[1];
        $headerLines = 0;
        while (($line = fgets($sock, 8192)) !== false) {
            $t = rtrim($line, "\r\n");
            if ($t === '') {
                break;
            }
            if (++$headerLines > self::MAX_HEADER_LINES) {
                @fclose($sock);
                return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'too many header lines', 'total' => 0, 'complete' => false, 'final' => true);
            }
            if (($pos = strpos($t, ':')) !== false) {
                $name = strtolower(trim(substr($t, 0, $pos)));
                $val = trim(substr($t, $pos + 1));
                $respHeaders[$name] = isset($respHeaders[$name]) ? $respHeaders[$name] . ', ' . $val : $val;
            }
        }
        $chunked = isset($respHeaders['transfer-encoding']) && stripos($respHeaders['transfer-encoding'], 'chunked') !== false;
        $length = isset($respHeaders['content-length']) && ctype_digit(trim($respHeaders['content-length'])) ? (int)trim($respHeaders['content-length']) : -1;
        $toFile = $file !== null && ($status === 200 || $status === 206);
        if ($toFile && $status === 206 && !self::rangeOk($respHeaders, $have)) {
            @fclose($sock);
            return array('status' => $status, 'headers' => $respHeaders, 'body' => '', 'error' => 'unexpected range', 'total' => 0, 'complete' => false);
        }
        $fh = null;
        if ($toFile) {
            $fh = @fopen($file, ($have > 0 && $status === 206) ? 'ab' : 'wb');
            if (!$fh) {
                @fclose($sock);
                return array('status' => $status, 'headers' => $respHeaders, 'body' => '', 'error' => 'cannot write the download file', 'total' => 0, 'complete' => false);
            }
        }
        $body = '';
        $got = 0;
        $complete = false;
        $cut = false;
        $over = false;
        $base = ($have > 0 && $status === 206) ? $have : 0;
        $sink = function ($data) use (&$body, &$got, $fh, &$cut, &$over, $base, $deadline, $toFile) {
            $len = strlen($data);
            if ($toFile) {
                $drop = (int)Config::fault('net_drop_after');
                if ($drop > 0 && $got + $len > $drop) {
                    $cut = true;
                    return false;
                }
                // never past the signed size
                if (self::$cap > 0 && $base + $got + $len > self::$cap) {
                    $over = true;
                    return false;
                }
                if (@fwrite($fh, $data) !== $len) {
                    return false;
                }
            } else {
                if (strlen($body) + $len > self::MAX_BODY) {
                    return false;
                }
                $body .= $data;
            }
            $got += $len;
            if (microtime(true) >= $deadline) {
                $cut = true;
                return false;
            }
            return true;
        };
        if ($method === 'HEAD' || $status === 204 || $status === 304 || ($status >= 300 && $status < 400 && $length <= 0)) {
            $complete = true;
        } elseif ($chunked) {
            while (true) {
                $sizeLine = fgets($sock, 1024);
                if ($sizeLine === false) {
                    break;
                }
                $size = hexdec(trim(explode(';', $sizeLine)[0]));
                if ($size === 0) {
                    $complete = true;
                    break;
                }
                $left = (int)$size;
                $ok = true;
                while ($left > 0) {
                    $data = fread($sock, min(65536, $left));
                    if ($data === false || $data === '') {
                        $ok = false;
                        break;
                    }
                    $left -= strlen($data);
                    if (!$sink($data)) {
                        $ok = false;
                        break;
                    }
                }
                if (!$ok) {
                    break;
                }
                fgets($sock, 16);
            }
        } else {
            while (!feof($sock)) {
                $want = $length >= 0 ? min(65536, $length - $got) : 65536;
                if ($length >= 0 && $want <= 0) {
                    break;
                }
                $data = fread($sock, $want);
                if ($data === false || $data === '') {
                    $meta = stream_get_meta_data($sock);
                    if (!empty($meta['timed_out'])) {
                        break;
                    }
                    continue;
                }
                if (!$sink($data)) {
                    break;
                }
            }
            $complete = $length >= 0 ? $got === $length : feof($sock) && !$cut;
        }
        @fclose($sock);
        if ($fh) {
            @fflush($fh);
            Util::fsync($fh);
            @fclose($fh);
        }
        return array('status' => $status, 'headers' => $respHeaders, 'body' => $toFile ? '' : $body, 'error' => $over ? 'more data than expected' : '',
            'total' => self::totalSize($respHeaders, $have, $status), 'complete' => $complete && !$cut && !$over, 'final' => $over);
    }

    // ------------------------------------------------------------------ stream wrapper (needs allow_url_fopen)

    private static function viaStream($method, $url, array $headers, $deadline, $file, $have)
    {
        $lines = '';
        foreach ($headers as $k => $v) {
            $lines .= $k . ': ' . $v . "\r\n";
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        $ctx = stream_context_create(array(
            'http' => array('method' => $method, 'header' => $lines . "Connection: close\r\n", 'follow_location' => 0, 'max_redirects' => 1,
                'ignore_errors' => true, 'timeout' => max(1.0, $deadline - microtime(true)), 'protocol_version' => 1.1),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true, 'cafile' => self::caBundle(), 'peer_name' => $host, 'SNI_enabled' => true),
        ));
        $fh = @fopen($url, 'rb', false, $ctx);
        if (!$fh) {
            $e = error_get_last();
            return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'stream: ' . Util::redact(is_array($e) ? $e['message'] : 'failed'), 'total' => 0, 'complete' => false);
        }
        $meta = stream_get_meta_data($fh);
        $status = 0;
        $respHeaders = array();
        if (count((array)($meta['wrapper_data'] ?? array())) > self::MAX_HEADER_LINES) {
            @fclose($fh);
            return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'too many header lines', 'total' => 0, 'complete' => false, 'final' => true);
        }
        foreach ((array)($meta['wrapper_data'] ?? array()) as $line) {
            $t = trim((string)$line);
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $t, $m)) {
                $status = (int)$m[1];
                $respHeaders = array();
            } elseif (($pos = strpos($t, ':')) !== false) {
                $respHeaders[strtolower(trim(substr($t, 0, $pos)))] = trim(substr($t, $pos + 1));
            }
        }
        $toFile = $file !== null && ($status === 200 || $status === 206);
        if ($toFile && $status === 206 && !self::rangeOk($respHeaders, $have)) {
            @fclose($fh);
            return array('status' => $status, 'headers' => $respHeaders, 'body' => '', 'error' => 'unexpected range', 'total' => 0, 'complete' => false);
        }
        $out = $toFile ? @fopen($file, ($have > 0 && $status === 206) ? 'ab' : 'wb') : null;
        $body = '';
        $cut = false;
        $over = false;
        $got = 0;
        $base = ($have > 0 && $status === 206) ? $have : 0;
        while (!feof($fh)) {
            $data = fread($fh, 65536);
            if ($data === false || $data === '') {
                $m = stream_get_meta_data($fh);
                if (!empty($m['timed_out'])) {
                    $cut = true;
                    break;
                }
                continue;
            }
            if ($toFile) {
                // never past the signed size
                if (self::$cap > 0 && $base + $got + strlen($data) > self::$cap) {
                    $over = true;
                    $cut = true;
                    break;
                }
                if (!$out || @fwrite($out, $data) !== strlen($data)) {
                    $cut = true;
                    break;
                }
            } elseif (strlen($body) + strlen($data) <= self::MAX_BODY) {
                $body .= $data;
            } else {
                $cut = true;
                break;
            }
            $got += strlen($data);
            if (microtime(true) >= $deadline) {
                $cut = true;
                break;
            }
        }
        @fclose($fh);
        if ($out) {
            @fflush($out);
            Util::fsync($out);
            @fclose($out);
        }
        return array('status' => $status, 'headers' => $respHeaders, 'body' => $body, 'error' => $over ? 'more data than expected' : '',
            'total' => self::totalSize($respHeaders, $have, $status), 'complete' => !$cut, 'final' => $over);
    }
}
