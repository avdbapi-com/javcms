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
 * Web entry of a running update, answered BEFORE any file of the site's own code is loaded (UPDATER decision U3).
 *
 * The admin entry (javcms.php, or the name the site gave it) includes runtime/update/runner.php while a run is active,
 * and so does install.php (mode "install": the bridge of a 1.2.0 site is replaced by the new install.php during the run);
 * that file loads the frozen engine copy of the run (namespace javcms_upd_<run>) and calls entry():
 *   - "?jcupd=<action>" requests (the Updates page's steps) are handled here and the request ends;
 *   - while files are being replaced (maintenance "hold"), every other admin page gets the run's own progress page
 *     (the site's code is half replaced, so the framework is never started then): it shows what the run is doing, and
 *     when nobody drives the run any more it asks for the founder password and continues the run from there;
 *   - install.php shows the same page for as long as a run is active (a reload of the bridge page lands there);
 *   - any other admin request continues normally.
 * The maintenance gate calls entry() with $rescue = true to roll back a run nobody drives any more.
 *
 * The step actions need the run token (X-Javcms-Update-Token header; 256 random bits handed to the page that armed the
 * run; only its hash is stored) and, when the PHP session can be read the site's way, the signed-in founder admin (id 1).
 * "continue" needs the founder password instead (custom request header: no other site can send it) and hands out a new
 * token; it is open only while nobody drives the run (15 s without a step), to the signed-in founder, on the bridge also
 * to the browser that proved access to the site's files there (its file-proof session), and on a regular run to a caller
 * without the founder's session only where that session cannot be read on this host (another session store, a session
 * folder this code may not look into: nobody can be told apart there) - while the admin pages are held. "retry" ("Try
 * the rollback again") and "close" ("Close this run" after the founder finished the rollback by hand) do the same for a
 * rollback that waits for the founder (needs attention); a caller without the founder's session may use them only where
 * sessions cannot be read, during the first ANON_ATTENTION_SECONDS after the rollback stopped, five wrong passwords then
 * pausing them for an hour (security-4 VS4-1). Five wrong passwords pause it for 15 minutes, counted under a lock
 * (parallel requests cannot try more): for the founder's account, for a file-proof session, and - for callers without the
 * founder's session - for the client's address, so strangers never pause the founder.
 * A visitor who is not the signed-in founder sees what the run does and how far it is, never its details or versions; a
 * password form only where it may be used.
 */
final class Runner
{
    const LOCALES = array('en-us', 'vi-vn', 'zh-cn', 'zh-tw');
    const PASSWORD_TRIES = 5;
    /** Seconds after a rollback stopped ("needs attention") during which a caller without a readable session may try. */
    const ANON_ATTENTION_SECONDS = 3600;
    /** Pause of that counter after five wrong passwords. */
    const ANON_ATTENTION_PAUSE = 3600;

    /** @return mixed|void */
    public static function entry($root, $rescue = false, $mode = 'admin')
    {
        Util::setRoot($root);
        $cur = Store::current();
        if ($rescue) {
            if ($cur !== null) {
                $m = Store::maintenance();
                if (is_array($m) && Store::stalled($m, Config::stallSeconds())) {
                    Engine::rescue($cur['run']);
                }
            } else {
                // a flag without a run: nothing can be half done any more
                Store::maintenanceOff();
            }
            return null;
        }
        if (isset($_GET['jcupd'])) {
            self::handle($cur);
            exit;
        }
        if ($cur === null) {
            return null;
        }
        $m = Store::maintenance();
        if ($mode === 'install' || (is_array($m) && !empty($m['hold']) && ($m['run'] ?? '') === $cur['run'])) {
            self::page($cur, $mode);
            exit;
        }
        return null;
    }

    /** Handles one "?jcupd=" request and prints JSON. */
    private static function handle($cur)
    {
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
        $action = is_string($_GET['jcupd']) ? $_GET['jcupd'] : '';
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            self::out(405, array('ok' => false, 'error' => 'POST only'));
        }
        if ($cur === null) {
            self::out(200, array('ok' => false, 'status' => 'none', 'error' => 'no update is running'));
        }
        $since = isset($_POST['seq']) && ctype_digit((string)$_POST['seq']) ? (int)$_POST['seq'] : 0;
        $who = self::sessionAdmin();
        if ($who !== null && $who > 0 && $who !== 1 && empty($cur['bridge'])) {
            self::out(403, array('ok' => false, 'error' => 'only the founder admin account may run updates', 'k' => 'founder_only'));
        }
        if ($action === 'continue') {
            self::continueRun($cur, $who, $since);
        }
        if ($action === 'retry') {
            self::retryRun($cur, $who, $since);
        }
        if ($action === 'close') {
            self::closeRun($cur, $who, $since);
        }
        $token = (string)($_SERVER['HTTP_X_JAVCMS_UPDATE_TOKEN'] ?? '');
        if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token) || !hash_equals((string)($cur['token'] ?? ''), hash('sha256', $token))) {
            self::out(403, array('ok' => false, 'error' => 'the update token is not valid (reload the Updates page and confirm with your password)'));
        }
        // the page reports its own session state; the long step runs without holding the session (scout: a held
        // session blocks the admin's other tabs for the whole step)
        if ($action === 'step') {
            $r = Engine::step($cur['run']);
        } elseif ($action === 'health') {
            $report = json_decode((string)($_POST['report'] ?? ''), true);
            $ok = is_array($report) && Engine::healthReport($cur['run'], $report);
            $s = Store::load($cur['run']);
            $r = $s === null ? array('ok' => false, 'status' => 'missing') : Engine::result($s);
            $r['accepted'] = $ok;
        } elseif ($action === 'status') {
            $s = Store::load($cur['run']);
            $r = $s === null ? array('ok' => false, 'status' => 'missing') : Engine::result($s);
        } else {
            self::out(400, array('ok' => false, 'error' => 'unknown action'));
        }
        $r['log'] = Store::readLog($cur['run'], $since, 200);
        $r['seq'] = $since + count($r['log']);
        self::out(200, $r);
    }

    /**
     * "continue": the founder password hands out a new run token (the page that started the run was closed or reloaded,
     * or it never had one: the progress page of a held admin). Only a request carrying the custom header
     * X-Javcms-Update-Continue can do it (a form of another site cannot set it).
     */
    private static function continueRun(array $cur, $who, $since)
    {
        if ((string)($_SERVER['HTTP_X_JAVCMS_UPDATE_CONTINUE'] ?? '') !== '1') {
            self::out(403, array('ok' => false, 'error' => 'not allowed'));
        }
        // an early run nobody continued for hours ends here too (a bridge run on a 1.2.0 site has no other housekeeping)
        Service::expireEarly();
        // only a run nobody drives (the page that held its key was closed) is continued here, and only by a caller who may
        // try the founder password: the signed-in founder; on the bridge the browser of a file-proof session; on a regular
        // run anyone while the admin pages are held (the framework cannot run then). While a page drives the run, or
        // before anything needs it, there is nothing to try a password on.
        $run = Store::load($cur['run']);
        $m = Store::maintenance();
        $held = is_array($m) && !empty($m['hold']) && (string)($m['run'] ?? '') === (string)$cur['run'];
        $proof = !empty($cur['bridge']) && $who !== 1 ? self::bridgeProof() : '';
        // a caller without the founder's session only where that session cannot be read here (vs4-1)
        $allowed = $who === 1 || $proof !== '' || ($held && empty($cur['bridge']) && !self::sessionsReadable());
        if ($run === null || $run['status'] !== 'running' || Store::current() === null || Store::driven($run) || !$allowed) {
            self::out(200, array('ok' => false, 'k' => 'continue_not_now',
                'error' => 'This update is being driven by another page, or it needs no password here now: reload the page in a few seconds.'));
        }
        $raw = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $bad = self::passwordCheck($raw, self::counterOf($who, $proof), $who !== 1);
        if ($bad !== '') {
            self::out(200, array('ok' => false, 'password' => 1, 'k' => $bad, 'error' => $bad === 'password_paused'
                ? 'Too many wrong passwords. Wait 15 minutes, then try again.' : 'The password is not correct.'));
        }
        $r = Service::rearm();
        if (empty($r['ok'])) {
            self::out(200, array('ok' => false, 'error' => (string)$r['error']));
        }
        $s = Store::load($cur['run']);
        $out = $s === null ? array('ok' => false, 'status' => 'missing') : Engine::result($s);
        $out['token'] = $r['token'];
        $out['log'] = Store::readLog($cur['run'], $since, 200);
        $out['seq'] = $since + count($out['log']);
        Store::log($cur['run'], 'info', 'The founder continued the run from the progress page', $s['phase'] ?? '', 'l_continued');
        self::out(200, $out);
    }

    /**
     * Whether the caller may type the founder password for run $run, a rollback that waits for the founder: the signed-in
     * founder; on the bridge a file-proof session; on a regular run a caller without the founder's session only where
     * sessions cannot be read on this host, and only during the first ANON_ATTENTION_SECONDS after the rollback stopped
     * (vs4-1: an attention state lasts until the founder acts - hours or days - and is no password oracle meanwhile).
     * Returns [allowed, counter file, pause seconds].
     */
    private static function attentionCaller(array $cur, array $run, $who, $proof)
    {
        if ($who === 1 || $proof !== '') {
            return array(true, self::counterOf($who, $proof), 900);
        }
        $anon = empty($cur['bridge']) && !self::sessionsReadable()
            && time() - (int)($run['attention']['at'] ?? 0) <= self::ANON_ATTENTION_SECONDS;
        return array($anon, 'auth-fails-attn.php', self::ANON_ATTENTION_PAUSE);
    }

    /**
     * "retry": "Try the rollback again" of a rollback that waits for the founder (Engine::needsAttention). The callers of
     * attentionCaller(); the rollback goes on from the step that failed, with a new run token.
     */
    private static function retryRun(array $cur, $who, $since)
    {
        if ((string)($_SERVER['HTTP_X_JAVCMS_UPDATE_CONTINUE'] ?? '') !== '1') {
            self::out(403, array('ok' => false, 'error' => 'not allowed'));
        }
        $run = Store::load($cur['run']);
        $proof = !empty($cur['bridge']) && $who !== 1 ? self::bridgeProof() : '';
        $caller = $run !== null ? self::attentionCaller($cur, $run, $who, $proof) : array(false, '', 900);
        if ($run === null || $run['status'] !== 'attention' || !$caller[0]) {
            self::out(200, array('ok' => false, 'k' => 'retry_not_now', 'error' => 'This rollback does not wait for you (any more): reload the page.'));
        }
        $raw = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $bad = self::passwordCheck($raw, $caller[1], $who !== 1, $caller[2]);
        if ($bad !== '') {
            self::out(200, array('ok' => false, 'password' => 1, 'k' => $bad, 'error' => $bad === 'password_paused'
                ? 'Too many wrong passwords. Wait, then try again.' : 'The password is not correct.'));
        }
        if (!Engine::retryAttention($cur['run'])) {
            self::out(200, array('ok' => false, 'k' => 'busy', 'error' => 'Another step is still working; try again in a moment.'));
        }
        $r = Service::rearm();
        if (empty($r['ok'])) {
            self::out(200, array('ok' => false, 'error' => (string)$r['error']));
        }
        $s = Store::load($cur['run']);
        $out = $s === null ? array('ok' => false, 'status' => 'missing') : Engine::result($s);
        $out['token'] = $r['token'];
        $out['log'] = Store::readLog($cur['run'], $since, 200);
        $out['seq'] = $since + count($out['log']);
        self::out(200, $out);
    }

    /**
     * "close": "Close this run" of a rollback that waits for the founder, after the founder finished it by hand (the files
     * back as runtime/update/runs/<run>/attention.txt lists them, the maintenance flag deleted or not): the run ends as
     * closed by the founder (Engine::closeAttention: the flag goes, the version number is the old one again). The callers
     * of attentionCaller(); the answer is the run's end (no token: nothing runs any more).
     */
    private static function closeRun(array $cur, $who, $since)
    {
        if ((string)($_SERVER['HTTP_X_JAVCMS_UPDATE_CONTINUE'] ?? '') !== '1') {
            self::out(403, array('ok' => false, 'error' => 'not allowed'));
        }
        $run = Store::load($cur['run']);
        $proof = !empty($cur['bridge']) && $who !== 1 ? self::bridgeProof() : '';
        $caller = $run !== null ? self::attentionCaller($cur, $run, $who, $proof) : array(false, '', 900);
        if ($run === null || $run['status'] !== 'attention' || !$caller[0]) {
            self::out(200, array('ok' => false, 'k' => 'close_not_now', 'error' => 'This run does not wait for you (any more): reload the page.'));
        }
        $raw = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $bad = self::passwordCheck($raw, $caller[1], $who !== 1, $caller[2]);
        if ($bad !== '') {
            self::out(200, array('ok' => false, 'password' => 1, 'k' => $bad, 'error' => $bad === 'password_paused'
                ? 'Too many wrong passwords. Wait, then try again.' : 'The password is not correct.'));
        }
        $r = Engine::closeAttention($cur['run'], 'founder');
        if (empty($r['ok'])) {
            self::out(200, array('ok' => false, 'k' => (string)$r['k'], 'error' => (string)$r['error']));
        }
        $s = Store::load($cur['run']);
        $out = $s === null ? array('ok' => false, 'status' => 'missing') : Engine::result($s);
        $out['closed_now'] = 1;
        $out['log'] = Store::readLog($cur['run'], $since, 200);
        $out['seq'] = $since + count($out['log']);
        self::out(200, $out);
    }

    /**
     * True when the founder's sign-in can be read on this host: the site's session store is PHP's file handler and its
     * folder can be looked into. Then a caller without the founder's session is a stranger (or a founder whose session
     * ended) and never gets a password form for a run; where it cannot be read nobody can be told apart (vs4-1).
     */
    public static function sessionsReadable()
    {
        $cfg = self::sessionConfig();
        if (!$cfg['readable']) {
            return false;
        }
        $handler = strtolower((string)ini_get('session.save_handler'));
        if ($handler !== 'files' && $handler !== '') {
            return false;
        }
        $path = $cfg['path'] !== '' ? (string)$cfg['path'] : (string)ini_get('session.save_path');
        if (strpos($path, ';') !== false) {
            $parts = explode(';', $path);
            $path = (string)end($parts);
        }
        if ($path === '') {
            $path = sys_get_temp_dir();
        }
        $path = rtrim(str_replace('\\', '/', $path), '/');
        return $path !== '' && @is_dir($path);
    }

    /** The password counter of a caller: the founder's account, a bridge file-proof session, or the anonymous one. */
    private static function counterOf($who, $proof)
    {
        if ($who === 1) {
            return 'auth-fails.php';
        }
        return $proof !== '' ? 'auth-fails-p' . substr($proof, 0, 12) . '.php' : 'auth-fails-anon.php';
    }

    /**
     * The sha256 of this browser's file-proof session of the bridge (cookie javcms_bridge whose hash is listed, not expired,
     * in runtime/update/bridge/auth.php: the owner proved on the bridge page that they manage the site's files), or ''.
     * Read only; the same rule as Bridge::proofSession (the bridge library is not loaded here after the swap).
     */
    public static function bridgeProof()
    {
        $c = isset($_COOKIE['javcms_bridge']) && is_string($_COOKIE['javcms_bridge']) ? $_COOKIE['javcms_bridge'] : '';
        if (!preg_match('/^[0-9a-f]{48}$/', $c)) {
            return '';
        }
        $a = Util::loadData(Util::root() . 'runtime/update/bridge/auth.php');
        $h = hash('sha256', $c);
        foreach ((array)(is_array($a) ? ($a['sessions'] ?? array()) : array()) as $sess) {
            if (is_array($sess) && (int)($sess['until'] ?? 0) > time() && hash_equals((string)($sess['hash'] ?? ''), $h)) {
                return $h;
            }
        }
        return '';
    }

    private static function out($code, array $data)
    {
        http_response_code($code);
        echo Util::json($data);
        exit;
    }

    // ------------------------------------------------------------------ who asks

    /**
     * The site's session settings (session block of application/config.php, as ThinkPHP applies them): 'readable' (PHP's
     * own file handler, so this code can read the same session), 'prefix', 'path', 'name'. Another session store (redis,
     * memcache), an override file or an unreadable configuration: not readable here.
     */
    public static function sessionConfig()
    {
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg;
        }
        $cfg = array('readable' => false, 'prefix' => 'think', 'path' => '', 'name' => '');
        if (is_file(Util::abs('application/extra/session.php'))) {
            return $cfg;
        }
        $raw = @file_get_contents(Util::abs('application/config.php'));
        if (!is_string($raw) || !preg_match("/'session'\s*=>\s*(?:\[|array\()(.*?)\n\s*(?:\]|\)),/s", $raw, $mm)) {
            return $cfg;
        }
        $block = $mm[1];
        $get = function ($key) use ($block) {
            if (preg_match("/'" . $key . "'\s*=>\s*'([^']*)'/", $block, $m)) {
                return $m[1];
            }
            return preg_match("/'" . $key . "'\s*=>\s*[^,\n]/", $block) ? null : '';
        };
        $type = $get('type');
        $prefix = $get('prefix');
        $path = $get('path');
        $name = $get('name');
        if ($type === null || $prefix === null || $path === null || $name === null || ($type !== '' && strtolower($type) !== 'file')) {
            return $cfg;
        }
        $cfg = array('readable' => true, 'prefix' => $prefix, 'path' => $path, 'name' => $name);
        return $cfg;
    }

    /**
     * Admin id of the PHP session sent with the request (read only, the session is never written or locked here); null
     * when no session can be read the site's way (no cookie, another session store, a session folder this code may not
     * look into), 0 for a session without an admin (or none at all).
     *
     * Only a session that already exists is read: PHP's file handler creates the file of any id it is asked to read, so a
     * visitor sending made-up session ids would otherwise plant session files (session fixation, inode spam). Strict mode
     * stays on, so PHP never adopts an unknown id either (other session stores check that themselves).
     */
    public static function sessionAdmin()
    {
        $cfg = self::sessionConfig();
        if (!$cfg['readable']) {
            return null;
        }
        $name = $cfg['name'] !== '' ? $cfg['name'] : session_name();
        $sid = isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) ? $_COOKIE[$name] : '';
        if ($sid === '' || !preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sid) || session_status() === PHP_SESSION_ACTIVE) {
            return null;
        }
        $handler = strtolower((string)ini_get('session.save_handler'));
        if ($handler === 'files' || $handler === '') {
            $file = self::sessionFile($cfg, $sid);
            if ($file === null) {
                return null;
            }
            if ($file === '') {
                return 0;
            }
        }
        $ok = false;
        try {
            @ini_set('session.use_cookies', '0');
            @ini_set('session.use_strict_mode', '1');
            if ($cfg['name'] !== '') {
                @session_name($cfg['name']);
            }
            if ($cfg['path'] !== '') {
                @session_save_path($cfg['path']);
            }
            session_id($sid);
            $ok = @session_start(array('read_and_close' => true));
        } catch (\Throwable $e) {
            $ok = false;
        }
        if (!$ok || !isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        $think = $cfg['prefix'] !== '' ? ($_SESSION[$cfg['prefix']] ?? null) : $_SESSION;
        $_SESSION = array();
        if (!is_array($think) || ($think['admin_auth'] ?? '') !== '1' || !is_array($think['admin_info'] ?? null)) {
            return 0;
        }
        return (int)($think['admin_info']['admin_id'] ?? 0);
    }

    /**
     * The file of session $sid in PHP's file handler ("N;MODE;/path" and sub-folder levels understood): the path when it
     * exists, '' when it does not, null when the session folder cannot be looked into here (open_basedir, permissions).
     */
    private static function sessionFile(array $cfg, $sid)
    {
        $path = $cfg['path'] !== '' ? (string)$cfg['path'] : (string)ini_get('session.save_path');
        $levels = 0;
        if (strpos($path, ';') !== false) {
            $parts = explode(';', $path);
            $levels = ctype_digit((string)$parts[0]) ? (int)$parts[0] : 0;
            $path = (string)end($parts);
        }
        if ($path === '') {
            $path = sys_get_temp_dir();
        }
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || !@is_dir($path)) {
            return null;
        }
        $sub = '';
        for ($i = 0; $i < $levels && $i < strlen($sid); $i++) {
            $sub .= $sid[$i] . '/';
        }
        $file = $path . '/' . $sub . 'sess_' . $sid;
        return @is_file($file) ? $file : '';
    }

    /** The client's address (the connection's own: a forwarded header could be anything) or 'cli'. */
    private static function clientAddr()
    {
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }
        $a = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return $a !== '' && filter_var($a, FILTER_VALIDATE_IP) ? $a : 'unknown';
    }

    /**
     * Checks the founder password against the database with the rules of the admin sign-in (legacy md5 or bcrypt). Wrong
     * passwords count in runtime/update/<$counter> and, with $countAddress (callers without the founder's session), in a
     * counter of the client's address; five in either pause the check for 15 minutes. The founder's own checks never read
     * or write the address counter: strangers behind the same address (a proxy, a CDN, NAT) cannot pause them. The counters
     * are read, checked and written under one lock, so parallel requests cannot try more passwords than that. Returns ''
     * when right, else the message key ('password_paused' or 'password_wrong').
     */
    public static function passwordCheck($raw, $counter = 'auth-fails.php', $countAddress = true, $pause = 900)
    {
        $dir = Util::dir();
        $file = $dir !== '' && preg_match('/^auth-fails[a-z0-9-]*\.php$/', $counter) ? $dir . $counter : '';
        $ipFile = $dir !== '' && $countAddress ? $dir . 'auth-fails-ip' . substr(hash('sha256', 'jcupd|' . self::clientAddr()), 0, 16) . '.php' : '';
        $lock = $dir !== '' ? @fopen($dir . 'auth-fails.lock', 'c') : false;
        if ($lock) {
            @flock($lock, LOCK_EX);
        }
        try {
            return self::passwordCheckLocked($raw, $file, $ipFile, max(900, (int)$pause));
        } finally {
            if ($lock) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function passwordCheckLocked($raw, $file, $ipFile, $pause = 900)
    {
        $load = function ($f) {
            $d = $f !== '' ? Util::loadData($f) : null;
            return is_array($d) ? $d + array('n' => 0, 'at' => 0) : array('n' => 0, 'at' => 0);
        };
        // the counter of the caller pauses for $pause seconds (an hour for strangers at a rollback that waits for the
        // founder), the address counter for 15 minutes
        $paused = function (array $d, $secs) {
            return (int)$d['n'] >= self::PASSWORD_TRIES && time() - (int)$d['at'] < $secs;
        };
        $fails = $load($file);
        $ipFails = $load($ipFile);
        if ($paused($fails, $pause) || $paused($ipFails, 900)) {
            return 'password_paused';
        }
        $ok = false;
        if (is_string($raw) && $raw !== '' && strlen($raw) <= 4096) {
            $pdo = Db::pdo();
            if ($pdo !== null) {
                try {
                    $st = $pdo->prepare('SELECT admin_pwd FROM ' . Db::quoteName(Db::prefix() . 'admin') . ' WHERE admin_id = 1');
                    $st->execute();
                    $stored = (string)$st->fetchColumn();
                    if (preg_match('/^[a-f0-9]{32}$/iD', $stored)) {
                        $ok = hash_equals(strtolower($stored), md5($raw));
                    } elseif (preg_match('/^\$2[aby]\$/', $stored)) {
                        $ok = strlen($raw) <= 72 && strpos($raw, "\0") === false && password_verify($raw, $stored);
                    }
                } catch (\Throwable $e) {
                    $ok = false;
                }
            }
        }
        if (!$ok) {
            foreach (array($file => $fails, $ipFile => $ipFails) as $f => $d) {
                if ($f !== '') {
                    Util::saveData($f, array('n' => ((int)$d['n'] >= self::PASSWORD_TRIES ? 0 : (int)$d['n']) + 1, 'at' => time()));
                }
            }
            return 'password_wrong';
        }
        foreach (array($file, $ipFile) as $f) {
            if ($f !== '' && is_file($f)) {
                @unlink($f);
            }
        }
        return '';
    }

    // ------------------------------------------------------------------ strings

    /** Language of the admin asking: the admin's language cookie, else the site's language, else the browser's. */
    public static function locale()
    {
        $pick = function ($tag) {
            $tag = strtolower(trim((string)$tag));
            if ($tag === '') {
                return '';
            }
            if (in_array($tag, self::LOCALES, true)) {
                return $tag;
            }
            if (strpos($tag, 'vi') === 0) {
                return 'vi-vn';
            }
            if (in_array($tag, array('zh-tw', 'zh-hk', 'zh-mo'), true) || strpos($tag, 'zh-hant') === 0) {
                return 'zh-tw';
            }
            if (strpos($tag, 'zh') === 0) {
                return 'zh-cn';
            }
            return strpos($tag, 'en') === 0 ? 'en-us' : '';
        };
        $c = isset($_COOKIE['javcms_admin_lang']) && is_string($_COOKIE['javcms_admin_lang']) ? $_COOKIE['javcms_admin_lang'] : '';
        if (in_array($c, self::LOCALES, true)) {
            return $c;
        }
        $site = $pick(Store::siteLang());
        if ($site !== '') {
            return $site;
        }
        foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
            $l = $pick(explode(';', $part)[0]);
            if ($l !== '') {
                return $l;
            }
        }
        return 'en-us';
    }

    /**
     * Page strings of $loc (key without "admin/ux/updates/"), English underneath: the frozen copy of the run
     * (engine-<run>/lang/<loc>.php, or lang.json next to the bridge library), else the site's language files.
     */
    public static function strings($loc)
    {
        $read = function ($l) {
            $out = array();
            $json = __DIR__ . '/lang.json';
            if (is_file($json)) {
                $d = json_decode((string)@file_get_contents($json), true);
                if (is_array($d) && is_array($d[$l] ?? null)) {
                    return $d[$l];
                }
            }
            foreach (array(__DIR__ . '/lang/' . $l . '.php', Util::abs('application/admin/lang/' . $l . '/51-updates.php')) as $f) {
                if (is_file($f)) {
                    $arr = include $f;
                    if (is_array($arr)) {
                        foreach ($arr as $k => $v) {
                            if (is_string($k) && strpos($k, 'admin/ux/updates/') === 0 && is_string($v)) {
                                $out[substr($k, strlen('admin/ux/updates/'))] = $v;
                            }
                        }
                        return $out;
                    }
                }
            }
            return $out;
        };
        $en = $read('en-us');
        return $loc === 'en-us' ? $en : array_merge($en, $read($loc));
    }

    // ------------------------------------------------------------------ the run's own progress page

    /**
     * The progress page of the active run, without the framework: what the run does, its log (to the founder), and the
     * way to continue it when nobody drives it. AJAX requests of held admin pages get a short JSON answer instead.
     */
    private static function page(array $cur, $mode)
    {
        $ajax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
        $loc = self::locale();
        $L = self::strings($loc);
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 5');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
            header('X-Robots-Tag: noindex, nofollow');
            header('X-Content-Type-Options: nosniff');
        }
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo Util::json(array('code' => 0, 'msg' => (string)($L['mp_ajax'] ?? 'The site is being updated. Try again in a few seconds.'), 'updating' => 1));
            return;
        }
        // an early run nobody continued for hours ends when its page is opened (no other housekeeping on a 1.2.0 site)
        if (Service::expireEarly()) {
            $cur = Store::current() ?: $cur;
        }
        $s = Store::load($cur['run']);
        $who = self::sessionAdmin();
        $state = 'waiting';
        if ($who !== null && $who > 0 && $who !== 1 && empty($cur['bridge'])) {
            $state = 'founder';
        } elseif ($s !== null && $s['status'] === 'running' && Store::driven($s)) {
            $state = 'driven';
        }
        // whether this visitor may use a password form here (vs4-1): the signed-in founder; on the bridge a file-proof
        // session; on a regular run a caller without the founder's session only where sessions cannot be read here (and
        // at a rollback that waits for the founder only in its first hour)
        $bridgeRun = !empty($cur['bridge']);
        $mayPassword = $who === 1 || ($bridgeRun && self::bridgeProof() !== '');
        if (!$mayPassword && !$bridgeRun && !self::sessionsReadable()) {
            $mayPassword = $s === null || $s['status'] !== 'attention' || time() - (int)($s['attention']['at'] ?? 0) <= self::ANON_ATTENTION_SECONDS;
        }
        $nonce = base64_encode(random_bytes(16));
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; style-src 'nonce-" . $nonce . "'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $self = (string)($_SERVER['SCRIPT_NAME'] ?? '/javcms.php');
        $dir = rtrim(str_replace('\\', '/', dirname($self)), '/.');
        $admin = $s !== null && preg_match('/^[A-Za-z0-9_.-]{1,60}\.php$/', (string)$s['admin']) ? (string)$s['admin'] : 'javcms.php';
        $data = array(
            // the details of a run (failure reasons, backup locations, the health probe) only for the signed-in founder
            'run' => $s !== null ? ($who === 1 ? Engine::result($s) : Engine::publicResult($s)) : null, 'state' => $state, 'mode' => $mode === 'install' ? 'install' : 'admin',
            'key' => !empty($cur['bridge']) ? 'javcms-bridge-run' : 'javcms-update-run', 'founder' => $who === 1, 'may_password' => $mayPassword,
            'urls' => array('runner' => $self . '?jcupd=', 'runner_alt' => $dir . '/index.php?jcupd=', 'updates' => $dir . '/' . $admin . '/admin/update/index.html',
                'admin' => $dir . '/' . $admin, 'site' => $dir . '/'),
            'log' => $who === 1 ? Store::readLog($cur['run'], 0, 400) : array(), 'lang' => $L,
        );
        $t = function ($k, array $v = array()) use ($L) {
            $text = (string)($L[$k] ?? $k);
            foreach ($v as $n => $x) {
                $text = str_replace('{' . $n . '}', (string)$x, $text);
            }
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $html = array('en-us' => 'en', 'vi-vn' => 'vi', 'zh-cn' => 'zh-Hans', 'zh-tw' => 'zh-Hant')[$loc];
        // the versions only for the signed-in founder (they tell a stranger which known problems the site has)
        $rb = $s !== null && ($s['kind'] === 'rollback' || $s['mode'] === 'rollback');
        // a rollback goes from the newer version back to the older one: a manual rollback runs from -> to, the rollback of
        // an update to -> from
        $lead = $s === null ? '' : ($who !== 1 ? $t($rb ? 'mp_rollback_anon' : 'mp_update_anon') : ($rb
            ? $t('mp_rollback', array('from' => $s['kind'] === 'rollback' ? $s['from'] : $s['to'], 'to' => $s['kind'] === 'rollback' ? $s['to'] : $s['from']))
            : $t('mp_update', array('from' => $s['from'], 'to' => $s['to']))));
        echo '<!doctype html><html lang="' . $html . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $t('mp_title') . '</title><style nonce="' . $nonce . '">' . self::css() . '</style></head><body>'
            . '<main class="wrap"><h1>' . $t('mp_title') . '</h1><p class="lead" id="lead">' . $lead . '</p>'
            . ($mode === 'install' ? '' : '<p class="hint" id="held">' . $t('mp_admin_held') . '</p>')
            . '<section class="card" aria-labelledby="run-h"><h2 id="run-h">' . $t('mp_progress') . '</h2>'
            . '<p id="state" class="state" role="status" aria-live="polite"></p>'
            . '<ol id="phases" class="phases"></ol><div class="bar" aria-hidden="true"><span id="bar"></span></div>'
            // the log below is the one live region of the progress (a second one would read every message twice)
            . '<p id="now" class="now"></p>'
            . '<form id="cont" class="cont" autocomplete="off" hidden>'
            // a rollback that waits for the founder: the hint names both ways on (the password field points to it), and the
            // tick that confirms the rollback was finished by hand comes before the field - it decides what the buttons and
            // Enter do (ux-6 CLICK-1, SR-HINT)
            . '<p id="cont-hint" class="hint" hidden></p>'
            . '<label class="chk" id="close-row" hidden><input type="checkbox" id="close-ok"> <span>' . $t('attention_close_tick') . '</span></label>'
            . '<label for="pwd">' . $t('password') . '</label>'
            . '<input id="pwd" type="password" maxlength="72" autocomplete="current-password" required>'
            . '<button type="submit" class="btn" id="cont-btn">' . $t('continue_btn') . '</button>'
            . '<button type="button" class="btn ghost" id="close-btn" hidden>' . $t('attention_close_btn') . '</button>'
            . '<p id="cont-msg" class="bad" role="alert"></p></form>'
            // the result is read out by the log above it (its last line): no second live region (ux-4 P3-13)
            . '<div id="result" class="result" hidden></div>'
            . '<h3>' . $t('log_title') . '</h3><div role="log" aria-live="polite"><ol id="log" class="log" tabindex="0" aria-label="' . $t('log_title') . '"></ol></div></section>'
            . '<p class="links"><a href="' . htmlspecialchars($data['urls']['site'], ENT_QUOTES, 'UTF-8') . '">' . $t('open_site') . '</a></p></main>'
            . '<script type="application/json" id="data">' . Util::jsonScript($data) . '</script>'
            . '<script nonce="' . $nonce . '">' . self::js() . '</script></body></html>';
    }

    private static function css()
    {
        return ':root{color-scheme:dark light;--bg:#0f1115;--card:#171a21;--text:#e6e8ee;--muted:#b4bac6;--line:#2a2f3a;--accent:#7ea2ff;--ok:#5ad08a;--bad:#ff8a80;--warn:#e3b341}'
            . '@media(prefers-color-scheme:light){:root{--bg:#f5f6f8;--card:#fff;--text:#1d2230;--muted:#4b5263;--line:#d9dde5;--accent:#2457d6;--ok:#167a3e;--bad:#b42318;--warn:#8a5a00}}'
            . '*{box-sizing:border-box}[hidden]{display:none!important}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans",sans-serif}'
            . '.wrap{max-width:860px;margin:0 auto;padding:24px 16px 48px}h1{font-size:23px;margin:0 0 6px}h2{font-size:18px;margin:0 0 10px}h3{font-size:15px;margin:18px 0 6px}'
            . '.lead{margin:0;font-weight:600}.hint{color:var(--muted);font-size:14px;margin:6px 0 0}.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:18px;margin:16px 0}'
            . '.state{margin:0 0 8px}.phases{display:flex;flex-wrap:wrap;gap:6px;padding:0;list-style:none;margin:8px 0}.phases li{font-size:12.5px;border:1px solid var(--line);border-radius:999px;padding:2px 10px;color:var(--muted)}'
            . '.phases li.done{color:var(--ok);border-color:var(--ok)}.phases li.now{color:#fff;background:#2457d6;border-color:#2457d6}.phases li.skip{text-decoration:line-through}'
            . '.phases li.fail{color:var(--bad);border-color:var(--bad);font-weight:600}'
            . '.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}'
            . '.bar{height:6px;background:var(--line);border-radius:3px;overflow:hidden;margin:8px 0}.bar span{display:block;height:100%;width:0;background:var(--accent);transition:width .3s}'
            . '.now{font-weight:600;overflow-wrap:anywhere}.cont{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0}.cont label{flex:1 1 100%;font-weight:600}'
            . '.cont input{font:inherit;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg);color:var(--text);min-width:220px;flex:0 1 300px}'
            . '.btn{display:inline-block;border:1px solid #2457d6;background:#2457d6;color:#fff;border-radius:8px;padding:9px 16px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}'
            . '.btn.ghost{background:transparent;color:var(--accent);border-color:var(--accent)}.btn[aria-disabled=true]{opacity:.55;cursor:not-allowed}.bad{color:var(--bad);margin:0;flex:1 1 100%}'
            . '.cont .hint{flex:1 1 100%;margin:0}'
            . '.result{border-left:4px solid var(--ok);padding:8px 12px;margin:10px 0;overflow-wrap:anywhere}.result.fail{border-color:var(--bad)}.result.warn{border-color:var(--warn)}.result p{margin:0 0 8px}.result .row{display:flex;flex-wrap:wrap;gap:8px}'
            . '.chk{display:flex;gap:8px;align-items:flex-start;flex:1 1 100%;font-weight:400}.chk input{margin-top:4px}'
            // the tick is no text field: the field rules of .cont input (also those for narrow screens) never size it (ux-5 P3-1)
            . '.cont .chk input{min-width:0;flex:none;width:auto;padding:0}'
            . '.log{max-height:300px;overflow:auto;font:12px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;background:var(--bg);padding:8px 8px 8px 28px;border-radius:6px;margin:0;overflow-wrap:anywhere}'
            . '.log li.warn{color:var(--warn)}.log li.error{color:var(--bad)}.log time{color:var(--muted);margin-right:8px}.links a{color:var(--accent)}'
            . '@media(max-width:480px){h1{font-size:20px}.card{padding:14px}.cont input{min-width:0;flex:1 1 100%}}';
    }

    /** Script of the progress page: drives the run with a saved or a new token (the protocol of the Updates page). */
    private static function js()
    {
        return <<<'JS'
(function () {
  'use strict';
  var D = JSON.parse(document.getElementById('data').textContent), L = D.lang || {};
  var $ = function (id) { return document.getElementById(id); };
  var token = '', seq = 0, running = false, fails = 0, probing = false, runner = D.urls.runner, reloadTimer = null;
  function fmt(t, v) {
    t = String(t == null ? '' : t);
    if (v) { Object.keys(v).forEach(function (k) { if (v[k] !== null && typeof v[k] === 'object') { return; } t = t.split('{' + k + '}').join(String(v[k] == null ? '' : v[k])); }); }
    return t;
  }
  function tr(k, v, f) { var t = L[k]; return fmt(t != null && t !== '' ? t : (f != null ? f : k), v); }
  // two pieces of a sentence: no space after a full-width stop or before a full-width bracket (Chinese text: ux-5 P3-7)
  function join2(a, b) {
    if (!a) { return b || ''; }
    if (!b) { return a; }
    return /[　-〿！-／：-＠]$/.test(a) || /^[　-〿（［｛]/.test(b) ? a + b : a + ' ' + b;
  }
  // the failed pages of a health check, each in the admin's language (with its own colon)
  function checksText(checks) {
    return (checks || []).map(function (c) { return join2(tr('hc_' + c.name, null, c.name) + tr('colon', null, ': ') + tr(c.k, c.v || {}, '?'), c.browser ? tr('hr_by_browser') : ''); }).join(tr('list_sep', null, '; '));
  }
  // message variables in the admin's language: <name>_k translates <name>, a phase id becomes its label, a reason
  // (why_k) or a list of failed pages (checks) becomes {why}, an upgrade step line carries its own translations
  function withKeys(v) {
    var out = {};
    Object.keys(v || {}).forEach(function (x) { out[x] = v[x]; });
    Object.keys(v || {}).forEach(function (x) {
      if (x !== 'why_k' && x.length > 2 && x.slice(-2) === '_k' && typeof v[x] === 'string' && v[x] !== '') {
        var base = x.slice(0, -2);
        out[base] = tr(v[x], v[base + '_v'] || {}, v[base]);
      }
    });
    if (typeof v.phase === 'string' && v.phase !== '') { out.phase = tr('ph_' + v.phase, null, v.phase); }
    if (v && v.why_k) { out.why = reasonText(v.why_k, v.why_v, v.why); }
    else if (v && Array.isArray(v.checks) && v.checks.length) { out.why = checksText(v.checks); }
    if (v && Array.isArray(v.health_checks) && v.health_checks.length) { out.health = checksText(v.health_checks); }
    if (v && v.line_t && L.locale_code && typeof v.line_t[L.locale_code] === 'string') { out.line = v.line_t[L.locale_code]; }
    return out;
  }
  function reasonText(k, v, m) {
    v = v || {};
    if (!k || L[k] == null) { return String(m || ''); }
    var vars = withKeys(v);
    if (Array.isArray(v.checks) && v.checks.length) { vars.list = checksText(v.checks); }
    return tr(k, vars, m);
  }
  function msgText(m) {
    if (!m) { return ''; }
    if (String(m.k).indexOf('phase_') === 0) { return tr('ph_' + String(m.k).slice(6), null, m.m); }
    return tr('m_' + m.k, withKeys(m.v), m.m);
  }
  function lineText(l) {
    if (!l.k) { return String(l.m || ''); }
    if (String(l.k).indexOf('e_') === 0) { return reasonText(l.k, l.v, l.m); }
    return tr(l.k, withKeys(l.v), l.m);
  }
  // done / now / failed / waiting per phase; a run that stopped marks the phases before its failed one done, a rollback
  // that waits for the founder (or was closed by the founder) the phase where it stopped
  function phases(list, cur, status, skipped, failedAt) {
    var ol = $('phases'), seen = false;
    var stopAt = (status === 'failed' || status === 'cancelled') ? (failedAt || cur) : ((status === 'attention' || status === 'closed') ? cur : '');
    ol.textContent = '';
    (list || []).forEach(function (p) {
      var li = document.createElement('li'), st = '';
      if (skipped && skipped.indexOf(p) >= 0) { st = 'skip'; }
      else if (stopAt) { if (p === stopAt) { st = status === 'failed' ? 'fail' : 'stop'; seen = true; } else if (!seen) { st = 'done'; } }
      else if (status && status !== 'running') { st = (status === 'done' || status === 'rolled_back') ? 'done' : ''; }
      else if (p === cur) { st = 'now'; li.setAttribute('aria-current', 'step'); seen = true; }
      else if (!seen) { st = 'done'; }
      li.className = st === 'stop' ? 'fail' : st;
      li.appendChild(document.createTextNode(tr('ph_' + p, null, p)));
      var sr = document.createElement('span');
      sr.className = 'sr';
      sr.textContent = ' (' + tr(st === 'done' ? 'phase_done' : (st === 'now' ? 'phase_now' : (st === 'skip' ? 'phase_skipped' : (st === 'fail' || st === 'stop' ? 'phase_failed' : 'phase_wait')))) + ')';
      li.appendChild(sr);
      ol.appendChild(li);
    });
  }
  function addLog(lines) {
    var ol = $('log');
    (lines || []).forEach(function (l) {
      var li = document.createElement('li');
      li.className = l.l === 'warn' ? 'warn' : (l.l === 'error' ? 'error' : '');
      var t = document.createElement('time'), d = new Date((l.t || 0) * 1000);
      t.textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2) + ':' + ('0' + d.getSeconds()).slice(-2);
      li.appendChild(t); li.appendChild(document.createTextNode(lineText(l)));
      ol.appendChild(li);
    });
    ol.scrollTop = ol.scrollHeight;
  }
  // the bar shows the whole run: the phases done, plus the share of the phase in progress; a run that stopped keeps the
  // bar where it stopped
  function overall(r) {
    if (r.status === 'attention' || r.status === 'closed') {
      var at = (r.phases || []).indexOf(r.phase);
      return at < 0 ? 3 : Math.max(3, Math.round(100 * at / Math.max(1, (r.phases || []).length)));
    }
    if (r.status && r.status !== 'running') { return 100; }
    var list = r.phases || [], i = list.indexOf(r.phase), pr = r.progress || {};
    if (i < 0 || !list.length) { return 3; }
    var part = pr.total ? Math.min(pr.done, pr.total) / pr.total : 0;
    return Math.max(3, Math.round(100 * (i + part) / list.length));
  }
  function render(r) {
    if (!r) { return; }
    phases(r.phases, r.phase, r.status, r.skipped, r.error_phase);
    // a run the founder closed: its phases ("Maintenance off" waiting ...) no longer say anything true (ux-5 P3-4)
    $('phases').hidden = r.status === 'closed';
    $('bar').parentNode.hidden = r.status === 'closed';
    $('bar').style.width = overall(r) + '%';
    $('now').textContent = r.health_probe ? tr('checking_site') : msgText(r.msg);
  }
  // true when version v is older than 1.2.1, the first version with the Updates page (1.2.1-dev and the like count as 1.2.1)
  function beforeUpdater(v) {
    var m = /^(\d+)\.(\d+)\.(\d+)/.exec(String(v || ''));
    if (!m) { return false; }
    var a = [+m[1], +m[2], +m[3]], b = [1, 2, 1];
    for (var i = 0; i < 3; i++) { if (a[i] !== b[i]) { return a[i] < b[i]; } }
    return false;
  }
  function finish(r) {
    running = false;
    try { sessionStorage.removeItem(D.key); } catch (e) { /* ignore */ }
    render(r);
    // the result box below says the last message: the bold line does not say it a second time (ux-5 P3-2)
    $('now').textContent = '';
    $('cont').hidden = true;
    if (r.status === 'attention') { attention(r); return; }
    closeUi(false);
    var ok = r.status === 'done' || (r.status === 'rolled_back' && r.kind === 'rollback');
    var title = r.status === 'done' ? tr('done_title') : (r.status === 'rolled_back' ? (r.kind === 'rollback' ? tr('done_title') : tr('rolled_back_title'))
      : (r.status === 'cancelled' ? tr('cancelled_heading') : (r.status === 'closed' ? tr('closed_heading') : tr('failed_title'))));
    var box = $('result'), p = document.createElement('p');
    box.textContent = '';
    box.hidden = false;
    box.className = 'result' + (ok ? '' : (r.status === 'closed' ? ' warn' : ' fail'));
    // the lead names the result (its title): the box holds the message alone (ux-6 BRIDGE-DUP)
    p.textContent = msgText(r.msg) || title;
    box.appendChild(p);
    // the version left behind may predate the Updates page (a rollback to 1.2.0 after the bridge): its admin has no
    // Updates page, and the update bridge is install.php again
    var older = beforeUpdater(r.after);
    if (older) {
      var n = document.createElement('p');
      n.textContent = tr('no_updates_page', {v: r.after});
      box.appendChild(n);
    }
    var row = document.createElement('p'), a = document.createElement('a');
    row.className = 'row';
    a.className = 'btn';
    var toUpdates = !older && (r.status === 'done' || r.status === 'closed' || r.kind === 'rollback');
    a.href = toUpdates ? D.urls.updates : D.urls.admin;
    // the admin opens as a whole page, never inside the frame this page may have replaced
    a.target = '_top';
    a.textContent = tr(toUpdates ? 'mp_open_updates' : 'admin_home');
    row.appendChild(a);
    box.appendChild(row);
    $('state').textContent = '';
    // the end replaces what was said while it ran: the lead names the result, the hint about waiting admin pages goes;
    // the keyboard (and a screen reader) lands on it, as on the Updates page (ux-5 P3-2)
    $('lead').textContent = title;
    if ($('held')) { $('held').hidden = true; }
    $('lead').setAttribute('tabindex', '-1');
    $('lead').focus();
  }
  // "Close this run" (a rollback that waits for the founder): the hint, the tick and the button of the password form; the
  // password field is described by the hint while they show (ux-6 SR-HINT)
  function closeUi(on) {
    $('close-row').hidden = !on;
    $('close-btn').hidden = !on;
    $('cont-hint').hidden = !on;
    $('cont-hint').textContent = on ? tr('attention_retry_hint') : '';
    if (on) { $('pwd').setAttribute('aria-describedby', 'cont-hint'); } else { $('pwd').removeAttribute('aria-describedby'); }
    if (!on) { $('close-ok').checked = false; }
    syncRetry();
  }
  // "I finished the rollback by hand" ticked: "Try the rollback again" is off - a click on it would contradict the tick -
  // and Enter in the password field closes the run; unticked, both try the rollback again (ux-6 CLICK-1)
  function retryOff() { return $('cont').getAttribute('data-act') === 'retry' && !$('close-row').hidden && $('close-ok').checked; }
  function syncRetry() {
    var b = $('cont-btn');
    if (b.getAttribute('aria-busy') === 'true') { return; }
    if (retryOff()) { b.setAttribute('aria-disabled', 'true'); } else { b.removeAttribute('aria-disabled'); }
  }
  // a rollback that could not finish waits for the founder: what is where, how to finish by hand (the steps before the
  // form), "Try the rollback again" and "Close this run"; the heading is the lead above, the box holds the message
  function attention(r) {
    var box = $('result'), at = r.attention || {}, f = $('cont'), older = beforeUpdater(r.after);
    box.textContent = '';
    box.hidden = false;
    box.className = 'result fail';
    var p = document.createElement('p');
    p.textContent = msgText(r.msg);
    box.appendChild(p);
    var flag = at.flag || 'runtime/update/maintenance.php';
    if (at.guide) {
      [tr('attention_steps2', {guide: at.guide, folder: at.folder || '', flag: flag}),
        at.db ? tr('attention_db', {db: at.db, list: (at.db_tables || []).join(tr('list_sep', null, '; '))}) : '',
        // a version without the Updates page (a rollback through the bridge) closes the run on install.php (e2e-5 P3-3);
        // while the steps name database tables nothing closes the run by itself (e2e-6 P3-3)
        at.flag_on === false ? tr(older ? 'attention_flag_gone_bridge' : (at.db ? 'attention_flag_gone_db' : 'attention_flag_gone'))
          : tr(older ? 'attention_end_bridge' : (at.db ? 'attention_end_db' : 'attention_end'), {flag: flag})].forEach(function (text) {
        if (!text) { return; }
        var s = document.createElement('p');
        s.textContent = text;
        box.appendChild(s);
      });
    }
    $('lead').textContent = tr('needs_attention_heading');
    $('state').textContent = '';
    // "Admin pages wait ... again as soon as it is done" is not true while the rollback waits for the founder
    if ($('held')) { $('held').hidden = true; }
    box.parentNode.insertBefore(f, box.nextSibling);
    if (!D.may_password) {
      var n = document.createElement('p');
      n.textContent = tr('attention_founder_needed');
      box.appendChild(n);
      f.hidden = true;
      return;
    }
    f.hidden = false;
    f.setAttribute('data-act', 'retry');
    $('cont-btn').textContent = tr('attention_retry_btn');
    closeUi(true);
    $('pwd').focus();
  }
  function probeUrl(url, p, cred) {
    var t0 = Date.now();
    return fetch(url, {credentials: cred || 'same-origin', cache: 'no-store', redirect: 'follow', headers: {'X-Javcms-Update-Probe': p.probe}})
      .then(function (r) { return r.text().then(function (t) { return {status: r.status, text: t, ms: Date.now() - t0}; }); },
        function () { return {status: 0, text: '', ms: Date.now() - t0}; });
  }
  function markerIn(text, p) {
    var low = String(text || '').toLowerCase();
    for (var i = 0; i < (p.markers || []).length; i++) { if (low.indexOf(String(p.markers[i]).toLowerCase()) >= 0) { return p.markers[i]; } }
    return '';
  }
  function pickLinks(html, base) {
    var out = {list: '', detail: ''}, origin, m, re = /href\s*=\s*["']([^"'\s<>]+)["']/gi;
    try { origin = new URL(base, location.href).origin; } catch (e) { return out; }
    var detail = ['/watch/', '/detail/', 'vod/detail', '/vod/play', 'vodplay', '/video/', '/play/'];
    var list = ['/category/', '/show/', '/type/', '/label/', '/list/', 'vod/type', 'vod/show', '/tag/', '/genre/'];
    while ((m = re.exec(String(html || ''))) !== null && !(out.list && out.detail)) {
      var u;
      try { u = new URL(m[1].replace(/&amp;/g, '&'), new URL(base, location.href)); } catch (e) { continue; }
      if (u.origin !== origin) { continue; }
      var low = u.href.toLowerCase();
      if (!out.detail && detail.some(function (d) { return low.indexOf(d) >= 0; })) { out.detail = u.href; }
      if (!out.list && list.some(function (d) { return low.indexOf(d) >= 0; })) { out.list = u.href; }
    }
    return out;
  }
  function browserHealth(p) {
    var report = [], base = String(p.base || '/');
    var bust = function (u) { return u + (u.indexOf('?') < 0 ? '?' : '&') + 'jcupd_probe=' + Math.random().toString(36).slice(2, 8); };
    $('now').textContent = tr('checking_site');
    return probeUrl(bust(base), p).then(function (home) {
      report.push({name: 'home', status: home.status, marker: home.status === 200 ? markerIn(home.text, p) : '', path: '/', ms: home.ms});
      var links = home.status === 200 ? pickLinks(home.text, base) : {list: '', detail: ''}, chain = Promise.resolve();
      ['list', 'detail'].forEach(function (kind) {
        chain = chain.then(function () {
          if (!links[kind]) { report.push({name: kind, skipped: 1, status: 0, path: ''}); return null; }
          return probeUrl(bust(links[kind]), p).then(function (r) {
            report.push({name: kind, status: r.status, marker: r.status === 200 ? markerIn(r.text, p) : '', path: links[kind].replace(/^https?:\/\/[^/]+/, ''), ms: r.ms});
          });
        });
      });
      return chain;
    }).then(function () {
      return probeUrl(base + p.admin + '/admin/index/login.html', p, 'omit').then(function (r) {
        return r.status === 401 || r.status === 403 ? probeUrl(base + p.admin + '/admin/index/login.html', p) : r;
      }).then(function (r) {
        report.push({name: 'admin', status: r.status, marker: r.status === 200 ? markerIn(r.text, p) : '', form: r.text.indexOf('admin_name') >= 0 ? 1 : 0, path: p.admin, ms: r.ms});
      });
    }).then(function () {
      var body = new URLSearchParams();
      body.append('report', JSON.stringify(report));
      return fetch(runner + 'health', {method: 'POST', credentials: 'same-origin', body: body, headers: {'X-Javcms-Update-Token': token, 'Accept': 'application/json'}})
        .then(function (r) { return r.text(); });
    });
  }
  function loop() {
    if (!running) { return; }
    var body = new URLSearchParams();
    body.append('seq', String(seq));
    var ctl = window.AbortController ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, 75000) : null;
    fetch(runner + 'step', {method: 'POST', credentials: 'same-origin', body: body, signal: ctl ? ctl.signal : undefined,
      headers: {'X-Javcms-Update-Token': token, 'Accept': 'application/json'}})
      .then(function (resp) {
        if (timer) { clearTimeout(timer); }
        var type = resp.headers.get('Content-Type') || '';
        if (type.indexOf('json') < 0) { var nj = new Error('not the runner'); nj.notRunner = true; nj.status = resp.status; throw nj; }
        if (resp.status === 403) { return resp.json().then(function (j) { var e = new Error(j.error || 'token'); e.token = true; throw e; }); }
        return resp.json();
      })
      .then(function (r) {
        fails = 0;
        if (r.log) { addLog(r.log); }
        if (typeof r.seq === 'number') { seq = r.seq; }
        if (r.ok === false && r.status === 'none') {
          running = false;
          try { sessionStorage.removeItem(D.key); } catch (e) { /* ignore */ }
          setTimeout(function () { location.href = D.urls.updates; }, 800);
          return;
        }
        render(r);
        if (r.status === 'running' && r.health_probe && !probing) {
          probing = true;
          browserHealth(r.health_probe).then(function () { probing = false; setTimeout(loop, 200); }, function () { probing = false; setTimeout(loop, 1000); });
          return;
        }
        if (r.status === 'running') { setTimeout(loop, Math.max(150, r.wait || 0)); } else { finish(r); }
      })
      .catch(function (e) {
        if (timer) { clearTimeout(timer); }
        if (e && e.token) { running = false; askPassword(); return; }
        fails++;
        if (e && e.notRunner) { runner = runner === D.urls.runner ? D.urls.runner_alt : D.urls.runner; }
        $('now').textContent = tr(e && e.status >= 500 ? 'step_error' : 'network_retry', {n: fails});
        setTimeout(loop, Math.min(15000, (e && e.notRunner ? 300 : 1000) * fails));
      });
  }
  function start(tok) {
    token = tok;
    running = true;
    if (reloadTimer) { clearTimeout(reloadTimer); reloadTimer = null; }
    $('cont').hidden = true;
    $('state').textContent = tr('mp_driving');
    try { sessionStorage.setItem(D.key, JSON.stringify({run: D.run.run, token: tok})); } catch (e) { /* this tab only */ }
    loop();
  }
  function askPassword() {
    if (!D.may_password) {
      // only the signed-in founder may continue here (where this host lets the page read the founder's sign-in)
      $('state').textContent = tr('mp_founder_needed');
      $('cont').hidden = true;
      return;
    }
    $('state').textContent = tr('mp_waiting');
    $('cont').hidden = false;
    $('pwd').focus();
  }
  $('close-btn').addEventListener('click', function () {
    var pwd = $('pwd'), btn = $('close-btn');
    if (btn.getAttribute('aria-disabled') === 'true') { return; }
    if (!$('close-ok').checked) { $('cont-msg').textContent = tr('attention_close_need_tick'); $('close-ok').focus(); return; }
    if (!pwd.value) { $('cont-msg').textContent = tr('password_needed'); pwd.focus(); return; }
    btn.setAttribute('aria-disabled', 'true');
    var body = new URLSearchParams();
    body.append('password', pwd.value);
    body.append('seq', String(seq));
    fetch(runner + 'close', {method: 'POST', credentials: 'same-origin', body: body, headers: {'X-Javcms-Update-Continue': '1', 'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        pwd.value = '';
        btn.removeAttribute('aria-disabled');
        if (!j || j.status !== 'closed') {
          $('cont-msg').textContent = j && j.k ? tr(j.k, null, j.error) : tr('failed');
          pwd.focus();
          return;
        }
        $('cont-msg').textContent = '';
        if (j.log) { addLog(j.log); }
        if (typeof j.seq === 'number') { seq = j.seq; }
        $('cont').removeAttribute('data-act');
        // finish() puts the focus on the lead, which now names the result - as the Updates page does after its close (a
        // screen reader hears "Run closed" instead of a link name) (ux-5 P3-2)
        finish(j);
      }, function () { btn.removeAttribute('aria-disabled'); $('cont-msg').textContent = tr('failed'); });
  });
  // Enter in the password field: with "I finished the rollback by hand" ticked it means "Close this run" (ux-5 ENTER-1),
  // handled here, before the form's own submission - which reports the default button, "Try the rollback again", either way
  $('pwd').addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter' || ev.isComposing || ev.keyCode === 229) { return; }
    if (retryOff()) { ev.preventDefault(); $('close-btn').click(); }
  });
  $('close-ok').addEventListener('change', function () {
    syncRetry();
    $('cont-msg').textContent = retryOff() ? tr('attention_retry_off') : '';
  });
  $('cont').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var pwd = $('pwd'), btn = $('cont-btn');
    if (btn.getAttribute('aria-busy') === 'true') { return; }
    // "Try the rollback again" pressed (mouse or keyboard) while "I finished the rollback by hand" is ticked: neither a
    // retry nor a close - the founder unticks the box or presses "Close this run" (ux-6 CLICK-1: a click once closed the run)
    if (retryOff()) { $('cont-msg').textContent = tr('attention_retry_off'); $('close-ok').focus(); return; }
    if (!pwd.value) { pwd.focus(); return; }
    btn.setAttribute('aria-disabled', 'true');
    btn.setAttribute('aria-busy', 'true');
    var body = new URLSearchParams();
    body.append('password', pwd.value);
    body.append('seq', String(seq));
    var act = $('cont').getAttribute('data-act') === 'retry' ? 'retry' : 'continue';
    fetch(runner + act, {method: 'POST', credentials: 'same-origin', body: body, headers: {'X-Javcms-Update-Continue': '1', 'Accept': 'application/json'}})
      .then(function (r) { return r.json(); })
      .then(function (j) {
        pwd.value = '';
        btn.removeAttribute('aria-disabled');
        btn.removeAttribute('aria-busy');
        syncRetry();
        if (!j || !j.token) {
          $('cont-msg').textContent = j && j.k ? tr(j.k, null, j.error) : tr('failed');
          if (j && j.k === 'continue_not_now') { reloadTimer = setTimeout(function () { location.reload(); }, 5000); }
          pwd.focus();
          return;
        }
        $('cont-msg').textContent = '';
        // a rollback tried again says so in the lead (not "needs your attention" while it runs: ux-5 P3-2)
        if (act === 'retry') {
          $('lead').textContent = D.founder ? tr('mp_rollback', {from: j.kind === 'rollback' ? j.from : j.to, to: j.kind === 'rollback' ? j.to : j.from}) : tr('mp_rollback_anon');
        }
        $('cont').removeAttribute('data-act');
        $('cont-btn').textContent = tr('continue_btn');
        closeUi(false);
        $('result').hidden = true;
        if (j.log) { addLog(j.log); }
        if (typeof j.seq === 'number') { seq = j.seq; }
        render(j);
        start(j.token);
      }, function () { btn.removeAttribute('aria-disabled'); btn.removeAttribute('aria-busy'); syncRetry(); $('cont-msg').textContent = tr('failed'); });
  });
  window.addEventListener('beforeunload', function (ev) { if (running) { ev.preventDefault(); ev.returnValue = tr('leave_warning'); return ev.returnValue; } });

  // the log this page arrived with is shown once: the next step continues after it
  addLog(D.log);
  seq = (D.log || []).length;
  render(D.run);
  if (!D.run) { $('state').textContent = tr('m_missing'); return; }
  if (D.run.status !== 'running') { finish(D.run); return; }
  var saved = null;
  try { saved = JSON.parse(sessionStorage.getItem(D.key) || 'null'); } catch (e) { saved = null; }
  if (saved && saved.run === D.run.run && saved.token) { start(saved.token); return; }
  if (D.state === 'founder') { $('state').textContent = tr('founder_only'); return; }
  if (D.state === 'driven') {
    $('state').textContent = tr('mp_driven');
    reloadTimer = setTimeout(function () { location.reload(); }, 5000);
    return;
  }
  askPassword();
})();
JS;
    }
}
