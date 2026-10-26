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
 * Replaces, deletes and restores single site files safely (UPDATER-SCOUT-HOST rules 3, 11, 13, 14).
 *
 * put(): the new bytes go to ".<target>.<tag>.up-tmp" IN THE TARGET'S OWN FOLDER (a rename from runtime/ could cross
 * volumes and become a non-atomic copy), are synced and checked, and the temp file is renamed over the target. The tag
 * comes from the run (Util::tmpTag()): a step that died between the write and the rename is repeated and writes the same
 * temp file again, so no copy is left behind (verify_files and the rollback also sweep older leftovers). Windows
 * refuses that rename for a moment while another request has the file open: retried 20 x 50 ms (3 s for .htaccess /
 * web.config, which Apache opens on every request). Only when maintenance is on and the rename still fails, the old file
 * is moved aside and the new one renamed in (the name is missing for an instant: a visitor could see a 500, so never
 * while visitors are served); if the second rename fails the old file is put back. Never unlink-then-rename, never a
 * folder rename. A read-only flag (Windows) is cleared first. Every written file gets the current time as mtime, which
 * OPcache (validate_timestamps) and ThinkPHP's compiled templates need to notice the change; on POSIX the old file's
 * permissions are kept (a temp file starts as 0600, which a web server running as another user could not read).
 */
final class Swapper
{
    const MARK_START = '// Caching starts';
    const MARK_END = '// End of cache';

    /** Seconds a rename is retried for $rel. */
    public static function retrySeconds($rel)
    {
        $k = Paths::key(basename((string)$rel));
        return ($k === '.htaccess' || $k === 'web.config') ? 3.0 : 1.0;
    }

    /**
     * Puts the bytes of $src (a staged or backed-up file) at site path $target. $want: expected sha256 of the result or
     * null. $aside: move-aside allowed (maintenance on). $content: use this text instead of $src (merged playerconfig).
     * Returns ['ok', 'error', 'ms', 'method', 'dirs' => created folders, 'sha'].
     */
    public static function put($src, $target, $want, $aside, $content = null)
    {
        $abs = Util::abs($target);
        $dir = dirname($abs);
        $created = array();
        if (!is_dir($dir)) {
            $missing = array();
            $d = $dir;
            while (!is_dir($d) && strlen($d) > strlen(rtrim(Util::root(), '/'))) {
                $missing[] = $d;
                $d = dirname($d);
            }
            if (!Util::mkdirs($dir)) {
                return self::res(false, 'cannot create the folder of ' . $target, 0, '', array(), null, 'e_folder', array('file' => $target));
            }
            foreach (array_reverse($missing) as $m) {
                $created[] = substr(str_replace('\\', '/', $m), strlen(Util::root()));
            }
        }
        $mode = 0644;
        if (is_file($abs)) {
            if (!Util::isWindows()) {
                $perm = @fileperms($abs);
                if ($perm !== false) {
                    $mode = $perm & 0777;
                }
            }
            if (!is_writable($abs)) {
                @chmod($abs, Util::isWindows() ? 0644 : ($mode | 0200));
                clearstatcache(true, $abs);
            }
        }
        $tmp = self::tempName($abs, 'tmp');
        if ($content !== null) {
            $ok = self::writeNew($tmp, (string)$content);
        } else {
            $ok = self::copyNew($src, $tmp);
        }
        if (!$ok) {
            @unlink($tmp);
            return self::res(false, 'cannot write ' . $target . ' (disk full or no permission)', 0, '', $created, null, 'e_write', array('file' => $target));
        }
        @chmod($tmp, $mode);
        $sha = Util::sha256($tmp);
        if ($want !== null && $sha !== $want) {
            @unlink($tmp);
            return self::res(false, 'written copy of ' . $target . ' does not match', 0, '', $created, null, 'e_written_differs', array('file' => $target));
        }
        $r = Util::renameRetry($tmp, $abs, self::retrySeconds($target));
        if ($r['ok']) {
            return self::res(true, '', $r['ms'], 'rename', $created, $sha);
        }
        if ($aside && is_file($abs)) {
            $old = self::tempName($abs, 'old');
            if (@rename($abs, $old)) {
                if (@rename($tmp, $abs)) {
                    if (!@unlink($old)) {
                        // still open somewhere: the sweep of verify_files / the rollback removes it later
                        @touch($old);
                    }
                    return self::res(true, '', $r['ms'], 'aside', $created, $sha);
                }
                @rename($old, $abs);
            }
        }
        @unlink($tmp);
        return self::res(false, 'cannot replace ' . $target . ': ' . $r['error'], $r['ms'], '', $created, null, 'e_replace', array('file' => $target, 'why' => $r['error']));
    }

    /**
     * Moves the backed-up copy $copy back to site path $target (rollback): one rename in the same volume needs no free
     * space, so a rollback works on a full disk. The copy gets the target's permissions and the current time first (OPcache
     * and compiled templates notice the change). False when the rename is not possible (another volume, a locked file):
     * the caller copies instead.
     */
    public static function moveBack($copy, $target)
    {
        $abs = Util::abs($target);
        if (!is_dir(dirname($abs))) {
            return false;
        }
        if (is_file($abs)) {
            if (!Util::isWindows()) {
                $perm = @fileperms($abs);
                if ($perm !== false) {
                    @chmod($copy, $perm & 0777);
                }
            } elseif (!is_writable($abs)) {
                @chmod($abs, 0644);
                clearstatcache(true, $abs);
            }
        }
        @touch($copy);
        $r = Util::renameRetry($copy, $abs, self::retrySeconds($target));
        return $r['ok'];
    }

    /** Deletes site file $target (read-only flag cleared, retried; moved aside under maintenance when locked). */
    public static function remove($target, $aside)
    {
        $abs = Util::abs($target);
        if (!is_file($abs)) {
            return self::res(true, '', 0, 'gone');
        }
        if (!is_writable($abs)) {
            @chmod($abs, 0644);
        }
        $t0 = microtime(true);
        do {
            if (@unlink($abs)) {
                return self::res(true, '', (int)round((microtime(true) - $t0) * 1000), 'unlink');
            }
            usleep(50000);
        } while (microtime(true) - $t0 < self::retrySeconds($target));
        if ($aside) {
            $old = self::tempName($abs, 'old');
            if (@rename($abs, $old)) {
                @unlink($old);
                return self::res(true, '', (int)round((microtime(true) - $t0) * 1000), 'aside');
            }
        }
        return self::res(false, 'cannot delete ' . $target, (int)round((microtime(true) - $t0) * 1000), '', array(), null, 'e_delete', array('file' => $target));
    }

    /**
     * Name of the temp ($kind 'tmp') or moved-aside ('old') file of $abs: ".<name>.<tag>.up-<kind>" in its own folder. With
     * the run's tag the name is the same in every attempt, so a leftover of an attempt that died is removed first (only
     * the holder of the step lock writes site files); without one (no run) a random tag.
     */
    public static function tempName($abs, $kind = 'tmp')
    {
        $tag = Util::tmpTag();
        $name = dirname($abs) . '/.' . substr(basename($abs), 0, 40) . '.' . ($tag !== '' ? $tag : Util::randomId(5)) . '.up-' . ($kind === 'old' ? 'old' : 'tmp');
        if ($tag !== '' && is_file($name)) {
            if (!@unlink($name) && !(@chmod($name, 0644) && @unlink($name))) {
                // held by another program: this attempt takes a name of its own (the sweep removes the old one later)
                $name = dirname($abs) . '/.' . substr(basename($abs), 0, 40) . '.' . Util::randomId(5) . '.up-' . ($kind === 'old' ? 'old' : 'tmp');
            }
        }
        return $name;
    }

    private static function res($ok, $error, $ms = 0, $method = '', array $dirs = array(), $sha = null, $k = '', array $v = array())
    {
        return array('ok' => (bool)$ok, 'error' => (string)$error, 'ms' => (int)$ms, 'method' => (string)$method, 'dirs' => $dirs, 'sha' => $sha,
            'k' => (string)$k, 'v' => $v);
    }

    private static function writeNew($tmp, $data)
    {
        $fh = @fopen($tmp, 'xb');
        if (!$fh) {
            return false;
        }
        $ok = Util::writeAllowed() && @fwrite($fh, $data) === strlen($data);
        @fflush($fh);
        Util::fsync($fh);
        return @fclose($fh) && $ok;
    }

    private static function copyNew($src, $tmp)
    {
        $in = @fopen($src, 'rb');
        if (!$in) {
            return false;
        }
        $out = @fopen($tmp, 'xb');
        if (!$out) {
            fclose($in);
            return false;
        }
        $ok = true;
        while (!feof($in)) {
            $buf = fread($in, 65536);
            if ($buf === false) {
                $ok = false;
                break;
            }
            if ($buf === '') {
                continue;
            }
            if (!Util::writeAllowed() || @fwrite($out, $buf) !== strlen($buf)) {
                $ok = false;
                break;
            }
        }
        fclose($in);
        @fflush($out);
        Util::fsync($out);
        return @fclose($out) && $ok;
    }


    /**
     * The release's static/js/playerconfig.js with the site's own player list kept: the admin's "Clear cache" writes that
     * list between "// Caching starts" and "// End of cache"; the new file's block is replaced by the site's block.
     * Returns the merged text, or null when either file lacks the markers (then the release file is used as it is).
     */
    public static function mergePlayerConfig($siteText, $newText)
    {
        $s1 = strpos((string)$siteText, self::MARK_START);
        $s2 = strpos((string)$siteText, self::MARK_END);
        $n1 = strpos((string)$newText, self::MARK_START);
        $n2 = strpos((string)$newText, self::MARK_END);
        if ($s1 === false || $s2 === false || $n1 === false || $n2 === false || $s2 < $s1 || $n2 < $n1) {
            return null;
        }
        $block = substr($siteText, $s1, $s2 - $s1);
        return substr($newText, 0, $n1) . $block . substr($newText, $n2);
    }
}
