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
/*
 * JavCMS one-click updater on the command line (1.2.1). The same engine as Admins & backup > Updates.
 *
 *   php tools/update.php --check                       look for a newer release (GitHub Releases of avdbapi-com/javcms)
 *   php tools/update.php --apply [--yes]               update to the newest release (asks before it starts without --yes)
 *        [--keep-modified]                             leave release files this site changed as they are (not recommended)
 *        [--url=https://example.com/]                  the site's own address for the health check and the OPcache reset
 *                                                      (default: site_url of the settings, https first)
 *   php tools/update.php --rollback [--yes] [--db-restore]   back to the version before the last update (within 7 days)
 *   php tools/update.php --status                      the running run, the last runs
 *   php tools/update.php --resume                      continue a run that was interrupted (closed terminal, crash); a
 *                                                      rollback that needs attention is tried again
 *   php tools/update.php --close-attention [--yes]     close a rollback that needs attention after you finished it by
 *                                                      hand (runtime/update/runs/<run>/attention.txt lists the steps)
 *   php tools/update.php --cancel                      cancel a run that has not changed any site file yet
 *   php tools/update.php --help                        this text
 *   Common: [--root=<site folder>] [--json]
 *
 * After the files are replaced the update checks the home, a list, a detail and the admin sign-in page through the
 * site's own address. The command line can do that only when the site answers this server: --apply and --rollback
 * refuse to start (exit 3) when it does not, or when the site refuses this server's requests (HTTP basic auth on the
 * admin, an IP allow-list, a CDN challenge) - then pass --url= with an address that reaches this server directly (for
 * example http://127.0.0.1/ when the web server answers it for this site), or update from the Updates page in a browser
 * (the browser checks the pages there). --check says which applies.
 *
 * The command line can reset only its own OPcache: it asks the web server through the maintenance gate (token in a
 * request header). Before any file changes the gate tells how the web server's OPcache works: one that never re-reads
 * files (validate_timestamps=0) and may not be reset by this site (opcache.restrict_api, disabled functions) stops the
 * update right there (exit 3, nothing changed) - the new files would never be used until PHP restarts. When the web
 * server keeps running cached copies of the old code after the swap anyway, the update rolls back (exit 5), and a
 * rollback says when the web server still holds the newer entry scripts: then restart PHP (PHP-FPM, Apache, or "clear
 * OPcache" in the hosting panel). Run it as the user the web server runs PHP as: files it writes must stay writable for
 * the Updates page. The long upgrade builds of a big catalogue are left to housekeeping, like the admin page does; run
 * "php tools/upgrade.php" afterwards to do them at once.
 * Exit codes: 0 done / nothing to do, 1 failed or rolled back, 2 usage, 3 blocked by a requirement (nothing changed), 4
 * another run (or another check) is active at this moment: try again when it has ended (--status shows it; a rollback
 * that needs attention waits for --resume or --close-attention), 5 the web server keeps running cached PHP code: restart
 * PHP (the update was rolled back, or a rollback - also one finished by hand and closed with --close-attention - is on disk
 * but not yet in the web server's cache).
 */
if (PHP_VERSION_ID < 70400) {
    fwrite(STDERR, "PHP 7.4 or newer is required (this is " . PHP_VERSION . ")\n");
    exit(2);
}
$opt = array('check' => false, 'apply' => false, 'rollback' => false, 'status' => false, 'resume' => false, 'cancel' => false, 'help' => false,
    'close-attention' => false, 'yes' => false, 'keep-modified' => false, 'db-restore' => false, 'url' => '', 'root' => dirname(__DIR__), 'json' => false);
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/D', $arg, $m) || !array_key_exists($m[1], $opt)) {
        fwrite(STDERR, "Unknown option: {$arg} (php tools/update.php --help)\n");
        exit(2);
    }
    $opt[$m[1]] = isset($m[2]) ? $m[2] : true;
}
if ($opt['help']) {
    $src = (string)file_get_contents(__FILE__);
    // the block that documents the options (a release puts its licence notice in an earlier comment block)
    if (preg_match_all('#/\*\n(.*?)\n \*/#s', $src, $hm)) {
        foreach ($hm[1] as $block) {
            if (strpos($block, 'php tools/update.php --check') !== false) {
                echo preg_replace('/^ \* ?/m', '', $block), PHP_EOL;
                break;
            }
        }
    }
    exit(0);
}
$root = realpath((string)$opt['root']);
if ($root === false || !is_file($root . '/application/common/util/updater/Engine.php') || !is_file($root . '/application/database.php')) {
    fwrite(STDERR, "Not a JavCMS 1.2.1+ site folder: {$opt['root']}\n");
    exit(2);
}
if (!is_file($root . '/application/data/install/install.lock')) {
    fwrite(STDERR, "This site is not installed yet: open install.php first.\n");
    exit(2);
}
// PHP 8 removes a function named in disable_functions: calling it is a fatal error ("Call to undefined function", which
// @ does not hide), and shared hosts often disable these two; the web engine calls them through Util::call for the same
// reason (UPDATER-VERIFY-HOSTING-5 VH5-2)
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}
if (function_exists('ignore_user_abort')) {
    @ignore_user_abort(true);
}
foreach (array('Util', 'Config', 'Paths', 'Http', 'Keys', 'Signature', 'Manifest', 'Zip', 'Source', 'Store', 'Db', 'Planner', 'Backup',
             'Swapper', 'Health', 'Engine', 'Runner', 'Notes', 'Service') as $cls) {
    require_once $root . '/application/common/util/updater/' . $cls . '.php';
}

use app\common\util\updater\Config;
use app\common\util\updater\Health;
use app\common\util\updater\Service;
use app\common\util\updater\Source;
use app\common\util\updater\Store;
use app\common\util\updater\Util;

Util::setRoot($root);
$json = (bool)$opt['json'];
$out = array();
$say = function ($line) use ($json) {
    if (!$json) {
        echo $line, PHP_EOL;
    }
};
$done = function ($code) use (&$out, $json) {
    if ($json) {
        echo Util::json($out), PHP_EOL;
    }
    exit($code);
};

// The files the run writes must stay writable for the web server: a run as root (or as another user than the owner of
// the site's files) would leave files the Updates page cannot replace later.
if (function_exists('posix_geteuid') && function_exists('fileowner')) {
    $me = posix_geteuid();
    $owner = @fileowner($root . '/index.php');
    if ($owner !== false && $me !== $owner) {
        $name = function ($uid) {
            $p = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
            return is_array($p) ? $p['name'] : (string)$uid;
        };
        $say('Warning: you run this as ' . $name($me) . ', the site\'s files belong to ' . $name($owner) . '. Files the update writes would belong to '
            . $name($me) . '; run it as ' . $name($owner) . ' (for example: sudo -u ' . $name($owner) . ' php tools/update.php ...).');
        if ($me === 0 && ($opt['apply'] || $opt['rollback'] || $opt['resume'] || $opt['close-attention']) && !$opt['yes']) {
            $say('Not started as root without --yes.');
            $done(3);
        }
    }
}

/**
 * The site's own address for the health check: --url, else site_url of the settings (https first, then http). Returns
 * ['base', 'ok' (the server reaches the site and the site lets it check the pages), 'why'].
 */
$siteBase = function () use ($opt) {
    $cands = array();
    if ($opt['url'] !== '' && preg_match('#^https?://[^/\s]+/?#i', (string)$opt['url'])) {
        $cands[] = rtrim((string)$opt['url'], '/') . '/';
    } else {
        $raw = @file_get_contents(Util::abs('application/extra/maccms.php'));
        $host = '';
        if (is_string($raw) && preg_match("/'site_url'\s*=>\s*'([^']{1,200})'/", $raw, $m)) {
            $host = trim(preg_replace('#^https?://#i', '', $m[1]), '/');
        }
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            return array('base' => '', 'ok' => false, 'why' => 'the site address is unknown (no --url and no site_url in the settings)');
        }
        $cands[] = 'https://' . $host . '/';
        $cands[] = 'http://' . $host . '/';
    }
    $last = '';
    foreach ($cands as $base) {
        $home = Health::fetch($base, '', microtime(true) + 8);
        if ($home['status'] === 0) {
            $last = $base . ' does not answer from this server (' . ($home['error'] !== '' ? $home['error'] : 'no answer') . ')';
            continue;
        }
        $admin = \app\common\util\updater\Planner::renamedAdminEntry() ?: 'javcms.php';
        $login = Health::fetch($base . $admin . '/admin/index/login.html', '', microtime(true) + 8);
        foreach (array('home page' => $home, 'admin sign-in page' => $login) as $what => $r) {
            if (in_array($r['status'], Health::REFUSED, true)) {
                return array('base' => $base, 'ok' => false,
                    'why' => 'the ' . $what . ' refuses this server (HTTP ' . $r['status'] . ': a password, an IP allow-list or a CDN / firewall in front of the site)');
            }
        }
        return array('base' => $base, 'ok' => true, 'why' => '');
    }
    return array('base' => '', 'ok' => false, 'why' => $last);
};
$explainHealth = function (array $b) use ($say) {
    $say('The update cannot check the site from this server: ' . $b['why'] . '.');
    $say('  Use --url= with an address that reaches this web server directly, or update from Admins & backup > Updates in a');
    $say('  browser (the browser checks the pages there).');
};

/** The run's own last message (English), never an empty reason. */
$runMessage = function ($r) {
    $m = is_array($r) && is_array($r['msg'] ?? null) ? (string)($r['msg']['m'] ?? '') : '';
    if ($m === '') {
        $m = is_array($r) ? (string)($r['error'] ?? '') : '';
    }
    return $m !== '' ? $m : 'unknown';
};
/** What the web server's PHP cache needs after a run (rollbacks of the command line): advice lines, and true for exit 5. */
$webCacheAdvice = function ($r) use ($say) {
    $wc = is_array($r) ? (string)($r['web_cache'] ?? '') : '';
    if (is_array($r) && (string)($r['error_msg']['k'] ?? '') === 'e_opcache_blocked') {
        // stopped before any change (exit 3 by the caller): the web server's cache would never take the new files
        $say('The web server\'s PHP cache (OPcache) never re-reads changed files here, and this site may not reset it (opcache.restrict_api or disabled');
        $say('  functions). Nothing was changed. Ask your host to allow opcache_reset for this site (or to set opcache.validate_timestamps=1), or');
        $say('  update by hand and restart PHP afterwards.');
        return false;
    }
    if (is_array($r) && in_array((string)($r['error_msg']['k'] ?? ''), array('e_stale_web', 'e_gate_missing'), true)) {
        $say('The web server keeps running its cached copy of the old code (OPcache never re-reads files there and may not be reset): restart PHP');
        $say('  (PHP-FPM, Apache, or "clear OPcache" in the hosting panel), then run the update again - or update from Admins & backup > Updates in a browser.');
        return true;
    }
    if ($wc === 'restart') {
        $say('The files are back on disk, but the web server keeps its cached copies of the newer entry scripts until PHP restarts: restart PHP now');
        $say('  (PHP-FPM, Apache, or "clear OPcache" in the hosting panel).');
        return true;
    }
    if ($wc === 'unreachable') {
        $say('The web server could not be asked to reset its PHP cache: if pages still show the newer version, restart PHP (or clear OPcache in the hosting panel).');
    }
    return false;
};

/**
 * The engine class of run $id: its frozen copy (taken when the run started: consistent while the site's own files are half
 * old, half new), else the site's own.
 */
$engineOf = function ($id) {
    $ok = preg_match('/^[0-9a-f]{16}$/', (string)$id) === 1;
    $boot = Util::root() . 'runtime/update/engine-' . $id . '/bootstrap.php';
    $lib = Util::root() . 'runtime/update/engine-' . $id . '/bridge-lib.php';
    if ($ok && is_file($boot)) {
        require_once $boot;
        return '\\javcms_upd_' . $id . '\\Engine';
    }
    if ($ok && is_file($lib)) {
        if (!defined('JAVCMS_UPD_LIB')) {
            define('JAVCMS_UPD_LIB', 1);
        }
        require_once $lib;
        return '\\javcms_upd_bridge\\Engine';
    }
    return '\\app\\common\\util\\updater\\Engine';
};

/** The lines of a rollback that waits for the founder (attention): where the hand procedure is, and the two ways on. */
$attentionLines = function (array $st) use ($say) {
    $at = (array)($st['attention'] ?? array());
    if ((string)($at['guide'] ?? '') !== '') {
        $say('  Hand procedure (every file to copy back or delete' . ((string)($at['db'] ?? '') !== '' ? ', the database tables to restore' : '') . '): ' . $at['guide']);
    }
    // read here (not through a newer class method: the site's own updater files may be half restored right now)
    $m = Store::maintenance();
    $flagOn = is_array($m) && (string)($m['run'] ?? '') === (string)$st['id'];
    $say('  File backup: ' . (string)($at['folder'] ?? '?') . ((string)($at['db'] ?? '') !== '' ? '   database backup: ' . $at['db'] : '')
        . '   maintenance flag: runtime/update/maintenance.php' . ($flagOn ? '' : ' (already deleted)'));
    $say('  Fix the cause, then try again: php tools/update.php --resume');
    // before the flag goes: the command deletes it itself and clears the web server's PHP cache through it (hosting-6 VH6-2)
    $say('  Or finish it by hand as the file lists it, then: php tools/update.php --close-attention' . ($flagOn ? ' (while the maintenance flag is still there: it deletes it itself)' : ''));
};
/**
 * A run that is active right now - above all a rollback that waits for the founder: --apply and --rollback say so before
 * anything else (a check of GitHub would answer "up to date", the site's probe would meet the maintenance page) and end
 * with exit 4 (ux-6 CLI-ATT). True when one is active.
 */
$activeRun = function () use ($say, $attentionLines, $runMessage) {
    $cur = Store::current();
    if ($cur === null) {
        return false;
    }
    $st = Store::load($cur['run']);
    if ($st !== null && $st['status'] === 'attention') {
        $say('A rollback that could not finish waits for you: ' . $runMessage(\app\common\util\updater\Engine::result($st)));
        $attentionLines($st);
        return true;
    }
    $say('Another update or rollback is running: php tools/update.php --status / --resume');
    return true;
};

/** Drives the active run with its frozen engine copy until it ends; returns the last result. */
$drive = function () use ($say, &$out) {
    $cur = Store::current();
    if ($cur === null) {
        return null;
    }
    $boot = Util::root() . 'runtime/update/engine-' . $cur['run'] . '/bootstrap.php';
    $lib = Util::root() . 'runtime/update/engine-' . $cur['run'] . '/bridge-lib.php';
    if (is_file($boot)) {
        require_once $boot;
        $engine = '\\javcms_upd_' . $cur['run'] . '\\Engine';
    } elseif (is_file($lib)) {
        if (!defined('JAVCMS_UPD_LIB')) {
            define('JAVCMS_UPD_LIB', 1);
        }
        require_once $lib;
        $engine = '\\javcms_upd_bridge\\Engine';
    } else {
        $engine = '\\app\\common\\util\\updater\\Engine';
    }
    $seq = 0;
    $r = null;
    for ($guard = 0; $guard < 100000; $guard++) {
        $r = $engine::step($cur['run'], 30.0);
        foreach (Store::readLog($cur['run'], $seq, 500) as $l) {
            $seq++;
            $say(gmdate('H:i:s', (int)$l['t']) . '  ' . str_pad((string)$l['l'], 5) . ' ' . (string)$l['m']);
        }
        if (($r['status'] ?? '') !== 'running') {
            break;
        }
        if (!empty($r['wait'])) {
            usleep(min(30000, (int)$r['wait']) * 1000);
        }
    }
    $out['run'] = $r;
    return $r;
};

if ($opt['status']) {
    $st = Service::status();
    $out = array('installed' => $st['installed'], 'run' => $st['run'], 'history' => array_slice($st['history'], 0, 10), 'rollback' => $st['rollback']);
    $say('Installed: ' . $st['installed'] . ($st['test'] ? '   (TEST MODE: local mock endpoints and test keys)' : ''));
    $c = $st['check'];
    $say('Newest known: ' . (is_array($c['latest']) ? $c['latest']['version'] . ($c['available'] ? ' (update available)' : ' (current)') : 'not checked yet')
        . ($c['checked_at'] ? ', checked ' . date('Y-m-d H:i', (int)$c['checked_at']) : ''));
    if ($st['run']) {
        $say('Running: ' . $st['run']['kind'] . ' ' . $st['run']['from'] . ' -> ' . $st['run']['to'] . ', phase ' . $st['run']['phase'] . ', ' . $st['run']['status']);
        if ($st['run']['status'] === 'attention') {
            $say('  The rollback needs attention: ' . $runMessage($st['run']));
            $raw = Store::load((string)$st['run']['run']);
            if ($raw !== null) {
                $attentionLines($raw);
            }
        }
    }
    foreach (array_slice($st['history'], 0, 10) as $h) {
        $say('  ' . date('Y-m-d H:i', (int)$h['started']) . '  ' . $h['kind'] . ' ' . $h['from'] . ' -> ' . $h['to'] . '  ' . $h['status'] . ($h['error'] !== '' ? '  (' . $h['error'] . ')' : ''));
    }
    // no rollback is offered while a run is active (a rollback that waits for the founder above all: ux-6 CLI-ATT)
    if ($st['rollback'] && !$st['run']) {
        $say('Rollback to ' . $st['rollback']['to'] . ' available until ' . date('Y-m-d H:i', (int)$st['rollback']['until']) . ': php tools/update.php --rollback');
    }
    if ($st['run']) {
        $out['rollback'] = null;
    }
    $done(0);
}

if ($opt['close-attention']) {
    // a rollback that needs attention, finished by hand: the run ends as closed by the founder (Engine::closeAttention)
    $cur = Store::current();
    $st = $cur !== null ? Store::load($cur['run']) : null;
    if ($st === null || $st['status'] !== 'attention') {
        $say('No rollback waits for attention' . ($st !== null ? ' (the active run is ' . $st['status'] . ')' : '') . '.');
        $done($st === null ? 0 : 1);
    }
    $say('The rollback needs attention: ' . $runMessage(\app\common\util\updater\Engine::result($st)));
    $attentionLines($st);
    if (!$opt['yes']) {
        echo 'Close this run now? Only after you finished the rollback by hand as the file lists it. [y/N] ';
        $answer = strtolower(trim((string)fgets(STDIN)));
        if ($answer !== 'y' && $answer !== 'yes') {
            $say('Not closed.');
            $done(1);
        }
    }
    $eng = $engineOf($cur['run']);
    $r = $eng::closeAttention($cur['run'], 'founder');
    $out = $r;
    if (empty($r['ok'])) {
        $say('Not closed: ' . $r['error']);
        $done(($r['k'] ?? '') === 'busy' ? 4 : 1);
    }
    $end = Store::load($cur['run']);
    $say($end !== null ? $runMessage(\app\common\util\updater\Engine::result($end)) : 'Closed.');
    // the files put back by hand and the web server's PHP cache (hosting-5 VH5-3): reset through the gate while the flag
    // was still on; a cache that never re-reads files and could not be reset needs a restart (exit 5)
    $wc = (string)($r['web_cache'] ?? '');
    if ($wc === 'restart') {
        $say('The web server\'s PHP cache never re-reads changed files and could not be reset: it may keep the newer release\'s copies of the files you put');
        $say('  back until PHP restarts. Restart PHP now (PHP-FPM, Apache, or "clear OPcache" in the hosting panel).');
        $done(5);
    }
    // what this run learnt of the web server's cache decides when the flag was gone already (e2e-6 P3-4, hosting-6 VH6-2)
    if ($wc === 'reset') {
        $say('The web server\'s PHP cache was reset: it runs the files you put back.');
    } elseif ($wc === 'reread') {
        $say('The web server\'s PHP cache re-reads changed files by itself (as this run found out): it uses the files you put back within seconds.');
    } elseif ($wc === 'none') {
        $say('The web server uses no PHP cache (OPcache): it runs the files you put back.');
    } elseif ($wc === 'unknown') {
        $say('The web server\'s PHP cache could not be asked (it answers only while runtime/update/maintenance.php exists, and this run never learnt how it');
        $say('  works): if pages still behave like the newer version, restart PHP (or clear OPcache in the hosting panel).');
    }
    $done(0);
}

if ($opt['cancel']) {
    // waits for a step that runs now (up to a minute: a command-line step may take 30 s)
    $r = Service::cancel(60.0);
    $out = $r;
    if (!empty($r['ok']) && !empty($r['pending'])) {
        $say('Cancel requested: ' . $r['error'] . '. When no page or command drives the update any more, run php tools/update.php --cancel again in a moment.');
    } else {
        $say(!empty($r['ok']) ? 'Cancelled. Nothing of the site was changed' . (!empty($r['db_removed']) ? '; the unfinished database backup was removed.' : '.') : 'Not cancelled: ' . $r['error']);
    }
    $done(!empty($r['ok']) ? 0 : 1);
}

if ($opt['resume']) {
    $cur = Store::current();
    if ($cur === null) {
        $say('No update is running.');
        $done(0);
    }
    $st = Store::load($cur['run']);
    if ($st !== null && $st['status'] === 'attention') {
        // a rollback that could not finish waits for the founder: tried again from the step that failed
        $say('The rollback needs attention: ' . $runMessage(\app\common\util\updater\Engine::result($st)));
        $attentionLines($st);
        if (!$opt['yes']) {
            echo 'Try the rollback again now (after fixing its cause)? [y/N] ';
            $answer = strtolower(trim((string)fgets(STDIN)));
            if ($answer !== 'y' && $answer !== 'yes') {
                $say('Not started.');
                $done(1);
            }
        }
        // through the frozen engine of the run (the site's own updater files may be half restored)
        $eng = $engineOf($cur['run']);
        if (!$eng::retryAttention($cur['run'])) {
            $say('The rollback could not be restarted (another step is working?): try again in a moment.');
            $done(4);
        }
    }
    $r = $drive();
    $ok = in_array($r['status'] ?? '', array('done'), true) || (($r['kind'] ?? '') === 'rollback' && ($r['status'] ?? '') === 'rolled_back');
    if (!$ok) {
        $say('Ended: ' . $runMessage($r) . ' (status ' . ($r['status'] ?? '?') . ')');
    }
    $restart = $webCacheAdvice($r);
    if ((string)($r['error_msg']['k'] ?? '') === 'e_opcache_blocked') {
        $done(3); // stopped before any change, like --apply (UPDATER-VERIFY-E2E-5 P3-1)
    }
    $done($restart ? 5 : ($ok ? 0 : 1));
}

if ($opt['check'] || $opt['apply']) {
    $rp = Service::runtimeProblem();
    if ($rp !== null) {
        $say('Not started: ' . $rp['error']);
        $done(3);
    }
    if ($opt['apply'] && $activeRun()) {
        $done(4);
    }
    $c = Source::check('force');
    $out['check'] = array('state' => $c['state'], 'installed' => $c['installed'], 'latest' => $c['latest'], 'via' => $c['via'], 'error' => $c['error']);
    if ($c['state'] === 'skipped' && !is_array($c['latest'])) {
        // another process checks GitHub at this very moment (its lock): nothing to tell yet
        $say('Another check for updates is running at this moment (a cron line or the Updates page): try again in a few seconds.');
        $done(4);
    }
    if ($c['state'] === 'error') {
        $say('Check failed: ' . $c['error']);
        if (($c['error_k'] ?? '') === 'ce_unreachable' || ($c['error_k'] ?? '') === 'ce_timeout') {
            $say('GitHub did not answer: outgoing HTTPS may be blocked here. Nothing was changed. Upload javcms-<version>.zip, .json and .json.sig from the GitHub release into runtime/update/inbox/.');
        } elseif (($c['error_k'] ?? '') === 'ce_github_down') {
            $say('GitHub answered with a server error (it may be down for a while): try again later. Nothing was changed.');
        } else {
            $say('Nothing was changed.');
        }
        $done(1);
    }
    $say('Installed: ' . $c['installed'] . '   newest release: ' . $c['latest']['version'] . ' (' . $c['latest']['released'] . ', via ' . $c['via'] . ')'
        . (Config::test() !== null ? '   [TEST MODE]' : ''));
    if ($c['note'] !== '') {
        $say('Note: ' . $c['note']);
    }
    if ($opt['check']) {
        $say('OPcache: the command line has a cache of its own; the update resets the web server\'s cache through the site, and waits like the Updates page when the host forbids that (opcache.restrict_api).');
        $say('  A web server whose cache never re-reads files (validate_timestamps=0) and may not be reset stops the update before any change (exit 3).');
    }
    if (empty($c['available'])) {
        $say('JavCMS is up to date.');
        $done(0);
    }
    $say('Update available: ' . $c['latest']['version'] . ' (' . number_format((int)$c['latest']['files']) . ' files, ' . Util::bytes((int)$c['latest']['size']) . ')');
    $b = $siteBase();
    $out['health_from_server'] = $b;
    if (!$opt['apply']) {
        if ($c['notes'] !== '') {
            $say('');
            $say(Util::cut($c['notes'], 2000));
        }
        $say('');
        if ($b['ok']) {
            $say('Health check from this server: ' . $b['base'] . ' answers.');
            $say('Install it: php tools/update.php --apply');
        } else {
            $explainHealth($b);
        }
        $done(0);
    }
    if (Store::current() !== null) {
        $busyRun = method_exists('\app\common\util\updater\Service', 'attentionRun') ? Service::attentionRun() : null;
        $say($busyRun !== null ? 'A rollback that could not finish waits for you: php tools/update.php --status, then --resume (try again) or --close-attention (after finishing it by hand)'
            : 'Another update or rollback is running: php tools/update.php --status / --resume');
        $done(4);
    }
    if (!$b['ok']) {
        $explainHealth($b);
        $say('Not started.');
        $done(3);
    }
    $choice = $opt['keep-modified'] ? 'keep' : 'replace';
    $say('Checking the installed files ...');
    $fresh = true;
    do {
        $p = Service::precheck($choice, microtime(true) + 20, array(), $fresh);
        $fresh = false;
        if ($p['state'] === 'error') {
            $say('Check failed: ' . $p['error']);
            $done(in_array((string)($p['k'] ?? ''), array('req_runtime_link', 'req_runtime_no'), true) ? 3 : 1);
        }
    } while ($p['state'] === 'more');
    $out['precheck'] = $p;
    $blocking = false;
    $busy = false;
    foreach ($p['requirements'] as $row) {
        $say(str_pad(strtoupper($row['level'] === 'ok' ? 'ok' : $row['level']), 6) . $row['m']);
        $blocking = $blocking || $row['level'] === 'error';
        $busy = $busy || ($row['id'] === 'busy' && $row['level'] === 'error');
    }
    $pl = $p['plan'];
    // with --keep-modified the updater's own changed files are replaced all the same: counted apart (e2e-4 P3-5)
    $keepNote = (int)($pl['needed'] ?? 0) > 0 ? sprintf('%d kept, %d replaced because the updater needs them', (int)$pl['keep'], (int)$pl['needed']) : 'kept';
    $say(sprintf('Plan: %d new, %d replaced, %d removed, %d unchanged; %d changed by this site (%s); %d kept for a manual merge.',
        $pl['add'], $pl['replace'], $pl['delete'], $pl['same'], $pl['modified'], $choice === 'keep' ? $keepNote : 'replaced, backup keeps them', $pl['merge']));
    if ($pl['modified'] > 0) {
        foreach (array_slice($pl['modified_list'], 0, 20) as $f) {
            $say('    changed: ' . $f);
        }
        if ($pl['modified'] > 20) {
            $say('    ... ' . ($pl['modified'] - 20) . ' more');
        }
    }
    if ($choice === 'keep' && (int)($pl['needed'] ?? 0) > 0) {
        $say('Replaced even with --keep-modified, because the updater needs them (the backup keeps your copies): '
            . implode(', ', array_slice((array)$pl['needed_list'], 0, 10)) . ((int)$pl['needed'] > 10 ? ' ...' : ''));
    }
    if ($p['db'] !== null) {
        $say('Database backup: ' . $p['db']['tables'] . ' tables' . ($p['db']['full'] ? ' (whole database)' : ' (partial: structure + data of changed and small tables; not a full backup)') . ', into ' . $p['db']['folder']);
    }
    $est = (array)($p['estimate'] ?? array());
    $mm = max(1, (int)($est['maint_minutes'] ?? 1));
    if ($est) {
        $say('Estimate: about ' . (int)$est['files'] . ' files change; about ' . (int)$est['minutes'] . ' min in all, visitors see the maintenance page for about ' . $mm . ' min.');
    }
    if ($busy) {
        $say('Not started: another update or rollback is running (php tools/update.php --status / --resume).');
        $done(4);
    }
    if ($blocking) {
        $say('Not started: fix the errors above first.');
        $done(3);
    }
    if (!$opt['yes']) {
        echo 'Start the update to ' . $c['latest']['version'] . ' now? Visitors see a maintenance page for about ' . $mm . ' min. [y/N] ';
        $answer = strtolower(trim((string)fgets(STDIN)));
        if ($answer !== 'y' && $answer !== 'yes') {
            $say('Not started.');
            $done(0);
        }
    }
    $a = Service::arm(array('choice' => $choice, 'by' => 'cli', 'base' => $b['base'], 'admin' => \app\common\util\updater\Planner::renamedAdminEntry() ?: 'javcms.php'));
    if (empty($a['ok'])) {
        $say('Not started: ' . $a['error']);
        $done(($a['k'] ?? '') === 'run_busy' ? 4 : 3);
    }
    $r = $drive();
    $say(($r['status'] ?? '') === 'done' ? 'Done: JavCMS ' . $r['to'] . ' is installed.' : 'Not updated: ' . $runMessage($r) . ' (status ' . ($r['status'] ?? '?') . ')');
    if (($r['status'] ?? '') === 'done') {
        $say('Long builds of a big catalogue run in housekeeping; to do them now: php tools/upgrade.php');
    }
    if (($r['status'] ?? '') === 'attention') {
        $raw = Store::load((string)($r['run'] ?? ''));
        if ($raw !== null) {
            $attentionLines($raw);
        }
    }
    $restart = $webCacheAdvice($r);
    if ((string)($r['error_msg']['k'] ?? '') === 'e_opcache_blocked') {
        $done(3);
    }
    $done($restart ? 5 : (($r['status'] ?? '') === 'done' ? 0 : 1));
}

if ($opt['rollback']) {
    if ($activeRun()) {
        $done(4);
    }
    $offer = Service::rollbackOffer();
    if ($offer === null) {
        $say('Nothing to roll back (no update in the last ' . Config::KEEP_DAYS . ' days, or its backup is gone).');
        $done(1);
    }
    $say('Roll back from ' . $offer['from'] . ' to ' . $offer['to'] . ' (backup of ' . date('Y-m-d H:i', $offer['until'] - Config::KEEP_DAYS * 86400) . ').');
    if ($offer['breaking'] && !empty($offer['db_missing'])) {
        $say('Note: the database backup of ' . implode(', ', (array)$offer['db_missing']) . ' is missing (deleted in Backup & restore?): only the files can be rolled back.');
        if ($opt['db-restore']) {
            $say('Not started: --db-restore is not possible without that backup. Roll back the files only (without --db-restore), or restore those tables by hand.');
            $done(3);
        }
    } elseif ($offer['breaking'] && !$opt['db-restore']) {
        $say('Note: that update changed the database in a way ' . $offer['to'] . ' may not understand; add --db-restore to restore those tables from its backup (content added to them since is lost).');
    }
    $b = $siteBase();
    if (!$b['ok']) {
        $explainHealth($b);
        $say('Not started.');
        $done(3);
    }
    if (!$opt['yes']) {
        echo 'Roll back now? [y/N] ';
        $answer = strtolower(trim((string)fgets(STDIN)));
        if ($answer !== 'y' && $answer !== 'yes') {
            $say('Not started.');
            $done(0);
        }
    }
    $a = Service::armRollback(array('by' => 'cli', 'base' => $b['base'], 'admin' => \app\common\util\updater\Planner::renamedAdminEntry() ?: 'javcms.php',
        'db_restore' => (bool)$opt['db-restore']));
    if (empty($a['ok'])) {
        $say('Not started: ' . $a['error']);
        $done(($a['k'] ?? '') === 'run_busy' ? 4 : 3);
    }
    $r = $drive();
    $ok = ($r['status'] ?? '') === 'rolled_back';
    $say($ok ? 'Rolled back: JavCMS ' . $r['to'] . ' is installed again.' : 'Rollback did not finish: ' . $runMessage($r) . ' (status ' . ($r['status'] ?? '?') . '; php tools/update.php --resume)');
    $restart = $webCacheAdvice($r);
    $done($restart ? 5 : ($ok ? 0 : 1));
}

fwrite(STDERR, "Usage: php tools/update.php --check | --apply [--yes] [--keep-modified] [--url=...] | --rollback [--yes] [--db-restore] | --status | --resume | --close-attention [--yes] | --cancel | --help\n");
exit(2);
