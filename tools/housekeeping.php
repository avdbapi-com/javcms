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
// Runs the self-maintenance of app\common\util\Housekeeping from the command line (the site also runs it by itself).
// Usage: php tools/housekeeping.php [--root=<site folder>] [--all] [--dry-run] [--budget-ms=1000] [--pause-ms=20]
//                                   [--sleep-ms=200] [--max-runs=100000] [--no-db] [--status] [--no-scale] [--scale-only]
//                                   [--scale-budget=20] [--no-sitemap-files] [--sitemap-budget=30] [--scheme=http|https]
//        php tools/housekeeping.php --sitemap-files-only [--sitemap-budget=30] [--scheme=http|https] [--root=<site folder>]
//        php tools/housekeeping.php --search-stats-only [--search-budget=0] [--no-map] [--root=<site folder>]
//        php tools/housekeeping.php --write-crawler-lists [--root=<site folder>]
//   default   one bounded pass slice (continues where the previous one stopped)
//   --all     start a new pass at folder 00 and repeat slices until it has covered all of runtime/cache (backlog clean-up)
//   --dry-run count only (one slice from folder 00; use a large --budget-ms for the whole cache); nothing is deleted, no state written
//   --status  print the last run, the last full pass, totals and task errors (and the 1.2.0 scale jobs)
//   1.2.0: every run also runs the scale jobs once (hit fold, counts, sitemap buckets, search index, edge cache eviction,
//   cache caps, Cloudflare ranges; own connection without statement limit, --scale-budget seconds) and records the cron
//   heartbeat, so page views stop running them. Cron: */5 * * * * <php> <root>/tools/housekeeping.php >/dev/null 2>&1
//   --no-scale  skip them; --scale-only  only them (no cache pass)
//   1.2.0 (verify r2 R2-12): after the scale jobs the sitemap files of changed buckets are built and stored (the index and
//   the default-language video files whose stored copy is missing or older than 20 h, newest first, --sitemap-budget
//   seconds, default 30) with the framework booted as index.php boots it, so a search engine gets stored files instead of
//   waiting for a build; --no-sitemap-files skips it. The scheme is the configured canonical scheme, else the one the index
//   was last fetched with (nothing is built before the first web request of the index). verify r3 N20: also the language
//   video files the index lists (changed or missing copies) and the files a crawler was answered from an older copy.
//   --write-crawler-lists  packaging (before a release): fetch the search engines' published IP lists and store them, checked
//   like the daily refresh, as application/data/crawlers/<engine>.json (the lists shipped in the package: verified crawlers
//   are recognized from the first request on every host, verify r3 OW1); prints the result and exits.
//   --sitemap-files-only  site-r3 N20 (b): only the sitemap file prebuild (--sitemap-budget seconds, default 30), no cache pass
//   and no scale jobs; prints {"sitemap_files": {"state", "built", "lang_built", "fresh", "left", ...}}. Housekeeping::
//   prebuildSitemapFiles() runs it in a child process for tools/upgrade.php step 5c (--sitemap-budget=120), for the web
//   installer's last step (after install.lock is written) and for the admin "Finish upgrade" once the list indexes are
//   complete (both 30 s, result line on the page; CONTRACT HO-H-R3-4), so a search engine meets stored files from the start.
//   --scheme=http|https  the scheme of the sitemap URLs when the site's canonical scheme is "auto" and no web request of the
//   index was seen yet (the installer and "Finish upgrade" pass the scheme of their own request).
//   --search-stats-only  engines-r6 NEW-1 (owner decision D-10): only the search index's term statistics (refreshed when due:
//   statistics of an earlier 1.2.0 build, more than 5 % new videos, older than a week) and then the search term map when it
//   is missing or older than them; no cache pass and no scale jobs; prints {"search_stats": {"state", "terms", "exact",
//   "map", ...}}. --search-budget=N seconds (0 = no limit): no step starts later and the map only when its estimate fits;
//   --no-map leaves the map to housekeeping. Housekeeping::refreshSearchStats() runs it in a child process for
//   tools/upgrade.php step 5b (no limit) and the admin "Finish upgrade" (60 s); by hand it is the same as
//   "php tools/search-index.php --terms" followed by "--map" when they are due.
//   engines-r7 NEW-2: the PHP memory limit is raised to 1 GB (never lowered), so the term-map build fits; where a host keeps it
//   lower than the build needs, the run skips that build ('memory' in the scale result and in runtime/scale/hk.json).
// Only the site's own runtime folders and its own database (application/database.php) are used. Credentials are never printed.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
// One-click update (1.2.1): no run while an update replaces files (it would load half-replaced code). The job holds a
// shared lock on runtime/update-jobs.lock while it runs: an update waits for running jobs before it replaces files.
$javcmsUpdJobLock = is_dir(dirname(__DIR__) . '/runtime') ? @fopen(dirname(__DIR__) . '/runtime/update-jobs.lock', 'c') : false;
if ($javcmsUpdJobLock) { @flock($javcmsUpdJobLock, LOCK_SH); }
if (is_file(dirname(__DIR__) . '/runtime/update/maintenance.php')) { fwrite(STDERR, "An update is replacing files right now; skipped.\n"); exit(0); }
if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, "PHP 7.4 or newer is required (this is " . PHP_VERSION . ")\n");
    exit(2);
}
// engines-r7 NEW-2: the whole-table builds of a housekeeping run (the search term map: about 222 MB of PHP memory at 7 M videos,
// rank files) need more than the 128 MB a command-line PHP often has (the cron line died there with a fatal error): raised to
// 1 GB like tools/upgrade.php and tools/search-index.php (never lowered; -1 = no limit stays; a host ceiling such as PHP 8.5's
// max_memory_limit may keep it lower: housekeeping then skips the map build with 'memory' instead of dying)
(function () {
    $v = trim((string)ini_get('memory_limit'));
    if ($v === '' || $v === '-1' || !function_exists('ini_set')) { return; }
    $n = (int)$v;
    $u = strtolower(substr($v, -1));
    $n *= $u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1));
    if ($n > 0 && $n < 1073741824) { @ini_set('memory_limit', '1024M'); }
})();
$opt = ['root' => dirname(__DIR__), 'all' => false, 'dry-run' => false, 'budget-ms' => 1000, 'pause-ms' => 20, 'sleep-ms' => 200,
    'max-runs' => 100000, 'no-db' => false, 'status' => false, 'no-scale' => false, 'scale-only' => false, 'scale-budget' => 20,
    'no-sitemap-files' => false, 'sitemap-budget' => 30, 'write-crawler-lists' => false, 'sitemap-files-only' => false, 'scheme' => '',
    'search-stats-only' => false, 'search-budget' => 0, 'no-map' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) || !array_key_exists($m[1], $opt)) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
    $opt[$m[1]] = isset($m[2]) ? $m[2] : true;
}
$root = realpath((string)$opt['root']);
if ($root === false || !is_file($root . '/application/common/util/Housekeeping.php') || !is_dir($root . '/runtime')) {
    fwrite(STDERR, "Not a JavCMS site folder: {$opt['root']}\n");
    exit(2);
}
$scaleRoot = str_replace('\\', '/', $root) . '/';
defined('ROOT_PATH') || define('ROOT_PATH', $scaleRoot);
require $root . '/application/common/util/Housekeeping.php';

$maccms = is_file($root . '/application/extra/maccms.php') ? include $root . '/application/extra/maccms.php' : [];
$maccms = is_array($maccms) ? $maccms : [];
$cacheType = strtolower(isset($maccms['app']['cache_type']) ? (string)$maccms['app']['cache_type'] : 'file');

/**
 * 1.2.0 (verify r2 R2-12): boot the framework as index.php does (front-end entrance, the site's host and scheme, the route
 * names url() reverses), then build the sitemap files of changed buckets (Sitemap::prebuild). Runs last: the framework's
 * error handler stays active afterwards. Returns the prebuild result or ['state' => why not].
 */
function javcms_hk_sitemap_files($root, $budget, $forceScheme = '')
{
    $root = rtrim(str_replace('\\', '/', (string)$root), '/') . '/';
    if (!is_file($root . 'application/data/install/install.lock') || !is_file($root . 'thinkphp/base.php') || !is_file($root . 'application/database.php')) {
        return ['state' => 'not_installed'];
    }
    $m = include $root . 'application/extra/maccms.php';
    $m = is_array($m) ? $m : [];
    $host = trim((string)($m['site']['site_url'] ?? ''));
    if ($host === '' || preg_match('/[\s\/@?#\\\\]/', $host) || !preg_match('/^[A-Za-z0-9.\[\]:-]{1,255}$/', $host)) {
        return ['state' => 'no_site_url'];
    }
    $scheme = (string)($m['rewrite']['canonical_scheme'] ?? 'auto');
    if (!in_array($scheme, ['http', 'https'], true) && in_array((string)$forceScheme, ['http', 'https'], true)) {
        $scheme = (string)$forceScheme;
    }
    if (!in_array($scheme, ['http', 'https'], true)) {
        $seen = @json_decode((string)@file_get_contents($root . 'runtime/scale/sitemap_scheme.json'), true);
        $scheme = is_array($seen) && in_array($seen['scheme'] ?? '', ['http', 'https'], true) ? $seen['scheme'] : '';
        if ($scheme === '') { return ['state' => 'scheme_unknown']; }
    }
    $port = preg_match('/:(\d{1,5})$/', $host, $pm) ? $pm[1] : ($scheme === 'https' ? '443' : '80');
    $_SERVER = array_merge($_SERVER, ['HTTP_HOST' => $host, 'SERVER_NAME' => preg_replace('/:\d{1,5}$/', '', $host), 'SERVER_PORT' => $port,
        'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/sitemap.xml', 'QUERY_STRING' => '', 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php',
        'SCRIPT_FILENAME' => $root . 'index.php', 'DOCUMENT_ROOT' => rtrim($root, '/'), 'HTTP_USER_AGENT' => 'JavCMS-housekeeping', 'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_ACCEPT' => 'application/xml', 'HTTP_ACCEPT_LANGUAGE' => '']);
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['PATH_INFO'], $_SERVER['argv']);
    if ($scheme === 'https') { $_SERVER['HTTPS'] = 'on'; $_SERVER['REQUEST_SCHEME'] = 'https'; } else { $_SERVER['REQUEST_SCHEME'] = 'http'; }
    $_GET = [];
    $_COOKIE = [];
    foreach (['APP_PATH' => $root . 'application/', 'MAC_COMM' => $root . 'application/common/common/', 'MAC_HOME_COMM' => $root . 'application/index/common/',
                 'MAC_ADMIN_COMM' => $root . 'application/admin/common/', 'BIND_MODULE' => 'index', 'ENTRANCE' => 'index', 'IN_FILE' => '/index.php'] as $k => $v) {
        if (!defined($k)) { define($k, $v); }
    }
    if (!defined('MAC_START_TIME')) { define('MAC_START_TIME', microtime(true)); }
    try {
        require_once $root . 'thinkphp/base.php';
        \think\App::initCommon();
        $request = \think\Request::instance();
        $request->module('index');
        \think\App::routeCheck($request, \think\Config::get());
        if (!class_exists('\app\common\util\Sitemap')) { return ['state' => 'not_installed']; }
        return \app\common\util\Sitemap::prebuild(microtime(true) + max(1, (int)$budget));
    } catch (\Throwable $e) {
        return ['state' => 'error', 'error' => get_class($e) . ': ' . substr((string)preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200)];
    }
}

if ($opt['write-crawler-lists']) {
    require_once $root . '/application/common/util/PublicRequestGuard.php';
    $r = \app\common\util\PublicRequestGuard::writeShippedCrawlerLists($scaleRoot, function ($url) { return \app\common\util\Housekeeping::httpsGet($url); });
    echo json_encode(['write_crawler_lists' => $r], JSON_UNESCAPED_SLASHES), PHP_EOL;
    $bad = 0;
    foreach ($r as $one) { if (empty($one['ok'])) { $bad++; } }
    exit($r && !$bad ? 0 : 1);
}

if ($opt['sitemap-files-only']) {
    $scheme = is_string($opt['scheme']) && in_array($opt['scheme'], ['http', 'https'], true) ? $opt['scheme'] : '';
    $r = javcms_hk_sitemap_files($scaleRoot, max(1, (int)$opt['sitemap-budget']), $scheme);
    echo json_encode(['sitemap_files' => $r], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL;
    exit(isset($r['state']) && !in_array($r['state'], ['error', 'no_framework'], true) ? 0 : 1);
}

if ($opt['search-stats-only']) {
    // engines-r6 NEW-1 (D-10): term statistics + term map of the search index (Housekeeping::searchStats; own connection
    // without statement limit, memory limit raised to Housekeeping::SEARCH_STATS_MEMORY, low priority); set_time_limit only
    // when PHP has it (PHP 8 removes a disabled function: this child of tools/upgrade.php would die there)
    if (function_exists('set_time_limit')) { @set_time_limit(0); }
    $budget = max(0, min(86400, (int)$opt['search-budget']));
    $r = \app\common\util\Housekeeping::searchStats($scaleRoot, $budget > 0 ? microtime(true) + $budget : 0.0, !$opt['no-map']);
    echo json_encode(['search_stats' => $r], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL;
    exit(isset($r['state']) && !in_array($r['state'], ['error', 'no_database'], true) ? 0 : 1);
}

if ($opt['scale-only']) {
    $r = \app\common\util\Housekeeping::scale($scaleRoot, 'cron', max(1, (int)$opt['scale-budget']));
    $out = ['scale' => $r];
    if (!$opt['no-sitemap-files']) { $out['sitemap_files'] = javcms_hk_sitemap_files($scaleRoot, (int)$opt['sitemap-budget'], is_string($opt['scheme']) ? $opt['scheme'] : ''); }
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL;
    exit(isset($r['state']) && in_array($r['state'], ['done', 'busy', 'skipped'], true) ? 0 : 1);
}

$db = null;
$prefix = '';
$installed = is_file($root . '/application/data/install/install.lock') || is_file($root . '/application/install.lock');
if (!$opt['no-db'] && $installed && is_file($root . '/application/database.php')) {
    $c = include $root . '/application/database.php';
    if (is_array($c) && !empty($c['database'])) {
        $prefix = isset($c['prefix']) ? (string)$c['prefix'] : '';
        try {
            $dsn = 'mysql:host=' . $c['hostname'] . ';port=' . (isset($c['hostport']) && $c['hostport'] !== '' ? (int)$c['hostport'] : 3306)
                . ';dbname=' . $c['database'] . ';charset=' . (isset($c['charset']) && $c['charset'] !== '' ? $c['charset'] : 'utf8mb4');
            $pdo = new PDO($dsn, (string)$c['username'], (string)$c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
            $db = [
                'select' => function ($sql, array $bind) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($bind); return $s->fetchAll(); },
                'execute' => function ($sql, array $bind) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($bind); return $s->rowCount(); },
            ];
        } catch (Throwable $e) {
            fwrite(STDERR, "Database not reachable (" . get_class($e) . "); continuing with files only.\n");
            $db = null;
        }
        unset($c);
    }
}

$hk = new \app\common\util\Housekeeping([
    'runtime' => $root . DIRECTORY_SEPARATOR . 'runtime',
    'cache_enabled' => $cacheType === 'file',
    'db' => $db,
    'prefix' => $prefix,
    'dry_run' => (bool)$opt['dry-run'],
    'pause_ms' => (int)$opt['pause-ms'],
    'search_minutes' => isset($maccms['app']['vod_search_optimise_cache_minutes']) ? (int)$maccms['app']['vod_search_optimise_cache_minutes'] : 0,
]);

if ($opt['status']) {
    $status = $hk->status();
    if (!$opt['no-scale']) { $status['scale'] = \app\common\util\Housekeeping::scaleStatus(); }
    echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL;
    exit(0);
}
$sum = ['runs' => 0, 'deleted' => 0, 'bytes' => 0, 'tmp' => 0, 'tag' => 0, 'scanned' => 0, 'temp' => 0, 'logs' => 0, 'backups' => 0, 'rows' => 0];
$startPasses = $hk->passCount();
if ($opt['all'] || $opt['dry-run']) { $hk->restartPass(); }
$t0 = microtime(true);
do {
    $r = $hk->run('pass', (int)$opt['budget-ms']);
    if (isset($r['skipped'])) {
        fwrite(STDERR, "skipped: {$r['skipped']} (another run holds the lock); retrying\n");
        usleep(1000000);
        if (++$sum['runs'] > (int)$opt['max-runs']) { break; }
        continue;
    }
    $sum['runs']++;
    foreach (['deleted', 'bytes', 'tmp', 'tag', 'scanned'] as $k) { $sum[$k] += $r['cache'][$k]; }
    foreach (['temp', 'logs', 'backups'] as $k) { $sum[$k] += $r[$k]; }
    $sum['rows'] += array_sum($r['db']);
    if ($opt['all']) {
        printf("run %d: scanned %d, deleted %d (%.1f MB), folders %d, %d ms\n", $sum['runs'], $r['cache']['scanned'], $r['cache']['deleted'], $r['cache']['bytes'] / 1048576, $r['cache']['folders'], $r['ms']);
        usleep(max(0, (int)$opt['sleep-ms']) * 1000);
    }
    // In a dry run the state is not written, so a full pass cannot be detected: one slice only.
} while ($opt['all'] && !$opt['dry-run'] && $hk->passCount() === $startPasses && $sum['runs'] < (int)$opt['max-runs']);
$sum['seconds'] = round(microtime(true) - $t0, 1);
$sum['mb'] = round($sum['bytes'] / 1048576, 1);
if (!$opt['no-scale'] && !$opt['dry-run'] && !$opt['no-db']) {
    $sum['scale'] = \app\common\util\Housekeeping::scale($scaleRoot, 'cron', max(1, (int)$opt['scale-budget']));
    if (!$opt['no-sitemap-files']) { $sum['sitemap_files'] = javcms_hk_sitemap_files($scaleRoot, (int)$opt['sitemap-budget'], is_string($opt['scheme']) ? $opt['scheme'] : ''); }
}
echo json_encode($sum + ['dry_run' => (bool)$opt['dry-run']], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), PHP_EOL;
