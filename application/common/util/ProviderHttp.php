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
 * Shared HTTP layer for import providers (XVID-SCALE-BRIEF D-P3, built from AvdbClient::fetchJson/request).
 *
 * - fixed base URL per provider, never a URL built from admin input (the caller passes only its own constant host);
 * - TLS peer + host verification, HTTPS only, no redirects;
 * - gzip/deflate accepted (CURLOPT_ENCODING '') and the 8 MiB body cap counted on DECODED bytes inside the write
 *   callback, so a small compressed answer that inflates to 100 MB stops at 8 MiB without buffering the rest;
 * - Retry-After on 429, Cloudflare challenge detection, transient classes (5xx, cURL 6/7/28/35/52/56).
 *
 * Errors are ProviderHttpException with a kind the pacer (ProviderPacer::classify) maps to a verdict.
 * A transport callable can be injected for fixtures: fn(string $url, array $options): ['status','headers','body'].
 */
class ProviderHttp
{
    const MAX_BODY = 8388608;
    /** cURL errors that mean "try again later" (D-I6). */
    const TRANSIENT_CURL = [6, 7, 28, 35, 52, 56];

    private $name;
    private $transport;
    private $timeout;
    private $connectTimeout;
    private $maxBody;
    private $userAgent;

    /**
     * @param string        $name      provider label used in messages ("xvidapi", "AVDB")
     * @param callable|null $transport fixture transport (tests only)
     * @param array         $options   timeout (s), connect_timeout (s), max_body (bytes, <= MAX_BODY), user_agent
     */
    public function __construct($name, $transport = null, array $options = [])
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9 ._-]{1,32}$/D', $name)) { throw new \InvalidArgumentException('Invalid provider name.'); }
        if ($transport !== null && !is_callable($transport)) { throw new \InvalidArgumentException('Invalid provider transport.'); }
        $this->name = $name;
        $this->transport = $transport;
        $this->timeout = max(1, min(120, (int)($options['timeout'] ?? 30)));
        $this->connectTimeout = max(1, min(30, (int)($options['connect_timeout'] ?? 5)));
        $this->maxBody = max(1024, min(self::MAX_BODY, (int)($options['max_body'] ?? self::MAX_BODY)));
        $ua = (string)($options['user_agent'] ?? self::userAgent());
        $this->userAgent = preg_match('/^[\x20-\x7e]{1,200}$/D', $ua) ? $ua : 'JavCMS-Import/1';
    }

    /** "JavCMS-Import/<version> (+<site host>)" (D-I6). Works without the framework (tests): falls back to "1". */
    public static function userAgent()
    {
        $version = '1';
        $host = '';
        if (function_exists('config')) {
            try {
                $v = config('version.code');
                if (is_string($v) && preg_match('/^[0-9][0-9A-Za-z.\-]{0,19}$/D', $v)) { $version = $v; }
                $siteCfg = config('maccms.site'); $site = is_array($siteCfg) ? ($siteCfg['site_url'] ?? '') : '';
                if (is_string($site) && $site !== '') {
                    $h = parse_url(strpos($site, '//') === false ? 'https://' . $site : $site, PHP_URL_HOST);
                    if (is_string($h) && preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $h)) { $host = strtolower($h); }
                }
            } catch (\Throwable $e) { /* framework not booted */ }
        }
        return 'JavCMS-Import/' . $version . ($host !== '' ? ' (+' . $host . ')' : '');
    }

    public function maxBody() { return $this->maxBody; }

    /**
     * GET a JSON document. Returns the decoded array (depth <= 64) and the timing in $meta.
     * $expectCode: the provider's "code" value that means success (string), null = no check.
     */
    public function getJson($url, $expectCode = '1', &$meta = null)
    {
        $started = microtime(true);
        $response = $this->get($url);
        return $this->decodeJson($response, $started, $expectCode, $meta);
    }

    /**
     * Prefetch (D-I1): start a GET now and collect it later with finishJson(); between the two, pump() moves the
     * transfer forward without blocking (curl_multi). With a fixture transport the request runs at finishJson().
     * Returns an opaque handle.
     */
    public function start($url)
    {
        if (!is_string($url) || strlen($url) > 16384 || strpos($url, 'https://') !== 0 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \InvalidArgumentException('Invalid provider URL.');
        }
        $h = ['url' => $url, 'started' => microtime(true), 'curl' => null, 'state' => null, 'done' => false, 'result' => 0];
        if ($this->transport || !function_exists('curl_multi_init')) { return (object)$h; }
        $options = ['connect_timeout' => $this->connectTimeout, 'timeout' => $this->timeout, 'max_body' => $this->maxBody, 'user_agent' => $this->userAgent];
        $st = new \stdClass();
        $ch = $this->makeHandle($url, $options, $st);
        if ($this->multi === null) { $this->multi = curl_multi_init(); }
        curl_multi_add_handle($this->multi, $ch);
        $h['curl'] = $ch; $h['state'] = $st;
        $obj = (object)$h;
        $this->pending[] = $obj;
        // The pacer gave this request its slot now: make sure the request leaves now (connect + send, at most 2 s),
        // not at the next pump, which may come seconds later (a slow batch, a pause) and break the provider's spacing.
        $until = microtime(true) + 2.0;
        do {
            $this->pump();
            if ($obj->done || (int)curl_getinfo($ch, CURLINFO_REQUEST_SIZE) > 0) { break; }
            curl_multi_select($this->multi, 0.02);
        } while (microtime(true) < $until);
        return $obj;
    }

    /** Move started transfers forward without waiting (call between apply batches). */
    public function pump()
    {
        if ($this->multi === null) { return; }
        do { $code = curl_multi_exec($this->multi, $running); } while ($code === CURLM_CALL_MULTI_PERFORM);
        while ($info = curl_multi_info_read($this->multi)) {
            foreach ($this->pending as $p) { if ($p->curl === $info['handle']) { $p->done = true; $p->result = (int)$info['result']; } }
        }
    }

    /** Wait for a started GET and decode it exactly like getJson(). */
    public function finishJson($handle, $expectCode = '1', &$meta = null)
    {
        if (!is_object($handle) || !isset($handle->url)) { throw new \InvalidArgumentException('Invalid prefetch handle.'); }
        if ($handle->curl === null) {
            $response = $this->get($handle->url);
            return $this->decodeJson($response, $handle->started, $expectCode, $meta);
        }
        $deadline = microtime(true) + $this->timeout + 5;
        while (!$handle->done && microtime(true) < $deadline) {
            $this->pump();
            if (!$handle->done) { curl_multi_select($this->multi, 0.2); }
        }
        $ch = $handle->curl;
        curl_multi_remove_handle($this->multi, $ch);
        $this->pending = array_values(array_filter($this->pending, function ($p) use ($handle) { return $p !== $handle; }));
        $errno = $handle->done ? $handle->result : 28;
        $response = $this->finishCurl($ch, $errno === 0, $errno, $handle->state);
        return $this->decodeJson($response, $handle->started, $expectCode, $meta);
    }

    /** Started (prefetched) transfers that were not collected: drop them (end of a run). */
    public function abandon()
    {
        if ($this->multi === null) { return; }
        foreach ($this->pending as $p) { if ($p->curl) { curl_multi_remove_handle($this->multi, $p->curl); } }
        $this->pending = [];
    }

    private $multi;
    private $pending = [];

    private function decodeJson(array $response, $started, $expectCode, &$meta)
    {
        // sent_at: when the request really left (cURL start + pre-transfer time); the pacer counts the next slot from it
        $meta = ['status' => (int)$response['status'], 'ms' => (int)round((microtime(true) - $started) * 1000), 'bytes' => strlen($response['body']), 'sent_at' => isset($response['sent_at']) ? (float)$response['sent_at'] : (float)$started];
        $status = (int)$response['status'];
        $headers = self::lowerHeaders($response['headers'] ?? []);
        if (self::isChallenge($status, $headers, $response['body'])) {
            throw new ProviderHttpException(ImportProviders::text('provider_challenge', 'The provider asks for a browser check; its API must be exempt from the challenge.'), ProviderHttpException::CHALLENGE, $status, 3600);
        }
        if ($status === 429) {
            throw new ProviderHttpException($this->name . ' rate limit reached. Retry in ' . self::retryAfter($headers) . ' seconds.', ProviderHttpException::RATE_LIMIT, 429, self::retryAfter($headers));
        }
        if ($status >= 500 || $status === 408) {
            throw new ProviderHttpException($this->name . ' is unavailable (HTTP ' . $status . ').', ProviderHttpException::TRANSIENT, $status, 0);
        }
        if ($status !== 200) {
            throw new ProviderHttpException($this->name . ' refused the request (HTTP ' . $status . ').', ProviderHttpException::HTTP, $status, 0);
        }
        if (strlen($response['body']) > $this->maxBody) {
            throw new ProviderHttpException($this->name . ' response exceeds the size limit.', ProviderHttpException::TOO_LARGE, $status, 0);
        }
        $data = json_decode($response['body'], true, 64);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new ProviderHttpException($this->name . ' returned malformed JSON.', ProviderHttpException::MALFORMED, $status, 0);
        }
        if ($expectCode !== null && (!isset($data['code']) || !is_scalar($data['code']) || (string)$data['code'] !== (string)$expectCode)) {
            throw new ProviderHttpException($this->name . ' returned an unsuccessful answer.', ProviderHttpException::MALFORMED, $status, 0);
        }
        return $data;
    }

    /**
     * Resumable download of a static file (xvidexport snapshot shard, XVIDEXPORT-CONTRACT section 2/4) into $path:
     * GET with "Range: bytes=<offset>-" (no content decoding: the raw .gz bytes are kept), the file grows by what
     * arrives until $deadline (unix time, float) or until $expectBytes; at most $expectBytes bytes are ever written
     * (hard cap = the manifest size). Returns the file size afterwards (== $expectBytes when complete; less when the
     * deadline came first: the caller resumes from there in its next step). 206 appends; 200 (server ignored the
     * range) restarts the file; 416 at the full size counts as complete. HTTP errors, challenges, 429 and 5xx raise the
     * same ProviderHttpException kinds as getJson(); a timeout without a single new byte is TRANSIENT.
     * Fixture transports get options['range'] = $offset and return ['status','headers','body'].
     * $meta (round-2 verify fix import-r2 N2) = ['status', 'size' (bytes on disk after the call), 'written', 'restarted',
     * 'timed_out', 'short', 'ms', 'sent_at', 'requested']: 'short' = the transfer ended normally (HTTP 200 / 206, not the deadline
     * or a transfer error) with fewer bytes on disk than $expectBytes, or the server answered 416 below that size: the file the
     * server holds is shorter than announced (an error page served with status 200, a server that ignores Range, a truncated
     * file). Asking again at once would get the same answer: the caller counts it as a failed try.
     */
    public function download($url, $path, $offset, $expectBytes, $deadline, &$meta = null)
    {
        if (!is_string($url) || strlen($url) > 2048 || strpos($url, 'https://') !== 0 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \InvalidArgumentException('Invalid provider URL.');
        }
        $offset = max(0, (int)$offset); $expect = max(1, (int)$expectBytes);
        $meta = ['status' => 0, 'size' => 0, 'written' => 0, 'restarted' => false, 'timed_out' => false, 'short' => false, 'ms' => 0, 'ttfb_ms' => 0, 'sent_at' => microtime(true), 'requested' => false];
        clearstatcache(true, $path);
        $have = is_file($path) ? (int)filesize($path) : 0;
        if ($have !== $offset) { $offset = min($have, $offset); if (!self::truncateTo($path, $offset)) { throw new \RuntimeException('Cannot write the download file.'); } }
        $meta['size'] = $offset;
        if ($offset >= $expect) { return $offset; }
        $seconds = (float)$deadline - microtime(true);
        if ($seconds < 1) { return $offset; }
        $timeout = max(1, min($this->timeout * 20, (int)floor($seconds)));
        $t0 = microtime(true); $meta['requested'] = true;
        if ($this->transport) {
            $r = call_user_func($this->transport, $url, ['range' => $offset, 'timeout' => $timeout, 'max_body' => $expect - $offset, 'user_agent' => $this->userAgent]);
            if (!is_array($r) || !isset($r['status'], $r['body']) || !is_string($r['body'])) { throw new ProviderHttpException('Invalid ' . $this->name . ' transport response.', ProviderHttpException::MALFORMED, 0, 0); }
            $size = $this->storeDownload((int)$r['status'], self::lowerHeaders($r['headers'] ?? []), $r['body'], $path, $offset, $expect);
            $status = (int)$r['status'];
            $meta = ['status' => $status, 'size' => $size, 'written' => max(0, $status === 200 ? $size : $size - $offset), 'restarted' => $status === 200 && $offset > 0, 'timed_out' => !empty($r['timed_out']),
                'short' => empty($r['timed_out']) && $size < $expect && (in_array($status, [200, 206], true) || ($status === 416 && $offset < $expect)), 'ms' => (int)round((microtime(true) - $t0) * 1000), 'ttfb_ms' => (int)round((microtime(true) - $t0) * 1000), 'sent_at' => $t0, 'requested' => true];
            return $size;
        }
        if (!function_exists('curl_init')) { throw new \RuntimeException('The PHP cURL extension is required for provider import.'); }
        $fh = @fopen($path, 'ab');
        if (!$fh) { throw new \RuntimeException('Cannot write the download file.'); }
        $st = new \stdClass(); $st->headers = []; $st->status = 0; $st->written = 0; $st->overflow = false; $st->restart = false; $st->head = '';
        $room = $expect - $offset;
        $ch = curl_init($this->target($url));
        $configuredCa = ini_get('curl.cainfo');
        if (is_string($configuredCa) && $configuredCa !== '') { curl_setopt($ch, CURLOPT_CAINFO, $configuredCa); }
        $curlVersion = curl_version();
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS') && ($curlVersion['version_number'] ?? 0) >= 0x074700 && preg_match('/^(OpenSSL|LibreSSL|BoringSSL|AWS-LC)\//', $curlVersion['ssl_version'] ?? '')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, defined('CURLSSLOPT_NATIVE_CA') ? constant('CURLSSLOPT_NATIVE_CA') : 16);
        }
        $protocols = $this->protocols();
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => $protocols,
            CURLOPT_HTTPHEADER => ['Accept: application/gzip, application/octet-stream', 'Accept-Encoding: identity'],
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use ($st) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $st->status = (int)$m[1]; $st->headers = []; }
                $pair = explode(':', $line, 2);
                if (count($pair) === 2 && count($st->headers) < 100) { $st->headers[strtolower(trim($pair[0]))] = substr(trim($pair[1]), 0, 1024); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use ($st, $fh, &$room, $offset, $path) {
                if ($st->status !== 206 && $st->status !== 200) { if (strlen($st->head) < 4096) { $st->head .= substr($chunk, 0, 4096 - strlen($st->head)); } return strlen($chunk); }
                if ($st->status === 200 && $offset > 0 && !$st->restart) { $st->restart = true; ftruncate($fh, 0); $room += $offset; }
                if (strlen($chunk) > $room) { $st->overflow = true; return 0; }
                $n = fwrite($fh, $chunk);
                if ($n !== strlen($chunk)) { return 0; }
                $room -= $n; $st->written += $n;
                return $n;
            },
        ]);
        if ($offset > 0) { curl_setopt($ch, CURLOPT_RANGE, $offset . '-'); }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) { curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, $protocols); }
        $ok = curl_exec($ch); $errno = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: $st->status;
        $sentAt = $t0 + max(0.0, (float)curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME)); $ttfb = (int)round(1000 * max(0.0, (float)curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME)));
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        unset($ch);
        fclose($fh);
        clearstatcache(true, $path);
        $size = is_file($path) ? (int)filesize($path) : 0;
        $meta = ['status' => $status, 'size' => $size, 'written' => (int)$st->written, 'restarted' => (bool)$st->restart, 'timed_out' => (int)$errno === 28,
            'short' => false, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'ttfb_ms' => $ttfb, 'sent_at' => $sentAt, 'requested' => true];
        if ($st->overflow) { throw new ProviderHttpException($this->name . ' download exceeds its announced size.', ProviderHttpException::TOO_LARGE, $status, 0); }
        // 416 at the full size: complete; below it (import-r2 N2): the server holds fewer bytes than the manifest announces
        if ($status === 416) { $meta['size'] = $offset; $meta['short'] = $offset < $expect; return $offset; }
        if ($status !== 200 && $status !== 206) {
            $this->downloadStatus($status, $st->headers, $st->head);
        }
        if ($ok === false && $st->written === 0) {
            if (in_array((int)$errno, self::TRANSIENT_CURL, true)) { throw new ProviderHttpException('Could not reach ' . $this->name . ' (cURL ' . $errno . '). The import retries automatically.', ProviderHttpException::TRANSIENT, 0, 0, (int)$errno); }
            throw new ProviderHttpException('Could not download from ' . $this->name . ' (cURL ' . $errno . ').', ProviderHttpException::NETWORK, 0, 0, (int)$errno);
        }
        // import-r2 N2: a normal end (no transfer error, not the deadline) below the announced size is "short"
        $meta['short'] = $ok !== false && (int)$errno === 0 && $size < $expect;
        return $size;
    }

    /** Fixture transport answer of download(): same rules as the cURL path. */
    private function storeDownload($status, array $headers, $body, $path, $offset, $expect)
    {
        // 416: complete at the full size, else the server's file is shorter than announced ('short' in download()'s $meta)
        if ($status === 416) { return $offset; }
        if ($status !== 200 && $status !== 206) { $this->downloadStatus($status, $headers, substr($body, 0, 4096)); }
        if ($status === 200 && $offset > 0) { if (!self::truncateTo($path, 0)) { throw new \RuntimeException('Cannot write the download file.'); } $offset = 0; }
        if (strlen($body) > $expect - $offset) { @file_put_contents($path, substr($body, 0, $expect - $offset), FILE_APPEND); throw new ProviderHttpException($this->name . ' download exceeds its announced size.', ProviderHttpException::TOO_LARGE, $status, 0); }
        if ($body !== '' && @file_put_contents($path, $body, FILE_APPEND) === false) { throw new \RuntimeException('Cannot write the download file.'); }
        clearstatcache(true, $path);
        return is_file($path) ? (int)filesize($path) : 0;
    }

    /** Non-success status of a download: the getJson() error kinds (challenge, 429, 5xx, other HTTP). */
    private function downloadStatus($status, array $headers, $head)
    {
        $headers = self::lowerHeaders($headers);
        if (self::isChallenge($status, $headers, $head)) {
            throw new ProviderHttpException(ImportProviders::text('provider_challenge', 'The provider asks for a browser check; its API must be exempt from the challenge.'), ProviderHttpException::CHALLENGE, $status, 3600);
        }
        if ($status === 429) { throw new ProviderHttpException($this->name . ' rate limit reached. Retry in ' . self::retryAfter($headers) . ' seconds.', ProviderHttpException::RATE_LIMIT, 429, self::retryAfter($headers)); }
        if ($status >= 500 || $status === 408) { throw new ProviderHttpException($this->name . ' is unavailable (HTTP ' . $status . ').', ProviderHttpException::TRANSIENT, $status, 0); }
        throw new ProviderHttpException($this->name . ' refused the request (HTTP ' . $status . ').', ProviderHttpException::HTTP, $status, 0);
    }

    private static function truncateTo($path, $size)
    {
        $fh = @fopen($path, 'cb');
        if (!$fh) { return false; }
        $ok = ftruncate($fh, max(0, (int)$size)); fclose($fh); clearstatcache(true, $path);
        return $ok;
    }

    /** One GET; returns ['status'=>int,'headers'=>array,'body'=>string]. */
    public function get($url)
    {
        if (!is_string($url) || strlen($url) > 16384 || strpos($url, 'https://') !== 0 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new \InvalidArgumentException('Invalid provider URL.');
        }
        $options = ['connect_timeout' => $this->connectTimeout, 'timeout' => $this->timeout, 'max_body' => $this->maxBody, 'user_agent' => $this->userAgent];
        $response = $this->transport ? call_user_func($this->transport, $url, $options) : $this->request($url, $options);
        if (!is_array($response) || !isset($response['status'], $response['body']) || !is_string($response['body'])) {
            throw new ProviderHttpException('Invalid ' . $this->name . ' transport response.', ProviderHttpException::MALFORMED, 0, 0);
        }
        if (strlen($response['body']) > $this->maxBody) {
            throw new ProviderHttpException($this->name . ' response exceeds the size limit.', ProviderHttpException::TOO_LARGE, (int)$response['status'], 0);
        }
        return $response;
    }

    /**
     * The address cURL connects to for $url (always $url in the product). The mock provider of the tests overrides it
     * in a subclass, together with protocols(), to send the fixed provider URLs to a local fixture server.
     */
    protected function target($url)
    {
        return $url;
    }

    /** Allowed protocols. Tests override this in a subclass to reach a local http fixture server; the product never does. */
    protected function protocols()
    {
        return CURLPROTO_HTTPS;
    }

    protected function request($url, array $options)
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('The PHP cURL extension is required for provider import.'); }
        $st = new \stdClass();
        $ch = $this->makeHandle($url, $options, $st);
        $ok = curl_exec($ch);
        return $this->finishCurl($ch, $ok !== false, curl_errno($ch), $st);
    }

    /** One configured cURL handle; body, headers and the overflow flag land in $st (shared by request() and start()). */
    private function makeHandle($url, array $options, \stdClass $st)
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('The PHP cURL extension is required for provider import.'); }
        $st->body = ''; $st->headers = []; $st->overflow = false; $st->t0 = microtime(true);
        $ch = curl_init($this->target($url)); $max = (int)$options['max_body'];
        $configuredCa = ini_get('curl.cainfo');
        if (is_string($configuredCa) && $configuredCa !== '') { curl_setopt($ch, CURLOPT_CAINFO, $configuredCa); }
        $curlVersion = curl_version();
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS') &&
            ($curlVersion['version_number'] ?? 0) >= 0x074700 &&
            preg_match('/^(OpenSSL|LibreSSL|BoringSSL|AWS-LC)\//', $curlVersion['ssl_version'] ?? '')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, defined('CURLSSLOPT_NATIVE_CA') ? constant('CURLSSLOPT_NATIVE_CA') : 16);
        }
        $protocols = $this->protocols();
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'],
            CURLOPT_TIMEOUT => $options['timeout'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => $protocols,
            // '' = every encoding this libcurl can decode (gzip, deflate, br when built in); the write callback below
            // receives DECODED bytes, so the cap applies to what PHP would hold in memory (D-P3, se-R24).
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => $options['user_agent'],
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use ($st) {
                $pair = explode(':', $line, 2);
                if (count($pair) === 2 && count($st->headers) < 100) { $st->headers[strtolower(trim($pair[0]))] = substr(trim($pair[1]), 0, 1024); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use ($st, $max) {
                if (strlen($st->body) + strlen($chunk) > $max) { $st->overflow = true; return 0; }
                $st->body .= $chunk; return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_REDIR_PROTOCOLS')) { curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, $protocols); }
        return $ch;
    }

    private function finishCurl($ch, $ok, $errno, \stdClass $st)
    {
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $sentAt = (float)$st->t0 + max(0.0, (float)curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME));
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        unset($ch);
        $body = $st->body; $headers = $st->headers;
        if ($st->overflow) { throw new ProviderHttpException($this->name . ' response exceeds the size limit.', ProviderHttpException::TOO_LARGE, $status, 0); }
        if ($ok === false) {
            if (in_array((int)$errno, self::TRANSIENT_CURL, true)) {
                throw new ProviderHttpException('Could not reach ' . $this->name . ' (cURL ' . $errno . '). The import retries automatically.', ProviderHttpException::TRANSIENT, 0, 0, (int)$errno);
            }
            $message = 'Could not connect securely to ' . $this->name . ' (cURL ' . $errno . '). Check connectivity and the PHP certificate trust store.';
            if (in_array((int)$errno, [51, 58, 59, 60, 77, 83], true)) {
                $help = function_exists('lang') ? lang('admin/ux/tools/avdb_ca_help') : '';
                $message .= ' ' . (is_string($help) && $help !== '' && $help !== 'admin/ux/tools/avdb_ca_help' ? $help : 'Ask your host to set a CA certificate bundle for PHP (php.ini: curl.cainfo).');
            }
            throw new ProviderHttpException($message, ProviderHttpException::NETWORK, 0, 0, (int)$errno);
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'sent_at' => $sentAt];
    }

    public static function lowerHeaders($headers)
    {
        $out = [];
        if (is_array($headers)) { foreach ($headers as $k => $v) { if (is_scalar($v)) { $out[strtolower(trim((string)$k))] = trim((string)$v); } } }
        return $out;
    }

    /** Seconds from Retry-After (delta or HTTP date), 1..3600, default 60. */
    public static function retryAfter(array $headers, $now = null)
    {
        $now = $now === null ? time() : (int)$now;
        $value = trim((string)($headers['retry-after'] ?? ''));
        if ($value === '') { return 60; }
        if (ctype_digit($value)) { $seconds = (int)$value; }
        else { $ts = strtotime($value); $seconds = $ts === false ? 60 : $ts - $now; }
        return max(1, min(3600, $seconds));
    }

    /**
     * Cloudflare (or similar) browser check instead of JSON (D-I6): a `cf-mitigated` header, or 403/503 from
     * `server: cloudflare`, or an HTML body where JSON was expected.
     */
    public static function isChallenge($status, array $headers, $body)
    {
        if (isset($headers['cf-mitigated'])) { return true; }
        $server = strtolower((string)($headers['server'] ?? ''));
        if (in_array((int)$status, [403, 503], true) && strpos($server, 'cloudflare') !== false) { return true; }
        $head = ltrim(substr((string)$body, 0, 512));
        if ((int)$status === 200 || (int)$status === 403 || (int)$status === 503) {
            if ($head !== '' && $head[0] === '<' && preg_match('/^<(!doctype html|html|head|body)\b/i', $head)) { return true; }
        }
        return false;
    }
}
