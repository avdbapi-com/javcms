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
 * Works out what an update does to this site, file by file (resumable: the installed files are probed in slices).
 *
 * Inputs: the new release's manifest, the manifest of the installed version (null when it cannot be had: then local
 * changes cannot be told apart from the release and every differing file counts as "replace") and the owner's choice
 * for locally modified files ("replace": the backup keeps the site's copy; "keep": those files are left as they are).
 *
 * Plan fields: add, replace, delete, modified (changed by the site), keep (modified + "keep"), needed (modified files the
 * updater itself needs, Paths::updaterOwn: replaced even with "keep"), merge (merge-required
 * files the site changed: kept; the release's copy goes to the merge folder), owned (listed but site-owned: untouched),
 * left (removed by the release but changed by the site, or theme files: left in place), unreadable (files the update
 * would have to read but PHP may not: they block the update), seeds_kept, same, targets (path in the manifest => path
 * on this site: a renamed admin entry), bytes_new, bytes_backup, unknown_base.
 * Theme files are never deleted (a theme the release dropped may still be the site's active theme).
 *
 * A scan records size and modification time of every path. A later scan of the same two releases (the run after the
 * owner's "Review", or a new "Review") takes the earlier one over and probes again only the paths whose size or time
 * changed: the whole site is hashed once per update, and the review never shows files as they were an hour ago.
 *
 * The plan is built once per finished scan, in a step of its own, from the sizes the scan recorded (no second stat per
 * file), and kept next to the scan (plan.php): the later slices of a Review or of the run's write check load it instead
 * of building it again (on Windows with open_basedir a build costs seconds for a big release).
 */
final class Planner
{
    /** A taken-over scan is at most this old (seconds). */
    const SCAN_FRESH = 1800;

    /**
     * One slice of the scan. $prepKey names the scan folder (runtime/update/prepare/<key>/); $seedKey names a scan that
     * may be taken over when this one does not exist yet; $recheck: probe again what changed since a finished scan.
     * Returns ['state' => 'more'|'done', 'done' => n, 'total' => n, 'plan' => array|null].
     */
    public static function step($prepKey, Manifest $to, $from, $choice, $deadline, $seedKey = '', $recheck = false)
    {
        $dir = Util::dir('prepare/' . self::safeKey($prepKey));
        if ($dir === '') {
            throw new \RuntimeException('runtime/update is not writable');
        }
        $scan = Util::loadData($dir . 'scan.php');
        $sig = $to->sha . '|' . ($from instanceof Manifest ? $from->sha : '-');
        if (!self::validScan($scan, $sig)) {
            $seed = $seedKey !== '' ? Util::loadData(Util::root() . 'runtime/update/prepare/' . self::safeKey($seedKey) . '/scan.php') : null;
            if (self::validScan($seed, $sig) && (int)$seed['i'] >= count($seed['paths']) && !isset($seed['re_i'])
                && time() - (int)($seed['at'] ?? 0) < self::SCAN_FRESH) {
                $scan = $seed;
                $scan['re_i'] = 0;
            } else {
                $scan = array('sig' => $sig, 'paths' => self::scanList($to, $from), 'i' => 0, 'local' => array(), 'meta' => array());
            }
        } elseif ($recheck && (int)$scan['i'] >= count($scan['paths']) && !isset($scan['re_i'])) {
            $scan['re_i'] = 0;
        }
        $newFiles = $to->files();
        $oldFiles = $from instanceof Manifest ? $from->files() : array();
        $removed = $to->removed();
        $n = count($scan['paths']);
        $stop = false;
        $completed = false;
        // nothing probed in this call: the scan was finished before (a later slice that only needs the plan)
        $worked = (int)$scan['i'] < $n || isset($scan['re_i']);
        if ($scan['i'] < $n) {
            while ($scan['i'] < $n) {
                $p = $scan['paths'][$scan['i']];
                list($scan['local'][$p], $scan['meta'][$p]) = self::probe($p, $newFiles[$p] ?? null, $oldFiles[$p] ?? ($removed[$p] ?? null));
                $scan['i']++;
                if (($scan['i'] % 50) === 0 && microtime(true) >= $deadline) {
                    $stop = true;
                    break;
                }
            }
            $completed = !$stop;
        }
        if (!$stop && isset($scan['re_i'])) {
            // a taken-over or earlier scan: one stat per path, a probe only where size or time differ
            while ($scan['re_i'] < $n) {
                $p = $scan['paths'][$scan['re_i']];
                if (self::changed($p, $scan['meta'][$p] ?? null)) {
                    list($scan['local'][$p], $scan['meta'][$p]) = self::probe($p, $newFiles[$p] ?? null, $oldFiles[$p] ?? ($removed[$p] ?? null));
                }
                $scan['re_i']++;
                if (($scan['re_i'] % 100) === 0 && microtime(true) >= $deadline) {
                    $stop = true;
                    break;
                }
            }
            if (!$stop) {
                unset($scan['re_i']);
                $completed = true;
            }
        }
        if ($completed) {
            // the time the site's files were last seen as recorded (a later scan may take this one over until it is old)
            $scan['at'] = time();
        }
        if ($worked || !is_file($dir . 'scan.php')) {
            Util::saveData($dir . 'scan.php', $scan);
        }
        $done = isset($scan['re_i']) ? (int)$scan['re_i'] : (int)$scan['i'];
        if ($stop) {
            return array('state' => 'more', 'done' => $done, 'total' => $n, 'plan' => null);
        }
        if ($worked) {
            // the files are done; the plan always gets a step of its own (a short PHP limit never meets scan + build)
            return array('state' => 'more', 'done' => $n, 'total' => $n, 'plan' => null);
        }
        // the plan of this scan: built once (the sizes the scan recorded, no second stat per file), then loaded
        $stamp = sha1($sig . '|' . (int)($scan['at'] ?? 0) . '|' . $n . '|' . $choice);
        $cached = Util::loadData($dir . 'plan.php');
        if (is_array($cached) && ($cached['stamp'] ?? '') === $stamp && is_array($cached['plan'] ?? null)) {
            return array('state' => 'done', 'done' => $n, 'total' => $n, 'plan' => $cached['plan']);
        }
        $plan = self::build($to, $from, $scan['local'], $choice, (array)$scan['meta']);
        Util::saveData($dir . 'plan.php', array('stamp' => $stamp, 'plan' => $plan));
        return array('state' => 'done', 'done' => $n, 'total' => $n, 'plan' => $plan);
    }

    private static function safeKey($key)
    {
        return (string)preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$key);
    }

    private static function validScan($scan, $sig)
    {
        return is_array($scan) && ($scan['sig'] ?? '') === $sig && is_array($scan['paths'] ?? null) && is_array($scan['local'] ?? null)
            && is_array($scan['meta'] ?? null) && isset($scan['i']);
    }

    /** Every path whose local state matters: new + old + removed lists, plus root PHP files (renamed admin entry). */
    private static function scanList(Manifest $to, $from)
    {
        $paths = array_keys($to->files());
        if ($from instanceof Manifest) {
            $paths = array_merge($paths, array_keys($from->files()));
        }
        $paths = array_merge($paths, array_keys($to->removed()));
        foreach ((array)glob(Util::root() . '*.php') as $f) {
            $paths[] = basename((string)$f);
        }
        $paths = array_values(array_unique(array_map('strval', $paths)));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** True when $rel no longer has the size and modification time of $meta ([size, mtime] or null = missing). */
    private static function changed($rel, $meta)
    {
        if (Paths::invalid($rel) !== '' || Paths::siteOwned($rel)) {
            return false;
        }
        $st = @stat(Util::abs($rel));
        if ($st === false || !is_file(Util::abs($rel))) {
            return $meta !== null;
        }
        return !is_array($meta) || (int)$meta[0] !== (int)$st['size'] || (int)$meta[1] !== (int)$st['mtime'];
    }

    /**
     * Local state of one path: [state, meta]. state: null (missing), a sha256 (hashed because its size matches the new
     * or the old copy), "size:<n>" (its size matches neither, so it differs from both without reading it) or
     * "unreadable" (it exists, but PHP may not read it). meta: [size, mtime] or null.
     */
    private static function probe($rel, $new, $old)
    {
        if (Paths::invalid($rel) !== '' || Paths::siteOwned($rel)) {
            return array(null, null);
        }
        $abs = Util::abs($rel);
        $st = @stat($abs);
        if ($st === false || !is_file($abs)) {
            return array(null, null);
        }
        $size = (int)$st['size'];
        $meta = array($size, (int)$st['mtime']);
        $want = array();
        if (is_array($new)) {
            $want[] = (int)$new[1];
        }
        if (is_array($old) && isset($old[1]) && is_int($old[1])) {
            $want[] = (int)$old[1];
        }
        if (in_array($size, $want, true) || !$want || Paths::isEntry($rel)) {
            $h = Util::sha256($abs);
            return array($h === null ? 'unreadable' : $h, $meta);
        }
        return array(is_readable($abs) ? 'size:' . $size : 'unreadable', $meta);
    }

    /**
     * The plan from the scan results. $meta: [size, mtime] per path as the scan recorded them (sizes are taken from there;
     * a path the scan does not know is asked for its size).
     */
    public static function build(Manifest $to, $from, array $local, $choice, array $meta = array())
    {
        $sizeOf = function ($rel) use ($meta) {
            return isset($meta[$rel]) && is_array($meta[$rel]) ? (int)$meta[$rel][0] : self::localSize($rel);
        };
        $new = $to->files();
        $old = $from instanceof Manifest ? $from->files() : null;
        $plan = array(
            'to' => $to->version(), 'from' => $from instanceof Manifest ? $from->version() : '',
            'add' => array(), 'replace' => array(), 'delete' => array(), 'modified' => array(), 'keep' => array(), 'needed' => array(),
            'merge' => array(), 'owned' => array(), 'left' => array(), 'unreadable' => array(), 'seeds_kept' => 0, 'same' => 0, 'targets' => array(),
            'bytes_new' => 0, 'bytes_backup' => 0, 'unknown_base' => $old === null, 'admin_entry' => 'javcms.php', 'choice' => $choice,
        );
        $newKeys = array();
        foreach ($new as $p => $row) {
            $newKeys[Paths::key($p)] = $p;
        }
        // a renamed admin entry (javcms.php -> another name): that file gets the new javcms.php content
        $entryTarget = 'javcms.php';
        if (isset($new['javcms.php']) && ($local['javcms.php'] ?? null) === null) {
            $found = self::renamedAdminEntry();
            if ($found !== '') {
                $entryTarget = $found;
                $plan['admin_entry'] = $found;
            }
        }
        foreach ($new as $p => $row) {
            $p = (string)$p;
            if (Paths::siteOwned($p)) {
                $plan['owned'][] = $p;
                continue;
            }
            $target = ($p === 'javcms.php') ? $entryTarget : $p;
            $l = $local[$target] ?? null;
            if ($target !== $p && !array_key_exists($target, $local)) {
                $l = Util::sha256(Util::abs($target));
                if ($l === null && is_file(Util::abs($target))) {
                    $l = 'unreadable';
                }
            }
            if (Paths::seed($p)) {
                if ($l === null) {
                    $plan['add'][] = $p;
                    $plan['bytes_new'] += (int)$row[1];
                } else {
                    $plan['seeds_kept']++;
                }
                continue;
            }
            if ($l === $row[0]) {
                $plan['same']++;
                continue;
            }
            if ($target !== $p) {
                $plan['targets'][$p] = $target;
            }
            if ($l === null) {
                $plan['add'][] = $p;
                $plan['bytes_new'] += (int)$row[1];
                continue;
            }
            if ($l === 'unreadable') {
                // it cannot be backed up or compared: never counted as "changed by the site"; the update is blocked
                $plan['unreadable'][] = $p;
                continue;
            }
            $cleanOld = $old !== null && isset($old[$p]) && $l === $old[$p][0];
            $modified = $old !== null && !$cleanOld;
            $size = $sizeOf($target);
            if ($modified) {
                $plan['modified'][] = $p;
                if (Paths::mergeRequired($p)) {
                    $plan['merge'][] = $p;
                    continue;
                }
                // a changed file the updater itself needs is replaced whatever the choice (the backup keeps the site's copy)
                if (Paths::updaterOwn($p)) {
                    $plan['needed'][] = $p;
                } elseif ($choice === 'keep') {
                    $plan['keep'][] = $p;
                    continue;
                }
            } elseif ($old === null && Paths::mergeRequired($p)) {
                // without the installed manifest a differing .htaccess / web.config is the site's own: kept
                $plan['merge'][] = $p;
                continue;
            }
            $plan['replace'][] = $p;
            $plan['bytes_new'] += (int)$row[1];
            $plan['bytes_backup'] += $size;
        }
        // deletions: files the installed release (or the new manifest's removed list) had and the new one has not
        $candidates = array();
        if ($old !== null) {
            foreach ($old as $p => $row) {
                $candidates[(string)$p] = $row[0];
            }
        }
        foreach ($to->removed() as $p => $row) {
            if (!isset($candidates[(string)$p])) {
                $candidates[(string)$p] = $row[0];
            }
        }
        foreach ($candidates as $p => $sha) {
            if (isset($new[$p]) || isset($newKeys[Paths::key($p)])) {
                continue; // still shipped (maybe with other case: deleting it would delete the new file on Windows)
            }
            if (Paths::siteOwned($p) || Paths::seed($p) || Paths::invalid($p) !== '') {
                continue;
            }
            $l = $local[$p] ?? null;
            if ($l === null) {
                continue;
            }
            if (Paths::theme($p) !== '' || Paths::isEntry($p)) {
                $plan['left'][] = $p;
                continue;
            }
            if ($l === 'unreadable') {
                $plan['unreadable'][] = $p;
                continue;
            }
            if ($l === $sha) {
                $plan['delete'][] = $p;
                $plan['bytes_backup'] += $sizeOf($p);
            } else {
                $plan['left'][] = $p;
            }
        }
        foreach (array('add', 'replace', 'delete', 'modified', 'keep', 'needed', 'merge', 'owned', 'left', 'unreadable') as $k) {
            sort($plan[$k], SORT_STRING);
        }
        return $plan;
    }

    private static function localSize($rel)
    {
        $s = @filesize(Util::abs($rel));
        return $s === false ? 0 : (int)$s;
    }

    /**
     * The renamed admin entry: the one root *.php file other than the shipped entries that defines ENTRANCE 'admin'.
     * '' when there is none or more than one.
     */
    public static function renamedAdminEntry()
    {
        $found = array();
        foreach ((array)glob(Util::root() . '*.php') as $f) {
            $name = basename((string)$f);
            if (in_array(strtolower($name), array('index.php', 'api.php', 'install.php', 'javcms.php'), true)) {
                continue;
            }
            $head = (string)@file_get_contents((string)$f, false, null, 0, 8192);
            if (preg_match("/define\(\s*'ENTRANCE'\s*,\s*'admin'\s*\)/", $head)) {
                $found[] = $name;
            }
        }
        return count($found) === 1 ? $found[0] : '';
    }

    // ------------------------------------------------------------------ write permission

    /** State of a write check (writeCheckStep() fills it in slices). */
    public static function writeCheckStart()
    {
        return array('i' => 0, 'total' => 0, 'blocked' => array(), 'readonly' => array(), 'unreadable' => array(), 'count' => 0, 'dirs' => array());
    }

    /**
     * One slice of the write check of every file the plan touches. $w (writeCheckStart()) collects:
     * blocked = cannot be written (its folder is not writable, or - Windows - its permissions deny writing), readonly =
     * the Windows read-only attribute (the swap clears it), unreadable = a file to back up that PHP may not read. On
     * POSIX a file's own write bit does not matter: the swap writes a new file in the folder and renames it over the old
     * one (only the folder must be writable), keeping the old file's permissions. True when the check is complete.
     */
    public static function writeCheckStep(array $plan, array &$w, $deadline)
    {
        $w += self::writeCheckStart();
        $targets = $plan['targets'] ?? array();
        $list = array_merge($plan['add'], $plan['replace'], $plan['delete']);
        $added = array_flip($plan['add']);
        $w['total'] = count($list);
        $rootLen = strlen(rtrim(Util::root(), '/'));
        while ($w['i'] < $w['total']) {
            $p = $list[$w['i']];
            $w['i']++;
            $w['count']++;
            $abs = Util::abs($targets[$p] ?? $p);
            $dir = dirname($abs);
            while (strlen($dir) > $rootLen && !isset($w['dirs'][$dir]) && !is_dir($dir)) {
                $dir = dirname($dir);
            }
            if (!isset($w['dirs'][$dir])) {
                $w['dirs'][$dir] = is_writable($dir) ? 1 : 0;
            }
            if (!$w['dirs'][$dir]) {
                $w['blocked'][] = $p;
            } elseif (is_file($abs)) {
                if (!isset($added[$p]) && !is_readable($abs)) {
                    $w['unreadable'][] = $p;
                } elseif (Util::isWindows() && !is_writable($abs)) {
                    $perm = @fileperms($abs);
                    if ($perm !== false && ($perm & 0222) === 0) {
                        $w['readonly'][] = $p;
                    } else {
                        $w['blocked'][] = $p;
                    }
                }
            }
            if (($w['i'] % 100) === 0 && microtime(true) >= $deadline) {
                return false;
            }
        }
        return true;
    }

    /** The whole write check at once (small plans, tests). */
    public static function writeCheck(array $plan)
    {
        $w = self::writeCheckStart();
        self::writeCheckStep($plan, $w, INF);
        return $w;
    }
}
