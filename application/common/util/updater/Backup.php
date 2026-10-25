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
 * Backups of an update run: every file the update replaces or deletes, the site's settings files, and the database
 * tables the new release's upgrade may change (UPDATER decision U3, UPDATER-SCOUT-HOST rule 16).
 *
 * Files go to runtime/update/runs/<id>/backup/files/<path> with their sha256 recorded, so a rollback restores exactly
 * the bytes it took (a server configuration file - .htaccess, web.config - under its inert name, Paths::inertName: the
 * web server would read a copy named .htaccess as the rules of the backup folder). Settings (application/extra/*.php) go
 * to backup/settings/ (and, right before the new release's upgrade steps, again to backup/settings-pre/: a rollback
 * restores only what the update itself changed).
 *
 * The database backup is written in the format of Admins & backup > Backup & restore (<Ymd-His>-<part>.sql[.gz]) in
 * time-boxed slices by primary key. Unlike the legacy writer it keeps line breaks and NULLs exactly (PDO quoting, hex for
 * binary columns). Small tables share a part (up to the site's part_size, 20 MB by default, like the page's own backups);
 * a big table gets a part of its own. Every set is written into the update's own folder
 * <backup folder>/javcms-update/<Ymd-His>/, which Backup & restore never lists, so an unfinished set (the update was
 * cancelled, failed or expired while it was written) can never be taken for a backup there. Two layouts:
 * - full (the whole database is at most FULL_DB_BYTES): once every part has been read back and checked (dbVerifyStep),
 *   publishStep() moves the parts into the site's backup folder, where Backup & restore lists them and can restore them
 *   like any of its own backups;
 * - partial (a bigger database): the data of the tables the release declares it changes ("alters") and of small tables,
 *   the structure of every other table. It stays in its own folder with a README.txt (it is not a backup of the site). A
 *   structure-only part creates its table only when it is missing (CREATE TABLE IF NOT EXISTS): restoring it never
 *   empties a table.
 * An update that stops before it changed anything (cancel, failure, an armed page that expired) removes its set again
 * (removeJob): nothing was changed, so the set is of no use.
 *
 * Crash safety (a slice that dies is repeated, see Engine): every slice appends one gzip member per table and records,
 * when it ends, the size the part had then and the length, CRC32 and statement count of what it wrote. A slice that
 * continues a table first cuts the part back to the recorded size (a dead slice may have appended a cut-off member); a
 * table that starts cuts its part back to where the table begins (0, or the recorded end of the table before it in the
 * same part; when the part is shorter than that, the whole part is written again). Before the site is touched,
 * dbVerifyStep() reads every member back (inflate, length, CRC32, statements): a backup that does not verify stops the
 * update.
 *
 * The rollback restores a table into a new table beside the live one and swaps them with one RENAME (dbRestoreStep):
 * the live table is never half restored, a slice that died starts that table again, and the restore holds no long
 * metadata lock on the database server (only the final DROP of the replaced copy, while visitors see the maintenance
 * page). A table that shares its part is read from its own byte range of the part (its members only).
 */
final class Backup
{
    /** Databases up to this size (data + indexes) are backed up whole. */
    const FULL_DB_BYTES = 134217728;
    /** On a bigger database, tables up to this size are still backed up with their data. */
    const SMALL_TABLE_BYTES = 4194304;
    const ROWS_PER_QUERY = 500;
    const STATEMENT_BYTES = 524288;
    /** Second line of every part the updater writes (housekeeping finds the update's own backups by it). */
    const MARK = '-- JavCMS update backup';
    /** The update's own folder inside the site's backup folder (sets being written, partial backups; never listed). */
    const PARTIAL_DIR = 'javcms-update';
    /** Part size when the settings name none (db.part_size of application/extra/maccms.php, as the page's own backups). */
    const PART_DEFAULT = 20971520;
    /** Tables one part holds at most. */
    const GROUP_MAX_TABLES = 100;
    /** Restore tables: the copy built beside the live table, and the replaced table until it is dropped. */
    const SHADOW = '__jcupd_new';
    const ASIDE = '__jcupd_old';
    /** Failed restore attempts of one table before the rollback stops trying to restore the database (Engine). */
    const RESTORE_RESTARTS = 2;
    /** How long a restored table may wait for its file to be released by another program before the restore fails. */
    const FILE_BUSY_SECONDS = 1200;

    // ------------------------------------------------------------------ files

    /** Backup folder of run $id ('' when it cannot be made). */
    public static function dir($id, $sub = 'files')
    {
        return Store::runDir($id, 'backup/' . $sub);
    }

    /**
     * Copies the next files of $list (site-relative paths that exist) into the run's backup. $cursor = index into
     * $list; $index collects path => sha256. Returns ['state' => 'more'|'done'|'error', 'cursor', 'error'].
     */
    public static function filesStep($id, array $list, $cursor, array &$index, $deadline)
    {
        $base = self::dir($id);
        if ($base === '') {
            return array('state' => 'error', 'cursor' => $cursor, 'error' => 'cannot create the backup folder', 'k' => 'e_backup_folder', 'v' => array());
        }
        $n = count($list);
        while ($cursor < $n) {
            $rel = $list[$cursor];
            $src = Util::abs($rel);
            if (is_file($src)) {
                if (!is_readable($src)) {
                    return array('state' => 'error', 'cursor' => $cursor, 'error' => 'cannot read ' . $rel . ' (permissions)',
                        'k' => 'e_unreadable', 'v' => array('file' => $rel));
                }
                $sha = null;
                $dst = $base . Paths::inertName($rel);
                if (!Util::copyAtomic($src, $dst, 0644, $sha)) {
                    return array('state' => 'error', 'cursor' => $cursor, 'error' => 'cannot back up ' . $rel . ' (disk full or no permission)',
                        'k' => 'e_backup_file', 'v' => array('file' => $rel));
                }
                if (Util::sha256($src) !== $sha) {
                    // the site changed the file while it was copied: copy again in the next slice
                    @unlink($dst);
                    return array('state' => 'more', 'cursor' => $cursor, 'error' => '');
                }
                $index[$rel] = $sha;
            } else {
                $index[$rel] = null;
            }
            $cursor++;
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        return array('state' => $cursor >= $n ? 'done' : 'more', 'cursor' => $cursor, 'error' => '');
    }

    /**
     * Backs up the settings files (application/extra/*.php, version.php included) into backup/<$sub>/. Returns path =>
     * sha256 of every file copied.
     */
    public static function settings($id, $sub = 'settings')
    {
        $base = self::dir($id, $sub);
        $out = array();
        if ($base === '') {
            return $out;
        }
        foreach ((array)glob(Util::abs('application/extra/*.php')) as $f) {
            $name = basename((string)$f);
            $sha = null;
            if (Util::copyAtomic((string)$f, $base . $name, 0644, $sha)) {
                $out['application/extra/' . $name] = $sha;
            }
        }
        return $out;
    }

    /** Path of a backed-up file, or '' when the backup has no copy (an older backup kept a server configuration file under its real name). */
    public static function copyOf($id, $rel, $sub = 'files')
    {
        $base = self::dir($id, $sub);
        foreach (array_unique(array(Paths::inertName($rel), (string)$rel)) as $name) {
            if (is_file($base . $name)) {
                return $base . $name;
            }
        }
        return '';
    }

    // ------------------------------------------------------------------ database: what and where

    /**
     * What the database backup contains. Returns ['tables' => [name => ['data' => bool, 'bytes' => n]], 'bytes' =>
     * estimate of the data saved, 'full' => bool, 'folder' => site-relative backup folder of Backup & restore] or null
     * without a database.
     */
    public static function dbPlan(Manifest $to)
    {
        $tables = Db::tables();
        if (!$tables) {
            return null;
        }
        $pre = Db::prefix();
        $total = 0;
        foreach ($tables as $t) {
            $total += $t['bytes'];
        }
        $full = $total <= Config::fullDbBytes();
        $alters = array();
        foreach ($to->upgrade()['alters'] as $a) {
            $alters[$pre . $a] = true;
        }
        $out = array();
        $bytes = 0;
        foreach ($tables as $name => $t) {
            $data = $full || isset($alters[$name]) || $t['bytes'] <= Config::smallTableBytes();
            $out[$name] = array('data' => $data, 'bytes' => $t['data']);
            if ($data) {
                $bytes += $t['data'];
            }
        }
        return array('tables' => $out, 'bytes' => $bytes, 'full' => $full, 'folder' => self::backupFolder(),
            'partial_folder' => self::backupFolder() . self::PARTIAL_DIR . '/');
    }

    /**
     * A new database backup job (kept in the run state): name (a time stamp no other backup uses), the update's own
     * folder it is written into (stage), the backup folder of Backup & restore (home), tables and the part each one goes
     * into (pnum), the slice cursor and the records of every written member. null without a database. The folder gets a
     * README.txt saying what it holds.
     */
    public static function dbJob(Manifest $to, $label)
    {
        $p = self::dbPlan($to);
        if ($p === null) {
            return null;
        }
        // named in the site's time zone, like the sets Backup & restore makes itself (the framework applies the site's
        // time zone there; this engine runs without the site's code: Util::siteDate)
        $t = time();
        $root = Util::abs($p['folder']);
        while (glob($root . Util::siteDate('Ymd-His', $t) . '-*') || is_dir($root . self::PARTIAL_DIR . '/' . Util::siteDate('Ymd-His', $t))) {
            $t++;
        }
        $name = Util::siteDate('Ymd-His', $t);
        $stage = $p['folder'] . self::PARTIAL_DIR . '/' . $name . '/';
        $job = array('name' => $name, 'folder' => $stage, 'stage' => $stage, 'home' => $p['folder'], 'tables' => $p['tables'],
            'pnum' => self::groupTables($p['tables'], self::partSize()), 'pstart' => array(), 'i' => 0, 'last' => null, 'parts' => array(),
            'rows' => 0, 'label' => (string)$label, 'full' => $p['full'], 'bytes' => $p['bytes'], 'gz' => function_exists('gzopen'),
            'sizes' => array(), 'members' => array(), 'trows' => array(), 'done' => false, 'v_i' => 0, 'v_m' => 0, 'at' => $t,
            'published' => false, 'layout' => 2);
        $dir = Util::abs($stage);
        if (!is_dir($dir) && !Util::mkdirs($dir)) {
            return $job; // dbStep reports the folder problem
        }
        $data = array();
        $structure = array();
        foreach ($p['tables'] as $tn => $row) {
            if ($row['data']) {
                $data[] = $tn;
            } else {
                $structure[] = $tn;
            }
        }
        if ($p['full']) {
            Util::writeAtomic($dir . 'README.txt', "JavCMS update backup (whole database), " . Util::siteDate('Y-m-d H:i:s', $t) . ': ' . $label . "\r\n\r\n"
                . "The update writes its database backup here first. When every part has been written and read back, the parts move\r\n"
                . "into " . $p['folder'] . " (Admins & backup > Backup & restore lists them there) and this folder is removed.\r\n"
                . "A set that stays here is unfinished (the update was cancelled or failed before it changed anything): it is not\r\n"
                . "a backup, and it is removed automatically.\r\n");
        } else {
            Util::writeAtomic($dir . 'README.txt', "JavCMS update backup (PARTIAL), " . Util::siteDate('Y-m-d H:i:s', $t) . ': ' . $label . "\r\n\r\n"
                . "This is NOT a full backup of the database. It holds the DATA of these tables:\r\n  " . implode(', ', $data) . "\r\n"
                . "and only the STRUCTURE of the other " . count($structure) . " tables (CREATE TABLE IF NOT EXISTS: restoring a\r\n"
                . "structure-only part never empties or replaces a table).\r\n\r\n"
                . "The update's rollback restores the tables it needs from here. Admins & backup > Backup & restore does not list\r\n"
                . "this folder; make a full backup there when you need one. It is removed automatically " . Config::KEEP_DAYS . " days after the update.\r\n");
        }
        return $job;
    }

    /** Site-relative backup folder of Backup & restore (db.backup_path of the settings, default application/data/backup/). */
    public static function backupFolder()
    {
        $raw = @file_get_contents(Util::abs('application/extra/maccms.php'));
        $path = 'application/data/backup/';
        if (is_string($raw) && preg_match("/'backup_path'\s*=>\s*'([^']{1,200})'/", $raw, $m)) {
            $p = trim(str_replace('\\', '/', $m[1]));
            $p = preg_replace('#^\./#', '', $p);
            if ($p !== '' && Paths::invalid(rtrim($p, '/')) === '') {
                $path = rtrim($p, '/') . '/';
            }
        }
        return $path;
    }

    /** Part size of the page's own backups (db.part_size of the settings), 1 to 256 MB; 20 MB when the settings name none. */
    public static function partSize()
    {
        $raw = @file_get_contents(Util::abs('application/extra/maccms.php'));
        if (is_string($raw) && preg_match("/'part_size'\s*=>\s*'?(\d{1,12})'?/", $raw, $m)) {
            return (int)max(1048576, min(268435456, (int)$m[1]));
        }
        return self::PART_DEFAULT;
    }

    /**
     * The part number of every table: small tables share a part until it would hold more than $limit bytes of data (or
     * GROUP_MAX_TABLES tables); a table of half the limit or more gets a part of its own. Order: the order of $tables.
     */
    public static function groupTables(array $tables, $limit)
    {
        $out = array();
        $part = 0;
        $acc = 0;
        $count = 0;
        $open = false;
        foreach ($tables as $name => $t) {
            $b = !empty($t['data']) ? (int)($t['bytes'] ?? 0) : 0;
            if ($b * 2 >= $limit) {
                $part++;
                $out[(string)$name] = $part;
                $open = false;
                continue;
            }
            if (!$open || $acc + $b > $limit || $count >= self::GROUP_MAX_TABLES) {
                $part++;
                $acc = 0;
                $count = 0;
                $open = true;
            }
            $out[(string)$name] = $part;
            $acc += $b;
            $count++;
        }
        return $out;
    }

    /** Part number of table $table (index $i); a job of an older engine has one table per part. */
    private static function partOf(array $job, $table, $i)
    {
        return isset($job['pnum'][$table]) ? (int)$job['pnum'][$table] : (int)$i + 1;
    }

    /** File name of part $n of the set named $name. */
    private static function partName(array $job, $name, $n)
    {
        return $name . '-' . (int)$n . '.sql' . (!empty($job['gz']) ? '.gz' : '');
    }

    /** Number of parts of the job. */
    private static function partCount(array $job)
    {
        $n = 0;
        $i = 0;
        foreach (array_keys($job['tables']) as $t) {
            $n = max($n, self::partOf($job, $t, $i));
            $i++;
        }
        return $n;
    }

    /**
     * Where table number $i begins in its part: 0 for the first table of a part, else the recorded end of the table before
     * it (that table is complete: the cursor moves on only after a finished table).
     */
    private static function tableStart(array $job, array $names, $i)
    {
        if ($i <= 0) {
            return 0;
        }
        if (self::partOf($job, $names[$i - 1], $i - 1) !== self::partOf($job, $names[$i], $i)) {
            return 0;
        }
        return (int)($job['sizes'][$names[$i - 1]] ?? 0);
    }

    /** Index of the first table of the part that table number $i belongs to. */
    private static function firstOfPart(array $job, array $names, $i)
    {
        $p = self::partOf($job, $names[$i], $i);
        while ($i > 0 && self::partOf($job, $names[$i - 1], $i - 1) === $p) {
            $i--;
        }
        return $i;
    }

    /** True when table number $i is the last one of its part. */
    private static function lastOfPart(array $job, array $names, $i)
    {
        return !isset($names[$i + 1]) || self::partOf($job, $names[$i + 1], $i + 1) !== self::partOf($job, $names[$i], $i);
    }

    // ------------------------------------------------------------------ database: writing

    /**
     * One slice of the database backup (job of dbJob()). Returns ['state' => 'more'|'done'|'error', 'error', 'k', 'v'].
     */
    public static function dbStep(array &$job, $deadline)
    {
        $pdo = Db::pdo();
        if ($pdo === null) {
            return self::err('the database is not reachable', 'e_db_unreachable');
        }
        $folder = Util::abs($job['folder']);
        if (!is_dir($folder) && !Util::mkdirs($folder)) {
            return self::err('cannot create the backup folder ' . $job['folder'], 'e_backup_folder_db', array('folder' => $job['folder']));
        }
        $job += array('sizes' => array(), 'members' => array(), 'trows' => array(), 'gz' => function_exists('gzopen'), 'pstart' => array());
        $names = array_keys($job['tables']);
        $gz = !empty($job['gz']);
        $fault = Config::fault('db_fail');
        while ($job['i'] < count($names)) {
            $table = $names[$job['i']];
            if (is_array($fault) && $job['i'] >= (int)($fault['after'] ?? 0)) {
                // test mode only: a database backup that fails after N tables (a full disk, a lost connection)
                return self::err('database backup of ' . $table . ': write failed (test fault)', 'e_backup_db_table',
                    array('table' => $table, 'why' => 'write failed (test fault)'));
            }
            $part = self::partOf($job, $table, $job['i']);
            $file = $folder . self::partName($job, $job['name'], $part);
            $job['parts'][$table] = $job['folder'] . basename($file);
            if ($job['last'] !== null) {
                // the table continues: back to what the last finished slice wrote (a slice that died may have appended
                // more, or a cut-off gzip member); a part shorter than that cannot be trusted: the table starts again
                if (!self::cutTo($file, (int)($job['sizes'][$table] ?? -1))) {
                    $job['last'] = null;
                }
            }
            if ($job['last'] === null) {
                // the table starts (again): its part goes back to where the table begins
                $start = self::tableStart($job, $names, $job['i']);
                if ($start === 0) {
                    if (is_file($file) && !@unlink($file)) {
                        return self::err('cannot replace the unfinished database backup part ' . basename($file), 'e_backup_db_write');
                    }
                } elseif (!self::cutTo($file, $start)) {
                    // the part lost what the tables before this one wrote: the whole part is written again
                    $first = self::firstOfPart($job, $names, $job['i']);
                    for ($k = $first; $k < $job['i']; $k++) {
                        $tn = $names[$k];
                        $job['rows'] -= (int)($job['trows'][$tn] ?? 0);
                        unset($job['sizes'][$tn], $job['members'][$tn], $job['trows'][$tn], $job['pstart'][$tn]);
                    }
                    $job['i'] = $first;
                    if (is_file($file) && !@unlink($file)) {
                        return self::err('cannot replace the unfinished database backup part ' . basename($file), 'e_backup_db_write');
                    }
                    continue;
                }
                $job['rows'] -= (int)($job['trows'][$table] ?? 0);
                $job['pstart'][$table] = $start;
                $job['sizes'][$table] = $start;
                $job['members'][$table] = array();
                $job['trows'][$table] = 0;
            }
            $fh = $gz ? @gzopen($file, 'ab6') : @fopen($file, 'ab');
            if (!$fh) {
                return self::err('cannot write the database backup (disk full or no permission)', 'e_backup_db_write');
            }
            $m = array('bytes' => 0, 'stmts' => 0, 'rows' => 0, 'crc' => hash_init('crc32b'));
            $write = function ($text, $statements) use ($fh, $gz, &$m) {
                $len = strlen($text);
                $w = $gz ? @gzwrite($fh, $text) : @fwrite($fh, $text);
                if ($w !== $len) {
                    return false;
                }
                hash_update($m['crc'], $text);
                $m['bytes'] += $len;
                $m['stmts'] += $statements;
                return true;
            };
            $state = 'next';
            try {
                if ($job['last'] === null) {
                    $create = $pdo->query('SHOW CREATE TABLE ' . Db::quoteName($table))->fetch(\PDO::FETCH_NUM);
                    $data = !empty($job['tables'][$table]['data']);
                    $createSql = trim((string)$create[1]);
                    if (!$data) {
                        $createSql = (string)preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $createSql, 1);
                    }
                    $head = "-- -----------------------------\n" . self::MARK . ': ' . $job['label'] . "\n-- Part : #" . $part
                        . "\n-- Date : " . Util::siteDate('Y-m-d H:i:s') . ($data ? '' : "\n-- Structure only: restoring this part creates the table when it is missing and never empties it")
                        . "\n-- -----------------------------\n\nSET FOREIGN_KEY_CHECKS = 0;\n\n"
                        . "-- -----------------------------\n-- Table structure for `" . $table . "`\n-- -----------------------------\n"
                        . ($data ? 'DROP TABLE IF EXISTS ' . Db::quoteName($table) . ";\n" : '') . $createSql . ";\n\n";
                    if (!$write($head, $data ? 3 : 2)) {
                        throw new \RuntimeException('write failed (disk full?)');
                    }
                    $job['last'] = '';
                    $job['key'] = self::primaryKey($pdo, $table);
                    $job['bin'] = self::binaryColumns($pdo, $table);
                }
                if (!empty($job['tables'][$table]['data'])) {
                    while (true) {
                        $rows = self::rows($pdo, $table, $job['key'], $job['last']);
                        if (!$rows) {
                            break;
                        }
                        $sql = '';
                        foreach ($rows as $row) {
                            $vals = array();
                            foreach ($row as $col => $v) {
                                if ($v === null) {
                                    $vals[] = 'NULL';
                                } elseif (isset($job['bin'][$col])) {
                                    $vals[] = $v === '' ? "''" : '0x' . bin2hex((string)$v);
                                } else {
                                    $vals[] = $pdo->quote((string)$v);
                                }
                            }
                            $sql .= ($sql === '' ? 'INSERT INTO ' . Db::quoteName($table) . ' VALUES ' : ',') . '(' . implode(',', $vals) . ')';
                            $m['rows']++;
                            if (strlen($sql) >= self::STATEMENT_BYTES) {
                                if (!$write($sql . ";\n", 1)) {
                                    throw new \RuntimeException('write failed (disk full?)');
                                }
                                $sql = '';
                            }
                        }
                        if ($sql !== '' && !$write($sql . ";\n", 1)) {
                            throw new \RuntimeException('write failed (disk full?)');
                        }
                        $lastRow = end($rows);
                        $job['last'] = $job['key'] !== '' ? (string)$lastRow[$job['key']] : (string)((int)$job['last'] + count($rows));
                        if (count($rows) < self::ROWS_PER_QUERY) {
                            break;
                        }
                        if (microtime(true) >= $deadline) {
                            $state = 'more';
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $gz ? @gzclose($fh) : @fclose($fh);
                return self::err('database backup of ' . $table . ': ' . Util::cut(Util::redact($e->getMessage()), 200), 'e_backup_db_table',
                    array('table' => $table, 'why' => Util::cut(Util::redact($e->getMessage()), 200)));
            }
            // the member is complete only when it is closed (gzip trailer): its record is what the next slice relies on
            if (!($gz ? @gzclose($fh) : @fclose($fh))) {
                return self::err('cannot write the database backup (disk full or no permission)', 'e_backup_db_write');
            }
            clearstatcache(true, $file);
            $size = (int)@filesize($file);
            $job['members'][$table][] = array($size, $m['bytes'], hash_final($m['crc']), $m['stmts'], $m['rows']);
            $job['sizes'][$table] = $size;
            $job['trows'][$table] = (int)$job['trows'][$table] + $m['rows'];
            $job['rows'] += $m['rows'];
            if ($state === 'more') {
                return array('state' => 'more', 'error' => '');
            }
            $job['i']++;
            $job['last'] = null;
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        return array('state' => $job['i'] >= count($names) ? 'done' : 'more', 'error' => '');
    }

    /** Cuts $file back to $size bytes (the end of the last complete member). False when it is shorter (or unreadable). */
    private static function cutTo($file, $size)
    {
        clearstatcache(true, $file);
        if ($size < 0 || !is_file($file)) {
            return false;
        }
        $now = (int)@filesize($file);
        if ($now === $size) {
            return true;
        }
        if ($now < $size) {
            return false;
        }
        $fh = @fopen($file, 'r+b');
        if (!$fh) {
            return false;
        }
        $ok = @ftruncate($fh, $size);
        @fflush($fh);
        Util::fsync($fh);
        @fclose($fh);
        clearstatcache(true, $file);
        return $ok && (int)@filesize($file) === $size;
    }

    /** The single integer primary key column of $table, or '' (then rows are read by offset). */
    private static function primaryKey(\PDO $pdo, $table)
    {
        try {
            $keys = $pdo->query('SHOW KEYS FROM ' . Db::quoteName($table) . " WHERE Key_name = 'PRIMARY'")->fetchAll();
            if (count($keys) !== 1) {
                return '';
            }
            $col = (string)$keys[0]['Column_name'];
            $type = $pdo->query('SHOW COLUMNS FROM ' . Db::quoteName($table) . ' LIKE ' . $pdo->quote($col))->fetch();
            return is_array($type) && preg_match('/int/i', (string)$type['Type']) ? $col : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Binary columns of $table (written as hex literals). */
    private static function binaryColumns(\PDO $pdo, $table)
    {
        $out = array();
        try {
            foreach ($pdo->query('SHOW COLUMNS FROM ' . Db::quoteName($table))->fetchAll() as $c) {
                if (preg_match('/blob|binary/i', (string)$c['Type'])) {
                    $out[(string)$c['Field']] = true;
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    private static function rows(\PDO $pdo, $table, $key, $last)
    {
        if ($key !== '') {
            $where = $last === '' ? '' : ' WHERE ' . Db::quoteName($key) . ' > ' . $pdo->quote((string)$last);
            $sql = 'SELECT * FROM ' . Db::quoteName($table) . $where . ' ORDER BY ' . Db::quoteName($key) . ' LIMIT ' . self::ROWS_PER_QUERY;
        } else {
            $sql = 'SELECT * FROM ' . Db::quoteName($table) . ' LIMIT ' . (int)$last . ', ' . self::ROWS_PER_QUERY;
        }
        return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ------------------------------------------------------------------ database: reading back

    /**
     * One slice of reading the finished backup back: every member of every table is inflated (gzip trailer CRC by zlib)
     * and its length, CRC32 and statement count compared with what dbStep recorded; a part ends exactly where its last
     * table ends. Returns 'more'|'done'|'error'.
     */
    public static function dbVerifyStep(array &$job, $deadline)
    {
        $names = array_keys($job['tables']);
        $job += array('v_i' => 0, 'v_m' => 0);
        $gz = !empty($job['gz']);
        while ($job['v_i'] < count($names)) {
            $table = $names[$job['v_i']];
            $rel = (string)($job['parts'][$table] ?? '');
            $file = Util::abs($rel);
            $members = (array)($job['members'][$table] ?? array());
            clearstatcache(true, $file);
            if ($rel === '' || !is_file($file)) {
                return self::err('the backup part of ' . $table . ' is missing', 'e_backup_verify', array('table' => $table, 'why' => 'missing'));
            }
            $size = (int)@filesize($file);
            $want = (int)($job['sizes'][$table] ?? -1);
            if (!$members || (self::lastOfPart($job, $names, $job['v_i']) ? $size !== $want : $size < $want)) {
                return self::err('the backup part of ' . $table . ' has another size than was written', 'e_backup_verify',
                    array('table' => $table, 'why' => 'size'));
            }
            $fh = @fopen($file, 'rb');
            if (!$fh) {
                return self::err('the backup part of ' . $table . ' cannot be read', 'e_backup_verify', array('table' => $table, 'why' => 'read'));
            }
            while ($job['v_m'] < count($members)) {
                $start = $job['v_m'] > 0 ? (int)$members[$job['v_m'] - 1][0] : (int)($job['pstart'][$table] ?? 0);
                $why = self::checkMember($fh, $start, $members[$job['v_m']], $gz);
                if ($why !== '') {
                    fclose($fh);
                    return self::err('the backup part of ' . $table . ' does not read back correctly (' . $why . ')', 'e_backup_verify',
                        array('table' => $table, 'why' => $why));
                }
                $job['v_m']++;
                if (microtime(true) >= $deadline && $job['v_m'] < count($members)) {
                    fclose($fh);
                    return array('state' => 'more', 'error' => '');
                }
            }
            fclose($fh);
            $job['v_i']++;
            $job['v_m'] = 0;
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        return array('state' => $job['v_i'] >= count($names) ? 'done' : 'more', 'error' => '');
    }

    /** Checks one member [$start, end) of a part against its record [end, bytes, crc32b, statements, rows]; '' when right. */
    private static function checkMember($fh, $start, array $rec, $gz)
    {
        $end = (int)$rec[0];
        $left = $end - (int)$start;
        if ($left < 0) {
            return 'member out of order';
        }
        if (fseek($fh, (int)$start) !== 0) {
            return 'seek failed';
        }
        $crc = hash_init('crc32b');
        $bytes = 0;
        $count = array('open' => false, 'carry' => '', 'n' => 0);
        $inf = null;
        if ($gz && $left > 0) {
            $inf = @inflate_init(ZLIB_ENCODING_GZIP);
            if (!$inf) {
                return 'inflate not available';
            }
        }
        while ($left > 0) {
            $chunk = fread($fh, (int)min(65536, $left));
            if ($chunk === false || $chunk === '') {
                return 'file ended early';
            }
            $left -= strlen($chunk);
            if ($inf !== null) {
                $data = '';
                // small pieces: a member of one slice inflates to at most a few MB at a time
                foreach (str_split($chunk, 8192) as $i => $piece) {
                    $out = @inflate_add($inf, $piece, $left > 0 || ($i + 1) * 8192 < strlen($chunk) ? ZLIB_SYNC_FLUSH : ZLIB_FINISH);
                    if ($out === false) {
                        return 'damaged compressed data';
                    }
                    $data .= $out;
                }
            } else {
                $data = $chunk;
            }
            $bytes += strlen($data);
            if ($bytes > (int)$rec[1]) {
                return 'more data than written';
            }
            hash_update($crc, $data);
            self::countStatements($count, $data);
        }
        if ($inf !== null && function_exists('inflate_get_status') && inflate_get_status($inf) !== ZLIB_STREAM_END) {
            return 'cut-off gzip member';
        }
        self::countStatements($count, '', true);
        if ($bytes !== (int)$rec[1]) {
            return 'length differs';
        }
        if (!hash_equals((string)$rec[2], hash_final($crc))) {
            return 'CRC32 differs';
        }
        if ($count['n'] !== (int)$rec[3] || $count['open']) {
            return 'statement count differs';
        }
        return '';
    }

    /** Counts statements the way dbRestoreStep splits them (line based; comment and empty lines between statements). */
    private static function countStatements(array &$c, $data, $final = false)
    {
        $text = $c['carry'] . $data;
        $lines = explode("\n", $text);
        // the last piece may be the start of a line that continues in the next chunk; at the end it is a line of its own
        $c['carry'] = $final ? '' : (string)array_pop($lines);
        foreach ($lines as $line) {
            if (!$c['open'] && (strncmp($line, '--', 2) === 0 || trim($line) === '')) {
                continue;
            }
            $c['open'] = substr(rtrim($line), -1) !== ';';
            if (!$c['open']) {
                $c['n']++;
            }
        }
    }

    // ------------------------------------------------------------------ database: into Backup & restore

    /**
     * The name a whole-database set gets in the backup folder of Backup & restore: its own when no backup made since took
     * it (the page's own backups are named by their time too), else the next free second. The caller saves it in the job
     * BEFORE publishStep() moves the first part, so a step that dies in between finds the same name again.
     */
    public static function publishName(array $job)
    {
        $home = Util::abs((string)($job['home'] ?? self::backupFolder()));
        $t = (int)($job['at'] ?? time());
        for ($i = 0; $i < 3600; $i++) {
            $name = Util::siteDate('Ymd-His', $t + $i);
            $taken = false;
            foreach ((array)glob($home . $name . '-*') as $f) {
                // a part this job already moved there (an earlier attempt of this step) does not count
                if (!preg_match('/^' . preg_quote($name, '/') . '-\d+\.sql(\.gz)?$/', basename((string)$f)) || !self::isOwnPart((string)$f)
                    || ($job['pub_name'] ?? '') !== $name) {
                    $taken = true;
                    break;
                }
            }
            if (!$taken) {
                return $name;
            }
        }
        return Util::siteDate('Ymd-His', $t + 3600);
    }

    /**
     * Moves the parts of a whole-database set that was written and read back into the backup folder of Backup & restore
     * (rename in the same volume), under the name the job keeps in pub_name, and removes the update's folder of the set.
     * Repeatable: a part already moved is skipped. Returns ['state' => 'done'|'more'|'error', 'error', 'k', 'v'].
     */
    public static function publishStep(array &$job, $deadline)
    {
        if (!empty($job['published']) || empty($job['full'])) {
            return array('state' => 'done', 'error' => '');
        }
        $pub = (string)($job['pub_name'] ?? '');
        if (!preg_match('/^\d{8}-\d{6}$/', $pub)) {
            return self::err('the database backup has no name for Backup & restore', 'e_backup_publish', array('why' => 'name'));
        }
        $home = (string)($job['home'] ?? self::backupFolder());
        $homeAbs = Util::abs($home);
        if (!is_dir($homeAbs) && !Util::mkdirs($homeAbs)) {
            return self::err('cannot create the backup folder ' . $home, 'e_backup_publish', array('why' => $home));
        }
        $stage = Util::abs((string)$job['stage']);
        $n = self::partCount($job);
        for ($p = 1; $p <= $n; $p++) {
            $src = $stage . self::partName($job, $job['name'], $p);
            $dst = $homeAbs . self::partName($job, $pub, $p);
            if (is_file($src)) {
                if (is_file($dst) && !@unlink($dst)) {
                    return self::err('cannot replace ' . basename($dst) . ' in ' . $home, 'e_backup_publish', array('why' => basename($dst)));
                }
                $r = Util::renameRetry($src, $dst, 2.0);
                if (!$r['ok']) {
                    // another volume (a mount inside the backup folder): copy, then remove the original
                    $sha = null;
                    if (!Util::copyAtomic($src, $dst, 0644, $sha) || !@unlink($src)) {
                        return self::err('cannot move ' . basename($src) . ' into ' . $home, 'e_backup_publish', array('why' => basename($src)));
                    }
                }
            } elseif (!is_file($dst)) {
                return self::err('part ' . $p . ' of the database backup is missing', 'e_backup_publish', array('why' => 'part ' . $p));
            }
            if (microtime(true) >= $deadline && $p < $n) {
                return array('state' => 'more', 'error' => '');
            }
        }
        $i = 0;
        foreach (array_keys($job['tables']) as $t) {
            $job['parts'][$t] = $home . self::partName($job, $pub, self::partOf($job, $t, $i));
            $i++;
        }
        $job['folder'] = $home;
        $job['name'] = $pub;
        $job['published'] = true;
        @unlink($stage . 'README.txt');
        Util::removeTree($stage);
        return array('state' => 'done', 'error' => '');
    }

    // ------------------------------------------------------------------ database: restoring (rollback)

    /** Names of the restore copy and of the replaced table of $table (at most 64 characters, the MySQL limit). */
    public static function restoreNames($table)
    {
        $table = (string)$table;
        $base = strlen($table) + strlen(self::SHADOW) <= 64 ? $table : substr($table, 0, 64 - strlen(self::SHADOW) - 9) . '_' . substr(sha1($table), 0, 8);
        return array($base . self::SHADOW, $base . self::ASIDE);
    }

    /**
     * One slice of restoring the tables $job['restore'] from their backup parts. Each table: its part is loaded into a new
     * table (stage "load", progress r_off = statements done), then the live table and the copy swap names in one RENAME
     * ("swap"), then the replaced table is dropped ("drop"). $restart: the previous slice died (its progress was not saved),
     * so a table being loaded starts again from its first statement; a duplicate key while loading means the same and
     * restarts the table too (at most RESTORE_RESTARTS times). Returns ['state' => 'more'|'done'|'error', 'error', 'k',
     * 'v', 'fatal' (the backup itself cannot be restored)].
     */
    public static function dbRestoreStep(array &$job, $deadline, $restart = false)
    {
        $pdo = Db::pdo();
        if ($pdo === null) {
            return self::err('the database is not reachable', 'e_db_unreachable');
        }
        $list = array_values((array)($job['restore'] ?? array()));
        $job += array('r_i' => 0, 'r_off' => 0, 'r_stage' => 'load', 'r_restarts' => array(), 'r_mode' => '');
        if ($restart && $job['r_stage'] === 'load' && $job['r_off'] > 0) {
            $job['r_off'] = 0;
        }
        // a step that died (killed web server, PHP time limit) can leave its database connection running its last
        // statement: the server finishes it and keeps the table's locks meanwhile, so this step's DROP / RENAME would wait
        // for it until PHP's own time limit cut the step off. That connection is ended first, and no statement of this
        // step waits for a lock past the step's time (a blocked step ends as "busy" and the next one tries again).
        self::endStaleConnection($pdo, $list, $restart);
        self::boundLockWait($pdo, $deadline);
        while ($job['r_i'] < count($list)) {
            $table = $list[$job['r_i']];
            list($shadow, $aside) = self::restoreNames($table);
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                if ($job['r_stage'] === 'load') {
                    $r = self::loadTable($pdo, $job, $table, $shadow, $deadline);
                    if ($r['state'] !== 'done') {
                        return $r;
                    }
                    $job['r_stage'] = $job['r_mode'] === 'inplace' ? 'drop' : 'swap';
                }
                if ($job['r_stage'] === 'swap') {
                    $hasShadow = self::tableExists($pdo, $shadow);
                    if ($hasShadow) {
                        if (self::tableExists($pdo, $aside)) {
                            $pdo->exec('DROP TABLE IF EXISTS ' . Db::quoteName($aside));
                        }
                        // one statement: both names change together, the table is never missing
                        $sql = self::tableExists($pdo, $table)
                            ? 'RENAME TABLE ' . Db::quoteName($table) . ' TO ' . Db::quoteName($aside) . ', ' . Db::quoteName($shadow) . ' TO ' . Db::quoteName($table)
                            : 'RENAME TABLE ' . Db::quoteName($shadow) . ' TO ' . Db::quoteName($table);
                        $busy = self::renameOrWait($pdo, $sql, $job, $table, $deadline);
                        if ($busy !== null) {
                            return $busy;
                        }
                    } elseif (!self::tableExists($pdo, $table)) {
                        return self::err('the restored table ' . $table . ' is missing', 'e_restore_missing', array('table' => $table));
                    }
                    $job['r_stage'] = 'drop';
                }
                if ($job['r_stage'] === 'drop') {
                    // the replaced copy goes now when the database server lets it go within a few seconds; a server that
                    // still works on it (InnoDB's background statistics of a table changed in bulk a moment ago: such a
                    // DROP waited about 50 s on MariaDB) does not hold the rollback: the copy is dropped later
                    if (!self::dropBounded($pdo, $aside, $deadline)) {
                        $job['drop_later'][] = $aside;
                    }
                    self::statsDefault($pdo, $table, $job, $deadline);
                    $job['r_i']++;
                    $job['r_off'] = 0;
                    $job['r_stage'] = 'load';
                    $job['r_mode'] = '';
                }
            } catch (\Throwable $e) {
                if ($e instanceof \PDOException && self::lockTimeout($e)) {
                    // another connection holds the table (the statement of a step that died, still running): not a
                    // failure of the restore - the next step tries again (up to FILE_BUSY_SECONDS, like a held file)
                    return self::lockedResult($job, $table);
                }
                return self::err('restoring ' . $table . ': ' . Util::cut(Util::redact($e->getMessage()), 200), 'e_restore_table',
                    array('table' => $table, 'why' => Util::cut(Util::redact($e->getMessage()), 200)));
            }
            if (microtime(true) >= $deadline && $job['r_i'] < count($list)) {
                return array('state' => 'more', 'error' => '');
            }
        }
        // a replaced copy the server held on to gets one more bounded try, else the clean-up drops it (dropLater())
        $left = array();
        foreach (array_unique((array)($job['drop_later'] ?? array())) as $t) {
            if (!self::dropBounded($pdo, (string)$t, $deadline)) {
                $left[] = (string)$t;
            }
        }
        $job['drop_left'] = $left;
        if ($left) {
            self::rememberDrops($left);
        }
        Util::removeTree(Util::abs(self::restoreTmp($job)));
        if (Util::dir() !== '') {
            @unlink(Util::dir() . 'restore-connection.txt');
        }
        return array('state' => 'done', 'error' => '');
    }

    /**
     * DROP TABLE IF EXISTS $name, waiting at most a few seconds (never past the step's deadline): MariaDB ends the
     * statement after max_statement_time, MySQL after the session's bounded lock waits. False when the server did not let
     * the table go in time (the caller drops it later); other database errors are thrown.
     */
    private static function dropBounded(\PDO $pdo, $name, $deadline)
    {
        $secs = max(1, min(8, (int)floor((float)$deadline - microtime(true)) - 1));
        $sql = 'DROP TABLE IF EXISTS ' . Db::quoteName((string)$name);
        if (self::isMariaDb($pdo)) {
            $sql = 'SET STATEMENT max_statement_time=' . $secs . ' FOR ' . $sql;
        }
        try {
            $pdo->exec($sql);
            return true;
        } catch (\PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            // 1969 max_statement_time exceeded, 3024 / 1317 interrupted, 1205 / 3572 lock waits
            if (in_array($code, array(1969, 3024, 1317, 1205, 3572), true) || stripos($e->getMessage(), 'interrupted') !== false
                || self::lockTimeout($e)) {
                return false;
            }
            throw $e;
        }
    }

    /** True for a MariaDB server (cached per connection object). */
    private static function isMariaDb(\PDO $pdo)
    {
        static $seen = array();
        $k = spl_object_hash($pdo);
        if (!isset($seen[$k])) {
            try {
                $seen[$k] = stripos((string)$pdo->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
            } catch (\Throwable $e) {
                $seen[$k] = false;
            }
        }
        return $seen[$k];
    }

    /**
     * The restored table was created with STATS_PERSISTENT=0 (its copy needed no background statistics while it was
     * loaded and swapped in): its own setting comes back now, bounded like dropBounded(); a server that does not let it
     * within the time keeps transient statistics for that table (said in the job: 'stats_left').
     */
    private static function statsDefault(\PDO $pdo, $table, array &$job, $deadline)
    {
        if (empty($job['stats0'][$table])) {
            return;
        }
        $secs = max(1, min(8, (int)floor((float)$deadline - microtime(true)) - 1));
        $sql = 'ALTER TABLE ' . Db::quoteName((string)$table) . ' STATS_PERSISTENT=DEFAULT';
        if (self::isMariaDb($pdo)) {
            $sql = 'SET STATEMENT max_statement_time=' . $secs . ' FOR ' . $sql;
        }
        try {
            $pdo->exec($sql);
            unset($job['stats0'][$table]);
        } catch (\PDOException $e) {
            $job['stats_left'][] = (string)$table;
        }
    }

    /** Names of replaced table copies that the clean-up drops (runtime/update/drop-later.php). */
    private static function rememberDrops(array $names)
    {
        $dir = Util::dir();
        if ($dir === '') {
            return;
        }
        $d = Util::loadData($dir . 'drop-later.php');
        $list = is_array($d) ? (array)($d['tables'] ?? array()) : array();
        foreach ($names as $n) {
            if (preg_match('/^[A-Za-z0-9_$]{1,64}$/', (string)$n) && substr((string)$n, -strlen(self::ASIDE)) === self::ASIDE) {
                $list[] = (string)$n;
            }
        }
        Util::saveData($dir . 'drop-later.php', array('tables' => array_values(array_unique($list))));
    }

    /**
     * Housekeeping: drops the replaced table copies a rollback could not drop in time (only names of the update's own
     * copies: "<table>__jcupd_old"), each bounded; what is left waits for the next call. True when none is left.
     */
    public static function dropLater($deadline = null)
    {
        $file = Util::root() . 'runtime/update/drop-later.php';
        if (!is_file($file)) {
            return true;
        }
        $d = Util::loadData($file);
        $list = is_array($d) ? array_values((array)($d['tables'] ?? array())) : array();
        $pdo = $list ? Db::pdo() : null;
        if ($list && $pdo === null) {
            return false;
        }
        $left = array();
        $deadline = $deadline === null ? microtime(true) + 6.0 : (float)$deadline;
        foreach ($list as $t) {
            $t = (string)$t;
            if (!preg_match('/^[A-Za-z0-9_$]{1,64}$/', $t) || substr($t, -strlen(self::ASIDE)) !== self::ASIDE) {
                continue;
            }
            try {
                if (microtime(true) >= $deadline || !self::dropBounded($pdo, $t, max($deadline, microtime(true) + 3.0))) {
                    $left[] = $t;
                }
            } catch (\Throwable $e) {
                $left[] = $t;
            }
        }
        if ($left) {
            Util::saveData($file, array('tables' => $left));
            return false;
        }
        @unlink($file);
        return true;
    }

    /**
     * Ends the database connection of the restore step that ran before this one when that step died and its connection
     * still runs a statement on one of the restored tables (or their restore copies). Only a connection of the same
     * database user whose current statement names such a table is ended (a user may end its own connections; an id is
     * never taken on trust from the file alone). The id of this step's connection is written for the next step.
     */
    private static function endStaleConnection(\PDO $pdo, array $tables, $restart)
    {
        $file = Util::dir() !== '' ? Util::dir() . 'restore-connection.txt' : '';
        try {
            $me = (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        } catch (\Throwable $e) {
            return;
        }
        $prev = $file !== '' ? (int)trim((string)@file_get_contents($file)) : 0;
        if ($restart && $prev > 0 && $prev !== $me) {
            try {
                $st = $pdo->prepare('SELECT ID, INFO FROM information_schema.PROCESSLIST WHERE ID = ? AND USER = SUBSTRING_INDEX(CURRENT_USER(), \'@\', 1)');
                $st->execute(array($prev));
                $row = $st->fetch(\PDO::FETCH_ASSOC);
                $st->closeCursor();
                $info = is_array($row) ? (string)($row['INFO'] ?? '') : '';
                $ours = false;
                foreach ($tables as $t) {
                    list($shadow, $aside) = self::restoreNames((string)$t);
                    foreach (array((string)$t, $shadow, $aside) as $name) {
                        if ($name !== '' && $info !== '' && stripos($info, $name) !== false) {
                            $ours = true;
                        }
                    }
                }
                if ($ours) {
                    $pdo->exec('KILL ' . (int)$prev);
                    // the server rolls the cut statement back before it lets go of the table: wait a moment for that
                    $until = microtime(true) + 5.0;
                    do {
                        usleep(200000);
                        $st->execute(array($prev));
                        $still = $st->fetch(\PDO::FETCH_ASSOC);
                        $st->closeCursor();
                    } while ($still && microtime(true) < $until);
                }
            } catch (\Throwable $e) {
                // gone already, or the server does not show it: the bounded lock wait below covers the rest
            }
        }
        if ($file !== '') {
            @file_put_contents($file, (string)$me);
        }
    }

    /** No statement of this step waits for a table or row lock longer than the time the step has left (at least 1 s). */
    private static function boundLockWait(\PDO $pdo, $deadline)
    {
        $secs = max(1, (int)floor((float)$deadline - microtime(true)));
        foreach (array('lock_wait_timeout', 'innodb_lock_wait_timeout') as $var) {
            try {
                $pdo->exec('SET SESSION ' . $var . ' = ' . $secs);
            } catch (\Throwable $e) {
                // an older server without the variable: the statement waits as before
            }
        }
    }

    /** True for the server's "Lock wait timeout exceeded" answer (1205), which boundLockWait() makes possible. */
    private static function lockTimeout(\PDOException $e)
    {
        return (int)($e->errorInfo[1] ?? 0) === 1205 || stripos($e->getMessage(), 'Lock wait timeout exceeded') !== false;
    }

    /** The "busy" answer of a restore step that met a locked table (the next step tries again); fails after FILE_BUSY_SECONDS. */
    private static function lockedResult(array &$job, $table)
    {
        $since = (int)($job['r_busy_since'][$table] ?? 0);
        if ($since === 0) {
            $since = time();
            $job['r_busy_since'][$table] = $since;
        }
        if (time() - $since > self::FILE_BUSY_SECONDS) {
            $r = self::err('restoring ' . $table . ': another database connection kept the table locked for ' . (int)round((time() - $since) / 60) . ' minutes',
                'e_restore_locked', array('table' => $table, 'min' => (int)round((time() - $since) / 60)));
            $r['fatal'] = true;
            return $r;
        }
        return array('state' => 'more', 'error' => '', 'busy' => true, 'lock' => true, 'table' => $table, 'since' => $since);
    }

    /** Folder of the restore's copies of shared parts (the rollback run's own folder, else runtime/update/restore/). */
    private static function restoreTmp(array $job)
    {
        $t = (string)($job['tmp'] ?? '');
        return preg_match('#^runtime/update/[A-Za-z0-9/_-]+/$#', $t) && strpos($t, '..') === false ? $t : 'runtime/update/restore/';
    }

    /**
     * The RENAME of a restored table. On Windows the database server cannot rename a table file another program holds
     * open (a backup tool or an antivirus scanner reading the freshly written shadow table: "Error on rename ...
     * (errno: 168)", the server log says "Operating system error number 32"). That is not a failure of the restore: the
     * data waits complete in the shadow table. The rename is tried three times within the step, then the step ends
     * as "busy" and the next one tries again, for up to FILE_BUSY_SECONDS; only then does the restore fail. null = renamed.
     */
    private static function renameOrWait(\PDO $pdo, $sql, array &$job, $table, $deadline)
    {
        for ($try = 0; $try < 3; $try++) {
            try {
                $pdo->exec($sql);
                unset($job['r_busy_since'][$table]);
                return null;
            } catch (\PDOException $e) {
                if (!self::fileInUse($e)) {
                    throw $e;
                }
                if ($try < 2 && microtime(true) + 1.5 < $deadline) {
                    usleep(1000000);
                }
            }
        }
        $since = (int)($job['r_busy_since'][$table] ?? 0);
        if ($since === 0) {
            $since = time();
            $job['r_busy_since'][$table] = $since;
        }
        if (time() - $since > self::FILE_BUSY_SECONDS) {
            $r = self::err('restoring ' . $table . ': another program keeps its file in use (' . (int)round((time() - $since) / 60) . ' minutes)',
                'e_restore_busy', array('table' => $table, 'min' => (int)round((time() - $since) / 60)));
            $r['fatal'] = true;
            return $r;
        }
        return array('state' => 'more', 'error' => '', 'busy' => true, 'table' => $table, 'since' => $since);
    }

    /** True for the database's "cannot rename the table file" answer that a file held open by another program gives. */
    private static function fileInUse(\PDOException $e)
    {
        $code = (int)($e->errorInfo[1] ?? 0);
        $msg = $e->getMessage();
        // 1025 = ER_ERROR_ON_RENAME; errno 150 / 152 are foreign-key problems (permanent), everything else is the engine
        // failing on the file (168 = generic engine error with OS error 32 on Windows)
        return ($code === 1025 || stripos($msg, 'Error on rename') !== false) && !preg_match('/errno:\s*15[02]\b/', $msg);
    }

    /**
     * The file the restore of $table reads: its part when the table is alone in it, else a copy of the table's own byte
     * range of the shared part (its gzip members only: other tables' statements never reach the restore), made once in
     * the restore's folder ($job['tmp']). '' when the part is missing or the copy cannot be made.
     */
    private static function tableFile(array $job, $table)
    {
        $rel = (string)($job['parts'][$table] ?? '');
        $file = Util::abs($rel);
        if ($rel === '' || !is_file($file)) {
            return '';
        }
        $start = (int)($job['pstart'][$table] ?? 0);
        $end = (int)($job['sizes'][$table] ?? 0);
        clearstatcache(true, $file);
        $size = (int)@filesize($file);
        if ($start === 0 && ($end <= 0 || $size === $end)) {
            return $file;
        }
        if ($end <= $start || $size < $end) {
            return '';
        }
        $dir = Util::abs(self::restoreTmp($job));
        if (!is_dir($dir) && !Util::mkdirs($dir)) {
            return '';
        }
        $dst = $dir . preg_replace('/[^A-Za-z0-9_]/', '_', (string)$table) . '.sql' . (substr($file, -3) === '.gz' ? '.gz' : '');
        clearstatcache(true, $dst);
        if (is_file($dst) && (int)@filesize($dst) === $end - $start) {
            return $dst;
        }
        $in = @fopen($file, 'rb');
        if (!$in || fseek($in, $start) !== 0) {
            if ($in) {
                fclose($in);
            }
            return '';
        }
        $tmp = $dst . '.' . Util::randomId(4) . '.part';
        $out = @fopen($tmp, 'xb');
        if (!$out) {
            fclose($in);
            return '';
        }
        $copied = stream_copy_to_stream($in, $out, $end - $start);
        fclose($in);
        $closed = fclose($out);
        if ($copied !== $end - $start || !$closed || !Util::renameRetry($tmp, $dst, 2.0)['ok']) {
            @unlink($tmp);
            return '';
        }
        return $dst;
    }

    /** Loads the part of $table into $shadow (or, for a table with foreign keys, in place). */
    private static function loadTable(\PDO $pdo, array &$job, $table, $shadow, $deadline)
    {
        $file = self::tableFile($job, $table);
        if ($file === '') {
            $r = self::err('the database backup of ' . $table . ' is missing', 'e_restore_missing_part', array('table' => $table));
            $r['fatal'] = true;
            return $r;
        }
        if ((int)$job['r_off'] === 0) {
            $pdo->exec('DROP TABLE IF EXISTS ' . Db::quoteName($shadow));
            $job['r_mode'] = '';
        }
        $gz = substr($file, -3) === '.gz';
        $fh = $gz ? @gzopen($file, 'rb') : @fopen($file, 'rb');
        if (!$fh) {
            $r = self::err('cannot read the database backup of ' . $table, 'e_restore_read', array('table' => $table));
            $r['fatal'] = true;
            return $r;
        }
        $n = 0;
        $sql = '';
        try {
            while (true) {
                $line = $gz ? gzgets($fh) : fgets($fh);
                if ($line === false) {
                    break;
                }
                if ($sql === '' && (strncmp($line, '--', 2) === 0 || trim($line) === '')) {
                    continue;
                }
                $sql .= $line;
                if (substr(rtrim($sql), -1) !== ';') {
                    continue;
                }
                $n++;
                if ($n <= (int)$job['r_off']) {
                    $sql = '';
                    continue;
                }
                $stmts = self::restoreStatement($sql, $table, $shadow, $job);
                if ($stmts === false) {
                    $gz ? gzclose($fh) : fclose($fh);
                    $r = self::err('the database backup of ' . $table . ' holds a statement the update did not write', 'e_restore_foreign', array('table' => $table));
                    $r['fatal'] = true;
                    return $r;
                }
                foreach ($stmts as $stmt) {
                    try {
                        $pdo->exec($stmt);
                    } catch (\PDOException $e) {
                        $dup = (string)$e->getCode() === '23000' && stripos($e->getMessage(), 'Duplicate') !== false;
                        $done = (int)($job['r_restarts'][$table] ?? 0);
                        if ($dup && $done < self::RESTORE_RESTARTS) {
                            // rows of this statement are there already: the saved progress was older than the work done
                            // (a state save that failed). The table starts again from its first statement.
                            $job['r_restarts'][$table] = $done + 1;
                            $job['r_off'] = 0;
                            $gz ? gzclose($fh) : fclose($fh);
                            return array('state' => 'more', 'error' => '', 'restarted' => true);
                        }
                        throw $e;
                    }
                }
                $job['r_off'] = $n;
                $sql = '';
                if (microtime(true) >= $deadline) {
                    $gz ? gzclose($fh) : fclose($fh);
                    return array('state' => 'more', 'error' => '');
                }
            }
        } catch (\Throwable $e) {
            $gz ? @gzclose($fh) : @fclose($fh);
            return self::err('restoring ' . $table . ': ' . Util::cut(Util::redact($e->getMessage()), 200), 'e_restore_table',
                array('table' => $table, 'why' => Util::cut(Util::redact($e->getMessage()), 200)));
        }
        $gz ? gzclose($fh) : fclose($fh);
        if (trim($sql) !== '') {
            $r = self::err('the database backup of ' . $table . ' ends in the middle of a statement', 'e_restore_cut', array('table' => $table));
            $r['fatal'] = true;
            return $r;
        }
        if ($job['r_mode'] !== 'inplace' && !self::tableExists($pdo, $shadow)) {
            $r = self::err('the database backup of ' . $table . ' has no table structure', 'e_restore_cut', array('table' => $table));
            $r['fatal'] = true;
            return $r;
        }
        return array('state' => 'done', 'error' => '');
    }

    /**
     * The statements the restore runs for one statement of a backup part: the table name becomes the restore copy's, and
     * the part's own DROP of the live table is skipped (the swap replaces the table). A table with foreign keys (their
     * constraint names are unique per database, so no copy can be built beside it) is restored in place instead: the
     * live table is dropped and created again right there, and a restart begins with that drop too. Returns a list
     * (maybe empty), or false for a statement the update never writes.
     */
    private static function restoreStatement($sql, $table, $shadow, array &$job)
    {
        $s = ltrim((string)$sql);
        $q = Db::quoteName($table);
        $sh = Db::quoteName($shadow);
        if (preg_match('/^SET FOREIGN_KEY_CHECKS\s*=\s*0\s*;\s*$/', $s)) {
            return array($s);
        }
        if (rtrim($s) === 'DROP TABLE IF EXISTS ' . $q . ';') {
            return array();
        }
        foreach (array('CREATE TABLE IF NOT EXISTS ' . $q, 'CREATE TABLE ' . $q) as $p) {
            if (strncmp($s, $p, strlen($p)) === 0) {
                if (stripos($s, 'FOREIGN KEY') !== false || stripos($s, ' CONSTRAINT ') !== false) {
                    $job['r_mode'] = 'inplace';
                    return array('DROP TABLE IF EXISTS ' . $q, 'CREATE TABLE ' . $q . substr($s, strlen($p)));
                }
                // the copy is created without persistent statistics: InnoDB's background statistics work on a table that
                // was just loaded in bulk made a DROP of it wait about 50 s on MariaDB (statsDefault() puts the table's own
                // setting back once it is swapped in)
                $create = 'CREATE TABLE ' . $sh . substr($s, strlen($p));
                if (preg_match('/\bENGINE\s*=\s*InnoDB\b/i', $create) && stripos($create, 'STATS_PERSISTENT') === false) {
                    $create = rtrim(rtrim($create), ';') . ' STATS_PERSISTENT=0;';
                    $job['stats0'][$table] = 1;
                }
                return array($create);
            }
        }
        $p = 'INSERT INTO ' . $q . ' ';
        if (strncmp($s, $p, strlen($p)) === 0) {
            return array($job['r_mode'] === 'inplace' ? $s : 'INSERT INTO ' . $sh . ' ' . substr($s, strlen($p)));
        }
        return false;
    }

    private static function tableExists(\PDO $pdo, $name)
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute(array((string)$name));
        return (int)$st->fetchColumn() > 0;
    }

    // ------------------------------------------------------------------ housekeeping

    /**
     * The update's own database backups that no run refers to any more ($known: names of the jobs of every run on
     * record), older than an hour: sets in the backup folder whose first part carries MARK (an earlier attempt that died
     * before it saved its job, see Engine::backupDb) and folders in the update's own folder (sets that were being written,
     * partial backups). Returns the number of sets removed.
     */
    public static function removeOrphans(array $known, $deadline = null)
    {
        $folder = Util::abs(self::backupFolder());
        $removed = 0;
        foreach ((array)glob($folder . '*-1.sql*') as $f) {
            $base = basename((string)$f);
            if (!preg_match('/^(\d{8}-\d{6})-1\.sql(\.gz)?$/', $base, $m) || isset($known[$m[1]]) || time() - (int)@filemtime((string)$f) < 3600) {
                continue;
            }
            if (!self::isOwnPart((string)$f)) {
                continue; // a backup the owner made: never touched
            }
            foreach ((array)glob($folder . $m[1] . '-*.sql*') as $p) {
                if (preg_match('/^\d{8}-\d{6}-\d+\.sql(\.gz)?$/', basename((string)$p))) {
                    @unlink((string)$p);
                }
            }
            $removed++;
        }
        foreach ((array)glob($folder . self::PARTIAL_DIR . '/*', GLOB_ONLYDIR) as $d) {
            $name = basename((string)$d);
            if (!preg_match('/^\d{8}-\d{6}$/', $name) || isset($known[$name]) || time() - (int)@filemtime((string)$d) < 3600) {
                continue;
            }
            if ($deadline !== null ? Util::removeTreeTimed((string)$d, $deadline) : Util::removeTree((string)$d)) {
                $removed++;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                break;
            }
        }
        return $removed;
    }

    /** True when the part's second line carries MARK (written by this class). */
    public static function isOwnPart($file)
    {
        $gz = substr((string)$file, -3) === '.gz';
        $fh = $gz ? @gzopen((string)$file, 'rb') : @fopen((string)$file, 'rb');
        if (!$fh) {
            return false;
        }
        $head = $gz ? (string)@gzread($fh, 400) : (string)@fread($fh, 400);
        $gz ? @gzclose($fh) : @fclose($fh);
        return strpos($head, "\n" . self::MARK . ':') !== false;
    }

    /**
     * Removes a job's set: the update's own folder of it (a set being written, a partial backup) and the parts it moved
     * into the backup folder of Backup & restore (only parts this class wrote). $deadline: stop when it is reached (a big
     * partial folder on a slow host; housekeeping goes on later). True when nothing of the set is left.
     */
    public static function removeJob(array $job, $deadline = null)
    {
        $gone = true;
        $stages = array();
        foreach (array((string)($job['stage'] ?? ''), empty($job['full']) ? (string)($job['folder'] ?? '') : '') as $st) {
            if ($st !== '' && preg_match('#(^|/)' . preg_quote(self::PARTIAL_DIR, '#') . '/\d{8}-\d{6}/$#', $st)) {
                $stages[$st] = true;
            }
        }
        foreach (array_keys($stages) as $st) {
            $abs = Util::abs($st);
            if (is_dir($abs) && !($deadline !== null ? Util::removeTreeTimed($abs, $deadline) : Util::removeTree($abs))) {
                $gone = false;
            }
        }
        $home = Util::abs((string)($job['home'] ?? ''));
        if (!empty($job['pub_name']) && preg_match('/^\d{8}-\d{6}$/', (string)$job['pub_name']) && !empty($job['home'])) {
            foreach ((array)glob($home . $job['pub_name'] . '-*.sql*') as $p) {
                if (preg_match('/^\d{8}-\d{6}-\d+\.sql(\.gz)?$/', basename((string)$p)) && self::isOwnPart((string)$p) && !@unlink((string)$p)) {
                    $gone = false;
                }
            }
        }
        // a job of an older engine: its parts lie in the backup folder, listed one by one
        foreach ((array)($job['parts'] ?? array()) as $rel) {
            if (is_string($rel) && preg_match('#^[A-Za-z0-9_./-]+\d{8}-\d{6}-\d+\.sql(\.gz)?$#', $rel) && is_file(Util::abs($rel))) {
                if (!@unlink(Util::abs($rel))) {
                    $gone = false;
                }
            }
        }
        return $gone;
    }

    private static function err($text, $k = '', array $v = array())
    {
        return array('state' => 'error', 'error' => (string)$text, 'k' => (string)$k, 'v' => $v);
    }
}
