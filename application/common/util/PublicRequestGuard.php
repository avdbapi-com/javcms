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

/** Standalone public write guard: no framework, session, DB, cookies or remote IO.
 * Fixed-window counters use at most 256 shard files and one site-budget file.
 * The private directory must reside on a local filesystem with working flock.
 * 1.2.0 also holds the peer rules of RequestGate (peerInfo, trusted proxies, Cloudflare ranges) and the verification of
 * search-engine crawlers (crawlerClaim / crawlerVerify on the request path: files only; refreshCrawlerRanges and
 * resolvePendingCrawlers: housekeeping, off the request path).
 */
final class PublicRequestGuard
{
    private const MAX_BYTES = 65536;
    // 1.2.0 (XVID-SCALE-BRIEF L5, HARD F11): 512 entries per shard (256 shards: about 130,000 keys a minute) instead of 128
    // (503 for every new IP after about 26,000 IPs a minute); expired entries are evicted before a shard counts as full.
    private const MAX_ENTRIES = 512;
    private const LOCK_WAIT_MS = 50;
    // 1.2.0 (L5, se-R16): login has no site ceiling (a per-IP budget plus a per-account backoff instead, so many IPs can never
    // lock everybody out); suggest has a site ceiling of 6,000 a minute that answers an empty result, never 429.
    private const NO_CEILING = 1000000;
    private const LIMITS = [
        'post' => ['ip' => 60, 'site' => 600],
        'community' => ['ip' => 6, 'site' => 120],
        'auth' => ['ip' => 20, 'site' => self::NO_CEILING],
        'message' => ['ip' => 3, 'site' => 30],
        'write' => ['ip' => 120, 'site' => 2400],
        'suggest' => ['ip' => 60, 'site' => 6000],
        'captcha' => ['ip' => 60, 'site' => 600],
        // Ads manager (index/controller/Jcx.php): stats beacon POST, preview / HTML5 / country GETs.
        'jcxb' => ['ip' => 30, 'site' => 6000],
        'jcxr' => ['ip' => 120, 'site' => 6000],
        // Login attempts per account name (key = hash of the lower-cased user_name), whatever the IP.
        'account' => ['ip' => 10, 'site' => self::NO_CEILING],
    ];
    // Peers whose own address says "proxy in front of the site" (loopback, RFC 1918 / ULA, link-local): without a configured
    // trusted proxy, per-visitor budgets are off for them (L5, se-R2c, pr-R3), so visitors behind it are never pooled.
    private const PRIVATE_NETS = ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '169.254.0.0/16', '::1/128', 'fc00::/7', 'fe80::/10'];
    private static $cfRanges = null;
    // Official https://www.cloudflare.com/ips-v4 and /ips-v6, verified 2026-10-07.
    private const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32',
        '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    private static $consumed = [];

    public static function runEarly($root, ?array $server = null, ?array $get = null, ?array $post = null)
    {
        $server = $server ?? $_SERVER; $get = $get ?? $_GET; $post = $post ?? $_POST;
        $id = self::siteId($root);
        $decision = self::decide($root, $server, $get, $post, ['consumed' => self::$consumed[$id] ?? []]);
        self::$consumed[$id] = $decision['consumed'];
        return self::finish($decision);
    }

    /** Call before public Base's parent constructor; authoritative route beats raw aliases. */
    public static function enforceRoute($root, $controller, $action, ?array $server = null, ?array $get = null, ?array $post = null)
    {
        $id = self::siteId($root);
        $decision = self::decide($root, $server ?? $_SERVER, $get ?? $_GET, $post ?? $_POST, [
            'resolved' => [$controller, $action], 'consumed' => self::$consumed[$id] ?? [],
        ]);
        self::$consumed[$id] = $decision['consumed'];
        return self::finish($decision);
    }

    /** Deterministic route inspection, without storage or configuration access. */
    public static function inspect(array $server, array $get = [], array $post = [], ?array $resolved = null)
    {
        $method = strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET');
        if ($resolved !== null) {
            $controller = strtolower((string) ($resolved[0] ?? ''));
            $action = strtolower((string) ($resolved[1] ?? 'index'));
        } else {
            $route = self::route($server, $get);
            $parts = $route === '' ? [] : explode('/', $route);
            if (($parts[0] ?? '') === 'index') array_shift($parts);
            $controller = $parts[0] ?? 'index'; $action = $parts[1] ?? 'index';
        }
        $action = preg_replace('/\.html?$/', '', $action);
        $pair = $controller . '/' . $action;
        // Entry hooks exempt actual API/admin scripts. A client-selected prefix is never authority.
        $exempt = self::callback($controller, $action);
        $categories = []; $category = null; $rejectMethod = false;
        if (!$exempt) {
            $override = $server['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($post['_method'] ?? ($get['_method'] ?? ''));
            $rejectMethod = $method !== 'POST' && is_string($override) && strtoupper($override) === 'POST';
            // jcx/b (one stats beacon per page view) has its own budget and never drains the shared POST budget.
            if ($method === 'POST' && $pair !== 'jcx/b') $categories[] = 'post';
            if (in_array($pair, ['comment/save', 'comment/savedata', 'gbook/save', 'gbook/savedata'], true)
                || (in_array($pair, ['comment/index', 'gbook/index'], true) && ($method === 'POST' || $rejectMethod))) {
                $category = 'community'; $rejectMethod = $rejectMethod || $method !== 'POST';
            } elseif (in_array($pair, ['user/bindmsg', 'user/reg_msg'], true)
                || ($pair === 'user/findpass_msg' && ($method === 'POST' || $rejectMethod))) {
                // bindmsg historically accepts input() on GET; it must not bypass the send budget.
                // findpass_msg sends only on POST (User::findpass_msg); its GET is the recovery page itself.
                $category = 'message';
            } elseif (($controller === 'user' && in_array($action, ['login', 'reg', 'register', 'findpass', 'findpass_reset', 'bind', 'unbind'], true) && $method === 'POST')
                || $pair === 'ajax/pwd') {
                // ajax/pwd checks a video/article access password on GET or POST; its own 5-second gap lives in the session (a new session per try skips it).
                $category = 'auth';
            } elseif ($controller === 'ajax' && $action === 'suggest') {
                $category = 'suggest';
            } elseif ($controller === 'verify' && in_array($action, ['index', 'check'], true)) {
                $category = 'captcha';
            } elseif (in_array($pair, ['ajax/hits', 'ajax/digg', 'ajax/score', 'ajax/report', 'ajax/ulog', 'ajax/referer', 'user/ajax_ulog', 'user/visit', 'user/ajax_buy_popedom', 'user/ulog_del', 'user/plog_del', 'user/cash_del', 'user/logout', 'comment/digg', 'comment/report'], true)) {
                $category = 'write';
            } elseif ($pair === 'jcx/b') {
                $category = 'jcxb';
            // jcx/h5 (HTML5 creative files: read-only, versioned, realpath-checked) is not guarded: a creative's parallel
            // app.js + app.css answered 503 / 429 JSON, which browsers ORB-block (ads-fix-r2 VO-02).
            } elseif (in_array($pair, ['jcx/pv', 'jcx/geo'], true)) {
                $category = 'jcxr';
            }
            if ($category !== null) $categories[] = $category;
        }
        return ['route' => $pair, 'method' => $method, 'exempt' => $exempt,
            'category' => $category, 'categories' => $categories, 'reject_method' => $rejectMethod];
    }

    /** Standalone decision API. options now/config/consumed/resolved support private tests. */
    public static function decide($root, array $server, array $get = [], array $post = [], array $options = [])
    {
        $info = self::inspect($server, $get, $post, $options['resolved'] ?? null);
        $consumed = is_array($options['consumed'] ?? null) ? $options['consumed'] : [];
        if ($info['reject_method']) return self::result(405, 0, $consumed);
        if (!$info['categories']) return self::result(200, 0, $consumed);
        // Streaming public POST bodies cannot be bounded before framework bootstrap.
        if ($info['method'] === 'POST') {
            foreach (['HTTP_TRANSFER_ENCODING', 'TRANSFER_ENCODING'] as $header) {
                if (array_key_exists($header, $server)
                    && (!is_string($server[$header]) || $server[$header] !== '')) {
                    return self::result(411, 0, $consumed);
                }
            }
        }
        try {
            $config = self::config($root, $options['config'] ?? null);
            $bodyLimit = $info['category'] === 'community' ? $config['max_body_bytes']['community'] : $config['max_body_bytes']['post'];
            if (isset($server['CONTENT_LENGTH'])) {
                $length = $server['CONTENT_LENGTH'];
                if (!is_scalar($length) || !preg_match('/^[0-9]{1,20}$/D', (string) $length)) return self::result(400, 0, $consumed);
                // Decimal comparison avoids overflow and floating-point casts of hostile lengths.
                $digits = ltrim((string) $length, '0'); $limit = (string) $bodyLimit;
                if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) return self::result(413, 0, $consumed);
            }
            $pending = array_values(array_diff($info['categories'], $consumed));
            if (!$pending) return self::result(200, 0, $consumed);
            $ip = self::clientIp($server, $config);
            if ($ip === '') return self::result(503, 1, $consumed);
            // A peer that is a proxy in front of the site (loopback / private / link-local, not configured as trusted):
            // per-IP budgets would pool every visitor, so only the site budgets apply (L5, se-R2c).
            $perIp = !self::pooledPeer($server, $config, $options);
            $checks = [];
            foreach ($pending as $bucket) $checks[] = ['bucket' => $bucket, 'ip' => $perIp ? $config['limits'][$bucket]['ip'] : null, 'site' => $config['limits'][$bucket]['site'], 'window' => 60];
            // Login: per-account backoff on top of the per-IP budget (user_name of the POSTed form, any IP).
            if (in_array('auth', $pending, true) && $info['method'] === 'POST' && is_string($post['user_name'] ?? null) && $post['user_name'] !== '' && strlen($post['user_name']) <= 255) {
                $checks[] = ['bucket' => 'account', 'ip' => $config['limits']['account']['ip'], 'site' => null, 'window' => 60,
                    'who' => 'account:' . hash('sha256', strtolower(trim($post['user_name'])))];
            }
            $decision = self::consume($root, $ip, $checks, $config, self::now($options));
            // Suggest over the site ceiling sheds with an empty result instead of 429 (se-R16).
            if ($decision['status'] === 429 && ($decision['scope'] ?? '') === 'site' && $info['category'] === 'suggest') {
                $decision['empty_result'] = true;
            }
            $decision['consumed'] = $decision['status'] === 200 ? array_values(array_unique(array_merge($consumed, $pending))) : $consumed;
            return $decision;
        } catch (\Throwable $e) {
            return self::result(503, 1, $consumed);
        }
    }

    /** Existing API throttle adapter: one IP budget only, no new general/site read limit. */
    public static function checkRate($root, $bucket, $limit, $window, ?array $server = null, array $options = [])
    {
        try {
            if (!is_string($bucket) || !preg_match('/^api_search_[a-z0-9_]{0,64}$/D', $bucket)
                || !is_int($limit) || $limit < 1 || !is_int($window) || $window < 1) throw new \RuntimeException('invalid budget');
            $config = self::config($root, $options['config'] ?? null);
            $ip = self::clientIp($server ?? $_SERVER, $config);
            if ($ip === '') throw new \RuntimeException('invalid peer');
            return self::consume($root, $ip, [['bucket' => $bucket, 'ip' => $limit, 'site' => null, 'window' => $window]], $config, self::now($options));
        } catch (\Throwable $e) { return self::result(503, 1); }
    }

    public static function clientIp(array $server, array $config = [])
    {
        $peer = self::normalizeIp($server['REMOTE_ADDR'] ?? '');
        if ($peer === '') return '';
        // Cloudflare peers (shipped ranges + the monthly refresh), or the local cloudflared Tunnel when its switch is on.
        if (self::inNetworks($peer, self::cloudflareRanges()) || (self::inNetworks($peer, ['127.0.0.0/8', '::1/128']) && self::tunnelOn($config))) {
            $cf = self::normalizeIp($server['HTTP_CF_CONNECTING_IP'] ?? '');
            return $cf !== '' ? $cf : $peer;
        }
        $trusted = $config['trusted_proxies'] ?? [];
        if (!is_array($trusted) || !self::inNetworks($peer, $trusted)) return $peer;
        $header = $config['proxy_header'] ?? 'X-Forwarded-For';
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
        $value = $server[$key] ?? '';
        if (!is_string($value) || strlen($value) > 2048) return $peer;
        if ($header !== 'X-Forwarded-For') return self::normalizeIp($value) ?: $peer;
        $chain = explode(',', $value);
        if (count($chain) > 32) return $peer;
        $cursor = $peer;
        while ($chain && self::inNetworks($cursor, $trusted)) {
            $next = self::normalizeIp(trim(array_pop($chain)));
            if ($next === '') return $peer;
            $cursor = $next;
        }
        return $cursor;
    }

    /**
     * 1.2.0 (L5): who is asking, for the per-visitor budgets of RequestGate.
     * ['ip' => client address, 'peer' => REMOTE_ADDR, 'via' => 'cloudflare'|'tunnel'|'proxy'|'direct'|'pooled',
     *  'budgets' => bool (false for a pooled proxy peer), 'header' => the client-address header seen on a pooled peer ('' none)]
     * $options: 'config' (guard config override, tests), 'tunnel' (bool, the "Cloudflare Tunnel" switch).
     */
    public static function peerInfo($root, array $server, array $options = [])
    {
        try {
            $config = self::config($root, $options['config'] ?? null);
        } catch (\Throwable $e) {
            $config = ['trusted_proxies' => [], 'proxy_header' => 'X-Forwarded-For', '_tunnel' => false];
        }
        if (array_key_exists('tunnel', $options)) $config['_tunnel'] = (bool)$options['tunnel'];
        $peer = self::normalizeIp($server['REMOTE_ADDR'] ?? '');
        $out = ['ip' => '', 'peer' => $peer, 'via' => 'direct', 'budgets' => true, 'header' => ''];
        if ($peer === '') { $out['budgets'] = false; return $out; }
        $out['ip'] = self::clientIp($server, $config);
        if (self::inNetworks($peer, self::cloudflareRanges())) {
            $out['via'] = 'cloudflare';
        } elseif (!empty($config['_tunnel']) && self::inNetworks($peer, ['127.0.0.0/8', '::1/128'])) {
            $out['via'] = 'tunnel';
        } elseif (is_array($config['trusted_proxies']) && $config['trusted_proxies'] && self::inNetworks($peer, $config['trusted_proxies'])) {
            $out['via'] = 'proxy';
        } elseif (self::inNetworks($peer, self::PRIVATE_NETS)) {
            $out['via'] = 'pooled';
            $out['budgets'] = false;
            foreach (['HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For', 'HTTP_X_REAL_IP' => 'X-Real-IP', 'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP'] as $k => $name) {
                if (isset($server[$k]) && is_string($server[$k]) && $server[$k] !== '') { $out['header'] = $name; break; }
            }
        }
        return $out;
    }

    /** True when per-IP budgets must stay off for this peer (pooled proxy peer without a trusted-proxy setting). */
    private static function pooledPeer(array $server, array $config, array $options)
    {
        $peer = self::normalizeIp($server['REMOTE_ADDR'] ?? '');
        if ($peer === '' || !self::inNetworks($peer, self::PRIVATE_NETS)) return false;
        if (self::inNetworks($peer, self::cloudflareRanges())) return false;
        $tunnel = array_key_exists('tunnel', $options) ? (bool)$options['tunnel'] : !empty($config['_tunnel']);
        if ($tunnel && self::inNetworks($peer, ['127.0.0.0/8', '::1/128'])) return false;
        if (is_array($config['trusted_proxies'] ?? null) && $config['trusted_proxies'] && self::inNetworks($peer, $config['trusted_proxies'])) return false;
        return true;
    }

    /** The peer is a Cloudflare edge or a configured trusted proxy (L0: its X-Forwarded-Proto / CF-IPCountry may be used). */
    public static function trustedPeer($root, array $server)
    {
        $peer = self::normalizeIp($server['REMOTE_ADDR'] ?? '');
        if ($peer === '') return false;
        if (self::inNetworks($peer, self::cloudflareRanges())) return true;
        try { $config = self::config($root, null); } catch (\Throwable $e) { return false; }
        if (!empty($config['_tunnel']) && self::inNetworks($peer, ['127.0.0.0/8', '::1/128'])) return true;
        return is_array($config['trusted_proxies']) && $config['trusted_proxies'] && self::inNetworks($peer, $config['trusted_proxies']);
    }

    /** Headers honoured only from a Cloudflare peer or a trusted proxy (scrubHeaders, Sitemap::requestIsHttps). */
    public const PROXY_ONLY_HEADERS = ['HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL', 'HTTP_FRONT_END_HTTPS',
        'HTTP_X_FORWARDED_SCHEME', 'HTTP_CF_VISITOR', 'HTTP_FORWARDED', 'HTTP_CF_IPCOUNTRY'];

    /**
     * L0 header hygiene (index.php, before anything reads the request): ThinkPHP honours X-Real-Host, X-Rewrite-Url,
     * X-Original-Url and X-Forwarded-Host (cache poisoning, se-R12); they are always removed. The scheme headers
     * (X-Forwarded-Proto, X-Forwarded-Ssl, Front-End-Https, X-Forwarded-Scheme, CF-Visitor, Forwarded) and CF-IPCountry
     * are kept only from a Cloudflare peer or a trusted proxy: from anyone else one request could store a page with
     * https canonical / hreflang / og:url links that every plain visitor then gets from the edge cache.
     */
    public static function scrubHeaders($root)
    {
        foreach (['HTTP_X_REAL_HOST', 'HTTP_X_REWRITE_URL', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_FORWARDED_HOST'] as $h) {
            unset($_SERVER[$h]);
        }
        $present = false;
        foreach (self::PROXY_ONLY_HEADERS as $h) {
            if (isset($_SERVER[$h])) { $present = true; break; }
        }
        if ($present && !self::trustedPeer($root, $_SERVER)) {
            foreach (self::PROXY_ONLY_HEADERS as $h) {
                unset($_SERVER[$h]);
            }
        }
    }

    /** Cloudflare ranges: the shipped list plus the stored monthly refresh (kept only when it held >= 10 valid CIDRs). */
    public static function cloudflareRanges()
    {
        if (self::$cfRanges === null) {
            self::$cfRanges = self::CLOUDFLARE;
            $file = dirname(__DIR__, 3) . '/runtime/gate/cf-ranges.json';
            $raw = @file_get_contents($file);
            $list = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($list) && isset($list['cidrs']) && is_array($list['cidrs']) && count($list['cidrs']) >= 10 && count($list['cidrs']) <= 200) {
                $valid = array_values(array_filter($list['cidrs'], function ($c) { return self::validCidr($c) && strpos($c, '/') !== false; }));
                if (count($valid) >= 10) self::$cfRanges = array_values(array_unique(array_merge(self::CLOUDFLARE, $valid)));
            }
        }
        return self::$cfRanges;
    }

    /**
     * Monthly refresh (housekeeping): $fetch(url) returns the body of https://www.cloudflare.com/ips-v4 and /ips-v6 or null.
     * The list is stored only when it holds >= 10 valid CIDRs. Returns ['ok' => bool, 'count' => int].
     */
    public static function refreshCloudflareRanges($root, callable $fetch)
    {
        $cidrs = [];
        foreach (['https://www.cloudflare.com/ips-v4', 'https://www.cloudflare.com/ips-v6'] as $url) {
            $body = $fetch($url);
            if (!is_string($body) || strlen($body) > 65536) continue;
            foreach (preg_split('/\s+/', trim($body)) as $line) {
                if ($line !== '' && strpos($line, '/') !== false && self::validCidr($line)) $cidrs[] = strtolower($line);
            }
        }
        $cidrs = array_values(array_unique($cidrs));
        if (count($cidrs) < 10 || count($cidrs) > 200) return ['ok' => false, 'count' => count($cidrs)];
        $dir = rtrim(str_replace('\\', '/', (string)$root), '/') . '/runtime/gate';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $tmp = $dir . '/cf-ranges.json.' . getmypid() . '.tmp';
        $ok = @file_put_contents($tmp, json_encode(['cidrs' => $cidrs, 'at' => time()])) !== false && @rename($tmp, $dir . '/cf-ranges.json');
        if (!$ok) @unlink($tmp);
        self::$cfRanges = null;
        return ['ok' => $ok, 'count' => count($cidrs)];
    }

    // ----- verified search-engine crawlers (owner decision 2026-10-17 08:40: never blocked, never rate-limited) -----

    /**
     * Built-in engines. 'ua' = the User-Agent pattern (a claim only), 'lists' = the engine's published IP range files
     * (refreshed daily by housekeeping, the last good list is kept on failure), 'rdns' = host name suffixes for the reverse-DNS
     * + forward-confirm check (engines without a list, and any claimed address not in the list yet). DuckDuckGo publishes no
     * reverse-DNS names: list only.
     */
    public const CRAWLERS = [
        'google' => ['ua' => '~Googlebot|AdsBot-Google|Mediapartners-Google|APIs-Google|FeedFetcher-Google|Google-InspectionTool|GoogleOther|Storebot-Google|Google-Safety~i',
            'lists' => ['https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
                'https://developers.google.com/static/crawling/ipranges/special-crawlers.json'],
            'rdns' => ['.googlebot.com', '.google.com', '.googleusercontent.com']],
        'bing' => ['ua' => '~bingbot|adidxbot|BingPreview|MicrosoftPreview|msnbot~i',
            'lists' => ['https://www.bing.com/toolbox/bingbot.json'], 'rdns' => ['.search.msn.com']],
        'yandex' => ['ua' => '~Yandex(?:Bot|Images|MobileBot|Video|Accessibility|RenderResources|Metrika|News|Webmaster|Favicons|ImageResizer|Market|Direct)~i',
            'lists' => [], 'rdns' => ['.yandex.ru', '.yandex.net', '.yandex.com']],
        'duckduckgo' => ['ua' => '~DuckDuckBot|DuckAssistBot~i', 'lists' => ['https://duckduckgo.com/duckduckbot.json'], 'rdns' => []],
        'apple' => ['ua' => '~Applebot~i', 'lists' => ['https://search.developer.apple.com/applebot.json'], 'rdns' => ['.applebot.apple.com']],
    ];
    public const CRAWLER_DNS_TTL = 86400;      // a reverse-DNS verdict is reused for 24 h
    public const CRAWLER_DNS_MAX = 4096;       // cached verdicts (oldest dropped)
    public const CRAWLER_PENDING_MAX = 256;    // addresses waiting for the reverse-DNS check (housekeeping)
    /**
     * Shortest prefix a published crawler range may have (verify r2 O-W1): the real lists are at most /22 (IPv4) and /64
     * (IPv6) wide. A fetched list holding a wider range (0.0.0.0/0, ::/0, a /8 ...) is refused as a whole and the engine's
     * last good list stays: one poisoned entry must never make every address a verified crawler. Stored entries wider than
     * this (a file written before the check) are ignored when matching.
     */
    public const CRAWLER_MIN_BITS_V4 = 16;
    public const CRAWLER_MIN_BITS_V6 = 32;
    /**
     * Lists shipped in the package (verify r3 OW1): application/data/crawlers/<engine>.json, in the engines' own published
     * format ({"prefixes":[{"ipv4Prefix":..},{"ipv6Prefix":..}]}) plus "fetched" (unix time) and "sources". They verify the
     * listed crawlers from the first request on every host while runtime/gate/crawler-<engine>.json does not exist yet (a
     * host without cron and without fastcgi / litespeed finish-request, a fresh install, a wiped runtime); the first use
     * stores them compiled there (marked 'shipped'), the daily refresh replaces them. Same checks as a fetched list.
     * Refreshed before a release by "php tools/housekeeping.php --write-crawler-lists".
     */
    public const CRAWLER_SHIPPED_DIR = 'application/data/crawlers/';
    private static $crawlerRanges = [];

    /**
     * The engine a User-Agent claims to be ('' = none). A claim only: crawlerVerify() decides. $engines = ScaleTier settings
     * 'crawlers' (built-in keys with ua null, custom engines with a plain UA token).
     */
    public static function crawlerClaim($ua, array $engines)
    {
        if (!is_string($ua) || $ua === '' || strlen($ua) > 1024 || !$engines) return '';
        foreach ($engines as $key => $def) {
            $token = is_array($def) ? ($def['ua'] ?? null) : null;
            if ($token === null) {
                if (isset(self::CRAWLERS[$key]) && preg_match(self::CRAWLERS[$key]['ua'], $ua)) return (string)$key;
            } elseif (is_string($token) && $token !== '' && stripos($ua, $token) !== false) {
                return (string)$key;
            }
        }
        return '';
    }

    /**
     * true when $ip (the client address from the trusted-proxy rules, never a raw header) belongs to $engine: in its published
     * IP ranges, or confirmed by reverse DNS + forward lookup within the last 24 h. Otherwise false, and the address is queued
     * for the reverse-DNS check that housekeeping runs off the request path (resolvePendingCrawlers), so a real crawler
     * from an address that is not in the list yet is recognized from the next housekeeping run on. Never does network IO.
     */
    public static function crawlerVerify($root, $engine, $ip, array $def = [])
    {
        try {
            $ip = self::normalizeIp($ip);
            if ($ip === '' || !preg_match('/^[a-z0-9_-]{2,24}$/', (string)$engine)) return false;
            if (self::inNetworks($ip, self::PRIVATE_NETS)) return false;
            $dir = self::gateDir($root);
            if (self::inCrawlerRanges($root, $dir, (string)$engine, $ip)) return true;
            $suffixes = isset($def['rdns']) && is_array($def['rdns']) ? $def['rdns'] : (self::CRAWLERS[$engine]['rdns'] ?? []);
            if (!$suffixes) return false;
            $cache = self::readJsonFile($dir . 'crawler-dns.json');
            $hit = $cache[$ip] ?? null;
            if (is_array($hit) && count($hit) === 3 && (int)$hit[2] > time() - self::CRAWLER_DNS_TTL) {
                return (int)$hit[1] === 1 && (string)$hit[0] === (string)$engine;
            }
            self::queueCrawler($dir, $ip, (string)$engine, $suffixes);
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Published ranges of one engine, from runtime/gate/crawler-<engine>.json (compiled by refreshCrawlerRanges), else from
     * the list shipped in the package (shippedCrawlerRanges(), verify r3 OW1).
     */
    private static function inCrawlerRanges($root, $dir, $engine, $ip)
    {
        if (!array_key_exists($engine, self::$crawlerRanges)) {
            $data = self::readJsonFile($dir . 'crawler-' . $engine . '.json');
            $data = is_array($data) && isset($data['idx']) && is_array($data['idx']) ? $data : null;
            self::$crawlerRanges[$engine] = $data !== null ? $data : self::shippedCrawlerRanges($root, $dir, $engine);
        }
        $data = self::$crawlerRanges[$engine];
        if ($data === null) return false;
        $packed = @inet_pton($ip);
        if ($packed === false) return false;
        $key = strlen($packed) === 4 ? '4:' . bin2hex(substr($packed, 0, 2)) : '6:' . bin2hex(substr($packed, 0, 4));
        $cands = array_merge(isset($data['idx'][$key]) && is_array($data['idx'][$key]) ? $data['idx'][$key] : [], is_array($data['wide'] ?? null) ? $data['wide'] : []);
        foreach ($cands as $c) {
            if (!is_array($c) || count($c) !== 2) continue;
            $net = @hex2bin((string)$c[0]);
            $bits = (int)$c[1];
            if ($net === false || strlen($net) !== strlen($packed) || $bits < 0 || $bits > strlen($net) * 8) continue;
            // never a catch-all range (O-W1): a stored entry wider than the published lists ever are matches nothing
            if ($bits < (strlen($net) === 4 ? self::CRAWLER_MIN_BITS_V4 : self::CRAWLER_MIN_BITS_V6)) continue;
            $whole = intdiv($bits, 8); $rest = $bits % 8;
            if (substr($packed, 0, $whole) !== substr($net, 0, $whole)) continue;
            if ($rest && (ord($packed[$whole]) & (255 << (8 - $rest)) & 255) !== (ord($net[$whole]) & (255 << (8 - $rest)) & 255)) continue;
            return true;
        }
        return false;
    }

    /** Remember an unverified claim for the off-request reverse-DNS check (bounded; a busy lock skips it). */
    private static function queueCrawler($dir, $ip, $engine, array $suffixes)
    {
        $file = $dir . 'crawler-pending.json';
        $list = self::readJsonFile($file);
        if (isset($list[$ip]) || (is_array($list) && count($list) >= self::CRAWLER_PENDING_MAX)) return;
        $h = @fopen($file, 'c+');
        if (!$h) return;
        try {
            if (!@flock($h, LOCK_EX | LOCK_NB)) return;
            $raw = stream_get_contents($h);
            $list = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($list)) $list = [];
            if (!isset($list[$ip]) && count($list) < self::CRAWLER_PENDING_MAX) {
                $list[$ip] = [$engine, array_values($suffixes), time()];
                $bytes = json_encode($list, JSON_UNESCAPED_SLASHES);
                ftruncate($h, 0); rewind($h); fwrite($h, $bytes); fflush($h);
            }
            @flock($h, LOCK_UN);
        } finally {
            fclose($h);
        }
    }

    /**
     * Housekeeping (off the request path): reverse DNS + forward confirmation of the queued crawler claims. $resolver($ip)
     * returns ['ptr' => host name or '', 'forward' => [addresses of that host name]] (default: gethostbyaddr + dns_get_record
     * A / AAAA). Verified = the PTR name ends with one of the engine's suffixes AND that name resolves back to the address.
     * Verdicts are cached 24 h (runtime/gate/crawler-dns.json, at most CRAWLER_DNS_MAX). Stops at $deadline (microtime).
     * Returns ['checked' => n, 'verified' => n, 'left' => n].
     */
    public static function resolvePendingCrawlers($root, $deadline, ?callable $resolver = null, $max = 50)
    {
        $dir = self::gateDir($root);
        $file = $dir . 'crawler-pending.json';
        $pending = self::readJsonFile($file);
        $out = ['checked' => 0, 'verified' => 0, 'left' => is_array($pending) ? count($pending) : 0];
        if (!is_array($pending) || !$pending) return $out;
        $resolver = $resolver ?: [__CLASS__, 'dnsResolve'];
        $done = [];
        $verdicts = [];
        foreach ($pending as $ip => $row) {
            if ($out['checked'] >= $max || microtime(true) >= $deadline) break;
            $done[] = $ip;
            $ipN = self::normalizeIp((string)$ip);
            if ($ipN === '' || !is_array($row) || count($row) < 2 || !is_array($row[1])) continue;
            $out['checked']++;
            $ok = 0;
            try {
                $r = $resolver($ipN);
                $ptr = is_array($r) ? strtolower(rtrim((string)($r['ptr'] ?? ''), '.')) : '';
                if ($ptr !== '' && $ptr !== $ipN) {
                    foreach ($row[1] as $sfx) {
                        $sfx = strtolower((string)$sfx);
                        if ($sfx !== '' && $sfx[0] === '.' && substr($ptr, -strlen($sfx)) === $sfx) {
                            foreach ((array)($r['forward'] ?? []) as $f) {
                                if (self::normalizeIp((string)$f) === $ipN) { $ok = 1; break 2; }
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $ok = 0;
            }
            $verdicts[$ipN] = [(string)$row[0], $ok, time()];
            $out['verified'] += $ok;
        }
        // write the verdicts, then drop the handled addresses from the queue (both under their own lock)
        if ($verdicts) {
            self::updateJsonFile($dir . 'crawler-dns.json', function ($cache) use ($verdicts) {
                $cache = is_array($cache) ? $cache : [];
                foreach ($verdicts as $ip => $v) { unset($cache[$ip]); $cache[$ip] = $v; }
                $limit = time() - self::CRAWLER_DNS_TTL;
                foreach ($cache as $ip => $v) { if (!is_array($v) || (int)($v[2] ?? 0) < $limit) unset($cache[$ip]); }
                if (count($cache) > self::CRAWLER_DNS_MAX) $cache = array_slice($cache, -self::CRAWLER_DNS_MAX, null, true);
                return $cache;
            });
        }
        if ($done) {
            $left = self::updateJsonFile($file, function ($list) use ($done) {
                $list = is_array($list) ? $list : [];
                foreach ($done as $ip) unset($list[$ip]);
                return $list;
            });
            $out['left'] = is_array($left) ? count($left) : 0;
        }
        return $out;
    }

    /** Default resolver of resolvePendingCrawlers (system resolver; called only off the request path). */
    public static function dnsResolve($ip)
    {
        $ptr = @gethostbyaddr($ip);
        if (!is_string($ptr) || $ptr === '' || $ptr === $ip) return ['ptr' => '', 'forward' => []];
        $forward = [];
        $recs = @dns_get_record($ptr, strpos($ip, ':') !== false ? DNS_AAAA : DNS_A);
        foreach (is_array($recs) ? $recs : [] as $r) {
            if (isset($r['ip'])) $forward[] = $r['ip'];
            if (isset($r['ipv6'])) $forward[] = $r['ipv6'];
        }
        return ['ptr' => $ptr, 'forward' => $forward];
    }

    /**
     * Daily refresh (housekeeping): $fetch(url) returns the body of each engine's published range file or null. Google /
     * Bing / Apple / DuckDuckGo publish {"prefixes":[{"ipv4Prefix":..},{"ipv6Prefix":..}]}. An engine's list is replaced
     * only when every file of that engine was read and they hold >= 3 valid CIDRs together (<= 20,000), none of them wider
     * than /16 (IPv4) or /32 (IPv6) (O-W1: a list with 0.0.0.0/0 or ::/0 is refused whole); otherwise its last good list
     * stays. Returns [engine => ['ok' => bool, 'count' => int, 'why' => '' | 'wide_range' | ...]].
     */
    public static function refreshCrawlerRanges($root, callable $fetch, ?array $engines = null)
    {
        $dir = self::gateDir($root);
        $out = [];
        foreach (self::CRAWLERS as $engine => $def) {
            if (!$def['lists'] || ($engines !== null && !array_key_exists($engine, $engines))) continue;
            $bodies = [];
            foreach ($def['lists'] as $url) {
                $body = null;
                try { $body = $fetch($url); } catch (\Throwable $e) { $body = null; }
                $bodies[] = $body;
            }
            $p = self::parseCrawlerLists($bodies);
            if (!$p['ok']) {
                $out[$engine] = ['ok' => false, 'count' => count($p['cidrs']), 'why' => $p['why']] + ($p['why'] === 'wide_range' ? ['range' => $p['range']] : []);
                continue;
            }
            $ok = self::writeJsonFile($dir . 'crawler-' . $engine . '.json', self::compileRanges($p['cidrs']) + ['n' => count($p['cidrs']), 'at' => time()]);
            unset(self::$crawlerRanges[$engine]);
            $out[$engine] = ['ok' => $ok, 'count' => count($p['cidrs'])];
        }
        return $out;
    }

    /**
     * The checks every crawler list passes before use (fetched or shipped): each body is a published list file
     * ({"prefixes":[...]}, <= 1 MB); together they hold 3-20,000 valid CIDRs, none wider than /16 (IPv4) or /32 (IPv6) (one
     * such entry refuses the whole list: O-W1). Returns ['ok' => bool, 'cidrs' => [...], 'why' => '' | 'fetch' | 'count' |
     * 'wide_range', 'range' => the wide entry].
     */
    private static function parseCrawlerLists(array $bodies)
    {
        $cidrs = [];
        $wide = '';
        foreach ($bodies as $body) {
            $data = is_string($body) && strlen($body) <= 1048576 ? json_decode($body, true) : (is_array($body) ? $body : null);
            if (!is_array($data) || !isset($data['prefixes']) || !is_array($data['prefixes'])) {
                return ['ok' => false, 'cidrs' => [], 'why' => 'fetch', 'range' => ''];
            }
            foreach ($data['prefixes'] as $p) {
                foreach (['ipv4Prefix', 'ipv6Prefix'] as $k) {
                    if (is_array($p) && isset($p[$k]) && is_string($p[$k]) && strpos($p[$k], '/') !== false && self::validCidr($p[$k])) {
                        if (!self::crawlerPrefixOk($p[$k])) { $wide = strtolower($p[$k]); continue; }
                        $cidrs[] = strtolower($p[$k]);
                    }
                }
            }
        }
        $cidrs = array_values(array_unique($cidrs));
        if ($wide !== '') {
            // one entry wider than any published crawler range: the whole list is untrusted (a compromised source)
            return ['ok' => false, 'cidrs' => $cidrs, 'why' => 'wide_range', 'range' => substr($wide, 0, 49)];
        }
        if (count($cidrs) < 3 || count($cidrs) > 20000) {
            return ['ok' => false, 'cidrs' => $cidrs, 'why' => 'count', 'range' => ''];
        }
        return ['ok' => true, 'cidrs' => $cidrs, 'why' => '', 'range' => ''];
    }

    /** Path of the list shipped in the package for $engine (CRAWLER_SHIPPED_DIR). */
    public static function shippedCrawlerFile($root, $engine)
    {
        return rtrim(str_replace('\\', '/', (string)$root), '/') . '/' . self::CRAWLER_SHIPPED_DIR . $engine . '.json';
    }

    /**
     * The compiled shipped list of $engine (verify r3 OW1) or null (none, unreadable, refused by parseCrawlerLists()). The
     * first use stores it as runtime/gate/crawler-<engine>.json (marked 'shipped', 'at' = the date the list was fetched for
     * the package) when that file still does not exist, so later requests read the compiled copy; the daily refresh
     * (housekeeping, tools/upgrade.php, Housekeeping::refreshCrawlerLists()) replaces it with the engine's current list.
     */
    private static function shippedCrawlerRanges($root, $dir, $engine)
    {
        if (!isset(self::CRAWLERS[$engine]) || !self::CRAWLERS[$engine]['lists']) return null;
        $file = self::shippedCrawlerFile($root, $engine);
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file, false, null, 0, 1048577);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) return null;
        $p = self::parseCrawlerLists([$data]);
        if (!$p['ok']) return null;
        $fetched = isset($data['fetched']) && is_numeric($data['fetched']) ? (int)$data['fetched'] : (int)@filemtime($file);
        $compiled = self::compileRanges($p['cidrs']) + ['n' => count($p['cidrs']), 'at' => max(0, min($fetched, time())), 'shipped' => 1];
        $target = $dir . 'crawler-' . $engine . '.json';
        if (!is_file($target)) self::writeJsonFile($target, $compiled);
        return $compiled;
    }

    /**
     * Packaging (tools/housekeeping.php --write-crawler-lists, before a release): fetch every engine's published lists with
     * $fetch(url) and write them, checked like a refresh, as application/data/crawlers/<engine>.json under $root. An engine
     * whose lists fail a check keeps its shipped file. $fetchedAt: [engine => unix time the lists were fetched] when they
     * were fetched earlier (default: now). Returns [engine => ['ok' => bool, 'count' => n, 'why' => ...]].
     */
    public static function writeShippedCrawlerLists($root, callable $fetch, array $fetchedAt = [])
    {
        $dir = rtrim(str_replace('\\', '/', (string)$root), '/') . '/' . self::CRAWLER_SHIPPED_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $out = [];
        foreach (self::CRAWLERS as $engine => $def) {
            if (!$def['lists']) continue;
            $bodies = [];
            foreach ($def['lists'] as $url) {
                $body = null;
                try { $body = $fetch($url); } catch (\Throwable $e) { $body = null; }
                $bodies[] = $body;
            }
            $p = self::parseCrawlerLists($bodies);
            if (!$p['ok']) {
                $out[$engine] = ['ok' => false, 'count' => count($p['cidrs']), 'why' => $p['why']];
                continue;
            }
            // the engines' own format, one range per line (reviewable diffs between releases)
            $lines = [];
            foreach ($p['cidrs'] as $c) $lines[] = '    ' . json_encode([strpos($c, ':') !== false ? 'ipv6Prefix' : 'ipv4Prefix' => $c], JSON_UNESCAPED_SLASHES);
            $at = isset($fetchedAt[$engine]) && (int)$fetchedAt[$engine] > 0 ? (int)$fetchedAt[$engine] : time();
            $bytes = "{\n  \"engine\": " . json_encode($engine) . ",\n  \"fetched\": " . $at . ",\n  \"fetched_utc\": " . json_encode(gmdate('Y-m-d\TH:i:s\Z', $at))
                . ",\n  \"sources\": " . json_encode($def['lists'], JSON_UNESCAPED_SLASHES) . ",\n  \"prefixes\": [\n" . implode(",\n", $lines) . "\n  ]\n}\n";
            $ok = self::writeJsonFileRaw($dir . $engine . '.json', $bytes);
            $out[$engine] = ['ok' => $ok, 'count' => count($p['cidrs'])];
        }
        return $out;
    }

    /**
     * true when a crawler range is not wider than CRAWLER_MIN_BITS_V4 / _V6 (a bare address counts as /32 or /128).
     */
    public static function crawlerPrefixOk($cidr)
    {
        if (!is_string($cidr) || !self::validCidr($cidr)) return false;
        $parts = explode('/', $cidr, 2);
        $v6 = strpos(self::normalizeIp($parts[0]), ':') !== false;
        $bits = isset($parts[1]) ? (int)$parts[1] : ($v6 ? 128 : 32);
        return $bits >= ($v6 ? self::CRAWLER_MIN_BITS_V6 : self::CRAWLER_MIN_BITS_V4);
    }

    /**
     * CIDR list -> ['idx' => ["4:<2 bytes hex>" | "6:<4 bytes hex>" => [[network hex, bits], ...]], 'wide' => [...]].
     * Ranges wider than CRAWLER_MIN_BITS_V4 / _V6 are dropped (O-W1; refreshCrawlerRanges refuses such a list before this).
     */
    public static function compileRanges(array $cidrs)
    {
        $idx = []; $wide = [];
        foreach ($cidrs as $cidr) {
            if (!is_string($cidr) || !self::validCidr($cidr) || !self::crawlerPrefixOk($cidr)) continue;
            $parts = explode('/', $cidr, 2);
            $net = @inet_pton(self::normalizeIp($parts[0]));
            if ($net === false) continue;
            $bits = isset($parts[1]) ? (int)$parts[1] : strlen($net) * 8;
            $entry = [bin2hex($net), $bits];
            $keyBytes = strlen($net) === 4 ? 2 : 4;
            if ($bits >= $keyBytes * 8) {
                $idx[(strlen($net) === 4 ? '4:' : '6:') . bin2hex(substr($net, 0, $keyBytes))][] = $entry;
            } else {
                $wide[] = $entry;
            }
        }
        return ['idx' => $idx, 'wide' => $wide];
    }

    /**
     * State of the crawler lists for the Speed page and tools/upgrade.php: [engine => ['count' => n|null, 'at' => unix|0,
     * 'shipped' => true while the list in use is the one shipped in the package (its 'at' = the date it was fetched)]].
     */
    public static function crawlerStatus($root)
    {
        $dir = self::gateDir($root);
        $out = [];
        foreach (self::CRAWLERS as $engine => $def) {
            $d = $def['lists'] ? self::readJsonFile($dir . 'crawler-' . $engine . '.json') : null;
            if ($def['lists'] && !is_array($d)) {
                // not used yet on this host: the shipped list (compiled and stored by its first use)
                $d = self::shippedCrawlerRanges($root, $dir, $engine);
            }
            $out[$engine] = ['list' => (bool)$def['lists'], 'count' => is_array($d) ? (int)($d['n'] ?? 0) : null, 'at' => is_array($d) ? (int)($d['at'] ?? 0) : 0,
                'rdns' => (bool)$def['rdns'], 'shipped' => is_array($d) && !empty($d['shipped'])];
        }
        $dns = self::readJsonFile($dir . 'crawler-dns.json');
        $pending = self::readJsonFile($dir . 'crawler-pending.json');
        return ['engines' => $out, 'dns_cached' => is_array($dns) ? count($dns) : 0, 'pending' => is_array($pending) ? count($pending) : 0];
    }

    /** Test hook: forget the per-request crawler range memo. */
    public static function resetCrawlers()
    {
        self::$crawlerRanges = [];
    }

    private static function gateDir($root)
    {
        $dir = rtrim(str_replace('\\', '/', (string)$root), '/') . '/runtime/gate/';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    private static function readJsonFile($file)
    {
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    private static function writeJsonFile($file, array $data)
    {
        $bytes = json_encode($data, JSON_UNESCAPED_SLASHES);
        if (!is_string($bytes)) return false;
        return self::writeJsonFileRaw($file, $bytes);
    }

    /** Atomic write of prepared bytes (temporary file + rename; Windows: the target is removed first when rename fails). */
    private static function writeJsonFileRaw($file, $bytes)
    {
        $tmp = $file . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes)) { @unlink($tmp); return false; }
        if (!@rename($tmp, $file)) { @unlink($file); if (!@rename($tmp, $file)) { @unlink($tmp); return false; } }
        return true;
    }

    /** Read-modify-write of a small JSON file under its lock (blocking: housekeeping only). Returns the new content. */
    private static function updateJsonFile($file, callable $fn)
    {
        $h = @fopen($file, 'c+');
        if (!$h) return null;
        try {
            if (!@flock($h, LOCK_EX)) return null;
            $raw = stream_get_contents($h);
            $cur = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $new = $fn(is_array($cur) ? $cur : null);
            $bytes = json_encode($new ?: new \stdClass(), JSON_UNESCAPED_SLASHES);
            ftruncate($h, 0); rewind($h); fwrite($h, $bytes); fflush($h);
            @flock($h, LOCK_UN);
            return $new;
        } finally {
            fclose($h);
        }
    }

    /** Test hook: forget the cached Cloudflare list. */
    public static function resetRanges()
    {
        self::$cfRanges = null;
    }

    private static function tunnelOn(array $config)
    {
        return !empty($config['_tunnel']);
    }

    private static function tunnelSetting($realRoot)
    {
        // app.cf_tunnel (maccms.php, HP-H-4) through the lane H settings snapshot; absent class or file = off.
        try {
            if (!class_exists('\app\common\util\ScaleTier', false)) {
                $f = __DIR__ . '/ScaleTier.php';
                if (!is_file($f)) return false;
                require_once $f;
            }
            if (self::path(\app\common\util\ScaleTier::root()) !== self::path($realRoot)) return false;
            $s = \app\common\util\ScaleTier::settings();
            return !empty($s['cf_tunnel']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function route(array $server, array $get)
    {
        if (is_string($get['s'] ?? null)) $route = $get['s'];
        elseif (is_string($server['PATH_INFO'] ?? null) && $server['PATH_INFO'] !== '') $route = $server['PATH_INFO'];
        elseif (is_string($server['ORIG_PATH_INFO'] ?? null) && $server['ORIG_PATH_INFO'] !== '') $route = $server['ORIG_PATH_INFO'];
        elseif (is_string($server['QUERY_STRING'] ?? null) && substr($server['QUERY_STRING'], 0, 1) === '/') $route = explode('&', $server['QUERY_STRING'], 2)[0];
        else $route = explode('?', (string) ($server['REQUEST_URI'] ?? ''), 2)[0];
        if (strlen($route) > 8192) return '';
        $route = strtolower(rawurldecode(explode('?', $route, 2)[0]));
        $scriptDir = str_replace('\\', '/', dirname((string) ($server['SCRIPT_NAME'] ?? '/index.php')));
        if ($scriptDir !== '/' && $scriptDir !== '.' && strpos($route, strtolower($scriptDir) . '/') === 0) $route = substr($route, strlen($scriptDir));
        $parts = array_values(array_filter(explode('/', trim($route, '/')), function ($p) { return $p !== ''; }));
        if (($parts[0] ?? '') === 'index.php') array_shift($parts);
        if (isset($parts[0]) && preg_match('/^[a-z]{2}(?:[-_][a-z]{2})?$/D', $parts[0])) array_shift($parts);
        return implode('/', $parts);
    }

    private static function callback($controller, $action)
    {
        $routes = [
            'payment' => ['notify'], 'receive' => ['index', 'vod', 'art', 'actor', 'role', 'website', 'comment'],
            'wechat' => ['index'], 'user' => ['logincallback'], 'avdbschedule' => ['index', 'bridge'],
            'avdbschedulebridge' => ['register', 'disable', 'health'],
            'translateapi' => ['index', 'all_terms', 'terms', 'new_videos', 'updated_videos', 'video', 'untranslated_videos', 'incomplete_videos', 'batch_videos', 'save_video_batch', 'bulk_save', 'bulk_incomplete', 'save_terms', 'save_video', 'stats', 'progress', 'cleanup_translations', 'batch_titles', 'ui_dict'],
        ];
        return isset($routes[$controller]) && in_array($action, $routes[$controller], true);
    }

    private static function config($root, $override)
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) throw new \RuntimeException('site root');
        // PHP on Windows may return an 8.3 short temp path. Canonicalize only the
        // system-selected default so identity checks do not reject the same directory.
        $systemTemp = sys_get_temp_dir();
        $canonicalTemp = @realpath($systemTemp);
        if ($canonicalTemp !== false && @is_dir($canonicalTemp)) $systemTemp = $canonicalTemp;
        $config = ['storage_dir' => rtrim($systemTemp, '/\\') . DIRECTORY_SEPARATOR . 'public-request-guard-' . self::siteId($root),
            'limits' => self::LIMITS, 'max_body_bytes' => ['post' => 8388608, 'community' => 65536],
            'trusted_proxies' => [], 'proxy_header' => 'X-Forwarded-For'];
        $file = $realRoot . '/application/extra/public_request_guard.php';
        $custom = [];
        if ($override !== null) $custom = $override;
        elseif (@file_exists($file) || @is_link($file)) {
            if (@is_link($file) || !@is_file($file) || @filesize($file) > self::MAX_BYTES
                || self::path(@realpath($file)) !== self::path($file)) throw new \RuntimeException('config path');
            ob_start();
            try { $custom = (static function ($path) { return include $path; })($file); }
            finally { ob_end_clean(); }
        }
        if (!is_array($custom) || array_diff(array_keys($custom), array_keys($config))) throw new \RuntimeException('config shape');
        $config = array_replace_recursive($config, $custom);
        if (!is_string($config['storage_dir']) || $config['storage_dir'] === '' || strpos($config['storage_dir'], "\0") !== false
            || !preg_match('~^(?:[a-zA-Z]:[\\\\/]|/)~', $config['storage_dir'])) throw new \RuntimeException('store path');
        if (!is_array($config['limits']) || array_keys($config['limits']) !== array_keys(self::LIMITS)) throw new \RuntimeException('budgets');
        foreach ($config['limits'] as $limits) {
            if (!is_array($limits) || array_keys($limits) !== ['ip', 'site']) throw new \RuntimeException('limit shape');
            foreach ($limits as $limit) if (!is_int($limit) || $limit < 1 || $limit > 1000000) throw new \RuntimeException('limit value');
        }
        if (!is_array($config['max_body_bytes']) || array_keys($config['max_body_bytes']) !== ['post', 'community']) throw new \RuntimeException('body limits');
        foreach ($config['max_body_bytes'] as $limit) if (!is_int($limit) || $limit < 1 || $limit > 67108864) throw new \RuntimeException('body limit value');
        if (!is_array($config['trusted_proxies']) || count($config['trusted_proxies']) > 64) throw new \RuntimeException('proxy list');
        foreach ($config['trusted_proxies'] as $cidr) if (!self::validCidr($cidr)) throw new \RuntimeException('proxy network');
        if (!in_array($config['proxy_header'], ['X-Forwarded-For', 'X-Real-IP', 'CF-Connecting-IP'], true)) throw new \RuntimeException('proxy header');
        // Only the default temp store may fall back. An explicitly configured bad path fails closed.
        $config['_fallback_dir'] = array_key_exists('storage_dir', $custom) ? null : $realRoot . '/runtime/public-request-guard';
        $config['_tunnel'] = self::tunnelSetting($realRoot);
        return $config;
    }

    private static function consume($root, $ip, array $checks, array $config, $now)
    {
        $handles = []; $states = []; $new = []; $shards = []; $siteChecks = [];
        try {
            try { $dir = self::directory($config['storage_dir']); }
            catch (\RuntimeException $e) {
                if ($config['_fallback_dir'] === null || !in_array($e->getMessage(), ['create store', 'unavailable store'], true)) throw $e;
                $dir = self::fallbackDirectory($config['_fallback_dir']);
            }
            foreach ($checks as $check) {
                $who = isset($check['who']) ? (string)$check['who'] : $ip;
                $key = hash('sha256', self::siteId($root) . "\0" . $check['bucket'] . "\0" . $check['window'] . "\0" . $who);
                $shard = 'shard-' . substr($key, 0, 2) . '.json';
                // ip null = no per-IP budget for this request (pooled proxy peer): only the site budget counts
                if ($check['ip'] !== null) $shards[$shard][] = $check + ['key' => $key];
                if ($check['site'] !== null) $siteChecks[] = $check;
            }
            if ($siteChecks) {
                $handles['global.json'] = self::open($dir . '/global.json', $fresh);
                $states['global.json'] = self::read($handles['global.json'], $fresh, true);
                foreach ($siteChecks as $check) {
                    $test = self::available($states['global.json'], $check['bucket'], $check['site'], $check['window'], $now);
                    if ($test !== null) return $test + ['scope' => 'site'];
                }
            }
            ksort($shards);
            foreach ($shards as $name => $entries) {
                $handles[$name] = self::open($dir . '/' . $name, $fresh);
                $states[$name] = self::read($handles[$name], $fresh, false);
                foreach ($entries as $check) {
                    $test = self::available($states[$name], $check['key'], $check['ip'], $check['window'], $now);
                    if ($test !== null) return $test + ['scope' => $check['bucket'] === 'account' ? 'account' : 'ip'];
                }
            }
            // Check every limit and capacity before committing any count. Expired entries alone are reclaimed.
            foreach ($states as $name => $state) {
                $entries = array_filter($state['e'], function ($e) use ($now) { return $e[0] > $now; });
                $budgets = $name === 'global.json' ? $siteChecks : $shards[$name];
                foreach ($budgets as $check) {
                    $key = $name === 'global.json' ? $check['bucket'] : $check['key'];
                    $expiration = self::expiration($now, $check['window']);
                    $count = isset($entries[$key]) ? $entries[$key][1] : 0;
                    $entries[$key] = [$expiration, $count + 1];
                }
                if (count($entries) > self::MAX_ENTRIES) return self::result(503, 1);
                $bytes = json_encode(['v' => 1, 'e' => $entries], JSON_UNESCAPED_SLASHES);
                if (!is_string($bytes) || strlen($bytes) > self::MAX_BYTES) return self::result(503, 1);
                $new[$name] = $bytes;
            }
            // Site counts commit first. A later local IO failure can only reduce available allowance.
            foreach ($new as $name => $bytes) self::write($handles[$name], $bytes);
            return self::result(200, 0);
        } catch (\Throwable $e) { return self::result(503, 1); }
        finally { foreach ($handles as $handle) { @flock($handle, LOCK_UN); @fclose($handle); } }
    }

    private static function available(array $state, $key, $limit, $window, $now)
    {
        if (!isset($state['e'][$key]) || $state['e'][$key][0] <= $now) return null;
        $entry = $state['e'][$key];
        if ($entry[0] !== self::expiration($now, $window)) return self::result(503, 1);
        return $entry[1] >= $limit ? self::result(429, max(1, $entry[0] - $now)) : null;
    }

    private static function directory($dir)
    {
        if (@is_link($dir)) throw new \RuntimeException('linked store');
        if (!@is_dir($dir)) {
            if (@file_exists($dir)) throw new \RuntimeException('store is not directory');
            if (!@mkdir($dir, 0700) && !@is_dir($dir)) throw new \RuntimeException('create store');
        }
        $real = @realpath($dir);
        if ($real === false || self::path($real) !== self::path($dir) || @is_link($dir)) throw new \RuntimeException('store path changed');
        $stat = @lstat($dir);
        if (!$stat || !self::privateStat($stat, true)) throw new \RuntimeException('unsafe store');
        if (!@is_writable($dir)) throw new \RuntimeException('unavailable store');
        return $real;
    }

    private static function fallbackDirectory($dir)
    {
        $parent = dirname($dir); $real = @realpath($parent); $stat = @lstat($parent);
        // The existing runtime directory is protected by the application's web-server deny rule.
        // It must additionally be owner-controlled: never create a parent or repair unsafe modes.
        if (!$real || @is_link($parent) || !@is_dir($parent) || !@is_writable($parent)
            || self::path($real) !== self::path($parent) || !$stat || ($stat['mode'] & 0170000) !== 0040000) throw new \RuntimeException('fallback parent');
        if (PHP_OS_FAMILY !== 'Windows' && (($stat['mode'] & 0022) !== 0
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()))) throw new \RuntimeException('unsafe fallback parent');
        return self::directory($dir);
    }

    private static function open($path, &$fresh)
    {
        clearstatcache(true, $path);
        if (is_link($path)) throw new \RuntimeException('linked state');
        $fresh = !file_exists($path);
        if (!$fresh && (!is_file($path) || !self::privateStat(@lstat($path), false))) throw new \RuntimeException('unsafe state');
        $handle = @fopen($path, 'c+b');
        if (!$handle) throw new \RuntimeException('open state');
        if ($fresh && !@chmod($path, 0600)) { fclose($handle); throw new \RuntimeException('state mode'); }
        $stat = @fstat($handle); $named = @lstat($path);
        if (!$stat || !$named || !self::privateStat($stat, false) || is_link($path)
            || $stat['ino'] !== $named['ino'] || $stat['dev'] !== $named['dev']) { fclose($handle); throw new \RuntimeException('state identity'); }
        // 1.2.0 (L5, HARD F11): a short bounded wait (50 ms) instead of one LOCK_NB try, which turned ordinary concurrency
        // (8 parallel workers: 80 % of decisions) into 503. A filesystem without flock support stays fail-closed here.
        // Windows: sleeps last at least one timer tick (about 15 ms) and polling loses every race, so after the first try the
        // lock is taken blocking (local files only; the holder is a few hundred microseconds of file IO in this class).
        $deadline = microtime(true) + self::LOCK_WAIT_MS / 1000;
        while (true) {
            $wouldBlock = 0;
            if (@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) break;
            if (PHP_OS_FAMILY === 'Windows') {
                if (@flock($handle, LOCK_EX)) break;
                fclose($handle); throw new \RuntimeException('state busy');
            }
            if (!$wouldBlock || microtime(true) >= $deadline) { fclose($handle); throw new \RuntimeException('state busy'); }
            usleep(1000);
        }
        return $handle;
    }

    private static function privateStat($stat, $directory)
    {
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000)) return false;
        if (!$directory && isset($stat['nlink']) && $stat['nlink'] > 1) return false;
        // Windows PHP chmod cannot verify ACLs: inherit the service account's private temp directory.
        if (PHP_OS_FAMILY !== 'Windows') {
            if (($stat['mode'] & 0077) !== 0) return false;
            if (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid()) return false;
        }
        return true;
    }

    private static function read($handle, $fresh, $global)
    {
        $stat = fstat($handle);
        if ($stat['size'] > self::MAX_BYTES) throw new \RuntimeException('large state');
        if ($stat['size'] === 0 && $fresh) {
            $empty = ['v' => 1, 'e' => []];
            // Initialize once under lock: a new second shard cannot be poisoned by a later denial.
            self::write($handle, json_encode($empty));
            return $empty;
        }
        rewind($handle); $bytes = stream_get_contents($handle, self::MAX_BYTES);
        if (!is_string($bytes) || strlen($bytes) !== $stat['size']) throw new \RuntimeException('state read');
        $state = json_decode($bytes, true, 8);
        if (!self::validState($state, $global)) {
            // A truncated or malformed file is what a hard reset leaves behind (2026-10-12: the whole site
            // answered 503 until the file was removed by hand). We hold the exclusive lock, so resetting the
            // counters here is safe and keeps the guard fail-closed for every other error.
            $empty = ['v' => 1, 'e' => []];
            self::write($handle, json_encode($empty));
            return $empty;
        }
        return $state;
    }

    private static function validState($state, $global)
    {
        if (!is_array($state) || array_keys($state) !== ['v', 'e'] || $state['v'] !== 1 || !is_array($state['e']) || count($state['e']) > self::MAX_ENTRIES) return false;
        foreach ($state['e'] as $key => $entry) {
            if (($global ? !array_key_exists($key, self::LIMITS) : !preg_match('/^[a-f0-9]{64}$/D', (string) $key))
                || !is_array($entry) || array_keys($entry) !== [0, 1] || !is_int($entry[0]) || $entry[0] < 1
                || !is_int($entry[1]) || $entry[1] < 0) return false;
        }
        return true;
    }

    private static function write($handle, $bytes)
    {
        if (!rewind($handle) || fwrite($handle, $bytes) !== strlen($bytes)
            || !ftruncate($handle, strlen($bytes)) || !fflush($handle)) throw new \RuntimeException('state write');
    }

    private static function normalizeIp($ip)
    {
        if (!is_string($ip) || strlen($ip) > 45 || filter_var($ip, FILTER_VALIDATE_IP) === false) return '';
        $packed = @inet_pton($ip);
        if ($packed === false) return '';
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") $packed = substr($packed, 12);
        return strtolower(inet_ntop($packed));
    }

    private static function validCidr($cidr)
    {
        if (!is_string($cidr) || strlen($cidr) > 49) return false;
        $parts = explode('/', $cidr, 2); $ip = self::normalizeIp($parts[0]);
        if ($ip === '') return false;
        return !isset($parts[1]) || (ctype_digit($parts[1]) && (int) $parts[1] <= (strpos($ip, ':') === false ? 32 : 128));
    }

    private static function inNetworks($ip, array $networks)
    {
        $packed = @inet_pton($ip);
        foreach ($networks as $cidr) {
            if (!self::validCidr($cidr)) continue;
            $parts = explode('/', $cidr, 2); $network = inet_pton(self::normalizeIp($parts[0]));
            if (strlen($packed) !== strlen($network)) continue;
            $bits = isset($parts[1]) ? (int) $parts[1] : strlen($network) * 8;
            $whole = intdiv($bits, 8); $remainder = $bits % 8;
            if (substr($packed, 0, $whole) !== substr($network, 0, $whole)) continue;
            if ($remainder && (ord($packed[$whole]) & (255 << (8 - $remainder))) !== (ord($network[$whole]) & (255 << (8 - $remainder)))) continue;
            return true;
        }
        return false;
    }

    private static function now(array $options)
    {
        $now = $options['now'] ?? time();
        if (!is_int($now) || $now < 0) throw new \RuntimeException('clock');
        return $now;
    }

    private static function expiration($now, $window)
    {
        $start = intdiv($now, $window) * $window;
        if ($start > PHP_INT_MAX - $window) throw new \RuntimeException('budget clock overflow');
        return $start + $window;
    }

    private static function siteId($root)
    {
        $real = realpath($root);
        return substr(hash('sha256', self::path($real !== false ? $real : (string) $root)), 0, 32);
    }

    private static function path($path)
    {
        $path = rtrim(str_replace('\\', '/', (string) $path), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private static function result($status, $retry, array $consumed = [])
    {
        $messages = [200 => 'OK', 400 => 'Invalid request.', 405 => 'Method not allowed.', 411 => 'Content-Length is required for this request.', 413 => 'Request body is too large.', 429 => 'Too many requests. Please try again later.', 503 => 'Please try again shortly.'];
        return ['allowed' => $status === 200, 'status' => $status, 'code' => $status === 200 ? 1 : 0,
            'msg' => $messages[$status], 'retry_after' => $retry, 'consumed' => $consumed];
    }

    private static function finish(array $decision)
    {
        if ($decision['allowed']) return $decision;
        if (!empty($decision['empty_result'])) {
            // suggest over the site ceiling: an empty suggestion list, never an error for the visitor
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
            echo json_encode(['code' => 1, 'msg' => '', 'page' => 1, 'pagecount' => 0, 'limit' => 0, 'total' => 0, 'list' => [], 'url' => ''], JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code($decision['status']);
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        if ($decision['retry_after'] > 0) header('Retry-After: ' . $decision['retry_after']);
        echo json_encode(['code' => $decision['code'], 'msg' => $decision['msg'], 'retry_after' => $decision['retry_after']], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
