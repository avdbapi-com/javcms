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
namespace app\common\util\updater;

/**
 * The step machine of an update run (UPDATER decision U3). Each call of step() does at most one time-boxed slice of
 * work (Config::STEP_BUDGET, about 10 s), saves where it stopped and answers; the browser, the command line or - for a
 * run nobody drives any more - the visitors' requests call it again. A step that dies (timeout, closed tab, killed
 * process) is simply repeated: every slice re-checks what is already done on disk before it acts. A step that keeps
 * dying in the same phase fails the run (before the site is touched) or rolls it back.
 *
 * Update phases (each one sliced; none loops over the whole plan in one request):
 *   scan          hash the installed files (or take over a fresh pre-check scan and re-probe what changed), plan,
 *                 write permission of every file, free disk
 *   download      the release zip from GitHub, resumable (or the owner's inbox copy)
 *   verify_zip    size + sha256 against the signed manifest
 *   stage         unpack only the files that change into runs/<id>/staging/, each checked against the manifest
 *   backup_files  every file the update replaces or deletes, and the settings files
 *   backup_db     database backup (Backup), then read back and checked: a backup that does not verify stops the update
 *   maint_on      waits for running background jobs, then the maintenance page for visitors (admin pages get the run's
 *                 progress page while files are swapped)
 *   swap          temp file + rename per file; entry scripts first, .htaccess / web.config last
 *   delete        files the release removed (unchanged copies only)
 *   verify_files  every written file hashed again (catches a zeroed or half-written file)
 *   caches        OPcache, compiled templates, page cache; admin pages work again
 *   upgrade       the NEW release's upgrade steps (UpgradeSteps of the new code; long builds go to housekeeping), then
 *                 the version number and a second cache refresh for what they wrote
 *   health        home, list, detail and admin pages answer without errors (from the server, or the admin's browser
 *                 when the server cannot reach itself or the site refuses its requests)
 *   maint_off     visitors get the site again
 *   finish        record, clean-up; the backup stays 7 days for "Roll back"
 * Any failure after maint_on switches the run to rollback (r_* phases): files restored from the backup, files the
 * update added removed, the database restored when the release declared a breaking schema step and it ran, the settings
 * files the upgrade changed restored, caches cleared, maintenance off. Before maint_on nothing of the site was touched:
 * the run just fails.
 *
 * Messages carry a key and variables (the admin page shows them in the admin's language; logs and the command line keep
 * the English text). Failure reasons too: 'error_k' / 'error_v' next to the English 'error'.
 */
final class Engine
{
    /** Phase of the slice in progress (fault switches of the test mode read it). */
    public static $phase = '';

    const UPDATE_PHASES = array('scan', 'download', 'verify_zip', 'stage', 'backup_files', 'backup_db', 'maint_on', 'swap', 'delete',
        'verify_files', 'caches', 'upgrade', 'health', 'maint_off', 'finish');
    /**
     * Rollback order: the entry scripts (index.php, api.php, the admin entry, install.php) come back last, in r_entries,
     * and r_maint_off / r_finish run in that same request. An older entry may not forward the page's steps or hold the
     * maintenance gate (1.2.0 has neither), so nothing could drive a rollback step after it is back.
     */
    const ROLLBACK_PHASES = array('r_maint_on', 'r_files', 'r_dirs', 'r_db', 'r_settings', 'r_caches', 'r_health', 'r_entries', 'r_maint_off', 'r_finish');
    /** Entry scripts in the order r_entries restores them (index.php last: it holds the maintenance gate). */
    const ENTRY_RESTORE_ORDER = array('install.php', 'javcms.php', 'api.php', 'index.php');
    /** Seconds the run waits for the browser's health report before it gives up (and rolls back). */
    const HEALTH_BROWSER_WAIT = 120;
    /** Phases during which the site's files may be half old, half new (failures roll back). */
    const LIVE_PHASES = array('maint_on', 'swap', 'delete', 'verify_files', 'caches', 'upgrade', 'health', 'maint_off');
    /** Steps that die at the same spot / in the same phase before the run fails (or a rollback slows down). */
    const DEATHS_AT_SPOT = 3;
    const DEATHS_IN_PHASE = 6;
    /** Seconds maint_on waits for a running background job (housekeeping, import) before it turns maintenance on. */
    const JOB_WAIT = 120;
    /** Failed database restore attempts before the rollback goes on without the database (it must end, visibly). */
    const DB_RESTORE_TRIES = 8;
    /**
     * Rollback steps that fail one after the other (with growing pauses: about 5 minutes in all) before the rollback stops
     * and waits for the founder ("needs attention"): a backup copy that is gone or damaged, or a permission taken away
     * during the run, would otherwise keep visitors on the maintenance page for ever.
     */
    const ROLLBACK_TRIES = 12;
    /**
     * Tries of a rollback the founder asked to try again ("Try the rollback again"): a cause that is still there sends the
     * run back to "needs attention" within seconds, instead of minutes of maintenance page.
     */
    const RETRY_TRIES = 4;
    /**
     * Seconds the command line waits for the web server to read the new entry scripts after the swap (its OPcache may
     * re-read changed files only every revalidate_freq seconds, at most 60 as the update counts it) before it decides
     * that the web server keeps running its cached copy of the old code.
     */
    const STALE_WAIT = 65;

    // ------------------------------------------------------------------ creating runs

    /**
     * New run state. $a: kind ('update'|'rollback'), from, to, by, choice, base (site URL), admin (entry), of (rollback:
     * the update run), db_restore (rollback: bool), manifest_to, manifest_from (file names in runtime/update/manifests/),
     * origin (the server's own address as the arming request saw it: health probes go there first).
     */
    public static function create(array $a)
    {
        $id = Util::randomId(8);
        $kind = $a['kind'] === 'rollback' ? 'rollback' : 'update';
        $s = array(
            'id' => $id, 'kind' => $kind, 'mode' => $kind, 'status' => 'running',
            'from' => (string)$a['from'], 'to' => (string)$a['to'], 'of' => (string)($a['of'] ?? ''),
            'created' => time(), 'by' => (string)($a['by'] ?? ''), 'choice' => (string)($a['choice'] ?? 'replace'),
            'phase' => $kind === 'rollback' ? 'r_maint_on' : 'scan', 'cursor' => 0, 'sub' => array(),
            'probe' => Util::randomId(24), 'cache_token' => Util::randomId(24),
            'base' => (string)($a['base'] ?? ''), 'admin' => (string)($a['admin'] ?? 'javcms.php'), 'origin' => (string)($a['origin'] ?? ''),
            'manifest_to' => (string)($a['manifest_to'] ?? ''), 'manifest_from' => (string)($a['manifest_from'] ?? ''),
            'db_restore' => !empty($a['db_restore']), 'manual' => $kind === 'rollback',
            'backup' => array(), 'maint' => false, 'upgrade' => array('started' => false, 'cursor' => array(), 'done' => false),
            'health' => array('tries' => 0), 'error' => '', 'error_k' => '', 'error_v' => array(), 'error_phase' => '', 'rollback' => array(),
            'inflight' => null, 'deaths' => array(), 'skipped' => array(),
            'heartbeat' => time(), 'ended' => 0, 'rollback_until' => 0, 'attempts' => array(), 'bridge' => !empty($a['bridge']),
            'skip' => array_values(array_map('strval', (array)($a['skip'] ?? array()))),
            // release files replaced even when the site changed them (the bridge file sits at install.php)
            'force' => array_values(array_map('strval', (array)($a['force'] ?? array()))),
            'progress' => array('done' => 0, 'total' => 0), 'chain' => false,
        );
        Store::save($s);
        if ($kind === 'rollback') {
            Store::log($id, 'info', 'Rollback started: back to ' . $s['to'] . ' (update run ' . $s['of'] . ')', $s['phase'], 'l_started_rollback',
                array('to' => $s['to'], 'of' => $s['of']));
        } else {
            Store::log($id, 'info', 'Update started: ' . $s['from'] . ' -> ' . $s['to'] . ($s['bridge'] ? ' (bridge file)' : ''), $s['phase'],
                $s['bridge'] ? 'l_started_bridge' : 'l_started_update', array('from' => $s['from'], 'to' => $s['to']));
        }
        return $s;
    }

    // ------------------------------------------------------------------ one step

    /**
     * One slice of run $id. Returns ['ok', 'run', 'kind', 'mode', 'phase', 'status', 'progress' => [done, total],
     * 'msg' => ['k' => key, 'v' => vars, 'm' => English], 'maintenance', 'wait' (ms before the next call), 'error',
     * 'error_msg' => ['k', 'v', 'm'], 'skipped' (phases a rollback left out)].
     */
    public static function step($id, $budget = null, $rescue = false)
    {
        // $rescue: driven by visitors' requests (maintenance gate); no browser will report a health check then
        $lock = Store::lock();
        if ($lock === null) {
            if (Store::$lockError === 'unsupported') {
                // a file system without locks (some NFS / FUSE mounts) would make every step wait for ever: say so
                return array('ok' => false, 'run' => $id, 'status' => 'running', 'busy' => true, 'wait' => 15000, 'error' => 'no file locks',
                    'msg' => self::msg('locks_unsupported', array(), 'The folder runtime/ is on a file system without file locks: the update cannot run safely. Keep runtime/ on a local disk.'));
            }
            return array('ok' => true, 'run' => $id, 'status' => 'running', 'busy' => true, 'wait' => 1500,
                'msg' => self::msg('busy', array(), 'Another step is still working; waiting.'));
        }
        $s = Store::load($id);
        if ($s === null) {
            Store::unlock($lock);
            return array('ok' => false, 'run' => $id, 'status' => 'missing', 'error' => 'run not found', 'msg' => self::msg('missing', array(), 'This update run does not exist.'));
        }
        if ($s['status'] !== 'running') {
            Store::unlock($lock);
            return self::result($s);
        }
        // temp files next to site files carry this run's tag: a repeated step replaces what a dead one left
        Util::setTmpTag($id);
        // a cancel asked while another worker held the step lock (Service::cancel): honoured here, before any work,
        // as long as nothing of the site was changed; later the run goes on (the admin was told it is too late)
        if (Store::cancelRequested($id)) {
            if (self::cancellable($s)) {
                self::cancelNow($s);
                Store::unlock($lock);
                return self::result($s);
            }
            Store::clearCancel($id);
        }
        $deadline = microtime(true) + Util::stepBudget($budget === null ? Config::STEP_BUDGET : (float)$budget);
        Util::keepRunning();
        $s += array('deaths' => array(), 'skipped' => array());
        // a slice that died (timeout, killed process) is repeated; deaths are counted at the same spot and per phase (good
        // slices in between do not reset the phase count: a step that always dies at the same point ends the run)
        $here = $s['phase'] . '#' . (int)$s['cursor'];
        $n = 0;
        if (is_array($s['inflight'])) {
            $dp = (string)($s['inflight']['phase'] ?? strtok((string)($s['inflight']['at'] ?? ''), '#'));
            $s['deaths'][$dp] = (int)($s['deaths'][$dp] ?? 0) + 1;
            $n = ($s['inflight']['at'] ?? '') === $here ? (int)$s['inflight']['n'] + 1 : 1;
            if ($s['phase'] === 'r_db') {
                $s['sub']['died'] = true;
            }
            Store::log($id, 'warn', 'The previous step stopped before it finished (attempt ' . $n . ' at ' . $s['phase'] . ')', $s['phase'],
                'l_died', array('n' => $n, 'phase' => $s['phase']));
            $perPhase = (int)($s['deaths'][$s['phase']] ?? 0);
            if ($n >= self::DEATHS_AT_SPOT || $perPhase >= self::DEATHS_IN_PHASE) {
                $s['inflight'] = null;
                $times = max($n, $perPhase);
                if ($s['mode'] === 'rollback') {
                    $s['deaths'][$s['phase']] = 0;
                }
                self::fail($s, 'the step at "' . $s['phase'] . '" stopped ' . $times . ' times (PHP time limit or memory?)', 'e_stopped',
                    array('phase' => $s['phase'], 'n' => $times));
                Store::save($s);
                Store::unlock($lock);
                return self::result($s);
            }
        }
        $s['inflight'] = array('at' => $here, 'phase' => $s['phase'], 'n' => $n, 't' => time());
        $s['rescue'] = (bool)$rescue;
        Store::save($s);
        self::$phase = $s['phase'];
        if (in_array($s['phase'], self::LIVE_PHASES, true) || $s['mode'] === 'rollback') {
            Store::heartbeat($s);
        }
        $err = '';
        $rounds = 0;
        do {
            // r_entries hands over to r_maint_off and r_finish in the same request ($s['chain'])
            self::$phase = $s['phase'];
            try {
                $s['mode'] === 'rollback' ? self::rollbackStep($s, $deadline) : self::updateStep($s, $deadline);
            } catch (\Throwable $e) {
                $err = get_class($e) . ': ' . $e->getMessage();
                $short = Util::cut(Util::redact($err), 400);
                Store::log($id, 'error', 'Unexpected error: ' . $short, $s['phase'], 'l_unexpected', array('why' => $short));
                if ($s['mode'] === 'rollback') {
                    self::rollbackTrouble($s, $err, 'e_unexpected', array('why' => $short));
                } else {
                    self::fail($s, $err, 'e_unexpected', array('why' => $short));
                }
            }
        } while ($err === '' && !empty($s['chain']) && $s['status'] === 'running' && $s['mode'] === 'rollback'
            && in_array($s['phase'], array('r_maint_off', 'r_finish'), true) && ++$rounds < 4);
        $s['chain'] = false;
        $s['inflight'] = null;
        $s['heartbeat'] = time();
        Store::save($s);
        if ($s['status'] === 'running' && (in_array($s['phase'], self::LIVE_PHASES, true) || $s['mode'] === 'rollback')) {
            Store::heartbeat($s);
        }
        Store::unlock($lock);
        return self::result($s);
    }

    /**
     * What a visitor who is not the signed-in founder may see of run $s (the run's own page, install.php during a bridge
     * run): what it does and how far it is. No failure details, backup locations, health results, probe token or version
     * numbers (the versions tell a stranger which known problems the site has).
     */
    public static function publicResult(array $s)
    {
        $r = self::result($s);
        $phaseMsg = self::msg('phase_' . $s['phase'], array(), $s['phase']);
        return array(
            'ok' => true, 'run' => $r['run'], 'kind' => $r['kind'], 'mode' => $r['mode'], 'phase' => $r['phase'], 'status' => $r['status'],
            'from' => '', 'to' => '', 'after' => '', 'progress' => $r['progress'], 'phases' => $r['phases'], 'skipped' => $r['skipped'],
            'maintenance' => $r['maintenance'], 'wait' => $r['wait'], 'msg' => $phaseMsg, 'error' => '', 'error_phase' => $r['error_phase'],
            'error_msg' => array('k' => '', 'v' => array(), 'm' => ''), 'backup' => array('db' => '', 'files' => 0, 'db_partial' => false),
            'rollback_until' => 0, 'health' => null, 'health_probe' => null, 'public' => true, 'web_cache' => '', 'attention' => null,
        );
    }

    /**
     * The version installed when run $s is over (or now, for a run that stopped): the release it went to when it is
     * done, the version it went back to when it was rolled back (by hand or automatically), else the version it started
     * from. The pages decide with it whether the version left behind has an Updates page (1.2.1 and newer) at all.
     */
    public static function versionAfter(array $s)
    {
        $status = (string)($s['status'] ?? '');
        if ($status === 'done') {
            return (string)$s['to'];
        }
        if (($s['kind'] ?? '') === 'rollback') {
            return in_array($status, array('rolled_back', 'rollback_failed', 'attention', 'closed'), true) || ($s['mode'] ?? '') === 'rollback' ? (string)$s['to'] : (string)$s['from'];
        }
        return (string)$s['from'];
    }

    /** True while the maintenance flag on disk is the one of run $id (the founder may have deleted it by hand). */
    public static function flagOfRun($id)
    {
        $m = Store::maintenance();
        return is_array($m) && (string)($m['run'] ?? '') === (string)$id && (string)$id !== '';
    }

    /** The answer for the caller. */
    public static function result(array $s)
    {
        $msg = $s['last_msg'] ?? self::msg('phase_' . $s['phase'], array(), $s['phase']);
        return array(
            'ok' => true, 'run' => $s['id'], 'kind' => $s['kind'], 'mode' => $s['mode'], 'phase' => $s['phase'], 'status' => $s['status'],
            'from' => $s['from'], 'to' => $s['to'], 'progress' => $s['progress'] ?? array('done' => 0, 'total' => 0), 'msg' => $msg,
            // a rollback that waits for the founder: the flag on disk says it (the founder may have deleted it by hand)
            'maintenance' => ($s['status'] ?? '') === 'attention' ? self::flagOfRun($s['id']) : !empty($s['maint']),
            'wait' => (int)($s['wait'] ?? 0), 'error' => (string)$s['error'],
            'error_phase' => (string)($s['error_phase'] ?? ''),
            'error_msg' => array('k' => (string)($s['error_k'] ?? ''), 'v' => (array)($s['error_v'] ?? array()), 'm' => (string)$s['error']),
            'backup' => array('db' => (string)($s['backup']['db_location'] ?? ''), 'files' => (int)($s['backup']['files'] ?? 0),
                'db_partial' => !empty($s['backup']['db']) && empty($s['backup']['db']['full'])),
            'rollback_until' => (int)($s['rollback_until'] ?? 0), 'health' => $s['health']['result'] ?? null,
            'phases' => $s['mode'] === 'rollback' ? self::ROLLBACK_PHASES : self::UPDATE_PHASES,
            'skipped' => array_values(array_unique((array)($s['skipped'] ?? array()))),
            'health_probe' => !empty($s['health']['browser']['asked']) && empty($s['health']['browser']['report'])
                ? array('probe' => $s['probe'], 'base' => $s['base'], 'admin' => $s['admin'], 'markers' => Health::MARKERS) : null,
            'after' => self::versionAfter($s), 'bridge' => !empty($s['bridge']),
            // the command line's last word on the web server's PHP cache after a rollback ('' / reset / gateless / refused /
            // unreachable) and the facts of a rollback that needs the founder (attention)
            'web_cache' => (string)($s['rollback']['web_cache'] ?? ''),
            'attention' => ($s['status'] ?? '') === 'attention' ? ((array)($s['attention'] ?? array()) + array('flag_on' => self::flagOfRun($s['id']))) : null,
            // a run the founder closed after finishing its rollback by hand (closeAttention), and a rollback that ended
            // after the founder's retry
            'closed' => ($s['status'] ?? '') === 'closed' ? (array)($s['closed'] ?? array()) : null,
            'retried' => (int)($s['retried'] ?? 0),
        );
    }

    /** Message record: key (translated by the admin page), variables, English text (logs, command line). */
    public static function msg($k, array $v, $m)
    {
        return array('k' => $k, 'v' => $v, 'm' => $m);
    }

    private static function say(array &$s, $k, array $v, $m, $level = 'info')
    {
        $s['last_msg'] = self::msg($k, $v, $m);
        Store::log($s['id'], $level, $m, $s['phase'], 'm_' . $k, $v);
    }

    private static function next(array &$s, $phase)
    {
        $s['phase'] = $phase;
        $s['cursor'] = 0;
        $s['sub'] = array();
        $s['progress'] = array('done' => 0, 'total' => 0);
        $s['wait'] = 0;
        self::$phase = $phase;
    }

    /** Test mode: simulate a process killed in $phase after $after units (the state of the slice is not saved). */
    private static function maybeDie(array $s, $units)
    {
        $f = Config::faults();
        if (!empty($f['die']) && is_array($f['die']) && ($f['die']['phase'] ?? '') === $s['phase'] && $units >= (int)($f['die']['after'] ?? 0)) {
            if (!empty($f['die']['once'])) {
                $flag = Util::root() . 'runtime/update/test-died-' . $s['id'] . '-' . $s['phase'];
                if (is_file($flag)) {
                    return;
                }
                @file_put_contents($flag, '1');
            }
            exit(1);
        }
    }

    // ------------------------------------------------------------------ manifests and plan of a run

    private static function manifest(array $s, $which)
    {
        $name = $which === 'to' ? $s['manifest_to'] : $s['manifest_from'];
        $ver = $which === 'to' ? $s['to'] : $s['from'];
        if ($name === '') {
            return null;
        }
        $m = Source::stored($ver);
        if ($m === null) {
            throw new \RuntimeException('the signed manifest of ' . $ver . ' is missing or no longer verifies');
        }
        return $m;
    }

    public static function plan(array $s)
    {
        $id = $s['kind'] === 'rollback' && $s['of'] !== '' ? $s['of'] : $s['id'];
        return Store::loadPart($id, 'plan');
    }

    /** Absolute path of a run's sub-folder without creating it (Store::runDir creates folders). */
    private static function runPath($id, $sub)
    {
        return Util::root() . 'runtime/update/runs/' . $id . '/' . $sub;
    }

    // ------------------------------------------------------------------ update mode

    private static function updateStep(array &$s, $deadline)
    {
        switch ($s['phase']) {
            case 'scan':
                self::scan($s, $deadline);
                return;

            case 'download':
                self::download($s, $deadline);
                return;

            case 'verify_zip':
                $to = self::manifest($s, 'to');
                $z = $to->zip();
                $file = Util::abs($s['zip']);
                $size = is_file($file) ? (int)filesize($file) : -1;
                $sha = $size === $z['size'] ? Util::sha256($file) : '';
                if ($size !== $z['size'] || !hash_equals($z['sha256'], (string)$sha)) {
                    @unlink($file);
                    $s['attempts']['verify'] = (int)($s['attempts']['verify'] ?? 0) + 1;
                    $what = $size !== $z['size'] ? 'size ' . $size . ', expected ' . $z['size'] : 'size right, SHA-256 differs';
                    Store::log($s['id'], 'warn', 'The downloaded package does not match the signed manifest (' . $what . ')', 'verify_zip',
                        $size !== $z['size'] ? 'l_zip_size' : 'l_zip_sha', array('size' => $size, 'want' => $z['size']));
                    if ($s['attempts']['verify'] >= 2 || strpos((string)$s['zip'], '/inbox/') !== false) {
                        self::fail($s, 'the package does not match the signed manifest (wrong size or SHA-256)', 'e_zip_mismatch');
                        return;
                    }
                    $s['dl']['url_i'] = (int)($s['dl']['url_i'] ?? 0) + 1;
                    self::next($s, 'download');
                    return;
                }
                self::say($s, 'zip_ok', array(), 'Package verified: size and SHA-256 match the signed manifest');
                self::next($s, 'stage');
                return;

            case 'stage':
                self::stage($s, $deadline);
                return;

            case 'backup_files':
                $plan = self::plan($s);
                $list = array();
                foreach (array_merge($plan['replace'], $plan['delete']) as $p) {
                    $list[] = $plan['targets'][$p] ?? $p;
                }
                $index = Store::loadPart($s['id'], 'backup');
                $r = Backup::filesStep($s['id'], $list, (int)$s['cursor'], $index, $deadline);
                Store::savePart($s['id'], 'backup', $index);
                $s['cursor'] = $r['cursor'];
                $s['progress'] = array('done' => $r['cursor'], 'total' => count($list));
                if ($r['state'] === 'error') {
                    self::fail($s, $r['error'], (string)($r['k'] ?? ''), (array)($r['v'] ?? array()));
                    return;
                }
                if ($r['state'] === 'more') {
                    self::say($s, 'backup_files', array('done' => $r['cursor'], 'total' => count($list)), 'Backing up files: ' . $r['cursor'] . ' of ' . count($list));
                    return;
                }
                $s['backup']['settings'] = Backup::settings($s['id']);
                $s['backup']['files'] = count($list);
                $s['backup']['folder'] = 'runtime/update/runs/' . $s['id'] . '/backup/';
                self::say($s, 'backup_files_done', array('n' => count($list), 'folder' => $s['backup']['folder']),
                    'Backed up ' . count($list) . ' files and the settings in ' . $s['backup']['folder']);
                self::next($s, 'backup_db');
                return;

            case 'backup_db':
                self::backupDb($s, $deadline);
                return;

            case 'maint_on':
                // background jobs (housekeeping, import) load the site's code: the swap waits until they are done
                if (Store::jobsRunning()) {
                    $since = (int)($s['sub']['jobs_since'] ?? 0);
                    if ($since === 0) {
                        $s['sub']['jobs_since'] = time();
                        Store::log($s['id'], 'info', 'Waiting for a background job (housekeeping or import) to finish before the files are replaced', 'maint_on', 'l_jobs_wait');
                    }
                    if ($since === 0 || time() - $since < self::JOB_WAIT) {
                        $s['wait'] = 2000;
                        self::say($s, 'jobs_wait', array(), 'Waiting for a background job to finish');
                        return;
                    }
                    Store::log($s['id'], 'warn', 'A background job is still running after ' . self::JOB_WAIT . ' s; the update goes on', 'maint_on', 'l_jobs_timeout',
                        array('s' => self::JOB_WAIT));
                }
                if (!Store::maintenanceOn($s, true)) {
                    self::fail($s, 'cannot turn the maintenance page on (runtime/update not writable)', 'e_maint');
                    return;
                }
                if (PHP_SAPI === 'cli') {
                    // the command line cannot see what the web server runs: the gate answers its ping when the web server
                    // runs an entry script that has the gate (visitors get the maintenance page). A site that answers
                    // without the gate runs entry scripts without it - cached copies of an older version (OPcache that
                    // never re-reads files): replacing files now would show visitors a mix of old and new code, so the
                    // run stops before anything changes. The bridge's 1.2.0 entries have no gate before the swap: the
                    // bridge checked before it started that the web server runs the uploaded file and re-reads changed
                    // files (Bridge::cli).
                    $ping = self::gatePing($s);
                    if ($ping['state'] === 'stale' && empty($s['bridge'])) {
                        Store::maintenanceOffFor($s['id']);
                        self::fail($s, 'the web server does not show the maintenance page: it runs entry scripts without the update\'s gate (cached copies of an older version: restart PHP or clear OPcache, then try again)',
                            'e_gate_missing');
                        return;
                    }
                    // the gate also says how the web server's OPcache works and has just tried its reset: a cache that never
                    // re-reads files and refuses the reset (opcache.restrict_api, disabled functions) would keep running the
                    // old code over the new files - a mixed site until PHP restarts. Stopped here, before anything changes
                    // (e2e-4 P2-1; the Updates page blocks such a host with the same reason)
                    if ($ping['state'] === 'gate' && !empty($ping['enabled']) && empty($ping['reset']) && Util::opcacheNeverRereads($ping['vt'])) {
                        Store::maintenanceOffFor($s['id']);
                        self::fail($s, 'the web server\'s PHP cache (OPcache) never re-reads changed files here and this site may not reset it (opcache.restrict_api or disabled functions): the new files would not be used until PHP restarts. Ask your host to allow opcache_reset for this site, or update by hand and restart PHP',
                            'e_opcache_blocked');
                        return;
                    }
                    if ($ping['state'] !== 'gate') {
                        $s['maint'] = true;
                        $s['maint_on'] = time();
                        self::say($s, $s['bridge'] ? 'maint_on_bridge' : 'maint_on_unseen', array(), $s['bridge']
                            ? 'Maintenance flag written: visitors get the maintenance page as soon as the new index.php is in place (the first file of the swap)'
                            : 'Maintenance flag written; the web server did not answer this server\'s check (visitors get the maintenance page when it runs the gate)');
                        self::next($s, 'swap');
                        return;
                    }
                }
                $s['maint'] = true;
                $s['maint_on'] = time();
                self::say($s, 'maint_on', array(), 'Maintenance page on for visitors');
                self::next($s, 'swap');
                return;

            case 'swap':
                self::swap($s, $deadline);
                return;

            case 'delete':
                $plan = self::plan($s);
                $list = $plan['delete'];
                $journal = Store::loadPart($s['id'], 'journal');
                $i = (int)$s['cursor'];
                while ($i < count($list)) {
                    $p = $list[$i];
                    $r = Swapper::remove($p, true);
                    if (!$r['ok']) {
                        self::toRollback($s, $r['error'], (string)($r['k'] ?? ''), (array)($r['v'] ?? array()));
                        Store::savePart($s['id'], 'journal', $journal);
                        return;
                    }
                    $journal['deleted'][$p] = 1;
                    $i++;
                    if (microtime(true) >= $deadline) {
                        break;
                    }
                }
                Store::savePart($s['id'], 'journal', $journal);
                $s['cursor'] = $i;
                $s['progress'] = array('done' => $i, 'total' => count($list));
                if ($i < count($list)) {
                    self::say($s, 'delete', array('done' => $i, 'total' => count($list)), 'Removing old files: ' . $i . ' of ' . count($list));
                    return;
                }
                self::say($s, 'delete_done', array('n' => count($list)), 'Removed ' . count($list) . ' files the new release no longer has');
                self::next($s, 'verify_files');
                return;

            case 'verify_files':
                self::verifyFiles($s, $deadline);
                return;

            case 'caches':
                $plan = self::plan($s);
                $files = array();
                foreach (array_keys($plan['expect']) as $p) {
                    $files[] = Util::abs($plan['targets'][$p] ?? $p);
                }
                $c = self::clearCachesStep($s, $files, $deadline);
                if ($c === null) {
                    $s['progress'] = array('done' => (int)($s['sub']['ci'] ?? 0), 'total' => count($files));
                    self::say($s, 'caches_more', array('done' => (int)($s['sub']['ci'] ?? 0), 'total' => count($files)),
                        'Clearing caches: ' . (int)($s['sub']['ci'] ?? 0) . ' of ' . count($files) . ' files');
                    return;
                }
                if (!empty($c['stale'])) {
                    // the site answers this server, but not through the gate of the new entry scripts: the web server has
                    // not read them yet (its OPcache re-reads changed files only every few seconds) - or never will
                    // (validate_timestamps=0 and nobody may reset it): then the new files are not what visitors get, and
                    // the health check would test the old code. Rolled back to the old version, which is what it runs.
                    $since = (int)($s['stale_since'] ?? 0);
                    if ($since === 0) {
                        $since = time();
                        $s['stale_since'] = $since;
                    }
                    $left = $since + self::STALE_WAIT - time();
                    if ($left > 0) {
                        $s['wait'] = 3000;
                        self::say($s, 'caches_wait_web', array('s' => $left), 'Waiting for the web server to read the new files (' . $left . ' s at most)');
                        return;
                    }
                    self::toRollback($s, 'the web server keeps running its cached copy of the old code (PHP\'s OPcache never re-reads files here, and it could not be reset): the new files are not used. Restart PHP (or clear OPcache in the hosting panel), then update again',
                        'e_stale_web');
                    return;
                }
                if (!empty($c['stuck'])) {
                    // the web server answered, but its cache never re-reads files and it refused the reset (and did not take
                    // every file one by one): it keeps running the old code it compiled over the new files on disk, a mixed
                    // site until PHP restarts - rolled back to the version it runs (e2e-4 P2-1)
                    self::toRollback($s, 'the web server keeps running its cached copy of the old code (PHP\'s OPcache never re-reads files here, and it could not be reset): the new files are not used. Restart PHP (or clear OPcache in the hosting panel), then update again',
                        'e_stale_web');
                    return;
                }
                unset($s['stale_since']);
                Store::maintenanceOn($s, false);
                $s['caches_at'] = time();
                $s['opcache_refused'] = !empty($c['refused']);
                self::say($s, 'caches', $c, 'Caches cleared (OPcache: ' . $c['opcache'] . ', compiled templates: ' . $c['templates'] . ', page cache: ' . $c['edge'] . ')');
                self::next($s, 'upgrade');
                return;

            case 'upgrade':
                self::upgrade($s, $deadline);
                return;

            case 'health':
                $faults = Config::faults();
                $h = self::healthResult($s, $deadline);
                if ($h === null) {
                    return; // waiting for the browser's report
                }
                if (!empty($faults['health_fail'])) {
                    // test mode: a failing check, whoever checked the pages (this server or the admin's browser)
                    $h['ok'] = false;
                    $h['checks'][] = array('name' => 'test', 'path' => '', 'status' => 0, 'ok' => false, 'why' => 'health check fault switched on (test mode)',
                        'k' => 'hr_fault', 'v' => array(), 'ms' => 0);
                }
                $s['health']['result'] = $h;
                $s['health']['tries'] = (int)$s['health']['tries'] + 1;
                if ($h['ok']) {
                    self::say($s, 'health_ok', array(), 'Health check passed: home, list, detail and admin pages answer without errors');
                    self::next($s, 'maint_off');
                    return;
                }
                list($why, $checks) = Health::reasons($h);
                if ($s['health']['tries'] < 3 && empty($faults['health_fail'])) {
                    // the failed pages travel keyed (checks): the admin reads them in the admin's language
                    Store::log($s['id'], 'warn', 'Health check attempt ' . $s['health']['tries'] . ' failed (' . $why . '); trying again', 'health',
                        'l_health_retry', array('n' => $s['health']['tries'], 'why' => $why, 'checks' => $checks));
                    $s['wait'] = 3000;
                    return;
                }
                self::toRollback($s, 'health check failed: ' . $why, 'e_health', array('list' => $why, 'checks' => $checks));
                return;

            case 'maint_off':
                Store::maintenanceOff();
                $s['maint'] = false;
                self::say($s, 'maint_off', array(), 'Maintenance page off: visitors get the updated site');
                self::next($s, 'finish');
                return;

            case 'finish':
                self::finishUpdate($s, $deadline);
                return;
        }
        throw new \RuntimeException('unknown phase ' . $s['phase']);
    }

    /**
     * scan: (1) the installed files, sliced (a fresh pre-check scan of the same releases is taken over and only files
     * whose size or time changed are probed again); (2) the plan, in a slice of its own; (3) write permission of every
     * file the plan touches, sliced; (4) free disk. The scan of the run stays until the plan is accepted, so a step that
     * dies in (2) - (4) never starts the scan over.
     */
    private static function scan(array &$s, $deadline)
    {
        $to = self::manifest($s, 'to');
        $from = $s['manifest_from'] !== '' ? self::manifest($s, 'from') : null;
        $stage = (string)($s['sub']['stage'] ?? 'files');
        if ($stage === 'files') {
            $r = Planner::step('run-' . $s['id'], $to, $from, $s['choice'], $deadline, 'pre-' . $to->version());
            $s['progress'] = array('done' => $r['done'], 'total' => $r['total']);
            if ($r['state'] === 'more') {
                self::say($s, 'scan', array('done' => $r['done'], 'total' => $r['total']), 'Checking installed files: ' . $r['done'] . ' of ' . $r['total']);
                return;
            }
            $plan = self::finishPlan(self::applyForce(self::applySkip($r['plan'], (array)($s['skip'] ?? array())), (array)($s['force'] ?? array())), $to);
            Store::savePart($s['id'], 'plan', $plan);
            $s['sub'] = array('stage' => 'write', 'w' => Planner::writeCheckStart());
            $s['progress'] = array('done' => 0, 'total' => count($plan['add']) + count($plan['replace']) + count($plan['delete']));
            self::say($s, 'write_check', array('done' => 0, 'total' => $s['progress']['total']), 'Checking write permission: 0 of ' . $s['progress']['total']);
            return;
        }
        // the run protects itself even when no pre-check ran (command line --yes): nothing is touched when a file cannot
        // be read or written, or the disk is too small
        $plan = self::plan($s);
        $w = (array)$s['sub']['w'];
        $done = Planner::writeCheckStep($plan, $w, $deadline);
        $s['sub']['w'] = $w;
        $s['progress'] = array('done' => (int)$w['i'], 'total' => (int)$w['total']);
        if (!$done) {
            self::say($s, 'write_check', array('done' => (int)$w['i'], 'total' => (int)$w['total']), 'Checking write permission: ' . (int)$w['i'] . ' of ' . (int)$w['total']);
            return;
        }
        $unread = array_values(array_unique(array_merge((array)$w['unreadable'], (array)($plan['unreadable'] ?? array()))));
        if ($unread) {
            $list = implode(', ', array_slice($unread, 0, 5));
            self::fail($s, count($unread) . ' files cannot be read (no permission): ' . $list, 'e_unreadable_files', array('n' => count($unread), 'list' => $list));
            return;
        }
        if ($w['blocked']) {
            $list = implode(', ', array_slice($w['blocked'], 0, 5));
            self::fail($s, count($w['blocked']) . ' files cannot be written (no permission for the file or its folder): ' . $list, 'e_write_blocked',
                array('n' => count($w['blocked']), 'list' => $list));
            return;
        }
        $need = Service::diskNeed($to, $plan);
        $free = Util::freeSpace(Util::root());
        if ($free !== null && ($free < $need || Config::fault('disk_low'))) {
            self::fail($s, 'not enough free disk space: ' . Util::bytes($need) . ' needed, ' . Util::bytes((float)$free) . ' free', 'e_disk_low',
                array('need' => Util::bytes($need), 'free' => Util::bytes((float)$free)));
            return;
        }
        self::say($s, 'planned', array('add' => count($plan['add']), 'replace' => count($plan['replace']), 'delete' => count($plan['delete'])),
            'Plan: ' . count($plan['add']) . ' new, ' . count($plan['replace']) . ' replaced, ' . count($plan['delete']) . ' removed files'
            . ($plan['merge'] ? '; ' . count($plan['merge']) . ' kept for a manual merge' : '') . ($plan['keep'] ? '; ' . count($plan['keep']) . ' changed files kept' : ''));
        self::next($s, 'download');
        Util::removeTree(Util::root() . 'runtime/update/prepare/run-' . $s['id']);
    }

    /**
     * Removes the run's skipped paths from a plan (the bridge file sits at install.php and replaces itself only at the
     * very end); they are listed under 'skipped'.
     */
    private static function applySkip(array $plan, array $skip)
    {
        $plan['skipped'] = array();
        if (!$skip) {
            return $plan;
        }
        $keys = array();
        foreach ($skip as $p) {
            $keys[Paths::key($p)] = true;
        }
        foreach (array('add', 'replace', 'delete', 'modified', 'keep', 'merge', 'unreadable', 'needed') as $k) {
            $keep = array();
            foreach ((array)($plan[$k] ?? array()) as $p) {
                if (isset($keys[Paths::key($p)])) {
                    $plan['skipped'][$p] = true;
                } else {
                    $keep[] = $p;
                }
            }
            $plan[$k] = $keep;
        }
        $plan['skipped'] = array_keys($plan['skipped']);
        return $plan;
    }

    /**
     * Release files of $force are replaced even when the site's copy differs from the installed release, and are not
     * reported as changed by the site: the bridge file of a 1.2.0 site sits at install.php and is replaced by the new
     * release's install.php in the swap like any other file (a rollback brings the bridge back, which can then restore
     * the original install.php).
     */
    public static function applyForce(array $plan, array $force)
    {
        if (!$force) {
            return $plan;
        }
        $keys = array();
        foreach ($force as $p) {
            $keys[Paths::key($p)] = true;
        }
        $forced = array();
        foreach (array('modified', 'keep', 'merge', 'needed') as $k) {
            $keep = array();
            foreach ((array)($plan[$k] ?? array()) as $p) {
                if (isset($keys[Paths::key($p)])) {
                    $forced[$p] = true;
                } else {
                    $keep[] = $p;
                }
            }
            $plan[$k] = $keep;
        }
        foreach (array_keys($forced) as $p) {
            if (!in_array($p, $plan['replace'], true) && !in_array($p, $plan['add'], true)) {
                $plan['replace'][] = $p;
            }
        }
        $plan['forced'] = array_keys($forced);
        return $plan;
    }

    /**
     * Health check of the current phase: server-side probes (the server's own address first); when this server cannot
     * answer its own requests (one worker) or the site refuses them (HTTP basic auth on the admin, an IP allow-list, a
     * CDN / firewall challenge), the admin's browser probes instead (the step answer carries 'health_probe'; the page
     * reports through the runner action "health"). null = still waiting for that report.
     */
    private static function healthResult(array &$s, $deadline)
    {
        $hb = (array)($s['health']['browser'] ?? array());
        if (!empty($hb['report'])) {
            $h = Health::judgeReport((array)$hb['report']);
            unset($s['health']['browser']);
            return $h;
        }
        if (!empty($hb['asked'])) {
            if (time() - (int)$hb['asked'] > self::HEALTH_BROWSER_WAIT) {
                unset($s['health']['browser']);
                return array('ok' => false, 'checks' => array(array('name' => 'browser', 'path' => '', 'status' => 0, 'ok' => false,
                    'why' => 'the server cannot check the pages itself and no browser reported them', 'k' => 'hr_nobrowser', 'v' => array(), 'ms' => 0)),
                    'error' => 'health check failed');
            }
            $s['wait'] = 1000;
            return null;
        }
        $h = Health::run($s['base'], $s['admin'], $s['probe'], $deadline, (string)($s['origin'] ?? ''));
        if ((!empty($h['unreachable']) || !empty($h['refused'])) && PHP_SAPI !== 'cli' && empty($s['rescue'])) {
            $s['health']['browser'] = array('asked' => time(), 'why' => !empty($h['refused']) ? 'refused' : 'unreachable');
            if (!empty($h['refused'])) {
                self::say($s, 'health_refused', array(), 'The site refuses this server\'s own requests (password, allow-list or firewall): the admin page checks the pages instead');
            } else {
                self::say($s, 'health_browser', array(), 'This server does not answer requests it makes to itself (one worker): the admin page checks the pages instead');
            }
            $s['wait'] = 200;
            return null;
        }
        return $h;
    }

    /** Stores the health results the admin's browser reported for run $id (runner action "health"). */
    public static function healthReport($id, array $report)
    {
        $lock = Store::lock();
        if ($lock === null) {
            return false;
        }
        $s = Store::load($id);
        $ok = false;
        if ($s !== null && $s['status'] === 'running' && !empty($s['health']['browser']['asked'])) {
            $s['health']['browser']['report'] = array_slice($report, 0, 8);
            Store::save($s);
            $ok = true;
        }
        Store::unlock($lock);
        return $ok;
    }

    /** Adds the swap order and the expected hashes to a plan. */
    private static function finishPlan(array $plan, Manifest $to)
    {
        $files = $to->files();
        $list = array_merge($plan['add'], $plan['replace']);
        usort($list, function ($a, $b) {
            $ra = Paths::swapRank($a);
            $rb = Paths::swapRank($b);
            return $ra === $rb ? strcmp($a, $b) : $ra - $rb;
        });
        $plan['order'] = $list;
        $plan['expect'] = array();
        foreach ($list as $p) {
            $plan['expect'][$p] = $files[$p][0];
        }
        return $plan;
    }

    private static function download(array &$s, $deadline)
    {
        $to = self::manifest($s, 'to');
        $z = $to->zip();
        $dir = Util::dir('downloads');
        if ($dir === '') {
            self::fail($s, 'runtime/update/downloads cannot be created', 'e_downloads_dir');
            return;
        }
        $final = $dir . $z['name'];
        // an archive already here (earlier attempt, owner's inbox) is used when its size fits; verify_zip checks it
        $inbox = Source::inboxZip($to);
        if (is_file($final) && filesize($final) === $z['size']) {
            $s['zip'] = 'runtime/update/downloads/' . $z['name'];
            self::next($s, 'verify_zip');
            return;
        }
        if ($inbox !== '') {
            $s['zip'] = 'runtime/update/inbox/' . $z['name'];
            self::say($s, 'inbox', array(), 'Using the package uploaded into runtime/update/inbox/');
            self::next($s, 'verify_zip');
            return;
        }
        $urls = Source::zipUrls($to);
        $s['dl'] = ($s['dl'] ?? array()) + array('url_i' => 0, 'stall' => 0, 'bytes' => 0, 'expired' => 0, 'rounds' => 0, 'fresh' => array());
        $part = $final . '.part';
        if ($s['dl']['url_i'] >= count($urls)) {
            $s['dl']['rounds']++;
            // a failed round starts the next one from zero: a half file may come from a server that got the range wrong
            @unlink($part);
            if ($s['dl']['rounds'] >= 3) {
                $why = (string)($s['dl']['last_error'] ?? 'no answer');
                self::fail($s, 'the package could not be downloaded from GitHub: ' . $why, 'e_download', array('why' => $why));
                return;
            }
            $s['dl']['url_i'] = 0;
            $s['dl']['fresh'] = array();
            $s['wait'] = 5000 * $s['dl']['rounds'];
            Store::log($s['id'], 'warn', 'The download failed; trying again in ' . (5 * $s['dl']['rounds']) . ' s', 'download', 'l_dl_round',
                array('s' => 5 * $s['dl']['rounds']));
            return;
        }
        $url = $urls[$s['dl']['url_i']];
        $r = Http::download($url, $part, $deadline, $z['size']);
        $s['progress'] = array('done' => $r['bytes'], 'total' => $z['size']);
        if ($r['state'] === 'done') {
            if (!Util::renameRetry($part, $final, 2.0)['ok']) {
                self::fail($s, 'cannot move the downloaded package into place', 'e_move_zip');
                return;
            }
            $s['zip'] = 'runtime/update/downloads/' . $z['name'];
            $s['zip_src'] = Http::show($url);
            self::say($s, 'downloaded', array('size' => Util::bytes($z['size']), 'host' => (string)parse_url($url, PHP_URL_HOST)),
                'Downloaded ' . Util::bytes($z['size']) . ' from ' . (string)parse_url($url, PHP_URL_HOST));
            self::next($s, 'verify_zip');
            return;
        }
        if (!empty($r['range_bad'])) {
            // the server answered the resume with another range: the part is gone (Http), this source once more from zero
            $key = (string)$s['dl']['url_i'];
            if (empty($s['dl']['fresh'][$key])) {
                $s['dl']['fresh'][$key] = 1;
                $s['dl']['stall'] = 0;
                Store::log($s['id'], 'warn', 'The server answered the resume with a wrong range; downloading again from the start', 'download', 'l_dl_range');
                return;
            }
            $s['dl']['last_error'] = Http::show($url) . ': ' . $r['error'];
            $s['dl']['url_i']++;
            $s['dl']['stall'] = 0;
            return;
        }
        if ($r['state'] === 'more') {
            if ($r['bytes'] <= $s['dl']['bytes']) {
                $s['dl']['stall']++;
            } else {
                $s['dl']['stall'] = 0;
            }
            $s['dl']['bytes'] = $r['bytes'];
            if ($s['dl']['stall'] >= 4) {
                $s['dl']['last_error'] = 'no progress' . ($r['error'] !== '' ? ': ' . $r['error'] : '');
                Store::log($s['id'], 'warn', 'No download progress from ' . Http::show($url) . '; trying the next source', 'download', 'l_dl_stall',
                    array('url' => Http::show($url)));
                $s['dl']['url_i']++;
                $s['dl']['stall'] = 0;
                return;
            }
            self::say($s, 'download', array('got' => Util::bytes($r['bytes']), 'total' => Util::bytes($z['size'])),
                'Downloading: ' . Util::bytes($r['bytes']) . ' of ' . Util::bytes($z['size']));
            return;
        }
        // error: an expired asset link (403/404 from the asset host) gets a fresh redirect once, then the next source
        $s['dl']['last_error'] = Http::show($url) . ': ' . $r['error'];
        if (in_array($r['status'], array(401, 403, 404, 410), true) && $s['dl']['expired'] < 1 && $r['bytes'] > 0) {
            $s['dl']['expired']++;
            Store::log($s['id'], 'warn', 'Download link expired (HTTP ' . $r['status'] . '); asking GitHub for a fresh one', 'download', 'l_dl_expired',
                array('status' => $r['status']));
            return;
        }
        Store::log($s['id'], 'warn', 'Download from ' . Http::show($url) . ' failed: ' . $r['error'], 'download', 'l_dl_failed',
            array('url' => Http::show($url), 'why' => $r['error']));
        $s['dl']['url_i']++;
        $s['dl']['expired'] = 0;
        $s['dl']['stall'] = 0;
    }

    private static function stage(array &$s, $deadline)
    {
        $to = self::manifest($s, 'to');
        $plan = self::plan($s);
        $files = $to->files();
        $list = array_merge($plan['order'], $plan['merge']);
        $err = '';
        $zip = Zip::open(Util::abs($s['zip']), $err);
        if ($zip === null) {
            self::fail($s, 'the package cannot be read: ' . $err, 'e_zip_read', array('why' => $err));
            return;
        }
        $base = Store::runDir($s['id'], 'staging');
        $mergeDir = Util::dir('merge/' . $s['to']);
        $i = (int)$s['cursor'];
        $n = count($list);
        while ($i < $n) {
            $p = $list[$i];
            $isMerge = in_array($p, $plan['merge'], true) && !in_array($p, $plan['order'], true);
            // a server configuration file (.htaccess, web.config) waits under an inert name: the web server would read it
            // as the rules of the staging or merge folder (Paths::inertName); the swap writes it under its real name
            $dest = $isMerge ? $mergeDir . Paths::inertName($p) : $base . Paths::inertName($p);
            $r = $zip->extract($p, $dest, $files[$p][0], $files[$p][1]);
            if (!$r['ok']) {
                self::fail($s, 'unpacking failed: ' . $r['error'], 'e_unpack', array('why' => $r['error']));
                return;
            }
            $i++;
            self::maybeDie($s, $i);
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        $s['cursor'] = $i;
        $s['progress'] = array('done' => $i, 'total' => $n);
        if ($i < $n) {
            self::say($s, 'stage', array('done' => $i, 'total' => $n), 'Unpacking and checking files: ' . $i . ' of ' . $n);
            return;
        }
        if ($plan['merge']) {
            $copies = array();
            foreach ($plan['merge'] as $mp) {
                $copies[] = Paths::inertName($mp);
            }
            $copies = implode(', ', $copies);
            Store::log($s['id'], 'info', 'Kept your changed ' . implode(', ', $plan['merge']) . '; the new release\'s copies are in runtime/update/merge/' . $s['to'] . '/: '
                . $copies, 'stage', 'l_merge_kept', array('list' => implode(', ', $plan['merge']), 'v' => $s['to'], 'copies' => $copies));
        }
        self::say($s, 'stage_done', array('n' => $n), 'Unpacked and checked ' . $n . ' files');
        self::next($s, 'backup_files');
    }

    /**
     * backup_db: the job is created and saved at once (a slice that dies keeps writing the same backup, never a second
     * set), written in slices, then read back in slices. A backup that does not read back stops the update here, before
     * anything of the site changes.
     */
    private static function backupDb(array &$s, $deadline)
    {
        if (!isset($s['backup']['db'])) {
            $to = self::manifest($s, 'to');
            $job = Backup::dbJob($to, 'before ' . $s['from'] . ' -> ' . $s['to'] . ' (run ' . $s['id'] . ')');
            if ($job === null) {
                Store::log($s['id'], 'warn', 'Database not reachable or empty: no database backup made', 'backup_db', 'l_db_none');
                $s['backup']['db_location'] = '';
                self::next($s, 'maint_on');
                return;
            }
            $s['backup']['db'] = $job;
            $n = count($job['tables']);
            $withData = 0;
            foreach ($job['tables'] as $t) {
                $withData += !empty($t['data']) ? 1 : 0;
            }
            if ($job['full']) {
                Store::log($s['id'], 'info', 'Database backup: ' . $n . ' tables (whole database), about ' . Util::bytes($job['bytes']) . ' of data', 'backup_db',
                    'l_db_full', array('n' => $n, 'size' => Util::bytes($job['bytes'])));
            } else {
                Store::log($s['id'], 'info', 'Database backup (partial, the database is big): the data of ' . $withData . ' tables this release changes or that are small, the structure of all '
                    . $n . ' tables, about ' . Util::bytes($job['bytes']) . ' of data, into ' . $job['folder'], 'backup_db',
                    'l_db_partial', array('data' => $withData, 'n' => $n, 'size' => Util::bytes($job['bytes']), 'folder' => $job['folder']));
            }
            Store::save($s);
        }
        $job = $s['backup']['db'];
        if (empty($job['done'])) {
            $r = Backup::dbStep($job, $deadline);
            $s['backup']['db'] = $job;
            $s['progress'] = array('done' => (int)$job['i'], 'total' => count($job['tables']));
            if ($r['state'] === 'error') {
                self::fail($s, $r['error'], (string)($r['k'] ?? ''), (array)($r['v'] ?? array()));
                return;
            }
            if ($r['state'] === 'more') {
                self::say($s, 'backup_db', array('done' => (int)$job['i'] + 1, 'total' => count($job['tables']), 'rows' => number_format((int)$job['rows'])),
                    'Database backup: table ' . ((int)$job['i'] + 1) . ' of ' . count($job['tables']) . ', ' . number_format((int)$job['rows']) . ' rows');
                return;
            }
            $job['done'] = true;
            $s['backup']['db'] = $job;
            self::say($s, 'backup_db_check', array('done' => 0, 'total' => count($job['tables'])), 'Checking the database backup: table 0 of ' . count($job['tables']));
            if (microtime(true) >= $deadline) {
                return;
            }
        }
        $r = Backup::dbVerifyStep($job, $deadline);
        $s['backup']['db'] = $job;
        $s['progress'] = array('done' => (int)$job['v_i'], 'total' => count($job['tables']));
        if ($r['state'] === 'error') {
            self::fail($s, 'the database backup does not read back correctly: ' . $r['error'], 'e_backup_verify', (array)($r['v'] ?? array()));
            return;
        }
        if ($r['state'] === 'more') {
            self::say($s, 'backup_db_check', array('done' => (int)$job['v_i'], 'total' => count($job['tables'])),
                'Checking the database backup: table ' . (int)$job['v_i'] . ' of ' . count($job['tables']));
            return;
        }
        $parts = count(array_unique(array_values((array)$job['parts'])));
        if (empty($job['checked'])) {
            Store::log($s['id'], 'info', 'Database backup read back and checked: ' . $parts . ' parts, ' . number_format((int)$job['rows']) . ' rows', 'backup_db',
                'l_db_checked', array('parts' => $parts, 'rows' => number_format((int)$job['rows'])));
            $job['checked'] = true;
            $s['backup']['db'] = $job;
        }
        if ($job['full'] && empty($job['published'])) {
            // only a complete set that read back correctly ever reaches the folder Backup & restore lists; its name there
            // is fixed (and saved) before the first part moves, so a step that dies here finds the same name again
            if (empty($job['pub_name'])) {
                $job['pub_name'] = Backup::publishName($job);
                $s['backup']['db'] = $job;
                Store::save($s);
            }
            $r = Backup::publishStep($job, $deadline);
            $s['backup']['db'] = $job;
            if ($r['state'] === 'error') {
                self::fail($s, 'the database backup could not be moved into Backup & restore: ' . $r['error'], 'e_backup_publish', (array)($r['v'] ?? array()));
                return;
            }
            if ($r['state'] === 'more') {
                self::say($s, 'backup_db_publish', array(), 'Moving the database backup into Backup & restore');
                return;
            }
        }
        if ($job['full']) {
            $s['backup']['db_location'] = $job['folder'] . $job['name'] . '-*.sql' . (!empty($job['gz']) ? '.gz' : '');
            self::say($s, 'backup_db_done', array('location' => $s['backup']['db_location'], 'parts' => $parts),
                'Database backup saved: ' . $s['backup']['db_location'] . ' (' . $parts . ' parts; Admins & backup > Backup & restore lists it)');
        } else {
            $s['backup']['db_location'] = $job['folder'];
            self::say($s, 'backup_db_done_partial', array('location' => $job['folder'], 'parts' => $parts),
                'Partial database backup saved in ' . $job['folder'] . ' (' . $parts . ' parts, see README.txt there; it is not a full backup and Backup & restore does not list it)');
        }
        self::next($s, 'maint_on');
    }

    private static function swap(array &$s, $deadline)
    {
        $plan = self::plan($s);
        $list = !empty($s['sub']['redo']) ? array_values($s['sub']['redo']) : $plan['order'];
        $journal = Store::loadPart($s['id'], 'journal');
        $journal += array('swapped' => array(), 'deleted' => array(), 'dirs' => array());
        $staging = Store::runDir($s['id'], 'staging');
        $i = (int)$s['cursor'];
        $n = count($list);
        $changedPlan = false;
        while ($i < $n) {
            $p = $list[$i];
            $target = $plan['targets'][$p] ?? $p;
            $want = $plan['expect'][$p];
            $abs = Util::abs($target);
            if (Util::sha256($abs) === $want && !Paths::isPlayerConfig($p)) {
                $journal['swapped'][$p] = $journal['swapped'][$p] ?? 'same';
                $i++;
                continue;
            }
            $content = null;
            if (Paths::isPlayerConfig($p) && is_file($abs)) {
                $merged = Swapper::mergePlayerConfig((string)@file_get_contents($abs), (string)@file_get_contents($staging . Paths::inertName($p)));
                if ($merged !== null) {
                    $content = $merged;
                    $want = hash('sha256', $merged);
                    if (Util::sha256($abs) === $want) {
                        $i++;
                        continue;
                    }
                    $plan['expect'][$p] = $want;
                    $changedPlan = true;
                }
            }
            $r = Swapper::put($staging . Paths::inertName($p), $target, $want, true, $content);
            if (!$r['ok']) {
                $s['attempts']['swap'] = (int)($s['attempts']['swap'] ?? 0) + 1;
                Store::log($s['id'], 'warn', $r['error'], 'swap', (string)($r['k'] ?? ''), (array)($r['v'] ?? array()));
                if ($s['attempts']['swap'] < 3 && strpos($r['error'], 'disk full') === false) {
                    // a file another program holds (sharing violation): the next step tries again
                    $s['wait'] = 2000;
                    break;
                }
                Store::savePart($s['id'], 'journal', $journal);
                if ($changedPlan) {
                    Store::savePart($s['id'], 'plan', $plan);
                }
                self::toRollback($s, $r['error'], (string)($r['k'] ?? ''), (array)($r['v'] ?? array()));
                return;
            }
            $journal['swapped'][$p] = $r['method'];
            foreach ($r['dirs'] as $d) {
                $journal['dirs'][] = $d;
            }
            if (Paths::isEntry($p)) {
                // the web server takes a new entry script at its next request: a cache that never re-reads files would keep
                // the old one - without the maintenance gate on a 1.2.0 site - until the caches phase (no-op on the command
                // line, which cannot reach the web server's cache)
                Util::opcacheInvalidate(array(Util::abs($target)));
            }
            $i++;
            self::maybeDie($s, $i);
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        Store::savePart($s['id'], 'journal', $journal);
        if ($changedPlan) {
            Store::savePart($s['id'], 'plan', $plan);
        }
        $s['cursor'] = $i;
        $s['progress'] = array('done' => $i, 'total' => $n);
        if ($i < $n) {
            self::say($s, 'swap', array('done' => $i, 'total' => $n), 'Replacing files: ' . $i . ' of ' . $n);
            return;
        }
        self::say($s, 'swap_done', array('n' => $n), 'Replaced ' . $n . ' files');
        $redo = !empty($s['sub']['redo']);
        self::next($s, $redo ? 'verify_files' : 'delete');
    }

    /**
     * Leftover temp files next to the files of $plan (".<name>.<tag>.up-tmp" / ".up-old" of a step that died between a
     * write and its rename, a replaced file another program still held): one listing per folder, only the names the plan
     * touches; leftovers of this run go at once, older ones of other attempts after two minutes. Sliced with $sub['wi'];
     * returns null while folders are left, else the removed paths.
     */
    private static function sweepPlanFolders(array $plan, array &$sub, $deadline)
    {
        if (!isset($sub['wdirs'])) {
            $dirs = array();
            foreach (array_merge($plan['add'] ?? array(), $plan['replace'] ?? array(), $plan['delete'] ?? array()) as $p) {
                $t = (string)($plan['targets'][$p] ?? $p);
                $d = dirname($t);
                $dirs[$d === '.' ? '' : $d][] = basename($t);
            }
            ksort($dirs, SORT_STRING);
            $sub['wdirs'] = $dirs;
            $sub['wi'] = 0;
            $sub['swept'] = array();
        }
        $keys = array_keys($sub['wdirs']);
        while ($sub['wi'] < count($keys)) {
            $d = (string)$keys[$sub['wi']];
            foreach (Util::sweepTemps(Util::root() . $d, (array)$sub['wdirs'][$keys[$sub['wi']]], 120) as $gone) {
                if (count($sub['swept']) < 200) {
                    $sub['swept'][] = $gone;
                }
            }
            $sub['wi']++;
            if (($sub['wi'] % 25) === 0 && microtime(true) >= $deadline) {
                return null;
            }
        }
        $out = (array)$sub['swept'];
        unset($sub['wdirs'], $sub['wi'], $sub['swept']);
        return $out;
    }

    /** verify_files, sliced: every written file hashed again, every deleted file gone, temp leftovers removed. */
    private static function verifyFiles(array &$s, $deadline)
    {
        $plan = self::plan($s);
        $keys = array_keys($plan['expect']);
        $sub = (array)$s['sub'] + array('vi' => 0, 'di' => 0, 'bad' => array());
        $total = count($keys) + count($plan['delete']);
        while ($sub['vi'] < count($keys)) {
            $p = $keys[$sub['vi']];
            $t = $plan['targets'][$p] ?? $p;
            if (Util::sha256(Util::abs($t)) !== $plan['expect'][$p]) {
                $sub['bad'][] = $p;
            }
            $sub['vi']++;
            if (($sub['vi'] % 20) === 0 && microtime(true) >= $deadline) {
                break;
            }
        }
        if ($sub['vi'] >= count($keys)) {
            while ($sub['di'] < count($plan['delete'])) {
                $p = $plan['delete'][$sub['di']];
                if (is_file(Util::abs($p))) {
                    $sub['bad'][] = $p;
                }
                $sub['di']++;
                if (($sub['di'] % 50) === 0 && microtime(true) >= $deadline) {
                    break;
                }
            }
        }
        $s['sub'] = $sub;
        $done = $sub['vi'] + $sub['di'];
        $s['progress'] = array('done' => $done, 'total' => $total);
        if ($done < $total) {
            self::say($s, 'verify_files', array('done' => $done, 'total' => $total), 'Checking written files: ' . $done . ' of ' . $total);
            return;
        }
        $bad = $sub['bad'];
        if ($bad) {
            $s['attempts']['verify_files'] = (int)($s['attempts']['verify_files'] ?? 0) + 1;
            $list = implode(', ', array_slice($bad, 0, 5));
            if ($s['attempts']['verify_files'] < 2) {
                Store::log($s['id'], 'warn', count($bad) . ' files differ after the swap; writing them again: ' . $list, 'verify_files', 'l_verify_redo',
                    array('n' => count($bad), 'list' => $list));
                self::next($s, 'swap');
                $s['sub']['redo'] = $bad;
                return;
            }
            self::toRollback($s, count($bad) . ' files are not as the release has them after the swap: ' . $list, 'e_verify_files',
                array('n' => count($bad), 'list' => $list));
            return;
        }
        $swept = self::sweepPlanFolders($plan, $sub, $deadline);
        $s['sub'] = $sub;
        if ($swept === null) {
            self::say($s, 'verify_files', array('done' => $done, 'total' => $total), 'Checking written files: ' . $done . ' of ' . $total);
            return;
        }
        if ($swept) {
            Store::log($s['id'], 'info', 'Removed ' . count($swept) . ' leftover temporary files: ' . implode(', ', array_slice($swept, 0, 10)), 'verify_files',
                'l_temps_removed', array('n' => count($swept), 'list' => implode(', ', array_slice($swept, 0, 10))));
        }
        self::say($s, 'verify_ok', array('n' => count($keys)), 'All ' . count($keys) . ' written files checked (SHA-256)');
        self::next($s, 'caches');
    }

    /**
     * One slice of clearing the caches for $files: OPcache (invalidate every file, then reset: from a web request; the
     * command line asks the web server through the maintenance gate), compiled templates (runtime/temp, links never
     * followed) and the page cache generation (runtime/edge/GEN). Cursor in $s['sub']['ci']. Returns null while work is
     * left, else ['opcache', 'templates', 'edge', 'refused' (the host refused the reset)]; 'opcache_k' / 'opcache_v' and
     * 'edge_k' carry the same facts as message keys (the admin page shows them in the admin's language).
     */
    public static function clearCachesStep(array &$s, array $files, $deadline)
    {
        if (!isset($s['sub']['ci'])) {
            $s['sub']['ci'] = 0;
            $s['sub']['inv'] = 0;
            Util::writeAtomic(Util::dir() . 'cache-list.txt', implode("\n", $files));
        }
        $out = array('opcache' => 'not used', 'templates' => 0, 'edge' => 'no page cache', 'refused' => false, 'opcache_k' => 'opc_none',
            'opcache_v' => array('n' => 0), 'edge_k' => 'edge_none');
        if (PHP_SAPI === 'cli') {
            // the command line resets only its own cache: the web server resets its own when the gate gets the token;
            // a web server that may not reset it (opcache.restrict_api) makes the upgrade wait like a web run
            $g = self::askGateReset($s);
            if (!empty($g['ok'])) {
                $s['web_opcache'] = $g;
                $out['refused'] = empty($g['reset']) && !empty($g['enabled']);
                $out['opcache'] = !empty($g['reset']) ? 'reset through the web server' : (!empty($g['enabled'])
                    ? 'the web server refused the reset (' . (int)$g['invalidated'] . ' files invalidated)' : 'not used by the web server');
                $out['opcache_k'] = !empty($g['reset']) ? 'opc_gate' : (!empty($g['enabled']) ? 'opc_gate_refused' : 'opc_none');
                $out['opcache_v'] = array('n' => (int)$g['invalidated']);
                // a cache that never re-reads files, refused the reset and did not take every file of the list one by one
                // keeps running what it compiled before the swap (the update rolls back: e2e-4 P2-1)
                $out['stuck'] = $out['refused'] && Util::opcacheNeverRereads($g['vt']) && (int)$g['invalidated'] < count($files);
            } else {
                $out['opcache'] = 'web server not reachable: restart PHP if pages look old';
                $out['opcache_k'] = 'opc_unreachable';
                // the site answers this server without the gate (the web server runs other entry scripts than the ones
                // on disk) - told apart from a web server that cannot be reached at all
                $out['stale'] = self::homeAnswers($s);
            }
        } elseif (Util::opcacheActive()) {
            while ($s['sub']['ci'] < count($files)) {
                if (Util::call('opcache_invalidate', array($files[$s['sub']['ci']], true), false)) {
                    $s['sub']['inv']++;
                }
                $s['sub']['ci']++;
                if (($s['sub']['ci'] % 50) === 0 && microtime(true) >= $deadline) {
                    return null;
                }
            }
            $reset = (bool)Util::call('opcache_reset', array(), false);
            $out['refused'] = !$reset;
            $out['opcache'] = ($reset ? 'reset' : 'reset refused') . ', ' . (int)$s['sub']['inv'] . ' invalidated';
            $out['opcache_k'] = $reset ? 'opc_reset' : 'opc_refused';
            $out['opcache_v'] = array('n' => (int)$s['sub']['inv']);
            // the same rule in the web server itself (opcache functions disabled or restricted, validate_timestamps=0)
            $out['stuck'] = !$reset && Util::opcacheNeverRereads(ini_get('opcache.validate_timestamps')) && (int)$s['sub']['inv'] < count($files);
        }
        $t = self::wipeTemplates($deadline);
        if ($t === null) {
            return null;
        }
        $out['templates'] = $t;
        $gen = Util::root() . 'runtime/edge/GEN';
        if (is_dir(dirname($gen))) {
            $edgeOk = Util::writeAtomic($gen, Util::randomId(6));
            $out['edge'] = $edgeOk ? 'new generation' : 'could not write';
            $out['edge_k'] = $edgeOk ? 'edge_new' : 'edge_fail';
        }
        unset($s['sub']['ci'], $s['sub']['inv']);
        return $out;
    }

    /**
     * The command line's OPcache reset: the web server resets its own cache when the gate gets the run's token, and says
     * what it did and how its cache re-reads files. $files: written into cache-list.txt first (the files the web server
     * invalidates one by one before its reset). Returns ['ok' (the gate answered), 'reset' (the web server's cache was
     * reset), 'invalidated', 'enabled', 'vt', 'freq', 'restricted'].
     */
    private static function askGateReset(array $s, $files = null)
    {
        $none = array('ok' => false, 'reset' => false, 'invalidated' => 0, 'enabled' => false, 'vt' => null, 'freq' => 0, 'restricted' => false);
        if (is_array($files) && Util::dir() !== '') {
            Util::writeAtomic(Util::dir() . 'cache-list.txt', implode("\n", $files));
        }
        if ((string)$s['base'] === '') {
            return $none;
        }
        $url = rtrim((string)$s['base'], '/') . '/index.php';
        $r = Health::fetch($url, '', microtime(true) + 10, array('X-Javcms-Update-Cache: ' . $s['cache_token']));
        $j = $r['status'] === 200 ? json_decode((string)$r['body'], true) : null;
        if (!is_array($j) || !array_key_exists('reset', $j)) {
            return $none;
        }
        return array('ok' => true, 'reset' => !empty($j['reset']), 'invalidated' => (int)($j['invalidated'] ?? 0),
            // an older gate does not tell: a cache that was not reset is then taken to be one that is used (and one that
            // re-reads files: vt null)
            'enabled' => array_key_exists('enabled', $j) ? !empty($j['enabled']) : true,
            'vt' => array_key_exists('vt', $j) ? (string)$j['vt'] : null, 'freq' => (int)($j['freq'] ?? 0), 'restricted' => !empty($j['restricted']));
    }

    /**
     * The command line asks whether the web server runs the gate (maintenance on). Returns ['state' => 'gate' (it answered
     * the ping) | 'stale' (the site answers, but without the gate: the web server runs entry scripts that do not have it)
     * | 'unreachable' (no answer from the site at all), and what the gate said of the web server's OPcache: 'enabled',
     * 'vt' (validate_timestamps; null when not told), 'freq', 'reset' (the reset the gate just tried worked)].
     */
    private static function gatePing(array $s)
    {
        $out = array('state' => 'unreachable', 'enabled' => false, 'vt' => null, 'freq' => 0, 'reset' => false);
        if ((string)$s['base'] === '') {
            return $out;
        }
        $url = rtrim((string)$s['base'], '/') . '/index.php';
        $r = Health::fetch($url, '', microtime(true) + 8, array('X-Javcms-Update-Ping: ' . $s['cache_token']));
        $j = $r['status'] === 200 ? json_decode((string)$r['body'], true) : null;
        if (is_array($j) && !empty($j['gate'])) {
            // an older gate says nothing of the OPcache: taken as one the update may reset (the caches phase checks again)
            return array('state' => 'gate', 'enabled' => !empty($j['enabled']), 'vt' => array_key_exists('vt', $j) ? (string)$j['vt'] : null,
                'freq' => (int)($j['freq'] ?? 0), 'reset' => array_key_exists('reset', $j) ? !empty($j['reset']) : true);
        }
        $out['state'] = self::homeAnswers($s, $r) ? 'stale' : 'unreachable';
        return $out;
    }

    /**
     * True when the site answers this server while maintenance is on, but not through the gate (any answer the gate never
     * gives: not the 503 maintenance page, not "no answer"). $r: an answer already fetched from the home page.
     */
    private static function homeAnswers(array $s, $r = null)
    {
        if ($r === null) {
            if ((string)$s['base'] === '') {
                return false;
            }
            $r = Health::fetch(rtrim((string)$s['base'], '/') . '/', '', microtime(true) + 8);
        }
        $st = (int)($r['status'] ?? 0);
        return $st >= 200 && $st < 500 && $st !== 503;
    }

    /**
     * Seconds left until a web server whose OPcache may not be reset (opcache.restrict_api) sees files written at $at: it
     * re-reads changed files every revalidate_freq seconds (at most 60 as the update counts it); 0 when the cache was
     * reset, never re-reads files (validate_timestamps=0: a restart is needed) or re-reads them at once. The command line
     * takes the WEB server's settings from the gate's answer.
     */
    private static function cacheWaitLeft(array $s, $at, $refused)
    {
        if (!$refused) {
            return 0;
        }
        $web = PHP_SAPI === 'cli' && !empty($s['web_opcache']['ok']);
        $vt = $web ? ($s['web_opcache']['vt'] ?? null) : (string)ini_get('opcache.validate_timestamps');
        $freq = min(60, max(0, $web ? (int)$s['web_opcache']['freq'] : (int)ini_get('opcache.revalidate_freq')));
        // php.ini's "Off" reads back as "": a cache that never re-reads files (Util::opcacheNeverRereads)
        if (Util::opcacheNeverRereads($vt) || $freq <= 0) {
            return 0;
        }
        return max(0, (int)$at + $freq + 1 - time());
    }

    /** Empties runtime/temp (compiled templates) without following links; files removed, or null when time ran out. */
    public static function wipeTemplates($deadline = null)
    {
        $dir = Util::root() . 'runtime/temp';
        if (!Util::realDir($dir)) {
            return 0;
        }
        $n = 0;
        foreach ((array)@scandir($dir) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..' || $name === 'index.html') {
                continue;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                return null;
            }
            $p = $dir . '/' . $name;
            if (is_dir($p) && Util::realDir($p)) {
                if ($deadline !== null ? Util::removeTreeTimed($p, $deadline) : Util::removeTree($p)) {
                    $n++;
                }
            } elseif (@unlink($p)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * The new release's upgrade steps, run by the NEW code: the framework of the swapped tree is started in this request
     * (like tools/upgrade.php does on the command line) and app\common\util\updater\UpgradeSteps of the new release does
     * one bounded slice. The settings files are copied once more right before (a rollback restores only what the upgrade
     * changed), the version number in application/extra/version.php is stamped at the end, and PHP's cache is told about
     * every settings file written (a host whose OPcache never re-reads files would show the old version).
     */
    private static function upgrade(array &$s, $deadline)
    {
        // a host that refused the cache reset (opcache.restrict_api) sees the new files only when OPcache re-reads them;
        // the command line waits for the WEB server's cache (its settings came with the gate's answer)
        if (!empty($s['opcache_refused']) && !$s['upgrade']['started']) {
            $left = self::cacheWaitLeft($s, (int)($s['caches_at'] ?? 0), true);
            if ($left > 0) {
                $s['wait'] = 1000 * min($left, 10);
                self::say($s, 'upgrade_wait', array('s' => $left), 'Waiting ' . $left . ' s until PHP\'s cache sees the new files (this host does not let the update reset it)');
                return;
            }
        }
        if (!$s['upgrade']['started']) {
            $s['backup']['settings_pre'] = Backup::settings($s['id'], 'settings-pre');
        }
        $s['upgrade']['started'] = true;
        $faults = Config::faults();
        if (!empty($faults['upgrade_fail'])) {
            self::toRollback($s, 'upgrade step failed (test fault)', 'e_upgrade', array('why' => 'test fault'));
            return;
        }
        $cls = 'app\\common\\util\\updater\\UpgradeSteps';
        $r = null;
        if (is_file(Util::abs('application/common/util/updater/UpgradeSteps.php'))) {
            $boot = self::bootFramework();
            if ($boot !== '') {
                self::toRollback($s, 'the new release does not start: ' . $boot, 'e_upgrade_boot', array('why' => $boot));
                return;
            }
            if (!class_exists($cls)) {
                self::toRollback($s, 'the new release has no upgrade steps class', 'e_upgrade_class');
                return;
            }
            try {
                $r = call_user_func(array($cls, 'run'), $s['from'], $s['to'], (array)$s['upgrade']['cursor'], $deadline,
                    array('root' => Util::root(), 'run' => $s['id'], 'test' => Config::test() !== null, 'faults' => $faults));
            } catch (\Throwable $e) {
                $why = Util::cut(Util::redact($e->getMessage()), 300);
                self::toRollback($s, 'upgrade step failed: ' . $why, 'e_upgrade', array('why' => $why));
                return;
            }
        } else {
            $r = array('state' => 'done', 'cursor' => array(), 'log' => array('the new release has no upgrade steps'));
        }
        foreach ((array)($r['log'] ?? array()) as $line) {
            // a line of the new release's steps: plain English, or ['m' => English, 't' => [locale => text]] (the steps
            // carry their own translations: this engine's language files do not know a newer release's steps)
            $text = is_array($line) ? (string)($line['m'] ?? '') : (string)$line;
            $vars = array('line' => Util::cut($text, 600));
            if (is_array($line) && is_array($line['t'] ?? null)) {
                $tr = array();
                foreach (Runner::LOCALES as $loc) {
                    if (isset($line['t'][$loc]) && is_scalar($line['t'][$loc])) {
                        $tr[$loc] = Util::cut((string)$line['t'][$loc], 600);
                    }
                }
                if ($tr) {
                    $vars['line_t'] = $tr;
                }
            }
            Store::log($s['id'], 'info', 'Upgrade: ' . $vars['line'], 'upgrade', 'l_upgrade', $vars);
        }
        $s['upgrade']['cursor'] = (array)($r['cursor'] ?? array());
        if (!empty($r['db_changed'])) {
            $s['upgrade']['db_changed'] = true;
        }
        if (($r['state'] ?? '') === 'error') {
            $why = (string)($r['error'] ?? '');
            self::toRollback($s, 'upgrade step failed: ' . $why, 'e_upgrade', array('why' => $why));
            return;
        }
        if (($r['state'] ?? '') === 'more') {
            self::say($s, 'upgrade', array('step' => (string)($r['step'] ?? '')), 'Upgrade steps of the new release: ' . (string)($r['step'] ?? 'working'));
            return;
        }
        if (!self::stampVersion($s['to'])) {
            self::toRollback($s, 'the version number could not be written to application/extra/version.php', 'e_version');
            return;
        }
        // what the upgrade wrote into the settings, as it stands now (a rollback keeps what an admin saves after this)
        $post = array();
        foreach ((array)glob(Util::abs('application/extra/*.php')) as $f) {
            $post['application/extra/' . basename((string)$f)] = Util::sha256((string)$f);
        }
        $s['backup']['settings_post'] = $post;
        $written = array();
        foreach (array_keys($post) as $rel) {
            $written[] = Util::abs($rel);
        }
        // the same three cases as the caches phase: reset, refused, or no OPcache in the web server at all (then nothing
        // is said to be refused)
        if (PHP_SAPI === 'cli') {
            $g = self::askGateReset($s, $written);
            $n = (int)$g['invalidated'];
            if (!$g['ok']) {
                $how = 'web server not reachable';
                $howK = 'opc_unreachable';
            } elseif (!empty($g['reset'])) {
                $how = 'reset through the web server';
                $howK = 'opc_gate';
            } elseif (empty($g['enabled'])) {
                $how = 'not used by the web server';
                $howK = 'opc_none';
            } else {
                $how = 'the web server refused the reset, ' . $n . ' settings files invalidated';
                $howK = 'opc_gate_refused';
            }
        } elseif (!Util::opcacheActive()) {
            $n = 0;
            $how = 'not used';
            $howK = 'opc_none';
        } else {
            $n = Util::opcacheInvalidate($written);
            $reset = (bool)Util::call('opcache_reset', array(), false);
            $how = $reset ? 'reset, ' . $n . ' settings files invalidated' : 'reset refused, ' . $n . ' settings files invalidated';
            $howK = $reset ? 'opc_reset' : 'opc_refused';
        }
        if ($howK === 'opc_none') {
            // a cache that is not used has nothing to refresh: said as such (never "refreshed ... (not used)")
            Store::log($s['id'], 'info', 'PHP\'s cache (OPcache) is not used here: nothing to refresh after the upgrade steps', 'upgrade', 'l_opcache_none');
        } else {
            Store::log($s['id'], 'info', 'PHP cache refreshed after the upgrade steps (' . $how . ')', 'upgrade', 'l_opcache',
                array('how' => $how, 'how_k' => $howK, 'how_v' => array('n' => $n)));
        }
        $s['upgrade']['done'] = true;
        self::say($s, 'upgrade_done', array('v' => $s['to']), 'Upgrade steps finished; version ' . $s['to'] . ' recorded');
        self::next($s, 'health');
    }

    /** Starts the framework of the (new) site tree in this request; '' when it worked, else the error. */
    private static function bootFramework()
    {
        if (defined('THINK_VERSION') && class_exists('think\\App', false)) {
            return '';
        }
        $root = Util::root();
        try {
            foreach (array('ROOT_PATH' => $root, 'APP_PATH' => $root . 'application/', 'MAC_COMM' => $root . 'application/common/common/',
                'MAC_HOME_COMM' => $root . 'application/index/common/', 'MAC_ADMIN_COMM' => $root . 'application/admin/common/',
                'BIND_MODULE' => 'api', 'ENTRANCE' => 'api', 'IN_FILE' => '/api.php') as $k => $v) {
                if (!defined($k)) {
                    define($k, $v);
                }
            }
            if (!defined('MAC_START_TIME')) {
                define('MAC_START_TIME', microtime(true));
            }
            require $root . 'thinkphp/base.php';
            \think\App::initCommon();
        } catch (\Throwable $e) {
            return Util::cut(Util::redact(get_class($e) . ': ' . $e->getMessage()), 300);
        }
        return '';
    }

    /** Writes $version as 'code' of application/extra/version.php; the other keys stay as the site has them. */
    public static function stampVersion($version)
    {
        $file = Util::abs('application/extra/version.php');
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return false;
        }
        if (preg_match("/(['\"]code['\"]\s*=>\s*)(['\"])([^'\"]*)(['\"])/", $raw, $m)) {
            if ($m[3] === $version) {
                return true;
            }
            $new = preg_replace("/(['\"]code['\"]\s*=>\s*)(['\"])([^'\"]*)(['\"])/", '${1}${2}' . $version . '${4}', $raw, 1);
        } else {
            return false;
        }
        $ok = Util::writeAtomic($file, (string)$new, 0644, 1.0, true) && Config::installedVersion() === $version;
        if ($ok) {
            Util::opcacheInvalidate(array($file));
        }
        return $ok;
    }

    private static function finishUpdate(array &$s, $deadline)
    {
        if (empty($s['sub']['recorded'])) {
            Util::saveData(Util::dir() . 'installed.php', array('version' => $s['to'], 'manifest' => Source::manifestName($s['to']), 'run' => $s['id'], 'at' => time()));
            $s['sub']['recorded'] = true;
        }
        // the unpacked release goes now when it fits in this step; what is left is removed by the clean-up of the updater
        Util::removeTreeTimed(self::runPath($s['id'], 'staging'), $deadline);
        foreach ((array)glob(Util::root() . 'runtime/update/test-died-' . $s['id'] . '-*') as $f) {
            @unlink((string)$f);
        }
        self::dropGateFiles();
        // a temp copy of version.php or of a settings file a step left when it died
        Util::sweepTemps(Util::abs('application/extra'), null, 120);
        $s['status'] = 'done';
        $s['ended'] = time();
        $s['rollback_until'] = time() + Config::KEEP_DAYS * 86400;
        self::say($s, 'done', array('v' => $s['to']), 'Update finished: JavCMS ' . $s['to'] . ' is installed. "Roll back to ' . $s['from'] . '" stays available for ' . Config::KEEP_DAYS . ' days.');
        Store::recordHistory($s);
        Store::clearCurrent();
        Store::dropFrozen('');
        if (!empty($s['bridge'])) {
            // the bridge file itself was replaced by the new release's install.php in the swap; its unpacked engine and
            // its sign-in files go too
            Util::removeTree(Util::root() . 'runtime/update/bridge');
        }
    }

    /**
     * The files of the maintenance gate and its cache list go with the end of a run (maintenance is off by then): gate.php,
     * every gate-<hash>.php and cache-list.txt. Data the next run writes again; inert behind the deny rules either way.
     */
    private static function dropGateFiles()
    {
        Store::dropGateFiles();
    }

    // ------------------------------------------------------------------ failures

    /** Fails a run before its files were touched, or rolls back when they were. $k / $v: the reason's message key. */
    public static function fail(array &$s, $why, $k = '', array $v = array())
    {
        $why = Util::cut(Util::redact((string)$why), 500);
        // maint_on before the maintenance page is on has changed nothing yet (the same rule as cancellable())
        $early = $s['phase'] === 'maint_on' && empty($s['maint']);
        if ($s['mode'] === 'update' && ((in_array($s['phase'], self::LIVE_PHASES, true) && !$early) || !empty($s['maint']))) {
            self::toRollback($s, $why, $k, $v);
            return;
        }
        if ($s['mode'] === 'rollback') {
            self::rollbackTrouble($s, $why, $k, $v);
            return;
        }
        $s['status'] = 'failed';
        $s['error'] = $why;
        $s['error_k'] = (string)$k;
        $s['error_v'] = $v;
        $s['error_phase'] = $s['phase'];
        $s['ended'] = time();
        $dbRemoved = self::dropEarly($s);
        self::dropOwnFlag($s);
        self::say($s, $dbRemoved ? 'failed_db' : 'failed', array('why' => $why, 'why_k' => (string)$k, 'why_v' => $v),
            'Update stopped before any site file was changed: ' . $why . ($dbRemoved ? '. Its unfinished database backup was removed.' : ''), 'error');
        Store::recordHistory($s);
        Store::clearCurrent();
        Store::dropFrozen('');
    }

    /**
     * What a run that stops before it changed anything leaves behind goes: the unpacked release, the scan, half
     * downloads (they may come from a server that got the range wrong) and its database backup set - nothing of the site
     * was changed, so the set is of no use, and an unfinished set must never look like a backup (a part may be cut in the
     * middle of a table). Bounded in time; housekeeping removes what is left. True when a database backup set was there.
     */
    public static function dropEarly(array &$s)
    {
        $until = microtime(true) + 4.0;
        Util::removeTreeTimed(self::runPath($s['id'], 'staging'), $until);
        Util::removeTreeTimed(Util::root() . 'runtime/update/prepare/run-' . $s['id'], $until);
        foreach ((array)glob(Util::root() . 'runtime/update/downloads/*.part') as $part) {
            @unlink((string)$part);
        }
        if (empty($s['backup']['db']) || !empty($s['backup']['db_removed'])) {
            return false;
        }
        $gone = Backup::removeJob((array)$s['backup']['db'], max($until, microtime(true) + 2.0));
        $s['backup']['db_removed'] = $gone ? 1 : 0;
        $s['backup']['db_location'] = '';
        Store::log($s['id'], 'info', 'The unfinished database backup of this attempt was removed: nothing of the site was changed, so it is of no use'
            . ($gone ? '' : ' (the rest goes with the next clean-up)'), $s['phase'], 'l_db_backup_removed');
        return true;
    }

    /**
     * A run that ends before it changed anything never leaves visitors on the maintenance page: a step that died between
     * writing the flag and saving the run's state (maint_on: a killed PHP worker, a server restart) leaves a flag this
     * run's state does not know about; it goes with the run (only a flag of THIS run).
     */
    private static function dropOwnFlag(array $s)
    {
        if (Store::maintenanceOffFor($s['id'])) {
            Store::log($s['id'], 'info', 'The maintenance page that a step turned on before it stopped was turned off again (nothing of the site was changed)',
                $s['phase'], 'l_maint_flag_removed');
        }
    }

    /** True while run $s may still be cancelled: an update that has not turned the maintenance page on yet. */
    public static function cancellable(array $s)
    {
        if (($s['status'] ?? '') !== 'running' || ($s['mode'] ?? '') !== 'update') {
            return false;
        }
        $early = array('scan', 'download', 'verify_zip', 'stage', 'backup_files', 'backup_db');
        return in_array($s['phase'], $early, true) || ($s['phase'] === 'maint_on' && empty($s['maint']));
    }

    /**
     * Cancels run $s (the caller holds the step lock and checked cancellable()): nothing of the site was changed, so the
     * run just ends - unpacked release, half downloads and its database backup set removed - and the page says so.
     */
    public static function cancelNow(array &$s)
    {
        $s['status'] = 'cancelled';
        $s['ended'] = time();
        $s['error'] = 'cancelled by the admin';
        $s['error_k'] = 'e_cancelled';
        $s['error_v'] = array();
        $s['error_phase'] = $s['phase'];
        $s['inflight'] = null;
        Store::log($s['id'], 'info', 'Cancelled before any site file was changed', $s['phase'], 'l_cancelled');
        $dbRemoved = self::dropEarly($s);
        self::dropOwnFlag($s);
        $s['last_msg'] = self::msg($dbRemoved ? 'cancelled_db' : 'cancelled', array(),
            'Cancelled. Nothing of your site was changed' . ($dbRemoved ? '; the unfinished database backup was removed.' : '.'));
        $s['cancel_db_removed'] = $dbRemoved;
        Store::clearCancel($s['id']);
        Store::save($s);
        Store::recordHistory($s);
        Store::clearCurrent();
        Store::dropFrozen('');
    }

    /** Switches an update run to automatic rollback. */
    public static function toRollback(array &$s, $why, $k = '', array $v = array())
    {
        $why = Util::cut(Util::redact((string)$why), 500);
        $s['error'] = $why;
        $s['error_k'] = (string)$k;
        $s['error_v'] = $v;
        $s['error_phase'] = $s['phase'];
        $s['rollback'] = array('reason' => $why, 'from_phase' => $s['phase'], 'at' => time(), 'auto' => true);
        $s['mode'] = 'rollback';
        self::say($s, 'rollback_start', array('why' => $why, 'why_k' => (string)$k, 'why_v' => $v, 'phase' => $s['phase']),
            'Update failed at "' . $s['phase'] . '": ' . $why . '. Rolling back automatically.', 'error');
        self::next($s, 'r_maint_on');
    }

    /**
     * A problem during a rollback: the step is retried (maintenance stays on, visitors never see a half site), with growing
     * pauses; after ROLLBACK_TRIES failures one after the other the rollback stops and waits for the founder.
     */
    private static function rollbackTrouble(array &$s, $why, $k = '', array $v = array())
    {
        $s['attempts']['rollback'] = (int)($s['attempts']['rollback'] ?? 0) + 1;
        $s['rollback']['last_error'] = Util::cut(Util::redact((string)$why), 500);
        // after the founder's "Try the rollback again" the founder watches: a few tries, then back to "needs attention"
        $limit = !empty($s['retried']) ? self::RETRY_TRIES : self::ROLLBACK_TRIES;
        if ($s['attempts']['rollback'] >= $limit) {
            self::needsAttention($s, $s['rollback']['last_error'], $k, $v);
            return;
        }
        $s['wait'] = min(30000, 2000 * $s['attempts']['rollback']);
        self::say($s, 'rollback_retry', array('why' => $s['rollback']['last_error'], 'why_k' => (string)$k, 'why_v' => $v),
            'Rollback step failed (' . $s['rollback']['last_error'] . '); it is tried again. Visitors keep the maintenance page meanwhile.', 'error');
    }

    /**
     * A rollback that keeps failing (a backup copy that is gone or damaged, a permission taken away during the run, a full
     * disk) stops here instead of trying for ever. Visitors keep the maintenance page (never half a site) and nothing
     * drives the run any more (the gate leaves it alone). The update's own failure stays the run's reason ('error'); why
     * the rollback stopped is kept apart ('attention'). The hand procedure is written, as precisely as the run knows it, to
     * runtime/update/runs/<run>/attention.txt (attentionGuide: the files still to copy back from backup/files/, the files to
     * delete, the settings and the database tables to restore); the founder's page names that file, the file backup, the
     * maintenance flag and - when the update changed the database and its tables are not back - the database backup set,
     * and offers "Try the rollback again" (Runner action "retry", tools/update.php --resume: on from the step that failed)
     * and "Close this run" (Runner action "close", tools/update.php --close-attention: after the rollback was finished by
     * hand; the Updates page closes the run by itself once the flag is gone and every file is back).
     */
    private static function needsAttention(array &$s, $why, $k, array $v)
    {
        $of = $s['kind'] === 'rollback' && $s['of'] !== '' ? $s['of'] : $s['id'];
        $folder = 'runtime/update/runs/' . $of . '/backup/files/';
        $back = self::versionAfter($s);
        $n = (int)$s['attempts']['rollback'];
        $s['status'] = 'attention';
        $s['wait'] = 0;
        $g = self::attentionGuide($s, $of, $why, $k, $v);
        $s['attention'] = array('at' => time(), 'phase' => $s['phase'], 'cursor' => (int)$s['cursor'], 'folder' => $folder,
            'flag' => 'runtime/update/maintenance.php', 'db' => $g['db'], 'db_tables' => $g['db_tables'], 'v' => $back, 'tries' => $n,
            'guide' => $g['file'], 'copy' => $g['copy'], 'delete' => $g['delete'], 'missing' => $g['missing'], 'settings' => $g['settings'],
            'why' => $why, 'why_k' => (string)$k, 'why_v' => $v);
        Store::maintenanceAttention($s);
        self::say($s, 'attention', array('n' => $n, 'why' => $why, 'why_k' => (string)$k, 'why_v' => $v, 'phase' => $s['phase'],
            'folder' => $folder, 'db' => $g['db'] !== '' ? $g['db'] : '-', 'v' => $back, 'guide' => $g['file']),
            'The rollback could not finish after ' . $n . ' tries (' . $why . '). Visitors get the maintenance page until runtime/update/maintenance.php is deleted. '
            . 'Fix the cause and press "Try the rollback again", or finish by hand as ' . $g['file'] . ' lists it: copy the files back from ' . $folder
            . ' (same paths), delete the files the update added'
            . ($g['db'] !== '' ? ', restore the database tables ' . implode(', ', $g['db_tables']) . ' from ' . $g['db'] . ' (the update changed the database)' : '')
            // a version without the Updates page (a rollback through the bridge) has no tools/update.php either once the
            // files the update added are deleted: the run is closed on install.php, the bridge again (e2e-5 P3-3)
            // with SSH the command line closes it before the flag goes: it deletes the flag itself and clears the web server's
            // PHP cache through it (hosting-6 VH6-2)
            . ', then ' . (self::beforeUpdater($back)
                ? 'delete runtime/update/maintenance.php and close the run on install.php, the update bridge again ("Close this run"; the version the rollback goes back to has no Updates page and no tools/update.php).'
                : 'delete runtime/update/maintenance.php and press "Close this run", or run php tools/update.php --close-attention before deleting it (the command deletes the flag itself).'), 'error');
        // the history row says what waits for the founder (it is written again when the run ends, with the update's reason)
        $h = $s;
        $h['error'] = 'the rollback could not finish after ' . $n . ' tries: ' . $why;
        $h['error_k'] = 'e_attention';
        $h['error_v'] = array('n' => $n, 'why' => $why, 'why_k' => (string)$k, 'why_v' => $v, 'phase' => $s['phase']);
        Store::recordHistory($h);
    }

    /**
     * What a rollback that stopped still has to do, item by item, from the step where it stopped: every file it puts back
     * ('copy' from the backup; 'delete' for a file that was not there before) and every file the update added ('delete'),
     * in the rollback's own order (the entry scripts last), then - for an automatic rollback whose upgrade steps ran and
     * whose settings step did not - the settings files to put back ('copy' from backup/settings-pre/) or remove. Each item:
     * ['act' => 'copy'|'delete', 'path' => site path, 'want' => sha256 of the old file (copy), 'copy' => absolute path of
     * the backup copy or '' when it is missing].
     */
    private static function attentionItems(array $s, $of)
    {
        $plan = Store::loadPart($of, 'plan');
        $index = Store::loadPart($of, 'backup');
        $out = array();
        if (!$plan) {
            return $out;
        }
        $order = self::ROLLBACK_PHASES;
        $at = array_search((string)$s['phase'], $order, true);
        $at = $at === false ? 0 : (int)$at;
        $work = array();
        if ($at <= (int)array_search('r_files', $order, true)) {
            $work = array_slice(self::rollbackWork($plan, false), $s['phase'] === 'r_files' ? (int)$s['cursor'] : 0);
        }
        if ($at <= (int)array_search('r_entries', $order, true)) {
            $work = array_merge($work, self::rollbackWork($plan, true));
        }
        foreach ($work as $w) {
            list($act, $p, $t) = $w;
            // 'entry': an entry script (the hand procedure of a rollback to a version without the maintenance gate puts
            // them back last: attentionGuide)
            if ($act === 'restore' && array_key_exists($t, $index) && $index[$t] !== null) {
                $out[] = array('act' => 'copy', 'path' => $t, 'want' => (string)$index[$t], 'copy' => Backup::copyOf($of, $t), 'entry' => Paths::isEntry($p));
            } else {
                $out[] = array('act' => 'delete', 'path' => $t, 'want' => '', 'copy' => '', 'entry' => Paths::isEntry($p));
            }
        }
        // the settings the upgrade steps changed (rollbackSettings' rule: restored when they still hold what the upgrade
        // left, or the upgrade did not finish), unless r_settings ran already
        if ($s['kind'] !== 'rollback' && !empty($s['upgrade']['started']) && $at <= (int)array_search('r_settings', $order, true)) {
            $pre = (array)($s['backup']['settings_pre'] ?? array());
            $post = isset($s['backup']['settings_post']) ? (array)$s['backup']['settings_post'] : null;
            foreach ($pre as $rel => $sha) {
                $now = Util::sha256(Util::abs($rel));
                if ($now === $sha || ($post !== null && $now !== ($post[$rel] ?? null))) {
                    continue;
                }
                $out[] = array('act' => 'copy', 'path' => (string)$rel, 'want' => (string)$sha, 'copy' => Backup::copyOf($s['id'], basename((string)$rel), 'settings-pre'),
                    'settings' => true);
            }
            foreach ((array)glob(Util::abs('application/extra/*.php')) as $f) {
                $rel = 'application/extra/' . basename((string)$f);
                if (!isset($pre[$rel]) && ($post === null || Util::sha256((string)$f) === ($post[$rel] ?? null)) && !in_array($rel, $plan['add'] ?? array(), true)) {
                    $out[] = array('act' => 'delete', 'path' => $rel, 'want' => '', 'copy' => '', 'settings' => true);
                }
            }
        }
        return $out;
    }

    /**
     * The items of attentionItems() that are not done yet ('copy': the file does not have the old bytes; 'delete': the
     * file is still there), checked until $deadline. Returns ['left' => [items], 'unchecked' => n (not checked in time)].
     */
    private static function attentionLeft(array $s, $of, $deadline)
    {
        $left = array();
        $unchecked = 0;
        foreach (self::attentionItems($s, $of) as $it) {
            if (microtime(true) >= $deadline) {
                $unchecked++;
                continue;
            }
            $abs = Util::abs($it['path']);
            $done = $it['act'] === 'copy' ? Util::sha256($abs) === $it['want'] : !is_file($abs);
            if (!$done) {
                $left[] = $it;
            }
        }
        return array('left' => $left, 'unchecked' => $unchecked);
    }

    /** True when an automatic rollback of run $s still has to restore database tables (or failed to). */
    private static function attentionDb(array $s, $of)
    {
        $source = $s['kind'] === 'rollback' ? Store::load($of) : $s;
        if ($source === null || empty($source['upgrade']['started']) || empty($source['backup']['db']['parts'])) {
            return array();
        }
        $to = Source::stored((string)$source['to']);
        if ($to === null || $to->upgrade()['compat'] !== 'breaking') {
            return array();
        }
        if ($s['kind'] === 'rollback' && empty($s['db_restore'])) {
            return array();
        }
        $order = self::ROLLBACK_PHASES;
        $at = array_search((string)$s['phase'], $order, true);
        $done = $at !== false && (int)$at > (int)array_search('r_db', $order, true) && (string)($s['rollback']['db_failed'] ?? '') === '';
        if ($done) {
            return array();
        }
        $pre = Db::prefix();
        $tables = array();
        foreach ((array)$to->upgrade()['alters'] as $t) {
            $tables[] = $pre . $t;
        }
        return $tables;
    }

    /**
     * Writes the hand procedure of a rollback that needs the founder to runtime/update/runs/<run>/attention.txt: plain text
     * for the hosting file manager, in the admin's language (English when it has none), one line per file. Returns
     * ['file' => site path of the guide, 'copy', 'delete', 'missing', 'settings' (counts), 'db' => the database backup to
     * restore from or '', 'db_tables'].
     */
    private static function attentionGuide(array $s, $of, $why, $k, array $v)
    {
        $items = self::attentionItems($s, $of);
        $u = $s['kind'] === 'rollback' ? Store::load($of) : $s;
        $tables = self::attentionDb($s, $of);
        $db = $tables ? (string)($u['backup']['db_location'] ?? '') : '';
        $L = Runner::strings(Runner::locale());
        $t = function ($key, array $vars, $en) use ($L) {
            $text = isset($L[$key]) && is_string($L[$key]) && $L[$key] !== '' ? $L[$key] : $en;
            foreach ($vars as $n => $x) {
                $text = str_replace('{' . $n . '}', (string)$x, $text);
            }
            return $text;
        };
        // a reason in the admin's language: its key, and the key of a reason inside it (why_k / why_v)
        $reason = function ($rk, array $rv, $m) use (&$reason, $L) {
            if ($rk === '' || !isset($L[$rk]) || !is_string($L[$rk])) {
                return (string)$m;
            }
            if (!empty($rv['why_k'])) {
                $rv['why'] = $reason((string)$rv['why_k'], (array)($rv['why_v'] ?? array()), (string)($rv['why'] ?? ''));
            }
            $text = $L[$rk];
            foreach ($rv as $n => $x) {
                if (is_scalar($x)) {
                    $text = str_replace('{' . $n . '}', (string)$x, $text);
                }
            }
            return $text;
        };
        $rel = function ($abs) {
            $root = Util::root();
            return strpos((string)$abs, $root) === 0 ? substr((string)$abs, strlen($root)) : (string)$abs;
        };
        // the folders the swap created (the rollback's r_dirs removes them when empty): listed while r_dirs has not run
        $order = self::ROLLBACK_PHASES;
        $at = array_search((string)$s['phase'], $order, true);
        $dirs = $at === false || (int)$at <= (int)array_search('r_dirs', $order, true) ? self::createdDirs($of) : array();
        $back = self::versionAfter($s);
        // a version without the maintenance gate (1.2.0: a rollback through the update bridge) shows visitors its pages as
        // soon as its entry scripts are back, flag or not: they are put back last, after every other step, so visitors never
        // get a half-restored site (ux-6 BRIDGE-GATE); a newer version's entry scripts carry the gate (step 1, as before)
        $gateless = self::beforeUpdater($back);
        $copy = $delete = $missing = $settings = $entries = array();
        foreach ($items as $it) {
            if (!empty($it['settings'])) {
                $settings[] = $it;
            } elseif ($it['act'] === 'copy' && $gateless && !empty($it['entry'])) {
                $entries[] = $it;
            } elseif ($it['act'] === 'copy' && $it['copy'] === '') {
                $missing[] = $it;
            } elseif ($it['act'] === 'copy') {
                $copy[] = $it;
            } else {
                $delete[] = $it;
            }
        }
        $lines = array($t('guide_title', array('run' => $s['id']), 'JavCMS: finish the rollback by hand (run ' . $s['id'] . ')'), '',
            $t('guide_intro', array('from' => $s['kind'] === 'rollback' ? $s['from'] : $s['to'], 'to' => $back, 'phase' => $t('ph_' . $s['phase'], array(), $s['phase']),
                'why' => $reason((string)$k, $v, $why)),
                'The rollback to JavCMS ' . $back . ' stopped at "' . $s['phase'] . '": ' . $why . '. Visitors get the maintenance page until runtime/update/maintenance.php is gone. Do the steps below in this order, with the file manager of your hosting or FTP.'), '');
        $step = 0;
        if ($copy) {
            $lines[] = (++$step) . '. ' . $t('guide_copy', array(), 'Put these files back: copy each file on the left over the file on the right (same path; .htaccess and web.config are kept in the backup as htaccess.jcupd.txt and web.config.jcupd.txt).');
            foreach ($copy as $it) {
                $lines[] = '   ' . $rel($it['copy']) . '  ->  ' . $it['path'];
            }
            $lines[] = '';
        }
        if ($missing) {
            $lines[] = (++$step) . '. ' . $t('guide_missing', array('v' => $back), 'These files have no copy in the backup: put back your own copy, or take the file from the zip of JavCMS ' . $back . ' (same path):');
            foreach ($missing as $it) {
                $lines[] = '   ' . $it['path'];
            }
            $lines[] = '';
        }
        if ($delete) {
            $lines[] = (++$step) . '. ' . $t('guide_delete', array(), 'Delete these files (the update added them):');
            foreach ($delete as $it) {
                $lines[] = '   ' . $it['path'];
            }
            $lines[] = '';
        }
        if ($dirs) {
            $lines[] = (++$step) . '. ' . $t('guide_dirs', array(), 'Then delete these folders if they are empty now (the update created them):');
            foreach ($dirs as $d) {
                $lines[] = '   ' . $d . '/';
            }
            $lines[] = '';
        }
        if ($settings) {
            $lines[] = (++$step) . '. ' . $t('guide_settings', array(), 'Settings the upgrade steps changed: copy each file on the left over the file on the right; delete the files listed alone (the upgrade created them).');
            foreach ($settings as $it) {
                $lines[] = '   ' . ($it['act'] === 'copy' ? ($it['copy'] !== '' ? $rel($it['copy']) . '  ->  ' : '?  ->  ') . $it['path'] : $it['path']);
            }
            $lines[] = '';
        }
        if ($s['kind'] === 'rollback') {
            $lines[] = (++$step) . '. ' . $t('guide_version', array('v' => $back), 'In application/extra/version.php, the version must be ' . $back . ": 'code' => '" . $back . "' (closing the run below writes it).");
            $lines[] = '';
        }
        if ($db !== '') {
            $lines[] = (++$step) . '. ' . $t('guide_db', array('list' => implode(', ', $tables), 'db' => $db),
                'The update changed the database: restore the tables ' . implode(', ', $tables) . ' from ' . $db . ' (Admins & backup > Backup & restore lists a whole-database backup; a partial backup has a README.txt).');
            $lines[] = '';
        }
        $entriesMissing = 0;
        if ($entries) {
            $lines[] = (++$step) . '. ' . $t('guide_copy_entries', array('v' => $back), 'Then put the entry scripts back: copy each file on the left over the file on the right (a "?" has no copy in the backup: take that file from the zip of JavCMS ' . $back . '). From this moment visitors get the site again: JavCMS ' . $back . ' has no maintenance page.');
            foreach ($entries as $it) {
                $entriesMissing += $it['copy'] === '' ? 1 : 0;
                $lines[] = '   ' . ($it['copy'] !== '' ? $rel($it['copy']) : '?') . '  ->  ' . $it['path'];
            }
            $lines[] = '';
        }
        if (!$step) {
            $lines[] = $t('guide_none', array(), 'No file is left to copy or delete.');
            $lines[] = '';
        }
        // the version left behind may predate the Updates page (a rollback to 1.2.0 after the bridge): its admin has no
        // Updates page and the steps above delete tools/update.php - such a run is closed on install.php, the update
        // bridge again (e2e-5 P3-3)
        // nothing closes a run by itself while the steps name database tables (autoCloseAttention): the founder confirms
        // them with "Close this run" (e2e-6 P3-3); with SSH the command line closes the run before the flag goes - it
        // deletes the flag itself and clears the web server's PHP cache through it (hosting-6 VH6-2)
        $lines[] = (++$step) . '. ' . ($gateless
            ? $t('guide_end_bridge', array(), 'Last: delete runtime/update/maintenance.php. Then open install.php of this site in the browser where you are signed in to the admin as the founder (it is the update bridge again): tick "I finished the rollback by hand" and press "Close this run" there.')
            : ($db !== ''
                ? $t('guide_end_db', array(), 'Last, once the tables are restored: delete runtime/update/maintenance.php, then open Admins & backup > Updates, tick "I finished the rollback by hand" and press "Close this run" there (the run does not close by itself while the steps name database tables). With SSH, run php tools/update.php --close-attention instead, before you delete the flag: it deletes the flag itself and clears the web server\'s PHP cache through it.')
                : $t('guide_end', array(), 'Last: delete runtime/update/maintenance.php, then open Admins & backup > Updates: when every file above is back it closes this run by itself; else tick "I finished the rollback by hand" and press "Close this run" there. With SSH, run php tools/update.php --close-attention instead, before you delete the flag: it deletes the flag itself and clears the web server\'s PHP cache through it.')));
        // the files put back by hand are new to PHP's cache: a host whose cache keeps the newer release's compiled copies
        // needs a restart - said by what the owner can see, not by a php.ini setting (hosting-5 VH5-3, ux-6 WORDING-6)
        $lines[] = '';
        $lines[] = $t('guide_restart', array(), 'If pages still behave like the newer version after these steps, restart PHP (or clear OPcache in the hosting panel).');
        $file = 'runtime/update/runs/' . $s['id'] . '/attention.txt';
        $dir = Store::runDir($s['id']);
        if ($dir !== '') {
            Util::writeAtomic($dir . 'attention.txt', implode("\n", $lines) . "\n", 0644);
        }
        return array('file' => $file, 'copy' => count($copy) + count($entries) - $entriesMissing, 'delete' => count($delete), 'missing' => count($missing) + $entriesMissing,
            'settings' => count($settings), 'dirs' => count($dirs), 'db' => $db, 'db_tables' => $tables);
    }

    /** True when version $v is older than 1.2.1, the first version with the Updates page (1.2.1-dev and the like count as 1.2.1). */
    private static function beforeUpdater($v)
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', (string)$v, $m)) {
            return false;
        }
        return version_compare($m[1] . '.' . $m[2] . '.' . $m[3], '1.2.1', '<');
    }

    /**
     * PHP's cache after a rollback the founder finished by hand (closeAttention): the files of the hand procedure are new
     * to it, and a web server whose OPcache never re-reads files would keep running the newer release's compiled copies
     * (hosting-5 VH5-3). From a web request this server invalidates them and resets its cache; the command line asks the
     * web server through the gate while this run's maintenance flag is still on (the gate answers only then). Returns
     * 'reset' | 'none' (no OPcache) | 'reread' (not reset, but the cache re-reads changed files by itself) | 'restart' (the
     * cache never re-reads files and could not be reset: PHP must restart) | 'unknown' (the web server could not be asked).
     */
    private static function closeCaches(array $s, $of)
    {
        $files = array();
        foreach (self::attentionItems($s, $of) as $it) {
            $files[] = Util::abs($it['path']);
        }
        if (PHP_SAPI !== 'cli') {
            if (!Util::opcacheActive()) {
                return 'none';
            }
            $n = Util::opcacheInvalidate($files);
            if (Util::call('opcache_reset', array(), false)) {
                return 'reset';
            }
            return Util::opcacheNeverRereads(ini_get('opcache.validate_timestamps')) && $n < count($files) ? 'restart' : 'reread';
        }
        if (!self::flagOfRun($s['id'])) {
            // the founder deleted the flag first: no gate answers any more. What this run learnt of the web server's cache
            // (its caches phase, the command line's r_entries) decides: no OPcache, one that re-reads changed files by
            // itself, one that never does (a restart); 'unknown' only without such facts (e2e-6 P3-4, hosting-6 VH6-2)
            $w = (array)($s['web_opcache'] ?? array());
            if (empty($w['ok'])) {
                return 'unknown';
            }
            if (empty($w['enabled'])) {
                return 'none';
            }
            return Util::opcacheNeverRereads($w['vt'] ?? null) ? 'restart' : 'reread';
        }
        $g = self::askGateReset($s, $files);
        if (empty($g['ok'])) {
            return 'unknown';
        }
        if (empty($g['enabled'])) {
            return 'none';
        }
        if (!empty($g['reset'])) {
            return 'reset';
        }
        return Util::opcacheNeverRereads($g['vt']) && (int)$g['invalidated'] < count($files) ? 'restart' : 'reread';
    }

    /**
     * The folders the swap of run $of created (its journal), deepest first, that still exist - the ones r_dirs removes when
     * they are empty (never a folder the site owns).
     */
    private static function createdDirs($of)
    {
        $journal = Store::loadPart($of, 'journal');
        $dirs = array_unique(array_map('strval', (array)($journal['dirs'] ?? array())));
        usort($dirs, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        $out = array();
        foreach ($dirs as $d) {
            if ($d !== '' && Paths::invalid($d) === '' && !Paths::siteOwned($d . '/') && is_dir(Util::abs($d))) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * "Close this run" (Runner action "close", tools/update.php --close-attention, or the Updates page by itself:
     * autoCloseAttention): the founder finished the rollback of run $id by hand. The maintenance flag goes, the version
     * number becomes the one the rollback went back to, the run ends "closed by the founder" with the update's own failure
     * as its reason, and the history keeps it. What the hand procedure still lists is said in the log and the result.
     * $how: 'founder' | 'auto'. Returns ['ok', 'left' => [paths], 'unchecked' => n, 'k', 'error', 'web_cache' (closeCaches)].
     */
    public static function closeAttention($id, $how = 'founder')
    {
        $lock = Store::lock();
        if ($lock === null) {
            return array('ok' => false, 'k' => 'busy', 'error' => 'another step is still working: try again in a moment', 'left' => array(), 'unchecked' => 0);
        }
        try {
            $s = Store::load($id);
            if ($s === null || $s['status'] !== 'attention') {
                return array('ok' => false, 'k' => 'close_not_now', 'error' => 'this run does not wait for the founder (any more)', 'left' => array(), 'unchecked' => 0);
            }
            $of = $s['kind'] === 'rollback' && $s['of'] !== '' ? $s['of'] : $s['id'];
            $chk = self::attentionLeft($s, $of, microtime(true) + 4.0);
            $left = array();
            foreach ($chk['left'] as $it) {
                $left[] = $it['path'];
            }
            $back = self::versionAfter($s);
            // the folders the update created and the founder emptied (what r_dirs does: only an empty folder goes)
            foreach (self::createdDirs($of) as $d) {
                @rmdir(Util::abs($d));
            }
            // before the flag goes: the command line reaches the web server's cache through the gate only while it is on
            $webCache = self::closeCaches($s, $of);
            if ($webCache === 'restart') {
                Store::log($s['id'], 'warn', 'PHP\'s cache on the web server never re-reads files and could not be reset: it may keep the newer release\'s copies of the files put back by hand until PHP restarts. Restart PHP (or clear OPcache in the hosting panel) now.',
                    $s['phase'], 'l_close_web_restart');
            }
            Store::maintenanceOffFor($s['id']);
            Store::dropGateFiles();
            $stamped = self::stampVersion($back);
            $s['status'] = 'closed';
            $s['ended'] = time();
            $s['rollback_until'] = 0;
            $s['maint'] = false;
            $s['inflight'] = null;
            $s['closed'] = array('how' => $how === 'auto' ? 'auto' : 'founder', 'at' => time(), 'left' => count($left), 'left_list' => array_slice($left, 0, 10),
                'unchecked' => (int)$chk['unchecked'], 'v' => $back, 'phase' => (string)($s['attention']['phase'] ?? $s['phase']), 'web_cache' => $webCache);
            // the counts without "1 files", and the files not checked in time only when there are some (ux-5 P3-3)
            Store::log($s['id'], $left || $chk['unchecked'] ? 'warn' : 'info', ($how === 'auto'
                ? 'The maintenance flag is gone and every file of the hand procedure is back: the run is closed (rollback finished by hand)'
                : 'The founder closed the run: the rollback was finished by hand') . ($stamped ? '; the version number is ' . $back . ' again' : '')
                . ($left ? '. Still not as before the update (' . count($left) . '): ' . implode(', ', array_slice($left, 0, 10)) : '')
                . ($chk['unchecked'] ? '. Files not checked in time: ' . (int)$chk['unchecked'] : ''), $s['phase'],
                $how === 'auto' ? 'l_attention_closed_auto' : ($chk['unchecked'] ? 'l_attention_closed_unchecked' : ($left ? 'l_attention_closed_left' : 'l_attention_closed')),
                array('v' => $back, 'n' => count($left), 'list' => implode(', ', array_slice($left, 0, 10)),
                    'unchecked' => (int)$chk['unchecked']));
            if ($s['kind'] === 'rollback') {
                $u = Store::load($of);
                if ($u !== null) {
                    $u['status'] = 'rolled_back';
                    $u['rollback_until'] = 0;
                    $u['rolled_back_by'] = $s['id'];
                    Store::save($u);
                    Store::recordHistory($u);
                }
            }
            Util::saveData(Util::dir() . 'installed.php', array('version' => $back, 'manifest' => Source::manifestName($back), 'run' => $s['id'], 'at' => time()));
            unset($s['attention']);
            // a manual "Roll back to X" has no failure of its own: its close names none (e2e-5 P3-2)
            $why = (string)$s['error'];
            $sfx = $why === '' ? '_rb' : '';
            $s['last_msg'] = $left
                ? self::msg('attention_closed_left' . $sfx, array('v' => $back, 'n' => count($left), 'list' => implode(', ', array_slice($left, 0, 10)), 'why' => $why,
                    'why_k' => (string)($s['error_k'] ?? ''), 'why_v' => (array)($s['error_v'] ?? array())),
                    'Run closed: the rollback was finished by hand and JavCMS ' . $back . ' is the installed version, but not every file is as before the update ('
                    . count($left) . ': ' . implode(', ', array_slice($left, 0, 10)) . '): check them.' . ($why !== '' ? ' The update had failed because: ' . $why : ''))
                : self::msg('attention_closed' . $sfx, array('v' => $back, 'why' => $why, 'why_k' => (string)($s['error_k'] ?? ''), 'why_v' => (array)($s['error_v'] ?? array())),
                    'Run closed: the rollback was finished by hand and JavCMS ' . $back . ' is the installed version.' . ($why !== '' ? ' The update had failed because: ' . $why : ''));
            Store::save($s);
            Store::recordHistory($s);
            Store::clearCurrent();
            Store::dropFrozen('');
            return array('ok' => true, 'left' => $left, 'unchecked' => (int)$chk['unchecked'], 'k' => '', 'error' => '', 'web_cache' => $webCache);
        } finally {
            Store::unlock($lock);
        }
    }

    /**
     * The Updates page (housekeeping) ends a rollback that waits for the founder by itself once the founder finished it by
     * hand: its maintenance flag is gone, every file of the hand procedure is back, and no database table has to be
     * restored (that the founder confirms with "Close this run"). Bounded to a few seconds. True when the run was closed.
     */
    public static function autoCloseAttention()
    {
        $cur = Store::current();
        $s = $cur !== null ? Store::load($cur['run']) : null;
        if ($s === null || $s['status'] !== 'attention' || self::flagOfRun($s['id'])) {
            return false;
        }
        $of = $s['kind'] === 'rollback' && $s['of'] !== '' ? $s['of'] : $s['id'];
        if (self::attentionDb($s, $of)) {
            return false;
        }
        $chk = self::attentionLeft($s, $of, microtime(true) + 3.0);
        if ($chk['left'] || $chk['unchecked']) {
            return false;
        }
        $r = self::closeAttention($s['id'], 'auto');
        return !empty($r['ok']);
    }

    /**
     * "Try the rollback again" for run $id (it needs attention): the rollback goes on from the step that failed, with a
     * fresh count of tries (RETRY_TRIES: the founder watches it). The caller checked who asks (founder password). True
     * when the run runs again.
     */
    public static function retryAttention($id)
    {
        $lock = Store::lock();
        if ($lock === null) {
            return false;
        }
        $s = Store::load($id);
        $ok = false;
        if ($s !== null && $s['status'] === 'attention') {
            $s['status'] = 'running';
            $s['attempts']['rollback'] = 0;
            $s['retried'] = (int)($s['retried'] ?? 0) + 1;
            $s['deaths'][$s['phase']] = 0;
            $s['inflight'] = null;
            $s['heartbeat'] = time();
            unset($s['attention']);
            Store::maintenanceOn($s, true);
            Store::log($id, 'info', 'The founder asked for the rollback to be tried again (from "' . $s['phase'] . '")', $s['phase'], 'l_attention_retry',
                array('phase' => $s['phase']));
            $s['last_msg'] = self::msg('attention_retry', array('phase' => $s['phase']), 'The rollback is tried again');
            Store::save($s);
            $ok = true;
        }
        Store::unlock($lock);
        return $ok;
    }

    // ------------------------------------------------------------------ rollback mode

    /**
     * The file work of a rollback: [action ('restore'|'remove'), release path, site path]. $entries false: every planned
     * file but the entry scripts, in the reverse order of the swap; true: only the entry scripts, index.php last.
     */
    private static function rollbackWork(array $plan, $entries)
    {
        $work = array();
        foreach (array_merge($plan['replace'] ?? array(), $plan['delete'] ?? array()) as $p) {
            $work[] = array('restore', $p, $plan['targets'][$p] ?? $p);
        }
        foreach ($plan['add'] ?? array() as $p) {
            $work[] = array('remove', $p, $plan['targets'][$p] ?? $p);
        }
        $out = array();
        foreach ($work as $i => $w) {
            if (Paths::isEntry($w[1]) === (bool)$entries) {
                $rank = $entries ? array_search(Paths::key($w[1]), self::ENTRY_RESTORE_ORDER, true) : -Paths::swapRank($w[1]);
                $out[] = array($rank === false ? 99 : (int)$rank, $i, $w);
            }
        }
        usort($out, function ($a, $b) {
            return $a[0] === $b[0] ? $a[1] - $b[1] : $a[0] - $b[0];
        });
        return array_map(function ($x) {
            return $x[2];
        }, $out);
    }

    /**
     * Puts one file of a rollback back; '' when done, else the problem. A backed-up file is moved back into place by a
     * rename when it can (same volume: no free space needed, so a full disk does not keep the site in maintenance), else
     * copied (temp file + rename).
     */
    private static function rollbackOne(array $s, $of, array $plan, array $index, array $w)
    {
        list($act, $p, $t) = $w;
        $now = Util::sha256(Util::abs($t));
        if ($act === 'restore') {
            $want = $index[$t] ?? null;
            if ($want === null) {
                // there was no such file before the update
                if ($now !== null) {
                    $r = Swapper::remove($t, true);
                    return $r['ok'] ? '' : (string)$r['error'];
                }
                return '';
            }
            if ($now === $want) {
                return '';
            }
            $copy = Backup::copyOf($of, $t);
            if ($copy === '') {
                // keyed: the founder's page shows the reason in the admin's language (ux-4 P3-2)
                return array('m' => 'no backup copy of ' . $t, 'k' => 'e_no_backup_copy', 'v' => array('file' => $t));
            }
            if (Util::sha256($copy) === $want && Swapper::moveBack($copy, $t)) {
                return '';
            }
            $r = Swapper::put($copy, $t, $want, true);
            return $r['ok'] ? '' : array('m' => (string)$r['error'], 'k' => (string)($r['k'] ?? ''), 'v' => (array)($r['v'] ?? array()));
        }
        // added by the update: removed while it still has the release's bytes (a seed the update created stays when the
        // site edited it since)
        $rel = $plan['expect'][$p] ?? null;
        if ($now !== null && ($rel === null || $now === $rel || !$s['manual'])) {
            $r = Swapper::remove($t, true);
            return $r['ok'] ? '' : array('m' => (string)$r['error'], 'k' => (string)($r['k'] ?? ''), 'v' => (array)($r['v'] ?? array()));
        }
        return '';
    }

    /** One problem of rollbackOne(): an array ['m', 'k', 'v'] (older callers: an English string). */
    private static function troubleOf($p)
    {
        if (is_array($p)) {
            return array(Util::cut(Util::redact((string)($p['m'] ?? '')), 400), (string)($p['k'] ?? ''), (array)($p['v'] ?? array()));
        }
        return array((string)$p, '', array());
    }

    private static function rollbackStep(array &$s, $deadline)
    {
        $of = $s['kind'] === 'rollback' && $s['of'] !== '' ? $s['of'] : $s['id'];
        $plan = Store::loadPart($of, 'plan');
        $index = Store::loadPart($of, 'backup');
        if (!$plan && !in_array($s['phase'], array('r_maint_off', 'r_finish'), true)) {
            // nothing was planned, so nothing was changed
            self::next($s, 'r_maint_off');
        }
        switch ($s['phase']) {
            case 'r_maint_on':
                if (!Store::maintenanceOn($s, true)) {
                    self::rollbackTrouble($s, 'cannot turn the maintenance page on', 'e_maint');
                    return;
                }
                $s['maint'] = true;
                if (PHP_SAPI === 'cli' && self::gatePing($s)['state'] !== 'gate') {
                    // the command line cannot see the maintenance page itself: the web server did not answer through the
                    // gate (it runs cached entry scripts without it, or cannot be reached), so the log does not claim that
                    // visitors get it (hosting-4 VH4-1)
                    self::say($s, 'r_maint_on_unseen', array(), 'Maintenance flag written for the rollback; the web server did not answer through the maintenance gate (it may serve its cached pages until PHP re-reads the files)', 'warn');
                } else {
                    self::say($s, 'r_maint_on', array(), 'Maintenance page on for visitors (rollback)');
                }
                self::next($s, 'r_files');
                return;

            case 'r_files':
                // every planned file back to its backed-up bytes, files the update added (none before) removed; in the
                // reverse order of the swap, without the entry scripts (r_entries)
                $work = self::rollbackWork($plan, false);
                $i = (int)$s['cursor'];
                $n = count($work);
                $problems = array();
                while ($i < $n) {
                    $r = self::rollbackOne($s, $of, $plan, $index, $work[$i]);
                    if ($r !== '') {
                        $problems[] = $r;
                        break;
                    }
                    $i++;
                    if (microtime(true) >= $deadline) {
                        break;
                    }
                }
                $s['cursor'] = $i;
                $s['progress'] = array('done' => $i, 'total' => $n);
                if ($problems) {
                    list($pm, $pk, $pv) = self::troubleOf($problems[0]);
                    self::rollbackTrouble($s, $pm, 'e_rollback_file', array('why' => $pm, 'why_k' => $pk, 'why_v' => $pv));
                    return;
                }
                if ($i < $n) {
                    self::say($s, 'r_files', array('done' => $i, 'total' => $n), 'Restoring files: ' . $i . ' of ' . $n);
                    return;
                }
                self::say($s, 'r_files_done', array('n' => $n), 'Restored ' . $n . ' files from the backup');
                self::next($s, 'r_dirs');
                return;

            case 'r_dirs':
                $journal = Store::loadPart($of, 'journal');
                $dirs = array_unique((array)($journal['dirs'] ?? array()));
                usort($dirs, function ($a, $b) {
                    return strlen($b) - strlen($a);
                });
                foreach ($dirs as $d) {
                    if (Paths::invalid($d) === '' && !Paths::siteOwned($d . '/')) {
                        @rmdir(Util::abs($d));
                    }
                }
                self::next($s, 'r_db');
                return;

            case 'r_db':
                self::rollbackDb($s, $of, $deadline);
                return;

            case 'r_settings':
                self::rollbackSettings($s, $of, $plan);
                self::next($s, 'r_caches');
                return;

            case 'r_caches':
                // temp leftovers next to the restored files (a slice that died while it moved a file back) go first
                if (empty($s['sub']['swept_done'])) {
                    $sub = (array)$s['sub'];
                    $swept = self::sweepPlanFolders($plan, $sub, $deadline);
                    $s['sub'] = $sub;
                    if ($swept === null) {
                        return;
                    }
                    if ($swept) {
                        Store::log($s['id'], 'info', 'Removed ' . count($swept) . ' leftover temporary files: ' . implode(', ', array_slice($swept, 0, 10)), 'r_caches',
                            'l_temps_removed', array('n' => count($swept), 'list' => implode(', ', array_slice($swept, 0, 10))));
                    }
                    $s['sub']['swept_done'] = 1;
                }
                $files = array();
                foreach (array_merge($plan['replace'] ?? array(), $plan['delete'] ?? array(), $plan['add'] ?? array()) as $p) {
                    $files[] = Util::abs($plan['targets'][$p] ?? $p);
                }
                foreach ((array)glob(Util::abs('application/extra/*.php')) as $f) {
                    $files[] = (string)$f;
                }
                if (empty($s['sub']['caches_at'])) {
                    $c = self::clearCachesStep($s, $files, $deadline);
                    if ($c === null) {
                        self::say($s, 'caches_more', array('done' => (int)($s['sub']['ci'] ?? 0), 'total' => count($files)),
                            'Clearing caches: ' . (int)($s['sub']['ci'] ?? 0) . ' of ' . count($files) . ' files');
                        return;
                    }
                    Store::maintenanceOn($s, false);
                    self::say($s, 'caches', $c, 'Caches cleared (OPcache: ' . $c['opcache'] . ', compiled templates: ' . $c['templates'] . ', page cache: ' . $c['edge'] . ')');
                    $s['sub']['caches_at'] = time();
                    $s['sub']['refused'] = !empty($c['refused']);
                }
                // a host that refused the reset sees the restored files only when its OPcache re-reads them: maintenance
                // stays on until then (the same rule as before the upgrade steps), so neither the health check below nor
                // a visitor meets the code the rollback just removed
                $left = self::cacheWaitLeft($s, (int)$s['sub']['caches_at'], !empty($s['sub']['refused']));
                if ($left > 0) {
                    $s['wait'] = 1000 * min($left, 10);
                    self::say($s, 'r_caches_wait', array('s' => $left), 'Waiting ' . $left . ' s until PHP\'s cache sees the restored files (this host does not let the update reset it)');
                    return;
                }
                self::next($s, 'r_health');
                return;

            case 'r_health':
                if (!empty($s['rescue'])) {
                    // visitors' requests drive this rollback: nobody reads a report, and a server with one PHP worker
                    // would make this visitor wait for its own probes
                    Store::log($s['id'], 'warn', 'Health check after the rollback skipped (the rollback ran from visitors\' requests): open the site to check it', 'r_health',
                        'l_rb_health_skip');
                    $s['skipped'][] = 'r_health';
                    self::next($s, 'r_entries');
                    return;
                }
                $h = self::healthResult($s, $deadline);
                if ($h === null) {
                    return;
                }
                $s['health']['rollback'] = $h;
                if (!$h['ok']) {
                    list($why, $checks) = Health::reasons($h);
                    Store::log($s['id'], 'warn', 'Health check after the rollback reports problems: ' . $why, 'r_health', 'l_rb_health_warn',
                        array('why' => $why, 'checks' => $checks));
                    $s['rollback']['health_warning'] = $why;
                    $s['rollback']['health_checks'] = $checks;
                } else {
                    self::say($s, 'health_ok', array(), 'Health check passed: home, list, detail and admin pages answer without errors');
                }
                self::next($s, 'r_entries');
                return;

            case 'r_entries':
                // the entry scripts last, all in this request, then maintenance off and the end of the run (chain)
                $problems = array();
                $restored = array();
                foreach (self::rollbackWork($plan, true) as $w) {
                    $r = self::rollbackOne($s, $of, $plan, $index, $w);
                    if ($r !== '') {
                        $problems[] = $r;
                        break;
                    }
                    $restored[] = Util::abs($w[2]);
                }
                Util::opcacheInvalidate($restored);
                if ($problems) {
                    list($pm, $pk, $pv) = self::troubleOf($problems[0]);
                    self::rollbackTrouble($s, $pm, 'e_rollback_file', array('why' => $pm, 'why_k' => $pk, 'why_v' => $pv));
                    return;
                }
                Util::sweepTemps(Util::root(), array('index.php', 'api.php', 'install.php', 'javcms.php', (string)($plan['admin_entry'] ?? 'javcms.php')), 120);
                if ($restored && empty($s['sub']['entries_logged'])) {
                    Store::log($s['id'], 'info', 'Main entry files restored: ' . implode(', ', array_map('basename', $restored)), 'r_entries', 'l_entries',
                        array('list' => implode(', ', array_map('basename', $restored))));
                    $s['sub']['entries_logged'] = 1;
                }
                if (PHP_SAPI === 'cli' && $restored && !self::cliEntriesCache($s, $restored)) {
                    return; // waiting for the web server's cache (maintenance stays on); the next step goes on from here
                }
                $s['chain'] = true;
                self::next($s, 'r_maint_off');
                return;

            case 'r_maint_off':
                Store::maintenanceOff();
                $s['maint'] = false;
                self::next($s, 'r_finish');
                return;

            case 'r_finish':
                $s['ended'] = time();
                $dbFailed = (string)($s['rollback']['db_failed'] ?? '');
                $s['status'] = $dbFailed !== '' ? 'rollback_failed' : 'rolled_back';
                $healthWarn = (string)($s['rollback']['health_warning'] ?? '');
                // never more than the rest of this step: the entry scripts are back, an older one may not forward another
                Util::removeTreeTimed(self::runPath($s['id'], 'staging'), $deadline);
                self::dropGateFiles();
                if ($s['kind'] === 'rollback') {
                    $u = Store::load($of);
                    // the message below sends the founder to the update's database set: housekeeping keeps that run's backup
                    // (files and set) KEEP_DAYS from now, whatever the update's own age - a rollback on day 7 would otherwise
                    // leave an hour or less (Service::housekeep honours keep_until; security-6 VS6-1)
                    $keep = time() + Config::KEEP_DAYS * 86400;
                    if ($u !== null) {
                        $u['status'] = 'rolled_back';
                        $u['rollback_until'] = 0;
                        $u['rolled_back_by'] = $s['id'];
                        if ($dbFailed !== '') {
                            $u['keep_until'] = max((int)($u['keep_until'] ?? 0), $keep);
                        }
                        Store::save($u);
                        Store::recordHistory($u);
                    }
                    Util::saveData(Util::dir() . 'installed.php', array('version' => $s['to'], 'manifest' => Source::manifestName($s['to']), 'run' => $s['id'], 'at' => time()));
                    if ($dbFailed !== '') {
                        $until = Util::siteDate('Y-m-d H:i', $keep);
                        self::say($s, 'rolled_back_db_failed_kept', array('v' => $s['to'], 'why' => $dbFailed, 'location' => (string)($u['backup']['db_location'] ?? ''), 'until' => $until),
                            'Rolled back to JavCMS ' . $s['to'] . ', but the database tables could not be restored (' . $dbFailed . '): restore them from ' . (string)($u['backup']['db_location'] ?? 'the backup')
                            . ' (the backup is kept until ' . $until . ')', 'error');
                    } elseif ($healthWarn !== '') {
                        self::say($s, 'rolled_back_manual_warn', array('v' => $s['to'], 'why' => $healthWarn, 'checks' => (array)($s['rollback']['health_checks'] ?? array())),
                            'Rolled back: the files of JavCMS ' . $s['to'] . ' are back, but the health check after the rollback still reports problems (' . $healthWarn . '): open the site and check it.', 'warn');
                    } elseif (!empty($s['retried'])) {
                        self::say($s, 'rolled_back_manual_retry', array('v' => $s['to']), 'Rolled back after the founder\'s retry: JavCMS ' . $s['to'] . ' is installed again.');
                    } else {
                        self::say($s, 'rolled_back_manual', array('v' => $s['to']), 'Rolled back: JavCMS ' . $s['to'] . ' is installed again.');
                    }
                } elseif ($dbFailed !== '') {
                    // the run's own backup stays KEEP_DAYS from its end (housekeep): the message names the date
                    $until = Util::siteDate('Y-m-d H:i', (int)$s['ended'] + Config::KEEP_DAYS * 86400);
                    self::say($s, 'rolled_back_db_failed_kept', array('v' => $s['from'], 'why' => $dbFailed, 'location' => (string)($s['backup']['db_location'] ?? ''), 'until' => $until),
                        'The update failed; the files of JavCMS ' . $s['from'] . ' are back, but the database tables could not be restored (' . $dbFailed . '): restore them from '
                        . (string)($s['backup']['db_location'] ?? 'the backup') . ' (the backup is kept until ' . $until . ')', 'error');
                } elseif ($healthWarn !== '') {
                    // the files are back, but the pages still do not answer cleanly: never "works as before"
                    self::say($s, 'rolled_back_warn', array('v' => $s['from'], 'why' => $s['error'], 'why_k' => (string)($s['error_k'] ?? ''), 'why_v' => (array)($s['error_v'] ?? array()),
                        'health' => $healthWarn, 'health_checks' => (array)($s['rollback']['health_checks'] ?? array())),
                        'The update failed and was rolled back automatically: the files of JavCMS ' . $s['from'] . ' are back, but the health check after the rollback still reports problems ('
                        . $healthWarn . '): open the site and check it. Reason of the rollback: ' . $s['error'], 'warn');
                } elseif (!empty($s['retried'])) {
                    // the rollback needed the founder's "Try the rollback again": said as such, with the update's own reason
                    // (needsAttention never replaces it: e2e-4 P3-3, ux-4 P3-3, hosting-4 VH4-5)
                    self::say($s, 'rolled_back_retry', array('v' => $s['from'], 'why' => $s['error'], 'why_k' => (string)($s['error_k'] ?? ''), 'why_v' => (array)($s['error_v'] ?? array())),
                        'The update failed; its rollback needed a retry by the founder and has now finished: JavCMS ' . $s['from'] . ' works as before. The update failed because: ' . $s['error']);
                } else {
                    self::say($s, 'rolled_back', array('v' => $s['from'], 'why' => $s['error'], 'why_k' => (string)($s['error_k'] ?? ''), 'why_v' => (array)($s['error_v'] ?? array())),
                        'The update failed and was rolled back automatically: JavCMS ' . $s['from'] . ' works as before. Reason: ' . $s['error']);
                }
                Store::recordHistory($s);
                Store::clearCurrent();
                Store::dropFrozen('');
                return;
        }
        throw new \RuntimeException('unknown phase ' . $s['phase']);
    }

    /**
     * The command line puts the entry scripts back last (r_entries): the web server keeps its compiled copies of the ones
     * the rollback just replaced (the health check and visitors made it compile them), and only it can drop them. The gate
     * (still on) takes the list of restored files and resets the web server's OPcache. When the web server may not reset
     * it and re-reads files only every few seconds, maintenance stays on until it has (the step returns false and the
     * next one comes back here); a web server whose cache never re-reads files and refuses the reset keeps the newer entry
     * scripts until PHP restarts: said in the log and by the command line. True when the rollback may go on.
     */
    private static function cliEntriesCache(array &$s, array $restored)
    {
        if (empty($s['sub']['web_cache'])) {
            $g = self::askGateReset($s, $restored);
            if (!empty($g['ok'])) {
                $s['web_opcache'] = $g;
                $state = !empty($g['reset']) || empty($g['enabled']) ? 'reset' : 'refused';
            } else {
                // no gate answered: the web server already runs the restored entry script (an older one without the gate:
                // the site answers without it), or it cannot be reached from here
                $state = self::homeAnswers($s) ? 'gateless' : 'unreachable';
            }
            $s['sub']['web_cache'] = $state;
            $s['sub']['web_cache_at'] = time();
            $s['rollback']['web_cache'] = $state;
            if ($state === 'refused' || $state === 'unreachable') {
                // php.ini's "Off" reads back as "": Util::opcacheNeverRereads knows every spelling
                $never = $state === 'refused' && Util::opcacheNeverRereads($g['vt'] ?? null);
                Store::log($s['id'], 'warn', $state === 'refused'
                    ? ($never ? 'The web server may not reset its PHP cache and never re-reads files: it keeps the newer entry scripts until PHP restarts. Restart PHP (or clear OPcache in the hosting panel) now.'
                        : 'The web server may not reset its PHP cache: the rollback waits until it re-reads the restored entry scripts')
                    : 'The web server could not be asked to reset its PHP cache: if pages still show the newer version, restart PHP (or clear OPcache in the hosting panel)',
                    'r_entries', $state === 'refused' ? ($never ? 'l_rb_web_never' : 'l_rb_web_wait') : 'l_rb_web_unreachable');
                if ($never) {
                    $s['rollback']['web_cache'] = 'restart';
                }
            }
        }
        if ($s['sub']['web_cache'] === 'refused') {
            $left = self::cacheWaitLeft($s, (int)$s['sub']['web_cache_at'], true);
            if ($left > 0) {
                $s['wait'] = 1000 * min($left, 10);
                self::say($s, 'r_caches_wait', array('s' => $left), 'Waiting ' . $left . ' s until PHP\'s cache sees the restored files (this host does not let the update reset it)');
                return false;
            }
        }
        return true;
    }

    /**
     * r_db: the tables a breaking release changed come back from the update's database backup (shadow copy + RENAME, see
     * Backup::dbRestoreStep). A step that died restarts the table it was loading. A restore that keeps failing (or a
     * backup that cannot be restored) does not hold the site in maintenance for ever: after DB_RESTORE_TRIES attempts the
     * rollback goes on with the files and ends "needs attention", naming the backup to restore by hand.
     */
    private static function rollbackDb(array &$s, $of, $deadline)
    {
        $u = Store::load($of);
        $source = $s['kind'] === 'rollback' ? $u : $s;
        $to = $source !== null ? Source::stored($source['to']) : null;
        $breaking = $to !== null && $to->upgrade()['compat'] === 'breaking';
        $ran = $source !== null && !empty($source['upgrade']['started']);
        $want = $s['kind'] === 'rollback' ? !empty($s['db_restore']) : true;
        if (!$breaking || !$ran || !$want || empty($source['backup']['db']['parts'])) {
            if ($breaking && $ran && $s['kind'] === 'rollback' && !$want) {
                $gone = Service::missingDbParts($source, $to);
                if ($gone) {
                    // the restore was never offered: the backup of those tables is gone (ux-4 P3-12)
                    Store::log($s['id'], 'warn', 'The database keeps the changes of ' . $source['to'] . ': the backup of ' . implode(', ', $gone) . ' is gone (deleted in Backup & restore?), so those tables could not be restored',
                        'r_db', 'l_rb_db_missing', array('v' => $source['to'], 'list' => implode(', ', $gone)));
                } else {
                    Store::log($s['id'], 'warn', 'The database keeps the changes of ' . $source['to'] . ' (restore not chosen)', 'r_db', 'l_rb_db_keep', array('v' => $source['to']));
                }
            }
            $s['skipped'][] = 'r_db';
            self::next($s, 'r_settings');
            return;
        }
        if (!isset($s['sub']['job'])) {
            $pre = Db::prefix();
            $job = $source['backup']['db'];
            $job['restore'] = array();
            // a table that shares its part is read from a copy of its own byte range, kept in this run's folder
            $job['tmp'] = 'runtime/update/runs/' . $s['id'] . '/restore/';
            foreach ($to->upgrade()['alters'] as $t) {
                if (isset($job['parts'][$pre . $t]) && !empty($job['tables'][$pre . $t]['data'])) {
                    $job['restore'][] = $pre . $t;
                }
            }
            $s['sub']['job'] = $job;
            $s['sub']['fails'] = 0;
            Store::log($s['id'], 'info', 'Restoring database tables changed by ' . $source['to'] . ': ' . implode(', ', $job['restore']), 'r_db', 'l_rb_db_tables',
                array('v' => $source['to'], 'list' => implode(', ', $job['restore'])));
        }
        $job = $s['sub']['job'];
        $restart = !empty($s['sub']['died']);
        unset($s['sub']['died']);
        if ($restart && ($job['r_stage'] ?? 'load') === 'load' && (int)($job['r_off'] ?? 0) > 0) {
            $tn = (string)($job['restore'][(int)($job['r_i'] ?? 0)] ?? '');
            Store::log($s['id'], 'warn', 'The restore of ' . $tn . ' starts again (the previous step stopped before it saved its progress)', 'r_db',
                'l_rb_db_restart', array('table' => $tn));
        }
        $r = Backup::dbRestoreStep($job, $deadline, $restart);
        $s['sub']['job'] = $job;
        $total = count((array)$job['restore']);
        $s['progress'] = array('done' => (int)($job['r_i'] ?? 0), 'total' => $total);
        if ($r['state'] === 'error') {
            $s['sub']['fails'] = (int)($s['sub']['fails'] ?? 0) + 1;
            if (!empty($r['fatal']) || $s['sub']['fails'] >= self::DB_RESTORE_TRIES) {
                $s['rollback']['db_failed'] = Util::cut((string)$r['error'], 300);
                Store::log($s['id'], 'error', 'The database restore failed ' . $s['sub']['fails'] . ' times (' . $r['error'] . '); the rollback goes on without it',
                    'r_db', 'l_rb_db_gaveup', array('n' => $s['sub']['fails'], 'why' => (string)$r['error']));
                self::next($s, 'r_settings');
                return;
            }
            self::rollbackTrouble($s, $r['error'], (string)($r['k'] ?? ''), (array)($r['v'] ?? array()));
            return;
        }
        if ($r['state'] === 'more') {
            $tn = (string)($job['restore'][(int)($job['r_i'] ?? 0)] ?? '');
            if (!empty($r['busy']) && !empty($r['lock'])) {
                // another database connection still uses the table (the last statement of a step that was cut off):
                // wait for it, visitors keep the maintenance page, the log says why once a minute
                if (time() - (int)($s['sub']['busy_logged'] ?? 0) >= 60) {
                    Store::log($s['id'], 'warn', 'The database table ' . $tn . ' is still used by another connection (a step that was cut off is finishing its last statement). The restore goes on as soon as it lets go.',
                        'r_db', 'l_rb_db_locked', array('table' => $tn));
                    $s['sub']['busy_logged'] = time();
                }
                $s['wait'] = 3000;
                self::say($s, 'r_db_locked', array('table' => $tn), 'Waiting for the table ' . $tn . ': another database connection still uses it');
                return;
            }
            if (!empty($r['busy'])) {
                // the restored data is complete in the shadow table; another program holds the table file (Windows):
                // wait for it, visitors keep the maintenance page, the log says why once a minute
                if (time() - (int)($s['sub']['busy_logged'] ?? 0) >= 60) {
                    Store::log($s['id'], 'warn', 'The database cannot rename the restored table ' . $tn . ' yet: another program (a backup tool or an antivirus scanner?) holds its file. The restore finishes as soon as it lets go.',
                        'r_db', 'l_rb_db_busy', array('table' => $tn));
                    $s['sub']['busy_logged'] = time();
                }
                $s['wait'] = 5000;
                self::say($s, 'r_db_busy', array('table' => $tn), 'Waiting for the file of ' . $tn . ' (in use by another program)');
                return;
            }
            $s['wait'] = 0;
            self::say($s, 'r_db', array('table' => $tn, 'done' => (int)($job['r_i'] ?? 0) + 1, 'total' => $total), 'Restoring database tables: ' . $tn);
            return;
        }
        if (!empty($job['drop_left'])) {
            // the data is back; only the replaced copies wait for the clean-up (the database server held them a while)
            Store::log($s['id'], 'info', 'The replaced copies ' . implode(', ', (array)$job['drop_left']) . ' are dropped by the next clean-up (the database server was still busy with them)',
                'r_db', 'l_rb_db_drop_later', array('list' => implode(', ', (array)$job['drop_left'])));
        }
        self::say($s, 'r_db_done', array(), 'Database tables restored from the backup');
        self::next($s, 'r_settings');
    }

    /**
     * r_settings. A manual rollback days later keeps the owner's settings (only the version number goes back). The
     * automatic rollback restores only what the update itself changed: settings files are written by the new release's
     * upgrade steps only, so when they never ran nothing comes back; when they ran, a file is restored from its copy of
     * right before them when it still has what the upgrade left (or the upgrade did not finish), and kept when an admin
     * saved it after the upgrade. Settings files the swap created (none before) go away again.
     */
    private static function rollbackSettings(array &$s, $of, array $plan)
    {
        if ($s['kind'] === 'rollback') {
            self::stampVersion($s['to']);
            $s['skipped'][] = 'r_settings';
            return;
        }
        $before = (array)($s['backup']['settings'] ?? array());
        $restored = 0;
        if (!empty($s['upgrade']['started'])) {
            $pre = (array)($s['backup']['settings_pre'] ?? array());
            $post = isset($s['backup']['settings_post']) ? (array)$s['backup']['settings_post'] : null;
            foreach ($pre as $rel => $sha) {
                $now = Util::sha256(Util::abs($rel));
                if ($now === $sha) {
                    continue;
                }
                if ($post !== null && $now !== ($post[$rel] ?? null)) {
                    Store::log($s['id'], 'warn', 'Kept your newer settings in ' . $rel . ' (saved after the upgrade steps)', 'r_settings', 'l_settings_kept',
                        array('file' => $rel));
                    continue;
                }
                $copy = Backup::copyOf($s['id'], basename($rel), 'settings-pre');
                if ($copy !== '' && Swapper::put($copy, $rel, $sha, true)['ok']) {
                    $restored++;
                }
            }
            // a settings file the upgrade steps created goes again (unless an admin changed it since)
            foreach ((array)glob(Util::abs('application/extra/*.php')) as $f) {
                $rel = 'application/extra/' . basename((string)$f);
                if (!isset($pre[$rel]) && ($post === null || Util::sha256((string)$f) === ($post[$rel] ?? null))
                    && !in_array($rel, $plan['add'] ?? array(), true)) {
                    @unlink((string)$f);
                }
            }
        } else {
            $s['skipped'][] = 'r_settings';
        }
        // a settings file the run created (none before) goes away again
        foreach ((array)glob(Util::abs('application/extra/*.php')) as $f) {
            $rel = 'application/extra/' . basename((string)$f);
            if (!isset($before[$rel]) && in_array($rel, $plan['add'] ?? array(), true)) {
                @unlink((string)$f);
            }
        }
        if ($restored) {
            Store::log($s['id'], 'info', 'Restored ' . $restored . ' settings files the upgrade steps had changed', 'r_settings', 'l_settings_restored',
                array('n' => $restored));
        }
    }

    /**
     * A run whose page was closed while visitors saw the maintenance page: called by the gate (visitor requests) after
     * Config::STALL_SECONDS without a step. Rolls the run back in short slices (or finishes the turning-off when the
     * update had already passed its health check).
     */
    public static function rescue($id)
    {
        $s = Store::load($id);
        if ($s !== null && $s['status'] === 'attention') {
            // a rollback that waits for the founder: visitors keep the maintenance page, nothing is driven from here
            return;
        }
        if ($s === null || $s['status'] !== 'running') {
            Store::maintenanceOff();
            Store::clearCurrent();
            return;
        }
        if (!empty($s['wait']) && time() < (int)$s['heartbeat'] + (int)ceil((int)$s['wait'] / 1000)) {
            // the run's own pause before its next step (a rollback step that failed waits longer after each try; a cache
            // that re-reads files every few seconds): visitors' requests honour it as a page or the command line does, so
            // the tries of a rollback span minutes, not seconds (hosting-4 VH4-6)
            return;
        }
        if ($s['mode'] === 'update' && in_array($s['phase'], array('maint_off', 'finish'), true)) {
            self::step($id, 4.0, true);
            return;
        }
        if ($s['mode'] === 'update') {
            $lock = Store::lock();
            if ($lock === null) {
                return;
            }
            $s = Store::load($id);
            if ($s !== null && $s['status'] === 'running' && $s['mode'] === 'update') {
                $stall = Config::stallSeconds();
                $time = $stall < 120 ? $stall . ' seconds' : (int)round($stall / 60) . ' minutes';
                // the reason in seconds for short limits (tests, impatient hosts), else in minutes; both keys are translated
                self::toRollback($s, 'nobody continued the update for ' . $time . ' (page closed?)', $stall < 120 ? 'e_stall_s' : 'e_stall_m',
                    $stall < 120 ? array('s' => $stall) : array('min' => (int)round($stall / 60)));
                Store::save($s);
            }
            Store::unlock($lock);
        }
        self::step($id, 4.0, true);
    }
}
