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

if (!class_exists(ScaleTier::class, false)) {
    require_once __DIR__ . '/ScaleTier.php';
}

/**
 * C5 (owner: lane H) + the pre-framework page cache of XVID-SCALE-BRIEF 2.10 L1/L2 (from the abuse-hardening prototype,
 * with every L1 change of the brief).
 *
 * Contract C5 (used by E, T, P, S):
 *   bumpGeneration(bool $force=false): void  admin "Clear cache", theme and settings changes ONLY, never imports. A copy of
 *                                            an older generation is "expired, usable as stale", never "absent". Without
 *                                            $force a bump is skipped when the last one is younger than 10 s.
 *   purge(array $vodIds): void               marker per id + delete the stored copies of that video (detail, watch, every
 *                                            language and mobile flag); a purged id is never served, not even stale.
 *   purgeRange(int $minId, int $maxId): void same for an id range (undo, remove all): one line in a small ranges file
 *                                            (<= 100 ranges, merged), so a mass removal never writes millions of markers.
 *
 * Lane H internals (index.php through HP-H-1, All.php through HP-H-2, housekeeping):
 *   serve(): bool        before ThinkPHP. Anonymous GET/HEAD with a fresh copy -> answered from runtime/edge (about 1-2 ms),
 *                        returns true (the caller exits). Expired copy: remembered as stale; when another request is
 *                        rendering the same key right now, this one gets the stale copy at once (single flight).
 *   finish($html, $meta) after a 200 render: stores the page only when the request URI is the page's canonical URL
 *                        (rel=canonical, or the video's own URL for detail/watch pages); a request that differs from it
 *                        only by extra query parameters gets a 301 to it; anything else is not stored. Gzip storage,
 *                        caps per tier (app.edge_max_mb / app.edge_max_files), admission in T3 (detail and watch pages
 *                        are stored on their second miss within the TTL), Content-Type kept (html, rss, xml). A render
 *                        whose landing facts were cut (renderCut(), flood-r9 R9-1a) is never stored: it keeps the
 *                        no-store headers, so no copy of it reaches a verified search-engine crawler.
 *   renderCut(): bool    true when this request rendered a landing whose facts were cut (TemplateSeo::landingCutSeen(),
 *                        lane S): neither this store nor the page cache (All::label_fetch, HP-H-9) keeps that page.
 *   shed(): void         the request cannot be rendered now (gate full, statement timeout): stale copy (never a purged
 *                        one); else, after a statement timeout (DbTimeout::seen) or for a verified search-engine crawler,
 *                        the lighter page lite() with HTTP 200 (owner decision 2026-10-17: never a bare error page);
 *                        else RequestGate::busy() (503, load shedding as designed); exits. Partial template output is
 *                        discarded first.
 *   lite(): void         the lighter page: site name, a short note, a "try again" link and the newest videos (a cached
 *                        list, rebuilt with one primary-key read at most every LITE_TTL seconds inside the framework).
 *   evict(), stats()     housekeeping and the Speed page.
 *   serve() also holds the L12 trigger: when housekeeping is due and no cron ran in 24 h it is scheduled after the
 *   response (FPM / LiteSpeed) or this request falls through to the framework and runs it at its end (one per minute).
 *   The same check continues a running web import (H4): with FPM / LiteSpeed, once per 30 s one request falls through and
 *   steps the job after its response (Housekeeping::importTrigger; one more stat of runtime/scale/import.due).
 * Cursor pages (H5): canonical after= / cursor= URLs are stored like any canonical page, with TTL_CURSOR (300 s) at most;
 * an after= / cursor= value of the wrong shape is never stored (cursorTtl()). Caps and admission unchanged.
 *
 * Eligible = GET/HEAD, no cookie except the allowlist (_ga*, _gid, _gat*, _fbp, __cf*, cf_*, _cfuvid) and avdb_ui_lang
 * holding an enabled locale (the locale then joins the key), no AJAX/JSON, URI <= 1 KB, not a member / ajax / ads /
 * captcha / payment / sitemap / robots route, app.edge_cache on.
 * Key = sha1(configured host, scheme of site_url, mobile flag (only when a mobile template is active), locale, URI).
 * File = runtime/edge/d/<2 hex>/<38 hex>.e: "JCE2 <expires> <generation> <ttl> <vod_id> <type> <stored_at>\n" + gzip.
 */
final class EdgeCache
{
    public const STALE_MAX = 86400;       // oldest copy served while shedding
    public const TTL_RSS = 300;
    public const TTL_EMPTY_SEARCH = 60;
    /** XVID-NOLIMIT-BRIEF H5: cursor pages (after= lists, cursor= searches) are stored at most this long. */
    public const TTL_CURSOR = 300;
    /** The newest-videos list of the lighter page (lite()) is rebuilt at most this often; LITE_KEEP: oldest list still shown. */
    public const LITE_TTL = 300;
    public const LITE_KEEP = 604800;
    public const LITE_ITEMS = 24;
    public const EVICT_PASS = 2000;       // deletes per housekeeping pass
    public const ADMISSION_KEEP = 512;    // remembered first misses per key prefix (T3 admission)
    public const MIN_FREE_BYTES = 1073741824; // no new copies when the disk holding runtime/ has less than 1 GB free
    public const ALLOW_COOKIES = '~^(?:_ga.*|_gid|_gat.*|_fbp|__cf.*|cf_.*|_cfuvid)$~';
    public const BLOCK_ROUTES = '~^/(?:index\.php/)?(?:[a-z]{2}(?:-[a-z]{2})?/)?(?:user|ajax|jcx|verify|comment|gbook|payment|label/user|sitemap|robots|api\.php|install|avdbschedule|translateapi|receive|wechat)(?:[/.?-]|$)~i';
    private const TYPES = ['h' => 'text/html; charset=utf-8', 'r' => 'application/rss+xml; charset=UTF-8', 'x' => 'application/xml; charset=UTF-8'];

    private static $stale = null;      // path of an expired copy of this request's key (usable when shedding)
    private static $flight = null;     // single-flight lock handle held while this request renders
    private static $conf = null;
    private static $served = false;
    private static $ranges = null;     // purge ranges, read once per request
    private static $detail = null;     // the video row of this detail / watch page (noteDetail), for the lighter page

    // ----- C5 -----

    public static function bumpGeneration(bool $force = false): void
    {
        $file = self::dir() . 'GEN';
        $age = is_file($file) ? time() - (int)filemtime($file) : PHP_INT_MAX;
        if (!$force && is_file($file) && $age < 10) {
            return;
        }
        ScaleTier::writeAtomic($file, bin2hex(random_bytes(6)));
    }

    public static function purge(array $vodIds): void
    {
        $ids = [];
        foreach ($vodIds as $id) {
            $id = (int)$id;
            if ($id > 0) { $ids[$id] = true; }
        }
        if (!$ids) {
            return;
        }
        $now = time();
        $byBucket = [];
        foreach (array_keys($ids) as $id) {
            $dir = self::dir() . 'p/' . sprintf('%02x', $id % 256) . '/';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            @file_put_contents($dir . $id, (string)$now);
            @touch($dir . $id, $now);
            $byBucket[self::vodBucket($id)][$id] = true;
        }
        // delete the known copies through the per-video key index
        foreach ($byBucket as $bucket => $set) {
            $file = self::dir() . 'v/' . $bucket . '.idx';
            $h = @fopen($file, 'c+');
            if (!$h) { continue; }
            if (ScaleTier::lock($h, LOCK_EX, 200) !== false) {
                $keep = '';
                $raw = stream_get_contents($h);
                foreach (explode("\n", (string)$raw) as $line) {
                    if ($line === '') { continue; }
                    $parts = explode(' ', $line, 2);
                    if (isset($set[(int)$parts[0]]) && isset($parts[1]) && preg_match('/^[0-9a-f]{40}$/', $parts[1])) {
                        self::unlinkCopy(self::path($parts[1]));
                    } else {
                        $keep .= $line . "\n";
                    }
                }
                ftruncate($h, 0);
                rewind($h);
                fwrite($h, $keep);
                fflush($h);
                @flock($h, LOCK_UN);
            }
            fclose($h);
        }
    }

    public static function purgeRange(int $minId, int $maxId): void
    {
        if ($maxId < $minId) {
            [$minId, $maxId] = [$maxId, $minId];
        }
        if ($maxId < 1) {
            return;
        }
        $minId = max(1, $minId);
        $file = self::dir() . 'ranges.json';
        $h = @fopen($file, 'c+');
        if (!$h) {
            return;
        }
        if (ScaleTier::lock($h, LOCK_EX, 500) === false) {
            fclose($h);
            return;
        }
        $now = time();
        $ranges = json_decode((string)stream_get_contents($h), true);
        $ranges = is_array($ranges) ? $ranges : [];
        $ranges[] = [$minId, $maxId, $now];
        $ranges = self::mergeRanges($ranges, $now);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($ranges));
        fflush($h);
        @flock($h, LOCK_UN);
        fclose($h);
    }

    /** Pure: drop ranges no copy can outlive, merge overlapping/adjacent ones, keep at most 100 (over-purging is safe). */
    public static function mergeRanges(array $ranges, int $now): array
    {
        $out = [];
        foreach ($ranges as $r) {
            if (!is_array($r) || count($r) !== 3) { continue; }
            [$a, $b, $t] = [(int)$r[0], (int)$r[1], (int)$r[2]];
            if ($b < $a || $t < $now - self::STALE_MAX - 3600) { continue; }
            $out[] = [$a, $b, $t];
        }
        usort($out, function ($x, $y) { return $x[0] <=> $y[0]; });
        $merged = [];
        foreach ($out as $r) {
            $n = count($merged);
            if ($n && $r[0] <= $merged[$n - 1][1] + 1) {
                $merged[$n - 1][1] = max($merged[$n - 1][1], $r[1]);
                $merged[$n - 1][2] = max($merged[$n - 1][2], $r[2]);
            } else {
                $merged[] = $r;
            }
        }
        while (count($merged) > 100) {
            $best = 0;
            $gap = PHP_INT_MAX;
            for ($i = 0; $i < count($merged) - 1; $i++) {
                $g = $merged[$i + 1][0] - $merged[$i][1];
                if ($g < $gap) { $gap = $g; $best = $i; }
            }
            $merged[$best] = [$merged[$best][0], $merged[$best + 1][1], max($merged[$best][2], $merged[$best + 1][2])];
            array_splice($merged, $best + 1, 1);
        }
        return $merged;
    }

    // ----- serve (before the framework) -----

    public static function serve(string $root = ''): bool
    {
        if ($root !== '') {
            ScaleTier::reset($root);
        }
        // L12: housekeeping due and no cron seen -> scheduled after the response, or this request falls through. While a
        // heavy maintenance step runs (ScaleTier::heavyActive) the trigger starts nothing (engines-r4 R4-3: the claiming
        // request is answered below like any other, an expired page from its stale copy, instead of a cold render plus a
        // housekeeping slice next to the step); the marker is read by the trigger at most once per claim window, never on
        // the HIT path
        if (self::housekeepingDue()) {
            return false;
        }
        try {
            if (!self::eligible($_SERVER, $_COOKIE, $_GET)) {
                return false;
            }
            $key = self::key($_SERVER, $_COOKIE);
            $path = self::path($key);
            $copy = self::read($path, false);
            if ($copy === null) {
                self::count('m');
                return false;
            }
            if (self::purged($copy)) {
                self::unlinkCopy($path);
                return false;
            }
            $now = time();
            if ($copy['exp'] >= $now && $copy['gen'] === self::gen()) {
                self::emit($path, $copy, 'HIT');
                return true;
            }
            if ($copy['stored'] > $now - self::STALE_MAX) {
                self::$stale = $path;
                // single flight: one request renders this key, the others get the stale copy at once. While a heavy
                // maintenance step runs (index builds, the rank files' full read, the upgrade's first builds:
                // ScaleTier::heavyActive) every request gets the stale copy and the page is rendered again after the step
                // (engines-r3 R3-8: on G23's one core a cold render next to the rank files' copy took 2.4 s)
                if (ScaleTier::heavyActive() || !self::takeFlight($key)) {
                    self::emit($path, $copy, 'STALE');
                    return true;
                }
            }
            self::count('m');
            return false;
        } catch (\Throwable $e) {
            return false; // the cache never breaks a page
        }
    }

    /**
     * L12 check, one stat while not due (runtime/scale/hk.due holds the next due time as its mtime). When due, the
     * housekeeping trigger decides (Housekeeping::edgeTrigger); true = this request must fall through to the framework.
     */
    private static function housekeepingDue(): bool
    {
        try {
            $now = time();
            $hkDue = true;
            $due = ScaleTier::root() . 'runtime/scale/hk.due';
            if (is_file($due)) {
                $m = @filemtime($due);
                if ($m !== false && $m > $now) {
                    $hkDue = false;
                }
            }
            if (!$hkDue) {
                // H4: a background import step is looked at only where the response can be finished first, at most
                // once per 30 s (import.due mtime = last check); otherwise this is the whole cost: one function_exists
                if (!function_exists('fastcgi_finish_request') && !function_exists('litespeed_finish_request')) {
                    return false;
                }
                $imp = ScaleTier::root() . 'runtime/scale/import.due';
                if (is_file($imp)) {
                    $m = @filemtime($imp);
                    if ($m !== false && $now - $m < 30 && $m - $now < 30) {
                        return false;
                    }
                }
            }
            if (!class_exists(Housekeeping::class, false)) {
                require_once __DIR__ . '/Housekeeping.php';
            }
            return Housekeeping::edgeTrigger(ScaleTier::root()) === 'fallthrough';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Release this request's single-flight lock (housekeeping runs after the response and must not hold it). */
    public static function releaseFlight(): void
    {
        if (self::$flight) {
            @flock(self::$flight, LOCK_UN);
            @fclose(self::$flight);
        }
        self::$flight = null;
    }

    public static function eligible(array $s, array $cookies, array $get): bool
    {
        $settings = ScaleTier::settings();
        if (empty($settings['edge_cache'])) {
            return false;
        }
        $m = strtoupper((string)($s['REQUEST_METHOD'] ?? 'GET'));
        if ($m !== 'GET' && $m !== 'HEAD') {
            return false;
        }
        // a host outside the allowlist goes to the framework, which redirects it (L3); it is never served or stored
        if ($settings['host'] !== '' && isset($s['HTTP_HOST']) && !in_array(strtolower((string)$s['HTTP_HOST']), $settings['hosts'], true)) {
            return false;
        }
        foreach ($cookies as $name => $value) {
            $name = (string)$name;
            if (preg_match(self::ALLOW_COOKIES, $name)) {
                continue;
            }
            if ($name === 'avdb_ui_lang' && self::locale($cookies) !== null) {
                continue;
            }
            return false;
        }
        if (!empty($s['HTTP_X_REQUESTED_WITH']) || stripos((string)($s['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
            return false;
        }
        // HO-T-1: the language switcher's request (ui_lang_set=1) sets the avdb_ui_lang cookie: never served, never stored
        if (isset($get['ui_lang_set'])) {
            return false;
        }
        $uri = (string)($s['REQUEST_URI'] ?? '');
        if ($uri === '' || $uri[0] !== '/' || strlen($uri) > 1024 || preg_match(self::BLOCK_ROUTES, $uri)) {
            return false;
        }
        return true;
    }

    /** Eligibility of the current request (framework side). */
    public static function eligibleNow(): bool
    {
        try {
            return self::eligible($_SERVER, $_COOKIE, $_GET);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function key(array $s, array $cookies = []): string
    {
        $settings = ScaleTier::settings();
        $host = ScaleTier::keyHost($s);
        $scheme = $settings['scheme'] !== '' ? $settings['scheme']
            : ((!empty($s['HTTPS']) && strtolower((string)$s['HTTPS']) !== 'off') ? 'https' : 'http');
        $mobile = !empty($settings['mob']) && preg_match('/(nokia|sony|ericsson|mot|samsung|sgh|lg|philips|panasonic|alcatel|lenovo|meizu|cldc|midp|iphone|wap|mobile|android)/i', (string)($s['HTTP_USER_AGENT'] ?? '')) ? 1 : 0;
        $locale = (string)self::locale($cookies);
        return sha1($host . '|' . $scheme . '|' . $mobile . '|' . $locale . '|' . (string)($s['REQUEST_URI'] ?? ''));
    }

    /** The avdb_ui_lang cookie when it names an enabled locale other than the default (null = absent or not enabled). */
    private static function locale(array $cookies): ?string
    {
        if (!isset($cookies['avdb_ui_lang']) || !is_string($cookies['avdb_ui_lang'])) {
            return null;
        }
        $v = $cookies['avdb_ui_lang'];
        $conf = self::conf();
        if (!in_array($v, $conf['locales'], true)) {
            return null;
        }
        return $v === $conf['default_locale'] ? '' : $v;
    }

    // ----- finish (after the render, framework side) -----

    /**
     * Called by All::label_fetch after a 200 render (HP-H-2). $meta: tpl, detail (bool: detail/watch page), vod_id (int),
     * self_url (the video's own URL for detail/watch), feed (bool: rss), empty (bool: a search with 0 results).
     * Stores the copy when the URI is canonical; 301 for extra query parameters (exits); otherwise leaves no-store headers.
     */
    public static function finish($html, array $meta = []): string
    {
        try {
            if (!is_string($html) || $html === '' || http_response_code() !== 200 || !self::eligibleNow() || headers_sent()) {
                return 'skip';
            }
            foreach (headers_list() as $h) {
                if (stripos($h, 'Set-Cookie:') === 0) {
                    return 'skip';
                }
            }
            self::refreshConf();
            $feed = !empty($meta['feed']);
            $verdict = self::canonical($html, (string)($_SERVER['REQUEST_URI'] ?? ''), (string)($meta['self_url'] ?? ''), $feed, (string)self::locale($_COOKIE));
            if (strpos($verdict, 'redirect:') === 0) {
                $to = substr($verdict, 9);
                header('Location: ' . $to, true, 301);
                header('Cache-Control: public, max-age=3600');
                header('X-Edge-Cache: CANONICAL');
                http_response_code(301);
                exit;
            }
            if ($verdict !== 'store') {
                return 'skip';
            }
            // flood-r9 R9-1a (orchestrator O-20): a page rendered while a landing's facts were cut (the request's database
            // deadline, or a late read's lower bound that does not make the page indexable) carries noindex only for lack of
            // time. It is never stored: a visitor's (or an unverified bot's) copy would reach a verified crawler as a HIT for
            // edge_ttl. This visitor keeps the no-store headers of mac_no_cahche() (cdnHeaders() is not called)
            if (self::renderCut()) {
                return 'skip';
            }
            $settings = ScaleTier::settings();
            $ttl = $feed ? self::TTL_RSS : (!empty($meta['empty']) ? self::TTL_EMPTY_SEARCH : (int)$settings['edge_ttl']);
            $ttl = self::cursorTtl((string)($_SERVER['REQUEST_URI'] ?? ''), $ttl);
            if ($ttl <= 0) {
                return 'skip';
            }
            $tier = ScaleTier::get();
            self::cdnHeaders($ttl);
            if (!empty($meta['detail']) && (int)$tier['tier'] === 3 && !self::admitted(self::key($_SERVER, $_COOKIE), $ttl)) {
                return 'admission';
            }
            $type = $feed ? 'r' : (stripos((string)($meta['type'] ?? ''), 'xml') !== false ? 'x' : 'h');
            return self::write($html, $ttl, (int)($meta['vod_id'] ?? 0), $type) ? 'stored' : 'full';
        } catch (\Throwable $e) {
            return 'skip';
        }
    }

    /**
     * flood-r9 R9-1a (orchestrator O-20): true when this request rendered a landing (tag / actor page) whose facts were cut
     * short: the request's database deadline ended the reads, or a late read left a lower bound (or nothing) that does not make
     * the page indexable (TemplateSeo::landingCutSeen(), lane S; head() sends a verified crawler of such a landing to the
     * time-limit fallback, visitors get the noindex page). Such a page is answered to this request with the no-store headers
     * and is never stored: not as the edge copy (finish()) and not in the page cache (All::label_fetch(), HP-H-9), so no later
     * request, a verified crawler included, gets it from a cache. False when this request never loaded TemplateSeo (no landing
     * was described) or TemplateSeo has no landingCutSeen() (an older lane S file: nothing to read).
     */
    public static function renderCut(): bool
    {
        try {
            return class_exists(TemplateSeo::class, false) && method_exists(TemplateSeo::class, 'landingCutSeen')
                && TemplateSeo::landingCutSeen();
        } catch (\Throwable $e) {
            return true; // cannot tell: not stored (a skipped store costs one render, a stored cut page costs indexability)
        }
    }

    /**
     * H5 (XVID-NOLIMIT-BRIEF B3 / C3): TTL of a page by its URI. after=<vod_time>.<vod_id> (^[0-9]{1,10}\.[0-9]{1,10}$) and
     * cursor=<doc id> (^[1-9][0-9]{0,19}$) pages get min($ttl, TTL_CURSOR); a cursor parameter of any other shape, given
     * twice or as an array gives 0 (never stored). Pages without one keep $ttl.
     */
    public static function cursorTtl(string $uri, int $ttl): int
    {
        $q = (string)parse_url('http://x' . (strpos($uri, '/') === 0 ? $uri : '/' . $uri), PHP_URL_QUERY);
        if ($q === '' || (stripos($q, 'after') === false && stripos($q, 'cursor') === false)) {
            return $ttl;
        }
        $seen = ['after' => 0, 'cursor' => 0];
        foreach (explode('&', $q) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $k = strtolower(rawurldecode($k));
            $base = preg_replace('/\[.*$/', '', $k);
            if (!isset($seen[$base])) {
                continue;
            }
            $v = rawurldecode($v);
            if ($k !== $base || ++$seen[$base] > 1) {
                return 0;
            }
            if (!preg_match($base === 'after' ? '/^[0-9]{1,10}\.[0-9]{1,10}$/D' : '/^[1-9][0-9]{0,19}$/D', $v)) {
                return 0;
            }
        }
        return $seen['after'] + $seen['cursor'] > 0 ? min($ttl, self::TTL_CURSOR) : $ttl;
    }

    /**
     * 'store' | 'redirect:<path?query>' | 'skip'. The request is canonical when its path and query equal the page's
     * rel=canonical (host ignored: L3 handles hosts) or, for detail/watch pages, the video's own URL with no query.
     * Feeds (no canonical link) are canonical without a query string.
     */
    public static function canonical(string $html, string $uri, string $selfUrl = '', bool $feed = false, string $cookieLocale = ''): string
    {
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        $dPath = rawurldecode($path);
        if ($feed) {
            return $query === '' ? 'store' : 'redirect:' . $path;
        }
        $canonPath = null;
        $canonQuery = '';
        $head = substr($html, 0, 65536);
        if (preg_match('~<link\b[^>]*\brel=["\']?canonical["\']?[^>]*>~i', $head, $m)
            && preg_match('~\bhref=["\']([^"\']+)["\']~i', $m[0], $hm)) {
            $href = html_entity_decode($hm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parts = parse_url($href);
            if (is_array($parts)) {
                $canonPath = isset($parts['path']) ? $parts['path'] : '/';
                $canonQuery = isset($parts['query']) ? $parts['query'] : '';
            }
        }
        $selfPath = '';
        if ($selfUrl !== '') {
            $sp = parse_url($selfUrl);
            $selfPath = is_array($sp) && isset($sp['path']) ? $sp['path'] : '';
        }
        if ($canonPath !== null && rawurldecode($canonPath) === $dPath && self::sameQuery($canonQuery, $query)) {
            return 'store';
        }
        // A language-cookie visitor (pr-R9): the page names ?ui_lang=<cookie locale> as canonical; the bare URL is the same
        // page for that cookie (the locale is part of the key), so it is stored under the bare URI.
        if ($cookieLocale !== '' && $canonPath !== null && rawurldecode($canonPath) === $dPath
            && self::sameQuery(self::dropParam($canonQuery, 'ui_lang', $cookieLocale), $query)) {
            return 'store';
        }
        if ($selfPath !== '' && rawurldecode($selfPath) === $dPath && $query === '') {
            return 'store';
        }
        if ($query !== '') {
            if ($canonPath !== null && rawurldecode($canonPath) === $dPath) {
                return 'redirect:' . $path . ($canonQuery !== '' ? '?' . $canonQuery : '');
            }
            if ($selfPath !== '' && rawurldecode($selfPath) === $dPath) {
                return 'redirect:' . $path;
            }
        }
        return 'skip';
    }

    private static function dropParam(string $query, string $name, string $value): string
    {
        $keep = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            if ($pair !== $name . '=' . $value) { $keep[] = $pair; }
        }
        return implode('&', $keep);
    }

    private static function sameQuery(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $pa = [];
        $pb = [];
        foreach ($a === '' ? [] : explode('&', $a) as $pair) { $pa[] = rawurldecode(str_replace('+', ' ', $pair)); }
        foreach ($b === '' ? [] : explode('&', $b) as $pair) { $pb[] = rawurldecode(str_replace('+', ' ', $pair)); }
        // live hotfix 2026-10-19: the order of the parameters does not matter (clients such as GPTBot sort them; a 301 to the same
        // URL in another order looped)
        sort($pa, SORT_STRING);
        sort($pb, SORT_STRING);
        return $pa === $pb;
    }

    /** CDN headers for an eligible page (L2): replaces the no-store set of mac_no_cahche(). */
    public static function cdnHeaders(int $ttl): void
    {
        if (headers_sent()) {
            return;
        }
        foreach (['Pragma', 'Expires', 'CDN-Cache-Control', 'Cloudflare-CDN-Cache-Control', 'Surrogate-Control', 'X-Accel-Expires', 'Last-Modified'] as $h) {
            header_remove($h);
        }
        $s = ScaleTier::settings();
        $sMax = !empty($s['mob_differs']) ? 0 : max(0, $ttl);
        header('Cache-Control: public, max-age=0, s-maxage=' . $sMax, true);
        header('Vary: Accept-Encoding', true);
        header('X-Edge-Cache: MISS', true);
    }

    // ----- shed -----

    /**
     * Stale copy (never purged, at most 24 h old); else the lighter page with HTTP 200 after a statement timeout, for a
     * verified search-engine crawler (owner decision 2026-10-17) or for a light page (detail, watch, home, shallow list)
     * that found no render slot or token within its wait (verify r3 O-F1c: on a full machine a visitor gets the video's
     * title and player, never a bare busy page); else the busy page (load shedding: heavy pages, budget and in-flight
     * refusals). Exits.
     */
    public static function shed(): void
    {
        self::cleanBuffers();
        if (self::$stale !== null) {
            $copy = self::read(self::$stale, false);
            if ($copy !== null && !self::purged($copy) && $copy['stored'] > time() - self::STALE_MAX) {
                self::emit(self::$stale, $copy, 'STALE');
                exit;
            }
        }
        if (!class_exists(RequestGate::class, false)) {
            require_once __DIR__ . '/RequestGate.php';
        }
        $timeout = class_exists(DbTimeout::class, false) && DbTimeout::seen();
        if ($timeout || RequestGate::crawler() !== '' || RequestGate::liteOnShed()) {
            self::lite();
            exit;
        }
        RequestGate::busy();
        exit;
    }

    /** Drop partial template output (a query can fail inside a template include, under ThinkPHP's output buffer). */
    private static function cleanBuffers(): void
    {
        if (PHP_SAPI === 'cli') {
            return; // tests capture output with their own buffer
        }
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                break;
            }
        }
    }

    /**
     * The video row of the current detail / watch page (mac_label_vod_detail, after a successful read): when a later
     * statement of the render runs out of time, lite() shows this video's title, poster and player instead of only the
     * newest videos (owner decision 2026-10-17, verify r2 R2-10: title + player, no related blocks). $param: the page's
     * sid / nid (watch pages).
     */
    public static function noteDetail(array $info, array $param = []): void
    {
        if (empty($info['vod_id'])) {
            return;
        }
        self::$detail = ['info' => $info, 'sid' => (int)($param['sid'] ?? 0), 'nid' => (int)($param['nid'] ?? 0)];
    }

    /**
     * The lighter page (HTTP 200, no-store, Retry-After): the site name, a short note ("this page needs more time than
     * usual"), a link that tries the same address again, links home and the newest videos; on a detail / watch page whose
     * video row was read (noteDetail) also that video's title, poster and player (verify r2 R2-10). Visitors get
     * X-Robots-Tag noindex; a verified crawler does not (owner decision: a crawler always gets a 200 answer, and a noindex
     * there could drop the page). Works before the framework too (then the list comes only from the cached file).
     */
    public static function lite(): void
    {
        if (!class_exists(RequestGate::class, false)) {
            require_once __DIR__ . '/RequestGate.php';
        }
        $crawler = RequestGate::crawler() !== '';
        $video = self::liteDetail();
        $items = self::liteItems();
        $site = '';
        if (isset($GLOBALS['config']['site']['site_name']) && is_string($GLOBALS['config']['site']['site_name'])) {
            $site = trim(strip_tags($GLOBALS['config']['site']['site_name']));
        }
        if ($site === '') {
            $site = (string)(ScaleTier::settings()['host'] ?? '');
        }
        $self = (string)($_SERVER['REQUEST_URI'] ?? '/');
        if ($self === '' || $self[0] !== '/' || (isset($self[1]) && ($self[1] === '/' || $self[1] === '\\')) || strlen($self) > 1024) {
            $self = '/';
        }
        $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
        $note = RequestGate::phrase('guard.lite', 'This page needs more time than usual right now. Here are the newest videos; please try again in a moment.');
        $retry = RequestGate::phrase('guard.retry', 'Try again');
        $home = RequestGate::phrase('guard.home', 'Home');
        $lang = RequestGate::locale();
        $list = '';
        foreach ($items as $it) {
            if (!is_array($it) || !isset($it['u'], $it['t'])) {
                continue;
            }
            $img = isset($it['p']) && is_string($it['p']) && preg_match('~^(?:https?://|/)~i', $it['p']) ? '<img src="' . $h($it['p']) . '" alt="" loading="lazy" width="160" height="90">' : '';
            $list .= '<li><a href="' . $h($it['u']) . '">' . $img . '<span>' . $h($it['t']) . '</span></a></li>';
        }
        $main = '';
        if ($video !== null) {
            $media = '';
            if ($video['kind'] === 'iframe') {
                // the provider's player page, a plain iframe like the themes use (no sandbox, no referrer policy override)
                $media = '<div class="p"><iframe src="' . $h($video['url']) . '" allowfullscreen allow="autoplay; fullscreen; picture-in-picture; encrypted-media" frameborder="0" scrolling="no"></iframe></div>';
            } elseif ($video['kind'] === 'video') {
                $media = '<div class="p"><video controls playsinline preload="none"' . ($video['pic'] !== '' ? ' poster="' . $h($video['pic']) . '"' : '') . ' src="' . $h($video['url']) . '"></video></div>';
            } elseif ($video['pic'] !== '') {
                $media = '<div class="p"><img src="' . $h($video['pic']) . '" alt=""></div>';
            }
            $main = '<h1 dir="auto">' . $h($video['title']) . '</h1>' . $media;
        }
        if (!headers_sent()) {
            http_response_code(200);
            header_remove('Set-Cookie');
            header('Content-Type: text/html; charset=utf-8', true);
            header('Cache-Control: no-store', true);
            header('X-Edge-Cache: LITE', true);
            // owner decision 2026-10-17 / verify r2 R2-10: the full page can be asked for again shortly
            header('Retry-After: ' . random_int(20, 40), true);
            if (!$crawler) {
                header('X-Robots-Tag: noindex', true);
            }
        }
        self::$served = true;
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            return;
        }
        echo '<!doctype html><html lang="' . $h($lang) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">' . ($crawler ? '' : '<meta name="robots" content="noindex">')
            . '<title>' . $h($video !== null ? $video['title'] . ' - ' . $site : $site) . '</title><style>body{font:16px/1.5 system-ui,sans-serif;margin:0 auto;max-width:72em;padding:1em;color:#222;background:#fff}'
            . 'header a{font-weight:700;font-size:1.3em;color:inherit;text-decoration:none}ul{list-style:none;padding:0;display:grid;'
            . 'grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1em}li a{color:inherit;text-decoration:none}img{display:block;'
            . 'width:100%;height:auto;aspect-ratio:16/9;object-fit:cover;background:#ddd}li span{display:block;font-size:.9em}'
            . 'h1{font-size:1.4em;margin:.6em 0}.p{position:relative;width:100%;max-width:960px;aspect-ratio:16/9;background:#000}'
            . '.p iframe,.p video,.p img{position:absolute;inset:0;width:100%;height:100%;border:0}'
            . '@media (prefers-color-scheme:dark){body{background:#111;color:#eee}}</style></head><body>'
            . '<header><a href="/">' . $h($site) . '</a></header>' . $main . '<p dir="auto">' . $h($note) . '</p>'
            . '<p><a href="' . $h($self) . '">' . $h($retry) . '</a> &middot; <a href="/">' . $h($home) . '</a></p>'
            . ($list !== '' ? '<ul>' . $list . '</ul>' : '') . '</body></html>';
    }

    /**
     * Title, poster and player of the noted video row (noteDetail) for lite(): ['title', 'pic', 'url', 'kind' =>
     * 'iframe' | 'video' | ''] or null. The episode is the page's sid / nid when the row has it, else the first one; only
     * an http(s) address of a player without a parse step is shown (the rule of the video sitemap's player_loc): media
     * files (.m3u8 / .mp4 / .webm) in a <video> element, provider player pages in an iframe. No query: the row is in memory.
     */
    private static function liteDetail(): ?array
    {
        if (self::$detail === null) {
            return null;
        }
        try {
            $info = self::$detail['info'];
            $title = trim(strip_tags((string)($info['vod_name'] ?? '')));
            if ($title === '') {
                return null;
            }
            $title = function_exists('mb_substr') ? mb_substr($title, 0, 200, 'UTF-8') : substr($title, 0, 200);
            $pic = trim((string)($info['vod_pic'] ?? ''));
            if ($pic !== '' && function_exists('mac_url_img')) {
                $pic = (string)mac_url_img($pic);
            }
            if (!preg_match('~^(?:https?://|/)[^\s"\'<>]*$~i', $pic)) {
                $pic = '';
            }
            $out = ['title' => $title, 'pic' => $pic, 'url' => '', 'kind' => ''];
            $list = isset($info['vod_play_list']) && is_array($info['vod_play_list']) ? $info['vod_play_list'] : [];
            if (!$list) {
                return $out;
            }
            $sid = isset($list[self::$detail['sid']]) ? self::$detail['sid'] : (int)array_key_first($list);
            $urls = isset($list[$sid]['urls']) && is_array($list[$sid]['urls']) ? $list[$sid]['urls'] : [];
            if (!$urls) {
                return $out;
            }
            $nid = isset($urls[self::$detail['nid']]) ? self::$detail['nid'] : (int)array_key_first($urls);
            $url = trim((string)($urls[$nid]['url'] ?? ''));
            $from = (string)($urls[$nid]['from'] ?? ($list[$sid]['from'] ?? ''));
            $players = function_exists('config') ? config('vodplayer') : null;
            $player = is_array($players) && isset($players[$from]) && is_array($players[$from]) ? $players[$from] : null;
            if ($player !== null && (string)($player['ps'] ?? '0') === '1') {
                return $out; // a parse-step player needs the theme's player script
            }
            if (!preg_match('~^https?://[^\s"\'<>]+$~i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                return $out;
            }
            $path = strtolower((string)parse_url($url, PHP_URL_PATH));
            $out['url'] = $url;
            $out['kind'] = preg_match('~\.(?:m3u8|mp4|webm)$~', $path) ? 'video' : 'iframe';
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The newest published videos for lite(): runtime/scale/lite.json, [{u: URL, t: title, p: picture}], rebuilt inside the
     * framework when older than LITE_TTL (one primary-key read of LITE_ITEMS rows on the front-end connection, so the
     * statement limit still applies; single flight: a busy lock or any error keeps the old list, shown up to LITE_KEEP).
     */
    private static function liteItems(): array
    {
        $file = ScaleTier::runtime('scale') . 'lite.json';
        $data = ScaleTier::readJson($file);
        $age = is_array($data) ? time() - (int)($data['at'] ?? 0) : PHP_INT_MAX;
        $items = is_array($data) && is_array($data['items'] ?? null) && $age < self::LITE_KEEP ? $data['items'] : [];
        if ($age < self::LITE_TTL || !class_exists('\think\Db', false) || !function_exists('mac_url_vod_detail') || !defined('ENTRANCE') || ENTRANCE !== 'index') {
            return $items;
        }
        $lock = @fopen(ScaleTier::runtime('scale') . 'lite.lock', 'c');
        if (!$lock) {
            return $items;
        }
        try {
            if (!@flock($lock, LOCK_EX | LOCK_NB)) {
                return $items;
            }
            $rows = \think\Db::name('vod')->where('vod_status', 1)->where('vod_recycle_time', 0)->order('vod_id desc')->limit(self::LITE_ITEMS)->select();
            $fresh = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row) && is_object($row) && method_exists($row, 'toArray')) {
                    $row = $row->toArray();
                }
                if (!is_array($row) || empty($row['vod_id'])) {
                    continue;
                }
                $url = (string)mac_url_vod_detail($row);
                $pic = function_exists('mac_url_img') ? (string)mac_url_img((string)($row['vod_pic'] ?? '')) : '';
                if ($url === '') {
                    continue;
                }
                $fresh[] = ['u' => $url, 't' => mb_substr(trim(strip_tags((string)($row['vod_name'] ?? ''))), 0, 200), 'p' => $pic];
            }
            ScaleTier::writeAtomic($file, json_encode(['at' => time(), 'items' => $fresh], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            @flock($lock, LOCK_UN);
            return $fresh;
        } catch (\Throwable $e) {
            return $items;
        } finally {
            @fclose($lock);
        }
    }

    /** True when this request found an expired copy of its own page (light over-budget answers with it). */
    public static function hasStale(): bool
    {
        return self::$stale !== null;
    }

    /** Serve the stale copy if there is one (returns false when there is none). Does not exit. */
    public static function emitStale(): bool
    {
        if (self::$stale === null) {
            return false;
        }
        $copy = self::read(self::$stale, false);
        if ($copy === null || self::purged($copy) || $copy['stored'] <= time() - self::STALE_MAX) {
            return false;
        }
        self::emit(self::$stale, $copy, 'STALE');
        return true;
    }

    // ----- housekeeping and Speed page -----

    /**
     * One eviction pass (<= $maxDeletes deletes): copies no request can use any more (older than 24 h), old purge markers
     * and admission files, then oldest-first until both caps have 20 % headroom. Rewrites the exact size counter.
     */
    public static function evict(int $maxDeletes = self::EVICT_PASS, ?int $now = null): array
    {
        $now = $now ?? time();
        $tier = ScaleTier::get();
        $capFiles = (int)$tier['edge_max_files'];
        $capBytes = (int)$tier['edge_max_mb'] * 1048576;
        $files = [];
        $bytes = 0;
        $deleted = 0;
        $base = self::dir() . 'd/';
        foreach (glob($base . '*', GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (scandir($sub) ?: [] as $f) {
                if ($f === '.' || $f === '..') { continue; }
                $p = $sub . '/' . $f;
                if (substr($f, -4) === '.tmp') {
                    if ((int)@filemtime($p) < $now - 600) { @unlink($p); }
                    continue;
                }
                $st = @stat($p);
                if (!$st) { continue; }
                if ($st['mtime'] < $now - self::STALE_MAX && $deleted < $maxDeletes) {
                    @unlink($p);
                    $deleted++;
                    continue;
                }
                $files[] = [$st['mtime'], $st['size'], $p];
                $bytes += $st['size'];
            }
        }
        $count = count($files);
        if (($count > 0.9 * $capFiles || $bytes > 0.9 * $capBytes) && $deleted < $maxDeletes) {
            usort($files, function ($a, $b) { return $a[0] <=> $b[0]; });
            foreach ($files as $i => $f) {
                if (($count <= 0.8 * $capFiles && $bytes <= 0.8 * $capBytes) || $deleted >= $maxDeletes) { break; }
                @unlink($f[2]);
                $count--;
                $bytes -= $f[1];
                $deleted++;
            }
        }
        // purge markers no copy can outlive
        foreach (glob(self::dir() . 'p/*', GLOB_ONLYDIR) ?: [] as $sub) {
            foreach (scandir($sub) ?: [] as $f) {
                if ($f !== '.' && $f !== '..' && (int)@filemtime($sub . '/' . $f) < $now - self::STALE_MAX - 3600) { @unlink($sub . '/' . $f); }
            }
        }
        // hourly counters older than 2 days
        foreach (glob(self::dir() . 'n/*.txt') ?: [] as $f) {
            if ((int)@filemtime($f) < $now - 172800) { @unlink($f); }
        }
        ScaleTier::writeAtomic(self::dir() . 'STATS', $count . ' ' . $bytes);
        return ['files' => $count, 'bytes' => $bytes, 'deleted' => $deleted, 'cap_files' => $capFiles, 'cap_bytes' => $capBytes];
    }

    /** Size of runtime/edge against its caps, hit counts of the current and previous hour (sampled x8). */
    public static function stats(): array
    {
        [$files, $bytes] = self::readStats();
        $tier = ScaleTier::get();
        $hits = [];
        foreach ([0, 1] as $back) {
            $f = self::dir() . 'n/' . date('YmdH', time() - $back * 3600) . '.txt';
            $raw = is_file($f) ? (string)file_get_contents($f) : '';
            $hits[$back ? 'previous_hour' : 'this_hour'] = ['hit' => substr_count($raw, 'h') * 8, 'stale' => substr_count($raw, 's') * 8, 'miss' => substr_count($raw, 'm') * 8];
        }
        $s = ScaleTier::settings();
        $h = $hits['this_hour']['hit'] + $hits['this_hour']['stale'] + $hits['previous_hour']['hit'] + $hits['previous_hour']['stale'];
        $all = $h + $hits['this_hour']['miss'] + $hits['previous_hour']['miss'];
        // shape asked by lane E (HO-E-6): enabled, hit_rate, mb, files, max_mb, max_files (+ details)
        return ['enabled' => !empty($s['edge_cache']), 'hit_rate' => $all > 0 ? round($h / $all, 3) : null, 'mb' => round($bytes / 1048576, 1),
            'files' => $files, 'max_mb' => (int)$tier['edge_max_mb'], 'max_files' => (int)$tier['edge_max_files'],
            'bytes' => $bytes, 'cap_files' => (int)$tier['edge_max_files'], 'cap_bytes' => (int)$tier['edge_max_mb'] * 1048576,
            'dir' => self::dir(), 'hits' => $hits, 'free_bytes' => (int)@disk_free_space(self::dir())];
    }

    // ----- internals -----

    private static function dir(): string
    {
        return ScaleTier::runtime('edge');
    }

    private static function path(string $key): string
    {
        return self::dir() . 'd/' . substr($key, 0, 2) . '/' . substr($key, 2) . '.e';
    }

    private static function vodBucket(int $id): string
    {
        return sprintf('%02x', $id % 256);
    }

    private static function gen(): string
    {
        $g = is_file(self::dir() . 'GEN') ? @file_get_contents(self::dir() . 'GEN') : false;
        return is_string($g) && preg_match('/^[0-9a-f]{1,32}$/', trim($g)) ? trim($g) : '0';
    }

    /** Header of a copy (and the body when $body). null when missing or malformed. */
    private static function read(string $path, bool $body)
    {
        $h = @fopen($path, 'rb');
        if (!$h) {
            return null;
        }
        $head = fgets($h, 256);
        if (!is_string($head) || !preg_match('/^JCE2 (\d+) ([0-9a-f]{1,32}) (\d+) (\d+) ([hrx]) (\d+)\n$/', $head, $m)) {
            fclose($h);
            return null;
        }
        $copy = ['exp' => (int)$m[1], 'gen' => $m[2], 'ttl' => (int)$m[3], 'vod' => (int)$m[4], 'type' => $m[5], 'stored' => (int)$m[6],
            'offset' => strlen($head)];
        if ($body) {
            $copy['body'] = (string)stream_get_contents($h);
        }
        fclose($h);
        return $copy;
    }

    private static function purged(array $copy): bool
    {
        $id = (int)$copy['vod'];
        if ($id < 1) {
            return false;
        }
        $marker = self::dir() . 'p/' . sprintf('%02x', $id % 256) . '/' . $id;
        $t = is_file($marker) ? filemtime($marker) : false;
        if ($t !== false && $t >= $copy['stored']) {
            return true;
        }
        if (self::$ranges === null) {
            $r = is_file(self::dir() . 'ranges.json') ? json_decode((string)file_get_contents(self::dir() . 'ranges.json'), true) : null;
            self::$ranges = is_array($r) ? $r : [];
        }
        foreach (self::$ranges as $r) {
            if ($id >= (int)$r[0] && $id <= (int)$r[1] && (int)$r[2] >= $copy['stored']) {
                return true;
            }
        }
        return false;
    }

    private static function emit(string $path, array $copy, string $state): void
    {
        $full = self::read($path, true);
        if ($full === null) {
            return;
        }
        $gz = $full['body'];
        $accepts = stripos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false && !ini_get('zlib.output_compression');
        $body = $accepts ? $gz : (string)@gzdecode($gz);
        $left = max(0, $copy['exp'] - time());
        $s = ScaleTier::settings();
        if (!headers_sent()) {
            header_remove('Set-Cookie');
            header('Content-Type: ' . (self::TYPES[$copy['type']] ?? self::TYPES['h']), true);
            header('Cache-Control: public, max-age=0, s-maxage=' . (!empty($s['mob_differs']) || $state !== 'HIT' ? 0 : $left), true);
            header('Vary: Accept-Encoding', true);
            header('X-Edge-Cache: ' . $state, true);
            if ($copy['type'] === 'r') {
                header('X-Robots-Tag: noindex, follow', true);
            }
            if ($accepts) {
                header('Content-Encoding: gzip', true);
            }
            header('Content-Length: ' . strlen($body), true);
        }
        self::$served = true;
        self::count($state === 'HIT' ? 'h' : 's');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo $body;
        }
    }

    private static function write(string $html, int $ttl, int $vodId, string $type): bool
    {
        $key = self::key($_SERVER, $_COOKIE);
        $path = self::path($key);
        $tier = ScaleTier::get();
        $old = is_file($path) ? filesize($path) : false;
        [$files, $bytes] = self::readStats();
        $capFiles = (int)$tier['edge_max_files'];
        $capBytes = (int)$tier['edge_max_mb'] * 1048576;
        if ($old === false && ($files >= $capFiles || $bytes >= $capBytes)) {
            return false; // at the cap: skip the write; housekeeping evicts oldest-first
        }
        $free = @disk_free_space(self::dir());
        if ($free !== false && $free < self::MIN_FREE_BYTES) {
            return false;
        }
        if (class_exists('\app\common\util\StorageGuard') && method_exists('\app\common\util\StorageGuard', 'status')) {
            // C13 (lane P): a full disk or a storage error stops new cache files. The database-size limit does not: cache
            // copies live on the web disk, not in the database (verify F-1: a 10 GB database over the 1 GB "unknown"
            // limit of a web-cron site stopped every store, edge p95 98 ms all MISS)
            try {
                $reason = (string)(\app\common\util\StorageGuard::status()['reason'] ?? '');
                if ($reason === 'disk' || $reason === 'tripped') { return false; }
            } catch (\Throwable $e) {}
        }
        $gz = gzencode($html, 6);
        if (!is_string($gz)) {
            return false;
        }
        $now = time();
        $data = 'JCE2 ' . ($now + $ttl) . ' ' . self::gen() . ' ' . $ttl . ' ' . max(0, $vodId) . ' ' . $type . ' ' . $now . "\n" . $gz;
        $delta = strlen($data) - ($old === false ? 0 : (int)$old);
        // a new copy reserves its place under the counter's lock (check + count in one step): parallel writers never pass
        // the caps together (step 3: a2 found 501 copies for a 500 cap with the earlier read-then-write)
        if ($old === false && !self::adjustStats(1, $delta, $capFiles, $capBytes)) {
            return false;
        }
        if (!ScaleTier::writeAtomic($path, $data)) {
            if ($old === false) {
                self::adjustStats(-1, -$delta);
            }
            return false;
        }
        if ($old !== false) {
            self::adjustStats(0, $delta);
        }
        if ($vodId > 0) {
            if (!is_dir(self::dir() . 'v')) { @mkdir(self::dir() . 'v', 0755, true); }
            @file_put_contents(self::dir() . 'v/' . self::vodBucket($vodId) . '.idx', $vodId . ' ' . $key . "\n", FILE_APPEND | LOCK_EX);
        }
        return true;
    }

    private static function unlinkCopy(string $path): void
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size !== false && @unlink($path)) {
            self::adjustStats(-1, -(int)$size);
        }
    }

    private static function readStats(): array
    {
        $raw = is_file(self::dir() . 'STATS') ? @file_get_contents(self::dir() . 'STATS') : false;
        if (is_string($raw) && preg_match('/^(-?\d+) (-?\d+)/', $raw, $m)) {
            return [max(0, (int)$m[1]), max(0, (int)$m[2])];
        }
        return [0, 0];
    }

    /**
     * Adds to the copy / byte counter under its lock. With caps (> 0): only when the counter is still below both caps
     * (a reservation); false = at a cap or the counter could not be locked (the write is skipped).
     */
    private static function adjustStats(int $dFiles, int $dBytes, int $capFiles = 0, int $capBytes = 0): bool
    {
        $file = self::dir() . 'STATS';
        $h = @fopen($file, 'c+');
        if (!$h) {
            return $capFiles <= 0;
        }
        $ok = $capFiles <= 0;
        if (ScaleTier::lock($h, LOCK_EX, 50) !== false) {
            $raw = (string)stream_get_contents($h);
            $f = 0;
            $b = 0;
            if (preg_match('/^(-?\d+) (-?\d+)/', $raw, $m)) { $f = (int)$m[1]; $b = (int)$m[2]; }
            if ($capFiles > 0 && (max(0, $f) >= $capFiles || max(0, $b) >= $capBytes)) {
                @flock($h, LOCK_UN);
                fclose($h);
                return false;
            }
            $ok = true;
            $line = max(0, $f + $dFiles) . ' ' . max(0, $b + $dBytes);
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, $line);
            fflush($h);
            @flock($h, LOCK_UN);
        }
        fclose($h);
        return $ok;
    }

    /** T3 admission: true on the second miss of a key within $ttl seconds. */
    private static function admitted(string $key, int $ttl): bool
    {
        $file = self::dir() . 'a/' . substr($key, 0, 2) . '.txt';
        if (!is_dir(dirname($file))) { @mkdir(dirname($file), 0755, true); }
        $h = @fopen($file, 'c+');
        if (!$h) {
            return true;
        }
        $admit = false;
        if (ScaleTier::lock($h, LOCK_EX, 50) !== false) {
            $now = time();
            $short = substr($key, 2, 16);
            $lines = [];
            foreach (explode("\n", (string)stream_get_contents($h)) as $line) {
                if ($line === '') { continue; }
                [$k, $t] = array_pad(explode(' ', $line, 2), 2, '0');
                if ((int)$t < $now - $ttl) { continue; }
                if ($k === $short) { $admit = true; continue; }
                $lines[] = $line;
            }
            if (!$admit) {
                $lines[] = $short . ' ' . $now;
            }
            $lines = array_slice($lines, -self::ADMISSION_KEEP);
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, $lines ? implode("\n", $lines) . "\n" : '');
            fflush($h);
            @flock($h, LOCK_UN);
        }
        fclose($h);
        return $admit;
    }

    private static function takeFlight(string $key): bool
    {
        $dir = self::dir() . 'l/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $h = @fopen($dir . substr($key, 0, 2) . '.lock', 'c');
        if (!$h) {
            return true;
        }
        $r = ScaleTier::lock($h, LOCK_EX, 0);
        if ($r === false) {
            fclose($h);
            return false;
        }
        self::$flight = $h; // released when the request ends
        return true;
    }

    /** Enabled locales for the cookie rule (written from the framework side, read before it). */
    private static function conf(): array
    {
        if (self::$conf === null) {
            $c = ScaleTier::readJson(self::dir() . 'conf.json');
            self::$conf = is_array($c) && isset($c['locales']) && is_array($c['locales'])
                ? ['locales' => array_values(array_filter($c['locales'], 'is_string')), 'default_locale' => (string)($c['default_locale'] ?? 'en'), 'at' => (int)($c['at'] ?? 0)]
                : ['locales' => [], 'default_locale' => 'en', 'at' => 0];
        }
        return self::$conf;
    }

    private static function refreshConf(): void
    {
        $c = self::conf();
        if (time() - $c['at'] < 600 || !class_exists('\app\common\util\AvdbLanguage')) {
            return;
        }
        try {
            $locales = AvdbLanguage::enabled() ? array_values(array_map('strval', array_keys(AvdbLanguage::available()))) : [];
            $default = AvdbLanguage::enabled() ? (string)AvdbLanguage::defaultLocale() : 'en';
        } catch (\Throwable $e) {
            return;
        }
        $new = ['locales' => $locales, 'default_locale' => $default, 'at' => time()];
        ScaleTier::writeAtomic(self::dir() . 'conf.json', json_encode($new));
        self::$conf = $new;
    }

    /** Sampled (1 in 8) hit / stale / miss counters per hour for the Speed page; never blocks. */
    private static function count(string $what): void
    {
        if (mt_rand(1, 8) !== 1) {
            return;
        }
        $dir = self::dir() . 'n/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $f = $dir . date('YmdH') . '.txt';
        if (!is_file($f) || filesize($f) < 1048576) {
            @file_put_contents($f, $what, FILE_APPEND);
        }
    }

    /** Test hook: forget per-request state. */
    public static function reset(): void
    {
        self::$stale = null;
        self::$conf = null;
        self::$served = false;
        self::$ranges = null;
        self::$detail = null;
        if (self::$flight) {
            @flock(self::$flight, LOCK_UN);
            @fclose(self::$flight);
        }
        self::$flight = null;
    }
}
