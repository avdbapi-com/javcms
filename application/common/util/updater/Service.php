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
 * What the Updates page, the command line (tools/update.php) and the bridge file ask the updater: status, check,
 * requirements, the plan before an update, arming an update or a rollback, cancelling, clean-up of old runs.
 * Callers check who is asking (founder admin + password); this class only does the work.
 *
 * Every refusal carries a message key ('k') and its variables ('v') next to the English text ('error'): the pages show
 * it in the admin's language, the command line prints the English text.
 */
final class Service
{
    /** A refusal: English text, message key of the page strings, variables. */
    private static function refuse($k, $m, array $v = array())
    {
        return array('ok' => false, 'state' => 'error', 'error' => (string)$m, 'k' => (string)$k, 'v' => $v);
    }

    /**
     * runtime/update/ cannot be used (a link, no permission, a file system without file locks): the refusal every action
     * gives instead of a result it could not keep (a check that cannot store what it found, a Review that then knows no
     * release, a check that would always look "busy"). null when it can.
     */
    public static function runtimeProblem()
    {
        $p = Util::dirProblem();
        if ($p === 'link') {
            return self::refuse('req_runtime_link', 'runtime/ (or runtime/update/) is a link to another folder: the updater never writes through a link. runtime/ and runtime/update/ must be real folders of the site: replace the link with a folder.');
        }
        if ($p !== '') {
            return self::refuse('req_runtime_no', 'runtime/update/ cannot be written.');
        }
        if (!Store::locksWork()) {
            return self::refuse('req_locks', 'runtime/ is on a file system without file locks (some network or FUSE mounts): steps could run twice at once. Keep runtime/ on a local disk.');
        }
        return null;
    }
    // ------------------------------------------------------------------ status

    /** Everything the Updates page shows (no network). */
    public static function status()
    {
        $installed = Config::installedVersion();
        $check = Source::cached();
        $cur = Store::current();
        $run = $cur !== null ? Store::load($cur['run']) : null;
        $history = Store::history();
        $rollback = self::rollbackOffer();
        return array(
            'installed' => $installed, 'installed_valid' => Util::semverValid($installed),
            'settings' => Config::settings(), 'check' => self::presentCheck($check, $installed),
            'run' => $run !== null ? Engine::result($run) : null, 'history' => $history, 'rollback' => $rollback,
            'test' => Config::test() !== null, 'engine' => Signature::engine(), 'keys' => count(Signature::keyring()),
            'php' => PHP_VERSION, 'inbox' => is_dir(Util::root() . 'runtime/update/inbox'),
        );
    }

    private static function presentCheck(array $c, $installed)
    {
        $c += array('state' => 'never', 'latest' => null, 'checked_at' => 0, 'notes' => '', 'via' => '', 'error' => '', 'error_k' => '', 'note' => '',
            'note_k' => '', 'note_v' => array(), 'release_url' => '');
        $c['available'] = is_array($c['latest']) && Util::semverValid($installed) && Util::semverCompare((string)$c['latest']['version'], $installed) > 0;
        return $c;
    }

    /** Badge data for the admin menu (cached check only). */
    public static function notice()
    {
        $installed = Config::installedVersion();
        $c = self::presentCheck(Source::cached(), $installed);
        return array('available' => $c['available'], 'latest' => is_array($c['latest']) ? (string)$c['latest']['version'] : '', 'installed' => $installed,
            'checked_at' => (int)$c['checked_at'], 'auto' => Config::settings()['auto_check']);
    }

    /** The automatic check (at most every 12 h; only when the owner left "Check for updates automatically" on). */
    public static function backgroundCheck()
    {
        if (!Config::settings()['auto_check']) {
            return self::notice();
        }
        self::housekeep();
        Source::check('auto');
        return self::notice();
    }

    /** The newest verified manifest the last check found (or null). */
    public static function latestManifest()
    {
        $c = Source::cached();
        if (!is_array($c['latest'] ?? null)) {
            return null;
        }
        return Source::stored((string)$c['latest']['version']);
    }

    /**
     * Manifest of the installed version: stored copy, the shipped one, else downloaded (signed) from the release of that
     * version, else the inbox. When GitHub could not be reached a moment ago, or this fetch failed a few minutes ago, only
     * the stored copies and the inbox are asked (Source::manifestFor): a Review on a host without outgoing HTTPS does not
     * wait for GitHub in every slice.
     */
    public static function installedManifest(&$error = '', $deadline = null)
    {
        $v = Config::installedVersion();
        if (!Util::semverValid($v)) {
            $error = 'the installed version "' . $v . '" is not a version number';
            return null;
        }
        $m = Source::stored($v);
        if ($m !== null) {
            return $m;
        }
        $shipped = Util::abs('application/data/update/manifest.json');
        if (is_file($shipped) && is_file($shipped . '.sig')) {
            $m = Source::accept((string)file_get_contents($shipped), (string)file_get_contents($shipped . '.sig'), $err);
            if ($m !== null && $m->version() === $v) {
                return $m;
            }
        }
        return Source::manifestFor($v, $error, $deadline);
    }

    // ------------------------------------------------------------------ requirements and plan

    /**
     * Requirement rows for updating to $to. Each row: ['id', 'level' => 'ok'|'warn'|'error', 'k', 'v', 'm']. $plan: the
     * plan of the pre-check; $w: its write check (Planner::writeCheckStep), computed here when missing.
     */
    public static function requirements(Manifest $to, $plan = null, $w = null)
    {
        $rows = array();
        $add = function ($id, $level, $k, array $v, $m) use (&$rows) {
            $rows[] = array('id' => $id, 'level' => $level, 'k' => $k, 'v' => $v, 'm' => $m);
        };
        $req = $to->requires();
        $installed = Config::installedVersion();
        $up = $to->upgrade();
        if (!Util::semverValid($installed)) {
            $add('installed', 'error', 'req_installed', array('v' => $installed), 'The installed version is unknown (application/extra/version.php).');
        } elseif ($up['min_from'] !== '' && Util::semverCompare($installed, $up['min_from']) < 0) {
            $add('min_from', 'error', 'req_min_from', array('v' => $up['min_from'], 'have' => $installed), 'Update to ' . $up['min_from'] . ' first (this site has ' . $installed . ').');
        }
        $phpOk = version_compare(PHP_VERSION, $req['php_min'], '>=');
        $add('php', $phpOk ? 'ok' : 'error', $phpOk ? 'req_php_ok' : 'req_php_low', array('have' => PHP_VERSION, 'need' => $req['php_min']),
            'PHP ' . PHP_VERSION . ($phpOk ? ' (needs ' . $req['php_min'] . ' or newer)' : ' is too old: ' . $req['php_min'] . ' or newer is needed'));
        if ($req['php_tested'] !== '' && version_compare(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $req['php_tested'], '>')) {
            $add('php_tested', 'warn', 'req_php_new', array('have' => PHP_VERSION, 'tested' => $req['php_tested']), 'This release was tested up to PHP ' . $req['php_tested'] . '.');
        }
        $missing = array();
        foreach ((array)$req['extensions'] as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        $add('ext', $missing ? 'error' : 'ok', $missing ? 'req_ext_missing' : 'req_ext_ok', array('list' => implode(', ', $missing)),
            $missing ? 'Missing PHP extensions: ' . implode(', ', $missing) : 'Required PHP extensions are loaded');
        $srv = Db::server();
        if ($srv === null) {
            $add('db', 'error', 'req_db_none', array(), 'The database is not reachable.');
        } else {
            $need = $srv['kind'] === 'mariadb' ? $req['mariadb_min'] : $req['mysql_min'];
            $ok = version_compare($srv['version'], $need, '>=');
            $add('db', $ok ? 'ok' : 'error', $ok ? 'req_db_ok' : 'req_db_low', array('kind' => $srv['kind'] === 'mariadb' ? 'MariaDB' : 'MySQL', 'have' => $srv['version'], 'need' => $need),
                ($srv['kind'] === 'mariadb' ? 'MariaDB ' : 'MySQL ') . $srv['version'] . ($ok ? '' : ' is too old: ' . $need . ' or newer is needed'));
        }
        $unzip = function_exists('inflate_init') || class_exists('ZipArchive');
        $add('unzip', $unzip ? 'ok' : 'error', $unzip ? 'req_unzip_ok' : 'req_unzip_none', array(), $unzip ? 'Packages can be unpacked (zlib)' : 'Neither zlib nor zip is available: packages cannot be unpacked.');
        $https = (function_exists('curl_init') || extension_loaded('openssl'));
        $inbox = Source::inboxZip($to) !== '';
        if (!$https && !$inbox) {
            $add('https', 'error', 'req_https_none', array(), 'No outgoing HTTPS (curl or openssl): upload the package and its signed manifest into runtime/update/inbox/.');
        } else {
            $add('https', 'ok', $inbox ? 'req_inbox' : 'req_https_ok', array(), $inbox ? 'Package found in runtime/update/inbox/' : 'HTTPS downloads available');
        }
        $keys = count(Signature::keyring());
        $add('keys', $keys > 0 ? 'ok' : 'error', $keys > 0 ? 'req_keys_ok' : 'req_keys_none', array('engine' => Signature::engine()),
            $keys > 0 ? 'Signatures checked with ' . Signature::engine() : 'This build has no release key: updates are refused.');
        if (!Store::locksWork()) {
            $add('locks', 'error', 'req_locks', array(), 'runtime/ is on a file system without file locks (some network or FUSE mounts): steps could run twice at once. Keep runtime/ on a local disk.');
        }
        $problem = Util::dirProblem();
        if ($problem === 'link') {
            $add('runtime', 'error', 'req_runtime_link', array(), 'runtime/ (or runtime/update/) is a link to another folder: the updater never writes through a link. runtime/ and runtime/update/ must be real folders of the site: replace the link with a folder.');
        } else {
            $upd = Util::dir();
            $add('runtime', $upd !== '' && is_writable($upd) ? 'ok' : 'error', $upd !== '' && is_writable($upd) ? 'req_runtime_ok' : 'req_runtime_no', array(),
                $upd !== '' ? 'runtime/update/ is writable' : 'runtime/update/ cannot be written.');
        }
        $bf = Backup::backupFolder();
        $bfAbs = Util::abs($bf);
        $bfOk = (is_dir($bfAbs) || Util::mkdirs($bfAbs)) && is_writable($bfAbs);
        $add('backup_folder', $bfOk ? 'ok' : 'error', $bfOk ? 'req_backup_ok' : 'req_backup_no', array('folder' => $bf),
            $bfOk ? 'Database backups go to ' . $bf : 'The backup folder ' . $bf . ' is not writable.');
        self::opcacheRows($add);
        // the host's own limit (the admin entry lifts it for itself; the update steps keep it)
        $met = (int)Util::iniGlobal('max_execution_time');
        $stl = Util::fnAvailable('set_time_limit');
        if ($met > 0 && $met < 15 && !$stl) {
            $add('time', 'warn', 'req_time_short', array('s' => $met), 'PHP may run only ' . $met . ' s per request; steps are kept shorter.');
        }
        if ($plan !== null) {
            if ($w === null) {
                $w = Planner::writeCheck($plan);
            }
            $unread = array_values(array_unique(array_merge((array)$w['unreadable'], (array)($plan['unreadable'] ?? array()))));
            if ($unread) {
                $add('read', 'error', 'req_unreadable', array('n' => count($unread), 'list' => implode(', ', array_slice($unread, 0, 8))),
                    count($unread) . ' files cannot be read (no permission): ' . implode(', ', array_slice($unread, 0, 8)));
            }
            if ($w['blocked']) {
                $add('write', 'error', 'req_write_blocked', array('n' => count($w['blocked']), 'list' => implode(', ', array_slice($w['blocked'], 0, 8))),
                    count($w['blocked']) . ' files cannot be written (no permission for the file or its folder): ' . implode(', ', array_slice($w['blocked'], 0, 8)));
            } else {
                $add('write', $w['readonly'] ? 'warn' : 'ok', $w['readonly'] ? 'req_write_readonly' : 'req_write_ok', array('n' => count($w['readonly']), 'total' => $w['count']),
                    $w['readonly'] ? count($w['readonly']) . ' files are marked read-only; the update clears that mark' : 'All ' . $w['count'] . ' files can be written');
            }
            $need = self::diskNeed($to, $plan);
            $free = Util::freeSpace(Util::root());
            if ($free === null) {
                $add('disk', 'warn', 'req_disk_unknown', array('need' => Util::bytes($need)), 'Free disk space unknown here (about ' . Util::bytes($need) . ' are needed).');
            } else {
                $add('disk', $free >= $need ? 'ok' : 'error', $free >= $need ? 'req_disk_ok' : 'req_disk_low', array('need' => Util::bytes($need), 'free' => Util::bytes($free)),
                    'Disk: ' . Util::bytes($need) . ' needed, ' . Util::bytes($free) . ' free');
            }
            if ($plan['unknown_base']) {
                $add('base', 'warn', 'req_unknown_base', array(), 'The file list of the installed version is not available: local changes cannot be told apart from the release.');
            }
            if ($plan['modified']) {
                $add('modified', 'warn', 'req_modified', array('n' => count($plan['modified'])), 'Release files changed on this site: ' . count($plan['modified']) . ' (choose below).');
            }
            // the updater's own files among them (replaced whatever the choice, Paths::updaterOwn) are named once, next to the
            // choice, by each page in its own words (the Updates page, the bridge, the command line): no requirement row
            if ($plan['merge']) {
                $add('merge', 'warn', 'req_merge', array('list' => implode(', ', $plan['merge'])), 'Kept for a manual merge: ' . implode(', ', $plan['merge']));
            }
            if ($plan['admin_entry'] !== 'javcms.php') {
                $add('entry', 'ok', 'req_entry_renamed', array('name' => $plan['admin_entry']), 'Renamed admin entry ' . $plan['admin_entry'] . ' is updated in place.');
            }
        }
        $cur = Store::current();
        if ($cur !== null) {
            if (self::attentionRun() !== null) {
                $add('busy', 'error', 'req_attention', array(), 'A rollback that could not finish waits for you: finish it first (Admins & backup > Updates, or php tools/update.php --status).');
            } else {
                $add('busy', 'error', 'req_busy', array(), 'Another update or rollback is running.');
            }
        }
        return $rows;
    }

    /** The active run when it is a rollback that waits for the founder (status attention), else null. */
    public static function attentionRun()
    {
        $cur = Store::current();
        $s = $cur !== null ? Store::load($cur['run']) : null;
        return $s !== null && $s['status'] === 'attention' ? $s : null;
    }

    /**
     * OPcache rows: a cache that never re-reads files (validate_timestamps=0) is reset by the update, unless the host
     * forbids this site to reset it (restrict_api); a cache that re-reads only every N seconds and may not be reset makes
     * the update wait N seconds before the new code runs its upgrade steps.
     */
    private static function opcacheRows(callable $add)
    {
        if (PHP_SAPI === 'cli') {
            // the command line's own php.ini says nothing about the web server's OPcache, and the command line cannot reset
            // it: the update asks the web server through the site (the maintenance gate), waits for a cache that may not
            // be reset, and stops - or rolls back - when the web server keeps running old code
            $add('opcache', 'ok', 'req_opcache_cli', array(),
                'The command line cannot see or reset the web server\'s PHP cache: the update asks the web server through the site, and stops (or rolls back) when the web server keeps running old code. After a manual change of files, restart PHP.');
            return;
        }
        // no active OPcache in this web server (not installed, off, or PHP's built-in server without enable_cli): no row
        if (!Util::opcacheActive()) {
            return;
        }
        $freq = (int)ini_get('opcache.revalidate_freq');
        $restrict = str_replace('\\', '/', (string)ini_get('opcache.restrict_api'));
        $root = str_replace('\\', '/', Util::root());
        // opcache functions disabled count like a restriction (hosting-4 VH4-3): an active OPcache this site cannot reset
        $blocked = ($restrict !== '' && stripos($root, $restrict) !== 0) || !Util::fnAvailable('opcache_reset') || !Util::fnAvailable('opcache_invalidate');
        // php.ini's "Off" reads back as "" (Util::opcacheNeverRereads)
        if (Util::opcacheNeverRereads(ini_get('opcache.validate_timestamps'))) {
            $add('opcache', $blocked ? 'error' : 'warn', $blocked ? 'req_opcache_blocked' : 'req_opcache_reset', array(),
                $blocked ? 'OPcache never re-reads files here and this site may not reset it (opcache.restrict_api or disabled functions): ask your host to allow opcache_reset for this site, or update manually and restart PHP.'
                    : 'OPcache never re-reads files here (validate_timestamps=0): the update resets it.');
        } elseif ($blocked && $freq > 2) {
            $add('opcache', 'warn', 'req_opcache_wait', array('s' => min(60, $freq)),
                'This site may not reset PHP\'s cache, which re-reads changed files every ' . $freq . ' s: the update waits that long before the new code runs.');
        }
    }

    /** Bytes the run needs on disk: package (unless here), staging, file backup, database backup, plus 20 %. */
    public static function diskNeed(Manifest $to, array $plan)
    {
        $z = $to->zip();
        $have = is_file(Util::root() . 'runtime/update/downloads/' . $z['name']) || Source::inboxZip($to) !== '';
        $db = Backup::dbPlan($to);
        $need = ($have ? 0 : $z['size']) + (int)$plan['bytes_new'] + (int)$plan['bytes_backup'] + (int)($db['bytes'] ?? 0);
        return (int)ceil($need * 1.2) + 5242880;
    }

    /** True when any requirement row is an error. */
    public static function blocking(array $rows)
    {
        foreach ($rows as $r) {
            if ($r['level'] === 'error') {
                return true;
            }
        }
        return false;
    }

    /**
     * One slice of the pre-update check: plan + requirements for the newest release. $fresh (the first call of a new
     * Review): files changed since an earlier scan are probed again, and write permission is checked again. Returns
     * ['state' => 'more'|'done'|'error', 'stage' => 'scan'|'write', 'done', 'total', 'plan' (summary), 'requirements',
     * 'db', 'error', 'to', 'from'].
     */
    public static function precheck($choice, $deadline, array $force = array(), $fresh = false)
    {
        $problem = self::runtimeProblem();
        if ($problem !== null) {
            return $problem;
        }
        $to = self::latestManifest();
        if ($to === null) {
            return self::refuse('no_newer_check', 'no newer release is known: check for updates first');
        }
        $installed = Config::installedVersion();
        if (!Util::semverValid($installed) || Util::semverCompare($to->version(), $installed) <= 0) {
            return self::refuse('not_newer', 'the newest release is not newer than the installed ' . $installed, array('v' => $installed));
        }
        $err = '';
        if ($fresh) {
            // a new Review asks GitHub once more for the installed version's file list (a fetch that failed a few minutes
            // ago - GitHub down, a cut answer - is not remembered past the Review it happened in)
            Source::forgetMiss($installed);
        }
        $from = self::installedManifest($err, min($deadline, Source::deadline()));
        $key = 'pre-' . $to->version();
        $choice = $choice === 'keep' ? 'keep' : 'replace';
        $wfile = Util::dir('prepare/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $key)) . 'write.php';
        if ($fresh) {
            @unlink($wfile);
        }
        $r = Planner::step($key, $to, $from, $choice, $deadline, '', $fresh);
        if ($r['state'] === 'more') {
            return array('state' => 'more', 'stage' => 'scan', 'done' => $r['done'], 'total' => $r['total'], 'to' => $to->version(), 'from' => $installed);
        }
        // $force: the bridge file (install.php) is replaced whatever the site has there, so it is not listed as changed
        $plan = Engine::applyForce($r['plan'], $force);
        // write permission of every file the plan touches, in slices of their own (a big plan on a slow host)
        $sig = sha1(Util::json(array($plan['add'], $plan['replace'], $plan['delete'], $plan['targets'])));
        $w = Util::loadData($wfile);
        if (!is_array($w) || ($w['sig'] ?? '') !== $sig) {
            $w = Planner::writeCheckStart() + array('sig' => $sig);
        }
        $done = Planner::writeCheckStep($plan, $w, $deadline);
        Util::saveData($wfile, $w);
        if (!$done) {
            return array('state' => 'more', 'stage' => 'write', 'done' => (int)$w['i'], 'total' => (int)$w['total'], 'to' => $to->version(), 'from' => $installed);
        }
        $db = Backup::dbPlan($to);
        return array('state' => 'done', 'done' => $r['done'], 'total' => $r['total'], 'to' => $to->version(), 'from' => $installed,
            'plan' => self::planSummary($plan), 'requirements' => self::requirements($to, $plan, $w),
            'db' => $db === null ? null : array('tables' => count($db['tables']), 'bytes' => $db['bytes'], 'full' => $db['full'],
                'folder' => $db['full'] ? $db['folder'] : $db['partial_folder']),
            'base_error' => $from === null ? $err : '', 'compat' => $to->upgrade()['compat'], 'estimate' => self::estimate($plan));
    }

    /**
     * How long an update of $plan takes, roughly (measured on Windows and Apache, shared-host limits: a 3,700-file
     * update - the size of the 1.2.0 bridge - kept visitors on the maintenance page for 1 to 2.5 minutes and took 2.5 to
     * 7 minutes in all; a few files about a minute). Whole minutes, rounded up: ['files', 'minutes', 'maint_minutes'].
     */
    public static function estimate(array $plan)
    {
        $files = count((array)($plan['add'] ?? array())) + count((array)($plan['replace'] ?? array())) + count((array)($plan['delete'] ?? array()));
        $maint = 30 + $files * 0.03;
        $all = $maint + 30 + $files * 0.05;
        return array('files' => $files, 'minutes' => max(1, (int)ceil($all / 60)), 'maint_minutes' => max(1, (int)ceil($maint / 60)));
    }

    /** Plan facts for the page (lists cut to 200 entries; the full list of changed files is a download). */
    public static function planSummary(array $plan)
    {
        $out = array();
        foreach (array('add', 'replace', 'delete', 'modified', 'keep', 'merge', 'left', 'unreadable', 'needed') as $k) {
            $out[$k] = count((array)($plan[$k] ?? array()));
            $out[$k . '_list'] = array_slice((array)($plan[$k] ?? array()), 0, 200);
        }
        $out['owned'] = count($plan['owned']);
        $out['same'] = $plan['same'];
        $out['seeds_kept'] = $plan['seeds_kept'];
        $out['bytes_new'] = $plan['bytes_new'];
        $out['bytes_backup'] = $plan['bytes_backup'];
        $out['unknown_base'] = $plan['unknown_base'];
        $out['admin_entry'] = $plan['admin_entry'];
        return $out;
    }

    /** The full list of locally changed release files of the last pre-check (text, one path per line). */
    public static function modifiedList(array $force = array())
    {
        $to = self::latestManifest();
        if ($to === null) {
            return '';
        }
        $scan = Util::loadData(Util::root() . 'runtime/update/prepare/pre-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $to->version()) . '/scan.php');
        if (!is_array($scan)) {
            return '';
        }
        $from = self::installedManifest();
        $plan = Engine::applyForce(Planner::build($to, $from, (array)$scan['local'], 'replace', (array)($scan['meta'] ?? array())), $force);
        return implode("\n", $plan['modified']) . "\n";
    }

    // ------------------------------------------------------------------ arming runs

    /** Base URL of the site as the current web request sees it ("https://host/sub/"). */
    public static function siteBase()
    {
        $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}$/', $host)) {
            return '';
        }
        $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
        $dir = ($dir === '/' || $dir === '.' || $dir === '') ? '/' : rtrim($dir, '/') . '/';
        return ($https ? 'https://' : 'http://') . $host . $dir;
    }

    /** The server's own IP address as the current web request reached it (health probes try it first), or ''. */
    public static function serverAddr()
    {
        foreach (array('SERVER_ADDR', 'LOCAL_ADDR') as $k) {
            $a = (string)($_SERVER[$k] ?? '');
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_IP)) {
                return $a;
            }
        }
        return '';
    }

    /** Admin entry file name of the current request (javcms.php or the site's own name). */
    public static function adminEntry()
    {
        $n = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'javcms.php'));
        return preg_match('/^[A-Za-z0-9_.-]{1,60}\.php$/', $n) && $n !== 'index.php' && $n !== 'api.php' ? $n : 'javcms.php';
    }

    /**
     * Arms an update to the newest release. $a: choice, by, base, admin, origin, bridge. Returns ['ok', 'token', 'run',
     * 'error']. The token is shown once to the caller; only its hash is kept. Arming holds the step lock: two requests
     * at the same moment (several PHP workers) never create two runs.
     */
    public static function arm(array $a)
    {
        $problem = self::runtimeProblem();
        if ($problem !== null) {
            return $problem;
        }
        self::housekeep();
        $lock = Store::lock();
        if ($lock === null) {
            return self::lockRefusal();
        }
        try {
            if (Store::current() !== null) {
                return self::busyRefusal();
            }
            $to = self::latestManifest();
            if ($to === null) {
                return self::refuse('no_newer', 'no newer release is known');
            }
            $installed = Config::installedVersion();
            if (!Util::semverValid($installed) || Util::semverCompare($to->version(), $installed) <= 0) {
                return self::refuse('not_newer', 'the newest release is not newer than the installed version (no downgrades)', array('v' => $installed));
            }
            $err = '';
            $from = self::installedManifest($err);
            $rows = self::requirements($to, null);
            if (self::blocking($rows)) {
                $why = array();
                $bad = array();
                foreach ($rows as $r) {
                    if ($r['level'] === 'error') {
                        $why[] = $r['m'];
                        $bad[] = $r;
                    }
                }
                $out = self::refuse('blocked', implode(' ', $why));
                $out['rows'] = $bad;
                return $out;
            }
            $s = Engine::create(array(
                'kind' => 'update', 'from' => $installed, 'to' => $to->version(), 'by' => (string)($a['by'] ?? ''),
                'choice' => ($a['choice'] ?? '') === 'keep' ? 'keep' : 'replace', 'base' => (string)($a['base'] ?? ''),
                'admin' => (string)($a['admin'] ?? 'javcms.php'), 'origin' => (string)($a['origin'] ?? ''), 'manifest_to' => Source::manifestName($to->version()),
                'manifest_from' => $from !== null ? Source::manifestName($from->version()) : '', 'bridge' => !empty($a['bridge']),
                'skip' => (array)($a['skip'] ?? array()), 'force' => (array)($a['force'] ?? array()),
            ));
            if ($from === null) {
                Store::log($s['id'], 'warn', 'No file list of the installed version (' . $err . '): changed files cannot be detected', 'scan', 'l_no_base',
                    array('why' => $err));
            }
            return self::activate($s, !empty($a['bridge']), (string)($a['frozen_from'] ?? ''));
        } finally {
            Store::unlock($lock);
        }
    }

    /**
     * The refusal while a run is active: a rollback that waits for the founder says so (it never ends by itself: the founder
     * tries it again or closes it after finishing it by hand), anything else is "another update or rollback is running".
     */
    private static function busyRefusal()
    {
        if (self::attentionRun() !== null) {
            return self::refuse('run_attention', 'a rollback that could not finish waits for you: try it again, or finish it by hand and close it (Admins & backup > Updates; php tools/update.php --status)');
        }
        return self::refuse('run_busy', 'another update or rollback is running');
    }

    /** The refusal when the step lock cannot be taken (Store::$lockError says why). */
    private static function lockRefusal()
    {
        if (Store::$lockError === 'unsupported') {
            return self::refuse('req_locks', 'runtime/ is on a file system without file locks (some network or FUSE mounts): steps could run twice at once. Keep runtime/ on a local disk.');
        }
        if (Store::$lockError === 'nofolder') {
            return self::refuse('req_runtime_no', 'runtime/update/ cannot be written.');
        }
        return self::refuse('run_busy', 'another update or rollback is running');
    }

    /** Freezes the engine, writes the active-run record; returns the token. */
    private static function activate(array $s, $bridge, $frozenFrom)
    {
        $token = Util::randomId(32);
        if ($frozenFrom !== '') {
            $ok = self::freezeBridge($s['id'], $frozenFrom);
        } else {
            $ok = Store::freeze($s['id']) !== '';
        }
        if (!$ok) {
            $s['status'] = 'failed';
            $s['error'] = 'the update engine could not be copied to runtime/update';
            $s['error_k'] = 'e_engine_copy';
            $s['ended'] = time();
            Store::save($s);
            Store::recordHistory($s);
            return self::refuse('e_engine_copy', $s['error']);
        }
        Store::setCurrent(array('run' => $s['id'], 'kind' => $s['kind'], 'token' => hash('sha256', $token), 'armed' => time(), 'bridge' => (bool)$bridge,
            'engine' => $frozenFrom !== '' ? 'bridge' : 'frozen'));
        return array('ok' => true, 'token' => $token, 'run' => $s['id'], 'kind' => $s['kind']);
    }

    /**
     * The bridge file freezes its own engine (it is not in application/common/util/updater/): the unpacked library file
     * with its sodium/ fallback, cacert.pem and page strings next to it.
     */
    private static function freezeBridge($id, $lib)
    {
        $base = Util::dir();
        $dir = $base . 'engine-' . $id . '/';
        $src = dirname((string)$lib) . '/';
        if (!Util::mkdirs($dir . 'sodium') || !@copy($lib, $dir . 'bridge-lib.php')) {
            return false;
        }
        foreach ((array)@scandir($src . 'sodium') as $f) {
            if (is_string($f) && is_file($src . 'sodium/' . $f) && !@copy($src . 'sodium/' . $f, $dir . 'sodium/' . $f)) {
                return false;
            }
        }
        // base-manifest.json.gz: the file list of the version the bridge is for (its SHA-256 is compiled into the library)
        foreach (array('cacert.pem', 'lang.json', 'base-manifest.json.gz') as $f) {
            if (is_file($src . $f) && !@copy($src . $f, $dir . $f)) {
                return false;
            }
        }
        return Store::writeRunner();
    }

    /** A new token for the active run (the page was reloaded or closed; the caller checked the password). */
    public static function rearm()
    {
        $cur = Store::current();
        if ($cur === null) {
            return self::refuse('no_run', 'no update is running');
        }
        $token = Util::randomId(32);
        $cur['token'] = hash('sha256', $token);
        $cur['armed'] = time();
        Store::setCurrent($cur);
        return array('ok' => true, 'token' => $token, 'run' => $cur['run'], 'kind' => $cur['kind']);
    }

    /** The finished update that may still be rolled back (newest done run within its window, backup present). */
    public static function rollbackOffer()
    {
        foreach (Store::history() as $h) {
            // only finished updates changed the installed version: a failed, cancelled or rolled-back attempt (or an
            // update already rolled back) is skipped, so the last good update stays the one that can be undone
            if (($h['kind'] ?? '') !== 'update' || ($h['status'] ?? '') !== 'done') {
                continue;
            }
            if ((int)($h['rollback_until'] ?? 0) > time() && ($h['to'] ?? '') === Config::installedVersion()) {
                $s = Store::load($h['id']);
                if ($s !== null && is_dir(Util::root() . 'runtime/update/runs/' . $h['id'] . '/backup')) {
                    $to = Source::stored($s['to']);
                    $breaking = $to !== null && $to->upgrade()['compat'] === 'breaking';
                    // the database tables a breaking release changed come back only from its backup set, which the owner may
                    // have deleted in Backup & restore meanwhile: then the rollback offers the files only, and says why
                    $dbMissing = $breaking ? self::missingDbParts($s, $to) : array();
                    return array('run' => $h['id'], 'from' => $s['to'], 'to' => $s['from'], 'until' => (int)$h['rollback_until'],
                        'db' => (string)($s['backup']['db_location'] ?? ''), 'breaking' => $breaking,
                        'db_restorable' => $breaking && !$dbMissing && !empty($s['backup']['db']['parts']), 'db_missing' => $dbMissing);
                }
            }
            return null;
        }
        return null;
    }

    /**
     * The tables of the update run $s that a rollback with "restore the database" would read and whose backup part is
     * missing (deleted in Backup & restore, moved away). Empty when every part is there.
     */
    public static function missingDbParts(array $s, $to = null)
    {
        $job = (array)($s['backup']['db'] ?? array());
        if (!$job || empty($job['parts'])) {
            return array();
        }
        $to = $to instanceof Manifest ? $to : Source::stored((string)$s['to']);
        $pre = Db::prefix();
        $missing = array();
        foreach ($to !== null ? (array)$to->upgrade()['alters'] : array() as $t) {
            $name = $pre . $t;
            $part = (string)($job['parts'][$name] ?? '');
            if ($part !== '' && !empty($job['tables'][$name]['data']) && !is_file(Util::abs($part))) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    /** Arms a manual rollback of the newest finished update. $a: by, base, admin, origin, db_restore. */
    public static function armRollback(array $a)
    {
        $problem = self::runtimeProblem();
        if ($problem !== null) {
            return $problem;
        }
        $lock = Store::lock();
        if ($lock === null) {
            return self::lockRefusal();
        }
        try {
            if (Store::current() !== null) {
                return self::busyRefusal();
            }
            $offer = self::rollbackOffer();
            if ($offer === null) {
                return self::refuse('no_rollback', 'no update can be rolled back (none in the last ' . Config::KEEP_DAYS . ' days, or its backup is gone)',
                    array('days' => Config::KEEP_DAYS));
            }
            if (!empty($a['db_restore']) && !empty($offer['db_missing'])) {
                // refused before any file changes: a rollback that puts the old files back and then cannot restore the
                // tables would leave the old code on the new database
                $list = implode(', ', (array)$offer['db_missing']);
                return self::refuse('rollback_db_missing', 'the database backup of ' . $list . ' is missing (deleted in Backup & restore?): "restore the database" is not possible; roll back the files only, or restore those tables by hand',
                    array('list' => $list));
            }
            $s = Engine::create(array(
                'kind' => 'rollback', 'from' => $offer['from'], 'to' => $offer['to'], 'of' => $offer['run'], 'by' => (string)($a['by'] ?? ''),
                'base' => (string)($a['base'] ?? ''), 'admin' => (string)($a['admin'] ?? 'javcms.php'), 'origin' => (string)($a['origin'] ?? ''),
                'db_restore' => !empty($a['db_restore']),
                'manifest_to' => Source::manifestName($offer['to']), 'manifest_from' => Source::manifestName($offer['from']),
            ));
            return self::activate($s, false, '');
        } finally {
            Store::unlock($lock);
        }
    }

    /**
     * Cancels the active run while it has not changed any site file yet (before the maintenance page). Every step holds
     * the step lock for its whole slice, and on Apache / PHP-FPM the page's next step starts about 150 ms after the last
     * one ends: so the request is written first (Store::requestCancel) and the step that runs now honours it at its next
     * boundary (Engine::step). Then this waits for the lock ($wait seconds, default a step's budget plus a margin) and ends
     * the run itself; when a long step (the command line's) still holds it, the answer is 'pending': that step stops when
     * it ends. Returns ['ok', 'pending', 'db_removed' (its database backup set was removed), 'error', 'k', 'v'].
     */
    public static function cancel($wait = null)
    {
        $cur = Store::current();
        $s = $cur !== null ? Store::load($cur['run']) : null;
        if ($s !== null && $s['status'] === 'attention') {
            // a rollback that waits for the founder is not cancelled: its files are half restored (the run's page says what to
            // do: try it again, or finish it by hand and close it)
            return self::refuse('cancel_attention', 'a rollback that could not finish waits for you: nothing can be cancelled; try it again, or finish it by hand and close it');
        }
        if ($s === null || $s['status'] !== 'running') {
            // nothing runs: a record of a run that ended goes (under the lock, so a step that just started is not cut off)
            $lock = Store::lock();
            if ($lock !== null) {
                $c2 = Store::current();
                $s2 = $c2 !== null ? Store::load($c2['run']) : null;
                if ($s2 === null || !in_array($s2['status'], array('running', 'attention'), true)) {
                    Store::clearCurrent();
                    Store::dropFrozen('');
                    if ($s2 !== null) {
                        Store::maintenanceOffFor($s2['id']);
                    }
                }
                Store::unlock($lock);
            }
            return array('ok' => true, 'pending' => false, 'db_removed' => false, 'status' => (string)($s['status'] ?? ''));
        }
        if (!Engine::cancellable($s)) {
            return self::refuse('cancel_too_late', 'files are being changed: the run must finish or roll back');
        }
        Store::requestCancel($s['id']);
        // the wait for the step that runs now never outlasts this request: a host whose limit cannot be raised (both
        // set_time_limit and ini_set disabled, a limit counted in wall time) would cut the request off with an error page
        $want = $wait === null ? Config::STEP_BUDGET + 4.0 : (float)$wait;
        $lock = Store::lockWait(PHP_SAPI === 'cli' ? $want : min($want, Util::requestTimeLeft(2.0)));
        if ($lock === null) {
            if (Store::$lockError !== 'busy') {
                Store::clearCancel($s['id']);
                return self::lockRefusal();
            }
            $out = self::refuse('cancel_pending', 'the update stops at the end of the step that is running now (nothing of the site was changed)');
            $out['ok'] = true;
            $out['pending'] = true;
            $out['db_removed'] = false;
            return $out;
        }
        try {
            $s = Store::load($s['id']);
            if ($s === null) {
                Store::clearCurrent();
                return array('ok' => true, 'pending' => false, 'db_removed' => false);
            }
            if ($s['status'] === 'running') {
                if (!Engine::cancellable($s)) {
                    Store::clearCancel($s['id']);
                    return self::refuse('cancel_too_late', 'files are being changed: the run must finish or roll back');
                }
                Engine::cancelNow($s);
                return array('ok' => true, 'pending' => false, 'db_removed' => !empty($s['cancel_db_removed']));
            }
            // the run ended while this request waited: the step honoured the request, or it failed or finished first
            Store::clearCancel($s['id']);
            if ($s['status'] === 'cancelled') {
                return array('ok' => true, 'pending' => false, 'db_removed' => !empty($s['cancel_db_removed']));
            }
            return self::refuse('cancel_ended', 'the update ended before the cancel arrived (' . $s['status'] . ')', array('status' => (string)$s['status']));
        } finally {
            Store::unlock($lock);
        }
    }

    // ------------------------------------------------------------------ clean-up

    /**
     * Hourly clean-up: runs older than Config::KEEP_DAYS lose their backups (files and the database backup they made);
     * finished runs lose their unpacked release (a big one may have been left for this); the update's own database
     * backups that no run refers to (an attempt that died before it saved its job) go after an hour; downloads of
     * versions other than the newest known; an armed run that never got past its preparation for Config::ARM_SECONDS is
     * closed (with its database backup set: nothing was changed); a run that stopped early and could not remove its set
     * then loses it now; temp files older than an hour that a process left in runtime/update/ go. Every removal is
     * bounded by one deadline of a few seconds per call: what is left goes at the next call.
     */
    /**
     * An armed update nobody drives (the page was closed) that never got past its preparation ends after
     * Config::ARM_SECONDS: nothing of the site was changed, so it is closed with its database backup set (and a maintenance
     * flag a step that died at maint_on may have left). Cheap: called by every housekeeping and by the run's own pages
     * (Runner::page, the bridge page, "continue"), so a bridge run abandoned on a 1.2.0 site ends too. The caller holds no
     * lock; this takes the step lock and never waits for it. True when a run was closed.
     */
    public static function expireEarly()
    {
        $cur = Store::current();
        if ($cur === null) {
            return false;
        }
        $s = Store::load($cur['run']);
        if ($s === null || $s['status'] !== 'running' || !Engine::cancellable($s) || time() - (int)$s['heartbeat'] <= Config::ARM_SECONDS) {
            return false;
        }
        $lock = Store::lock();
        if ($lock === null) {
            return false;
        }
        $closed = false;
        try {
            $s = Store::load($cur['run']);
            if ($s !== null && $s['status'] === 'running' && Engine::cancellable($s) && time() - (int)$s['heartbeat'] > Config::ARM_SECONDS) {
                $s['status'] = 'failed';
                $s['error'] = 'the update page was closed before the files were changed (nothing changed)';
                $s['error_k'] = 'e_closed_early';
                $s['error_phase'] = $s['phase'];
                $s['ended'] = time();
                Engine::dropEarly($s);
                if (Store::maintenanceOffFor($s['id'])) {
                    Store::log($s['id'], 'info', 'The maintenance page that a step turned on before it stopped was turned off again (nothing of the site was changed)',
                        $s['phase'], 'l_maint_flag_removed');
                }
                Store::log($s['id'], 'info', 'Nobody continued the update for ' . (int)round(Config::ARM_SECONDS / 3600) . ' hours before any file was changed: it was closed (nothing changed)',
                    $s['phase'], 'l_closed_early', array('h' => (int)round(Config::ARM_SECONDS / 3600)));
                Store::save($s);
                Store::recordHistory($s);
                Store::clearCurrent();
                Store::dropFrozen('');
                $closed = true;
            }
        } finally {
            Store::unlock($lock);
        }
        return $closed;
    }

    public static function housekeep()
    {
        $base = Util::root() . 'runtime/update/';
        if (!is_dir($base)) {
            return;
        }
        // runtime/ or runtime/update/ is a link (or cannot be written): nothing is written or removed through it
        if (Util::dirProblem() !== '') {
            return;
        }
        // these are cheap and must not wait an hour: an abandoned early run, a rollback the founder finished by hand (its
        // flag gone, every file of the hand procedure back), a maintenance flag no run can end, and the replaced table
        // copies a rollback left for later (never while a run is active)
        self::expireEarly();
        Engine::autoCloseAttention();
        Store::dropOrphanFlag();
        if (Store::current() === null && is_file($base . 'drop-later.php')) {
            Backup::dropLater(microtime(true) + 6.0);
        }
        $marker = $base . 'housekeep.txt';
        if (is_file($marker) && time() - (int)@filemtime($marker) < 3600) {
            return;
        }
        Util::writeAtomic($marker, (string)time());
        $deadline = microtime(true) + 5.0;
        $cur = Store::current();
        // the update run that the current run rolls back (a manual "Roll back to X" is a run of its own whose copies and
        // database set belong to the update run): its backup is what that rollback - and the hand procedure of
        // attention.txt, should it need the founder - restores from, so it stays while the rollback runs or waits, also
        // beyond the 7 days (UPDATER-VERIFY-E2E-5 P2-1)
        $keepOf = '';
        if ($cur !== null) {
            $s = Store::load($cur['run']);
            // a record of a run that ended goes (a rollback that waits for the founder keeps it: its page is the way on)
            if ($s === null || !in_array($s['status'], array('running', 'attention'), true)) {
                Store::clearCurrent();
            } elseif ((string)($s['kind'] ?? '') === 'rollback' && (string)($s['of'] ?? '') !== '') {
                $keepOf = (string)$s['of'];
            }
            $cur = Store::current();
        }
        $keepUntil = time() - Config::KEEP_DAYS * 86400;
        $known = array();
        foreach ((array)glob($base . 'runs/*', GLOB_ONLYDIR) as $dir) {
            $id = basename((string)$dir);
            $s = Store::load($id);
            if ($s !== null && !empty($s['backup']['db']['name'])) {
                $known[(string)$s['backup']['db']['name']] = true;
            }
            if ($cur !== null && ($cur['run'] === $id || $id === $keepOf)) {
                continue;
            }
            if (microtime(true) >= $deadline) {
                continue; // the run's database backup name stays known; the rest waits for the next call
            }
            if ($s !== null && $s['status'] !== 'running' && is_dir((string)$dir . '/staging')) {
                Util::removeTreeTimed((string)$dir . '/staging', $deadline);
            }
            // a run that stopped before it changed anything keeps no database backup set (a step that died while it
            // removed it, or an older engine that left it)
            if ($s !== null && in_array($s['status'], array('failed', 'cancelled'), true) && !empty($s['backup']['db'])
                && empty($s['backup']['db_removed']) && !in_array((string)($s['error_phase'] ?? ''), Engine::LIVE_PHASES, true)) {
                if (Backup::removeJob((array)$s['backup']['db'], $deadline)) {
                    $s['backup']['db_removed'] = 1;
                    $s['backup']['db_location'] = '';
                    Store::save($s);
                }
            }
            // data-file temps a process that died left in the run's folder (state, plan, journal)
            Util::sweepTemps((string)$dir, null, 3600);
            $ended = $s !== null ? (int)($s['ended'] ?: $s['created']) : (int)@filemtime((string)$dir);
            // an update whose manual rollback could not restore the database tables keeps its backup (files and set) until
            // keep_until: the rollback's message sends the founder to that set, KEEP_DAYS from the failure, whatever the
            // update's own age (Engine r_finish; UPDATER-VERIFY-SECURITY-6 VS6-1)
            if ($ended > $keepUntil || ($s !== null && (int)($s['keep_until'] ?? 0) > time())) {
                continue;
            }
            if ($s !== null && !empty($s['backup']['db']) && !Backup::removeJob((array)$s['backup']['db'], $deadline)) {
                continue;
            }
            if ($s !== null) {
                unset($known[(string)($s['backup']['db']['name'] ?? '')]);
            }
            if (!Util::removeTreeTimed((string)$dir . '/backup', $deadline) || !Util::removeTreeTimed((string)$dir . '/staging', $deadline)) {
                continue;
            }
            if ($s !== null && ($s['rollback_until'] ?? 0) > 0) {
                $s['rollback_until'] = 0;
                Store::save($s);
                Store::recordHistory($s);
            }
        }
        if ($cur === null && microtime(true) < $deadline) {
            Backup::removeOrphans($known, $deadline);
        }
        // data-file temps a process that died left in runtime/update/ itself (check.php, history.php ...)
        Util::sweepTemps($base, null, 3600);
        $latest = Source::cached()['latest']['version'] ?? '';
        foreach ((array)glob($base . 'downloads/*') as $f) {
            $name = basename((string)$f);
            if ($latest !== '' && strpos($name, 'javcms-' . $latest . '.') === 0) {
                continue;
            }
            if (time() - (int)@filemtime((string)$f) > Config::KEEP_DAYS * 86400) {
                @unlink((string)$f);
            }
        }
        // the password counters of one address or one bridge proof (a pause lasts 15 minutes): a day-old one only takes
        // space; removed under the counters' lock, never while a password is being checked
        $authLock = @fopen($base . 'auth-fails.lock', 'c');
        if ($authLock && @flock($authLock, LOCK_EX | LOCK_NB)) {
            foreach (array_merge((array)glob($base . 'auth-fails-ip*.php'), (array)glob($base . 'auth-fails-p*.php')) as $f) {
                if (is_file((string)$f) && time() - (int)@filemtime((string)$f) > 86400) {
                    @unlink((string)$f);
                }
            }
            @flock($authLock, LOCK_UN);
        }
        if ($authLock) {
            @fclose($authLock);
        }
        foreach ((array)glob($base . 'prepare/*', GLOB_ONLYDIR) as $d) {
            if (time() - (int)@filemtime((string)$d) > 86400 && microtime(true) < $deadline) {
                Util::removeTreeTimed((string)$d, $deadline);
            }
        }
        if ($cur === null) {
            Store::dropFrozen('');
            foreach (array('runner.php', 'gate.php') as $f) {
                if (is_file($base . $f) && !is_file($base . 'maintenance.php')) {
                    @unlink($base . $f);
                }
            }
            foreach ((array)glob($base . 'gate-*.php') as $g) {
                if (!is_file($base . 'maintenance.php')) {
                    @unlink((string)$g);
                }
            }
        }
    }
}
