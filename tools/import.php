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
if (PHP_SAPI !== 'cli') { exit(1); }
// One-click update (1.2.1): no run while an update replaces files (it would load half-replaced code). The job holds a
// shared lock on runtime/update-jobs.lock while it runs: an update waits for running jobs before it replaces files.
$javcmsUpdJobLock = is_dir(dirname(__DIR__) . '/runtime') ? @fopen(dirname(__DIR__) . '/runtime/update-jobs.lock', 'c') : false;
if ($javcmsUpdJobLock) { @flock($javcmsUpdJobLock, LOCK_SH); }
if (is_file(dirname(__DIR__) . '/runtime/update/maintenance.php')) { fwrite(STDERR, "An update is replacing files right now; skipped.\n"); exit(0); }
/*
 * JavCMS import runner for the command line and cron (XVID-SCALE-BRIEF D-I1, D-I11).
 *
 *   php tools/import.php --list-providers
 *   php tools/import.php --estimate --provider=xvid --scope=category --categories=11,43
 *   php tools/import.php --start --provider=xvid --scope=recent --limit=100000 --preset=grouped
 *   php tools/import.php --start --provider=xvid --scope=full --confirm=1 [--via=windows]
 *   php tools/import.php --run [--job=<id>] --max-minutes=4 [--budget=50]
 *   php tools/import.php --tick
 *   php tools/import.php --status
 *   php tools/import.php --pause --job=<id>
 *   php tools/import.php --pause --all
 *   php tools/import.php --upgrade
 *
 * Cron (every 5 minutes): php <site>/tools/import.php --run --max-minutes=4 >/dev/null 2>&1
 *
 * Scopes for --estimate / --start: full (the whole catalog, the default; xvidapi: snapshot files, else the keyset export
 * API, else id windows; --via=windows forces id windows), recent_10000, recent_50000, recent_100000, recent_1000000,
 * recent (--limit=N), category (--categories=<remote ids>), incremental (--hours=3|24|168). --confirm=1 is needed when
 * --estimate says so (more than 1,000,000 videos or more than half of the space left). An import stops cleanly at the
 * storage target (0.8 x the database limit, or the free disk minus a reserve); nothing is refused for its size.
 * Other --start options: --preset=grouped|mirror, --categories-off=<remote ids>, --skip-short=0|1, --skip-unknown=0|1,
 * --skip-sd=0|1, --langs=en,pt, --placement=new|upload, --images=0|1, --publish=0|1, --owner=<admin id>, --drop-indexes=1.
 *
 * The four filter FULLTEXT keys (ft_vod_actor, ft_vod_tag, ft_vod_class, ft_vod_director) stay during every import:
 * measured, a bulk import is only about 4 % faster without them and re-adding them takes minutes on a large table.
 * --drop-indexes=1 drops them for a bulk job that writes more than 100,000 videos and at least half of the table (a
 * fresh whole-catalog import); they are re-added one at a time when the job ends, is cancelled or undone, stays paused
 * for 10 minutes or loses its runner (--run, --tick and the import page finish a re-add that was cut short; ft_vod_name
 * always stays). --status shows a pending re-add.
 *
 * The runner writes the CLI heartbeat, runs at low priority, sleeps 200 ms between steps, obeys the provider pacer and
 * waits while the server is busy (load average above 1.5 x the CPU count). --run also continues a web job started on
 * the import page when no page stepped it for 60 s; --tick runs the schedule, then one background step of such a job. A killed run loses at most one step: the
 * job lease (300 s) expires and the next run continues from the last checkpoint. It uses only this site's own database
 * (application/database.php) and never prints credentials. This file uses old PHP syntax on purpose, so an old PHP
 * binary reaches the version message below instead of a parse error.
 */
if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, 'JavCMS import needs PHP 7.4 or newer; this is PHP ' . PHP_VERSION . ".\n"
        . "Use the PHP 7.4+ binary of your hosting panel, for example /usr/local/bin/ea-php74 or /opt/alt/php74/usr/bin/php.\n");
    exit(2);
}

/** Exit codes: 0 ok, 1 refused / failed, 2 usage. */
function javcms_import_main(array $argv, array $hooks = array())
{
    $known = array('list-providers', 'estimate', 'start', 'run', 'tick', 'status', 'pause', 'upgrade', 'help',
        'job', 'all', 'max-minutes', 'budget', 'provider', 'scope', 'limit', 'categories', 'categories-off', 'preset',
        'skip-short', 'skip-unknown', 'skip-sd', 'langs', 'placement', 'images', 'publish', 'owner', 'confirm', 'hours',
        'ids', 'slim', 'via', 'drop-indexes', 'root', 'quiet', 'json');
    $opt = array();
    foreach (array_slice($argv, 1) as $arg) {
        if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) || !in_array($m[1], $known, true)) {
            fwrite(STDERR, "Unknown option: {$arg} (see --help)\n");
            return 2;
        }
        $opt[$m[1]] = isset($m[2]) ? $m[2] : true;
    }
    $commands = array_values(array_intersect(array('list-providers', 'estimate', 'start', 'run', 'tick', 'status', 'pause', 'upgrade', 'help'), array_keys($opt)));
    if (count($commands) !== 1 || $commands[0] === 'help') {
        javcms_import_usage();
        return count($commands) === 1 ? 0 : 2;
    }
    $cmd = $commands[0];
    $root = isset($opt['root']) ? realpath((string)$opt['root']) : realpath(dirname(__DIR__));
    if ($root === false || !is_file($root . '/application/common/model/AvdbImport.php') || !is_file($root . '/thinkphp/base.php')) {
        fwrite(STDERR, "Not a JavCMS site folder.\n");
        return 2;
    }
    if (!is_file($root . '/application/data/install/install.lock') && !is_file($root . '/application/install.lock')) {
        fwrite(STDERR, "JavCMS is not installed yet: finish the installer first.\n");
        return 1;
    }
    javcms_import_boot($root);
    if (function_exists('proc_nice')) { @proc_nice(19); }
    // A test script that includes this file builds its mock providers here, after the framework autoloader exists.
    if (isset($hooks['setup']) && is_callable($hooks['setup'])) { $hooks = array_merge($hooks, (array)call_user_func($hooks['setup'])); }
    $out = new JavcmsImportCli($opt, $hooks);
    try {
        return $out->dispatch($cmd);
    } catch (\Throwable $e) {
        // Database credentials never reach this message: the framework's PDO errors are reduced to their class.
        $msg = ($e instanceof \think\exception\PDOException || $e instanceof \PDOException) ? 'Database error (' . get_class($e) . ').' : $e->getMessage();
        fwrite(STDERR, 'Refused: ' . $msg . "\n");
        return 1;
    }
}

function javcms_import_usage()
{
    $lines = array(
        'Usage:',
        '  php tools/import.php --list-providers',
        '  php tools/import.php --estimate --provider=xvid --scope=category --categories=11,43',
        '  php tools/import.php --start --provider=xvid --scope=recent --limit=100000 --preset=grouped',
        '  php tools/import.php --start --provider=xvid --scope=full --confirm=1 [--via=windows]',
        '  php tools/import.php --run [--job=<id>] --max-minutes=4 [--budget=50]',
        '  php tools/import.php --tick',
        '  php tools/import.php --status',
        '  php tools/import.php --pause --job=<id>',
        '  php tools/import.php --pause --all',
        '  php tools/import.php --upgrade',
        'Cron: */5 * * * * php ' . str_replace(chr(92), '/', dirname(__DIR__)) . '/tools/import.php --run --max-minutes=4 >/dev/null 2>&1',
    );
    fwrite(STDOUT, implode("\n", $lines) . "\n");
}

/** Boot ThinkPHP from the command line (the way the admin entry boots it), without dispatching a request. */
function javcms_import_boot($root)
{
    $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $defs = array('ROOT_PATH' => $root, 'APP_PATH' => $root . 'application/', 'MAC_COMM' => $root . 'application/common/common/',
        'MAC_HOME_COMM' => $root . 'application/index/common/', 'MAC_ADMIN_COMM' => $root . 'application/admin/common/',
        'BIND_MODULE' => 'admin', 'ENTRANCE' => 'admin', 'IN_FILE' => '/javcms.php');
    foreach ($defs as $k => $v) { if (!defined($k)) { define($k, $v); } }
    if (!defined('MAC_START_TIME')) { define('MAC_START_TIME', microtime(true)); }
    require $root . 'thinkphp/base.php';
    \think\App::initCommon();
    if (!defined('MAC_PATH')) {
        $site = config('maccms.site');
        define('MAC_PATH', is_array($site) && !empty($site['install_dir']) ? (string)$site['install_dir'] : '/');
    }
}

final class JavcmsImportCli
{
    const SLEEP_BETWEEN_US = 200000;
    const LOAD_WAIT_S = 10;
    const LOCK_FILE = 'import_cli_run.lock';

    private $opt;
    private $hooks;
    private $importer;
    private $lock;

    public function __construct(array $opt, array $hooks)
    {
        $this->opt = $opt;
        $this->hooks = $hooks;
    }

    public function dispatch($cmd)
    {
        switch ($cmd) {
            case 'list-providers': return $this->listProviders();
            case 'estimate': return $this->estimate();
            case 'start': return $this->start();
            case 'run': return $this->run();
            case 'tick': return $this->tick();
            case 'status': return $this->status();
            case 'pause': return $this->pause();
            case 'upgrade': return $this->upgrade();
        }
        return 2;
    }

    /** Importer with the site's providers (a test script that includes this file may pass mock providers in $hooks). */
    private function importer()
    {
        if ($this->importer) { return $this->importer; }
        $imp = new \app\common\model\AvdbImport(isset($this->hooks['avdb_client']) ? $this->hooks['avdb_client'] : null);
        if (!empty($this->hooks['providers'])) { foreach ($this->hooks['providers'] as $p) { $imp->withProvider($p); } }
        if (!empty($this->hooks['pacer'])) { $imp->setPacerFactory($this->hooks['pacer']); }
        return $this->importer = $imp;
    }

    private function say($line)
    {
        if (empty($this->opt['quiet'])) { fwrite(STDOUT, '[' . date('H:i:s') . '] ' . $line . "\n"); }
    }

    private function json($data)
    {
        fwrite(STDOUT, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /** CLI heartbeat for every provider (brief 2.2: written by every --run / --tick); creates the 1.2.0 tables first. */
    private function heartbeat()
    {
        $this->importer()->ensureSchema();
        foreach (\app\common\util\ImportProviders::keys() as $key) {
            try { (new \app\common\util\ProviderPacer($key))->heartbeat(); } catch (\Throwable $e) { /* informational */ }
        }
    }

    private function owner()
    {
        if (isset($this->opt['owner'])) {
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string)$this->opt['owner'])) { throw new \InvalidArgumentException('Invalid --owner.'); }
            $id = (int)$this->opt['owner'];
            if (!\think\Db::name('admin')->where(array('admin_id' => $id, 'admin_status' => 1))->find()) { throw new \InvalidArgumentException('Administrator ' . $id . ' does not exist or is disabled.'); }
            return $id;
        }
        $id = (int)\think\Db::name('admin')->where('admin_status', 1)->order('admin_id asc')->value('admin_id');
        if ($id < 1) { throw new \RuntimeException('No active administrator found.'); }
        return $id;
    }

    /** Scope request from the options (the names E's page sends). */
    private function scopeParams($runner)
    {
        $o = $this->opt;
        $p = array('provider' => isset($o['provider']) ? (string)$o['provider'] : 'xvid', 'runner' => $runner);
        if (!\app\common\util\ImportProviders::isKey($p['provider'])) { throw new \InvalidArgumentException(\app\common\util\ImportProviders::text('provider_unknown', 'Unknown import provider.')); }
        if (isset($o['scope'])) { $p['scope'] = (string)$o['scope']; }
        $map = array('limit' => 'limit', 'categories' => 'categories', 'categories-off' => 'categories_off', 'preset' => 'preset',
            'skip-short' => 'skip_short', 'skip-unknown' => 'skip_unknown', 'skip-sd' => 'skip_sd', 'langs' => 'langs',
            'placement' => 'placement', 'images' => 'images', 'publish' => 'publish', 'confirm' => 'confirm', 'hours' => 'hours', 'ids' => 'ids', 'slim' => 'slim', 'via' => 'via', 'drop-indexes' => 'drop_indexes');
        foreach ($map as $cli => $key) { if (isset($o[$cli]) && $o[$cli] !== true) { $p[$key] = (string)$o[$cli]; } elseif (isset($o[$cli])) { $p[$key] = '1'; } }
        return $p;
    }

    private function listProviders()
    {
        $this->importer()->ensureSchema();
        $rows = array();
        foreach ($this->importer()->providersInfo() as $p) {
            $c = $p['capabilities'];
            $rows[] = array('key' => $p['key'], 'label' => $p['label'], 'discovery' => $c['stable_cursor'], 'ids_max' => $c['ids_max'],
                'pace_ms' => $c['min_interval_ms']['bulk'], 'translations' => count($c['translations']), 'videos' => $p['videos'],
                'newest_remote_time' => $p['newest_remote_time'] ? gmdate('Y-m-d H:i', $p['newest_remote_time']) . ' UTC' : '',
                'pacer' => $p['state']);
        }
        $this->json(array('providers' => $rows, 'cli_seen_at' => \app\common\util\ProviderPacer::cliSeenAt()));
        return 0;
    }

    private function estimate()
    {
        $this->importer()->ensureSchema();
        $e = $this->importer()->estimateFor($this->scopeParams('cli'), false);
        if (!empty($this->opt['json'])) { $this->json($e); return 0; }
        $mb = function ($v) { return $v >= 1024 ? number_format($v / 1024, 1) . ' GB' : $v . ' MB'; };
        $lines = array(
            'Scope:            ' . $e['scope'] . ($e['remote_total'] ? ' (provider catalog ' . number_format($e['remote_total']) . ')' : ''),
            'Videos:           ' . number_format($e['videos']),
            'Database:         ' . $mb($e['db_mb_lean']) . ' (lean) / ' . $mb($e['db_mb_full']) . ' (full rows)',
            'Database now:     ' . $mb($e['db_mb_now']) . ' -> after: ' . $mb($e['db_mb_after']) . ($e['db_limit_mb'] !== null ? ' (limit ' . $mb($e['db_limit_mb']) . ')' : ' (no limit)'),
            'Peak disk:        ' . $mb($e['peak_mb']) . (!empty($e['binlog']['on']) ? ' incl. about ' . $mb((int)$e['binlog']['est_mb']) . ' of binary logs' . ((int)$e['binlog']['expire_days'] > 0 ? ' (kept ' . (int)$e['binlog']['expire_days'] . ' days)' : '') : '') . ($e['tmp_free_mb'] !== null ? ' (tmp free ' . $mb($e['tmp_free_mb']) . ')' : ''),
            'Provider:         ' . number_format($e['requests']) . ' requests, ' . $e['provider_minutes'] . ' min at the provider pace'
                . ($e['source'] === 'snapshot' ? ' (xvidapi snapshot: ' . (int)$e['shards'] . ' files, ' . $mb((int)$e['download_mb']) . ' download, no provider database time)' : ($e['source'] === 'keyset' ? ' (xvidapi keyset export pages)' : ($e['source'] === 'windows' ? ' (id windows of 500)' : ''))),
            'Import time:      ' . $e['import_minutes_here'] . ' min here, ' . $e['import_minutes_cron'] . ' min by cron, ' . $e['import_minutes_closed'] . ' min from the admin page with the tab closed, ' . $e['import_minutes_shared'] . ' min on shared hosting (' . $e['rate_items_s'] . ' videos/s)',
            'Storage target:   ' . ($e['stop_at_videos'] < $e['videos'] ? 'stops at about ' . number_format($e['stop_at_videos']) . ' videos (' . ($e['stop_reason'] === 'disk' ? 'free disk' : 'database at ' . $mb((int)$e['target_db_mb'])) . ')' : 'the whole scope fits'),
            'Runner:           web or command line' . ($e['cli_recommended'] ? ' (cron recommended: faster)' : ''),
        );
        if (!empty($e['confirm_needed'])) { $lines[] = 'Confirm with:     --confirm=1'; }
        if ($e['lang_note'] !== '') { $lines[] = 'Note:             ' . $e['lang_note']; }
        foreach ($e['warnings'] as $w) { if ($w['code'] !== 'cli_recommended') { $lines[] = 'Warning:          ' . $w['text']; } }
        if ($e['slim_offer']) { $lines[] = 'Tip:              php tools/db-tune.php --slim-indexes --dry-run (smaller and faster above 1,000,000 videos)'; }
        fwrite(STDOUT, implode("\n", $lines) . "\n");
        return 0;
    }

    private function start()
    {
        $this->heartbeat();
        $p = $this->scopeParams('cli');
        if (!isset($p['scope'])) { throw new \InvalidArgumentException('Choose a --scope (see --help).'); }
        $status = $this->importer()->startScope($this->owner(), $p, true);
        $this->say('Started job ' . $status['id'] . ' (' . $status['provider'] . ', ' . ($status['bulk'] ? 'bulk, ' : '') . 'runner cli). Run it with: php tools/import.php --run --max-minutes=4');
        if (!empty($this->opt['json'])) { unset($status['options'], $status['results']); $this->json($status); }
        return 0;
    }

    /** The server is busy (D-I11): load average above 1.5 x CPUs, or both deep request slots of the gate in use. */
    private function busy()
    {
        if (isset($this->hooks['busy']) && is_callable($this->hooks['busy'])) { return (bool)call_user_func($this->hooks['busy']); }
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            $cpus = self::cpuCount();
            if (is_array($load) && isset($load[0]) && $cpus > 0 && $load[0] > 1.5 * $cpus) { return true; }
        }
        $gate = '\app\common\util\RequestGate';
        if (class_exists($gate) && method_exists($gate, 'deepBusy')) {
            try { if (call_user_func(array($gate, 'deepBusy'))) { return true; } } catch (\Throwable $e) { /* gate not ready */ }
        }
        return false;
    }

    private static function cpuCount()
    {
        static $n = null;
        if ($n !== null) { return $n; }
        $n = 0;
        if (is_readable('/proc/cpuinfo')) { $n = substr_count((string)@file_get_contents('/proc/cpuinfo'), "\nprocessor") + (strpos((string)@file_get_contents('/proc/cpuinfo'), 'processor') === 0 ? 1 : 0); }
        if ($n < 1 && getenv('NUMBER_OF_PROCESSORS')) { $n = (int)getenv('NUMBER_OF_PROCESSORS'); }
        if ($n < 1) { $n = 1; }
        return $n;
    }

    /** One --run at a time per site (cron may start the next run while a slow one finishes). */
    private function takeLock()
    {
        $file = RUNTIME_PATH . self::LOCK_FILE;
        $fh = @fopen($file, 'c');
        if (!$fh) { return true; }
        if (!flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }
        $this->lock = $fh;
        return true;
    }

    private function run()
    {
        $minutes = isset($this->opt['max-minutes']) ? (float)$this->opt['max-minutes'] : 4.0;
        if ($minutes <= 0 || $minutes > 1440) { throw new \InvalidArgumentException('--max-minutes must be between 0 and 1440.'); }
        $budget = isset($this->opt['budget']) ? (float)$this->opt['budget'] : 50.0;
        if ($budget < 1 || $budget > 600) { throw new \InvalidArgumentException('--budget must be 1 to 600 seconds.'); }
        $jobId = isset($this->opt['job']) && $this->opt['job'] !== true ? (string)$this->opt['job'] : '';
        if ($jobId !== '' && \app\common\model\AvdbImport::jobOwner($jobId) === null) { throw new \InvalidArgumentException('Import job not found.'); }
        if ($jobId === '' && !$this->takeLock()) { $this->say('Another import run is active; nothing to do.'); return 0; }
        $this->heartbeat();
        $deadline = microtime(true) + $minutes * 60;
        $imp = $this->importer();
        // R2-7b: search-index appends that a job start or a web request handed to the command line (closed bulk windows)
        $this->deferredAppends();
        $steps = 0; $t0 = microtime(true); $before = array(); $last = array();
        $lastBeat = time();
        while (microtime(true) < $deadline - 1) {
            if (time() - $lastBeat >= 60) { $this->heartbeat(); $lastBeat = time(); }
            $jobs = $jobId !== '' ? array_filter(\app\common\model\AvdbImport::runnableJobs(true, 200), function ($j) use ($jobId) { return $j['job_id'] === $jobId; }) : \app\common\model\AvdbImport::runnableJobs(false);
            $jobs = array_values($jobs);
            if (!$jobs && $jobId === '') {
                // a web job of the import page that no page stepped for 60 s: continue it here (XVID-NOLIMIT-BRIEF A3)
                if ($this->busy()) { $this->say('Server busy (load); waiting ' . self::LOAD_WAIT_S . ' s.'); usleep(self::LOAD_WAIT_S * 1000000); continue; }
                $left = $deadline - microtime(true);
                $t = $imp->tickWeb(max(1.0, min($budget, $left - 1)));
                if (!$t || empty($t['stepped'])) { break; }
                $steps++;
                $this->say(sprintf('%s web job (background): %s, created %d, updated %d, failed %d', substr($t['job'], 0, 8), $t['state'], $t['created'], $t['updated'], $t['failed']));
                if ($t['state'] !== 'running') { break; }
                usleep(self::SLEEP_BETWEEN_US);
                continue;
            }
            if (!$jobs) { break; }
            // The first job that is not waiting for the provider and not held by another runner's lease.
            $now = time(); $job = null; $wait = PHP_INT_MAX;
            foreach ($jobs as $j) {
                $until = max($j['retry_at'], $j['lock_until']);
                if ($until <= $now) { $job = $j; break; }
                $wait = min($wait, $until - $now);
            }
            if ($job === null) {
                if ($now + $wait >= $deadline) { $this->say('Waiting ' . $wait . ' s for the provider or another runner: past this run\'s time; the next run continues.'); break; }
                usleep((int)min(60, max(1, $wait)) * 1000000);
                continue;
            }
            if ($this->busy()) { $this->say('Server busy (load); waiting ' . self::LOAD_WAIT_S . ' s.'); usleep(self::LOAD_WAIT_S * 1000000); continue; }
            $left = $deadline - microtime(true);
            $stepBudget = max(1.0, min($budget, $left - 1));
            $id = $job['job_id'];
            if (!isset($before[$id])) { $before[$id] = $this->counts($imp->status($job['admin_id'], $id)); }
            try {
                $s = $imp->step($job['admin_id'], $id, $stepBudget);
            } catch (\Throwable $e) {
                if ($e->getMessage() === 'A step is already running.') { usleep(self::SLEEP_BETWEEN_US); continue; }
                throw $e;
            }
            $steps++;
            $last[$id] = $s;
            $c = $this->counts($s);
            $this->say(sprintf('%s %s %s: %s, created %d, updated %d, skipped %d, failed %d%s%s', substr($id, 0, 8), $s['provider'], $s['type'], $s['state'],
                $c['created'], $c['updated'], $c['skipped'], $c['failed'], $s['hash_skipped'] ? ', unchanged ' . $s['hash_skipped'] : '',
                isset($s['waiting']) ? ', waiting until ' . date('H:i:s', $s['waiting']['until']) : ($s['error'] !== '' ? ' (' . $s['error'] . ')' : '')));
            if ($s['state'] !== 'running' && $jobId !== '') { break; }
            usleep(self::SLEEP_BETWEEN_US);
        }
        // P-O1: finish a re-add of the filter FULLTEXT keys that a web step or a killed run left (no-op when none is due)
        $fk = \app\common\model\AvdbImport::ftKeysRestore(0);
        if ($fk['restored']) { $this->say('Filter indexes re-added: ' . implode(', ', $fk['restored']) . '.'); }
        if ($fk['error'] !== '') { $this->say('Filter indexes: ' . $fk['error'] . ' (the next run retries).'); }
        $sum = array('steps' => $steps, 'seconds' => round(microtime(true) - $t0, 1), 'jobs' => array());
        foreach ($last as $id => $s) {
            $c = $this->counts($s); $b = $before[$id];
            $sum['jobs'][] = array('job' => $id, 'state' => $s['state'], 'provider' => $s['provider'], 'created' => $c['created'] - $b['created'], 'updated' => $c['updated'] - $b['updated'], 'skipped' => $c['skipped'] - $b['skipped'], 'failed' => $c['failed'] - $b['failed'], 'hash_skipped' => (int)$s['hash_skipped'] - $b['hash_skipped'], 'error' => $s['error']);
        }
        if (!empty($this->opt['json'])) { $this->json($sum); } else { $this->say('Run finished: ' . $steps . ' steps in ' . $sum['seconds'] . ' s.'); }
        return 0;
    }

    /** R2-7b: run the search-index appends of closed bulk windows that were handed to the command line (AvdbImport::runDeferredAppends). */
    private function deferredAppends()
    {
        try {
            $t = microtime(true);
            $n = \app\common\model\AvdbImport::runDeferredAppends(false);
            if ($n) { $this->say('Search index: appended the videos of ' . $n . ' closed bulk window(s) in ' . round(microtime(true) - $t, 1) . ' s.'); }
        } catch (\Throwable $e) { $this->say('Search index append failed: ' . $e->getMessage() . ' (the next run retries).'); }
    }

    private function counts(array $s)
    {
        return array('created' => (int)$s['created'], 'updated' => (int)$s['updated'], 'skipped' => (int)$s['skipped'], 'failed' => (int)$s['failed'], 'hash_skipped' => (int)(isset($s['hash_skipped']) ? $s['hash_skipped'] : 0));
    }

    /** Scheduler without the managed trigger (D-I10): steps or starts the schedule's job until --max-minutes. */
    private function tick()
    {
        $this->heartbeat();
        // verify fixes: refresh the shared storage measurement (visitor requests only read it) and close the search
        // window of a bulk job that stays paused (engines F13)
        try { \app\common\util\StorageGuard::refresh(); } catch (\Throwable $e) { /* reported by --status */ }
        try { $closed = \app\common\model\AvdbImport::closeIdleBulkWindows(); if ($closed) { $this->say('Closed the search window of ' . $closed . ' paused or stopped bulk import(s).'); } } catch (\Throwable $e) { /* next tick */ }
        $minutes = isset($this->opt['max-minutes']) ? (float)$this->opt['max-minutes'] : 4.0;
        $deadline = microtime(true) + max(0.1, min(1440.0, $minutes)) * 60;
        try { $rows = \think\Db::name('avdb_import_schedule')->where('enabled', 1)->select(); } catch (\Throwable $e) { $rows = array(); }
        if (!$rows) { $this->say('No automatic import is enabled.'); $this->tickWeb(); return 0; }
        $sched = new \app\common\model\AvdbSchedule($this->importer());
        $passes = 0;
        foreach ($rows as $row) {
            while (microtime(true) < $deadline - 10) {
                $fresh = \think\Db::name('avdb_import_schedule')->where('client_id', $row['client_id'])->find();
                if (!$fresh || !(int)$fresh['enabled']) { break; }
                if ($fresh['job_id'] === '' && (int)$fresh['next_run_at'] > time() && $passes > 0) { break; }
                if ($fresh['job_id'] !== '') {
                    $retry = (int)\think\Db::name('avdb_import_job')->where('job_id', $fresh['job_id'])->value('retry_at');
                    if ($retry > time()) { if ($retry >= $deadline) { break; } usleep((int)(($retry - time()) + 1) * 1000000); }
                }
                $st = $sched->tick($fresh);
                $passes++;
                $this->say('Schedule ' . substr((string)$row['client_id'], 0, 8) . ': ' . (isset($st['state']) ? $st['state'] : '?') . (isset($st['job_id']) && $st['job_id'] !== '' ? ', job ' . substr((string)$st['job_id'], 0, 8) : '') . (!empty($st['error']) ? ' (' . $st['error'] . ')' : ''));
                if (empty($st['job_id']) || (isset($st['state']) && $st['state'] !== 'running')) { break; }
                usleep(self::SLEEP_BETWEEN_US);
            }
        }
        $this->tickWeb();
        return 0;
    }

    /** One background step of a web job of the import page (AvdbImport::tickWeb, C17). */
    private function tickWeb()
    {
        try {
            $t = $this->importer()->tickWeb(isset($this->opt['budget']) ? (float)$this->opt['budget'] : 50.0);
            if ($t && !empty($t['stepped'])) { $this->say('Web job ' . substr($t['job'], 0, 8) . ' (background step): ' . $t['state'] . ', created ' . $t['created'] . ', updated ' . $t['updated'] . ', failed ' . $t['failed']); }
            elseif ($t && $t['reason'] !== 'no_job') { $this->say('Web job: not stepped (' . $t['reason'] . ').'); }
        } catch (\Throwable $e) { $this->say('Web job step failed: ' . $e->getMessage()); }
    }

    private function status()
    {
        $imp = $this->importer();
        $imp->ensureSchema();
        $jobs = array();
        foreach (\think\Db::name('avdb_import_job')->order('created_at desc')->limit(10)->field('job_id,admin_id')->select() as $r) {
            $s = $imp->status((int)$r['admin_id'], $r['job_id']);
            $jobs[] = array('job' => $s['id'], 'provider' => $s['provider'], 'type' => $s['type'], 'runner' => $s['runner'], 'state' => $s['state'], 'bulk' => $s['bulk'],
                'created' => $s['created'], 'updated' => $s['updated'], 'skipped' => $s['skipped'], 'failed' => $s['failed'], 'hash_skipped' => $s['hash_skipped'],
                'waiting_until' => isset($s['waiting']) ? $s['waiting']['until'] : 0, 'error' => $s['error'], 'updated_at' => date('Y-m-d H:i:s', $s['updated_at']),
                'mode' => isset($s['options']['mode']) ? $s['options']['mode'] : '', 'source' => $s['source'], 'stop_reason' => $s['stop_reason']);
            if (isset($s['snapshot'])) {
                $sn = $s['snapshot'];
                $jobs[count($jobs) - 1]['snapshot'] = array('phase' => $sn['phase'], 'shard' => $sn['shard'] . '/' . $sn['shards'], 'line' => $sn['line'], 'mb' => $sn['mb'], 'refused' => $sn['refused'], 'switched' => $sn['switched'], 'feed_rows' => $sn['feed_rows'], 'hidden' => $sn['hidden']);
            }
        }
        $st = \app\common\util\StorageGuard::status();
        $this->json(array('jobs' => $jobs, 'filter_indexes' => \app\common\model\AvdbImport::ftKeysState(), 'providers' => \app\common\util\ProviderPacer::rows(), 'cli_seen_at' => \app\common\util\ProviderPacer::cliSeenAt(),
            'storage' => array('db_mb' => $st['db_mb'], 'limit_mb' => $st['limit_mb'], 'free_disk_mb' => $st['free_disk_mb'], 'full' => $st['full'], 'reason' => $st['reason'])));
        return 0;
    }

    private function pause()
    {
        if (!empty($this->opt['all'])) {
            $n = \app\common\model\AvdbImport::pauseAll(\app\common\util\ImportProviders::text('paused_cli', 'Paused from the command line.'));
            $this->say('Paused ' . $n . ' jobs and schedule plans.');
            return 0;
        }
        $id = isset($this->opt['job']) && $this->opt['job'] !== true ? (string)$this->opt['job'] : '';
        $owner = \app\common\model\AvdbImport::jobOwner($id);
        if ($owner === null) { throw new \InvalidArgumentException('Use --pause --job=<id> or --pause --all.'); }
        $s = $this->importer()->pause($owner, $id);
        $this->say('Job ' . $id . ': ' . $s['state']);
        return 0;
    }

    private function upgrade()
    {
        $imp = $this->importer();
        // the log of the upgrade itself (ensureSchema() runs it too and would leave "nothing to do" here)
        $log = array();
        try { \think\Db::name('avdb_import_job')->limit(1)->field('job_id')->select(); $log = \app\common\model\AvdbImport::upgradeSchema(); } catch (\think\exception\PDOException $e) { $log = array(); }
        $imp->ensureSchema();
        $this->json(array('upgrade' => $log ? $log : 'nothing to do'));
        return 0;
    }
}

if (!defined('JAVCMS_IMPORT_NO_MAIN')) {
    exit(javcms_import_main($argv));
}
