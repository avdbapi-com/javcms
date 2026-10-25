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
// Duplicate-title finder (XVID-SCALE-BRIEF D-L13). The admin page's "Duplicates" chip runs one GROUP BY over every title
// (minutes and GBs of temp tables at millions of videos); above 1 M videos the admin page prints this command instead.
// The scan runs in hash buckets of the title's collation key (CRC32(WEIGHT_STRING(vod_name)) % N), so each GROUP BY only
// sorts about 500,000 rows; titles that are equal for the database collation always land in the same bucket.
// Uses only the site's own database (application/database.php); credentials are never printed; one JSON line on stdout.
// Usage: php tools/vod-duplicates.php [--root=<site folder>] [--buckets=N] [--quiet]
//          report: runtime/vod_duplicates.json (summary) + runtime/vod_duplicates.csv (title, copies, lowest id, highest id)
//        php tools/vod-duplicates.php --recycle --keep=min|max [--yes]
//          moves every copy except the kept one (lowest or highest id) to the recycle bin (reversible in the admin);
//          without --yes it only counts.
$opt = ['root' => dirname(__DIR__), 'buckets' => 0, 'quiet' => false, 'recycle' => false, 'keep' => 'min', 'yes' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z]+)(?:=(.*))?$/D', $arg, $m) || !array_key_exists($m[1], $opt)) {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
    $opt[$m[1]] = isset($m[2]) ? $m[2] : true;
}
if (!in_array($opt['keep'], ['min', 'max'], true)) {
    fwrite(STDERR, "--keep must be min or max\n");
    exit(2);
}
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    fwrite(STDERR, "PHP 7.4 or newer is required (this is " . PHP_VERSION . ")\n");
    exit(2);
}
$root = realpath((string)$opt['root']);
if ($root === false || !is_file($root . '/application/database.php')) {
    fwrite(STDERR, "Not a JavCMS site folder: {$opt['root']}\n");
    exit(2);
}
if (function_exists('proc_nice')) {
    @proc_nice(19);
}
@set_time_limit(0);
ini_set('memory_limit', '512M');
$util = $root . '/application/common/util/';
foreach (['FtText', 'FtIndex', 'ScaleTier', 'ScaleCounts', 'StorageGuard', 'DbTimeout', 'SeoSettings', 'AvdbLanguage'] as $class) {
    if (is_file($util . $class . '.php')) {
        require_once $util . $class . '.php';
    }
}
$GLOBALS['config'] = [];
$maccms = is_file($root . '/application/extra/maccms.php') ? include $root . '/application/extra/maccms.php' : [];
if (is_array($maccms)) {
    $GLOBALS['config'] = $maccms;
}

$c = include $root . '/application/database.php';
if (!is_array($c) || empty($c['database'])) {
    fwrite(STDERR, "No database configured\n");
    exit(2);
}
$prefix = isset($c['prefix']) ? (string)$c['prefix'] : '';
if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) {
    fwrite(STDERR, "Invalid table prefix\n");
    exit(2);
}
try {
    $dsn = 'mysql:host=' . $c['hostname'] . ';port=' . (isset($c['hostport']) && $c['hostport'] !== '' ? (int)$c['hostport'] : 3306)
        . ';dbname=' . $c['database'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)$c['username'], (string)$c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    // the CLI has no statement limit (L12)
    try { $pdo->exec('/*M!100108 SET SESSION max_statement_time = 0 */'); } catch (Throwable $e) {}
    try { $pdo->exec('SET SESSION max_execution_time = 0'); } catch (Throwable $e) {}
} catch (Throwable $e) {
    fwrite(STDERR, "Database not reachable (" . get_class($e) . ")\n");
    exit(3);
}
$dbName = (string)$c['database'];
unset($c);
$vod = '`' . $prefix . 'vod`';
$runtime = $root . DIRECTORY_SEPARATOR . 'runtime';
$say = function (string $s) use ($opt) {
    if (!$opt['quiet']) {
        fwrite(STDERR, date('H:i:s') . ' ' . $s . "\n");
    }
};
$out = function (array $data, int $code = 0) {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit($code);
};

// single flight: one duplicate scan per site
$lock = (string)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($dbName . '_' . $prefix . 'vod_dupes') . ', 0) AS l')->fetch()['l'];
if ($lock !== '1') {
    $out(['status' => 'refused', 'reason' => 'busy'], 1);
}

$t0 = microtime(true);
$rows = 0;
try {
    $st = $pdo->prepare('SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $st->execute([$dbName, $prefix . 'vod']);
    $rows = (int)$st->fetchColumn();
} catch (Throwable $e) {
}
$buckets = (int)$opt['buckets'] > 0 ? min(4096, (int)$opt['buckets']) : max(1, (int)ceil($rows / 500000));
$hash = 'CRC32(WEIGHT_STRING(vod_name))';
try {
    $pdo->query('SELECT ' . $hash . ' FROM ' . $vod . ' LIMIT 1')->fetchAll();
} catch (Throwable $e) {
    $hash = 'CRC32(LOWER(vod_name))'; // engines without WEIGHT_STRING: case-insensitive bucket key
}

@mkdir($runtime, 0755, true);
$csvFile = $runtime . DIRECTORY_SEPARATOR . 'vod_duplicates.csv';
$tmpCsv = $csvFile . '.' . getmypid() . '.tmp';
$csv = @fopen($tmpCsv, 'w');
if ($csv === false) {
    $out(['status' => 'failed', 'reason' => 'runtime_not_writable'], 1);
}
fputcsv($csv, ['title', 'copies', 'lowest_id', 'highest_id']);
$groups = 0;
$extra = 0;
$recycled = 0;
$keepMax = $opt['keep'] === 'max';
$now = time();
$touch = class_exists('\app\common\util\FtIndex');
if ($touch) {
    \app\common\util\FtIndex::useConnection($pdo, $prefix, $dbName);
    \app\common\util\FtIndex::useRuntime($runtime);
}
for ($b = 0; $b < $buckets; $b++) {
    $sql = 'SELECT vod_name, COUNT(*) AS c, MIN(vod_id) AS mn, MAX(vod_id) AS mx FROM ' . $vod . ' WHERE vod_recycle_time = 0'
        . ($buckets > 1 ? ' AND ' . $hash . ' % ' . $buckets . ' = ' . $b : '') . ' GROUP BY vod_name HAVING COUNT(*) > 1';
    $found = $pdo->query($sql)->fetchAll();
    foreach ($found as $g) {
        $groups++;
        $extra += (int)$g['c'] - 1;
        fputcsv($csv, [(string)$g['vod_name'], (int)$g['c'], (int)$g['mn'], (int)$g['mx']]);
    }
    if ($opt['recycle'] && $opt['yes'] && $found) {
        foreach (array_chunk($found, 200) as $chunk) {
            $names = [];
            $keep = [];
            foreach ($chunk as $g) {
                $names[] = $pdo->quote((string)$g['vod_name']);
                $keep[(int)($keepMax ? $g['mx'] : $g['mn'])] = true;
            }
            $ids = [];
            foreach ($pdo->query('SELECT vod_id FROM ' . $vod . ' WHERE vod_recycle_time = 0 AND vod_name IN (' . implode(',', $names) . ')') as $r) {
                if (!isset($keep[(int)$r['vod_id']])) {
                    $ids[] = (int)$r['vod_id'];
                }
            }
            foreach (array_chunk($ids, 500) as $part) {
                $recycled += $pdo->exec('UPDATE ' . $vod . ' SET vod_recycle_time = ' . $now . ' WHERE vod_recycle_time = 0 AND vod_id IN (' . implode(',', $part) . ')');
                if ($touch) {
                    \app\common\util\FtIndex::touch($part); // the recycled copies leave the search table at the next drain
                }
            }
        }
    }
    $say(sprintf('bucket %d / %d: %d groups so far', $b + 1, $buckets, $groups));
}
fclose($csv);
@rename($tmpCsv, $csvFile) || @unlink($tmpCsv);
if ($recycled > 0) {
    // C9 list revision (same file format as Vod::bumpListRevision(true)): lists and searches refresh at once
    $revFile = $runtime . DIRECTORY_SEPARATOR . 'list_revision.json';
    $cur = is_file($revFile) ? json_decode((string)@file_get_contents($revFile), true) : null;
    $rev = is_array($cur) && isset($cur['rev']) ? (int)$cur['rev'] + 1 : 1;
    $tmp = $revFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode(['rev' => $rev, 'at' => time(), 'pending' => 0])) !== false && !@rename($tmp, $revFile)) {
        @unlink($tmp);
    }
    if (class_exists('\app\common\util\ScaleCounts')) {
        try { \app\common\util\ScaleCounts::markDirty('dupes'); } catch (Throwable $e) {}
    }
}
$summary = ['status' => 'done', 'at' => time(), 'groups' => $groups, 'videos' => $extra, 'buckets' => $buckets,
    'seconds' => round(microtime(true) - $t0, 1), 'csv' => 'runtime/vod_duplicates.csv'];
if ($opt['recycle']) {
    $summary['recycled'] = $recycled;
    $summary['keep'] = $opt['keep'];
    if (!$opt['yes']) {
        $summary['hint'] = 'dry run: add --yes to move ' . $extra . ' copies to the recycle bin';
    }
}
@file_put_contents($runtime . DIRECTORY_SEPARATOR . 'vod_duplicates.json', json_encode($summary));
$pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($dbName . '_' . $prefix . 'vod_dupes') . ')');
$out($summary);
