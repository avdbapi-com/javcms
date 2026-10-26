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
/**
 * Vocabulary of the FULLTEXT filter columns (vod_tag, vod_actor, vod_class, vod_director) for FilterMatch v2: the table
 * {prefix}vod_ft_vocab and its full build. See application/common/util/FilterVocab.php for what it is and why.
 *
 *   php tools/vod-ft-vocab.php              status (same as --status): table, build state, freshness, tokens per column
 *   php tools/vod-ft-vocab.php --dry        what --build would do, with the expected time for this table
 *   php tools/vod-ft-vocab.php --build      create the table if missing, then build (or finish a running build) when
 *                                           the vocabulary is missing, stale or older than a day; resumable
 *   php tools/vod-ft-vocab.php --rebuild    start a new full build now (old words stay usable until it finishes)
 *   php tools/vod-ft-vocab.php --off        switch the vocabulary off: every filter uses the plain LIKE (v1 behaviour), no
 *                                           catch-ups, no builds (housekeeping included) until --on; builds never undo it
 *   php tools/vod-ft-vocab.php --on         switch it on again: the next filter page catches up what changed meanwhile
 *   options: --pause=20      ms between two batches of 2,000 videos (default 20; 0 = none)
 *            --lock-wait=5   seconds to wait for the vocabulary lock while a page catch-up or housekeeping holds it
 *                            (default 5; the tool uses the site's lock name, so they never interleave)
 *            --root=PATH     site root whose application/database.php is used (default: this script's site)
 *
 * Locks: the build only reads the video table (primary-key ranges of 2,000 rows, no locking reads) and writes the
 * small vocabulary table. Pages keep using the old vocabulary during a rebuild and catch up new rows themselves; the
 * vocabulary lock (one named lock shared with the site: page catch-ups, housekeeping) is held per batch only.
 * Times are printed in the PHP default time zone of this CLI (shown after each time; the site's may differ). Measured on a 524,983-video copy (MariaDB 11.4): see FT-VOCAB-BUILD.md.
 * Reads application/database.php; never prints credentials.
 * JavCMS 1.2.0 (fp-port): a backlog (state 'pend': more new or queued ids than one catch-up reads, e.g. after a bulk import
 * that no filter page followed) is shown in the status and worked off by --build (catch-ups of 20,000 rows) before any
 * build; housekeeping (tools/housekeeping.php, the scale run) and the importers do the same on their own.
 * fp-port-b: the status shows the item path of CJK / kana / Hangul / Thai tag, class and actor values (items kept, rows
 * checked against the search table, exception rows; usable after one full build by this version: --build runs it when
 * missing); --build ends with a catch-up, so rows inserted since the last one are in the vocabulary when it says current.
 * fp-port-c: the item path is complete after a full build with the document checks of item-path version 2 (every visible
 * row's search document checked, a missing token queues the row); a table built by an earlier version is built once more.
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
$opts = ['mode' => 'status', 'pause' => 20, 'lock-wait' => 5, 'root' => dirname(__DIR__)];
foreach (array_slice($argv, 1) as $arg) {
    if (in_array($arg, ['--status', '--dry', '--build', '--rebuild', '--off', '--on'], true)) { $opts['mode'] = substr($arg, 2); }
    elseif (preg_match('/^--(pause|lock-wait)=(\d+)$/', $arg, $m)) { $opts[$m[1]] = (int)$m[2]; }
    elseif (preg_match('/^--root=(.+)$/', $arg, $m)) { $opts['root'] = rtrim($m[1], '/\\'); }
    else { fwrite(STDERR, "unknown option $arg\n"); exit(2); }
}
$root = $opts['root'] . DIRECTORY_SEPARATOR;
require_once $root . 'application/common/util/FilterVocab.php';
use app\common\util\FilterVocab;

$cfg = include $root . 'application' . DIRECTORY_SEPARATOR . 'database.php';
$pre = (string)$cfg['prefix'];
if (!preg_match('/^[A-Za-z0-9_]*$/D', $pre)) { exit("bad table prefix\n"); }
$pdo = new PDO('mysql:host=' . $cfg['hostname'] . ';port=' . ($cfg['hostport'] ?: 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
    $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$vod = $pre . 'vod';
$table = $pre . FilterVocab::TABLE;
$q = function ($sql) use ($pdo) { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); };
$present = (int)$q("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table))[0]['n'] > 0;
$colInfo = [];
foreach ($q("SELECT COLUMN_NAME AS n, CHARACTER_SET_NAME AS s, COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($vod)) as $r) {
    $colInfo[$r['n']] = $r;
}
$rows = (int)$q("SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($vod))[0]['n'];
$timeIdx = (int)$q("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($vod) . " AND COLUMN_NAME = 'vod_time' AND SEQ_IN_INDEX = 1")[0]['n'] > 0;
$cs = $colInfo['vod_tag']['s'] ?? 'utf8mb4';
$coll = $colInfo['vod_tag']['c'] ?? 'utf8mb4_general_ci';
$log = function ($msg) { printf("[%s] %s\n", date('H:i:s T'), $msg); };
$dbKey = FilterVocab::dbKey($cfg); // the site's lock / cache name part: CLI builds share the app's named lock
$collOk = in_array(strtolower($coll), FilterVocab::COLLATIONS, true);

printf("video table %s: about %d rows, filter columns %s / %s%s\n", $vod, $rows, $cs, $coll, $timeIdx ? '' : ' (NO index starting with vod_time: the vocabulary is not used)');
if (!$collOk) {
    printf("  note: the vocabulary is used only with %s (exact there; under %s other characters LIKE-match a separator):\n" .
        "  every filter keeps the plain LIKE (v1 behaviour), and --build does nothing\n", implode(' / ', FilterVocab::COLLATIONS), $coll);
}
foreach (array_keys(FilterVocab::COLS) as $c) {
    if (isset($colInfo[$c]) && ($colInfo[$c]['c'] !== $coll || $colInfo[$c]['s'] !== $cs)) {
        printf("  note: %s has %s / %s, not the vocabulary's collation: its filter keeps the v1 behaviour\n", $c, $colInfo[$c]['s'], $colInfo[$c]['c']);
    }
}
if ($present) {
    $tc = $q("SELECT CHARACTER_SET_NAME AS s, COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . " AND COLUMN_NAME = 'token'");
    printf("vocabulary table %s: present (token %s / %s)%s\n", $table, $tc[0]['s'] ?? '?', $tc[0]['c'] ?? '?',
        in_array(strtolower((string)($tc[0]['c'] ?? '')), FilterVocab::COLLATIONS, true) ? '' : ' - NOT USED: only ' . implode(' / ', FilterVocab::COLLATIONS));
} else {
    printf("vocabulary table %s: MISSING\n", $table);
}
$per = max(0.1, $rows / 524983);
if ($opts['mode'] === 'dry') {
    if (!$collOk) { echo "--build would do nothing (collation, see the note above)\n"; exit(0); }
    if (!$present) { echo "--build would create it:\n  " . FilterVocab::createSql($pre, $cs, $coll) . "\n"; }
    printf("--build would read %d videos in batches of %d (primary-key ranges, no locks on the video table) and write the\n" .
        "vocabulary (~65,000 words for 525,000 videos): expected ~%.0f-%.0f s with --pause=%d (measured on 524,983 videos,\n" .
        "MariaDB 11.4: 3.9 s warm / 7.2 s cold without pauses, 11.9 s with --pause=20; see FT-VOCAB-BUILD.md).\n" .
        "Pages are not blocked: until it finishes every filter runs as before; the vocabulary lock is held per batch.\n",
        $rows, FilterVocab::BATCH, 4 * $per + $rows / FilterVocab::BATCH * $opts['pause'] / 1000, 8 * $per + $rows / FilterVocab::BATCH * $opts['pause'] / 1000, $opts['pause']);
    exit(0);
}
if ($opts['mode'] === 'off' || $opts['mode'] === 'on') {
    if (!$present) { echo "nothing to switch: the vocabulary table is missing (filters use the plain LIKE)\n"; exit($opts['mode'] === 'off' ? 0 : 1); }
    FilterVocab::pdo($pdo, $pre, false, $dbKey)->setOff($opts['mode'] === 'off');
    echo $opts['mode'] === 'off'
        ? "vocabulary switched OFF: every filter uses the plain LIKE (v1 behaviour); no catch-ups, no builds until --on\n"
        : "vocabulary switched on: the next filter page catches up what changed meanwhile (or the next build runs if it is not ready)\n";
    $opts['mode'] = 'status';
}
if ($opts['mode'] === 'status') {
    if (!$present) { exit(1); }
    $v = FilterVocab::pdo($pdo, $pre, false, $dbKey);
    $st = $v->status();
    $s = $st['state'];
    if (!empty($s['off'])) {
        printf("SWITCHED OFF since %s (tools/vod-ft-vocab.php --on): every filter uses the plain LIKE, no catch-ups, no builds\n", date('Y-m-d H:i:s T', $s['off']));
    }
    printf("state: %s, version %d (code %d), built %s, rev %d.%d, stale %s, build running %s\n", !empty($s['ready']) && (int)($s['ver'] ?? 0) === FilterVocab::VERSION ? 'READY' : 'not ready',
        $s['ver'] ?? 0, FilterVocab::VERSION, !empty($s['built']) ? date('Y-m-d H:i:s T', $s['built']) : 'never', $s['rev'] ?? 0, $s['nonce'] ?? 0,
        !empty($s['stale']) ? date('Y-m-d H:i:s T', $s['stale']) . ' (filters use the plain LIKE until the next build)' : 'no',
        !empty($s['b_start']) ? sprintf('yes, since %s, at id %d of %d', date('H:i:s T', $s['b_start']), $s['b_cursor'] ?? 0, $s['b_max'] ?? 0) : 'no');
    $p = $v->probe(true);
    if (!empty($s['ready']) && (int)($s['ver'] ?? 0) === FilterVocab::VERSION && empty($s['off']) && !empty($s['pend'])) {
        printf("freshness: BACKLOG since %s: %d videos above id %d not read yet (filters use the plain LIKE until it is worked off by the next filter pages, housekeeping, the importers or --build)\n",
            date('Y-m-d H:i:s T', $s['pend']), (int)($st['backlog'] ?? 0), (int)($s['ifloor'] ?? 0));
    } elseif (!empty($s['ready']) && (int)($s['ver'] ?? 0) === FilterVocab::VERSION && empty($s['off'])) {
        printf("freshness: %s (queued ids %d)\n", (int)$p['fp'] === (int)($s['fp'] ?? -1) && $p['dirty'] === 0 ? 'current' : 'new rows since the last catch-up (the next filter page adds them)', $p['dirty']);
    }
    foreach (FilterVocab::COLS as $name => $id) {
        $c = $st['counts'][$id] ?? ['tokens' => 0, 'non_ascii' => 0, 'long' => 0];
        printf("  %-13s %7d words (%d with non-ASCII characters, %d pieces of long words)\n", $name, $c['tokens'], $c['non_ascii'], $c['long']);
    }
    // fp-port-b: item path (CJK / kana / Hangul / Thai values of tag, class and actor filters)
    $items = [];
    foreach (FilterVocab::ITEM_COLS as $name => $id) {
        $items[] = $name . ' ' . (int)($st['counts'][$id]['tokens'] ?? 0);
    }
    $xrows = (int)($st['counts'][FilterVocab::COL_XROW]['tokens'] ?? 0);
    // fp-port-c: complete only after a full build with this version's document checks ('xiver' = ITEMS_VERSION)
    $itemOk = FilterVocab::itemsComplete($s); // and that build was the last full build ('xibuilt' = 'built')
    printf("item path: %s; items with a CJK / kana / Hangul / Thai character: %s; rows checked against the search table %d; exception rows %d%s\n",
        $itemOk ? 'complete since ' . date('Y-m-d H:i:s T', $s['xitems']) . ' (item-path version ' . (int)$s['xiver'] . ')' : 'waits for one full build by this version (--build runs it)',
        implode(', ', $items), (int)($st['counts'][FilterVocab::COL_HASH]['tokens'] ?? 0), $xrows,
        $xrows > FilterVocab::XROW_MAX ? ' (more than ' . FilterVocab::XROW_MAX . ': the item path is off)' : '');
    exit(!empty($s['ready']) && (int)($s['ver'] ?? 0) === FilterVocab::VERSION && empty($s['off']) ? 0 : 1);
}
// --build / --rebuild
if (!$collOk) { $log('nothing built: the vocabulary is not used with ' . $coll . ' (see the note above)'); exit(0); }
if (!$present) {
    $pdo->exec(FilterVocab::createSql($pre, $cs, $coll));
    $log("created $table");
}
$v = FilterVocab::pdo($pdo, $pre, false, $dbKey);
$t0 = microtime(true);
$force = $opts['mode'] === 'rebuild';
// 1.2.0: a backlog of a ready vocabulary is worked off first (catch-ups of 20,000 rows; no full build needed for it)
$s0 = $v->state();
if (!empty($s0['pend']) && empty($s0['off']) && !empty($s0['ready']) && (int)($s0['ver'] ?? 0) === FilterVocab::VERSION) {
    $log('working off the backlog above id ' . (int)($s0['ifloor'] ?? 0));
    $tries = 0;
    while (true) {
        $done = $v->catchUp($opts['lock-wait'], FilterVocab::DELTA_MAX);
        $s0 = $v->state();
        if ($done || empty($s0['pend'])) { $log(sprintf('backlog worked off in %.1f s', microtime(true) - $t0)); break; }
        if (!empty($s0['stale']) || empty($s0['ready'])) { $log('marked stale meanwhile: a full build follows'); break; }
        if (++$tries > 100000) { $log('backlog not finished; run again'); break; }
        if ($opts['pause'] > 0) { usleep($opts['pause'] * 1000); }
    }
}
while (true) {
    $r = $v->buildStep(3600, $opts['pause'], $opts['lock-wait'], $force, $log);
    if ($r['state'] !== 'locked' && $r['state'] !== 'wait') { $force = false; } // --rebuild: keep forcing until a build ran
    if ($r['state'] === 'locked' || $r['state'] === 'wait') { $log($r['state'] === 'locked' ? 'vocabulary lock busy (a page catch-up); retrying' : 'marked stale this second; starting in a second'); sleep(1); continue; }
    if ($r['state'] === 'off') { $log('vocabulary is switched off (tools/vod-ft-vocab.php --on first): nothing built'); exit(0); }
    if ($r['state'] === 'idle') { $log('vocabulary is built; no full build needed (use --rebuild to force a new build)'); break; }
    if ($r['state'] === 'done') { $log(sprintf('done in %.1f s', microtime(true) - $t0)); break; }
}
// fp-port-b (P3-5): a catch-up last, so rows inserted or changed since the last one (raw SQL, an import no page followed) are in
// the vocabulary when this tool says it is current
$s = $v->state();
if (empty($s['off']) && !empty($s['ready']) && (int)($s['ver'] ?? 0) === FilterVocab::VERSION && empty($s['stale'])) {
    for ($i = 0; $i < 100000; $i++) {
        if ($v->catchUp($opts['lock-wait'], FilterVocab::DELTA_MAX)) { $log('caught up: the vocabulary is current'); break; }
        $s = $v->state();
        if (empty($s['pend']) && (!empty($s['stale']) || empty($s['ready']))) { $log('marked stale by the catch-up (more than 20,000 re-timed rows): run --build again'); break; }
        if ($opts['pause'] > 0) { usleep($opts['pause'] * 1000); }
    }
}
$s = $v->state();
exit(!empty($s['ready']) ? 0 : 1);
