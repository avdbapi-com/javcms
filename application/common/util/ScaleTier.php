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
 * C3 (owner: lane H). Scale tier and the limits it sets. XVID-SCALE-BRIEF 3.2 C3, "Scale tiers" (2.10), L1, L5, L6,
 * D-L5, D-S3 (WALK_CAP).
 *
 * Tiers: T1 < 200 k videos, T2 200 k - 1 M, T3 >= 1 M. 'rows' comes from scale_count 'vod_all' (exact, written by the
 * counts job and at the end of every import job through refresh()); information_schema.TABLES.TABLE_ROWS only before the
 * first count exists; the admin can pin a tier (pin()). The tier never depends on the provider.
 *
 * get() returns exactly the C3 keys. The values live in a small JSON snapshot (runtime/scale/tier.json) so the
 * pre-framework layers (EdgeCache::serve, RequestGate::preCheck) read them without database or framework. The snapshot
 * also carries the settings those layers need (configured host, budgets, slots, edge caps, Tunnel switch), refreshed from
 * application/extra/maccms.php whenever that file changes. maccms.php overrides (HP-H-4, 0 = from tier):
 * app.edge_max_mb, app.edge_max_files, app.budget_heavy, app.budget_light, app.slots_search, app.slots_deep,
 * app.slots_light, app.stmt_limit, app.edge_cache, app.edge_ttl, app.cf_tunnel; owner decision 2026-10-17 (HP-H-5):
 * app.under_attack (0/1), app.attack_budget_heavy, app.attack_budget_light, app.crawlers (search engines that are never
 * limited: '' = all built-in engines, see PublicRequestGuard::CRAWLERS), app.crawler_slots; verify r3 O-F1c: app.slots_render
 * (renders of all classes at once; 0 = 2 x cores on a T3 machine with <= 2 cores, no cap elsewhere; gate()).
 *
 * Protection defaults (owner decision 2026-10-17 08:40): shedding on, per-visitor budgets on with HIGH values (300 heavy /
 * 2,400 light uncached pages a minute per IPv4 /32 or IPv6 /64), statement limit on; "Under attack" (off by default)
 * lowers the budgets to 30 / 240, halves the heavy slots and caps the statement limit at 3 s. Verified search-engine
 * crawlers are exempt in both modes (RequestGate).
 *
 * This file also holds the small file helpers shared by the lane H classes (EdgeCache, RequestGate, ScaleCounts,
 * ScaleSchema), because those must work before ThinkPHP is loaded.
 */
final class ScaleTier
{
    /**
     * Values from XVID-SCALE-BRIEF 2.9 D-L5, 2.10 L1/L6, D-S3. Page caps (list_cap, by_cap, search_cap) are 0 = none in every
     * tier (XVID-NOLIMIT-BRIEF H1, C3 note: owner decision 2026-10-15, no limit on list, sort or search depth); deep pages
     * stay protected by the budgets (every page > 50, after= and cursor= are heavy), the gates and the statement limit. walk_cap is 150,000 at every buffer pool (verify fix
     * 2026-10-17: 300,000 at a 1.5 GB pool made G1 p95 1.39-1.48 s with 6.3 k cold reads; 150,000 gives 0.74 s).
     * T3 on a machine with <= 2 CPU cores: search 2 slots, deep 1 (G11: 4 search renders on 2 cores pushed the victims'
     * p50 to 0.42 s; 2 slots gave 0.31 s).
     */
    private const TIERS = [
        1 => ['list_cap' => 0, 'by_cap' => 0, 'search_cap' => 0, 'stmt_limit' => 5, 'walk_cap' => 150000,
              'slots' => ['search' => 3, 'deep' => 3, 'light' => 8], 'edge_max_mb' => 256, 'edge_max_files' => 20000],
        2 => ['list_cap' => 0, 'by_cap' => 0, 'search_cap' => 0, 'stmt_limit' => 5, 'walk_cap' => 150000,
              'slots' => ['search' => 4, 'deep' => 2, 'light' => 6], 'edge_max_mb' => 512, 'edge_max_files' => 50000],
        3 => ['list_cap' => 0, 'by_cap' => 0, 'search_cap' => 0, 'stmt_limit' => 3, 'walk_cap' => 150000,
              'slots' => ['search' => 4, 'deep' => 2, 'light' => 6], 'edge_max_mb' => 1024, 'edge_max_files' => 100000],
    ];
    /**
     * Sanity ceiling for page numbers while every cap is 0 (pageCeiling()): no list, sort or search can have more pages than
     * videos, so a page above max(PAGE_CEILING_MIN, 2 x rows) is answered 404 before any query (a page=10^9 URL never
     * reaches an OFFSET). It is not a depth limit: every real page stays reachable (rows is the exact published count,
     * doubled for imports since the last count).
     */
    public const PAGE_CEILING_MIN = 100000;
    public const T2_ROWS = 200000;
    public const T3_ROWS = 1000000;
    public const NOINDEX_FROM = 200000;   // seo.noindex_after defaults to 50 from 200 k videos (L9)
    public const REFRESH_AGE = 86400;     // a snapshot older than this is refreshed lazily (framework context only)
    public const VERSION = 2;            // 2: page caps 0 = none (XVID-NOLIMIT-BRIEF H1); older snapshots are recomputed
    /** Revision of compute() and of the settings keys: part of the settings signature, so snapshots and settings.json written
     * by older code are recomputed (3: high budgets, under-attack mode, crawler list; owner decision 2026-10-17; 4: render
     * tokens, global wait places and the light waiters of render-cap mode, verify r3 O-F1c). */
    public const COMPUTE_REV = 4;
    /**
     * verify r3 O-F1c: render-cap mode (T3 on a machine with <= 2 CPU cores, or app.slots_render > 0 on any machine). All
     * framework renders together (search + deep + light + verified crawler) hold one of 2 x cores render tokens, `cores` of
     * them only light pages may take (visitors' detail / home / list pages first); at most `cores` requests wait for a token
     * or slot at once (light pages on any wait place, a verified crawler from the second, search / deep from the third:
     * never on 2 cores), light pages with CAP_LIGHT_WAITERS waiter places for at most CAP_LIGHT_WAIT_MS (then the stale copy
     * or the lighter page, HTTP 200). Measured: a 64-connection search flood + a verified crawler at 10 pages / s + a fake one on 8
     * fixed workers left 13 crawler requests with 502 and victims at p50 0.86-0.97 s because renders and waiters (search 2+2,
     * deep 1+2, light 6+6 waiting up to 1.5 s, crawler 2+2) exceeded the 8 workers.
     */
    public const CAP_LIGHT_WAITERS = 2;
    public const CAP_LIGHT_WAIT_MS = 300;
    /** Per-visitor budgets per minute (owner decision 2026-10-17: about 10x the brief's 30 / 240). */
    public const BUDGET_HEAVY = 300;
    public const BUDGET_LIGHT = 2400;
    /** "Under attack" budgets (switch app.under_attack, off by default; adjustable app.attack_budget_heavy / _light). */
    public const ATTACK_BUDGET_HEAVY = 30;
    public const ATTACK_BUDGET_LIGHT = 240;
    /** Heavy renders of verified search-engine crawlers at once (their own slot pool; app.crawler_slots). */
    public const CRAWLER_SLOTS = 2;
    /**
     * engines-r3 R3-8: longest life of a heavy-maintenance marker (heavyMark): a marker a killed process left behind ends by
     * itself after this time.
     */
    public const HEAVY_TTL = 3600;

    private static $memo = null;
    private static $settings = null;
    private static $refreshDue = false;
    private static $root = null;
    private static $heavyMemo = null;

    /** C3: the tier and its limits (exact key set of the contract). */
    public static function get(): array
    {
        $s = self::snapshot();
        $o = $s['effective'];
        return [
            'tier' => $o['tier'],
            'rows' => $o['rows'],
            'list_cap' => $o['list_cap'],
            'by_cap' => $o['by_cap'],
            'search_cap' => (int)($o['search_cap'] ?? 0),
            'stmt_limit' => $o['stmt_limit'],
            'walk_cap' => $o['walk_cap'],
            'slots' => $o['slots'],
            'budget' => $o['budget'],
            'edge_max_mb' => $o['edge_max_mb'],
            'edge_max_files' => $o['edge_max_files'],
        ];
    }

    /**
     * Deepest page number a visitor URL may ask for: the tier cap when one is set ($kind 'list' | 'by' | 'search'), else
     * (cap 0 = none, the default) the sanity ceiling max(PAGE_CEILING_MIN, 2 x rows). Pure variant: ceilingFor().
     */
    public static function pageCeiling(string $kind = 'list'): int
    {
        $t = self::get();
        return self::ceilingFor((int)($t[$kind === 'search' ? 'search_cap' : ($kind === 'by' ? 'by_cap' : 'list_cap')] ?? 0), (int)$t['rows']);
    }

    /** Pure: $cap > 0 wins; 0 = none -> max(PAGE_CEILING_MIN, 2 x rows). */
    public static function ceilingFor(int $cap, int $rows): int
    {
        return $cap > 0 ? $cap : max(self::PAGE_CEILING_MIN, 2 * max(0, $rows));
    }

    /** Everything the snapshot knows (for the Speed page and tools): source of the row count, pin, pool, version, noindex. */
    public static function info(): array
    {
        $s = self::snapshot();
        return $s['effective'] + ['source' => $s['source'], 'refreshed_at' => $s['refreshed_at'], 'pin' => $s['pin'],
            'pool_bytes' => $s['pool_bytes'], 'db_version' => $s['db_version']];
    }

    /** Settings needed before the framework (host allowlist, edge switch and TTL, Tunnel switch, mobile template). */
    public static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }
        $root = self::root();
        $file = self::runtime('scale') . 'settings.json';
        $mac = $root . 'application/extra/maccms.php';
        $dom = $root . 'application/extra/domain.php';
        // mtime + size + a content checksum (HO-E-O2: two saves in one second with the same size, e.g. under_attack 1 -> 0,
        // left the old values cached); the files are small (a few KB) and read raw, never through opcache
        $sig = self::fileSig($mac) . ':' . self::fileSig($dom) . ':r' . self::COMPUTE_REV;
        $cached = self::readJson($file);
        if (is_array($cached) && ($cached['sig'] ?? '') === $sig && ($cached['v'] ?? 0) === self::VERSION && isset($cached['cores'])) {
            return self::$settings = $cached;
        }
        $config = self::loadConfigFile($mac);
        $domain = self::loadConfigFile($dom);
        $site = is_array($config['site'] ?? null) ? $config['site'] : [];
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $siteUrl = self::parseHost((string)($site['site_url'] ?? ''));
        $hosts = [];
        foreach (array_merge([(string)($site['site_url'] ?? ''), (string)($site['site_wapurl'] ?? '')], array_keys($domain)) as $h) {
            $p = self::parseHost((string)$h);
            if ($p['host'] === '') {
                continue;
            }
            $hosts[$p['host']] = true;
            $bare = strpos($p['host'], 'www.') === 0 ? substr($p['host'], 4) : $p['host'];
            $hosts[$bare] = true;
            $hosts['www.' . $bare] = true;
        }
        $mobStatus = (int)($site['mob_status'] ?? 0);
        $mobDiffers = $mobStatus !== 0 && (string)($site['mob_template_dir'] ?? '') !== '' && (string)($site['mob_template_dir'] ?? '') !== 'no'
            && (string)($site['mob_template_dir'] ?? '') !== (string)($site['template_dir'] ?? '');
        $int = function ($key, $default) use ($app) {
            return isset($app[$key]) && is_numeric($app[$key]) ? max(0, (int)$app[$key]) : $default;
        };
        $out = [
            'v' => self::VERSION, 'sig' => $sig,
            'host' => $siteUrl['host'], 'scheme' => $siteUrl['scheme'], 'hosts' => array_keys($hosts),
            'mob' => $mobStatus !== 0 ? 1 : 0, 'mob_differs' => $mobDiffers ? 1 : 0,
            'edge_cache' => $int('edge_cache', 1), 'edge_ttl' => max(30, min(86400, $int('edge_ttl', 300) ?: 300)),
            'edge_max_mb' => $int('edge_max_mb', 0), 'edge_max_files' => $int('edge_max_files', 0),
            'budget_heavy' => $int('budget_heavy', self::BUDGET_HEAVY), 'budget_light' => $int('budget_light', self::BUDGET_LIGHT),
            'under_attack' => $int('under_attack', 0) ? 1 : 0,
            'attack_budget_heavy' => $int('attack_budget_heavy', self::ATTACK_BUDGET_HEAVY), 'attack_budget_light' => $int('attack_budget_light', self::ATTACK_BUDGET_LIGHT),
            'crawlers' => self::crawlerList($app['crawlers'] ?? ''), 'crawler_slots' => max(1, min(16, $int('crawler_slots', self::CRAWLER_SLOTS) ?: self::CRAWLER_SLOTS)),
            'slots_search' => $int('slots_search', 0), 'slots_deep' => $int('slots_deep', 0), 'slots_light' => $int('slots_light', 0),
            'slots_render' => $int('slots_render', 0),
            'stmt_limit' => $int('stmt_limit', 0), 'cores' => $int('cpu_cores', 0) ?: self::cpuCores(), 'cf_tunnel' => $int('cf_tunnel', 0) ? 1 : 0,
            'lang' => preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', (string)($app['lang'] ?? '')) ? (string)$app['lang'] : 'en-us',
            'noindex_after' => isset($config['seo']['noindex_after']) && is_numeric($config['seo']['noindex_after']) ? (int)$config['seo']['noindex_after'] : -1,
        ];
        self::writeAtomic($file, json_encode($out, JSON_UNESCAPED_SLASHES));
        self::$memo = null;
        return self::$settings = $out;
    }

    /**
     * C3: re-read the row count (scale_count 'vod_all', information_schema before the first count), the buffer pool and
     * the server version, and store the tier snapshot. Called by P at the end of every import job and by the counts job.
     * Needs a database: the framework connection (think\Db) or the PDO passed in.
     */
    public static function refresh(): void
    {
        self::refreshWith(null, null);
    }

    /** refresh() on an explicit connection (housekeeping, tools). Never throws. */
    public static function refreshWith(?\PDO $pdo, ?string $prefix): void
    {
        try {
            $q = self::queryFn($pdo, $prefix);
            if ($q === null) {
                return;
            }
            [$select, $pre] = $q;
            $rows = null;
            $source = 'none';
            $pin = 0;
            try {
                $r = $select('SELECT k, v FROM ' . $pre . "scale_count WHERE k IN ('vod_all','tier_pin')");
                foreach ($r as $row) {
                    if ($row['k'] === 'vod_all') { $rows = (int)$row['v']; $source = 'scale_count'; }
                    if ($row['k'] === 'tier_pin') { $pin = max(0, min(3, (int)$row['v'])); }
                }
            } catch (\Throwable $e) {
                // table missing: the estimate below
            }
            if ($rows === null) {
                try {
                    $r = $select('SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$pre . 'vod']);
                    if ($r) { $rows = (int)$r[0]['n']; $source = 'information_schema'; }
                } catch (\Throwable $e) {
                }
            }
            $pool = 0;
            $version = '';
            try {
                $r = $select('SELECT @@innodb_buffer_pool_size AS p, VERSION() AS v');
                if ($r) { $pool = (int)$r[0]['p']; $version = (string)$r[0]['v']; }
            } catch (\Throwable $e) {
            }
            if ($version !== '' && class_exists(DbTimeout::class)) {
                DbTimeout::rememberVersion($version);
            }
            self::store((int)($rows ?? 0), $source, $pin, $pool, $version);
        } catch (\Throwable $e) {
            // a refresh never breaks the caller (import end, housekeeping)
        }
    }

    /** Admin pin: 0 = automatic, 1-3 = fixed tier. Stored as scale_count 'tier_pin'. */
    public static function pin(int $tier, ?\PDO $pdo = null, ?string $prefix = null): bool
    {
        $tier = max(0, min(3, $tier));
        $q = self::queryFn($pdo, $prefix, true);
        if ($q === null) {
            return false;
        }
        try {
            $q[2]('REPLACE INTO ' . $q[1] . 'scale_count (k, v, at) VALUES (\'tier_pin\', ?, ?)', [$tier, time()]);
        } catch (\Throwable $e) {
            return false;
        }
        self::refreshWith($pdo, $prefix);
        return true;
    }

    /** Pure: the effective limits for a row count, pin, buffer pool and overrides (0 = from tier). */
    public static function compute(int $rows, int $pin = 0, int $poolBytes = 0, array $settings = []): array
    {
        $tier = $pin >= 1 && $pin <= 3 ? $pin : ($rows >= self::T3_ROWS ? 3 : ($rows >= self::T2_ROWS ? 2 : 1));
        $t = self::TIERS[$tier];
        // a small machine (<= 2 cores: settings 'cores' from cpuCores() or app.cpu_cores) at T3 renders fewer heavy pages at once
        $cores = isset($settings['cores']) ? (int)$settings['cores'] : 0;
        $small = $tier === 3 && $cores >= 1 && $cores <= 2;
        $o = function ($key, $fallback) use ($settings) {
            return isset($settings[$key]) && (int)$settings[$key] > 0 ? (int)$settings[$key] : $fallback;
        };
        // "Under attack" (owner switch, off by default): the brief's strict budgets, half the heavy slots, statement limit <= 3 s
        $attack = !empty($settings['under_attack']);
        $search = max(1, min(64, $o('slots_search', $small ? 2 : $t['slots']['search'])));
        $deep = max(1, min(64, $o('slots_deep', $small ? 1 : $t['slots']['deep'])));
        $stmt = max(1, min(600, $o('stmt_limit', $t['stmt_limit'])));
        $budget = ['heavy' => max(1, $o('budget_heavy', self::BUDGET_HEAVY)), 'light' => max(1, $o('budget_light', self::BUDGET_LIGHT))];
        if ($attack) {
            $search = max(1, intdiv($search, 2));
            $deep = max(1, intdiv($deep, 2));
            $stmt = min($stmt, 3);
            $budget = ['heavy' => min($budget['heavy'], max(1, $o('attack_budget_heavy', self::ATTACK_BUDGET_HEAVY))),
                'light' => min($budget['light'], max(1, $o('attack_budget_light', self::ATTACK_BUDGET_LIGHT)))];
        }
        $noindex = isset($settings['noindex_after']) && (int)$settings['noindex_after'] >= 0 ? (int)$settings['noindex_after']
            : ($rows >= self::NOINDEX_FROM ? 50 : 0);
        // verify r3 O-F1c: render-cap mode on a small T3 machine (or app.slots_render): 2 x cores render tokens for all
        // classes together, `cores` of them for light pages only, `cores` global wait places, 2 light waiters for 0.3 s
        $capCores = max(1, $cores);
        $render = isset($settings['slots_render']) && (int)$settings['slots_render'] > 0 ? max(2, min(256, (int)$settings['slots_render']))
            : ($small ? 2 * $capCores : 0);
        return [
            'tier' => $tier,
            'rows' => max(0, $rows),
            'list_cap' => $t['list_cap'],
            'by_cap' => $t['by_cap'],
            'search_cap' => $t['search_cap'],
            'stmt_limit' => $stmt,
            'walk_cap' => $t['walk_cap'],
            'slots' => [
                'search' => $search,
                'deep' => $deep,
                'light' => max(1, min(256, $o('slots_light', $t['slots']['light']))),
            ],
            'budget' => $budget,
            'edge_max_mb' => max(1, $o('edge_max_mb', $t['edge_max_mb'])),
            'edge_max_files' => max(10, $o('edge_max_files', $t['edge_max_files'])),
            'noindex_after' => $noindex,
            'under_attack' => $attack ? 1 : 0,
            'render' => $render,
            'render_reserve' => $render > 0 ? min($capCores, $render - 1) : 0,
            'render_waits' => $render > 0 ? $capCores : 0,
            'light_waiters' => $render > 0 ? self::CAP_LIGHT_WAITERS : 0,
            'light_wait_ms' => $render > 0 ? self::CAP_LIGHT_WAIT_MS : 0,
        ];
    }

    /**
     * verify r3 O-F1c: the render-cap values of the snapshot for RequestGate: ['render' => tokens (0 = no cap), 'reserve' =>
     * tokens only light renders take, 'waits' => global wait places, 'light_waiters', 'light_wait_ms' (0 = RequestGate's own
     * defaults)]. Outside the C3 key set of get().
     */
    public static function gate(): array
    {
        $o = self::snapshot()['effective'];
        $render = max(0, (int)($o['render'] ?? 0));
        return ['render' => $render, 'reserve' => $render > 0 ? max(0, min($render - 1, (int)($o['render_reserve'] ?? 0))) : 0,
            'waits' => $render > 0 ? max(1, (int)($o['render_waits'] ?? 1)) : 0,
            'light_waiters' => max(0, (int)($o['light_waiters'] ?? 0)), 'light_wait_ms' => max(0, (int)($o['light_wait_ms'] ?? 0))];
    }

    /**
     * app.crawlers -> the crawler list RequestGate exempts (owner decision 2026-10-17: verified search engines are never
     * blocked or rate-limited). '' = every built-in engine (PublicRequestGuard::CRAWLERS: google, bing, yandex, duckduckgo,
     * apple); otherwise a comma / newline list of built-in keys and custom engines written "name:UA token:.rdns-suffix|.other"
     * (verified by reverse DNS + forward confirmation only; at most 20 entries). '-' or 'none' = no exemption.
     * Returns [key => ['ua' => token|null (null = built-in pattern), 'rdns' => [suffix, ...]|null]].
     */
    public static function crawlerList($raw): array
    {
        $raw = is_string($raw) ? trim($raw) : '';
        $builtin = ['google', 'bing', 'yandex', 'duckduckgo', 'apple'];
        if ($raw === '') {
            return array_fill_keys($builtin, ['ua' => null, 'rdns' => null]);
        }
        if (in_array(strtolower($raw), ['-', 'none', 'off'], true)) {
            return [];
        }
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) as $item) {
            if ($item === '' || count($out) >= 20) {
                continue;
            }
            $parts = explode(':', $item, 3);
            $key = strtolower($parts[0]);
            if (!preg_match('/^[a-z0-9_-]{2,24}$/', $key)) {
                continue;
            }
            if (count($parts) === 1) {
                if (in_array($key, $builtin, true)) { $out[$key] = ['ua' => null, 'rdns' => null]; }
                continue;
            }
            $ua = (string)($parts[1] ?? '');
            $suffixes = [];
            foreach (explode('|', (string)($parts[2] ?? '')) as $sfx) {
                $sfx = strtolower(trim($sfx));
                if (preg_match('/^\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $sfx)) { $suffixes[] = $sfx; }
            }
            if (in_array($key, $builtin, true) || !preg_match('/^[A-Za-z0-9._-]{3,40}$/', $ua) || !$suffixes) {
                continue;
            }
            $out[$key] = ['ua' => $ua, 'rdns' => array_slice(array_values(array_unique($suffixes)), 0, 5)];
        }
        return $out;
    }

    private static function store(int $rows, string $source, int $pin, int $pool, string $version): void
    {
        $settings = self::settings();
        $snap = ['v' => self::VERSION, 'rows' => $rows, 'source' => $source, 'pin' => $pin, 'pool_bytes' => $pool,
            'db_version' => $version, 'refreshed_at' => time(), 'sig' => $settings['sig'], 'cores' => (int)($settings['cores'] ?? 0)];
        $snap['effective'] = self::compute($rows, $pin, $pool, $settings);
        self::writeAtomic(self::runtime('scale') . 'tier.json', json_encode($snap, JSON_UNESCAPED_SLASHES));
        self::$memo = $snap;
    }

    private static function snapshot(): array
    {
        if (self::$memo !== null) {
            // a snapshot read before the framework (EdgeCache::serve, RequestGate::preCheck) that was due for a refresh:
            // refresh it once the framework is there, so a wiped or stale tier.json does not stay tier 1 for the whole
            // request and every later one (verify F5)
            if (self::$refreshDue && class_exists('\think\Db', false) && defined('ENTRANCE')) {
                self::$refreshDue = false;
                self::lazyRefresh();
            }
            return self::$memo;
        }
        $settings = self::settings();
        $snap = self::readJson(self::runtime('scale') . 'tier.json');
        if (is_array($snap) && is_array($snap['effective'] ?? null) && ($snap['v'] ?? 0) !== self::VERSION && isset($snap['rows'])) {
            // a snapshot of an older version (1.2.0 builds with page caps): keep the measured rows, pin and pool, recompute
            // the limits below (no database needed, so the pre-framework layers never fall back to tier 1 after an upgrade)
            $snap['v'] = self::VERSION;
            $snap['effective'] = null;
        }
        if (!is_array($snap) || ($snap['v'] ?? 0) !== self::VERSION || !isset($snap['rows'])) {
            $snap = ['v' => self::VERSION, 'rows' => 0, 'source' => 'none', 'pin' => 0, 'pool_bytes' => 0, 'db_version' => '',
                'refreshed_at' => 0, 'sig' => ''];
        }
        if (($snap['sig'] ?? '') !== $settings['sig'] || (int)($snap['cores'] ?? -1) !== (int)($settings['cores'] ?? 0) || !is_array($snap['effective'] ?? null)) {
            // Settings changed since the snapshot (or no snapshot yet): recompute without the database.
            $snap['effective'] = self::compute((int)$snap['rows'], (int)$snap['pin'], (int)$snap['pool_bytes'], $settings);
            $snap['sig'] = $settings['sig'];
            $snap['cores'] = (int)($settings['cores'] ?? 0);
            if ((int)$snap['refreshed_at'] > 0) {
                self::writeAtomic(self::runtime('scale') . 'tier.json', json_encode($snap, JSON_UNESCAPED_SLASHES));
            }
        }
        self::$memo = $snap;
        // Lazy refresh inside the framework only (one cheap primary-key read), single flight, never twice per request.
        if ((time() - (int)$snap['refreshed_at']) > self::REFRESH_AGE) {
            if (class_exists('\think\Db', false) && defined('ENTRANCE')) {
                self::lazyRefresh();
            } else {
                self::$refreshDue = true;
            }
        }
        return self::$memo;
    }

    private static function lazyRefresh(): void
    {
        $lock = self::runtime('scale') . 'tier.lock';
        $h = @fopen($lock, 'c');
        if ($h && @flock($h, LOCK_EX | LOCK_NB)) {
            self::refresh();
            @flock($h, LOCK_UN);
        }
        if ($h) { @fclose($h); }
    }

    /** Test hook: forget the per-request memo (settings and snapshot). */
    public static function reset(?string $root = null): void
    {
        self::$memo = null;
        self::$settings = null;
        self::$refreshDue = false;
        self::$heavyMemo = null;
        if ($root !== null) {
            self::$root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        }
    }

    /**
     * engines-r3 R3-8: a heavy maintenance step runs (index builds, the rank files' full read, the upgrade's recount and
     * first builds): runtime/scale/heavy-<what>.json with its end time. While one is active, EdgeCache answers an expired
     * page with its stale copy instead of rendering it on the database the step keeps busy (G23, one core: a cold render
     * next to the rank files' 89 s copy took 2.4 s); pages without any copy are rendered as always. $what: a-z, 0-9, _.
     * Clear it with heavyClear() when the step ends; a marker that is never cleared ends by itself after $ttl seconds.
     */
    public static function heavyMark(string $what, int $ttl = self::HEAVY_TTL): void
    {
        if (!preg_match('/^[a-z0-9_]{1,24}$/D', $what)) {
            return;
        }
        $now = time();
        try {
            self::writeAtomic(self::runtime('scale') . 'heavy-' . $what . '.json',
                json_encode(['what' => $what, 'since' => $now, 'until' => $now + max(60, min(86400, $ttl)), 'pid' => (int)getmypid()]));
        } catch (\Throwable $e) {
        }
        self::$heavyMemo = null;
    }

    /** End the heavy-maintenance marker $what (heavyMark). */
    public static function heavyClear(string $what): void
    {
        if (preg_match('/^[a-z0-9_]{1,24}$/D', $what)) {
            @unlink(self::root() . 'runtime/scale/heavy-' . $what . '.json');
        }
        self::$heavyMemo = null;
    }

    /**
     * true while a heavy-maintenance marker is active (heavyMark); remembered for the request. A marker past its end time
     * (a killed process) counts as over and is removed. Read only when a page copy has expired (EdgeCache::serve) and by the
     * page-view housekeeping / import triggers once they would start a run (Housekeeping::hkTrigger / importTrigger, engines-r4
     * R4-3: no visitor request carries a housekeeping slice or a background step next to a heavy step). $root: another site
     * root (tests, a trigger called with its own root); the memo is kept for this site's root only.
     */
    public static function heavyActive(?string $root = null): bool
    {
        $root = $root === null || $root === '' ? self::root() : rtrim(str_replace('\\', '/', $root), '/') . '/';
        $own = $root === self::root();
        if ($own && self::$heavyMemo !== null) {
            return self::$heavyMemo;
        }
        $active = false;
        try {
            $now = time();
            foreach (glob($root . 'runtime/scale/heavy-*.json') ?: [] as $file) {
                $d = self::readJson($file);
                $until = is_array($d) ? (int)($d['until'] ?? 0) : 0;
                if ($until > $now) {
                    $active = true;
                    break;
                }
                if ($until > 0 || (int)@filemtime($file) < $now - 60) {
                    @unlink($file);
                }
            }
        } catch (\Throwable $e) {
        }
        if ($own) {
            self::$heavyMemo = $active;
        }
        return $active;
    }

    /**
     * [select(sql, bind): rows, prefix, execute(sql, bind): int] for the given PDO or the framework connection; null
     * when neither is available.
     */
    public static function queryFn(?\PDO $pdo, ?string $prefix, bool $withExec = false): ?array
    {
        if ($pdo !== null) {
            $pre = (string)$prefix;
            if (!preg_match('/^[A-Za-z0-9_]*$/D', $pre)) {
                return null;
            }
            $select = function ($sql, array $bind = []) use ($pdo) {
                $st = $pdo->prepare($sql);
                $st->execute($bind);
                return $st->fetchAll(\PDO::FETCH_ASSOC);
            };
            $exec = function ($sql, array $bind = []) use ($pdo) {
                $st = $pdo->prepare($sql);
                $st->execute($bind);
                return $st->rowCount();
            };
            return [$select, $pre, $exec];
        }
        if (!class_exists('\think\Db')) {
            return null;
        }
        $pre = (string)\think\Config::get('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $pre)) {
            return null;
        }
        $select = function ($sql, array $bind = []) { return \think\Db::query($sql, $bind); };
        $exec = function ($sql, array $bind = []) { return \think\Db::execute($sql, $bind); };
        return [$select, $pre, $exec];
    }

    // ----- shared helpers (no framework) -----

    public static function root(): string
    {
        if (self::$root === null) {
            self::$root = defined('ROOT_PATH') ? rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/'
                : rtrim(str_replace('\\', '/', dirname(__DIR__, 3)), '/') . '/';
        }
        return self::$root;
    }

    /** runtime/<sub>/ with a trailing slash; created on demand. */
    public static function runtime(string $sub): string
    {
        $dir = self::root() . 'runtime/' . trim($sub, '/') . '/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function readJson(string $file)
    {
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Write through a temporary file and rename, so readers never see a half-written file. */
    public static function writeAtomic(string $file, $bytes): bool
    {
        if (!is_string($bytes)) {
            return false;
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $file . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes)) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $file)) {
            // Windows: rename over an open file can fail; try once more after removing the target.
            @unlink($file);
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
                return false;
            }
        }
        return true;
    }

    /**
     * flock with a bounded wait (default 50 ms) instead of a blocking call or a single LOCK_NB try.
     * Returns true (locked), false (busy after the wait) or null (flock unsupported on this filesystem).
     */
    public static function lock($handle, int $mode = LOCK_EX, int $waitMs = 50): ?bool
    {
        $deadline = microtime(true) + $waitMs / 1000;
        do {
            $wouldBlock = 0;
            if (@flock($handle, $mode | LOCK_NB, $wouldBlock)) {
                return true;
            }
            if (!$wouldBlock && PHP_OS_FAMILY !== 'Windows') {
                return null;
            }
            if ($waitMs <= 0) {
                return false;
            }
            if (PHP_OS_FAMILY === 'Windows') {
                // a sleep lasts one timer tick (about 15 ms) on Windows: wait blocking instead (local runtime files only)
                return @flock($handle, $mode) ? true : false;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);
        return false;
    }

    /**
     * Host for cache keys (L3, se-R12): the request host when it is on the allowlist (site_url, site_wapurl, config('domain')
     * hosts and their www. variants), else the configured site_url host; the raw Host only when nothing is configured.
     */
    public static function keyHost(array $server): string
    {
        $s = self::settings();
        $req = strtolower((string)($server['HTTP_HOST'] ?? ''));
        if ($s['host'] === '') {
            return $req;
        }
        return in_array($req, $s['hosts'], true) ? $req : $s['host'];
    }

    /** ['scheme' => 'http'|'https'|'', 'host' => 'example.com[:port]'] from a site_url value (scheme optional). */
    public static function parseHost(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 255 || preg_match('/[\s<>"\']/', $url)) {
            return ['scheme' => '', 'host' => ''];
        }
        $scheme = '';
        if (preg_match('~^(https?)://~i', $url, $m)) {
            $scheme = strtolower($m[1]);
            $url = substr($url, strlen($m[0]));
        } elseif (strpos($url, '//') === 0) {
            $url = substr($url, 2);
        }
        $host = strtolower(explode('/', $url, 2)[0]);
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?)*(?::[0-9]{1,5})?$/', $host)
            && !preg_match('/^\[[0-9a-f:.]+\](?::[0-9]{1,5})?$/', $host)) {
            return ['scheme' => '', 'host' => ''];
        }
        return ['scheme' => $scheme, 'host' => $host];
    }

    /**
     * CPU cores this PHP can use (0 = unknown): Linux /proc/self/status Cpus_allowed_list (affinity, cpusets) capped by the
     * cgroup v2 cpu.max quota, else /proc/cpuinfo; Windows NUMBER_OF_PROCESSORS. Read only when settings.json is rebuilt
     * (maccms.php changed); app.cpu_cores overrides it.
     */
    public static function cpuCores(): int
    {
        try {
            $n = 0;
            if (PHP_OS_FAMILY === 'Linux') {
                $st = @file_get_contents('/proc/self/status');
                if (is_string($st) && preg_match('/^Cpus_allowed_list:\s*([0-9,\-]+)/m', $st, $m)) {
                    foreach (explode(',', $m[1]) as $part) {
                        $r = explode('-', $part);
                        $n += count($r) === 2 ? max(0, (int)$r[1] - (int)$r[0] + 1) : 1;
                    }
                }
                $max = @file_get_contents('/sys/fs/cgroup/cpu.max');
                if (is_string($max) && preg_match('/^(\d+)\s+(\d+)/', trim($max), $m) && (int)$m[2] > 0) {
                    $q = max(1, (int)ceil((int)$m[1] / (int)$m[2]));
                    $n = $n > 0 ? min($n, $q) : $q;
                }
                if ($n === 0) {
                    $info = @file_get_contents('/proc/cpuinfo');
                    $n = is_string($info) ? (int)preg_match_all('/^processor\s*:/m', $info) : 0;
                }
            } elseif (PHP_OS_FAMILY === 'Windows') {
                $n = (int)getenv('NUMBER_OF_PROCESSORS');
            }
            return max(0, min(1024, (int)$n));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** mtime:size:crc32 of a small config file ('0:0:0' when missing). */
    private static function fileSig(string $file): string
    {
        if (!is_file($file)) {
            return '0:0:0';
        }
        clearstatcache(true, $file);
        $raw = @file_get_contents($file);
        return (int)@filemtime($file) . ':' . (int)@filesize($file) . ':' . (is_string($raw) ? hash('crc32b', $raw) : '0');
    }

    private static function loadConfigFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        // a settings rebuild runs only when the signature changed: drop the opcache copy first, so a save within
        // opcache.revalidate_freq is never read back with its old values (HO-E-O2)
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        try {
            ob_start();
            $data = (static function ($path) { return include $path; })($file);
        } catch (\Throwable $e) {
            $data = [];
        } finally {
            ob_end_clean();
        }
        return is_array($data) ? $data : [];
    }
}
