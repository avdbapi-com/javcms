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
 * Self-maintenance that keeps every install bounded with no admin action (DB-SLIM, 2026-10-14).
 *
 * What grows without it, and the rule applied here:
 *  - runtime/cache (ThinkPHP File cache incl. the public page cache): the driver deletes an expired entry only when the
 *    same key is read again, so old page-cache generations and one-off keys stay forever. Rule: delete a cache file when
 *    its TTL > 0 and now > mtime + TTL + GRACE, or when it is older than MAX_AGE (PRESSURE_AGE while the folder is
 *    crowded), or when its mtime lies in the future. TTL-0 entries are kept, except a known tag index above
 *    TAG_INDEX_MAX. Crashed atomic writes (*.tmp) older than TMP_AGE go too.
 *  - runtime/temp (compiled templates): a file is removed only when none of the template files it was compiled from
 *    exists any more (ThinkPHP never includes it again), and it is older than TEMP_ORPHAN_AGE. A source behind a stream
 *    wrapper or a UNC path is never probed; such a compiled file is kept.
 *  - runtime/log: *.log older than LOG_DAYS; the folder is capped at LOG_CAP bytes (oldest first).
 *  - automatic settings snapshots (runtime/*-settings-backups, runtime/multisite-backups): newest BACKUP_KEEP per folder
 *    plus every snapshot younger than BACKUP_DAYS are kept.
 *  - database: verification messages (msg) older than MSG_DAYS, referral visits (visit) older than VISIT_DAYS, expired
 *    search caches (vod_search) and a SEARCH_CAP row cap on that cache table. Owner and member content (videos, comments,
 *    members, history, points, orders, uploads, backups) is never touched; the AVDB import journal has its own retention.
 *
 * 1.2.0 scale jobs (scale(), see there): hit fold, counts, sitemap buckets, search index, deep-page maps (page maps, rank
 * files, search term map: deepMaps(), XVID-NOLIMIT-BRIEF H3), edge eviction, cache caps and the Cloudflare range refresh,
 * from tools/housekeeping.php (cron heartbeat), the cron URL / schedule callback, or a page view when they are due and no
 * cron ran in 24 h (edgeTrigger() from EdgeCache::serve(), L12). The same page-view trigger also continues a running web
 * import job (importTrigger() -> edgeRun(.., 'import') -> AvdbImport::tickWeb(8), XVID-NOLIMIT-BRIEF A3 / H4): only after
 * fastcgi_finish_request() / litespeed_finish_request(), at most once per IMPORT_GAP seconds, never inside a gate slot.
 *
 * Triggers (all automatic): 1 page view in TICK_ODDS, or TICK_ODDS_INLINE where the response cannot be finished early
 * (behavior HousekeepingTick -> shutdown function; after the response
 * when fastcgi_finish_request / litespeed_finish_request exists, else a TICK_BUDGET_MS slice), the AVDB schedule
 * callback, the cron URL (api/timming) and the CLI tools/housekeeping.php. Every run is bounded by a time budget and
 * a non-blocking lock, works only inside the configured runtime folders and deletes only names it recognises.
 *
 * Adaptive tick (backlog): when a run's cache slice ends on its deadline while at least BACKLOG_SET_PCT % of the cache
 * files it scanned were deletable, it touches runtime/housekeeping/backlog; a run that finds fewer than
 * BACKLOG_CLEAR_PCT % deletable removes it. While that marker is younger than BACKLOG_TTL seconds, page views tick about
 * 1 in BACKLOG_ODDS_INLINE (inline, BACKLOG_TICK_BUDGET_MS slices) or 1 in BACKLOG_ODDS (after an early finish), so
 * hosts without a schedule or cron still delete faster than crawler traffic writes. The marker is looked at only when
 * the normal draw misses (one stat), and a stale or future-dated marker is ignored. A cut slice that walked
 * BACKLOG_EMPTY_FOLDERS folders and saw almost no cache files clears it (an emptied cache).
 * Links (F3 fix 2): no create, write, touch, mkdir or delete ever goes through a symlink, Windows junction or hard link,
 * dangling or not, at any depth below the runtime folder (the runtime folder itself may be a link: a supported setup).
 * Every folder entered must pass a realpath identity check (realDir / realChild), every file deleted must be a plain
 * file with one name (plainFile), runtime/housekeeping is only created where nothing at all has that name, and the
 * marker, run.lock and state.json are only ever put in place as new plain files renamed over the name (replaceWith):
 * rename() replaces a link by name and fails on a junction, it never writes through either. A link planted under one of
 * those names is removed by its own name (never followed); a linked runtime/housekeeping makes the run refuse.
 */
class Housekeeping
{
    const VERSION = 1;
    const TICK_ODDS = 50;
    const TICK_ODDS_INLINE = 20;
    const TICK_BUDGET_MS = 5;
    const BACKGROUND_BUDGET_MS = 150;
    const CALLBACK_BUDGET_MS = 3000;
    const CALLBACK_BUDGET_INLINE_MS = 800;
    const GRACE = 60;
    const MAX_AGE = 259200;
    const PRESSURE_AGE = 3600;
    const PRESSURE_FILES = 400;
    const PRESSURE_TOTAL_BYTES = 2147483648;
    const TAG_INDEX_MAX = 1048576;
    const TMP_AGE = 3600;
    const TEMP_ORPHAN_AGE = 86400;
    const LOG_DAYS = 30;
    const LOG_CAP = 104857600;
    const BACKUP_KEEP = 20;
    const BACKUP_DAYS = 30;
    const MSG_DAYS = 30;
    const VISIT_DAYS = 90;
    const SEARCH_CAP = 20000;
    const DAY = 86400;
    const BACKLOG_ODDS = 5;
    const BACKLOG_ODDS_INLINE = 2;
    const BACKLOG_TICK_BUDGET_MS = 10;
    const BACKLOG_TTL = 900;
    const BACKLOG_SET_PCT = 50;
    const BACKLOG_CLEAR_PCT = 10;
    const BACKLOG_MIN_SCANNED = 5;
    const BACKLOG_REFRESH = 60;
    const BACKLOG_EMPTY_FOLDERS = 16;
    // 1.2.0 scale jobs (XVID-SCALE-BRIEF L12, D-L3, D-L9, D-L11, L1, L5): own connection without statement limit
    const SCALE_BUDGET = 20;            // seconds per run (cron, CLI, after an early-finished response)
    const SCALE_BUDGET_INLINE = 8;      // seconds when the callback response cannot be finished first
    // verify r2 R2-8: a page view whose response cannot be finished first (no fastcgi / litespeed finish-request) carries
    // only a short slice (the visitor waits for it): fold chunk, counts slice, sitemap slice, search drain, page maps; every
    // statement under the time left; no full builds, no network, no edge eviction. Work left -> the next claim in 60 s.
    const SCALE_BUDGET_EDGE_INLINE = 1.2;
    const CAP_GRACE = 2.0;              // web slices: a statement may run this long past the slice deadline (then it is stopped)
    const FOLD_SHARE = 0.5;             // web runs: the hit-buffer fold stops at this share of the budget (the other jobs follow)
    const SCALE_GAP = 300;              // the due file asks for the next run 5 minutes later
    const SCALE_CLAIM = 60;             // a page view that starts a run pushes the due time 60 s ahead (one per minute)
    const CRON_FRESH = 86400;           // a cron heartbeat younger than this: page views never run the jobs
    const EDGE_EVICT_GAP = 300;
    // FR-3 (decision O-29): RequestGate::sweep() of the per-key in-flight places (runtime/gate/k/<2 hex>/): every GATE_SWEEP_GAP
    // seconds (SCALE_CLAIM while the last sweep stopped on its budget), resumable; seconds per sweep by run kind (a visitor's slice
    // only a few milliseconds) and the most place names looked at in one sweep
    const GATE_SWEEP_GAP = 300;
    const GATE_SWEEP_SEC = 1.5;
    const GATE_SWEEP_INLINE_SEC = 0.15;
    const GATE_SWEEP_VISITOR_SEC = 0.03;
    const GATE_SWEEP_FILES = 100000;
    const GATE_SWEEP_INLINE_FILES = 3000;
    const GATE_SWEEP_VISITOR_FILES = 300;
    const CF_REFRESH_GAP = 2592000;     // Cloudflare ranges: monthly
    const CF_RETRY_GAP = 86400;
    const CRAWLER_REFRESH_GAP = 86400;  // search-engine IP range lists: daily (owner decision 2026-10-17)
    const CRAWLER_RETRY_GAP = 3600;     // after a failed refresh (the last good lists stay in use)
    // verify r3 OW1: a host without cron and without fastcgi / litespeed finish-request runs only visitor slices. When no
    // other run happened for 26 h, a visitor slice refreshes ONE engine's list (HTTPS, 2 s connect / 3 s in all); when none
    // happened for 15 minutes, it resolves up to 2 queued crawler claims (1 s). The shipped lists cover the time before.
    const CRAWLER_VISITOR_GAP = 93600;
    const CRAWLER_DNS_VISITOR_GAP = 900;
    const CRAWLER_VISITOR_CONNECT = 2;
    const CRAWLER_VISITOR_TOTAL = 3;
    const CRAWLER_VISITOR_DNS = 2;      // queued claims per visitor slice
    const CRAWLER_VISITOR_DNS_SEC = 1.0;
    // verify r3 N20: no other run for an hour -> a visitor slice builds one sitemap video file a crawler was answered from
    // an older copy, else (site-r3 N20) one that was never built, newest first (Sitemap::prebuild 'visitor', only when its
    // estimated build time fits into the slice)
    const PREBUILD_VISITOR_GAP = 3600;
    // site-r3 N20 (b): prebuildSitemapFiles() for the web installer and the admin "Finish upgrade" (tools/housekeeping.php
    // --sitemap-files-only in a child process: the front-end boot is not possible inside an admin request)
    const PREBUILD_CLI_BUDGET = 30;
    const PREBUILD_CLI_GRACE = 20;
    // engines-r6 NEW-1 / owner decision D-10: refreshSearchStats() = the search index's term statistics (FtIndex::terms())
    // when they are due, then the search term map when it is missing or older than them, in a child process
    // (tools/housekeeping.php --search-stats-only: the term build needs more memory than a web request has) for the admin
    // "Finish upgrade" (SEARCH_STATS_WEB_BUDGET seconds, the child is stopped SEARCH_STATS_GRACE seconds later) and for
    // tools/upgrade.php step 5b (no time bound). The map is started only when its estimate fits the budget that is left:
    // SEARCH_MAP_SEC_BASE + SEARCH_MAP_SEC_PER_MDOC per million search documents (measured 29-34 s per million at 7.0 M on
    // MariaDB 11.4, 1.5 GB pool; doubled for slower disks); otherwise housekeeping rebuilds it (deepHeavy: a map older than
    // the statistics counts as missing). SEARCH_STATS_MEMORY: memory limit of the command-line work (FtIndex::terms() counts
    // the tag / performer words exactly within 60 % of it; the map build keeps near 256 MB).
    const SEARCH_STATS_WEB_BUDGET = 60;
    const SEARCH_STATS_GRACE = 30;
    const SEARCH_MAP_SEC_PER_MDOC = 60;
    const SEARCH_MAP_SEC_BASE = 5;
    const SEARCH_STATS_MEMORY = 1073741824;
    const SCALE_HISTORY = 50;
    // XVID-NOLIMIT-BRIEF H3: deep-page maps (lane S classes, run inside the scale budget and lock)
    /**
     * Rank files: seconds between two full builds of each sort. verify r3 R3-4: every sort that is due is built from ONE read
     * of the video table (ListRank::buildAll: a narrow temporary copy of the published rows, then one sort per file set), and
     * the sorts share one daily cycle (time_add weekly: new rows are appended on every run), so a large site reads the table
     * once a day instead of once per sort and once an hour (8.2 M videos on a 2-core / 512 MB VPS: one sort alone 203 s and
     * 1.57 M page reads; all seven from one read 298 s). hits_day keeps an hourly cycle only where a full read is cheap
     * (RANK_HOURLY, RANK_HOURLY_MAX_S: the last measured read).
     */
    const RANK_SORTS = ['hits_day' => 86400, 'hits' => 86400, 'hits_week' => 86400, 'hits_month' => 86400, 'score' => 86400, 'up' => 86400, 'time_add' => 604800];
    /** Shorter cycles while the last full read of the video table took at most RANK_HOURLY_MAX_S seconds (small catalogs). */
    const RANK_HOURLY = ['hits_day' => 3600];
    const RANK_HOURLY_MAX_S = 10.0;
    /** A sort due within this share of its own interval joins the read of the sorts that are due now (the cycles align). */
    const RANK_ALIGN = 0.25;
    const RANK_RETRY = 3600;            // a failed or killed build is tried again after an hour, never on every run
    const FTMAP_RETRY = 3600;           // search term map build (when missing) at most once an hour
    /**
     * engines-r7 NEW-2: PHP memory of the term-map build (FtIndex::mapBuild()), checked before housekeeping starts it. FtIndex
     * keeps one build partition near 256 MB of PHP memory (7.1 M videos: 3 partitions, peak 222 MB); a smaller site needs
     * FTMAP_MEM_BASE_MB + FTMAP_MEM_PER_MDOC_MB per million search documents, at most FTMAP_MEM_MAX_MB. The command line raises
     * its limit to SEARCH_STATS_MEMORY (1 GB, never lowered) and a web run after its response to FTMAP_WEB_MEMORY; when the
     * limit that stays leaves less room than that (a host's php_admin_value, PHP 8.5's max_memory_limit), the build is skipped
     * with 'memory' (hk.json; deep.ftmap_mem for the Speed page) instead of dying (the documented cron line at PHP's default
     * 128M died with a fatal error after 70 s, a 'building' state and a 1-hour heavy marker were left behind).
     */
    const FTMAP_MEM_MAX_MB = 256;
    const FTMAP_MEM_BASE_MB = 64;
    const FTMAP_MEM_PER_MDOC_MB = 60;
    const FTMAP_WEB_MEMORY = 536870912;
    const FTMAP_APPEND = 20000;         // documents per term-map append slice
    const FTMAP_APPEND_INLINE = 500;    // the same inside a visitor request's short slice
    const DEEP_BUILD_TIME = 900;        // PHP time limit for one full build in a web run after the response
    const BUSY_LOAD = 1.5;              // web runs skip the deep maps while the load average is above 1.5 x CPU cores
    // XVID-NOLIMIT-BRIEF A3 / H4: page-view import tick (runtime/scale/import.due, owned by AvdbImport::tickWeb)
    const IMPORT_GAP = 30;              // at most one background import step per 30 s per site
    const IMPORT_IDLE_GAP = 300;        // the last check found no running web job: look again after 5 minutes
    const IMPORT_TICK_BUDGET = 8;       // seconds of one background step (AvdbImport::tickWeb(8))
    /** flood-r4 F3 / G9: sitemap rows-list backfill (Sitemap::rowsBackfill): every hour once complete; seconds per web / CLI run. */
    const SITEMAP_ROWS_GAP = 3600;
    const ROWS_BACKFILL_WEB = 3;
    const ROWS_BACKFILL_CLI = 8;

    /** Tags the application uses with Cache::tag(); their index files are TTL 0 and may only be dropped when huge. */
    private static $tags = ['vod', 'art', 'actor', 'topic', 'website', 'role', 'plot', 'type'];
    /** Tests: replaces finishResponse() in edgeRun (callable(): bool). */
    private static $finisher = null;
    private static $niced = false;
    /** engines-r7 NEW-2: heavy-maintenance markers of a build in progress (cleared by its finally; onFatal() after a fatal error). */
    private static $fatalMarks = [];
    /** engines-r7 NEW-2: a little memory freed first by onFatal(), so the clean-up still fits after a memory-limit error. */
    private static $fatalReserve = null;
    private static $dbTasks = ['msg', 'visit', 'search_old', 'search_cap'];

    private $runtime;
    private $cache;
    private $temp;
    private $log;
    private $cacheEnabled;
    private $db;
    private $prefix;
    private $now;
    private $dry;
    private $pauseUs;
    private $searchMinutes;
    private $msgDays;
    private $tagFiles = null;
    private $stats = [];
    private $restart = false;
    /** The checked real runtime/housekeeping folder (set by run(), status(), passCount()). */
    private $hkDir = null;
    /** Tier caps (1.2.0, sc-S7e): the page cache and the search-ids cache count as crowded above them (0 = no cap). */
    private $capFiles = 0;
    private $capBytes = 0;

    /**
     * @param array $o runtime (required), cache, temp, log, cache_enabled, db (['select'=>callable,'execute'=>callable] or
     *                 null), prefix, now, dry_run, pause_ms (between cache folders in a pass), search_minutes, msg_days,
     *                 cap_files / cap_bytes (1.2.0: the tier caps of the edge cache, applied to runtime/cache too)
     */
    public function __construct(array $o)
    {
        $runtime = isset($o['runtime']) ? (string)$o['runtime'] : '';
        if ($runtime === '') { throw new \InvalidArgumentException('runtime folder required'); }
        $this->runtime = rtrim($runtime, '/\\') . DIRECTORY_SEPARATOR;
        $this->cache = rtrim(isset($o['cache']) ? (string)$o['cache'] : $this->runtime . 'cache', '/\\');
        $this->temp = rtrim(isset($o['temp']) ? (string)$o['temp'] : $this->runtime . 'temp', '/\\');
        $this->log = rtrim(isset($o['log']) ? (string)$o['log'] : $this->runtime . 'log', '/\\');
        $this->cacheEnabled = !isset($o['cache_enabled']) || (bool)$o['cache_enabled'];
        $this->db = isset($o['db']) && is_array($o['db']) ? $o['db'] : null;
        $this->prefix = isset($o['prefix']) ? preg_replace('/[^A-Za-z0-9_]/', '', (string)$o['prefix']) : '';
        $this->now = isset($o['now']) ? (int)$o['now'] : null;
        $this->dry = !empty($o['dry_run']);
        $this->pauseUs = isset($o['pause_ms']) ? max(0, (int)$o['pause_ms']) * 1000 : 0;
        $this->searchMinutes = isset($o['search_minutes']) && (int)$o['search_minutes'] > 0 ? (int)$o['search_minutes'] : 20160;
        $this->msgDays = isset($o['msg_days']) && (int)$o['msg_days'] > 0 ? (int)$o['msg_days'] : self::MSG_DAYS;
        $this->capFiles = isset($o['cap_files']) ? max(0, (int)$o['cap_files']) : 0;
        $this->capBytes = isset($o['cap_bytes']) ? max(0, (int)$o['cap_bytes']) : 0;
    }

    // ------------------------------------------------------------------ entry points (framework)

    /**
     * Called by the app_begin behavior: true for about 1 request in TICK_ODDS (TICK_ODDS_INLINE without an early finish).
     * Only when that draw misses and a fresh backlog marker exists, a second draw of 1 in BACKLOG_ODDS
     * (BACKLOG_ODDS_INLINE) is made. $runtime and $background are for tests; the defaults come from the framework.
     */
    public static function shouldTick($runtime = null, $background = null, $now = null)
    {
        if ($background === null) { $background = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'); }
        if (mt_rand(1, $background ? self::TICK_ODDS : self::TICK_ODDS_INLINE) === 1) { return true; }
        if (!self::backlogActive($runtime, $now)) { return false; }
        return mt_rand(1, $background ? self::BACKLOG_ODDS : self::BACKLOG_ODDS_INLINE) === 1;
    }

    /**
     * True while runtime/housekeeping/backlog exists and is younger than BACKLOG_TTL (one stat: is_file fills PHP's stat
     * cache for filemtime; neither warns when the file is missing). An mtime more than BACKLOG_REFRESH s in the future
     * (clock skew) counts as stale.
     */
    public static function backlogActive($runtime = null, $now = null)
    {
        if ($runtime === null) {
            if (!defined('RUNTIME_PATH')) { return false; }
            $runtime = RUNTIME_PATH;
        }
        $p = rtrim((string)$runtime, '/\\') . DIRECTORY_SEPARATOR . 'housekeeping' . DIRECTORY_SEPARATOR . 'backlog';
        if (!is_file($p)) { return false; }
        $m = filemtime($p);
        $age = ($now === null ? time() : (int)$now) - (int)$m;
        return $m !== false && $age < self::BACKLOG_TTL && $age > -self::BACKLOG_REFRESH;
    }

    /** Shutdown function of a ticked page view. Never throws, never prints. */
    public static function onShutdown()
    {
        try {
            $finished = self::finishResponse();
            $hk = self::fromApp();
            if (!$hk) { return; }
            if ($finished) { $hk->run('background', self::BACKGROUND_BUDGET_MS); }
            else { $hk->run('tick', self::backlogActive() ? self::BACKLOG_TICK_BUDGET_MS : self::TICK_BUDGET_MS); }
        } catch (\Throwable $e) {
            // Housekeeping must never break or slow a page; the next tick retries.
        }
    }

    /** Shutdown function of the AVDB schedule callback and the cron URL: one bounded pass. */
    public static function onCallback()
    {
        try {
            $finished = self::finishResponse();
            $hk = self::fromApp();
            if ($hk) { $hk->run('pass', $finished ? self::CALLBACK_BUDGET_MS : self::CALLBACK_BUDGET_INLINE_MS); }
            // 1.2.0: the cron URL and the schedule callback also run the scale jobs and count as a cron heartbeat (L12)
            if (defined('ROOT_PATH')) { self::scale(ROOT_PATH, 'callback', $finished ? self::SCALE_BUDGET : self::SCALE_BUDGET_INLINE, ['inline' => !$finished]); }
        } catch (\Throwable $e) {
        }
    }

    /** Admin "Clear cache": a pass with the given budget; returns the run statistics. */
    public static function adminPass($budgetMs = 5000)
    {
        try {
            $hk = self::fromApp();
            return $hk ? $hk->run('pass', (int)$budgetMs) : [];
        } catch (\Throwable $e) {
            return ['error' => get_class($e)];
        }
    }

    /** Builds an instance from the framework constants and configuration, or null outside ThinkPHP. */
    public static function fromApp()
    {
        if (!defined('RUNTIME_PATH') || !function_exists('config')) { return null; }
        $type = strtolower((string)config('cache.type'));
        $cachePath = (string)config('cache.path');
        if ($cachePath === '') { $cachePath = defined('CACHE_PATH') ? CACHE_PATH : RUNTIME_PATH . 'cache'; }
        $prefix = (string)config('cache.prefix');
        if ($prefix !== '') { $cachePath = rtrim($cachePath, '/\\') . DIRECTORY_SEPARATOR . $prefix; }
        $installed = defined('APP_PATH') && (is_file(APP_PATH . 'data/install/install.lock') || is_file(APP_PATH . 'install.lock'));
        $db = null;
        if ($installed && class_exists('\\think\\Db')) {
            $db = [
                'select' => function ($sql, array $bind) { return \think\Db::query($sql, $bind); },
                'execute' => function ($sql, array $bind) { return \think\Db::execute($sql, $bind); },
            ];
        }
        $cfg = isset($GLOBALS['config']) && is_array($GLOBALS['config']) ? $GLOBALS['config'] : [];
        return new self([
            'runtime' => RUNTIME_PATH,
            'cache' => $cachePath,
            'temp' => defined('TEMP_PATH') ? TEMP_PATH : RUNTIME_PATH . 'temp',
            'log' => defined('LOG_PATH') ? LOG_PATH : RUNTIME_PATH . 'log',
            'cache_enabled' => ($type === '' || $type === 'file'),
            'db' => $db,
            'prefix' => (string)config('database.prefix'),
            'search_minutes' => isset($cfg['app']['vod_search_optimise_cache_minutes']) ? (int)$cfg['app']['vod_search_optimise_cache_minutes'] : 0,
            'msg_days' => isset($cfg['email']['time']) ? max(self::MSG_DAYS, (int)ceil((int)$cfg['email']['time'] * 2 / 1440)) : self::MSG_DAYS,
        ] + self::tierCaps());
    }

    /** ['cap_files', 'cap_bytes'] from the scale tier (edge cache caps, sc-S7e); [] when the tier class is absent. */
    public static function tierCaps()
    {
        try {
            if (!class_exists(__NAMESPACE__ . '\ScaleTier')) { return []; }
            $t = ScaleTier::get();
            return ['cap_files' => (int)$t['edge_max_files'], 'cap_bytes' => (int)$t['edge_max_mb'] * 1048576];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Sends the response to the client first when the SAPI allows it. */
    private static function finishResponse()
    {
        // think\Response::send() already calls fastcgi_finish_request(); a second call returns false but the client has
        // its answer either way, so the function's existence is what counts.
        if (function_exists('fastcgi_finish_request')) { @ignore_user_abort(true); @fastcgi_finish_request(); return true; }
        if (function_exists('litespeed_finish_request')) { @ignore_user_abort(true); @litespeed_finish_request(); return true; }
        return false;
    }

    // ------------------------------------------------------------------ run

    /**
     * One bounded run. Modes: 'tick' (random cache folder, light tasks), 'background' (same, larger budget, after the
     * response), 'pass' (continues the deterministic folder cursor; a full pass over the 256 folders completes over
     * several runs). Returns statistics; ['skipped' => 'locked'] when another run holds the lock.
     */
    public function run($mode = 'tick', $budgetMs = 5)
    {
        // PHP caches realpath() results for up to 120 s per process: a folder swapped for a link since an earlier run in
        // this process (CLI loops, the schedule callback) would still pass the realpath identity checks (F3 polish 4.2).
        clearstatcache(true);
        $t0 = microtime(true);
        $deadline = $t0 + max(1, (int)$budgetMs) / 1000;
        $this->stats = ['mode' => $mode, 'cache' => ['folders' => 0, 'scanned' => 0, 'deleted' => 0, 'bytes' => 0, 'tmp' => 0, 'tag' => 0, 'cut' => false],
            'temp' => 0, 'logs' => 0, 'backups' => 0, 'db' => []];
        // Its own folder must really be inside runtime (not a symlink / junction): the lock, state and backlog marker are
        // written there.
        $dir = $this->ownDir(true);
        if ($dir === null) { return ['skipped' => 'no-runtime']; }
        $this->hkDir = $dir;
        // The lock and the state are opened by name: a symlink / junction / hard link planted there would lead outside.
        // ownEntry() removes such an entry by its own name (never what it points to); the run is refused if it stays.
        $lockPath = $dir . DIRECTORY_SEPARATOR . 'run.lock';
        foreach ([$lockPath, $this->statePath()] as $own) {
            if (!self::ownEntry($own)) { return ['skipped' => 'no-runtime']; }
        }
        // Opened read-only ('c' would create a missing file at the target of a link planted after the check); a missing
        // lock file is first put in place as a new plain file. flock() works on a read handle.
        if (!is_file($lockPath) && !self::replaceWith($lockPath, '', null) && !is_file($lockPath)) { return ['skipped' => 'no-lock']; }
        $lock = @fopen($lockPath, 'rb');
        if (!$lock) { return ['skipped' => 'no-lock']; }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ['skipped' => 'locked']; }
        try {
            $tLock = microtime(true); $state = $this->readState(true);
            $now = $this->now();
            $light = $mode === 'tick';
            // Daily tasks first (each is small and bounded): a tick runs at most one, a larger run every due task once.
            // A task that hit its batch limit stays due, so a backlog drains over the next runs.
            $taskRan = false;
            $tasks = ['logs', 'backups', 'temp'];
            if ($this->db) { $tasks = array_merge($tasks, self::$dbTasks); }
            foreach ($tasks as $task) {
                if (microtime(true) >= $deadline) { break; }
                $due = isset($state['due'][$task]) ? (int)$state['due'][$task] : 0;
                if ($due > $now) { continue; }
                $more = false;
                try { $more = $this->runTask($task, $state, $light ? 50 : 500, $deadline); $state['err'][$task] = ''; }
                catch (\Throwable $e) { $state['err'][$task] = get_class($e) . ':' . $e->getCode(); $more = false; }
                $state['due'][$task] = $more ? $now : $now + self::DAY;
                $taskRan = true;
                if ($light) { break; }
            }
            $tTasks = microtime(true);
            if ($this->cacheEnabled && microtime(true) < $deadline) {
                if ($mode === 'pass') { $this->passCache($state, $deadline); }
                else { $this->randomCache($state, $deadline); }
                if (!$this->dry) { $this->stats['backlog'] = $this->updateBacklog($dir, $now); }
            }
            $ms = (int)round((microtime(true) - $t0) * 1000);
            $c = $this->stats['cache'];
            $state['totals']['files'] = (isset($state['totals']['files']) ? (int)$state['totals']['files'] : 0) + $c['deleted'] + $c['tmp'] + $c['tag'] + $this->stats['temp'] + $this->stats['logs'] + $this->stats['backups'];
            $state['totals']['bytes'] = (isset($state['totals']['bytes']) ? (float)$state['totals']['bytes'] : 0) + $c['bytes'];
            $state['totals']['rows'] = (isset($state['totals']['rows']) ? (int)$state['totals']['rows'] : 0) + array_sum($this->stats['db']);
            $state['last_run'] = ['at' => $now, 'mode' => $mode, 'ms' => $ms];
            $state['runs'] = (isset($state['runs']) ? (int)$state['runs'] : 0) + 1;
            // A full run also removes what a crash left between creating and renaming a file or folder (old, own names only).
            if ($mode === 'pass' && !$this->dry) { $this->sweepOwnTmp($dir, $now); }
            $tCache = microtime(true);
            // A page tick saves the state only when a daily task ran (its due date matters); its cache work needs no state.
            if (!$this->dry && ($mode === 'pass' || $taskRan)) { $this->writeState($state); }
            $this->stats['ms'] = $ms;
            $this->stats['phases'] = ['lock' => round(($tLock - $t0) * 1000, 2), 'tasks' => round(($tTasks - $tLock) * 1000, 2),
                'cache' => round(($tCache - $tTasks) * 1000, 2), 'state' => round((microtime(true) - $tCache) * 1000, 2)];
            return $this->stats;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Backlog marker after a cache slice (called under the run lock; $dir = the checked runtime/housekeeping folder).
     * Set (or refreshed, at most once per BACKLOG_REFRESH s) when the slice stopped on its deadline with >= BACKLOG_SET_PCT %
     * of the scanned files deletable; removed when < BACKLOG_CLEAR_PCT % were deletable. Small samples decide nothing,
     * except a slice that finished before its deadline (it saw everything it looked for). Between the two thresholds the
     * marker is left alone and ages out after BACKLOG_TTL. Returns 'set', 'refreshed', 'cleared' or ''.
     */
    private function updateBacklog($dir, $now)
    {
        $c = $this->stats['cache'];
        $scanned = (int)$c['scanned'];
        $deletable = (int)$c['deleted'] + (int)$c['tag'];
        $cut = !empty($c['cut']);
        $p = $dir . DIRECTORY_SEPARATOR . 'backlog';
        // A 5 ms inline tick on a slow disk scans only ~10-15 files, so setting needs BACKLOG_MIN_SCANNED; clearing needs
        // twice that (or the whole cache seen), so one unlucky small sample does not end a real backlog.
        if ($cut && $scanned >= self::BACKLOG_MIN_SCANNED && $deletable * 100 >= $scanned * self::BACKLOG_SET_PCT) {
            // Only a plain file of our own counts (a planted link / junction / hard link is removed by its name first).
            if (!self::ownEntry($p)) { return ''; }
            $m = is_file($p) ? filemtime($p) : false;
            if ($m !== false && $now - $m < self::BACKLOG_REFRESH && $m <= $now + self::BACKLOG_REFRESH) { return ''; }
            // Not touch(): it follows a link planted after the check and creates a missing file with fopen('w') at a
            // dangling link's target (or empties a linked file). A new plain file is renamed over the name instead.
            if (self::replaceWith($p, '', $this->now !== null ? $now : null)) { return $m === false ? 'set' : 'refreshed'; }
            return '';
        }
        // A cut slice that saw fewer than 10 cache files decides nothing, unless it walked BACKLOG_EMPTY_FOLDERS or more
        // folders: then the cache is (almost) empty, e.g. after "Clear cache" left the 256 hex folders behind.
        if ($cut && $scanned < 2 * self::BACKLOG_MIN_SCANNED && (int)$c['folders'] < self::BACKLOG_EMPTY_FOLDERS) { return ''; }
        if ($deletable * 100 < $scanned * self::BACKLOG_CLEAR_PCT || $scanned === 0) {
            // unlink() removes a link itself, never its target.
            if (is_file($p) && @unlink($p)) { return 'cleared'; }
        }
        return '';
    }

    /**
     * Makes $p (a fixed name inside the checked runtime/housekeeping folder) safe to create, open, write or touch.
     * True when nothing is there or it is a plain file with a single name. Anything else there (a symlink, a Windows
     * junction or directory symlink, a hard link, a folder, also a dangling link) is removed by its own name and never
     * followed; false when it cannot be removed (the caller then writes nothing). The normal paths raise no warning:
     * ThinkPHP logs even @-suppressed ones.
     */
    private static function ownEntry($p)
    {
        $kind = self::entryKind($p);
        if ($kind !== 'other') { return true; }
        // unlink() removes a file, a hard link or a file symlink itself; a junction / directory symlink / empty folder
        // needs rmdir(), which removes the reparse point or link, not the folder it points to (on Linux a directory
        // symlink needs unlink(): the second call). A non-empty real folder stays: false.
        $fileLike = is_link($p) ? !is_dir($p) : is_file($p);
        if ($fileLike) { if (!@unlink($p)) { @rmdir($p); } }
        elseif (!@rmdir($p)) { @unlink($p); }
        return self::entryKind($p) === 'none';
    }

    /**
     * 'none' (no entry by that name), 'own' (a plain file, not a link, one hard link) or 'other'. PHP on Windows reports
     * a junction as neither is_link() nor (7.4, dangling) file_exists(), and lstat() gives it mode 0, so a name that only
     * the folder listing shows counts as 'other'.
     */
    private static function entryKind($p)
    {
        clearstatcache(true, $p);
        if (is_link($p)) { return 'other'; }
        if (!file_exists($p)) {
            $name = basename($p);
            foreach ((array)@scandir(dirname($p)) as $n) {
                if (is_string($n) && (DIRECTORY_SEPARATOR === '\\' ? strcasecmp($n, $name) === 0 : $n === $name)) { return 'other'; }
            }
            return 'none';
        }
        if (!is_file($p)) { return 'other'; }
        $s = @lstat($p);
        // nlink 0 = not reported by the file system (treated as one name); 2+ = a hard link to a file that may lie outside.
        return is_array($s) && ($s['mode'] & 0170000) === 0100000 && (int)$s['nlink'] <= 1 ? 'own' : 'other';
    }

    /**
     * A file that may be deleted or read for a decision: an existing plain file with a single name (not a symlink, not a
     * junction (lstat mode 0), not a hard link to a file that may lie outside). Called only on names that exist.
     */
    private static function plainFile($p)
    {
        if (is_link($p)) { return false; }
        $s = @lstat($p);
        return is_array($s) && ($s['mode'] & 0170000) === 0100000 && (int)$s['nlink'] <= 1;
    }

    /**
     * Puts a new plain file with $content at $p without ever writing through what is there: it is written under a
     * random name in the same folder (fopen 'x', a name nobody can plant in advance) and renamed over $p. rename()
     * replaces a file, file symlink or hard link by name and fails on a folder or junction; it never writes to a link's
     * target. $mtime (test clock) dates the new file. False when nothing was put there.
     */
    private static function replaceWith($p, $content, $mtime)
    {
        $tmp = $p . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $h = @fopen($tmp, 'x');
        if ($h === false) { return false; }
        $ok = $content === '' || @fwrite($h, $content) === strlen($content);
        fclose($h);
        if ($ok && $mtime !== null) { @touch($tmp, (int)$mtime); }
        if ($ok && @rename($tmp, $p)) { return true; }
        @unlink($tmp);
        return false;
    }

    /**
     * Creates the folder $name in the real folder $parent only where nothing at all has that name: a dangling symlink or
     * junction is not is_dir(), and mkdir() through it creates its target outside (PHP 7.4 on Windows). The folder is
     * made under a random name and renamed into place, so a link planted after the check is not followed either
     * (rename() of a folder fails on a junction / directory symlink and replaces a file link by name).
     */
    private static function makeDir($parent, $name)
    {
        $p = $parent . DIRECTORY_SEPARATOR . $name;
        if (self::entryKind($p) !== 'none') { return false; }
        $tmp = $p . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (!@mkdir($tmp, 0755)) { return false; }
        if (@rename($tmp, $p)) { return true; }
        @rmdir($tmp);
        return false;
    }

    /**
     * The real runtime/housekeeping folder, or null when the runtime folder is missing or that name is anything but a
     * real folder (a symlink, a junction, dangling or not, a file). $create: make it when nothing has that name.
     */
    private function ownDir($create = false)
    {
        $rt = @realpath($this->runtime);
        if ($rt === false || !is_dir($rt)) { return null; }
        $rt = rtrim($rt, '/\\');
        $dir = self::realChild($rt, 'housekeeping');
        if ($dir === null && $create) {
            self::makeDir($rt, 'housekeeping');
            // A run that made it at the same moment counts too; anything else by that name makes the run refuse.
            $dir = self::realChild($rt, 'housekeeping');
        }
        return $dir;
    }

    /**
     * Left by a crash between creating and renaming: replaceWith() temp files in runtime/housekeeping and the empty temp
     * folders of makeDir() and of the admin Clear cache (runtime/temp), older than TMP_AGE, own names only, never
     * through a link.
     */
    private function sweepOwnTmp($dir, $now)
    {
        foreach ((array)@scandir($dir) as $n) {
            if (!is_string($n) || !preg_match('/^(?:run\.lock|state\.json|backlog)\.[0-9a-f]{12}\.tmp$/', $n)) { continue; }
            $p = $dir . DIRECTORY_SEPARATOR . $n;
            $m = @filemtime($p);
            if ($m !== false && $now - $m > self::TMP_AGE) { $this->unlinkIfUnchanged($p, $m); }
        }
        $rt = dirname($dir);
        foreach ((array)@scandir($rt) as $n) {
            if (!is_string($n) || !preg_match('/^(?:housekeeping|temp)\.[0-9a-f]{12}\.tmp$/', $n) || ($p = self::realChild($rt, $n)) === null) { continue; }
            $m = @filemtime($p);
            if ($m !== false && $now - $m > self::TMP_AGE) { @rmdir($p); }
        }
    }

    /** Last full pass, totals and errors for an admin status line (read-only; never through a linked folder). */
    public function status()
    {
        $this->hkDir = $this->ownDir();
        $s = $this->readState();
        $bp = $this->hkDir === null ? null : $this->hkDir . DIRECTORY_SEPARATOR . 'backlog';
        $m = $bp !== null && is_file($bp) ? filemtime($bp) : false;
        return [
            'last_run' => isset($s['last_run']) ? $s['last_run'] : null,
            'last_pass' => isset($s['last_pass']) ? $s['last_pass'] : null,
            'totals' => isset($s['totals']) ? $s['totals'] : ['files' => 0, 'bytes' => 0, 'rows' => 0],
            'errors' => array_filter(isset($s['err']) ? (array)$s['err'] : []),
            'backlog' => $m === false ? null : ['at' => (int)$m, 'active' => self::backlogActive($this->runtime, $this->now())],
        ];
    }

    /** The next 'pass' run starts a new full pass at folder 00 (CLI --all / --dry-run). */
    public function restartPass()
    {
        $this->restart = true;
    }

    /** Number of completed full passes (the CLI --all loop stops when it grows). */
    public function passCount()
    {
        if ($this->hkDir === null) { $this->hkDir = $this->ownDir(); }
        $s = $this->readState();
        return isset($s['passes']) ? (int)$s['passes'] : 0;
    }

    // ------------------------------------------------------------------ cache

    /**
     * Pure decision for one cache file. $header = first 32 bytes. Returns 'keep', 'unknown' (not a cache file),
     * 'expired', 'aged', 'future' or 'tag'.
     */
    public static function cacheVerdict($header, $mtime, $now, $size, $pressure, $isTagIndex)
    {
        if (!is_string($header) || strlen($header) < 21 || substr($header, 0, 8) !== "<?php\n//" || $header[20] !== "\n") { return 'unknown'; }
        $ttlText = substr($header, 8, 12);
        if (!preg_match('/^-?[0-9]+$/', $ttlText)) { return 'unknown'; }
        $ttl = (int)$ttlText;
        if ($ttl === 0) { return ($isTagIndex && $size > self::TAG_INDEX_MAX) ? 'tag' : 'keep'; }
        if ($mtime > $now + 300) { return 'future'; }
        if ($now > $mtime + $ttl + self::GRACE) { return 'expired'; }
        if ($now - $mtime > ($pressure ? self::PRESSURE_AGE : self::MAX_AGE)) { return 'aged'; }
        return 'keep';
    }

    private function cacheBase()
    {
        return $this->realDir($this->cache);
    }

    /**
     * A configured folder (cache, temp, log) as a real path, or null. Inside the runtime folder every part below it must be
     * a real folder: realpath() must equal the runtime folder's real path plus the same names (the runtime folder itself
     * may be a symlink / junction, a supported setup). A folder configured elsewhere must not be a link itself.
     */
    private function realDir($path)
    {
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$path), DIRECTORY_SEPARATOR);
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $this->runtime), DIRECTORY_SEPARATOR);
        $real = $path === '' ? false : @realpath($path);
        if ($real === false || !is_dir($real)) { return null; }
        $real = rtrim($real, '/\\');
        $n = strlen($root);
        if ($n > 0 && strlen($path) > $n + 1 && $path[$n] === DIRECTORY_SEPARATOR && self::samePath(substr($path, 0, $n), $root)) {
            $rootReal = @realpath($root);
            $rel = [];
            foreach (explode(DIRECTORY_SEPARATOR, substr($path, $n + 1)) as $part) {
                if ($part === '' || $part === '.') { continue; }
                if ($part !== '..') { $rel[] = $part; continue; }
                if (!$rel) { $rootReal = false; break; }
                array_pop($rel);
            }
            if ($rootReal !== false && $rel) {
                return self::samePath($real, rtrim($rootReal, '/\\') . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $rel)) ? $real : null;
            }
        }
        return self::realRoot($path) !== null ? $real : null;
    }

    private function hexDir($base, $i)
    {
        return self::realChild($base, sprintf('%02x', $i));
    }

    /** Same path? Case-insensitive on Windows only. */
    private static function samePath($a, $b)
    {
        $a = rtrim(str_replace('/', DIRECTORY_SEPARATOR, (string)$a), DIRECTORY_SEPARATOR);
        $b = rtrim(str_replace('/', DIRECTORY_SEPARATOR, (string)$b), DIRECTORY_SEPARATOR);
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    /**
     * The real sub-folder $name of the real folder $parent, or null when it is missing, a symlink or a Windows junction
     * (is_link() is false for junctions on PHP 7.4 / 8.x, so realpath() must lead back to the same path).
     */
    private static function realChild($parent, $name)
    {
        $path = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($path) || is_link($path)) { return null; }
        $real = @realpath($path);
        if ($real === false || !self::samePath($real, $path)) { return null; }
        return $path;
    }

    /** A configured folder whose last part is not a symlink or junction, as a real path; null otherwise. */
    private static function realRoot($path)
    {
        $path = rtrim((string)$path, '/\\');
        $parent = @realpath(dirname($path));
        if ($parent === false || $path === '') { return null; }
        return self::realChild($parent, basename($path));
    }

    /**
     * A plain file directly inside the real folder $dir (a file symlink, a hard link or a path that leads elsewhere is
     * refused).
     */
    private static function fileIn($dir, $name)
    {
        $p = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_file($p) || !self::plainFile($p)) { return null; }
        $real = @realpath($p);
        if ($real === false || !self::samePath(dirname($real), $dir) || !self::samePath($real, $p)) { return null; }
        return $p;
    }

    private function pressure($fileCount, array $state)
    {
        if ($fileCount > self::PRESSURE_FILES) { return true; }
        // 1.2.0 (sc-S7e): the page cache and the search-ids cache live here; above the tier's file or size cap the whole
        // cache counts as crowded and entries older than PRESSURE_AGE go, so runtime/cache stays near the edge caps.
        if ($this->capFiles > 0 && isset($state['last_pass']['files']) && (int)$state['last_pass']['files'] > $this->capFiles) { return true; }
        if ($this->capBytes > 0 && isset($state['last_pass']['bytes']) && (float)$state['last_pass']['bytes'] > $this->capBytes) { return true; }
        return isset($state['last_pass']['bytes']) && (float)$state['last_pass']['bytes'] > self::PRESSURE_TOTAL_BYTES;
    }

    private function randomCache(array &$state, $deadline)
    {
        $base = $this->cacheBase();
        if ($base === null) { return; }
        $subs = [];
        foreach ((array)@scandir($base) as $sub) {
            if (is_string($sub) && strlen($sub) === 2 && ctype_xdigit($sub) && strtolower($sub) === $sub) { $subs[] = hexdec($sub); }
        }
        $count = count($subs);
        if ($count === 0) { return; }
        $start = mt_rand(0, $count - 1);
        for ($k = 0; $k < $count && microtime(true) < $deadline; $k++) {
            $dir = $this->hexDir($base, $subs[($start + $k) % $count]);
            if ($dir === null) { continue; }
            $names = @scandir($dir);
            if (!is_array($names)) { continue; }
            $names = array_values(array_diff($names, ['.', '..']));
            $this->stats['cache']['folders']++;
            $n = count($names);
            if ($n === 0) { continue; }
            $pressure = $this->pressure($n, $state);
            $offset = mt_rand(0, $n - 1);
            $done = true;
            for ($j = 0; $j < $n; $j++) {
                if (microtime(true) >= $deadline) { $done = false; break; }
                $this->cacheFile($dir, $names[($offset + $j) % $n], $pressure);
            }
            if (!$done) { break; }
        }
        if (microtime(true) >= $deadline) { $this->stats['cache']['cut'] = true; }
    }

    private function passCache(array &$state, $deadline)
    {
        $base = $this->cacheBase();
        if ($base === null) { return; }
        if ($this->restart) {
            $this->restart = false;
            $state['cursor'] = 0; $state['cursor_file'] = '';
            $state['pass'] = ['started' => $this->now(), 'files' => 0, 'bytes' => 0, 'deleted' => 0];
        }
        $cursor = isset($state['cursor']) ? max(0, min(255, (int)$state['cursor'])) : 0;
        $after = isset($state['cursor_file']) ? (string)$state['cursor_file'] : '';
        if (!isset($state['pass']) || !is_array($state['pass'])) { $state['pass'] = ['started' => $this->now(), 'files' => 0, 'bytes' => 0, 'deleted' => 0]; }
        while (microtime(true) < $deadline) {
            $dir = $this->hexDir($base, $cursor);
            $complete = true;
            if ($dir !== null) {
                $names = @scandir($dir);
                $names = is_array($names) ? array_values(array_diff($names, ['.', '..'])) : [];
                $this->stats['cache']['folders']++;
                $pressure = $this->pressure(count($names), $state);
                foreach ($names as $name) {
                    if ($after !== '' && strcmp($name, $after) <= 0) { continue; }
                    if (microtime(true) >= $deadline) { $complete = false; break; }
                    $r = $this->cacheFile($dir, $name, $pressure);
                    if ($r[0] === 'keep') { $state['pass']['files']++; $state['pass']['bytes'] += $r[1]; }
                    elseif ($r[0] !== 'skip') { $state['pass']['deleted']++; }
                    $after = $name;
                }
            }
            if (!$complete) { $this->stats['cache']['cut'] = true; break; }
            $after = '';
            if ($cursor === 255) {
                $state['last_pass'] = ['at' => $this->now(), 'started' => (int)$state['pass']['started'], 'files' => (int)$state['pass']['files'],
                    'bytes' => (float)$state['pass']['bytes'], 'deleted' => (int)$state['pass']['deleted']];
                $state['passes'] = (isset($state['passes']) ? (int)$state['passes'] : 0) + 1;
                $state['pass'] = ['started' => $this->now(), 'files' => 0, 'bytes' => 0, 'deleted' => 0];
                $cursor = 0;
                $state['cursor'] = 0; $state['cursor_file'] = '';
                break;
            }
            $cursor++;
            if ($this->pauseUs) { usleep($this->pauseUs); }
        }
        // Stopped between two folders on the deadline (a completed pass resets the cursor to 0 and breaks out above).
        if ($cursor !== 0 && $after === '' && microtime(true) >= $deadline) { $this->stats['cache']['cut'] = true; }
        if ($cursor !== 0 || $after !== '') { $state['cursor'] = $cursor; $state['cursor_file'] = $after; }
    }

    /** Returns [verdict, bytes]. Deletes only recognised cache files that the rules allow. */
    private function cacheFile($dir, $name, $pressure)
    {
        $now = $this->now();
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (preg_match('/^[0-9a-f]{30}\.php\.[0-9a-f]{1,40}\.tmp$/', $name)) {
            // plainFile() (lstat only) first: filemtime() warns on a dangling link on PHP 7.4, and ThinkPHP logs it.
            if (!self::plainFile($path)) { return ['skip', 0]; }
            $m = @filemtime($path);
            if ($m !== false && $now - $m > self::TMP_AGE && $this->unlinkIfUnchanged($path, $m)) { $this->stats['cache']['tmp']++; return ['tmp', 0]; }
            return ['skip', 0];
        }
        if (!preg_match('/^[0-9a-f]{30}\.php$/', $name)) { return ['skip', 0]; }
        $this->stats['cache']['scanned']++;
        // One lstat() decides first (it never warns and never follows): a folder, junction (mode 0), link, FIFO or hard
        // link with a cache file's name is skipped before anything is stat()ed or read. Reading a folder raises a warning
        // that ThinkPHP logs even under @, a FIFO would block the run, and filemtime() warns on a dangling link on PHP 7.4
        // (F3 polish 4.1). For a plain file lstat() equals stat(), so its mtime / size are used directly.
        $st = @lstat($path);
        if (!is_array($st) || ($st['mode'] & 0170000) !== 0100000 || (int)$st['nlink'] > 1) { return ['skip', 0]; }
        $m = (int)$st['mtime'];
        $size = (int)$st['size'];
        if ($now - $m <= self::GRACE && $m <= $now + 300) { return ['keep', $size]; }
        $header = @file_get_contents($path, false, null, 0, 32);
        $v = self::cacheVerdict($header, $m, $now, $size, $pressure, $this->isTagIndex($dir, $name));
        if ($v === 'keep' || $v === 'unknown') { return [$v === 'keep' ? 'keep' : 'skip', $size]; }
        if (!$this->unlinkIfUnchanged($path, $m)) { return ['keep', $size]; }
        if ($v === 'tag') { $this->stats['cache']['tag']++; } else { $this->stats['cache']['deleted']++; }
        $this->stats['cache']['bytes'] += $size;
        return [$v, $size];
    }

    private function isTagIndex($dir, $name)
    {
        if ($this->tagFiles === null) {
            $this->tagFiles = [];
            foreach (self::$tags as $tag) { $h = md5('tag_' . md5($tag)); $this->tagFiles[substr($h, 0, 2) . '/' . substr($h, 2) . '.php'] = true; }
        }
        return isset($this->tagFiles[basename($dir) . '/' . $name]);
    }

    /** Deletes only a plain file with one name whose mtime is still the one the decision was made on. */
    private function unlinkIfUnchanged($path, $mtime)
    {
        clearstatcache(true, $path);
        $m = @filemtime($path);
        if ($m === false || $m !== $mtime || !self::plainFile($path)) { return false; }
        if ($this->dry) { return true; }
        return @unlink($path);
    }

    // ------------------------------------------------------------------ daily tasks

    private function runTask($task, array &$state, $limit, $deadline)
    {
        switch ($task) {
            case 'logs': return $this->taskLogs();
            case 'backups': return $this->taskBackups();
            case 'temp': return $this->taskTemp($state, $deadline);
            default: return $this->taskDb($task, $limit);
        }
    }

    /** *.log older than LOG_DAYS, then the oldest files while the folder exceeds LOG_CAP. */
    private function taskLogs()
    {
        // Never follow a symlink / junction: the log root, every month folder and every file must really be inside.
        $base = $this->realDir($this->log);
        if ($base === null) { return false; }
        $now = $this->now();
        $files = [];
        $months = @scandir($base);
        foreach (is_array($months) ? $months : [] as $sub) {
            if ($sub === '.' || $sub === '..') { continue; }
            if (preg_match('/^[0-9A-Za-z_.-]{1,80}\.log$/', $sub)) { $f = self::fileIn($base, $sub); if ($f !== null) { $files[] = $f; } continue; }
            if (!preg_match('/^[0-9]{6}$/', $sub)) { continue; }
            $p = self::realChild($base, $sub);
            if ($p === null) { continue; }
            foreach ((array)@scandir($p) as $f) {
                if (is_string($f) && preg_match('/^[0-9A-Za-z_.-]{1,80}\.log$/', $f) && ($fp = self::fileIn($p, $f)) !== null) { $files[] = $fp; }
            }
        }
        $keep = [];
        $total = 0;
        foreach ($files as $f) {
            $m = @filemtime($f);
            if ($m === false) { continue; }
            if ($now - $m > self::LOG_DAYS * self::DAY) {
                if ($this->dry || @unlink($f)) { $this->stats['logs']++; }
                continue;
            }
            $size = (int)@filesize($f);
            $keep[] = [$m, $f, $size];
            $total += $size;
        }
        if ($total > self::LOG_CAP) {
            usort($keep, function ($a, $b) { return $a[0] - $b[0]; });
            foreach ($keep as $k) {
                if ($total <= self::LOG_CAP) { break; }
                if ($this->dry || @unlink($k[1])) { $this->stats['logs']++; $total -= $k[2]; }
            }
        }
        foreach (is_array($months) ? $months : [] as $sub) {
            // rmdir() only on an empty month folder: on a non-empty one it warns ("Directory not empty"), and ThinkPHP logs
            // @-suppressed warnings too (the current month always holds today's log).
            if (preg_match('/^[0-9]{6}$/', $sub) && !$this->dry && ($p = self::realChild($base, $sub)) !== null && is_array($left = @scandir($p)) && count($left) <= 2) { @rmdir($p); }
        }
        return false;
    }

    /** Automatic pre-save snapshots: keep the newest BACKUP_KEEP and everything younger than BACKUP_DAYS. */
    private function taskBackups()
    {
        $base = @realpath($this->runtime);
        if ($base === false) { return false; }
        $now = $this->now();
        foreach ((array)@scandir($base) as $sub) {
            if (!is_string($sub) || !preg_match('/^(?:[a-z]+-settings-backups|multisite-backups)$/', $sub)) { continue; }
            $dir = self::realChild($base, $sub);
            if ($dir === null) { continue; }
            $names = [];
            foreach ((array)@scandir($dir) as $f) {
                if (is_string($f) && preg_match('/^url-settings-[0-9]{8}-[0-9]{6}-[0-9a-f]{16}\.php$/', $f)) { $names[] = $f; }
            }
            rsort($names, SORT_STRING);
            foreach (array_slice($names, self::BACKUP_KEEP) as $f) {
                $p = self::fileIn($dir, $f);
                if ($p === null) { continue; }
                $m = @filemtime($p);
                if ($m !== false && $now - $m > self::BACKUP_DAYS * self::DAY && ($this->dry || @unlink($p))) { $this->stats['backups']++; }
            }
        }
        return false;
    }

    /** Compiled templates whose every source template is gone (never included again). Incremental, budgeted. */
    private function taskTemp(array &$state, $deadline)
    {
        // A linked runtime/temp (or a link in its path) is left alone; a linked file in it is never read or deleted.
        $base = $this->realDir($this->temp);
        if ($base === null) { return false; }
        $now = $this->now();
        $names = @scandir($base);
        $names = is_array($names) ? $names : [];
        $after = isset($state['temp_after']) ? (string)$state['temp_after'] : '';
        foreach ($names as $name) {
            if (!preg_match('/^[0-9a-f]{32}\.php$/', $name) || ($after !== '' && strcmp($name, $after) <= 0)) { continue; }
            if (microtime(true) >= $deadline) { $state['temp_after'] = $after; return true; }
            $after = $name;
            $p = $base . DIRECTORY_SEPARATOR . $name;
            // plainFile() (lstat only) first: filemtime() warns on a dangling link on PHP 7.4, and ThinkPHP logs it.
            if (!self::plainFile($p)) { continue; }
            $m = @filemtime($p);
            if ($m === false || $now - $m <= self::TEMP_ORPHAN_AGE) { continue; }
            $h = @fopen($p, 'rb');
            if (!$h) { continue; }
            $line = (string)fgets($h, 65536);
            fclose($h);
            if (!preg_match('/\/\*(.+?)\*\//', $line, $mm)) { continue; }
            $sources = @unserialize($mm[1], ['allowed_classes' => false]);
            if (!is_array($sources) || !$sources) { continue; }
            // A source behind a stream wrapper (phar://, a registered wrapper) or a UNC path (a network share) is never
            // probed (F3 polish 4.3); it cannot be checked, so its compiled file is kept (as with a live source).
            $alive = false;
            foreach ($sources as $src => $t) {
                if (!is_string($src) || $src === '') { continue; }
                if (!self::localSource($src) || is_file($src)) { $alive = true; break; }
            }
            if (!$alive && $this->unlinkIfUnchanged($p, $m)) { $this->stats['temp']++; }
        }
        $state['temp_after'] = '';
        return false;
    }

    /**
     * A template source path that may be checked with is_file(): no stream wrapper ("://") and no UNC prefix (two
     * backslashes; on Windows also "//" or a mix, which PHP sends to the network as well).
     */
    private static function localSource($src)
    {
        if (strpos($src, '://') !== false || strncmp($src, '\\\\', 2) === 0) { return false; }
        return !(DIRECTORY_SEPARATOR === '\\' && strspn($src, '\\/') >= 2);
    }

    /** One bounded batch of a database retention rule; returns true when more rows are waiting. */
    private function taskDb($task, $limit)
    {
        $limit = max(1, min(1000, (int)$limit));
        $now = $this->now();
        $p = $this->prefix;
        $rows = 0;
        switch ($task) {
            case 'msg':
                $sql = 'FROM `' . $p . 'msg` WHERE `msg_time` < ?';
                $rows = $this->deleteBatch($sql, [$now - $this->msgDays * self::DAY], '`msg_time`', $limit);
                break;
            case 'visit':
                $sql = 'FROM `' . $p . 'visit` WHERE `visit_time` < ?';
                $rows = $this->deleteBatch($sql, [$now - self::VISIT_DAYS * self::DAY], '`visit_time`', $limit);
                break;
            case 'search_old':
                // Same rule as VodSearch::clearOldResult(): expired results; actor results stay for the collector (the cap bounds them).
                $sql = 'FROM `' . $p . 'vod_search` WHERE `search_update_time` < ? AND `search_field` <> ?';
                $rows = $this->deleteBatch($sql, [$now - $this->searchMinutes * 60, 'vod_actor'], '`search_update_time`', $limit);
                break;
            case 'search_cap':
                $c = call_user_func($this->db['select'], 'SELECT COUNT(*) AS c FROM `' . $p . 'vod_search`', []);
                $over = (int)(isset($c[0]['c']) ? $c[0]['c'] : 0) - self::SEARCH_CAP;
                if ($over <= 0) { break; }
                $k = min($over, $limit);
                $keys = call_user_func($this->db['select'], 'SELECT `search_key` FROM `' . $p . 'vod_search` ORDER BY `search_last_hit_time` ASC, `search_update_time` ASC LIMIT ' . (int)$k, []);
                $list = [];
                foreach ((array)$keys as $r) { if (isset($r['search_key'])) { $list[] = (string)$r['search_key']; } }
                if (!$list) { break; }
                if ($this->dry) { $rows = count($list); }
                else { $rows = (int)call_user_func($this->db['execute'], 'DELETE FROM `' . $p . 'vod_search` WHERE `search_key` IN (' . implode(',', array_fill(0, count($list), '?')) . ')', $list); }
                $this->stats['db'][$task] = $rows;
                return $over > $k;
        }
        $this->stats['db'][$task] = $rows;
        return $rows >= $limit;
    }

    private function deleteBatch($fromWhere, array $bind, $orderColumn, $limit)
    {
        if ($this->dry) {
            $r = call_user_func($this->db['select'], 'SELECT COUNT(*) AS c ' . $fromWhere, $bind);
            return min($limit, (int)(isset($r[0]['c']) ? $r[0]['c'] : 0));
        }
        return (int)call_user_func($this->db['execute'], 'DELETE ' . $fromWhere . ' ORDER BY ' . $orderColumn . ' LIMIT ' . (int)$limit, $bind);
    }

    // ------------------------------------------------------------------ scale jobs (1.2.0, XVID-SCALE-BRIEF L12)

    /**
     * One bounded run of the 1.2.0 jobs, without the framework: hit-buffer fold (D-L11), counts (D-L3), sitemap buckets
     * (D-L9), search index housekeeping (FtIndex, lane S: drain, self-heal slice, ms_per_read probe, terms), edge-cache
     * eviction (L1), the sweep of RequestGate's per-key in-flight place files (FR-3, task 'gate'), one cache-pass slice of
     * runtime/cache under the tier caps (page cache, search-ids cache, old list
     * revisions: sc-S7e, D-L12), monthly Cloudflare range refresh (L5), daily search-engine IP range refresh and the
     * reverse-DNS check of queued crawler claims (owner decision 2026-10-17). Own connection without statement limit
     * (ScaleSchema::connect), $budgetSec budget (a job that starts before the deadline finishes its step), single flight
     * (runtime/scale/hk.lock). Never inside a gate slot: a page-view run first releases the request's gate and edge locks.
     * Triggers: 'cron' (tools/housekeeping.php: also the cron heartbeat), 'callback' (cron URL / schedule callback: also a
     * heartbeat), 'edge' (EdgeCache::serve when due and no cron was seen in 24 h), 'cli' (tools/upgrade.php, tests).
     * $opt: now, cf_fetch (callable url -> body|null; default an HTTPS GET with a 5 s timeout), crawler_fetch (same, for the
     * search-engine lists), crawler_resolver (callable ip -> ['ptr' => name, 'forward' => [ips]]), no_network, tasks (subset),
     * inline (a page view that cannot finish its response first: shorter sitemap sweep, cache slice and DNS budget).
     * Returns ['state' => 'done'|'busy'|'skipped', 'tasks' => [name => result], 'ms' => int, ...]; never throws.
     */
    public static function scale($root, $trigger = 'cli', $budgetSec = self::SCALE_BUDGET, array $opt = [])
    {
        $t0 = microtime(true);
        try {
            $root = rtrim(str_replace('\\', '/', (string)$root), '/') . '/';
            self::loadScaleClasses($root);
            ScaleTier::reset($root);
            $dir = ScaleTier::runtime('scale');
            $now = isset($opt['now']) ? (int)$opt['now'] : time();
            if ($trigger === 'cron' || $trigger === 'callback') { self::touchFile($dir . 'cron.seen', $now); }
            if (!is_file($root . 'application/data/install/install.lock')) { return ['state' => 'skipped', 'why' => 'not installed']; }
            $gateHeld = class_exists(__NAMESPACE__ . '\RequestGate', false) ? RequestGate::held() : 0;
            $lock = @fopen($dir . 'hk.lock', 'c');
            if (!$lock) { return ['state' => 'skipped', 'why' => 'lock file']; }
            if (ScaleTier::lock($lock, LOCK_EX, 0) === false) { @fclose($lock); return ['state' => 'busy']; }
            try {
                return self::scaleRun($root, $dir, $trigger, max(0.5, (float)$budgetSec), $opt, $now, $t0, $gateHeld);
            } finally {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        } catch (\Throwable $e) {
            return ['state' => 'error', 'error' => get_class($e), 'ms' => (int)round((microtime(true) - $t0) * 1000)];
        }
    }

    private static function scaleRun($root, $dir, $trigger, $budgetSec, array $opt, $now, $t0, $gateHeld)
    {
        $deadline = $t0 + $budgetSec;
        $only = isset($opt['tasks']) && is_array($opt['tasks']) ? array_flip($opt['tasks']) : null;
        $want = function ($task) use ($only) { return $only === null || isset($only[$task]); };
        // 'visitor' = a page view's short slice (edgeRun without finish-request: the visitor waits for it); 'inline' = the
        // response was not finished first (also the cron URL / schedule callback): no full builds, shorter passes
        $visitor = !empty($opt['visitor']);
        $inline = !empty($opt['inline']) || $visitor;
        // web runs (page view, cron URL, schedule callback) live under a PHP time limit: every statement of this run's
        // connection ends by the slice deadline + CAP_GRACE (statement limit and lock waits at the time left: verify r2
        // R2-8 / R2-9); the command line (cron, tools) works without limits
        $web = !(PHP_SAPI === 'cli' && in_array($trigger, ['cron', 'cli'], true));
        $state = ScaleTier::readJson($dir . 'hk.json');
        $state = is_array($state) ? $state : [];
        $tasks = [];
        $more = false;   // work left (an inline slice asks for the next claim sooner)
        // the next run is asked for now, so a crash or a long run never stops the page-view trigger for good
        self::touchFile($dir . 'hk.due', $now + self::SCALE_GAP);
        $pdo = ScaleSchema::connect(true);
        $pre = ScaleSchema::configPrefix();
        $cap = function ($until = null) use (&$pdo, $web, $deadline) {
            if ($pdo !== null && $web) { ScaleSchema::capSession($pdo, $until === null ? $deadline + self::CAP_GRACE : (float)$until); }
        };
        if ($pdo === null) {
            $tasks['db'] = 'unavailable';
        } else {
            $cap();
            if (!ScaleSchema::tablesReady()) {
                $tasks['tables'] = ScaleSchema::ensureTables($pdo, $pre)['ok'] ?? false;
                // a new schema version (ScaleSchema::VERSION 4: lane S round-6 search tables / columns) is also the search
                // schema's turn, as at the first admin visit: this run may come first and write the version flag
                $ft = __NAMESPACE__ . '\FtIndex';
                if (class_exists($ft) && method_exists($ft, 'ensureSchema') && method_exists($ft, 'useConnection')) {
                    try {
                        $ft::useConnection($pdo, $pre, (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: ''));
                        if (method_exists($ft, 'useRuntime')) { $ft::useRuntime($root . 'runtime'); }
                        $ft::ensureSchema();
                        $tasks['search_schema'] = 'ensured';
                    } catch (\Throwable $e) {
                        $tasks['search_schema'] = ['state' => 'error', 'error' => get_class($e)];
                    }
                }
            }
            if ($want('fold')) {
                // a web run gives the fold at most FOLD_SHARE of its budget: on a slow host a full hit buffer (200,000 rows of
                // random pages) takes many slices, and the page maps, counts and sitemap blocks must move on meanwhile (R2-9
                // re-check: a 1-core / 128 MB host folded 2,500 rows per 1.2 s visitor slice and nothing else ran)
                $foldUntil = $web ? min($deadline, $t0 + max(0.3, ($deadline - $t0) * self::FOLD_SHARE)) : null;
                $tasks['fold'] = HitBuffer::fold($pdo, $pre, ['now' => $now, 'deadline' => $foldUntil,
                    'chunk' => $visitor ? HitBuffer::CHUNK_INLINE : HitBuffer::CHUNK]);
                $more = $more || (is_array($tasks['fold']) && ($tasks['fold']['state'] ?? '') === 'partial');
            }
            // H3 deep-page maps, the incremental part FIRST (verify r2 N11 / N17): the page-map refresh and the search
            // term-map append run before the counts recount and before any full rank build, so the rows a run finds new reach
            // deep list pages and deep search pages in that same run (they used to wait behind counts, sitemap and search work
            // until the 20 s budget was gone)
            $deep = isset($state['deep']) && is_array($state['deep']) ? $state['deep'] : [];
            $deepCtx = ['now' => $now, 'deadline' => $deadline, 'web' => $web, 'heavy' => !$inline, 'inline' => $visitor,
                'state_file' => $dir . 'hk.json', 'state_all' => $state] + array_intersect_key($opt, ['full' => 1, 'busy' => 1]);
            $light = null;
            if ($want('deep') && microtime(true) < $deadline) {
                $cap();
                $light = self::deepLight($pdo, $pre, $root, $deepCtx, $deep);
                $tasks['deep'] = $light;
                $more = $more || empty($light['append_done']);
            }
            if ($want('counts') && microtime(true) < $deadline) {
                // web runs: resumable slices within the budget (verify F12); the command line counts in one go
                $cap();
                $r = ScaleCounts::runIfDue($pdo, $pre, $web ? $deadline : null);
                $tasks['counts'] = $r === null ? 'not_due' : $r;
                $more = $more || (is_array($r) && in_array((string)($r['state'] ?? ''), ['partial', 'counting'], true));
            }
            if ($want('sitemap') && microtime(true) < $deadline) {
                $sm = Sitemap::bucketState();
                if (empty($sm['built']) || !empty($sm['pending']) || !empty($sm['lang_pending']) || $now - (int)($state['at']['sitemap'] ?? 0) >= self::SCALE_GAP - 5 || isset($opt['tasks'])) {
                    $cap();
                    // verify r3 R3-8: the command line (cron) pauses after each statement on a shared host (Sitemap::pauseShare);
                    // web slices are bounded by their budget
                    $tasks['sitemap'] = Sitemap::refreshBuckets($pdo, $pre, min($deadline, microtime(true) + 10), ['now' => $now,
                        'sweep_seconds' => $visitor ? 0.2 : ($inline ? 0.5 : Sitemap::SWEEP_SECONDS),
                        'chunk' => $visitor ? Sitemap::INLINE_CHUNK : ($web ? Sitemap::WEB_CHUNK : Sitemap::BUILD_CHUNK),
                        'lang_rows' => $web ? Sitemap::LANG_ROWS_WEB : Sitemap::LANG_ROWS,
                        'pause' => $web ? 0.0 : Sitemap::pauseShare(),
                        // flood-r4 F3 / G9: the buckets' rows lists are kept while their rows are read (never in a visitor's slice)
                        'rows' => !$visitor]);
                    $state['at']['sitemap'] = $now;
                    $smState = is_array($tasks['sitemap']) ? (string)($tasks['sitemap']['state'] ?? '') : '';
                    $more = $more || in_array($smState, ['building', 'partial'], true) || (int)($tasks['sitemap']['lang_pending'] ?? 0) > 0;
                } else {
                    $tasks['sitemap'] = 'not_due';
                }
                // G9 (verify r2 R2-12, verify r3 N20): the video files of changed buckets (default and language files, and the
                // files a crawler was answered from an older copy) are built here, after the bucket refresh, where the framework
                // is loaded (a page view that went through it, the command line with the front-end boot of tools/housekeeping.php);
                // a crawler then gets the stored file instead of waiting for the build. On a host where no other run happened for
                // PREBUILD_VISITOR_GAP (no cron, no finish-request) a visitor slice builds one file: a wanted one, else one that
                // was never built (site-r3 N20: newest bucket first, default-language files first), when its estimated build
                // time fits into the slice
                if (method_exists(Sitemap::class, 'prebuild') && microtime(true) < $deadline && Sitemap::canPrebuild()) {
                    if (!$visitor) {
                        if (is_array($tasks['sitemap']) || Sitemap::wantedFiles()) {
                            $tasks['sitemap_files'] = Sitemap::prebuild(min($deadline, microtime(true) + ($web ? 6 : 12)));
                        }
                    } elseif ($now - (int)($state['nonvisitor_at'] ?? 0) >= self::PREBUILD_VISITOR_GAP) {
                        $tasks['sitemap_files'] = Sitemap::prebuild($deadline, ['max' => Sitemap::VISITOR_FILES, 'visitor' => true, 'guard' => true]);
                        // the next slice in SCALE_CLAIM only while slices make progress (a file that never fits waits for cron)
                        $sf = is_array($tasks['sitemap_files']) ? $tasks['sitemap_files'] : [];
                        $more = $more || ((int)($sf['built'] ?? 0) > 0 && (int)($sf['left'] ?? 0) > 0);
                    }
                }
                // flood-r4 F3 / G9: rows lists for the buckets that have none (a bucket table built before the lists existed, buckets
                // a visitor slice rebuilt): a never-built video file is then answered from one file read instead of ~3,000 table
                // pages. At most every SITEMAP_ROWS_GAP while the last pass found nothing missing; again in the next run while one
                // was partial. Never in a visitor's slice (the run after a finished response, cron, the command line)
                if (!$visitor && method_exists(Sitemap::class, 'rowsBackfill') && microtime(true) < $deadline - 1 && is_array($tasks['sitemap'] ?? null)
                    && (!empty($state['rows_partial']) || $now - (int)($state['at']['sitemap_rows'] ?? 0) >= self::SITEMAP_ROWS_GAP)) {
                    $cap();
                    $rb = Sitemap::rowsBackfill($pdo, $pre, min($deadline - 0.5, microtime(true) + ($web ? self::ROWS_BACKFILL_WEB : self::ROWS_BACKFILL_CLI)),
                        ['pause' => $web ? 0.0 : Sitemap::pauseShare()]);
                    $tasks['sitemap_rows'] = $rb;
                    if ((string)($rb['state'] ?? '') !== 'busy') { $state['at']['sitemap_rows'] = $now; }
                    $state['rows_partial'] = in_array((string)($rb['state'] ?? ''), ['partial', 'busy'], true) ? 1 : 0;
                    $more = $more || !empty($state['rows_partial']);
                }
            }
            if ($want('ft') && microtime(true) < $deadline - ($visitor ? 0.3 : 0)) {
                $ft = __NAMESPACE__ . '\FtIndex';
                if (class_exists($ft) && method_exists($ft, 'housekeeping') && method_exists($ft, 'useConnection')) {
                    try {
                        $cap();
                        $ft::useConnection($pdo, $pre, (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: ''));
                        if (method_exists($ft, 'useRuntime')) { $ft::useRuntime($root . 'runtime'); }
                        $left = (int)floor($deadline - microtime(true));
                        $tasks['ft'] = $ft::housekeeping(max(1, min(5, $left)));
                    } catch (\Throwable $e) {
                        $tasks['ft'] = ['state' => 'error', 'error' => get_class($e)];
                    }
                } else {
                    $tasks['ft'] = 'not_installed';
                }
            }
            // ft-vocab (fp-port, FilterMatch v2): the filter vocabulary's first build, its rebuild after a stale mark, the daily
            // rebuild and a backlog left by a bulk import, one slice per run on this run's connection and the site's lock name
            // (a visitor slice reads one batch of 500 videos, about 10 ms; other runs read until the deadline)
            if ($want('ft_vocab') && microtime(true) < $deadline - ($visitor ? 0.3 : 0)) {
                $fv = __NAMESPACE__ . '\FilterVocab';
                if (class_exists($fv) && method_exists($fv, 'housekeepPdo')) {
                    $cap();
                    $r = $fv::housekeepPdo($pdo, $pre, $root, $deadline, $visitor);
                    $tasks['ft_vocab'] = $r === false ? 'not_due' : $r;
                    $more = $more || in_array($r, ['building', 'pending', 'locked', 'wait'], true);
                } else {
                    $tasks['ft_vocab'] = 'not_installed';
                }
            }
            if ($want('deep') && is_array($light) && ($light['state'] ?? '') === 'done') {
                // H3 heavy part: time_add rank append, then one full rank build (only once the term-map append caught up, so
                // a 17 s build never starves the append again), the term map build when it is missing
                $heavy = self::deepHeavy($pdo, $pre, $root, $deepCtx + ['cap' => $cap], $deep, !empty($light['append_done']));
                $tasks['deep'] = array_merge($light, $heavy);
                $cap();
            }
            if (isset($tasks['deep'])) { $state['deep'] = $deep; }
            // FULLTEXT index sizes (owner decision 2026-10-17): the auxiliary tables are not in TABLES.DATA_LENGTH /
            // INDEX_LENGTH, so the measured total is cached daily for ScaleSchema::ftBytes() (StorageGuard, estimates)
            if ($want('ftsize') && !$visitor && microtime(true) < $deadline) {
                if ($now - (int)($state['at']['ftsize'] ?? 0) >= 86400 || isset($opt['tasks'])) {
                    $cap();
                    $sz = ScaleSchema::ftSizes($pdo, $pre);
                    $tasks['ftsize'] = $sz === null ? 'unavailable' : (int)array_sum($sz);
                    $state['at']['ftsize'] = $now;
                } else {
                    $tasks['ftsize'] = 'not_due';
                }
            }
        }
        if ($want('edge') && !$visitor && microtime(true) < $deadline) {
            if ($now - (int)($state['at']['edge'] ?? 0) >= self::EDGE_EVICT_GAP - 5 || isset($opt['tasks'])) {
                $tasks['edge'] = EdgeCache::evict();
                $state['at']['edge'] = $now;
            } else {
                $tasks['edge'] = 'not_due';
            }
        }
        // FR-3 (decision O-29): RequestGate's in-flight places are one small lock file per visitor key and kind; the files not taken
        // or created for RequestGate::SWEEP_AGE seconds go here (no database, no lock taken; every run kind, a visitor's slice only a
        // few milliseconds, so a host without cron and without finish-request sweeps too)
        if ($want('gate') && microtime(true) < $deadline && method_exists(RequestGate::class, 'sweep')) {
            $gap = !empty($state['gate_partial']) ? self::SCALE_CLAIM : self::GATE_SWEEP_GAP;
            if ($now - (int)($state['at']['gate'] ?? 0) >= $gap - 5 || isset($opt['tasks'])) {
                $sec = $visitor ? self::GATE_SWEEP_VISITOR_SEC : ($inline ? self::GATE_SWEEP_INLINE_SEC : self::GATE_SWEEP_SEC);
                $files = $visitor ? self::GATE_SWEEP_VISITOR_FILES : ($inline ? self::GATE_SWEEP_INLINE_FILES : self::GATE_SWEEP_FILES);
                $tasks['gate'] = RequestGate::sweep(min($deadline, microtime(true) + $sec), $files);
                $state['at']['gate'] = $now;
                $state['gate_partial'] = (string)($tasks['gate']['state'] ?? '') === 'partial' ? 1 : 0;
            } else {
                $tasks['gate'] = 'not_due';
            }
        }
        if ($want('cache') && microtime(true) < $deadline) {
            $tasks['cache'] = self::scaleCachePass($root, $deadline, $visitor ? 200 : ($inline ? 300 : 2000));
        }
        // network refreshes never run inside a visitor's request (a 5 s fetch would be the visitor's wait)
        if ($want('cf') && !$visitor && microtime(true) < $deadline && empty($opt['no_network'])) {
            $last = (int)($state['at']['cf'] ?? 0);
            $ok = (int)($state['cf_ok_at'] ?? 0);
            if ($now - $ok >= self::CF_REFRESH_GAP && $now - $last >= self::CF_RETRY_GAP) {
                $fetch = isset($opt['cf_fetch']) && is_callable($opt['cf_fetch']) ? $opt['cf_fetch'] : [__CLASS__, 'httpsGet'];
                $r = PublicRequestGuard::refreshCloudflareRanges($root, $fetch);
                $tasks['cf'] = $r;
                $state['at']['cf'] = $now;
                if (!empty($r['ok'])) { $state['cf_ok_at'] = $now; }
            } else {
                $tasks['cf'] = 'not_due';
            }
        }
        // verified search engines (owner decision 2026-10-17): the published IP range lists daily (each engine keeps its
        // last good list on failure; retried hourly), then the reverse-DNS + forward check of queued claims (engines without
        // a list, addresses not in the list yet); verdicts are cached 24 h. Both never run inside a visitor's render.
        if ($want('crawlers') && !$visitor && microtime(true) < $deadline && empty($opt['no_network'])) {
            $engines = ScaleTier::settings()['crawlers'] ?? [];
            $last = (int)($state['at']['crawlers'] ?? 0);
            $okAt = (int)($state['crawlers_ok_at'] ?? 0);
            if (is_array($engines) && $engines && (($now - $okAt >= self::CRAWLER_REFRESH_GAP && $now - $last >= self::CRAWLER_RETRY_GAP) || isset($opt['tasks']))) {
                $fetch = isset($opt['crawler_fetch']) && is_callable($opt['crawler_fetch']) ? $opt['crawler_fetch'] : [__CLASS__, 'httpsGet'];
                $r = PublicRequestGuard::refreshCrawlerRanges($root, $fetch, $engines);
                $tasks['crawlers'] = $r;
                $state['at']['crawlers'] = $now;
                $allOk = true;
                foreach ($r as $one) { if (empty($one['ok'])) { $allOk = false; } }
                if ($allOk) { $state['crawlers_ok_at'] = $now; }
            } else {
                $tasks['crawlers'] = 'not_due';
            }
        }
        if ($want('crawler_dns') && !$visitor && microtime(true) < $deadline && empty($opt['no_network'])) {
            $resolver = isset($opt['crawler_resolver']) && is_callable($opt['crawler_resolver']) ? $opt['crawler_resolver'] : null;
            $tasks['crawler_dns'] = PublicRequestGuard::resolvePendingCrawlers($root, min($deadline, microtime(true) + ($inline ? 2 : 5)), $resolver, $inline ? 3 : 50);
        }
        // verify r3 OW1 (c): a host without cron and without fastcgi / litespeed finish-request runs only visitor slices, so the
        // two network tasks above never ran there. When no other run happened for CRAWLER_VISITOR_GAP, one visitor slice
        // refreshes the list of ONE engine (the stalest that is due; HTTPS, 2 s connect / 3 s in all, the last good or shipped
        // list stays on failure, the next try an hour later); when none happened for CRAWLER_DNS_VISITOR_GAP, it resolves up
        // to CRAWLER_VISITOR_DNS queued claims within CRAWLER_VISITOR_DNS_SEC. The visitor waits for this once a day (lists)
        // and for at most 1 s (claims); the shipped lists verify the listed crawlers meanwhile.
        if ($visitor && empty($opt['no_network'])) {
            $quiet = $now - (int)($state['nonvisitor_at'] ?? 0);
            if ($want('crawlers') && $quiet >= self::CRAWLER_VISITOR_GAP && microtime(true) < $deadline) {
                $engines = ScaleTier::settings()['crawlers'] ?? [];
                $pick = self::crawlerDue($root, is_array($engines) ? $engines : [], $now, (array)($state['crawlers_v_at'] ?? []));
                if ($pick !== '') {
                    $fetch = isset($opt['crawler_fetch']) && is_callable($opt['crawler_fetch']) ? $opt['crawler_fetch']
                        : function ($url) { return self::httpsGet($url, self::CRAWLER_VISITOR_CONNECT, self::CRAWLER_VISITOR_TOTAL); };
                    $state['crawlers_v_at'][$pick] = $now;
                    $tasks['crawlers'] = PublicRequestGuard::refreshCrawlerRanges($root, $fetch, [$pick => $engines[$pick]]) + ['via' => 'visitor'];
                }
            }
            if ($want('crawler_dns') && $quiet >= self::CRAWLER_DNS_VISITOR_GAP) {
                $resolver = isset($opt['crawler_resolver']) && is_callable($opt['crawler_resolver']) ? $opt['crawler_resolver'] : null;
                $tasks['crawler_dns'] = PublicRequestGuard::resolvePendingCrawlers($root, microtime(true) + self::CRAWLER_VISITOR_DNS_SEC, $resolver, self::CRAWLER_VISITOR_DNS);
            }
        }
        if (!$visitor) {
            // every other run (cron, CLI, the run after a finished response, cron URL / schedule callback): visitor slices
            // leave the network and sitemap-file work to these while they happen
            $state['nonvisitor_at'] = $now;
        }
        if ($visitor && $more) {
            // a short slice left work (fold pass, count slices, sitemap blocks, term-map append): the next page view may claim
            // the next slice in SCALE_CLAIM seconds instead of SCALE_GAP (one visitor a minute carries <= 1.2 s)
            self::touchFile($dir . 'hk.due', $now + self::SCALE_CLAIM);
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $entry = ['at' => $now, 'trigger' => (string)$trigger, 'ms' => $ms, 'gate_held' => (int)$gateHeld, 'pid' => getmypid()];
        foreach (['fold', 'counts', 'sitemap', 'edge', 'deep'] as $k) {
            if (isset($tasks[$k])) { $entry[$k] = is_array($tasks[$k]) ? (string)($tasks[$k]['state'] ?? ($k === 'edge' ? 'evicted' : 'done')) : (string)$tasks[$k]; }
        }
        $state['history'] = array_slice(array_merge([$entry], (array)($state['history'] ?? [])), 0, self::SCALE_HISTORY);
        $state['last'] = $entry + ['tasks' => $tasks];
        $state['runs'] = (int)($state['runs'] ?? 0) + 1;
        ScaleTier::writeAtomic($dir . 'hk.json', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        return ['state' => 'done', 'trigger' => (string)$trigger, 'tasks' => $tasks, 'ms' => $ms, 'gate_held' => (int)$gateHeld];
    }

    /**
     * H3 (XVID-NOLIMIT-BRIEF B1, B2, C2): the deep-page maps of lane S, inside the scale run's budget and single-flight lock.
     * One call = deepLight() then deepHeavy() (tests, tools); Housekeeping::scaleRun() calls the two parts at different
     * places of the run (verify r2 N11 / N17: the incremental part first, the full builds last):
     *   deepLight: 1. ListPageMap::refresh(budget) every run (appends new top rows, folds dirty marks, resumable rewalks)
     *              2. FtIndex::mapAppend(FTMAP_APPEND, budget) every run once the term map exists
     *   deepHeavy: 3. ListRank::append('time_add') every run; ListRank::buildAll($sorts) for every sort that is due, plus the
     *                 sorts due within RANK_ALIGN of their interval, from ONE read of the video table (verify r3 R3-4: daily
     *                 cycle for every sort, time_add weekly, hits_day hourly only while a full read takes <= RANK_HOURLY_MAX_S),
     *                 only once the term-map append caught up (a long build never starves the append again)
     *              4. FtIndex::mapBuild() when mapState() says the term map is missing (at most once per FTMAP_RETRY)
     * Full builds (3, 4) run only when $ctx['heavy'] (command line, or a web run after its response; the PHP time limit is
     * raised to DEEP_BUILD_TIME there, and a web run's statement limit with it) and start before the deadline (a build
     * finishes once started). Skipped as a whole while StorageGuard::isFull() (measured only where the framework is loaded)
     * and, for web runs, while every deep gate slot is busy or the load average is above BUSY_LOAD x cores. Low priority:
     * proc_nice(10) on the command line. Missing classes or methods (lane S not installed) answer 'not_installed'; an
     * exception is one task's 'error'.
     * $ctx: now, deadline, web, heavy, inline (a visitor request's short slice: small append), cap (callable(until) setting
     * the statement limit of web runs), state_file (hk.json: the build attempt is saved there BEFORE a build, so a build
     * that is killed is not retried on every run), state_all (whole hk.json for that save), full / busy (tests).
     * $deep (in/out): ['rank' => [sort => last attempt], 'rank_ok' => [sort => last success], 'rank_read_s' => seconds of the
     * last full read (shared copy + one sort), 'ftmap_try' => int].
     */
    public static function deepMaps(?\PDO $pdo, string $pre, string $root, array $ctx, array &$deep): array
    {
        $light = self::deepLight($pdo, $pre, $root, $ctx, $deep);
        if (($light['state'] ?? '') !== 'done') {
            return $light;
        }
        return array_merge($light, self::deepHeavy($pdo, $pre, $root, $ctx, $deep, !empty($light['append_done'])));
    }

    /**
     * The incremental part of the deep-page maps (page-map refresh, term-map append), run before counts, sitemap and any
     * full build. Returns ['state' => 'done'|'skipped', 'pagemap' => ..., 'ftmap' => ['do' => 'append', 'docs' => n] |
     * 'missing' | ..., 'append_done' => bool (false while the append hit its document limit: more docs wait)].
     */
    public static function deepLight(?\PDO $pdo, string $pre, string $root, array $ctx, array &$deep): array
    {
        $deadline = (float)($ctx['deadline'] ?? (microtime(true) + 5));
        $web = !empty($ctx['web']);
        $inline = !empty($ctx['inline']);
        $full = array_key_exists('full', $ctx) ? (bool)$ctx['full'] : self::storageFull();
        if ($full) { return ['state' => 'skipped', 'why' => 'storage_full', 'append_done' => true]; }
        // a busy server (every deep gate slot taken, or load above BUSY_LOAD x cores) still gets the incremental refresh,
        // with a quarter of the budget (verify r2 N17: under a deep-page flood the maps were never refreshed); only the full
        // builds of deepHeavy() wait for a quiet moment
        $busy = array_key_exists('busy', $ctx) ? (bool)$ctx['busy'] : ($web && self::serverBusy());
        self::lowPriority();
        $out = ['state' => 'done', 'append_done' => true];
        if ($busy) { $out['busy'] = true; }
        // 1. page maps (inline: what fits into the visitor's short slice, at most half of what is left)
        $pm = __NAMESPACE__ . '\ListPageMap';
        if (class_exists($pm) && method_exists($pm, 'refresh')) {
            try {
                self::bindDeep($pm, $pdo, $pre, $root);
                $budget = $inline ? max(0.2, min(0.5, ($deadline - microtime(true)) / 2)) : self::left($deadline, $busy ? 2.5 : 10.0);
                $out['pagemap'] = self::brief($pm::refresh($budget));
            } catch (\Throwable $e) {
                $out['pagemap'] = ['state' => 'error', 'error' => get_class($e)];
            }
        } else {
            $out['pagemap'] = 'not_installed';
        }
        // 2. search term map: append the documents added since the last run (the build of a missing map is deepHeavy's)
        $ft = __NAMESPACE__ . '\FtIndex';
        if (class_exists($ft) && method_exists($ft, 'mapState') && (method_exists($ft, 'mapAppend') || method_exists($ft, 'mapBuild'))) {
            try {
                if ($pdo !== null && method_exists($ft, 'useConnection')) {
                    $ft::useConnection($pdo, $pre, (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: ''));
                    if (method_exists($ft, 'useRuntime')) { $ft::useRuntime($root . 'runtime'); }
                }
                $ms = (array)$ft::mapState();
                if (self::mapMissing($ms)) {
                    $out['ftmap'] = 'missing';
                } elseif (method_exists($ft, 'mapAppend') && microtime(true) < $deadline) {
                    $limit = $inline ? self::FTMAP_APPEND_INLINE : ($busy ? intdiv(self::FTMAP_APPEND, 4) : self::FTMAP_APPEND);
                    $docs = (int)$ft::mapAppend($limit, $inline ? max(0.2, min(0.5, $deadline - microtime(true))) : self::left($deadline, 5.0));
                    $out['ftmap'] = ['do' => 'append', 'docs' => $docs];
                    // the append hit its document limit: more new documents wait (no full rank build in this run)
                    $out['append_done'] = $docs < $limit;
                } else {
                    $out['ftmap'] = 'next_run';
                    $out['append_done'] = false;
                }
            } catch (\Throwable $e) {
                $out['ftmap'] = ['state' => 'error', 'error' => get_class($e)];
            }
        } else {
            $out['ftmap'] = 'not_installed';
        }
        return $out;
    }

    /**
     * The full-build part of the deep-page maps, after counts, sitemap and search work: the time_add rank append, the rank
     * files of every due sort from one read of the video table ($appendDone: only once the term-map append caught up; verify
     * r3 R3-4), the term map build when it is missing.
     * Returns ['rank_append' => ..., 'rank_build' => 'not_due' | 'deferred' | 'after_append' | 'next_run' | ['sorts' => 'a,b',
     * 'sort' => first, 'built' => n, 'failed' => 'c', 'state' => 'done' | 'partial' | 'error', 'shared' => bool, 'copy_s',
     * 'read_s', 'ms'], 'ftmap' => (only when the map is missing) ...].
     */
    public static function deepHeavy(?\PDO $pdo, string $pre, string $root, array $ctx, array &$deep, bool $appendDone = true): array
    {
        $now = (int)($ctx['now'] ?? time());
        $deadline = (float)($ctx['deadline'] ?? (microtime(true) + 5));
        $web = !empty($ctx['web']);
        // full builds in a web run wait while the server is busy (deep gate slots taken, load above BUSY_LOAD x cores)
        $busy = array_key_exists('busy', $ctx) ? (bool)$ctx['busy'] : ($web && self::serverBusy());
        $heavy = !empty($ctx['heavy']) && !$busy;
        $cap = isset($ctx['cap']) && is_callable($ctx['cap']) ? $ctx['cap'] : null;
        $out = [];
        $save = function () use (&$deep, $ctx) {
            if (empty($ctx['state_file'])) { return; }
            $all = isset($ctx['state_all']) && is_array($ctx['state_all']) ? $ctx['state_all'] : [];
            $all['deep'] = $deep;
            ScaleTier::writeAtomic((string)$ctx['state_file'], json_encode($all, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        };
        // a full build in a web run lives under the raised time limit: its statements get that much time, not the slice's
        $buildLimit = function () use ($cap, $web) {
            if ($cap !== null && $web) { $cap(microtime(true) + self::DEEP_BUILD_TIME - 30); }
        };
        // 3. rank files
        $lr = __NAMESPACE__ . '\ListRank';
        if (class_exists($lr) && method_exists($lr, 'build')) {
            try {
                self::bindDeep($lr, $pdo, $pre, $root);
                if (method_exists($lr, 'append') && microtime(true) < $deadline) { $out['rank_append'] = self::brief($lr::append('time_add')); }
            } catch (\Throwable $e) {
                $out['rank_append'] = ['state' => 'error', 'error' => get_class($e)];
            }
            $sorts = self::rankDueSet(isset($deep['rank']) && is_array($deep['rank']) ? $deep['rank'] : [], $now, $deep);
            if (!$sorts) {
                $out['rank_build'] = 'not_due';
            } elseif (!$heavy) {
                $out['rank_build'] = 'deferred';
            } elseif (!$appendDone) {
                $out['rank_build'] = 'after_append';
            } elseif (microtime(true) >= $deadline) {
                $out['rank_build'] = 'next_run';
            } else {
                // the attempts are recorded first: a build killed by a host time limit waits RANK_RETRY, each sort's own
                // interval starts only after its success
                foreach ($sorts as $sort) {
                    $deep['rank'][$sort] = $now - self::rankEvery($sort, $deep) + self::RANK_RETRY;
                }
                $save();
                self::raiseTimeLimit($web);
                $buildLimit();
                // engines-r3 R3-8: the full read keeps the database busy: expired cached pages are answered stale meanwhile
                ScaleTier::heavyMark('rank');
                try {
                    $out['rank_build'] = self::rankBuild($lr, $sorts, $now, $deep);
                } finally {
                    ScaleTier::heavyClear('rank');
                }
            }
        } else {
            $out['rank'] = 'not_installed';
        }
        // 4. search term map build (only while it is missing; the append ran in deepLight)
        $ft = __NAMESPACE__ . '\FtIndex';
        if (class_exists($ft) && method_exists($ft, 'mapState') && method_exists($ft, 'mapBuild')) {
            try {
                $ms = (array)$ft::mapState();
                if (self::mapMissing($ms)) {
                    if (!$heavy) {
                        $out['ftmap'] = 'deferred';
                    } elseif ($now - (int)($deep['ftmap_try'] ?? 0) < self::FTMAP_RETRY) {
                        $out['ftmap'] = 'retry_later';
                    } elseif (microtime(true) >= $deadline) {
                        $out['ftmap'] = 'next_run';
                    } elseif (!($mem = self::mapMemory($web, $ft))['ok']) {
                        // engines-r7 NEW-2: the PHP memory limit stays too low for the build: skipped (no fatal error, no
                        // dead build, no heavy marker left behind); the Speed page reads deep.ftmap_mem
                        $out['ftmap'] = ['state' => 'skipped', 'why' => 'memory', 'limit_mb' => $mem['limit_mb'], 'need_mb' => $mem['need_mb']];
                        $deep['ftmap_mem'] = ['at' => $now, 'limit_mb' => $mem['limit_mb'], 'need_mb' => $mem['need_mb'], 'web' => $web ? 1 : 0];
                    } else {
                        $deep['ftmap_try'] = $now;
                        unset($deep['ftmap_mem']);
                        $save();
                        self::raiseTimeLimit($web);
                        $buildLimit();
                        ScaleTier::heavyMark('ftmap');
                        self::guardFatal('ftmap');
                        try {
                            $out['ftmap'] = ['do' => 'build'] + self::brief($ft::mapBuild());
                        } finally {
                            ScaleTier::heavyClear('ftmap');
                            self::guardFatal('ftmap', false);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $out['ftmap'] = ['state' => 'error', 'error' => get_class($e)];
            }
        }
        return $out;
    }

    /**
     * verify r3 R3-4: the rank files of $sorts from ONE read of the video table (ListRank::buildAll; a lane S build without it:
     * one build per sort). Every sort that succeeded gets its new time ('rank' and 'rank_ok'); a failed or busy sort keeps
     * the attempt time set before the build (retried after RANK_RETRY). The seconds of the full read (the shared copy plus the
     * slowest sort, or the slowest single build) are kept as 'rank_read_s': they decide whether hits_day may stay hourly
     * (rankEvery). Never throws.
     */
    private static function rankBuild(string $lr, array $sorts, int $now, array &$deep): array
    {
        $t = microtime(true);
        $out = ['sorts' => implode(',', $sorts), 'sort' => (string)($sorts[0] ?? ''), 'built' => 0, 'failed' => '', 'state' => 'error',
            'shared' => false, 'copy_s' => 0.0, 'read_s' => 0.0, 'ms' => 0];
        try {
            $results = [];
            if (method_exists($lr, 'buildAll')) {
                $all = (array)$lr::buildAll($sorts);
                $results = isset($all['sorts']) && is_array($all['sorts']) ? $all['sorts'] : [];
                $out['shared'] = !empty($all['shared']);
                $out['copy_s'] = round((float)($all['copy_seconds'] ?? 0), 2);
                if (!empty($all['copy_error'])) { $out['copy_error'] = substr((string)$all['copy_error'], 0, 120); }
            } else {
                foreach ($sorts as $sort) {
                    try { $results[$sort] = (array)$lr::build($sort); }
                    catch (\Throwable $e) { $results[$sort] = ['state' => 'error', 'error' => get_class($e)]; }
                }
            }
            $built = [];
            $failed = [];
            $slowest = 0.0;
            foreach ($sorts as $sort) {
                $r = $results[$sort] ?? null;
                $bad = !is_array($r) || in_array((string)($r['state'] ?? ''), ['error', 'busy', 'failed'], true);
                if ($bad) {
                    $failed[] = $sort;
                    if (is_array($r) && !isset($out['error']) && !empty($r['error'])) { $out['error'] = substr((string)$r['error'], 0, 160); }
                    continue;
                }
                $deep['rank'][$sort] = $now;
                $deep['rank_ok'][$sort] = $now;
                $built[] = $sort;
                $slowest = max($slowest, (float)($r['seconds'] ?? 0));
            }
            $out['built'] = count($built);
            $out['failed'] = implode(',', $failed);
            $out['state'] = $failed ? ($built ? 'partial' : 'error') : 'done';
            if ($built) {
                $read = ($out['shared'] ? $out['copy_s'] : 0.0) + $slowest;
                $out['read_s'] = round($read, 2);
                $deep['rank_read_s'] = round($read, 2);
            }
        } catch (\Throwable $e) {
            $out['state'] = 'error';
            $out['error'] = get_class($e);
        }
        $out['ms'] = (int)round((microtime(true) - $t) * 1000);
        return $out;
    }

    /**
     * The interval of one rank sort: RANK_SORTS, or the shorter RANK_HOURLY cycle while the last measured full read
     * ($deep['rank_read_s']) took at most RANK_HOURLY_MAX_S (unknown before the first build: the daily cycle).
     */
    public static function rankEvery(string $sort, array $deep = []): int
    {
        $every = (int)(self::RANK_SORTS[$sort] ?? 86400);
        if (isset(self::RANK_HOURLY[$sort], $deep['rank_read_s']) && is_numeric($deep['rank_read_s'])
            && (float)$deep['rank_read_s'] <= self::RANK_HOURLY_MAX_S) {
            $every = min($every, (int)self::RANK_HOURLY[$sort]);
        }
        return $every;
    }

    /**
     * Pure (verify r3 R3-4): the rank sorts to build now from one read of the video table ($at = sort => last attempt /
     * success time). Empty when no sort is due; else every sort that is due (never built, or its interval passed) plus every
     * sort due within RANK_ALIGN of its own interval (it joins this read instead of causing its own one later), in RANK_SORTS
     * order.
     */
    public static function rankDueSet(array $at, int $now, array $deep = []): array
    {
        $due = false;
        $set = [];
        foreach (self::RANK_SORTS as $sort => $daily) {
            $every = self::rankEvery($sort, $deep);
            if (!isset($at[$sort])) {
                $due = true;
                $set[] = $sort;
                continue;
            }
            $age = $now - (int)$at[$sort];
            if ($age >= $every) {
                $due = true;
                $set[] = $sort;
            } elseif ($age >= (int)ceil($every * (1 - self::RANK_ALIGN))) {
                $set[] = $sort;
            }
        }
        return $due ? $set : [];
    }

    /**
     * Pure: the most overdue single rank sort ($at = sort => last attempt / success time), or null when none is due. A sort
     * never built (no entry) comes first in RANK_SORTS order. Kept for callers that report one sort; the build itself uses
     * rankDueSet() (one read for every due sort).
     */
    public static function rankDue(array $at, int $now, array $deep = []): ?string
    {
        $best = null;
        $bestLate = 0;
        foreach (self::RANK_SORTS as $sort => $daily) {
            if (!isset($at[$sort])) { return $sort; }
            $late = $now - (int)$at[$sort] - self::rankEvery($sort, $deep);
            if ($late >= 0 && ($best === null || $late > $bestLate)) { $best = $sort; $bestLate = $late; }
        }
        return $best;
    }

    /**
     * engines-r7 NEW-2: room in PHP's memory for FtIndex::mapBuild(). Raises the limit first: the command line to
     * SEARCH_STATS_MEMORY (raiseMemory()), a web run (after its response) to FTMAP_WEB_MEMORY when lower; never lowered, -1
     * stays. Then needs the limit to leave mapNeedMb() above what this process uses already (a host may refuse the raise:
     * php_admin_value, PHP 8.5's max_memory_limit). Returns ['ok' => bool, 'limit_mb' => the limit now in MB (-1 = none),
     * 'need_mb' => int]. Never throws (an unreadable limit counts as room enough: the build decides, as before).
     */
    private static function mapMemory(bool $web, string $ft): array
    {
        try {
            $docs = 0;
            if (method_exists($ft, 'state')) {
                $docs = (int)(((array)$ft::state(true))['docs'] ?? 0);
            }
            $need = self::mapNeedMb($docs);
            if (!function_exists('ini_set')) {
                // disable_functions: the limit stays as the host set it
            } elseif ($web) {
                $n = self::memoryBytes((string)ini_get('memory_limit'));
                if ($n > 0 && $n < self::FTMAP_WEB_MEMORY) {
                    @ini_set('memory_limit', (string)intdiv(self::FTMAP_WEB_MEMORY, 1048576) . 'M');
                }
            } else {
                self::raiseMemory();
            }
            $limit = self::memoryBytes((string)ini_get('memory_limit'));
            if ($limit <= 0) {
                return ['ok' => true, 'limit_mb' => -1, 'need_mb' => $need];
            }
            $room = $limit - memory_get_usage(true);
            return ['ok' => $room >= $need * 1048576, 'limit_mb' => intdiv($limit, 1048576), 'need_mb' => $need];
        } catch (\Throwable $e) {
            return ['ok' => true, 'limit_mb' => -1, 'need_mb' => self::FTMAP_MEM_MAX_MB];
        }
    }

    /** Pure (engines-r7 NEW-2): MB of PHP memory the term-map build of $docs search documents needs at most. */
    public static function mapNeedMb(int $docs): int
    {
        $mb = self::FTMAP_MEM_BASE_MB + (int)ceil(self::FTMAP_MEM_PER_MDOC_MB * max(0, $docs) / 1000000);
        return (int)min(self::FTMAP_MEM_MAX_MB, $mb);
    }

    /** Pure: a memory_limit value in bytes ('128M', '1G', '262144K', '134217728'); 0 = none / unreadable, -1 = no limit. */
    public static function memoryBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^(-?[0-9]+)\s*([kmgKMG]?)$/D', $v, $m)) {
            return 0;
        }
        $n = (int)$m[1];
        if ($n < 0) {
            return -1;
        }
        $u = strtolower($m[2]);
        return $n * ($u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1)));
    }

    /**
     * engines-r7 NEW-2: heavy-maintenance marker $what is set for a build of this process ($on), or the build ended ($on false).
     * The first call registers onFatal() as a shutdown function: after a fatal error (memory limit, time limit) the finally
     * blocks do not run, so it clears the markers still set (expired pages are no longer answered stale for the marker's hour
     * and page views may start housekeeping again).
     */
    private static function guardFatal(string $what, bool $on = true): void
    {
        if (!$on) {
            unset(self::$fatalMarks[$what]);
            return;
        }
        if (self::$fatalReserve === null) {
            self::$fatalReserve = str_repeat(' ', 262144);
            register_shutdown_function([__CLASS__, 'onFatal']);
        }
        self::$fatalMarks[$what] = true;
    }

    /** Shutdown function (guardFatal()): clears the heavy markers of a build that never reached its finally. Never throws. */
    public static function onFatal(): void
    {
        self::$fatalReserve = '';
        if (!self::$fatalMarks) {
            return;
        }
        try {
            $limit = self::memoryBytes((string)ini_get('memory_limit'));
            if ($limit > 0 && function_exists('ini_set')) {
                // a memory-limit error leaves the process at its limit: a little more room for the clean-up
                @ini_set('memory_limit', (string)(intdiv($limit, 1048576) + 32) . 'M');
            }
            foreach (array_keys(self::$fatalMarks) as $what) {
                ScaleTier::heavyClear((string)$what);
            }
        } catch (\Throwable $e) {
        }
        self::$fatalMarks = [];
    }

    /** Pure: the term map is missing per FtIndex::mapState() (E's read contract: ready, or a building / pct state). */
    public static function mapMissing(array $ms): bool
    {
        if (!empty($ms['ready'])) { return false; }
        if (!empty($ms['building']) || in_array((string)($ms['state'] ?? ''), ['building', 'running'], true)) { return false; }
        return true;
    }

    /** Seconds left before $deadline, at least 0.5, at most $max. */
    private static function left($deadline, $max)
    {
        return (float)max(0.5, min((float)$max, $deadline - microtime(true)));
    }

    /** Scalar fields of a lane S result (hk.json stays small). */
    private static function brief($r)
    {
        if (!is_array($r)) { return is_scalar($r) || $r === null ? ['result' => $r] : []; }
        $o = [];
        foreach ($r as $k => $v) {
            if (is_scalar($v) || $v === null) { $o[(string)$k] = $v; }
            if (count($o) >= 16) { break; }
        }
        return $o;
    }

    /** Lane S classes run here without the framework: they get the housekeeping connection (no statement limit). */
    private static function bindDeep($cls, $pdo, $pre, $root)
    {
        if ($pdo !== null && method_exists($cls, 'useConnection')) { $cls::useConnection($pdo, $pre); }
        if (method_exists($cls, 'useRuntime')) { $cls::useRuntime($root . 'runtime'); }
    }

    /** StorageGuard::isFull(); without the framework only the stored measurement is read (never measured: no think\Db). */
    private static function storageFull()
    {
        $sg = __NAMESPACE__ . '\StorageGuard';
        if (!class_exists($sg)) { return false; }
        $framework = class_exists('\think\Db', false) && function_exists('config');
        try {
            if (!$framework) { $sg::allowMeasure(false); }
            return (bool)$sg::isFull();
        } catch (\Throwable $e) {
            return false;
        } finally {
            if (!$framework) { $sg::allowMeasure(null); }
        }
    }

    /** Web runs yield to visitors: every deep gate slot taken, or the load average above BUSY_LOAD x cores. */
    private static function serverBusy()
    {
        try {
            if (class_exists(__NAMESPACE__ . '\RequestGate') && RequestGate::deepBusy()) { return true; }
            if (function_exists('sys_getloadavg')) {
                $load = @sys_getloadavg();
                $cores = max(1, (int)(ScaleTier::settings()['cores'] ?? 1));
                if (is_array($load) && isset($load[0]) && $load[0] > self::BUSY_LOAD * $cores) { return true; }
            }
        } catch (\Throwable $e) {
        }
        return false;
    }

    /** proc_nice(10) once per command-line process (never in a web worker: it would stay lowered for later requests). */
    private static function lowPriority()
    {
        if (self::$niced || PHP_SAPI !== 'cli') { return; }
        self::$niced = true;
        if (function_exists('proc_nice')) { @proc_nice(10); }
    }

    private static function raiseTimeLimit($web)
    {
        if (function_exists('set_time_limit')) { @set_time_limit($web ? self::DEEP_BUILD_TIME : 0); }
    }

    /** One 'pass' slice over runtime/cache with the tier caps (works without the framework). */
    private static function scaleCachePass($root, $deadline, $maxMs = 2000)
    {
        $file = $root . 'application/extra/maccms.php';
        $m = is_file($file) ? (static function ($path) { return include $path; })($file) : [];
        $type = is_array($m) && isset($m['app']['cache_type']) ? strtolower((string)$m['app']['cache_type']) : 'file';
        $ms = (int)max(50, min((int)$maxMs, ($deadline - microtime(true)) * 1000));
        $hk = new self(['runtime' => $root . 'runtime', 'cache_enabled' => $type === '' || $type === 'file', 'db' => null] + self::tierCaps());
        $r = $hk->run('pass', $ms);
        return isset($r['cache']) ? ['scanned' => $r['cache']['scanned'], 'deleted' => $r['cache']['deleted'], 'ms' => $r['ms']] : $r;
    }

    /**
     * HTTPS GET with a 5 s timeout (Cloudflare range lists: 64 KB cap; the search-engine range lists of
     * PublicRequestGuard::CRAWLERS: 1 MB cap); only those fixed URLs; null on any failure.
     */
    public static function httpsGet($url, $connectSec = 5, $totalSec = 0)
    {
        if (!is_string($url)) { return null; }
        $cap = 0;
        if (strpos($url, 'https://www.cloudflare.com/') === 0) {
            $cap = 65536;
        } else {
            foreach (PublicRequestGuard::CRAWLERS as $def) {
                if (in_array($url, $def['lists'], true)) { $cap = 1048576; break; }
            }
        }
        if ($cap === 0) { return null; }
        $connectSec = max(0.5, (float)$connectSec);
        $totalSec = (float)$totalSec;
        $end = $totalSec > 0 ? microtime(true) + max($connectSec, $totalSec) : 0.0;
        if ($totalSec > 0 && function_exists('curl_init')) {
            // verify r3 OW1: a visitor slice waits for this fetch, so connect (DNS included) and total time are both bounded
            $ch = curl_init($url);
            if ($ch === false) { return null; }
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT_MS => (int)round($connectSec * 1000), CURLOPT_TIMEOUT_MS => (int)round(max($connectSec, $totalSec) * 1000),
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'JavCMS-housekeeping', CURLOPT_ENCODING => '']);
            if (defined('CURLSSLOPT_NATIVE_CA')) {
                // the operating system's certificate store as well (Windows builds of PHP ship curl without a CA file)
                @curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
            }
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $errno = curl_errno($ch);
            unset($ch);
            if (is_string($body) && $code === 200) {
                return stripos($final, 'https://') === 0 && strlen($body) <= $cap ? $body : null;
            }
            // curl without a usable CA store (35 / 51 / 58 / 60 / 77): PHP's own TLS stream verifies with the system store; it
            // gets what is left of the total time. Every other failure (timeout, DNS, HTTP status) is final.
            if (!in_array($errno, [35, 51, 58, 60, 77], true) || microtime(true) >= $end - 0.2) { return null; }
            $connectSec = max(0.2, min($connectSec, $end - microtime(true)));
        }
        $ctx = stream_context_create(['http' => ['timeout' => $totalSec > 0 ? min($connectSec, $totalSec) : 5, 'ignore_errors' => false,
            'follow_location' => 1, 'max_redirects' => 3, 'header' => "User-Agent: JavCMS-housekeeping\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $h = @fopen($url, 'rb', false, $ctx);
        if (!$h) { return null; }
        $body = '';
        try {
            while (!feof($h) && strlen($body) <= $cap) {
                if ($end > 0 && microtime(true) >= $end) { return null; }
                $chunk = fread($h, 65536);
                if ($chunk === false) { return null; }
                if ($chunk === '') {
                    $meta = stream_get_meta_data($h);
                    if (!empty($meta['timed_out'])) { return null; }
                    if (!empty($meta['eof'])) { break; }
                    continue;
                }
                $body .= $chunk;
            }
        } finally {
            fclose($h);
        }
        return strlen($body) <= $cap ? $body : null;
    }

    /**
     * The stalest engine whose published list is due for a visitor-slice refresh (verify r3 OW1): a built-in engine with
     * lists, still configured, its list (fetched or shipped) older than CRAWLER_REFRESH_GAP and not tried by a visitor
     * slice within CRAWLER_RETRY_GAP. '' = none.
     */
    private static function crawlerDue($root, array $engines, $now, array $tried)
    {
        if (!$engines) { return ''; }
        $status = PublicRequestGuard::crawlerStatus($root);
        $pick = '';
        $oldest = PHP_INT_MAX;
        foreach ($engines as $engine => $def) {
            $engine = (string)$engine;
            if (empty(PublicRequestGuard::CRAWLERS[$engine]['lists'])) { continue; }
            $at = (int)($status['engines'][$engine]['at'] ?? 0);
            if ($now - $at < self::CRAWLER_REFRESH_GAP || $now - (int)($tried[$engine] ?? 0) < self::CRAWLER_RETRY_GAP) { continue; }
            if ($at < $oldest) { $oldest = $at; $pick = $engine; }
        }
        return $pick;
    }

    /**
     * verify r3 OW1 (b): fetch the search engines' published IP lists now and resolve queued claims: the web installer's last
     * step and the admin "Finish upgrade" call this (tools/upgrade.php does the same through scale()). One run of the
     * 'crawlers' and 'crawler_dns' tasks (due or not; each engine keeps its last good / shipped list on failure), at most
     * $budgetSec. Needs an installed site (install.lock). Returns ['status' => PublicRequestGuard::crawlerStatus(),
     * 'run' => scale() result]; never throws.
     */
    public static function refreshCrawlerLists($root, $budgetSec = 10)
    {
        $root = rtrim(str_replace('\\', '/', (string)$root), '/') . '/';
        $run = self::scale($root, 'cli', max(2.0, (float)$budgetSec), ['tasks' => ['crawlers', 'crawler_dns']]);
        try {
            $status = PublicRequestGuard::crawlerStatus($root);
        } catch (\Throwable $e) {
            $status = null;
        }
        return ['status' => $status, 'run' => $run];
    }

    /**
     * site-r3 N20 (b): build the sitemap files (the index, then the default-language and language video files whose stored
     * copy is missing or old, newest first) right after an install or upgrade, so a search engine meets stored files from the
     * start: a child process `<php> tools/housekeeping.php --sitemap-files-only --sitemap-budget=<budget> [--scheme=<scheme>]`
     * (the front-end boot the build needs is not possible inside an admin, installer or tools/upgrade.php process). For the
     * web installer's last step and the admin "Finish upgrade" (CONTRACT HO-H-R3-4; $scheme = the admin request's scheme
     * when the site's canonical scheme is "auto") and tools/upgrade.php step 5c. The child is the command-line PHP (cliCommand)
     * with this process's php.ini, or with its extensions when it runs without one (php -n). Waits at most $budget +
     * PREBUILD_CLI_GRACE seconds, then stops the child (what it stored stays). Never throws.
     * Returns the child's result (Sitemap::prebuild(): state, built, lang_built, left, ...) + ['via' => 'cli', 'seconds'], or
     * ['state' => 'no_cli' | 'disabled' | 'not_installed' | 'timeout' | 'error', 'seconds', ...].
     */
    public static function prebuildSitemapFiles($root, $budget = self::PREBUILD_CLI_BUDGET, $scheme = '')
    {
        $t0 = microtime(true);
        $done = function (array $r) use ($t0) { return $r + ['seconds' => round(microtime(true) - $t0, 1)]; };
        try {
            $root = rtrim(str_replace('\\', '/', (string)$root), '/') . '/';
            $tool = $root . 'tools/housekeeping.php';
            if (!is_file($root . 'application/data/install/install.lock') || !is_file($tool)) { return $done(['state' => 'not_installed']); }
            $budget = max(1, min(3600, (int)$budget));
            $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
            foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $fn) {
                if (!function_exists($fn) || in_array($fn, $disabled, true)) { return $done(['state' => 'disabled', 'why' => $fn]); }
            }
            $cmd = self::cliCommand();
            if (!$cmd) { return $done(['state' => 'no_cli']); }
            $cmd[] = $tool;
            $cmd[] = '--sitemap-files-only';
            $cmd[] = '--sitemap-budget=' . $budget;
            if (in_array((string)$scheme, ['http', 'https'], true)) { $cmd[] = '--scheme=' . $scheme; }
            $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
            // the child's answer goes to a file, not to a pipe: a pipe cannot be read without blocking on Windows, so the wait
            // below stays bounded on every system
            $outFile = self::childOutFile();
            if ($outFile === '') { return $done(['state' => 'error', 'why' => 'no writable temp file']); }
            $pipes = [];
            $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $null, 'a']], $pipes, rtrim($root, '/'), null, ['bypass_shell' => true]);
            if (!is_resource($proc)) {
                @unlink($outFile);
                return $done(['state' => 'error', 'why' => 'proc_open']);
            }
            $limit = microtime(true) + $budget + self::PREBUILD_CLI_GRACE;
            $timedOut = false;
            while (true) {
                $st = @proc_get_status($proc);
                if (!is_array($st) || empty($st['running'])) { break; }
                if (microtime(true) >= $limit) { $timedOut = true; @proc_terminate($proc); break; }
                usleep(100000);
            }
            @proc_close($proc);
            $outBuf = @file_get_contents($outFile, false, null, 0, 1048576);
            $outBuf = is_string($outBuf) ? $outBuf : '';
            @unlink($outFile);
            if ($timedOut) { return $done(['state' => 'timeout', 'via' => 'cli']); }
            $lines = preg_split('/\R/', trim($outBuf));
            $json = json_decode((string)end($lines), true);
            $res = is_array($json) && isset($json['sitemap_files']) && is_array($json['sitemap_files']) ? $json['sitemap_files'] : null;
            if ($res === null) { return $done(['state' => 'error', 'why' => 'no answer', 'via' => 'cli']); }
            $clean = [];
            foreach ($res as $k => $v) { if (is_scalar($v) || $v === null) { $clean[(string)$k] = $v; } }
            return $done($clean + ['via' => 'cli']);
        } catch (\Throwable $e) {
            return $done(['state' => 'error', 'why' => get_class($e)]);
        }
    }

    /**
     * engines-r6 NEW-1 / owner decision D-10: refresh the search index's term statistics and the search term map after an
     * upgrade. Housekeeping never runs the term build (FtIndex::housekeeping() only reports terms_due) and the documented cron
     * line runs only tools/housekeeping.php, so a site whose search index came from an earlier 1.2.0 build kept its old
     * statistics after an upgrade (vod_ft_state inv_doc 1 = "order not checked", term_doc 0): the round-5 tag route
     * (FtPlan::tagRoute 'terms') and the exact performer / tag tokens stayed off until someone ran tools/search-index.php
     * --terms and --map by hand (7.1 M: 12,211 old terms; 14 of 19 worst-class watch pages took the bounded MATCH, 287-347
     * reads, instead of the map page, 131-147 reads).
     * Runs searchStats() in a child process `<php> tools/housekeeping.php --search-stats-only [--search-budget=<budget>]
     * [--no-map]` (the command-line PHP of cliCommand(); the child raises its memory limit and lowers its priority). Callers:
     * tools/upgrade.php step 5b ($budget 0 = no time bound; the map unless --no-deep-maps) and the admin "Finish upgrade"
     * (CONTRACT HO-H-R6-1, SEARCH_STATS_WEB_BUDGET: the statistics, then the map only when its estimate fits; else
     * housekeeping rebuilds the map, since a map older than the statistics counts as missing there). With a budget the child
     * starts no step past it and is stopped after $budget + SEARCH_STATS_GRACE seconds (FtIndex::terms() writes its table in
     * one transaction: a stopped child leaves the old statistics). $tick: called about once a second while the child runs
     * (tools/upgrade.php renews its heavy-maintenance marker there). Never throws.
     * Returns the child's result (searchStats(): state, terms, exact, map, ...) + ['via' => 'cli', 'seconds'], or
     * ['state' => 'no_cli' | 'disabled' | 'not_installed' | 'timeout' | 'error', 'why', 'seconds'].
     */
    public static function refreshSearchStats($root, $budget = self::SEARCH_STATS_WEB_BUDGET, $map = true, $tick = null)
    {
        $t0 = microtime(true);
        $done = function (array $r) use ($t0) { return $r + ['seconds' => round(microtime(true) - $t0, 1)]; };
        try {
            $root = rtrim(str_replace('\\', '/', (string)$root), '/') . '/';
            $tool = $root . 'tools/housekeeping.php';
            if (!is_file($root . 'application/data/install/install.lock') || !is_file($tool)) { return $done(['state' => 'not_installed']); }
            $budget = max(0, min(86400, (int)$budget));
            $disabled = array_map('trim', explode(',', strtolower((string)ini_get('disable_functions'))));
            foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $fn) {
                if (!function_exists($fn) || in_array($fn, $disabled, true)) { return $done(['state' => 'disabled', 'why' => $fn]); }
            }
            $cmd = self::cliCommand();
            if (!$cmd) { return $done(['state' => 'no_cli']); }
            $cmd[] = $tool;
            $cmd[] = '--search-stats-only';
            $cmd[] = '--search-budget=' . $budget;
            if (!$map) { $cmd[] = '--no-map'; }
            $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
            // the answer goes to a file, not to a pipe (a pipe cannot be read without blocking on Windows): the wait stays bounded
            $outFile = self::childOutFile();
            if ($outFile === '') { return $done(['state' => 'error', 'why' => 'no writable temp file']); }
            $pipes = [];
            $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $null, 'a']], $pipes, rtrim($root, '/'), null, ['bypass_shell' => true]);
            if (!is_resource($proc)) {
                @unlink($outFile);
                return $done(['state' => 'error', 'why' => 'proc_open']);
            }
            $limit = $budget > 0 ? microtime(true) + $budget + self::SEARCH_STATS_GRACE : 0.0;
            $timedOut = false;
            $lastTick = microtime(true);
            while (true) {
                $st = @proc_get_status($proc);
                if (!is_array($st) || empty($st['running'])) { break; }
                if ($limit > 0 && microtime(true) >= $limit) { $timedOut = true; @proc_terminate($proc); break; }
                if ($tick !== null && is_callable($tick) && microtime(true) - $lastTick >= 1.0) {
                    $lastTick = microtime(true);
                    try { $tick(); } catch (\Throwable $e) {}
                }
                usleep(100000);
            }
            @proc_close($proc);
            $outBuf = @file_get_contents($outFile, false, null, 0, 1048576);
            $outBuf = is_string($outBuf) ? $outBuf : '';
            @unlink($outFile);
            if ($timedOut) { return $done(['state' => 'timeout', 'via' => 'cli']); }
            $lines = preg_split('/\R/', trim($outBuf));
            $json = json_decode((string)end($lines), true);
            $res = is_array($json) && isset($json['search_stats']) && is_array($json['search_stats']) ? $json['search_stats'] : null;
            if ($res === null) { return $done(['state' => 'error', 'why' => 'no answer', 'via' => 'cli']); }
            $clean = [];
            foreach ($res as $k => $v) { if (is_scalar($v) || $v === null) { $clean[(string)$k] = $v; } }
            unset($clean['seconds']);
            return $done($clean + ['via' => 'cli']);
        } catch (\Throwable $e) {
            return $done(['state' => 'error', 'why' => get_class($e)]);
        }
    }

    /**
     * The work of refreshSearchStats() (its child process; tools/upgrade.php also calls it directly when no child can be
     * started): with the search index ready, FtIndex::terms() when FtIndex::termsDue() (old statistics of an earlier build:
     * inv_doc 1; more than 5 % new documents; older than a week), then, when $map, FtIndex::mapBuild() when mapState() says
     * the term map is missing or stale (mapMissing()) and, after a refresh, also when it was built before the new statistics
     * (inside mapState()'s 60 s tolerance: the old map has no rows for the new exact field tokens). $deadline 0 = no time
     * bound; else no step starts past it and the map only when SEARCH_MAP_SEC_BASE + SEARCH_MAP_SEC_PER_MDOC x documents
     * (millions) fits before it ('left': housekeeping rebuilds the map). Both steps run under the heavy-maintenance marker
     * 'search_stats' (expired cached pages are answered stale meanwhile, page views start no housekeeping run). $pdo / $pre:
     * the caller's connection (statement limit removed), else an own connection from application/database.php. On the
     * command line the memory limit is raised to SEARCH_STATS_MEMORY (never lowered) and the priority lowered. Never throws.
     * Returns ['state' => 'refreshed' | 'current' | 'not_ready' | 'not_installed' | 'no_database' | 'busy' | 'left' | 'error',
     * 'terms' (refreshed: terms kept), 'exact' (the tag / performer words were counted exactly over every document: term_doc
     * > 0), 'docs', 'inv_doc', 'term_doc', 'terms_s', 'map' => 'built' | 'current' | 'building' | 'left' | 'skipped' | 'busy'
     * | 'not_ready' | 'not_installed' | 'error', 'map_terms', 'map_mb', 'map_s', 'map_est_s', 'why', 'seconds'].
     */
    public static function searchStats($root, $deadline = 0.0, $map = true, $pdo = null, $pre = null)
    {
        $t0 = microtime(true);
        $out = ['state' => 'error', 'map' => 'skipped'];
        $finish = function (array $r) use ($t0) { return $r + ['seconds' => round(microtime(true) - $t0, 1)]; };
        $marked = false;
        try {
            $root = rtrim(str_replace('\\', '/', (string)$root), '/') . '/';
            self::loadScaleClasses($root);
            $ft = __NAMESPACE__ . '\FtIndex';
            foreach (['installed', 'ready', 'termsDue', 'terms', 'state', 'useConnection'] as $m) {
                if (!class_exists($ft) || !method_exists($ft, $m)) { return $finish(['state' => 'not_installed', 'map' => 'skipped']); }
            }
            if (!$pdo instanceof \PDO) {
                $pdo = ScaleSchema::connect(true);
                $pre = ScaleSchema::configPrefix();
            }
            if (!$pdo instanceof \PDO) { return $finish(['state' => 'no_database', 'map' => 'skipped']); }
            $pre = (string)$pre;
            $ft::useConnection($pdo, $pre, (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: ''));
            if (method_exists($ft, 'useRuntime')) { $ft::useRuntime($root . 'runtime'); }
            if (!$ft::installed()) { return $finish(['state' => 'not_installed', 'map' => 'skipped']); }
            if ($ft::ready() !== true) {
                // no search index yet: its backfill writes fresh statistics and builds the map itself
                return $finish(['state' => 'not_ready', 'map' => 'skipped']);
            }
            self::lowPriority();
            self::raiseMemory();
            $deadline = (float)$deadline;
            if ($ft::termsDue()) {
                if ($deadline > 0 && microtime(true) >= $deadline) {
                    return $finish(['state' => 'left', 'map' => 'skipped', 'why' => 'budget']);
                }
                ScaleTier::heavyMark('search_stats');
                $marked = true;
                $r = (array)$ft::terms();
                if (isset($r['error'])) {
                    $why = preg_replace('/[^a-z_]/', '', strtolower((string)$r['error']));
                    return $finish(['state' => $why === 'busy' ? 'busy' : 'error', 'map' => 'skipped', 'why' => $why]);
                }
                $out = ['state' => 'refreshed', 'terms' => (int)($r['terms'] ?? 0), 'exact' => !empty($r['field_exact']) && (int)($r['term_doc'] ?? 0) > 0,
                    'docs' => (int)($r['docs'] ?? 0), 'inv_doc' => (int)($r['inv_doc'] ?? 0), 'term_doc' => (int)($r['term_doc'] ?? 0),
                    'terms_s' => round((float)($r['seconds'] ?? 0), 1), 'map' => 'skipped'];
            } else {
                $s = (array)$ft::state(true);
                $out = ['state' => 'current', 'map' => 'skipped', 'docs' => (int)($s['docs'] ?? 0)];
                if (array_key_exists('term_doc', $s)) { $out['term_doc'] = (int)$s['term_doc']; $out['exact'] = (int)$s['term_doc'] > 0; }
            }
            if (!$map) {
                return $finish($out);
            }
            if (!method_exists($ft, 'mapState') || !method_exists($ft, 'mapBuild')) {
                return $finish(['map' => 'not_installed'] + $out);
            }
            $ms = (array)$ft::mapState();
            // a map built before these statistics is older than them (D-10): rebuilt after a refresh even inside mapState()'s
            // 60 s tolerance (its 'stale' flag), so its rows match the new term table (exact tag / performer tokens included)
            $older = $out['state'] === 'refreshed' && empty($ms['building'])
                && (int)($ms['built_at'] ?? 0) < (int)(((array)$ft::state(true))['term_at'] ?? 0);
            if (!$older && !self::mapMissing($ms)) {
                return $finish(['map' => !empty($ms['building']) ? 'building' : 'current'] + $out);
            }
            $docs = (int)($out['docs'] ?? 0);
            if ($docs <= 0) { $docs = (int)(((array)$ft::state(true))['docs'] ?? 0); }
            $est = self::SEARCH_MAP_SEC_BASE + self::SEARCH_MAP_SEC_PER_MDOC * $docs / 1000000;
            $out['map_est_s'] = round($est, 1);
            if ($deadline > 0 && microtime(true) + $est > $deadline) {
                return $finish(['map' => 'left'] + $out);
            }
            if (!$marked) {
                ScaleTier::heavyMark('search_stats');
                $marked = true;
            }
            $tm = microtime(true);
            $b = (array)$ft::mapBuild();
            $bs = (string)($b['state'] ?? '');
            $out['map'] = $bs === 'done' ? 'built' : (in_array($bs, ['busy', 'not_ready', 'building'], true) ? $bs : 'error');
            if ($bs === 'done') {
                $out['map_terms'] = (int)($b['terms'] ?? 0);
                $out['map_mb'] = round((float)($b['mb'] ?? 0), 1);
            }
            $out['map_s'] = round(microtime(true) - $tm, 1);
            return $finish($out);
        } catch (\Throwable $e) {
            return $finish(['state' => ($out['state'] ?? '') === 'refreshed' ? 'refreshed' : 'error', 'why' => get_class($e)] + $out);
        } finally {
            if ($marked) {
                ScaleTier::heavyClear('search_stats');
            }
        }
    }

    /** Command line only: raise the memory limit to SEARCH_STATS_MEMORY (never lowered; -1 = no limit stays). */
    private static function raiseMemory()
    {
        if (PHP_SAPI !== 'cli' || !function_exists('ini_set')) { return; }
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') { return; }
        $n = (int)$v;
        $u = strtolower(substr($v, -1));
        $n *= $u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1));
        if ($n > 0 && $n < self::SEARCH_STATS_MEMORY) {
            @ini_set('memory_limit', (string)intdiv(self::SEARCH_STATS_MEMORY, 1048576) . 'M');
        }
    }

    /**
     * The command-line PHP for a child process (prebuildSitemapFiles, refreshSearchStats): this binary on the command line (cli, cli-server) with
     * its memory limit, plus -n and the loaded extensions when it runs without a php.ini; from another SAPI (FPM, CGI, module)
     * the php(.exe) next to this PHP or in PHP_BINDIR, else ImportProviders::phpBinaries() / the usual paths. [] when none.
     */
    private static function cliCommand()
    {
        $exe = DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && is_string(PHP_BINARY) && PHP_BINARY !== '' && @is_file(PHP_BINARY)) {
            $cmd = [PHP_BINARY];
            if (php_ini_loaded_file() === false) {
                // started without a php.ini (php -n -d extension=...): the child gets the same shared extensions
                $dir = (string)ini_get('extension_dir');
                $cmd[] = '-n';
                if ($dir !== '' && @is_dir($dir)) {
                    $cmd[] = '-d';
                    $cmd[] = 'extension_dir=' . $dir;
                    foreach (get_loaded_extensions() as $ext) {
                        $name = strtolower((string)$ext);
                        if (!preg_match('/^[a-z0-9_]{2,32}$/D', $name)) { continue; }
                        foreach (['php_' . $name . '.dll', $name . '.so'] as $file) {
                            if (@is_file($dir . DIRECTORY_SEPARATOR . $file)) { $cmd[] = '-d'; $cmd[] = 'extension=' . $name; break; }
                        }
                    }
                }
            }
            $mem = (string)ini_get('memory_limit');
            if (preg_match('/^-?[0-9]+[KMG]?$/i', $mem)) { $cmd[] = '-d'; $cmd[] = 'memory_limit=' . $mem; }
            return $cmd;
        }
        $candidates = [];
        if (is_string(PHP_BINARY) && PHP_BINARY !== '') { $candidates[] = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $exe; }
        if (defined('PHP_BINDIR')) { $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . $exe; }
        if (class_exists('\app\common\util\ImportProviders') && method_exists('\app\common\util\ImportProviders', 'phpBinaries')) {
            try { foreach ((array)ImportProviders::phpBinaries() as $b) { if (is_string($b)) { $candidates[] = $b; } } } catch (\Throwable $e) {}
        }
        $candidates[] = '/usr/local/bin/php';
        $candidates[] = '/usr/bin/php';
        foreach (array_unique($candidates) as $bin) {
            $base = strtolower(basename($bin));
            if (strpos($base, 'fpm') !== false || strpos($base, 'cgi') !== false) { continue; }
            if (@is_file($bin) && (DIRECTORY_SEPARATOR === '\\' || @is_executable($bin))) {
                return [$bin, '-d', 'memory_limit=512M'];
            }
        }
        return [];
    }

    /**
     * A new empty file for the answer of prebuildSitemapFiles()' child process (runtime/scale/, else the system temp
     * directory), '' when neither is writable. The caller deletes it.
     */
    private static function childOutFile()
    {
        $dirs = [];
        if (class_exists(__NAMESPACE__ . '\ScaleTier')) {
            try { $dirs[] = (string)ScaleTier::runtime('scale'); } catch (\Throwable $e) {}
        }
        $dirs[] = (string)sys_get_temp_dir();
        foreach ($dirs as $dir) {
            $dir = rtrim($dir, '/\\');
            if ($dir === '' || !@is_dir($dir) || !@is_writable($dir)) { continue; }
            $file = @tempnam($dir, 'jvsm');
            if (is_string($file) && $file !== '' && @is_file($file)) { return $file; }
        }
        return '';
    }

    /** The scale classes this run needs, loaded by file (the run also happens before the framework boots). */
    private static function loadScaleClasses($root)
    {
        foreach (['ScaleTier', 'DbTimeout', 'ScaleSchema', 'ScaleCounts', 'HitBuffer', 'EdgeCache', 'RequestGate', 'PublicRequestGuard', 'Sitemap',
                     'StorageGuard', 'FtText', 'FtPlan', 'FtIndex', 'ListPageMap', 'ListRank', 'FilterVocab'] as $c) {
            if (class_exists(__NAMESPACE__ . '\\' . $c, false)) { continue; }
            $f = $root . 'application/common/util/' . $c . '.php';
            if (is_file($f)) { require_once $f; }
        }
    }

    private static function touchFile($path, $mtime)
    {
        if (!is_file($path)) { @file_put_contents($path, ''); }
        @touch($path, (int)$mtime);
        clearstatcache(true, $path);
    }

    /**
     * Pre-framework trigger (L12), called by EdgeCache::serve() for every request: one stat when not due. When the due
     * time passed and no cron heartbeat is younger than 24 h, the due time is pushed 60 s ahead (one claim per minute) and
     * the run is scheduled: after the response where FPM / LiteSpeed can finish it (the request is served as usual), else
     * this request falls through to the framework and runs the jobs at its end ('fallthrough' returned: the caller must
     * not answer from the edge cache). While a heavy maintenance step runs (ScaleTier::heavyActive, engines-r4 R4-3) neither
     * a housekeeping run nor a background import step is started from a page view ('' returned, the claim used up), so the
     * request is answered as usual (an expired page from its stale copy). Returns '' (nothing to do), 'background' or
     * 'fallthrough'.
     */
    public static function edgeTrigger($root, $background = null)
    {
        $root = rtrim(str_replace('\\', '/', (string)$root), '/') . '/';
        $r = self::hkTrigger($root, $background);
        // a request that schedules the scale run does not also step the import (one bounded job per worker)
        return $r !== '' ? $r : self::importTrigger($root, null, $background);
    }

    /** The scale-run part of edgeTrigger() (L12). */
    private static function hkTrigger($root, $background)
    {
        $dir = $root . 'runtime/scale/';
        $due = $dir . 'hk.due';
        $now = time();
        if (is_file($due)) {
            $m = @filemtime($due);
            if ($m !== false && $m > $now) { return ''; }
        } elseif (!is_file($root . 'application/data/install/install.lock')) {
            return '';
        }
        $cron = $dir . 'cron.seen';
        if (is_file($cron) && $now - (int)@filemtime($cron) < self::CRON_FRESH) { return ''; }
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        // claim: exactly one request per due time (non-blocking lock, then the due time is read again and pushed ahead)
        $h = @fopen($dir . 'hk.claim', 'c');
        if (!$h) { return ''; }
        if (!@flock($h, LOCK_EX | LOCK_NB)) { @fclose($h); return ''; }
        clearstatcache(true, $due);
        $m = is_file($due) ? @filemtime($due) : false;
        if ($m !== false && $m > $now) { @flock($h, LOCK_UN); @fclose($h); return ''; }
        self::touchFile($due, $now + self::SCALE_CLAIM);
        // engines-r4 R4-3 (G23): while a heavy maintenance step runs (an index build, tools/upgrade.php steps 2b-5b, the rank
        // files' full read or the term-map build: ScaleTier::heavyMark) no page view starts a housekeeping run: the request
        // is answered as usual (an expired page from its stale copy, EdgeCache::serve) instead of falling through to a cold
        // render plus a slice next to the step (G23, 1 core: 2.4 s). The due time moved 60 s ahead (one look per minute),
        // so the slices resume within a minute after the step; the step itself holds the locks a slice would need anyway.
        $heavy = self::heavyNow($root);
        @flock($h, LOCK_UN);
        @fclose($h);
        if ($heavy) { return ''; }
        if ($background === null) { $background = self::canFinish(); }
        register_shutdown_function([__CLASS__, 'edgeRun'], $root, (bool)$background);
        return $background ? 'background' : 'fallthrough';
    }

    /** fastcgi_finish_request() or litespeed_finish_request() exists (the response can be sent before work continues). */
    public static function canFinish()
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /**
     * Pure rule of the page-view import tick (H4): '' = schedule a background step now, else why not. $s: now, mtime (of
     * runtime/scale/import.due, false = missing), reason + at (the last tickWeb decision and its time, from that file's JSON,
     * read only once the file is old enough), background (canFinish()), installed. 'idle' = the last decision found no
     * running web job less than IMPORT_IDLE_GAP ago.
     */
    public static function importDue(array $s)
    {
        if (empty($s['background'])) { return 'no_background'; }
        if (empty($s['installed'])) { return 'not_installed'; }
        $now = (int)($s['now'] ?? time());
        $m = $s['mtime'] ?? false;
        if ($m === false || $m === null) { return ''; }
        $age = $now - (int)$m;
        if ($age < self::IMPORT_GAP && $age > -self::IMPORT_GAP) { return 'not_due'; }
        $since = $now - (int)($s['at'] ?? 0);
        if ((string)($s['reason'] ?? '') === 'no_job' && $since < self::IMPORT_IDLE_GAP && $since >= 0) { return 'idle'; }
        return '';
    }

    /**
     * H4 / A3: when a background import step is due (importDue), claim it (non-blocking lock, import.due mtime pushed to
     * now: one claim per IMPORT_GAP per site), register edgeRun($root, true, 'import') and return 'fallthrough' (the step
     * needs the framework, so this one request is rendered instead of answered from the edge cache). Hosts without a
     * finish-request function never get a page-view step ('' at the first check, no stat). Returns '' or 'fallthrough'.
     */
    public static function importTrigger($root, $now = null, $background = null)
    {
        if ($background === null) { $background = self::canFinish(); }
        if (!$background) { return ''; }
        $root = rtrim(str_replace('\\', '/', (string)$root), '/') . '/';
        $dir = $root . 'runtime/scale/';
        $file = $dir . 'import.due';
        $now = $now === null ? time() : (int)$now;
        $installed = is_file($root . 'application/data/install/install.lock');
        clearstatcache(true, $file);
        $m = is_file($file) ? @filemtime($file) : false;
        if (self::importDue(['now' => $now, 'mtime' => $m, 'reason' => '', 'background' => true, 'installed' => $installed]) !== '') { return ''; }
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        $h = @fopen($dir . 'import.claim', 'c');
        if (!$h) { return ''; }
        if (!@flock($h, LOCK_EX | LOCK_NB)) { @fclose($h); return ''; }
        try {
            clearstatcache(true, $file);
            $m = is_file($file) ? @filemtime($file) : false;
            $reason = '';
            $at = 0;
            if ($m !== false) {
                $j = json_decode((string)@file_get_contents($file, false, null, 0, 4096), true);
                $reason = is_array($j) ? (string)($j['reason'] ?? '') : '';
                $at = is_array($j) ? (int)($j['at'] ?? 0) : 0;
            }
            $why = self::importDue(['now' => $now, 'mtime' => $m, 'reason' => $reason, 'at' => $at, 'background' => true, 'installed' => $installed]);
            if ($why === 'idle') {
                // no running web job: the mtime moves so the one-stat check skips every request until the idle time ends
                self::touchFile($file, $at + self::IMPORT_IDLE_GAP - self::IMPORT_GAP);
            }
            if ($why !== '') { return ''; }
            // touch keeps tickWeb's JSON (it decides from its own 'at'); a missing file is created empty (= never checked)
            self::touchFile($file, $now);
            // engines-r4 R4-3: no background step while a heavy maintenance step runs (the claim of this 30 s window is used
            // up, the request is answered from the edge cache as usual: never a cold render for a step next to an index
            // build or a full read); the next window after the step continues the job
            if (self::heavyNow($root)) { return ''; }
        } finally {
            @flock($h, LOCK_UN);
            @fclose($h);
        }
        register_shutdown_function([__CLASS__, 'edgeRun'], $root, true, 'import');
        return 'fallthrough';
    }

    /**
     * engines-r4 R4-3: true while a heavy-maintenance marker of the site at $root is active (ScaleTier::heavyActive: index
     * builds, tools/upgrade.php steps 2b-5b, the rank files' full read, the term-map build). Read only by the page-view triggers
     * once they would start a run (at most once per claim window). Never throws (false when the marker cannot be read).
     */
    private static function heavyNow($root)
    {
        try {
            if (!class_exists(__NAMESPACE__ . '\ScaleTier', false)) {
                if (!is_file(__DIR__ . '/ScaleTier.php')) { return false; }
                require_once __DIR__ . '/ScaleTier.php';
            }
            return ScaleTier::heavyActive((string)$root);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Tests: callable(): bool replacing the finish-request call of edgeRun(.., 'import'); null = the real one. */
    public static function setFinisher($finisher)
    {
        self::$finisher = is_callable($finisher) ? $finisher : null;
    }

    /**
     * Shutdown function of a page-view run: finish the response when possible, release gate and edge locks, run.
     * $what 'scale' = the scale jobs; 'import' = one background step of a running web import job (H4): only inside the
     * framework, only when the response WAS finished first (never inline in a visitor request), after the request's gate
     * slot and single-flight lock were released (never inside a gate slot). Returns what happened (tests; ignored by PHP).
     */
    public static function edgeRun($root, $background, $what = 'scale')
    {
        if ($what === 'import') {
            return self::importRun($root);
        }
        try {
            if ($background) { self::finishResponse(); }
            @ignore_user_abort(true);
            if (class_exists(__NAMESPACE__ . '\RequestGate', false)) { RequestGate::leave(); }
            if (class_exists(__NAMESPACE__ . '\EdgeCache', false)) { EdgeCache::releaseFlight(); }
            if (function_exists('set_time_limit')) { @set_time_limit(self::SCALE_BUDGET + 30); }
            // verify r2 R2-8: without finish-request the visitor waits for the run: a short slice only
            self::scale($root, 'edge', $background ? self::SCALE_BUDGET : self::SCALE_BUDGET_EDGE_INLINE, $background ? [] : ['inline' => true, 'visitor' => true]);
        } catch (\Throwable $e) {
            // never breaks a page
        }
    }

    private static function importRun($root)
    {
        try {
            $cls = '\\app\\common\\model\\AvdbImport';
            if (!class_exists('\\think\\Db', false) || !defined('ENTRANCE')) {
                // verify r2 N17: the claiming request ended before the framework (shed by the gate, answered from the edge
                // cache, a 429): its 30 s slot goes back, so the next request claims the step (under a flood, 87 % of the
                // requests are shed and no step ran in 6 minutes)
                self::releaseImportClaim($root);
                return 'no_framework';
            }
            if (!class_exists($cls) || !method_exists($cls, 'tickWeb')) { return 'not_installed'; }
            $finished = self::$finisher !== null ? (bool)call_user_func(self::$finisher) : (self::canFinish() && self::finishResponse());
            if (!$finished) {
                self::releaseImportClaim($root);
                return 'no_background';
            }
            @ignore_user_abort(true);
            if (class_exists(__NAMESPACE__ . '\RequestGate', false)) { RequestGate::leave(); }
            if (class_exists(__NAMESPACE__ . '\EdgeCache', false)) { EdgeCache::releaseFlight(); }
            if (function_exists('set_time_limit')) { @set_time_limit(self::IMPORT_TICK_BUDGET + 30); }
            // flood-r4 N17 (HO-P-R3-1): the step gets its own database time (IMPORT_TICK_BUDGET + DbTimeout::DEADLINE_GRACE from
            // now) instead of the visitor request's deadline (statement limit + 1 s from the request start, mostly spent by
            // now: under a flood every second background step was cut mid-batch and kept its 300 s lease). The visitor already
            // has the response; each statement stays limited to the request's statement limit; lane P's stepDeadlineDb()
            // (DbTimeout::remaining()) follows the new deadline
            if (class_exists(__NAMESPACE__ . '\DbTimeout', false)) { DbTimeout::startBackground((float)self::IMPORT_TICK_BUDGET); }
            $t = microtime(true);
            $r = (new $cls())->tickWeb(self::IMPORT_TICK_BUDGET);
            $out = is_array($r) ? (!empty($r['stepped']) ? 'stepped' : 'checked:' . (string)($r['reason'] ?? '')) : 'skipped';
            // last page-view step (Speed page, tests): runtime/scale/import.tick.json
            $root = rtrim(str_replace('\\', '/', (string)$root), '/') . '/';
            if (is_dir($root . 'runtime/scale')) {
                ScaleTier::writeAtomic($root . 'runtime/scale/import.tick.json', json_encode(['at' => time(), 'result' => $out,
                    'ms' => (int)round((microtime(true) - $t) * 1000), 'job' => is_array($r) ? (string)($r['job'] ?? '') : '',
                    'created' => is_array($r) ? (int)($r['created'] ?? 0) : 0], JSON_UNESCAPED_SLASHES));
            }
            return $out;
        } catch (\Throwable $e) {
            return 'error:' . get_class($e);
        }
    }

    /**
     * Give a claimed page-view import step back (verify r2 N17): import.due's mtime moves to IMPORT_GAP seconds ago, so the
     * next request that reaches the trigger claims it again; tickWeb's JSON in the file stays. Only when this request's own
     * claim is the newest one (mtime within the last IMPORT_GAP seconds and not in the future), never touching the idle
     * window that importTrigger() set after a 'no_job' check.
     */
    private static function releaseImportClaim($root)
    {
        try {
            $file = rtrim(str_replace('\\', '/', (string)$root), '/') . '/runtime/scale/import.due';
            clearstatcache(true, $file);
            if (!is_file($file)) {
                return;
            }
            $m = (int)@filemtime($file);
            $now = time();
            if ($m <= $now && $now - $m < self::IMPORT_GAP) {
                @touch($file, $now - self::IMPORT_GAP);
                clearstatcache(true, $file);
            }
        } catch (\Throwable $e) {
        }
    }

    /** Last run started by cron / the cron URL / the schedule callback (unix time, 0 = never). HO-E-6. */
    public static function cronSeenAt()
    {
        $f = (defined('ROOT_PATH') ? rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/' : dirname(__DIR__, 3) . '/') . 'runtime/scale/cron.seen';
        return is_file($f) ? (int)@filemtime($f) : 0;
    }

    /**
     * Scale job status for the Speed page and tools: last run, history, cron heartbeat and the T3 notice "Set up cron: at this
     * size, housekeeping from page views is not enough" (cron_missing: tier 3 and no heartbeat in 24 h).
     */
    public static function scaleStatus()
    {
        $root = defined('ROOT_PATH') ? rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/' : dirname(__DIR__, 3) . '/';
        self::loadScaleClasses($root);
        $s = ScaleTier::readJson(ScaleTier::runtime('scale') . 'hk.json');
        $s = is_array($s) ? $s : [];
        $cron = self::cronSeenAt();
        $due = ScaleTier::runtime('scale') . 'hk.due';
        $tier = (int)(ScaleTier::get()['tier'] ?? 1);
        return ['last' => $s['last'] ?? null, 'history' => array_slice((array)($s['history'] ?? []), 0, 10), 'runs' => (int)($s['runs'] ?? 0),
            'cron_seen_at' => $cron, 'due_at' => is_file($due) ? (int)@filemtime($due) : 0,
            'cron_missing' => $tier >= 3 && time() - $cron >= self::CRON_FRESH];
    }

    // ------------------------------------------------------------------ state

    private function now()
    {
        return $this->now !== null ? $this->now : time();
    }

    private function statePath()
    {
        return ($this->hkDir !== null ? $this->hkDir : $this->runtime . 'housekeeping') . DIRECTORY_SEPARATOR . 'state.json';
    }

    /** $checked: run() has just made sure state.json is absent or a plain file of our own. */
    private function readState($checked = false)
    {
        // Never read through a link: a planted state.json link reads as a fresh state (run() replaces it), and so does
        // one in a linked runtime/housekeeping. No read (and so no warning, which ThinkPHP would log) while the file does
        // not exist yet.
        $path = $this->statePath();
        $raw = ($checked ? is_file($path) : $this->hkDir !== null && self::entryKind($path) === 'own') ? @file_get_contents($path) : false;
        $s = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($s) || !isset($s['v']) || (int)$s['v'] !== self::VERSION) { $s = ['v' => self::VERSION]; }
        foreach (['due', 'err', 'totals'] as $k) { if (!isset($s[$k]) || !is_array($s[$k])) { $s[$k] = []; } }
        return $s;
    }

    private function writeState(array $s)
    {
        if ($this->hkDir === null) { $this->hkDir = $this->ownDir(); }
        $path = $this->statePath();
        $json = json_encode($s);
        if (!is_string($json)) { return; }
        // Only into the checked runtime/housekeeping folder (run() sets it); a planted link / junction / hard link is
        // removed by its name first (rename() cannot replace a junction).
        if ($this->hkDir === null || !self::ownEntry($path)) { return; }
        // Written under the run lock (only lock holders read it for decisions) as a new file renamed over the old one:
        // file_put_contents() would follow a link planted after the check. A page tick writes the state only when a daily
        // task ran (a few times a day), so the cost of creating a file (+0.3 ms measured) is rare. A crash before the
        // rename leaves the old state and a stray temp file, which the next full pass removes.
        self::replaceWith($path, $json, null);
    }
}
