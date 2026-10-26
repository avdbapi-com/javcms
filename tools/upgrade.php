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
if (PHP_SAPI !== 'cli') { exit(1); }
// Finishes an upgrade to JavCMS 1.2.0 in one command (XVID-SCALE-BRIEF 2.12 steps 2a-4). Safe to run again: every step
// first checks what is already there; a second run changes nothing.
// Usage: php tools/upgrade.php [--root=<site folder>] [--no-search-backfill] [--no-deep-maps] [--json]
//        php tools/upgrade.php --prepare-rollback [--root=<site folder>]
//   (default)            1. new tables (page counts, sitemap blocks, view-counter buffer; search index tables; importer
//                           tables and columns), 1b. settings: site.theme_switcher_show = 'always' (1.2.1, visitor theme
//                           switcher always visible) when application/extra/maccms.php has no such key (a stored value is
//                           never changed), 2. the indexes of the video table: two list indexes, four sort indexes
//                           and the five FULLTEXT indexes of the filters and title search (one at a time, skipped while
//                           another ALTER on that table runs; never dropped), plus the translation table's covering index
//                           idx_tr_lastmod (the sitemap's per-language dates read from the index only), 2b. the filter
//                           vocabulary of FilterMatch v2 (table {prefix}vod_ft_vocab created when missing, built or caught
//                           up; a 1.1 site that already has it from the ft-vocab package keeps it), 3. file-lock
//                           self-test, proxy check and
//                           the search-engine IP lists (verified crawlers are never limited; --no-network skips the
//                           download, housekeeping fetches them daily), 4. search index
//                           backfill (php tools/search-index.php --backfill, skipped when the index is ready), 5. first
//                           page counts and sitemap blocks (with their per-language dates; on a shared host every statement
//                           is followed by a pause, one core: about the statement's own time), 5b. search statistics (the
//                           search index's term statistics when they are due, e.g. made by an earlier 1.2.0 build, then the
//                           search term map when it is missing or older than them: tools/housekeeping.php
//                           --search-stats-only in a child process) and the first deep-page maps (page maps of the newest-first lists,
//                           rank files of the sorted lists, search term map; XVID-NOLIMIT-BRIEF H2), 6. prints the scale
//                           tier and the hosting row for this catalog.
//                        Steps 2b to 5b (whole-table reads and first builds) run as one heavy-maintenance window
//                        (ScaleTier::heavyMark('upgrade'), renewed while it lasts, ended before step 6 and on any exit):
//                        visitors get the stale copy of an expired cached page and page views start no housekeeping run.
//   --prepare-rollback   pauses every import job and every automatic import of every source and writes the waiting view
//                        counts; then restore the 1.1.0 files (new tables and columns stay and are ignored by 1.1.0).
//   --no-search-backfill leave step 4 to the admin button or a later "php tools/search-index.php --backfill".
//   --no-deep-maps       leave step 5b to housekeeping (cron or page views build the maps in slices; deep pages use the
//                        slower way until then). The search statistics of step 5b are still refreshed when due (housekeeping
//                        never refreshes them); their term map is then left to housekeeping too.
//   --no-ft-vocab        leave step 2b's build to housekeeping (the table is still created; filters use the plain LIKE for
//                        single words until the first build has finished).
//   --sitemap-budget=N   step 5c (site-r3 N20): seconds for building the sitemap files right away (default 120; 0 = leave
//                        them to housekeeping); --scheme=http|https gives their URL scheme when the canonical scheme is
//                        "auto" and the sitemap index was never fetched over the web yet.
//   --sitemap-rows-budget=N  step 5d (flood-r4 G9): seconds for the rows lists of sitemap blocks built before this release
//                        (default 180; 0 = leave them to housekeeping): a never-built video file is answered from its list.
// Only the site's own database (application/database.php) is used. Credentials are never printed.
if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, "PHP 7.4 or newer is required (this is " . PHP_VERSION . ")\n");
    exit(2);
}
$opt = ['root' => dirname(__DIR__), 'prepare-rollback' => false, 'no-search-backfill' => false, 'no-deep-maps' => false, 'no-network' => false, 'json' => false,
    'sitemap-budget' => 120, 'scheme' => '', 'no-ft-vocab' => false, 'sitemap-rows-budget' => 180];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $arg, $m) || !array_key_exists($m[1], $opt)) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
    $opt[$m[1]] = isset($m[2]) ? $m[2] : true;
}
$root = realpath((string)$opt['root']);
if ($root === false || !is_file($root . '/application/common/util/ScaleSchema.php') || !is_file($root . '/application/database.php')) {
    fwrite(STDERR, "Not a JavCMS 1.2.0 site folder: {$opt['root']}\n");
    exit(2);
}
$root = str_replace('\\', '/', $root) . '/';
if (!is_file($root . 'application/data/install/install.lock')) {
    fwrite(STDERR, "This site is not installed yet: open install.php first.\n");
    exit(2);
}
// PHP 8 removes a function named in disable_functions (calling it is a fatal error that @ does not hide): only what exists
// is called (shared hosts often disable these two for the command line too)
if (function_exists('set_time_limit')) { @set_time_limit(0); }
if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
// engines-r6 NEW-1: the whole-table builds of steps 2b to 5b (rank files, the search term map: about 250 MB of PHP memory at
// 7 M videos) need more than the 128 MB a command-line PHP often has: raised to 1 GB like tools/search-index.php (never
// lowered; -1 = no limit stays)
(function () {
    $v = trim((string)ini_get('memory_limit'));
    if ($v === '' || $v === '-1') { return; }
    $n = (int)$v;
    $u = strtolower(substr($v, -1));
    $n *= $u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1));
    if ($n > 0 && $n < 1073741824) { @ini_set('memory_limit', '1024M'); }
})();

// ---- framework (the importer model needs it); the CLI connection has no statement limit (Init sets limits for web only)
define('ROOT_PATH', $root);
define('APP_PATH', $root . 'application/');
define('MAC_COMM', $root . 'application/common/common/');
define('MAC_HOME_COMM', $root . 'application/index/common/');
define('MAC_ADMIN_COMM', $root . 'application/admin/common/');
define('MAC_START_TIME', microtime(true));
define('BIND_MODULE', 'api');
define('ENTRANCE', 'api');
define('IN_FILE', '/api.php');
$_SERVER += ['HTTP_HOST' => '', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/api.php', 'REQUEST_METHOD' => 'GET'];
require $root . 'thinkphp/base.php';
\think\App::initCommon();

use app\common\util\ScaleSchema;
use app\common\util\ScaleCounts;
use app\common\util\ScaleTier;
use app\common\util\Sitemap;
use app\common\util\HitBuffer;
use app\common\util\RequestGate;
use app\common\util\Housekeeping;

/**
 * Step 1b (1.2.1): site.theme_switcher_show = 'always' in application/extra/maccms.php when the key is missing; a stored
 * value is never changed (ThemeSwitcher::upgradeFile() / upgradeSite(), covered by tests/theme_switcher.php).
 * Returns the line printed after "1b. Settings: " (its first word is 'added' only when the file was written).
 */
function upgrade_settings($file)
{
    if (!class_exists('\app\common\util\ThemeSwitcher') || !method_exists('\app\common\util\ThemeSwitcher', 'upgradeFile')) {
        return 'not written: application/common/util/ThemeSwitcher.php is older than 1.2.1';
    }
    $state = \app\common\util\ThemeSwitcher::upgradeFile($file);
    $say = [
        'added' => 'added site.theme_switcher_show = always',
        'already' => 'already there',
        'missing' => 'not written: application/extra/maccms.php is missing',
        'unreadable' => 'not written: application/extra/maccms.php has no site settings',
        'unwritable' => 'not written: application/extra/maccms.php is not writable (the site uses "always" anyway)',
    ];
    return $say[$state] ?? 'not written: ' . $state;
}

$out = [];
$say = function ($line) use ($opt) {
    if (!$opt['json']) { echo $line, PHP_EOL; }
};
$lock = @fopen(ScaleTier::runtime('scale') . 'upgrade.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another upgrade run is working right now; try again when it has finished.\n");
    exit(3);
}
$pdo = ScaleSchema::connect(true);
$pre = ScaleSchema::configPrefix();
if ($pdo === null) {
    fwrite(STDERR, "The database in application/database.php is not reachable.\n");
    exit(2);
}
$importer = class_exists('\app\common\model\AvdbImport');

if ($opt['prepare-rollback']) {
    $paused = $importer && method_exists('\app\common\model\AvdbImport', 'pauseAll')
        ? \app\common\model\AvdbImport::pauseAll('Paused for the rollback to 1.1.0 (tools/upgrade.php --prepare-rollback).') : 0;
    $fold = HitBuffer::fold($pdo, $pre, ['force' => true]);
    $out = ['rollback' => ['paused' => $paused, 'fold' => $fold['state'] ?? '', 'hits_written' => (int)($fold['hits'] ?? 0)]];
    $say('Paused import jobs and automatic imports: ' . $paused);
    $say('View counts written: ' . (int)($fold['hits'] ?? 0) . ' (' . ($fold['state'] ?? '') . ')');
    $say('Now restore the 1.1.0 files. The new tables and columns stay; 1.1.0 ignores them.');
    if ($opt['json']) { echo json_encode($out, JSON_UNESCAPED_SLASHES), PHP_EOL; }
    exit(0);
}

$changed = 0;
// ---- 1. tables (2.12 step 2a; the same as the first admin visit)
$t = ScaleSchema::ensureTables($pdo, $pre);
$out['tables'] = $t;
$changed += count($t['created'] ?? []);
$say('1. Scale tables: ' . (!empty($t['ok']) ? (empty($t['created']) ? 'already there' : 'created ' . implode(', ', $t['created'])) : 'ERROR ' . ($t['error'] ?? '')));
if (class_exists('\app\common\util\FtIndex') && method_exists('\app\common\util\FtIndex', 'ensureSchema')) {
    try {
        if (method_exists('\app\common\util\FtIndex', 'useConnection')) {
            \app\common\util\FtIndex::useConnection($pdo, $pre, (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
            if (method_exists('\app\common\util\FtIndex', 'useRuntime')) { \app\common\util\FtIndex::useRuntime($root . 'runtime'); }
        }
        $before = method_exists('\app\common\util\FtIndex', 'installed') ? \app\common\util\FtIndex::installed() : null;
        \app\common\util\FtIndex::ensureSchema();
        $out['search_tables'] = $before === true ? 'already' : 'ensured';
        if ($before === false) { $changed++; }
    } catch (\Throwable $e) {
        $out['search_tables'] = 'error: ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
    }
    $say('   Search index tables: ' . $out['search_tables']);
}
if ($importer) {
    try {
        $log = method_exists('\app\common\model\AvdbImport', 'upgradeSchema') ? \app\common\model\AvdbImport::upgradeSchema() : [];
        if (method_exists('\app\common\model\AvdbImport', 'ensureSchema')) { (new \app\common\model\AvdbImport())->ensureSchema(); }
        $out['importer'] = $log;
        $changed += count($log);
        $say('   Importer tables and columns: ' . ($log ? count($log) . ' change(s): ' . implode(', ', array_map(function ($e) { return $e['what'] . ' (' . $e['algorithm'] . ', ' . $e['seconds'] . ' s)'; }, $log)) : 'already there'));
    } catch (\Throwable $e) {
        $out['importer'] = 'error: ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
        $say('   Importer tables and columns: ' . $out['importer']);
    }
}

// ---- 1b. settings (1.2.1): the display mode of the visitor theme switcher, added when missing (never changed; the site
// reads a missing or unknown value as "always" anyway, so a read-only settings file is only reported, not fatal)
$out['settings'] = upgrade_settings(APP_PATH . 'extra/maccms.php');
if (strpos($out['settings'], 'added') === 0) { $changed++; }
$say('1b. Settings: ' . $out['settings']);

// ---- 2. list indexes (2.12 step 2b; D-L1)
$t0 = microtime(true);
$ix = ScaleSchema::ensureIndexes($pdo, $pre, function ($msg) use ($say) { $say('   ' . $msg); });
// verify r3 R3-8: an index build that is running already (the admin "Finish upgrade" in another window, an ALTER on the video
// or translation table) is waited for, at most an hour, then checked again: the later steps (the sitemap's per-language dates
// read from the translation index) need what it builds
$waitUntil = microtime(true) + 3600;
$lastSay = 0.0;
while (($ix['state'] ?? '') === 'busy' && microtime(true) < $waitUntil) {
    if (microtime(true) - $lastSay >= 60) {
        $lastSay = microtime(true);
        $say('   another index build is running (' . (string)($ix['error'] ?? 'busy') . '): waiting for it, ' . round(microtime(true) - $t0) . ' s');
    }
    sleep(5);
    $ix = ScaleSchema::ensureIndexes($pdo, $pre, function ($msg) use ($say) { $say('   ' . $msg); });
}
$out['indexes'] = $ix;
$changed += count($ix['added'] ?? []);
$say('2. Indexes (lists, sorts, FULLTEXT): ' . ($ix['state'] ?? '?') . (!empty($ix['added']) ? ', added ' . implode(', ', $ix['added']) . ' in ' . round(microtime(true) - $t0, 1) . ' s' : '')
    . (!empty($ix['error']) ? ' (' . $ix['error'] . ')' : ''));
if (!empty($ix['ft']['sizes']) && is_array($ix['ft']['sizes'])) {
    $parts = [];
    foreach ($ix['ft']['sizes'] as $name => $bytes) { if ($name !== '_common') { $parts[] = $name . ' ' . number_format($bytes / 1048576, 0) . ' MB'; } }
    $say('   FULLTEXT sizes: ' . implode(', ', $parts) . ' (total ' . number_format(array_sum($ix['ft']['sizes']) / 1048576, 0) . ' MB)');
}

// ---- heavy-maintenance window of steps 2b to 5b (engines-r3 R3-8, engines-r5 R5-1): the vocabulary build (2b) reads every
// video row, the search backfill (4) reads and writes them, steps 5 to 5b read the whole video table in long single
// statements (the recount, the rank files' copy). Meanwhile visitors get the stale copy of an expired cached page instead of a
// render next to them, and no page view starts a housekeeping run or an import step beside them (ScaleTier::heavyMark,
// EdgeCache::serve, Housekeeping::hkTrigger / importTrigger: G23 run 1 handed a visitor a 1.2 s housekeeping slice during 2b
// while the marker started at step 5). A marker lives ScaleTier::HEAVY_TTL (1 h) from its last write, so the window renews it
// (at most once a minute) at every step and inside the long loops: a big catalog's window stays marked as long as it runs,
// and the marker of a killed run still ends within the hour. Ended before step 6, and on any exit (shutdown function).
$heavyAt = 0.0;
$heavy = function ($force = false) use (&$heavyAt) {
    if ($force || microtime(true) - $heavyAt >= 60) {
        ScaleTier::heavyMark('upgrade');
        $heavyAt = microtime(true);
    }
};
$heavy(true);
register_shutdown_function(function () { ScaleTier::heavyClear('upgrade'); });

// ---- 2b. filter vocabulary (fp-port: FilterMatch v2 / FilterVocab). The table {prefix}vod_ft_vocab is created when it is
// missing (only under the *_general_ci collations FilterVocab::COLLATIONS; other collations keep the plain LIKE and get no
// table). A 1.1 site that runs the ft-vocab package (live since 2026-10-18) already has it, built by the same format
// (VERSION 2): it is kept and only caught up / rebuilt when stale, switched to a new version or older than a day (fp-port-b:
// or not yet built by this version for the item path of CJK / kana / Hangul / Thai values: one build adds it; fp-port-c: also
// when the last build had an older item-path version ('xiver': this build checks the search document of every visible row)). The build
// reads the video table by primary-key ranges of 2,000 rows (no locks on it) and takes the site's vocabulary lock per
// batch (FilterVocab::dbKey of application/database.php), so page catch-ups, housekeeping and this step never interleave.
if (is_file($root . 'application/common/util/FilterVocab.php')) {
    require_once $root . 'application/common/util/FilterVocab.php';
    $tv = microtime(true);
    try {
        $dbCfg = include $root . 'application/database.php';
        $fv = \app\common\util\FilterVocab::pdo($pdo, $pre, false, \app\common\util\FilterVocab::dbKey(is_array($dbCfg) ? $dbCfg : []));
        $table = $fv->ensureTable();
        $res = ['table' => $table];
        if ($table === 'created') { $changed++; }
        if ($table !== 'exists' && $table !== 'created') {
            $say('2b. Filter vocabulary: not used here (' . $table . '): filters keep the plain LIKE for single words');
        } elseif ($opt['no-ft-vocab']) {
            $res['build'] = 'left to housekeeping';
            $say('2b. Filter vocabulary: table ' . $table . '; build left to housekeeping (--no-ft-vocab)');
        } else {
            $st0 = $fv->state();
            if (!empty($st0['off'])) {
                $res['build'] = 'off';
                $say('2b. Filter vocabulary: switched off (php tools/vod-ft-vocab.php --on to use it): nothing built');
            } else {
                $did = '';
                // a backlog of a ready vocabulary (bulk import, batch edit) first: catch-ups, no full build needed for it
                $guard = 0;
                while (!empty($st0['pend']) && !empty($st0['ready']) && (int)($st0['ver'] ?? 0) === \app\common\util\FilterVocab::VERSION && $guard++ < 100000) {
                    $heavy();
                    if ($fv->catchUp(5, \app\common\util\FilterVocab::DELTA_MAX)) { $did = 'caught up'; }
                    $st0 = $fv->state();
                    if (!empty($st0['stale'])) { break; }
                }
                $lastSay = microtime(true);
                $guard = 0;
                while ($guard++ < 1000000) {
                    // resumable slices of 60 s (the build continues at its cursor): the window's marker is renewed between them
                    $heavy();
                    $r = $fv->buildStep(60, 20, 5, false);
                    if ($r['state'] === 'locked' || $r['state'] === 'wait') { sleep(1); continue; }
                    if ($r['state'] === 'building') {
                        if (microtime(true) - $lastSay >= 30) { $lastSay = microtime(true); $say('   filter vocabulary: at id ' . (int)($r['cursor'] ?? 0) . ' of ' . (int)($r['max'] ?? 0)); }
                        continue;
                    }
                    if ($r['state'] === 'done') { $did = 'built'; $changed++; }
                    break;
                }
                // fp-port-b (P3-5): a catch-up last, so rows inserted since the last one are in it when this step says current
                $st0 = $fv->state();
                if (empty($st0['off']) && !empty($st0['ready']) && (int)($st0['ver'] ?? 0) === \app\common\util\FilterVocab::VERSION && empty($st0['stale'])) {
                    for ($guard = 0; $guard < 100000; $guard++) {
                        if ($fv->catchUp(5, \app\common\util\FilterVocab::DELTA_MAX)) { break; }
                        $st0 = $fv->state();
                        if (empty($st0['pend']) && (!empty($st0['stale']) || empty($st0['ready']))) { break; }
                    }
                }
                $st = $fv->status();
                $words = 0;
                foreach (\app\common\util\FilterVocab::COLS as $cid) { $words += (int)($st['counts'][$cid]['tokens'] ?? 0); }
                $ready = !empty($st['state']['ready']) && (int)($st['state']['ver'] ?? 0) === \app\common\util\FilterVocab::VERSION && empty($st['state']['stale']);
                $items = 0;
                foreach (\app\common\util\FilterVocab::ITEM_COLS as $cid) { $items += (int)($st['counts'][$cid]['tokens'] ?? 0); }
                $xrows = (int)($st['counts'][\app\common\util\FilterVocab::COL_XROW]['tokens'] ?? 0);
                // fp-port-c: the item path needs a full build with this version's document checks ('xiver' = ITEMS_VERSION)
                $itemReady = \app\common\util\FilterVocab::itemsComplete($st['state']); // and that build was the last full build ('xibuilt')
                $res += ['build' => $did !== '' ? $did : 'current', 'ready' => $ready, 'words' => $words, 'pend' => !empty($st['state']['pend']),
                    'item_path' => $itemReady, 'items' => $items, 'exception_rows' => $xrows, 'seconds' => round(microtime(true) - $tv, 1)];
                $say('2b. Filter vocabulary: ' . ($table === 'created' ? 'table created, ' : '') . ($did !== '' ? $did : 'already current') . ', '
                    . ($ready ? 'READY' : 'NOT READY (housekeeping finishes it)') . ', ' . number_format($words) . ' words'
                    . (!empty($st['state']['pend']) ? ', backlog left for housekeeping' : '')
                    . ', item path ' . ($itemReady ? 'ready (' . number_format($items) . ' CJK items, ' . $xrows . ' exception rows)' : 'waits for a build')
                    . ' (' . round(microtime(true) - $tv, 1) . ' s)');
            }
        }
        $out['ft_vocab'] = $res;
    } catch (\Throwable $e) {
        $out['ft_vocab'] = 'error: ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
        $say('2b. Filter vocabulary: ' . $out['ft_vocab'] . ' (filters keep the plain LIKE for single words; php tools/vod-ft-vocab.php --build retries)');
    }
}

// ---- 3. file locks and proxies (se-R15, se-R2c)
$heavy();
$self = RequestGate::selfTest();
$notices = RequestGate::notices();
$out['flock'] = $self;
$out['proxy_notice'] = $notices['proxy'] ?? null;
$say('3. File locks: ' . ($self === 'ok' ? 'ok' : 'NOT SUPPORTED on this disk: the request gates stay open (see Speed & protection)'));
if (!empty($notices['proxy'])) {
    $p = $notices['proxy'];
    $how = (string)($p['header'] ?? '');
    $say('   PROXY: requests arrive from ' . ($p['peer'] ?? '?') . ($how === 'user-agents' ? ' with many different browsers (a CDN or firewall in front of the site)'
        : ($how !== '' ? ' with the header ' . $how : '')) . ' but no trusted proxy is set:');
    $say('   per-visitor limits are off for these requests. Open Admins & backup > Speed & protection > "Trust this proxy".');
} else {
    $say('   Proxy: no unconfigured proxy seen');
}
// search engines (owner decision 2026-10-17): verified crawlers are never limited; their published IP lists are fetched
// here once when missing or older than a day (housekeeping keeps them fresh daily; engines without a list are checked by
// reverse DNS)
$cs = \app\common\util\PublicRequestGuard::crawlerStatus($root);
$stale = false;
foreach ($cs['engines'] as $engine => $st) {
    if ($st['list'] && (int)$st['at'] < time() - 86400) { $stale = true; }
}
if ($stale && !$opt['no-network']) {
    $hk = Housekeeping::scale($root, 'cli', 30, ['tasks' => ['crawlers']]);
    $out['crawlers'] = $hk['tasks']['crawlers'] ?? ($hk['state'] ?? '');
    $cs = \app\common\util\PublicRequestGuard::crawlerStatus($root);
}
$line = [];
foreach ($cs['engines'] as $engine => $st) {
    $line[] = $engine . ' ' . ($st['list'] ? ($st['count'] !== null ? number_format((int)$st['count']) . ' ranges' : 'list not loaded yet') : 'reverse DNS');
}
$say('   Search engines never limited: ' . implode(', ', $line));

// ---- 4. search index backfill (2.12 step 3)
$ready = class_exists('\app\common\util\FtIndex') && method_exists('\app\common\util\FtIndex', 'ready') ? \app\common\util\FtIndex::ready() : null;
$tool = $root . 'tools/search-index.php';
if ($ready === true) {
    $out['search'] = 'ready';
    $say('4. Search index: ready');
} elseif ($opt['no-search-backfill'] || !is_file($tool) || $ready === null) {
    $out['search'] = 'not built';
    $say('4. Search index: not built yet; run "php tools/search-index.php --backfill" (until then search works the old way)');
} elseif (!function_exists('passthru') || (PHP_VERSION_ID >= 80000 && !function_exists('set_time_limit'))) {
    // the backfill runs in a child PHP started with passthru() that calls set_time_limit(): PHP 8 removes a disabled
    // function (calling it is a fatal error), and the child reads the same php.ini - left to the admin then
    // (UPDATER-VERIFY-HOSTING-6 VH6-1)
    $out['search'] = 'not built';
    $say('4. Search index: not built here (this PHP disables passthru or set_time_limit, which the backfill needs): build it in the admin, or run "php tools/search-index.php --backfill" where PHP allows it (until then search works the old way)');
} else {
    $say('4. Search index: building (php tools/search-index.php --backfill) ...');
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --backfill --root=' . escapeshellarg(rtrim($root, '/'));
    // the child runs under the window's marker, renewed right before it (a full hour for the backfill: a longer one, above a
    // few million videos on a slow disk, runs its remainder unmarked, as this whole step did before) and right after it
    $heavy(true);
    passthru($cmd, $rc);
    $heavy(true);
    $out['search'] = $rc === 0 ? 'built' : 'backfill exit ' . $rc;
    $changed++;
    $say('   ' . $out['search']);
}

// ---- 5. first page counts and sitemap blocks (2.12 step 4)
// engines-r3 R3-8: steps 5 to 5b read the whole video table in long single statements (the recount, the rank files' copy):
// the window's marker (started before step 2b) is renewed here, so these steps get a full hour from their start whatever
// steps 2b to 4 took, and again at every slice below
$heavy(true);
// due = never counted on this site (runtime/scale/counts.json), marked dirty, or the nightly recount is due
if (ScaleCounts::due()) {
    $r = ScaleCounts::recount($pdo, $pre);
    $out['counts'] = $r;
    $changed++;
    $say('5. Page counts: ' . (!empty($r['ok']) ? 'counted ' . number_format((int)$r['vod_all']) . ' published videos in ' . round($r['ms'] / 1000, 1) . ' s' : ($r['state'] ?? 'error') . ' ' . ($r['error'] ?? '')));
} else {
    $out['counts'] = 'already';
    $say('5. Page counts: already counted');
}
$sm = Sitemap::bucketState();
$cfg = Sitemap::bucketConfig();
if (empty($sm['built']) || ($sm['sig'] ?? '') !== $cfg['sig']) {
    // verify r2 R2-8 / r3 R3-8: the blocks, then the per-language lastmod (a first-in-first-out queue worked off bucket by
    // bucket, index-only windows on idx_tr_lastmod from step 2); no rotating sweep in this loop; on a shared host every
    // statement is followed by a pause (one core: about the statement's own time), so visitors keep the database
    $pause = Sitemap::pauseShare();
    $runs = 0;
    $idle = 0;
    $t5 = microtime(true);
    $langDone = 0;
    $lastLine = 0.0;
    // progress = the block cursor, the language queue or the head bucket's read position moved (every call reads at least
    // one window); three calls without progress end the loop (housekeeping continues), so it can never spin
    $mark = function () {
        $s = Sitemap::bucketState();
        $part = ScaleTier::readJson(ScaleTier::runtime('scale') . 'sitemap_lang_part.json');
        return (int)($s['cursor'] ?? 0) . '|' . (int)!empty($s['built']) . '|' . count((array)($s['lang_pending'] ?? [])) . '|'
            . (is_array($part) ? (int)($part['bucket'] ?? -1) . ':' . (int)($part['lo'] ?? 0) : '-');
    };
    do {
        $heavy();
        $before = $mark();
        $r = Sitemap::refreshBuckets($pdo, $pre, microtime(true) + 20, ['sweep_seconds' => 0, 'pause' => $pause]);
        $runs++;
        $after = Sitemap::bucketState();
        $langDone += (int)($r['lang_done'] ?? 0);
        $idle = ($mark() !== $before || (int)($r['lang_done'] ?? 0) > 0) ? 0 : $idle + 1;
        if (microtime(true) - $lastLine >= 30) {
            $lastLine = microtime(true);
            $say('   sitemap blocks: ' . ($r['state'] ?? '?') . ', ' . (int)($after['cursor'] ?? 0) . ' blocks read, languages done for ' . $langDone
                . ' blocks, ' . count((array)($after['lang_pending'] ?? [])) . ' waiting' . (!empty($r['lang_index']) ? ' (index-only)' : '') . ', ' . round(microtime(true) - $t5) . ' s');
        }
        $more = ($r['state'] ?? '') === 'building' || (int)($r['lang_pending'] ?? 0) > 0;
    } while ($more && !in_array($r['state'] ?? '', ['error', 'busy'], true) && $idle < 3 && $runs < 100000);
    $out['sitemap'] = $r + ['runs' => $runs, 'seconds' => round(microtime(true) - $t5, 1), 'pause' => $pause];
    $changed++;
    $say('   Sitemap blocks: ' . ($r['state'] ?? '?') . ' (' . (int)(Sitemap::bucketState()['cursor'] ?? 0) . ' blocks of ' . $cfg['size'] . ' ids'
        . ($langDone ? ', languages of ' . $langDone . ' blocks' . (!empty($r['lang_index']) ? ' from the translation index' : '') : '') . ') in '
        . round(microtime(true) - $t5, 1) . ' s' . ((int)($r['lang_pending'] ?? 0) > 0 ? '; ' . (int)$r['lang_pending'] . ' blocks wait for housekeeping' : ''));
} else {
    $out['sitemap'] = 'already';
    $say('   Sitemap blocks: already built');
}

// ---- 5d. flood-r4 F3 / G9: the rows lists of the sitemap blocks (runtime/scale/sitemap_rows: a never-built video file is
// answered from one file read instead of ~3,000 table pages on a cold database). Blocks read in step 5 got theirs there; blocks
// built before this release get them here within --sitemap-rows-budget seconds, newest first (housekeeping writes the rest).
// Not counted as upgrade work (a second run stays a no-op).
if ((int)$opt['sitemap-rows-budget'] > 0 && method_exists(Sitemap::class, 'rowsBackfill')) {
    $heavy();
    $rb = Sitemap::rowsBackfill($pdo, $pre, microtime(true) + (int)$opt['sitemap-rows-budget'], ['pause' => Sitemap::pauseShare()]);
    $out['sitemap_rows'] = $rb;
    $rbState = (string)($rb['state'] ?? '');
    if (in_array($rbState, ['done', 'partial'], true)) {
        $say('5d. Sitemap rows lists: ' . (int)$rb['written'] . ' written, ' . (int)$rb['fresh'] . ' current, ' . (int)$rb['left'] . ' left for housekeeping, '
            . round((int)($rb['ms'] ?? 0) / 1000, 1) . ' s');
    } else {
        $say('5d. Sitemap rows lists: left to housekeeping (' . ($rbState !== '' ? $rbState : 'no result') . (isset($rb['error']) ? ': ' . $rb['error'] : '') . ')');
    }
} else {
    $out['sitemap_rows'] = 'skipped';
}

// ---- 5c. site-r3 N20 (b): the sitemap files are built now (tools/housekeeping.php --sitemap-files-only in a child process:
// the build needs the front-end boot), newest first, within --sitemap-budget seconds; a search engine then meets stored files
// instead of a first build in its request (housekeeping builds the rest). Not counted as upgrade work (a second run stays a
// no-op).
if ((int)$opt['sitemap-budget'] > 0) {
    $heavy();
    $pf = Housekeeping::prebuildSitemapFiles($root, (int)$opt['sitemap-budget'], in_array($opt['scheme'], ['http', 'https'], true) ? (string)$opt['scheme'] : '');
    $out['sitemap_files'] = $pf;
    $pfState = (string)($pf['state'] ?? '');
    if (in_array($pfState, ['done', 'error'], true) && isset($pf['built'])) {
        $say('5c. Sitemap files: ' . (int)$pf['built'] . ' built (' . (int)($pf['lang_built'] ?? 0) . ' language files), ' . (int)($pf['fresh'] ?? 0) . ' already stored, '
            . (int)($pf['left'] ?? 0) . ' left for housekeeping, ' . round((float)($pf['seconds'] ?? 0), 1) . ' s' . ($pfState === 'error' ? ' (' . (string)($pf['error'] ?? 'error') . ')' : ''));
    } elseif ($pfState === 'scheme_unknown') {
        $say('5c. Sitemap files: built by housekeeping after the first web visit of the sitemap index (or run again with --scheme=https|http)');
    } else {
        $say('5c. Sitemap files: left to housekeeping (' . ($pfState !== '' ? $pfState : 'no result') . (isset($pf['why']) ? ': ' . $pf['why'] : '') . ')');
    }
} else {
    $out['sitemap_files'] = 'skipped';
}

// ---- 5b (first part). search statistics (engines-r6 NEW-1, owner decision D-10): a search index built by an earlier 1.2.0
// build keeps its old term statistics through an upgrade (vod_ft_state inv_doc 1 = order not checked, term_doc 0), and neither
// housekeeping nor the documented cron line refreshes them, so the round-5 tag route and the exact performer / tag tokens stayed
// off. When the index is ready and FtIndex::termsDue(): FtIndex::terms() (7.1 M: about 40-50 s), then the search term map
// (FtIndex::mapBuild(), 7.1 M: about 200-240 s) because a map older than the new statistics counts as missing; a map missing
// for another reason is built here too. Child process tools/housekeeping.php --search-stats-only (memory limit 1 GB, low
// priority; Housekeeping::refreshSearchStats, the window's marker renewed while it runs), the same work in this process when no
// child can be started; --no-deep-maps leaves the map to housekeeping. One line; counted as work only when something was
// refreshed or built, so a second run stays a no-op.
if (class_exists('\app\common\util\FtIndex') && method_exists('\app\common\util\FtIndex', 'termsDue')) {
    $heavy(true);
    $ss = Housekeeping::refreshSearchStats($root, 0, !$opt['no-deep-maps'], $heavy);
    if (in_array((string)($ss['state'] ?? ''), ['disabled', 'no_cli'], true) || (($ss['state'] ?? '') === 'error' && ($ss['why'] ?? '') === 'proc_open')) {
        $ss = Housekeeping::searchStats($root, 0.0, !$opt['no-deep-maps'], $pdo, $pre) + ['via' => 'inline'];
    }
    $heavy(true);
    $out['search_stats'] = $ss;
    $ssState = (string)($ss['state'] ?? '');
    $ssMap = (string)($ss['map'] ?? '');
    $mapText = '';
    if ($ssMap === 'built') {
        $mapText = '; term map rebuilt (' . number_format((float)($ss['map_mb'] ?? 0), 0) . ' MB) in ' . round((float)($ss['map_s'] ?? 0), 1) . ' s';
    } elseif ($ssMap === 'skipped' && $opt['no-deep-maps'] && $ssState === 'refreshed') {
        $mapText = '; term map left to housekeeping (--no-deep-maps)';
    } elseif (in_array($ssMap, ['busy', 'building', 'error', 'not_ready'], true)) {
        $mapText = '; term map ' . ($ssMap === 'building' ? 'being built by another run' : $ssMap . ': housekeeping rebuilds it (or php tools/search-index.php --map)');
    }
    if ($ssState === 'refreshed') {
        $say('5b. Search statistics: refreshed (' . number_format((int)($ss['terms'] ?? 0)) . ' terms' . (!empty($ss['exact']) ? ', tag and performer words counted exactly' : '')
            . ') in ' . round((float)($ss['terms_s'] ?? 0), 1) . ' s' . $mapText);
    } elseif ($ssState === 'current') {
        $say('5b. Search statistics: current' . $mapText);
    } elseif ($ssState === 'not_ready' || $ssState === 'not_installed') {
        $say('5b. Search statistics: ' . ($ssState === 'not_ready' ? 'with the search index (its build writes them)' : 'not part of this build'));
    } else {
        $say('5b. Search statistics: not refreshed (' . ($ssState !== '' ? $ssState : 'no result') . (isset($ss['why']) && $ss['why'] !== $ssState ? ': ' . $ss['why'] : '')
            . '): run "php tools/search-index.php --terms" and then "--map" later');
    }
    if ($ssState === 'refreshed' || $ssMap === 'built') { $changed++; }
}

// ---- 5b. first deep-page maps (XVID-NOLIMIT-BRIEF H2, B1-B2, C2): lane S builders, command line without time limit
$deepRun = function () use ($pdo, $pre, $root, $say, $opt, $heavy) {
    $res = [];
    $util = '\\app\\common\\util\\';
    $bind = function ($cls) use ($pdo, $pre, $root) {
        if (method_exists($cls, 'useConnection')) { $cls::useConnection($pdo, $pre); }
        if (method_exists($cls, 'useRuntime')) { $cls::useRuntime($root . 'runtime'); }
    };
    $pct = function ($st) {
        if (!is_array($st)) { return null; }
        if (isset($st['pct']) && is_numeric($st['pct'])) { return (float)$st['pct']; }
        if (isset($st['progress']) && is_numeric($st['progress'])) { return 100 * (float)$st['progress']; }
        return null;
    };
    // page maps: refresh() in slices of 30 s until it reports nothing left (progress line per slice)
    $pm = $util . 'ListPageMap';
    if (!class_exists($pm) || !method_exists($pm, 'refresh')) {
        $res['pagemap'] = 'not installed';
        $say('5b. Deep list pages (page maps): not part of this build');
    } else {
        $bind($pm);
        $t0 = microtime(true);
        $slices = 0;
        do {
            $heavy();
            $r = (array)$pm::refresh(30.0);
            $slices++;
            $st = method_exists($pm, 'state') ? (array)$pm::state() : [];
            $p = $pct($st);
            $say('    page maps: slice ' . $slices . ', ' . ($p !== null ? round($p, 1) . ' %' : (string)($r['state'] ?? '?'))
                . (isset($r['rows']) ? ', ' . number_format((int)$r['rows']) . ' rows' : '') . ', ' . round(microtime(true) - $t0, 1) . ' s');
            $more = in_array((string)($r['state'] ?? ''), ['more', 'building', 'partial', 'running'], true) || !empty($r['more']);
        } while ($more && $slices < 100000);
        $res['pagemap'] = ['state' => (string)($r['state'] ?? ''), 'slices' => $slices, 'seconds' => round(microtime(true) - $t0, 1),
            'added' => (int)($r['added'] ?? -1)];
        $say('5b. Deep list pages (page maps): ' . ($res['pagemap']['state'] === 'error' ? 'ERROR ' . (string)($r['error'] ?? '') : 'ready') . ' in ' . $res['pagemap']['seconds'] . ' s');
    }
    // rank files: one full build per sort (hits_day, hits, hits_week, hits_month, score, up, time_add), skipped while the
    // housekeeping record (runtime/scale/hk.json 'deep') says the sort was built inside its interval; under hk.lock, so
    // housekeeping never builds the same files at the same time, and the record is updated for it
    $lr = $util . 'ListRank';
    $sorts = \app\common\util\Housekeeping::RANK_SORTS;
    if (!class_exists($lr) || !method_exists($lr, 'build')) {
        $res['rank'] = 'not installed';
        $say('    Sorted list pages (rank files): not part of this build');
    } else {
        $bind($lr);
        $done = [];
        $hkFile = ScaleTier::runtime('scale') . 'hk.json';
        $hkLock = @fopen(ScaleTier::runtime('scale') . 'hk.lock', 'c');
        if ($hkLock) { flock($hkLock, LOCK_EX); }
        try {
            $i = 0;
            $due = [];
            foreach ($sorts as $sort => $every) {
                $i++;
                $hk = ScaleTier::readJson($hkFile);
                $hk = is_array($hk) ? $hk : [];
                $okAt = (int)($hk['deep']['rank_ok'][$sort] ?? 0);
                if ($okAt > 0 && time() - $okAt < $every) {
                    $done[$sort] = 'already';
                    $say('    rank files: ' . $i . ' / ' . count($sorts) . ' (' . $sort . ') already built ' . (time() - $okAt) . ' s ago');
                    continue;
                }
                $due[$i] = $sort;
            }
            $record = function ($sort, array $r, $t0, $i) use (&$done, $hkFile, $say, $sorts) {
                $done[$sort] = (string)($r['state'] ?? 'done');
                if (!in_array($done[$sort], ['error', 'busy', 'failed'], true)) {
                    $hk = ScaleTier::readJson($hkFile);
                    $hk = is_array($hk) ? $hk : [];
                    $hk['deep']['rank'][$sort] = $hk['deep']['rank_ok'][$sort] = time();
                    ScaleTier::writeAtomic($hkFile, json_encode($hk, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
                }
                $secs = isset($r['seconds']) && is_numeric($r['seconds']) ? (float)$r['seconds'] : microtime(true) - $t0;
                $say('    rank files: ' . $i . ' / ' . count($sorts) . ' (' . $sort . ') ' . $done[$sort] . ', ' . round($secs, 1) . ' s'
                    . (isset($r['rows']) ? ', ' . number_format((int)$r['rows']) . ' videos' : ''));
            };
            if (count($due) >= 2 && method_exists($lr, 'buildAll')) {
                // CONTRACT HO-S-R2-1 (b): every due sort from ONE read of the video table (a narrow temporary copy of the sort
                // columns; lane S measured 3.06 M -> 584 k reads at 7.1 M); ListRank falls back to one build per sort itself
                $t0 = microtime(true);
                $heavy();
                try { $all = (array)$lr::buildAll(array_values($due)); } catch (\Throwable $e) { $all = ['state' => 'error', 'sorts' => [], 'error' => get_class($e)]; }
                $slowest = 0.0;
                foreach ($due as $i => $sort) {
                    $r = isset($all['sorts'][$sort]) && is_array($all['sorts'][$sort]) ? $all['sorts'][$sort] : ['state' => 'error', 'error' => (string)($all['error'] ?? 'missing')];
                    $record($sort, $r, $t0, $i);
                    if (($r['state'] ?? '') === 'done') { $slowest = max($slowest, (float)($r['seconds'] ?? 0)); }
                }
                if ($slowest > 0) {
                    // verify r3 R3-4: the cost of one full read decides whether housekeeping may rebuild hits_day hourly
                    $hk = ScaleTier::readJson($hkFile);
                    $hk = is_array($hk) ? $hk : [];
                    $hk['deep']['rank_read_s'] = round((!empty($all['shared']) ? (float)($all['copy_seconds'] ?? 0) : 0.0) + $slowest, 2);
                    ScaleTier::writeAtomic($hkFile, json_encode($hk, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
                }
                $say('    rank files: one read for ' . count($due) . ' sorts' . (!empty($all['shared']) ? ' (shared copy ' . round((float)($all['copy_seconds'] ?? 0), 1) . ' s)' : '')
                    . ', ' . round(microtime(true) - $t0, 1) . ' s');
            } else {
                foreach ($due as $i => $sort) {
                    $heavy();
                    $t0 = microtime(true);
                    try { $r = (array)$lr::build($sort); } catch (\Throwable $e) { $r = ['state' => 'error', 'error' => get_class($e)]; }
                    $record($sort, $r, $t0, $i);
                }
            }
        } finally {
            if ($hkLock) { flock($hkLock, LOCK_UN); fclose($hkLock); }
        }
        $res['rank'] = $done;
        $say('    Sorted list pages (rank files): ' . (in_array('error', $done, true) ? 'some failed, housekeeping tries again' : 'ready'));
    }
    // search term map: built when missing (the search index must be ready)
    $ft = $util . 'FtIndex';
    if (!class_exists($ft) || !method_exists($ft, 'mapBuild') || !method_exists($ft, 'mapState')) {
        $res['ftmap'] = 'not installed';
        $say('    Deep search pages (term map): not part of this build');
    } elseif (method_exists($ft, 'ready') && $ft::ready() !== true) {
        $res['ftmap'] = 'search index not ready';
        $say('    Deep search pages (term map): after the search index (housekeeping builds it then)');
    } elseif (!\app\common\util\Housekeeping::mapMissing((array)$ft::mapState())) {
        $res['ftmap'] = 'already';
        $say('    Deep search pages (term map): already built');
    } else {
        $t0 = microtime(true);
        $heavy();
        try { $r = (array)$ft::mapBuild(); } catch (\Throwable $e) { $r = ['state' => 'error', 'error' => get_class($e)]; }
        $res['ftmap'] = (string)($r['state'] ?? 'done');
        $say('    Deep search pages (term map): ' . $res['ftmap'] . ' in ' . round(microtime(true) - $t0, 1) . ' s');
    }
    return $res;
};
if ($opt['no-deep-maps']) {
    $out['deep'] = 'skipped';
    $say('5b. Deep-page maps: left to housekeeping (--no-deep-maps)');
} else {
    if (!class_exists('\\app\\common\\util\\Housekeeping')) { require_once $root . 'application/common/util/Housekeeping.php'; }
    $out['deep'] = $deepRun();
    // "did work" = a page-map slice added rows (or reported no count), a rank file or the term map was built
    $deepWork = is_array($out['deep']['pagemap'] ?? null) && (($out['deep']['pagemap']['slices'] ?? 0) > 1 || ($out['deep']['pagemap']['added'] ?? -1) !== 0)
        || (is_array($out['deep']['rank'] ?? null) && array_diff($out['deep']['rank'], ['already']))
        || !in_array($out['deep']['ftmap'] ?? '', ['not installed', 'already', 'search index not ready'], true);
    if ($deepWork) { $changed++; }
}
ScaleTier::heavyClear('upgrade');

// ---- 6. tier and hosting row (HO-E-1)
ScaleTier::refreshWith($pdo, $pre);
ScaleTier::reset();
$info = ScaleTier::info();
$rows = (int)$info['rows'];
$out['tier'] = ['tier' => (int)$info['tier'], 'rows' => $rows, 'list_cap' => (int)$info['list_cap'], 'by_cap' => (int)$info['by_cap'],
    'search_cap' => (int)($info['search_cap'] ?? 0), 'stmt_limit' => (int)$info['stmt_limit'], 'edge_max_mb' => (int)$info['edge_max_mb']];
$capText = function ($n) { return (int)$n > 0 ? 'up to page ' . number_format((int)$n) : 'no limit'; };
$say(sprintf('6. Scale tier %d (%s videos): page depth of lists %s, sorted lists %s, search %s; statement limit %d s; page cache up to %d MB',
    $info['tier'], number_format($rows), $capText($info['list_cap']), $capText($info['by_cap']), $capText($info['search_cap'] ?? 0), $info['stmt_limit'], $info['edge_max_mb']));
$hosting = [
    [50000, 'up to 50,000 videos', 'shared hosting; MySQL buffer pool 128 MB is fine; import from the admin page; cron recommended'],
    [600000, '50,000-600,000 videos', 'shared hosting with a 3 GB+ database (English) or a VPS 2 vCPU / 2 GB (all languages); buffer pool 128-512 MB; import from the admin page or cron + tools/import.php (both import everything; cron is faster)'],
    [2000000, '600,000-2,000,000 videos', 'VPS 2 vCPU / 2 GB, 20 GB disk; buffer pool 512 MB; import from the admin page or tools/import.php (both import everything); slim-index tool offered'],
    [PHP_INT_MAX, '2,000,000-7,100,000 videos', 'VPS 2 vCPU / 4 GB, 40-80 GB disk; buffer pool 1-1.5 GB; import from the admin page or tools/import.php (both import everything); cron recommended (maintenance and the faster import); slim-index tool recommended'],
];
foreach ($hosting as $row) {
    if ($rows <= $row[0]) {
        $out['hosting'] = $row[1] . ': ' . $row[2];
        $say('   Hosting for ' . $row[1] . ' (docs/HOSTING.md): ' . $row[2]);
        break;
    }
}
if ((int)$info['tier'] >= 3 && Housekeeping::cronSeenAt() < time() - Housekeeping::CRON_FRESH) {
    $say('   Set up cron: at this size, housekeeping from page views is not enough:');
    $say('   */5 * * * * ' . PHP_BINARY . ' ' . $root . 'tools/housekeeping.php >/dev/null 2>&1');
}
$out['changed'] = $changed;
$say($changed === 0 ? 'Nothing to do: the upgrade was already finished.' : 'Upgrade finished (' . $changed . ' step(s) did work).');
if ($opt['json']) { echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL; }
flock($lock, LOCK_UN);
fclose($lock);
exit(empty($t['ok']) || ($ix['state'] ?? '') === 'error' ? 1 : 0);
