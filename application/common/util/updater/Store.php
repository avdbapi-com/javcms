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
namespace app\common\util\updater;

/**
 * Files of the updater under runtime/update/ (site-owned: no release ever contains or replaces them).
 *
 *   current.php         the active run: id, kind, hash of the run token, which engine copy runs it
 *   runs/<id>/          state.php, plan.php, journal.php, log.php, staging/, backup/, merge/
 *   engine-<id>/        frozen copy of the update engine (namespace javcms_upd_<id>), taken when the run started, with
 *                       the page strings of the four admin languages (lang/<locale>.php)
 *   runner.php          entry of the active run (loaded by the admin entry, install.php and the maintenance gate)
 *   maintenance.php     maintenance flag (visitors get the maintenance page while it exists)
 *   gate.php            loader of the maintenance page code gate-<hash>.php (index.php / api.php include it while the
 *                       flag exists)
 *   step.lock           one step at a time
 *   history.php         finished runs (summary)
 *   check.php, seen.php, keys.php, manifests/, downloads/, inbox/   (Source, Signature)
 *
 * The frozen copy matters: while files are swapped, the site's own code is half old, half new, so every step from the
 * moment the run starts is executed by the engine copy taken at that moment, loaded before any site file.
 *
 * PHP files at a fixed path never change their content (runner.php, gate.php): a host whose OPcache never re-reads files
 * (opcache.validate_timestamps=0) would keep running an old copy. Code that differs between runs or versions lives in
 * files whose names are unique (engine-<run>/, gate-<hash of its code>.php); the fixed files only read data files
 * (current.php, maintenance.php) to find them.
 */
final class Store
{
    const STATE_FORMAT = 1;
    /** Seconds since the last step after which a run counts as "nobody drives it" for the admin pages (Runner). */
    const DRIVEN_SECONDS = 15;

    // ------------------------------------------------------------------ current run

    public static function current()
    {
        $c = Util::loadData(Util::root() . 'runtime/update/current.php');
        return is_array($c) && !empty($c['run']) && preg_match('/^[0-9a-f]{16}$/', (string)$c['run']) ? $c : null;
    }

    public static function setCurrent(array $c)
    {
        return Util::saveData(Util::dir() . 'current.php', $c);
    }

    /** Ends the active run: no runner, no flag, no current record (the run folder and its backup stay). */
    public static function clearCurrent()
    {
        $base = Util::root() . 'runtime/update/';
        @unlink($base . 'runner.php');
        @unlink($base . 'current.php');
    }

    public static function runDir($id, $sub = '')
    {
        if (!preg_match('/^[0-9a-f]{16}$/', (string)$id)) {
            return '';
        }
        return Util::dir('runs/' . $id . ($sub !== '' ? '/' . $sub : ''));
    }

    public static function load($id)
    {
        $d = self::runDir($id);
        if ($d === '') {
            return null;
        }
        $s = Util::loadData($d . 'state.php');
        return is_array($s) && ($s['format'] ?? 0) === self::STATE_FORMAT ? $s : null;
    }

    public static function save(array $state)
    {
        $state['format'] = self::STATE_FORMAT;
        $state['saved'] = time();
        $d = self::runDir($state['id']);
        return $d !== '' && Util::saveData($d . 'state.php', $state);
    }

    public static function loadPart($id, $name)
    {
        $d = self::runDir($id);
        $p = $d === '' ? null : Util::loadData($d . $name . '.php');
        return is_array($p) ? $p : array();
    }

    public static function savePart($id, $name, array $data)
    {
        $d = self::runDir($id);
        return $d !== '' && Util::saveData($d . $name . '.php', $data);
    }

    // ------------------------------------------------------------------ log

    /**
     * Appends one log line (redacted). $k / $v: the message key and its variables, so the admin page shows the line in
     * the admin's language (the downloaded log keeps the English text).
     */
    public static function log($id, $level, $text, $phase = '', $k = '', array $v = array())
    {
        $d = self::runDir($id);
        if ($d === '') {
            return;
        }
        $file = $d . 'log.php';
        $new = !is_file($file);
        $fh = @fopen($file, 'ab');
        if (!$fh) {
            return;
        }
        $row = array('t' => time(), 'l' => $level, 'p' => $phase, 'm' => Util::cut(Util::redact($text), 2000));
        if ($k !== '') {
            $row['k'] = $k;
            $row['v'] = self::logVars($v, 0);
        }
        if (flock($fh, LOCK_EX)) {
            if ($new) {
                fwrite($fh, Util::GUARD);
            }
            fwrite($fh, Util::json($row) . "\n");
            fflush($fh);
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    /**
     * Message variables for the log: scalars redacted and cut, lists four levels deep at most (a reason's own variables:
     * why_v => checks => [check => v => values] of a failed health check), 20 entries per level.
     */
    private static function logVars(array $v, $depth)
    {
        $out = array();
        foreach (array_slice($v, 0, 20, true) as $name => $val) {
            if (is_scalar($val) || $val === null) {
                $out[$name] = is_bool($val) ? ($val ? '1' : '') : Util::cut(Util::redact((string)$val), 600);
            } elseif (is_array($val) && $depth < 4) {
                $out[$name] = self::logVars($val, $depth + 1);
            }
        }
        return $out;
    }

    /** Log lines after line $from (0-based), at most $max. */
    public static function readLog($id, $from = 0, $max = 400)
    {
        $d = self::runDir($id);
        $raw = $d === '' ? false : @file_get_contents($d . 'log.php');
        if (!is_string($raw)) {
            return array();
        }
        $lines = explode("\n", substr($raw, strlen(Util::GUARD)));
        $out = array();
        $n = 0;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if ($n++ < $from) {
                continue;
            }
            $j = json_decode($line, true);
            if (is_array($j)) {
                $out[] = $j;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ step lock

    /** Why the last lock() returned null: 'busy' (another step holds it), 'nofolder' (runtime/update/ is missing or not
     * writable), 'unsupported' (the file system of runtime/ has no file locks: some NFS / FUSE mounts). */
    public static $lockError = '';

    /**
     * Takes the step lock without waiting; the handle, or null (self::$lockError says why). A lock that fails on a file
     * nobody else can hold (a probe file of this request) means the file system does not lock at all: then every step and
     * every arm would wait for ever, so that case gets its own answer (and requirement row).
     */
    public static function lock()
    {
        self::$lockError = '';
        $dir = Util::dir();
        if ($dir === '') {
            self::$lockError = 'nofolder';
            return null;
        }
        $fh = @fopen($dir . 'step.lock', 'c');
        if (!$fh) {
            self::$lockError = 'nofolder';
            return null;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            self::$lockError = self::locksWork() ? 'busy' : 'unsupported';
            return null;
        }
        return $fh;
    }

    /**
     * Takes the step lock, waiting up to $seconds for a step that holds it (a step is at most Config::STEP_BUDGET plus a
     * margin); null when it stays busy or cannot be taken at all (self::$lockError).
     */
    public static function lockWait($seconds)
    {
        $end = microtime(true) + max(0.0, (float)$seconds);
        do {
            $fh = self::lock();
            if ($fh !== null || self::$lockError !== 'busy') {
                return $fh;
            }
            usleep(100000);
        } while (microtime(true) < $end);
        return null;
    }

    /**
     * True when flock() works in runtime/update/ (a fresh probe file that no other request knows: a lock that fails on it
     * cannot be another step's). Cached per request.
     */
    public static function locksWork()
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        $dir = Util::dir();
        if ($dir === '') {
            return $ok = true; // reported as 'nofolder' elsewhere
        }
        $probe = $dir . 'lock-probe-' . Util::randomId(4) . '.tmp';
        $fh = @fopen($probe, 'c');
        if (!$fh) {
            return $ok = true;
        }
        $ok = @flock($fh, LOCK_EX | LOCK_NB);
        if ($ok) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        @unlink($probe);
        return $ok;
    }

    public static function unlock($fh)
    {
        if (is_resource($fh)) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    // ------------------------------------------------------------------ cancel requests

    /**
     * Cancel asked for run $id (runs/<id>/cancel.php). Cancelling needs the step lock, which a step holds for its whole
     * slice: the request is written first, so the step that runs now (another PHP worker, the command line) honours it at
     * its next boundary even when the caller cannot wait for the lock (Engine::step).
     */
    public static function requestCancel($id)
    {
        $d = self::runDir($id);
        return $d !== '' && Util::saveData($d . 'cancel.php', array('at' => time()));
    }

    public static function cancelRequested($id)
    {
        return preg_match('/^[0-9a-f]{16}$/', (string)$id) && is_file(Util::root() . 'runtime/update/runs/' . $id . '/cancel.php');
    }

    public static function clearCancel($id)
    {
        if (preg_match('/^[0-9a-f]{16}$/', (string)$id)) {
            @unlink(Util::root() . 'runtime/update/runs/' . $id . '/cancel.php');
        }
    }

    /** True while a step of the run is working right now (it holds the step lock); never waits, never keeps the lock. */
    public static function stepRunning()
    {
        $f = Util::root() . 'runtime/update/step.lock';
        if (!is_file($f)) {
            return false;
        }
        $fh = @fopen($f, 'c');
        if (!$fh) {
            return false;
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return !$free;
    }

    /** True when somebody drives run $state now: a step holds the lock, or the last step ended DRIVEN_SECONDS ago at most. */
    public static function driven(array $state)
    {
        return self::stepRunning() || time() - (int)($state['heartbeat'] ?? 0) <= self::DRIVEN_SECONDS;
    }

    // ------------------------------------------------------------------ long jobs (tools/housekeeping.php, tools/import.php)

    /**
     * The command-line jobs hold a shared lock on runtime/update-jobs.lock while they run; true when one runs now. The
     * update waits for them before it turns maintenance on (they load the site's code, which the swap replaces).
     */
    public static function jobsRunning()
    {
        $f = Util::root() . 'runtime/update-jobs.lock';
        if (!is_file($f)) {
            return false;
        }
        $fh = @fopen($f, 'c');
        if (!$fh) {
            return false;
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return !$free;
    }

    // ------------------------------------------------------------------ maintenance

    public static function maintenance()
    {
        return Util::loadData(Util::root() . 'runtime/update/maintenance.php');
    }

    /**
     * Turns the maintenance page on (or refreshes its heartbeat). $hold: admin pages wait too (they get the run's own
     * progress page) while files are being replaced.
     */
    public static function maintenanceOn(array $state, $hold)
    {
        $dir = Util::dir();
        if ($dir === '') {
            return false;
        }
        $gate = self::writeGate();
        if ($gate === '') {
            return false;
        }
        $old = self::maintenance();
        return Util::saveData($dir . 'maintenance.php', array(
            'run' => $state['id'], 'since' => is_array($old) && ($old['run'] ?? '') === $state['id'] ? (int)$old['since'] : time(),
            'hb' => time(), 'probe' => hash('sha256', (string)$state['probe']), 'cache' => hash('sha256', (string)$state['cache_token']),
            'hold' => (bool)$hold, 'stall' => Config::stallSeconds(), 'retry' => 60, 'rescue' => !empty($state['rescue']),
            'gate' => $gate, 'lang' => self::siteLang(),
            // a rollback that waits for the founder (Engine::needsAttention): the gate never drives it
            'attention' => ($state['status'] ?? '') === 'attention',
        ));
    }

    /**
     * The maintenance flag of a rollback that needs the founder: visitors keep the maintenance page, admin pages show the
     * run's own page (hold), and the gate no longer tries to drive the run.
     */
    public static function maintenanceAttention(array $state)
    {
        return self::maintenanceOn($state, true);
    }

    /** The site's default language (app.lang of application/extra/maccms.php): the visitors' maintenance page uses it. */
    public static function siteLang()
    {
        $raw = @file_get_contents(Util::abs('application/extra/maccms.php'));
        if (is_string($raw) && preg_match("/'lang'\s*=>\s*'([a-z]{2}(?:-[a-z]{2})?)'/i", $raw, $m)) {
            return strtolower($m[1]);
        }
        return 'en-us';
    }

    /**
     * Heartbeat of the maintenance flag (the gate rolls a run back that nobody drives). 'rescue' tells the gate that
     * visitors' requests drive the run now: then every visitor request (two seconds apart) takes the next slice instead
     * of waiting for another stall period.
     */
    public static function heartbeat(array $state)
    {
        $m = self::maintenance();
        if (is_array($m) && ($m['run'] ?? '') === $state['id'] && empty($m['attention'])) {
            $m['hb'] = time();
            $m['rescue'] = !empty($state['rescue']);
            Util::saveData(Util::root() . 'runtime/update/maintenance.php', $m);
        }
    }

    /** Whether the gate should drive run of flag $m now: nobody drove it for the stall time, or visitors drive it already. */
    public static function stalled(array $m, $stall)
    {
        $age = time() - (int)($m['hb'] ?? 0);
        return $age > (int)$stall || (!empty($m['rescue']) && $age >= 2);
    }

    public static function maintenanceOff()
    {
        $f = Util::root() . 'runtime/update/maintenance.php';
        if (is_file($f) && !@unlink($f)) {
            @chmod($f, 0644);
            @unlink($f);
        }
        $gone = !is_file($f);
        if ($gone) {
            // the gate's files go with the flag: also after a run that ended before the maintenance page (a cancel, an
            // early failure, an expired page, an orphan flag) - e2e-4 P3-1
            self::dropGateFiles();
        }
        return $gone;
    }

    /**
     * The files of the maintenance gate and its cache list go when maintenance is off: gate.php, every gate-<hash>.php and
     * cache-list.txt. Data the next run writes again; inert behind the deny rules either way. Never while the flag exists
     * (the gate is in use then: index.php includes it only while maintenance.php is there).
     */
    public static function dropGateFiles()
    {
        $base = Util::root() . 'runtime/update/';
        if (is_file($base . 'maintenance.php')) {
            return;
        }
        @unlink($base . 'cache-list.txt');
        @unlink($base . 'gate.php');
        foreach ((array)glob($base . 'gate-*.php') as $g) {
            @unlink((string)$g);
        }
    }

    /**
     * Removes the maintenance flag when it belongs to run $id (a step that died between writing the flag and saving the
     * run's state leaves a flag the state does not know about): a run that ends before it changed anything never leaves
     * visitors on the maintenance page. True when a flag of that run was there and is gone now.
     */
    public static function maintenanceOffFor($id)
    {
        $m = self::maintenance();
        if (!is_array($m) || (string)($m['run'] ?? '') !== (string)$id || (string)$id === '') {
            return false;
        }
        return self::maintenanceOff();
    }

    /**
     * A maintenance flag that no run can end any more: the active run (current.php) is not the one the flag names, or
     * there is none. Without its current record nothing can drive or roll back that run (the gate's rescue and the run's
     * pages need it), so the flag would keep visitors on the maintenance page for ever; nothing is half done without an
     * active run (a rollback that needs the founder keeps its current record). True when one was removed.
     */
    public static function dropOrphanFlag()
    {
        $m = self::maintenance();
        if (!is_array($m)) {
            return false;
        }
        $run = (string)($m['run'] ?? '');
        $cur = self::current();
        if ($cur !== null && $cur['run'] === $run) {
            return false;
        }
        $s = preg_match('/^[0-9a-f]{16}$/', $run) ? self::load($run) : null;
        if ($s !== null && (string)$s['status'] === 'attention') {
            // never: a rollback that waits for the founder (its record may come back with "try again")
            return false;
        }
        return self::maintenanceOff();
    }

    /**
     * Writes the gate: runtime/update/gate.php (a loader that never changes) and gate-<hash>.php (the maintenance page
     * code of this engine). Returns the name of the code file, '' when it cannot be written.
     */
    public static function writeGate()
    {
        $dir = Util::dir();
        if ($dir === '') {
            return '';
        }
        $code = self::gateCode();
        $name = 'gate-' . substr(hash('sha256', $code), 0, 12) . '.php';
        foreach (array($name => $code, 'gate.php' => self::gateLoaderCode()) as $f => $text) {
            if (is_file($dir . $f) && @file_get_contents($dir . $f) === $text) {
                continue;
            }
            if (!Util::writeAtomic($dir . $f, $text)) {
                return '';
            }
            Util::opcacheInvalidate(array($dir . $f));
        }
        return $name;
    }

    /** gate.php: finds the gate code named in the flag; constant, so a cached copy is always the right one. */
    public static function gateLoaderCode()
    {
        return <<<'LOADER'
<?php
// JavCMS one-click update: maintenance gate loader. Written by the updater; this code never changes (a PHP cache that
// never re-reads files runs the right one). The page itself is gate-<hash>.php, named in maintenance.php by the update
// that turned maintenance on. Not a release file.
return (function () {
    $dir = __DIR__ . '/';
    $raw = @file_get_contents($dir . 'maintenance.php');
    if (!is_string($raw) || ($p = strpos($raw, "\n")) === false) {
        return null;
    }
    $m = json_decode(substr($raw, $p + 1), true);
    if (!is_array($m)) {
        return null;
    }
    $g = isset($m['gate']) && is_string($m['gate']) && preg_match('/^gate-[0-9a-f]{12}\.php$/D', $m['gate']) ? $m['gate'] : '';
    if ($g !== '' && is_file($dir . $g)) {
        return include $dir . $g;
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store, max-age=0');
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'The site is being updated. Try again in a minute.';
    exit;
})();
LOADER;
    }

    /**
     * The maintenance gate. Runs before any site file on index.php / api.php while runtime/update/maintenance.php exists:
     * lets the run's health probes through, serves the command-line update's OPcache reset (token in a request header,
     * never in a URL that access logs keep), rolls back a run nobody drives any more (one visitor at a time, a short
     * slice each), and answers everyone else with a 503 page that search engines read as "come back later", in the
     * visitor's language when it is one of the four admin languages, else in the site's.
     */
    public static function gateCode()
    {
        return <<<'GATE'
<?php
// JavCMS one-click update: maintenance gate. Written by the updater when it turned maintenance on (loaded through
// gate.php) and removed with the flag. Not a release file: a file swap never replaces it while it runs.
return (function () {
    $dir = __DIR__ . '/';
    $raw = @file_get_contents($dir . 'maintenance.php');
    if (!is_string($raw) || ($p = strpos($raw, "\n")) === false) {
        return null;
    }
    $m = json_decode(substr($raw, $p + 1), true);
    if (!is_array($m)) {
        return null;
    }
    $probe = isset($_SERVER['HTTP_X_JAVCMS_UPDATE_PROBE']) ? (string)$_SERVER['HTTP_X_JAVCMS_UPDATE_PROBE'] : '';
    if ($probe !== '' && isset($m['probe']) && hash_equals((string)$m['probe'], hash('sha256', $probe))) {
        return null;
    }
    // this web server's OPcache: a function may be called when it exists and is not disabled; "enabled" is OPcache's own
    // answer when it may be asked, else the ini values (the command line and phpdbg need enable_cli as well, PHP's
    // built-in server does not); an OPcache whose functions are all disabled still caches - it counts as enabled
    $fnOk = function ($f) {
        if (!function_exists($f)) {
            return false;
        }
        foreach (explode(',', strtolower((string)ini_get('disable_functions'))) as $d) {
            if (trim($d) === $f) {
                return false;
            }
        }
        return true;
    };
    $iniOn = function ($v) {
        $v = strtolower(trim((string)$v));
        return in_array($v, array('1', 'on', 'yes', 'true'), true) || (ctype_digit($v) && (int)$v > 0);
    };
    $opc = function () use ($fnOk, $iniOn) {
        $on = null;
        if ($fnOk('opcache_get_status')) {
            $st = @opcache_get_status(false);
            if (is_array($st)) {
                $on = !empty($st['opcache_enabled']);
            }
        }
        if ($on === null) {
            $on = $iniOn(ini_get('opcache.enable')) && (!in_array(PHP_SAPI, array('cli', 'phpdbg'), true) || $iniOn(ini_get('opcache.enable_cli')));
        }
        return array('enabled' => (bool)$on, 'vt' => (string)ini_get('opcache.validate_timestamps'), 'freq' => (int)ini_get('opcache.revalidate_freq'),
            'restricted' => (string)ini_get('opcache.restrict_api') !== '');
    };
    $ping = isset($_SERVER['HTTP_X_JAVCMS_UPDATE_PING']) ? (string)$_SERVER['HTTP_X_JAVCMS_UPDATE_PING'] : '';
    if ($ping !== '' && isset($m['cache']) && hash_equals((string)$m['cache'], hash('sha256', $ping))) {
        // the command line asks whether the web server runs this gate (visitors get the maintenance page) - an index.php
        // the web server keeps in its cache from before the gate existed never answers this - and how its OPcache works:
        // a cache that never re-reads files must be one this site may reset, or the new files would never be used. The
        // reset is tried right here: harmless, nothing of the site was replaced yet (e2e-4 P2-1)
        $o = $opc();
        $o['reset'] = $o['enabled'] && $fnOk('opcache_reset') ? (bool)@opcache_reset() : false;
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(array('gate' => 1) + $o);
        exit;
    }
    $cache = isset($_SERVER['HTTP_X_JAVCMS_UPDATE_CACHE']) ? (string)$_SERVER['HTTP_X_JAVCMS_UPDATE_CACHE'] : '';
    if ($cache !== '' && isset($m['cache']) && hash_equals((string)$m['cache'], hash('sha256', $cache))) {
        // what the web server did, and how its OPcache re-reads files: the command line reads its own php.ini, not the
        // web server's, and waits like a web run when the web server refused the reset (opcache.restrict_api); without an
        // active OPcache there is nothing to reset and nothing was refused
        $done = array('reset' => false, 'invalidated' => 0) + $opc();
        $list = @file_get_contents($dir . 'cache-list.txt');
        if (is_string($list) && $fnOk('opcache_invalidate')) {
            foreach (explode("\n", $list) as $f) {
                if ($f !== '' && @opcache_invalidate($f, true)) {
                    $done['invalidated']++;
                }
            }
        }
        if ($fnOk('opcache_reset')) {
            $done['reset'] = (bool)@opcache_reset();
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($done);
        exit;
    }
    if (isset($_GET['jcupd']) && is_file($dir . 'runner.php')) {
        // the update page's steps, when the entry it used does not forward them any more (an older admin entry or
        // bridge brought back mid-rollback): the runner checks the run token as always and ends the request
        include $dir . 'runner.php';
        exit;
    }
    $age = time() - (int)($m['hb'] ?? 0);
    if ($age > (int)($m['stall'] ?? 180) && !is_file($dir . 'runner.php') && !is_file($dir . 'current.php')) {
        // a flag no run can end any more (its run ended - a cancel after a step that died - without removing it):
        // nothing is half done without an active run, so visitors get the site again; the gate's files go with it
        @unlink($dir . 'maintenance.php');
        clearstatcache();
        if (!is_file($dir . 'maintenance.php')) {
            @unlink($dir . 'cache-list.txt');
            foreach ((array)glob($dir . 'gate-*.php') as $g) {
                @unlink((string)$g);
            }
            @unlink($dir . 'gate.php');
            return null;
        }
    }
    if (($age > (int)($m['stall'] ?? 180) || (!empty($m['rescue']) && $age >= 2)) && empty($m['attention']) && is_file($dir . 'runner.php')) {
        // (a rollback that needs the founder - 'attention' - is never driven by visitors: it waits for the founder's page)
        if (!defined('JAVCMS_UPD_RESCUE')) {
            define('JAVCMS_UPD_RESCUE', 1);
        }
        try {
            include $dir . 'runner.php';
        } catch (\Throwable $e) {
        }
        clearstatcache();
        if (!is_file($dir . 'maintenance.php')) {
            return null;
        }
    }
    $retry = max(10, min(600, (int)($m['retry'] ?? 60)));
    $texts = array(
        'en' => array('en', 'Updating', 'The site is being updated', 'This usually takes a minute or two. The page reloads by itself.', 'The site is being updated. Try again in a minute.', 'Try again now'),
        'vi' => array('vi', 'Đang cập nhật', 'Trang web đang được cập nhật', 'Việc này thường mất một đến hai phút. Trang sẽ tự tải lại.', 'Trang web đang được cập nhật. Hãy thử lại sau một phút.', 'Thử lại ngay'),
        'zh-cn' => array('zh-Hans', '正在更新', '网站正在更新', '通常需要一到两分钟。本页会自动刷新。', '网站正在更新，请一分钟后再试。', '立即重试'),
        'zh-tw' => array('zh-Hant', '正在更新', '網站正在更新', '通常需要一到兩分鐘。本頁會自動重新整理。', '網站正在更新，請一分鐘後再試。', '立即重試'),
    );
    $pick = function ($tag) {
        $tag = strtolower(trim((string)$tag));
        if ($tag === '') {
            return '';
        }
        if (strpos($tag, 'vi') === 0) {
            return 'vi';
        }
        if (in_array($tag, array('zh-tw', 'zh-hk', 'zh-mo'), true) || strpos($tag, 'zh-hant') === 0) {
            return 'zh-tw';
        }
        if (strpos($tag, 'zh') === 0) {
            return 'zh-cn';
        }
        return strpos($tag, 'en') === 0 ? 'en' : '';
    };
    $lang = '';
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $lang = $pick(explode(';', $part)[0]);
        if ($lang !== '') {
            break;
        }
    }
    if ($lang === '') {
        $lang = $pick($m['lang'] ?? '') ?: 'en';
    }
    $t = $texts[$lang];
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: ' . $retry);
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Language: ' . $t[0]);
    }
    $api = isset($_SERVER['SCRIPT_NAME']) && stripos((string)$_SERVER['SCRIPT_NAME'], 'api.php') !== false;
    if ($api) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('code' => 503, 'msg' => $t[4]), JSON_UNESCAPED_UNICODE);
        exit;
    }
    $h = function ($s) {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    };
    // no meta refresh (assistive technology meets a sudden reload): a script reloads after Retry-After, and the visible
    // link lets the visitor try again at once
    echo '<!doctype html><html lang="' . $t[0] . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . $h($t[1]) . '</title>'
        . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;'
        . 'background:#0f1115;color:#e6e8ee}main{max-width:520px;padding:32px 24px;text-align:center}h1{font-size:22px;margin:0 0 8px}'
        . 'p{margin:0 0 12px;color:#c3c8d2}a{color:#9db8ff}@media(prefers-color-scheme:light){body{background:#f6f7f9;color:#1d2230}p{color:#3d4454}a{color:#1d4ed8}}</style>'
        . '</head><body><main><h1>' . $h($t[2]) . '</h1><p>' . $h($t[3]) . '</p><p><a href="" id="again">' . $h($t[5]) . '</a></p></main>'
        . '<script>setTimeout(function(){location.reload();},' . ($retry * 1000) . ');'
        . 'document.getElementById("again").addEventListener("click",function(e){e.preventDefault();location.reload();});</script>'
        . '</body></html>';
    exit;
})();
GATE;
    }

    // ------------------------------------------------------------------ the run's entry and its engine copy

    /** runtime/update/runner.php: finds the active run's engine copy through current.php; constant code. */
    public static function runnerCode()
    {
        return <<<'RUNNER'
<?php
// JavCMS one-click update: entry of the active run. Written by the updater while a run is active and removed when it
// ends; this code never changes. It reads the run from current.php (data) and loads the engine copy taken when that run
// started (engine-<run>/); no file of the site's own code is loaded here.
return (function () {
    $dir = __DIR__ . '/';
    $raw = @file_get_contents($dir . 'current.php');
    if (!is_string($raw) || ($p = strpos($raw, "\n")) === false) {
        return false;
    }
    $c = json_decode(substr($raw, $p + 1), true);
    $id = is_array($c) && isset($c['run']) && is_string($c['run']) && preg_match('/^[0-9a-f]{16}$/D', $c['run']) ? $c['run'] : '';
    if ($id === '') {
        return false;
    }
    if (is_file($dir . 'engine-' . $id . '/bootstrap.php')) {
        require_once $dir . 'engine-' . $id . '/bootstrap.php';
        $cls = '\\javcms_upd_' . $id . '\\Runner';
    } elseif (is_file($dir . 'engine-' . $id . '/bridge-lib.php')) {
        if (!defined('JAVCMS_UPD_LIB')) {
            define('JAVCMS_UPD_LIB', 1);
        }
        require_once $dir . 'engine-' . $id . '/bridge-lib.php';
        $cls = '\\javcms_upd_bridge\\Runner';
    } else {
        return false;
    }
    return $cls::entry(dirname(__DIR__, 2), defined('JAVCMS_UPD_RESCUE'), defined('JAVCMS_UPD_ENTRY') ? (string)JAVCMS_UPD_ENTRY : 'admin');
})();
RUNNER;
    }

    /** Writes runtime/update/runner.php when it is missing or differs (and tells this server's OPcache). */
    public static function writeRunner()
    {
        $dir = Util::dir();
        if ($dir === '') {
            return false;
        }
        $code = self::runnerCode();
        if (is_file($dir . 'runner.php') && @file_get_contents($dir . 'runner.php') === $code) {
            return true;
        }
        if (!Util::writeAtomic($dir . 'runner.php', $code)) {
            return false;
        }
        Util::opcacheInvalidate(array($dir . 'runner.php'));
        return true;
    }

    /**
     * Freezes the engine for run $id: copies this folder (without UpgradeSteps.php, which is the NEW release's code) to
     * runtime/update/engine-<id>/ with the namespace renamed to javcms_upd_<id>, with the page strings of the four admin
     * languages (the run's own pages work while the site's files are half replaced), and writes runtime/update/runner.php.
     * Returns the frozen folder or ''.
     */
    public static function freeze($id)
    {
        $base = Util::dir();
        if ($base === '') {
            return '';
        }
        $ns = 'javcms_upd_' . $id;
        $dest = $base . 'engine-' . $id . '/';
        Util::removeTree($dest);
        if (!Util::mkdirs($dest) || !Util::mkdirs($dest . 'sodium') || !Util::mkdirs($dest . 'lang')) {
            return '';
        }
        $src = __DIR__ . '/';
        $order = array();
        foreach ((array)scandir($src) as $f) {
            if (!is_string($f) || substr($f, -4) !== '.php' || $f === 'UpgradeSteps.php') {
                continue;
            }
            $code = (string)@file_get_contents($src . $f);
            $code = preg_replace('/^namespace [A-Za-z0-9_\\\\]+;/m', 'namespace ' . $ns . ';', $code, 1);
            if (!Util::writeAtomic($dest . $f, $code)) {
                return '';
            }
            $order[] = $f;
        }
        foreach ((array)scandir($src . 'sodium') as $f) {
            if (is_string($f) && is_file($src . 'sodium/' . $f)) {
                if (!@copy($src . 'sodium/' . $f, $dest . 'sodium/' . $f)) {
                    return '';
                }
            }
        }
        if (is_file($src . 'cacert.pem') && !@copy($src . 'cacert.pem', $dest . 'cacert.pem')) {
            return '';
        }
        foreach (Runner::LOCALES as $loc) {
            $lf = Util::abs('application/admin/lang/' . $loc . '/51-updates.php');
            if (is_file($lf) && !@copy($lf, $dest . 'lang/' . $loc . '.php')) {
                return '';
            }
        }
        $boot = "<?php\n// Frozen JavCMS update engine of run " . $id . " (namespace " . $ns . "). Loads its own files only.\n"
            . "foreach (" . var_export($order, true) . " as \$f) { require_once __DIR__ . '/' . \$f; }\n"
            . "\\" . $ns . "\\Util::setRoot(dirname(__DIR__, 3));\n";
        if (!Util::writeAtomic($dest . 'bootstrap.php', $boot)) {
            return '';
        }
        if (!self::writeRunner()) {
            return '';
        }
        return $dest;
    }

    /** Removes frozen engine copies of runs that are not active any more. */
    public static function dropFrozen($keepId = '')
    {
        foreach ((array)glob(Util::root() . 'runtime/update/engine-*', GLOB_ONLYDIR) as $d) {
            if ($keepId !== '' && basename((string)$d) === 'engine-' . $keepId) {
                continue;
            }
            Util::removeTree((string)$d);
        }
    }

    // ------------------------------------------------------------------ history

    public static function history()
    {
        $h = Util::loadData(Util::root() . 'runtime/update/history.php');
        return is_array($h) ? array_values((array)($h['runs'] ?? array())) : array();
    }

    /** Adds or replaces the summary of a run (newest first, at most 50 kept). */
    public static function recordHistory(array $state)
    {
        $runs = self::history();
        $row = array(
            'id' => $state['id'], 'kind' => $state['kind'], 'from' => $state['from'], 'to' => $state['to'],
            'started' => (int)$state['created'], 'ended' => (int)($state['ended'] ?? 0), 'status' => (string)$state['status'],
            'error' => Util::cut((string)($state['error'] ?? ''), 300), 'by' => (string)($state['by'] ?? ''),
            'rollback_until' => (int)($state['rollback_until'] ?? 0), 'of' => (string)($state['of'] ?? ''),
            'error_k' => (string)($state['error_k'] ?? ''), 'error_v' => (array)($state['error_v'] ?? array()),
            'bridge' => !empty($state['bridge']),
        );
        $out = array($row);
        foreach ($runs as $r) {
            if (is_array($r) && ($r['id'] ?? '') !== $state['id']) {
                $out[] = $r;
            }
        }
        Util::saveData(Util::dir() . 'history.php', array('runs' => array_slice($out, 0, 50)));
    }
}
