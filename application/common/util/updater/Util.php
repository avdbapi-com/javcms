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
 * Helpers of the one-click updater (JavCMS 1.2.1).
 *
 * Plain PHP 7.4 - 8.5, no framework: this folder also runs as a frozen copy (runtime/update/engine-<run>/, used while
 * the release files are being replaced) and inside the single-file bridge for 1.2.0 sites. Classes of this folder refer
 * to each other only by their short names, so the frozen copy and the bridge can live in another namespace.
 *
 * Rules taken from the host scout (UPDATER-SCOUT-HOST.md section 4):
 * - every write is a temp file in the target's own folder + rename (never a half-written file; rename never crosses a
 *   volume); fsync() where PHP has it (8.1+);
 * - a rename that Windows refuses because another request has the file open is retried (20 x 50 ms, longer for
 *   .htaccess / web.config), never unlink-then-rename;
 * - optional functions (set_time_limit, disk_free_space, opcache_*, fsync) are called only when they exist and are not
 *   disabled, inside try/catch: PHP 8 throws on a disabled function, 7.4 returns NULL while is_callable() says true;
 * - data files under runtime/update/ start with "<?php __halt_compiler();": executed by a web server they print nothing.
 */
final class Util
{
    /** First line of every data file the updater writes (the JSON follows on the next line). */
    const GUARD = "<?php __halt_compiler();\n";

    /** @var string site root, '/' separators, trailing slash */
    private static $root = '';
    /** @var string temp-file tag of the run in progress ('' = none): see tmpTag() */
    private static $tmpTag = '';

    // ------------------------------------------------------------------ site paths

    /** Sets the site root (the folder of index.php). */
    public static function setRoot($root)
    {
        $root = str_replace('\\', '/', (string)$root);
        self::$root = rtrim($root, '/') . '/';
    }

    /** The site root with a trailing slash; ROOT_PATH when nothing was set (framework requests). */
    public static function root()
    {
        if (self::$root === '') {
            if (defined('ROOT_PATH')) {
                self::setRoot(ROOT_PATH);
            } else {
                self::setRoot(dirname(__DIR__, 4));
            }
        }
        return self::$root;
    }

    /** Absolute path of a site-relative path ('/' separators). */
    public static function abs($rel)
    {
        return self::root() . ltrim(str_replace('\\', '/', (string)$rel), '/');
    }

    /** runtime/update/<sub>/ (created when missing, never through a link); '' when it cannot be made. */
    public static function dir($sub = '')
    {
        $base = self::root() . 'runtime/update/';
        if (!self::mkdirs($base)) {
            return '';
        }
        self::protectFolder($base);
        if ($sub === '') {
            return $base;
        }
        $path = $base . trim(str_replace('\\', '/', $sub), '/') . '/';
        return self::mkdirs($path) ? $path : '';
    }

    /**
     * runtime/update/ is already private (every shipped server rule denies runtime/); a deny file and an empty index are
     * added for servers configured by hand. The deny rules sit in IfModule blocks: "Deny from all" is an unknown command on
     * Apache 2.4 without mod_access_compat (HTTP 500 for the folder) and "Require" on 2.2.
     */
    private static function protectFolder($base)
    {
        if (!is_file($base . '.htaccess')) {
            self::writeAtomic($base . '.htaccess', self::DENY_HTACCESS);
        }
        if (!is_file($base . 'index.html')) {
            self::writeAtomic($base . 'index.html', '');
        }
    }

    /** Deny-all rules for Apache 2.2 and 2.4 (with or without mod_access_compat). */
    const DENY_HTACCESS = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";

    /**
     * Why runtime/update/ cannot be created, for the requirement row: '' (it can), 'link' (runtime/ or runtime/update/ is a
     * symbolic link or a junction: the updater never creates folders through a link) or 'write' (no permission).
     */
    public static function dirProblem()
    {
        $runtime = self::root() . 'runtime';
        foreach (array($runtime, $runtime . '/update') as $d) {
            if (is_dir($d) && !self::realDir($d)) {
                return 'link';
            }
        }
        return self::dir() === '' ? 'write' : '';
    }

    // ------------------------------------------------------------------ platform

    public static function isWindows()
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    /** Windows and macOS compare file names without case: deletions and protected-path checks must too. */
    public static function caseInsensitiveFs()
    {
        return self::isWindows() || PHP_OS_FAMILY === 'Darwin';
    }

    /** A function that exists and is not listed in disable_functions. */
    public static function fnAvailable($fn)
    {
        if (!function_exists($fn)) {
            return false;
        }
        $off = (string)@ini_get('disable_functions');
        if ($off === '') {
            return true;
        }
        foreach (explode(',', strtolower($off)) as $name) {
            if (trim($name) === strtolower($fn)) {
                return false;
            }
        }
        return true;
    }

    /** Calls an optional function; $default when it is missing, disabled or throws. */
    public static function call($fn, array $args = array(), $default = null)
    {
        if (!self::fnAvailable($fn)) {
            return $default;
        }
        try {
            $r = @call_user_func_array($fn, $args);
            return $r === null ? $default : $r;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Seconds of work one step may use: the wanted budget, capped by PHP's own limit (max_execution_time counts wall
     * time on Windows) minus a margin, never below 2 s.
     */
    public static function stepBudget($want = 10.0)
    {
        $want = (float)$want;
        $limit = (int)@ini_get('max_execution_time');
        if ($limit > 0 && PHP_SAPI !== 'cli') {
            if (self::call('set_time_limit', array(max(30, (int)ceil($want) + 20)), false) === false) {
                $want = min($want, max(2.0, $limit - 5.0));
            }
        }
        return max(2.0, $want);
    }

    /**
     * Seconds this web request may still run: plenty (3600) when PHP's limit is off or could be raised now, else the limit
     * minus the time already used and $margin, at least 1 (a host that disables both set_time_limit and ini_set, with a
     * limit counted in wall time: Windows, FPM's request_terminate_timeout, a gateway). The command line has no limit.
     */
    public static function requestTimeLeft($margin = 2.0)
    {
        if (PHP_SAPI === 'cli') {
            return 3600.0;
        }
        $limit = (int)@ini_get('max_execution_time');
        if ($limit <= 0) {
            return 3600.0;
        }
        if (self::call('set_time_limit', array(max(60, $limit)), false) !== false) {
            // set_time_limit() starts the count again from now
            return (float)max(60, $limit) - (float)$margin;
        }
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float)$_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        return max(1.0, $limit - (microtime(true) - $start) - (float)$margin);
    }

    /**
     * The time zone the site shows times in: app.timezone of the site settings (Site settings > Time zone), else
     * default_timezone of application/config.php, else PHP's own (the same order as the framework: Init behaviour). The
     * engine runs without the site's code, so it reads the two files.
     */
    public static function siteTimezone()
    {
        static $tz = null;
        if ($tz !== null) {
            return $tz;
        }
        $cands = array();
        $raw = @file_get_contents(self::abs('application/extra/maccms.php'));
        if (is_string($raw) && preg_match("/'timezone'\s*=>\s*'([A-Za-z0-9_\/+\-]{1,64})'/", $raw, $m)) {
            $cands[] = $m[1];
        }
        $raw = @file_get_contents(self::abs('application/config.php'));
        if (is_string($raw) && preg_match("/'default_timezone'\s*=>\s*'([A-Za-z0-9_\/+\-]{1,64})'/", $raw, $m)) {
            $cands[] = $m[1];
        }
        foreach ($cands as $c) {
            try {
                new \DateTimeZone($c);
                return $tz = $c;
            } catch (\Exception $e) {
                // not a time zone PHP knows: the next one
            }
        }
        return $tz = (string)date_default_timezone_get();
    }

    /** date($format, $t) in the site's time zone (backup set names and their README match the site's own backups). */
    public static function siteDate($format, $t = null)
    {
        try {
            $d = new \DateTime('@' . (int)($t === null ? time() : $t));
            $d->setTimezone(new \DateTimeZone(self::siteTimezone()));
            return $d->format($format);
        } catch (\Exception $e) {
            return date($format, (int)($t === null ? time() : $t));
        }
    }

    /**
     * A php.ini setting as the host configured it (php.ini, .user.ini, the server's own settings), not as this request
     * changed it: the admin entry sets max_execution_time to 0 for itself, the update steps keep the host's limit.
     */
    public static function iniGlobal($name)
    {
        $all = self::call('ini_get_all', array(null, true), null);
        if (is_array($all) && isset($all[$name]['global_value'])) {
            return (string)$all[$name]['global_value'];
        }
        return (string)@ini_get($name);
    }

    /** Keeps a mutating step alive when the browser tab closes; the session must already be closed by the caller. */
    public static function keepRunning()
    {
        self::call('ignore_user_abort', array(true));
    }

    /**
     * True when OPcache caches the scripts of THIS process. OPcache's own answer decides whenever it may be asked
     * (opcache_get_status(): it also covers PHP's built-in server, which OPcache serves without opcache.enable_cli); when
     * it may not be asked (the function disabled, opcache.restrict_api), the ini values decide: the extension enabled and,
     * for the command line (cli, phpdbg), opcache.enable_cli as well. An OPcache whose functions are all disabled still
     * caches: it counts as active (the update cannot reset it then and says so), never as "not used". A web server
     * without an active OPcache has nothing to reset: never "reset refused" then (hosting-3 VH3-5, hosting-4 VH4-3/VH4-4).
     */
    public static function opcacheActive()
    {
        if (self::fnAvailable('opcache_get_status')) {
            // false when OPcache is off for this process, or when opcache.restrict_api forbids the call: then the ini
            // values below decide
            $st = @opcache_get_status(false);
            if (is_array($st)) {
                return !empty($st['opcache_enabled']);
            }
        }
        if (!self::iniOn(ini_get('opcache.enable'))) {
            return false;
        }
        if (in_array(PHP_SAPI, array('cli', 'phpdbg'), true) && !self::iniOn(ini_get('opcache.enable_cli'))) {
            return false;
        }
        return true;
    }

    /**
     * True for an ini value that is on. php.ini's "Off" / "no" / "false" read back as "" (ini_get), a value set with -d or
     * ini_set() as the literal "0" / "off": all of them are off.
     */
    public static function iniOn($v)
    {
        if ($v === false || $v === null) {
            return false;
        }
        $v = strtolower(trim((string)$v));
        return in_array($v, array('1', 'on', 'yes', 'true'), true) || (ctype_digit($v) && (int)$v > 0);
    }

    /**
     * True when OPcache never re-reads changed files: opcache.validate_timestamps off ("0", "Off", or the "" that php.ini's
     * Off reads back as). null - an older gate that did not say - counts as a cache that re-reads.
     */
    public static function opcacheNeverRereads($vt)
    {
        return $vt !== null && !self::iniOn($vt);
    }

    /**
     * Makes PHP's OPcache of THIS server read $files again (web requests only: the command line has a cache of its own).
     * Returns the number of files invalidated (0 when OPcache is off or the host forbids it: opcache.restrict_api).
     */
    public static function opcacheInvalidate(array $files)
    {
        if (PHP_SAPI === 'cli' || !self::fnAvailable('opcache_invalidate')) {
            return 0;
        }
        $n = 0;
        foreach ($files as $f) {
            if (self::call('opcache_invalidate', array((string)$f, true), false)) {
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ files

    /**
     * True when $dir is a real folder: not a symlink and not a Windows junction (is_link() is false for a junction, so
     * the real path must lead back to the folder's own name inside its parent's real path).
     */
    public static function realDir($dir)
    {
        $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
        if ($dir === '' || !is_dir($dir) || is_link($dir)) {
            return false;
        }
        $real = @realpath($dir);
        $parent = @realpath(dirname($dir));
        if ($real === false || $parent === false) {
            return false;
        }
        $own = rtrim(str_replace('\\', '/', $parent), '/') . '/' . basename($dir);
        $real = str_replace('\\', '/', $real);
        return self::caseInsensitiveFs() ? strcasecmp($real, $own) === 0 : $real === $own;
    }

    /**
     * Creates $dir and its parents inside the site root. A part that exists must be a real folder (no link, no
     * junction): mkdir() through a dangling junction would create its target somewhere else.
     */
    public static function mkdirs($dir, $mode = 0755)
    {
        $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
        if ($dir === '') {
            return false;
        }
        if (is_dir($dir)) {
            return self::realDir($dir) || self::underRealRoot($dir);
        }
        $parent = dirname($dir);
        if ($parent === $dir || !self::mkdirs($parent, $mode)) {
            return false;
        }
        if (is_link($dir) || file_exists($dir)) {
            return false;
        }
        if (!@mkdir($dir, $mode) && !is_dir($dir)) {
            return false;
        }
        return self::realDir($dir);
    }

    /**
     * A folder that is not "real" by realDir() is still accepted when it is the site root itself or above it (the site
     * may live behind a linked parent folder: a supported setup).
     */
    private static function underRealRoot($dir)
    {
        $root = rtrim(self::root(), '/');
        $d = rtrim($dir, '/');
        return strlen($d) <= strlen($root) && strncasecmp($root, $d, strlen($d)) === 0;
    }

    /** fsync() / fdatasync() where PHP has them (8.1+); a no-op on 7.4. */
    public static function fsync($fh)
    {
        if (is_resource($fh) && function_exists('fsync')) {
            try {
                @fsync($fh);
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * Writes $data to $file atomically: a fresh temp file (fopen 'x') in the same folder, written in full, flushed and
     * synced, then renamed over $file with retries. False when any part fails (the temp file is removed). $stable: a site
     * file a run step writes (version.php): the temp file carries the run's tag (Swapper::tempName), so an attempt that
     * died leaves nothing a later attempt does not replace. Data files keep random names (several requests write them).
     */
    public static function writeAtomic($file, $data, $mode = null, $retrySeconds = 1.0, $stable = false)
    {
        $file = str_replace('\\', '/', (string)$file);
        $dir = dirname($file);
        if (!is_dir($dir) && !self::mkdirs($dir)) {
            return false;
        }
        $tmp = $stable && self::$tmpTag !== '' ? Swapper::tempName($file, 'tmp') : $dir . '/.' . substr(basename($file), 0, 40) . '.' . self::randomId(6) . '.up-tmp';
        $fh = @fopen($tmp, 'xb');
        if (!$fh) {
            return false;
        }
        $len = strlen($data);
        $done = 0;
        while ($done < $len) {
            $w = @fwrite($fh, $done === 0 ? $data : substr($data, $done));
            if ($w === false || $w === 0) {
                @fclose($fh);
                @unlink($tmp);
                return false;
            }
            $done += $w;
        }
        @fflush($fh);
        self::fsync($fh);
        if (!@fclose($fh)) {
            @unlink($tmp);
            return false;
        }
        if ($mode !== null) {
            @chmod($tmp, $mode);
        }
        $r = self::renameRetry($tmp, $file, $retrySeconds);
        if (!$r['ok']) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Copies $src to $dst through a temp file in $dst's folder; $sha receives the sha256 of what was written. The copy
     * gets the current time as its modification time (never the source's: OPcache and compiled templates compare times).
     */
    public static function copyAtomic($src, $dst, $mode = null, &$sha = null, $retrySeconds = 1.0)
    {
        $sha = null;
        $in = @fopen($src, 'rb');
        if (!$in) {
            return false;
        }
        $dst = str_replace('\\', '/', (string)$dst);
        $dir = dirname($dst);
        if (!is_dir($dir) && !self::mkdirs($dir)) {
            @fclose($in);
            return false;
        }
        $tmp = $dir . '/.' . substr(basename($dst), 0, 40) . '.' . self::randomId(6) . '.up-tmp';
        $out = @fopen($tmp, 'xb');
        if (!$out) {
            @fclose($in);
            return false;
        }
        $ctx = hash_init('sha256');
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
            if (!self::writeAllowed()) {
                $ok = false;
                break;
            }
            hash_update($ctx, $buf);
            $len = strlen($buf);
            $done = 0;
            while ($done < $len) {
                $w = @fwrite($out, $done === 0 ? $buf : substr($buf, $done));
                if ($w === false || $w === 0) {
                    $ok = false;
                    break 2;
                }
                $done += $w;
            }
        }
        @fclose($in);
        @fflush($out);
        self::fsync($out);
        if (!@fclose($out) || !$ok) {
            @unlink($tmp);
            return false;
        }
        if ($mode !== null) {
            @chmod($tmp, $mode);
        }
        $r = self::renameRetry($tmp, $dst, $retrySeconds);
        if (!$r['ok']) {
            @unlink($tmp);
            return false;
        }
        $sha = hash_final($ctx);
        return true;
    }

    /**
     * rename() with retries: Windows refuses to replace a file another request has open ("Access is denied", code 5) for
     * a moment; measured longest wait under 1,700 requests/s was 561 ms. Returns ['ok', 'ms', 'tries', 'error'].
     */
    public static function renameRetry($from, $to, $maxSeconds = 1.0)
    {
        $t0 = microtime(true);
        $tries = 0;
        $err = '';
        do {
            $tries++;
            if (@rename($from, $to)) {
                return array('ok' => true, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'tries' => $tries, 'error' => '');
            }
            $e = error_get_last();
            $err = is_array($e) ? (string)$e['message'] : 'rename failed';
            if (!is_file($from)) {
                break;
            }
            usleep(50000);
        } while (microtime(true) - $t0 < $maxSeconds);
        return array('ok' => false, 'ms' => (int)round((microtime(true) - $t0) * 1000), 'tries' => $tries, 'error' => self::redact($err));
    }

    /** sha256 of a file, null when it cannot be read. */
    public static function sha256($file)
    {
        if (!is_file($file)) {
            return null;
        }
        $h = @hash_file('sha256', $file);
        return is_string($h) ? $h : null;
    }

    /**
     * Deletes a folder tree without following links: real sub-folders are entered, any other entry (file, symlink,
     * junction, hard link) is removed by its own name only.
     */
    public static function removeTree($dir)
    {
        $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return true;
        }
        if (!self::realDir($dir)) {
            return @rmdir($dir) || @unlink($dir);
        }
        foreach ((array)@scandir($dir) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..') {
                continue;
            }
            $p = $dir . '/' . $name;
            if (is_dir($p) && self::realDir($p)) {
                self::removeTree($p);
            } elseif (!@unlink($p)) {
                @chmod($p, 0644);
                if (!@unlink($p)) {
                    @rmdir($p);
                }
            }
        }
        return @rmdir($dir);
    }

    /**
     * removeTree() with a deadline (big staging folders: thousands of files): true when the folder is gone, false when the
     * time ran out first (the next step continues; what is already deleted stays deleted). Links are never followed.
     */
    public static function removeTreeTimed($dir, $deadline)
    {
        $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return true;
        }
        if (!self::realDir($dir)) {
            return @rmdir($dir) || @unlink($dir);
        }
        foreach ((array)@scandir($dir) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..') {
                continue;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            $p = $dir . '/' . $name;
            if (is_dir($p) && self::realDir($p)) {
                if (!self::removeTreeTimed($p, $deadline)) {
                    return false;
                }
            } elseif (!@unlink($p)) {
                @chmod($p, 0644);
                if (!@unlink($p)) {
                    @rmdir($p);
                }
            }
        }
        return @rmdir($dir) || !is_dir($dir);
    }

    /** Free bytes on the volume of $dir (inside the site root: open_basedir), null when PHP does not tell. */
    public static function freeSpace($dir)
    {
        $f = self::call('disk_free_space', array($dir), null);
        return is_numeric($f) ? (float)$f : null;
    }

    // ------------------------------------------------------------------ data files

    /** Saves an array as guarded JSON (atomic). */
    public static function saveData($file, array $data)
    {
        return self::writeAtomic($file, self::GUARD . self::json($data) . "\n");
    }

    /** Reads a guarded JSON file; null when missing or damaged. */
    public static function loadData($file)
    {
        $raw = @file_get_contents($file);
        if (!is_string($raw) || strncmp($raw, self::GUARD, strlen(self::GUARD)) !== 0) {
            return null;
        }
        $data = json_decode(substr($raw, strlen(self::GUARD)), true);
        return is_array($data) ? $data : null;
    }

    /** JSON for files and replies: slashes and Unicode kept readable, never false. */
    public static function json($v)
    {
        $out = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return is_string($out) ? $out : 'null';
    }

    /**
     * JSON for a <script type="application/json"> block of a page: "<", ">", "&" and quotes are written as \u escapes, so
     * no value can ever close the script element ("</script>") or start a comment.
     */
    public static function jsonScript($v)
    {
        $out = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return is_string($out) ? $out : 'null';
    }

    // ------------------------------------------------------------------ temporary files next to site files

    /**
     * Tag of the temp files a run writes next to site files (".<name>.<tag>.up-tmp"): derived from the run id, so a step
     * that is repeated after a death writes (and so replaces) the same temp file instead of leaving a second one. Only the
     * holder of the step lock writes site files, so two writers never share a tag. '' outside a run (random names).
     */
    public static function setTmpTag($runId)
    {
        self::$tmpTag = (string)$runId !== '' ? substr(hash('sha256', 'jcupd-tmp|' . (string)$runId), 0, 10) : '';
    }

    public static function tmpTag()
    {
        return self::$tmpTag;
    }

    /**
     * Removes leftover temp files of the updater in folder $dir: ".<name>.<tag>.up-tmp" and ".<name>.<tag>.up-old" (a step
     * that died between writing a temp file and its rename, a replaced file another program still held). $names: only the
     * leftovers of these file names (null = any); a leftover is removed when it carries the current run's tag or is older
     * than $minAge seconds (a temp file another request is writing right now is never touched). Returns the removed
     * site-relative paths (logged by the caller).
     */
    public static function sweepTemps($dir, $names = null, $minAge = 120)
    {
        $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
        $out = array();
        if ($dir === '' || !is_dir($dir)) {
            return $out;
        }
        $want = null;
        if (is_array($names)) {
            $want = array();
            foreach ($names as $n) {
                $want[strtolower(substr((string)$n, 0, 40))] = true;
            }
        }
        $now = time();
        foreach ((array)@scandir($dir) as $f) {
            if (!is_string($f) || $f === '' || $f[0] !== '.' || !preg_match('/^\.(.+)\.([0-9a-f]{8,12})\.up-(tmp|old)$/D', $f, $m)) {
                continue;
            }
            if ($want !== null && !isset($want[strtolower($m[1])])) {
                continue;
            }
            $p = $dir . '/' . $f;
            if (!is_file($p)) {
                continue;
            }
            $mine = self::$tmpTag !== '' && $m[2] === self::$tmpTag;
            if (!$mine && $now - (int)@filemtime($p) < $minAge) {
                continue;
            }
            if (@unlink($p) || (@chmod($p, 0644) && @unlink($p))) {
                $rel = substr($p, strlen(self::root()));
                $out[] = $rel !== false && strpos($p, self::root()) === 0 ? $rel : $p;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ versions

    /**
     * Semantic version 2.0 (MAJOR.MINOR.PATCH, optional -pre-release; numeric pre-release identifiers without leading
     * zeros). Build metadata ("+...") is refused: a release version never carries it.
     */
    public static function semverValid($v)
    {
        if (!is_string($v) || strlen($v) > 40
            || preg_match('/^(0|[1-9]\d{0,4})\.(0|[1-9]\d{0,4})\.(0|[1-9]\d{0,4})(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D', $v, $m) !== 1) {
            return false;
        }
        foreach (isset($m[4]) && $m[4] !== '' ? explode('.', $m[4]) : array() as $id) {
            if (strlen($id) > 1 && $id[0] === '0' && ctype_digit($id)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compares two versions by SemVer 2.0 rules: -1, 0, 1. A pre-release is lower than its release, identifiers compare
     * numerically when numeric and by ASCII otherwise ("1.2.1-dev" < "1.2.1-test" < "1.2.1"). PHP's version_compare()
     * is not used: it sorts unknown words like "test" below "dev".
     */
    public static function semverCompare($a, $b)
    {
        $pa = self::semverParts($a);
        $pb = self::semverParts($b);
        for ($i = 0; $i < 3; $i++) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] < $pb[$i] ? -1 : 1;
            }
        }
        if ($pa[3] === $pb[3]) {
            return 0;
        }
        if ($pa[3] === '') {
            return 1;
        }
        if ($pb[3] === '') {
            return -1;
        }
        $xa = explode('.', $pa[3]);
        $xb = explode('.', $pb[3]);
        $n = max(count($xa), count($xb));
        for ($i = 0; $i < $n; $i++) {
            if (!isset($xa[$i])) {
                return -1;
            }
            if (!isset($xb[$i])) {
                return 1;
            }
            $na = ctype_digit($xa[$i]);
            $nb = ctype_digit($xb[$i]);
            if ($na && $nb) {
                $c = (int)$xa[$i] <=> (int)$xb[$i];
            } elseif ($na !== $nb) {
                $c = $na ? -1 : 1;
            } else {
                $c = strcmp($xa[$i], $xb[$i]);
                $c = $c < 0 ? -1 : ($c > 0 ? 1 : 0);
            }
            if ($c !== 0) {
                return $c;
            }
        }
        return 0;
    }

    /** [major, minor, patch, pre-release]; an invalid version counts as 0.0.0-invalid. */
    private static function semverParts($v)
    {
        if (!self::semverValid((string)$v)) {
            return array(0, 0, 0, 'invalid');
        }
        $pre = '';
        $core = (string)$v;
        $dash = strpos($core, '-');
        if ($dash !== false) {
            $pre = substr($core, $dash + 1);
            $core = substr($core, 0, $dash);
        }
        $p = explode('.', $core);
        return array((int)$p[0], (int)$p[1], (int)$p[2], $pre);
    }

    // ------------------------------------------------------------------ misc

    /** Lowercase hex of $bytes random bytes. */
    public static function randomId($bytes = 8)
    {
        return bin2hex(random_bytes(max(1, (int)$bytes)));
    }

    /**
     * Text for logs: query strings of URLs are dropped (a GitHub asset link carries an 800-character signed query that
     * works like a password for about 48 minutes), long hex / base64 runs (tokens, keys) are shortened. A path of the
     * update's own folders has the same letters as base64 (runtime/update/runs/<id>/backup/files/ of the hand procedure)
     * and stays (e2e-5 P3-4): a run counts as a secret when it holds an upper-case letter, "+" or "=" (a random base64
     * secret of 48 characters lacks all three with a chance of about 1 in 10^11) and is no runtime/update/ path.
     */
    public static function redact($text)
    {
        $text = (string)$text;
        $text = preg_replace('#(https?://[^\s?"\'<>]+)\?[^\s"\'<>]*#i', '$1?…', $text);
        $text = preg_replace('/\b[0-9a-f]{40,}\b/i', '[hex]', $text);
        $text = preg_replace_callback('#\b[A-Za-z0-9+/]{48,}={0,2}#', function ($m) {
            return strpos($m[0], 'runtime/update/') !== false || !preg_match('#[A-Z+=]#', $m[0]) ? $m[0] : '[secret]';
        }, $text);
        return (string)$text;
    }

    /** "1.2 MB" style size. */
    public static function bytes($n)
    {
        $n = (float)$n;
        if ($n < 1024) {
            return (int)$n . ' B';
        }
        foreach (array('KB', 'MB', 'GB', 'TB') as $i => $unit) {
            $v = $n / pow(1024, $i + 1);
            if ($v < 1024 || $unit === 'TB') {
                return number_format($v, 1) . ' ' . $unit;
            }
        }
        return (int)$n . ' B';
    }

    /** php.ini size ("128M") in bytes; -1 for unlimited. */
    public static function iniBytes($v)
    {
        $v = trim((string)$v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (float)$v;
        $u = strtolower(substr($v, -1));
        if ($u === 'g') {
            $n *= 1073741824;
        } elseif ($u === 'm') {
            $n *= 1048576;
        } elseif ($u === 'k') {
            $n *= 1024;
        }
        return (int)$n;
    }

    /**
     * Test mode only: a simulated full disk. The fault switch {"disk_full": {"phase": "<phase>", "after": N}} lets the
     * first N file writes of a request in that phase succeed and fails the rest (state files are never affected).
     */
    public static function writeAllowed()
    {
        static $writes = 0;
        $f = Config::faults();
        if (empty($f['disk_full'])) {
            return true;
        }
        $cfg = $f['disk_full'];
        if (is_array($cfg) && isset($cfg['phase']) && Engine::$phase !== $cfg['phase']) {
            return true;
        }
        $writes++;
        return $writes <= (is_array($cfg) ? (int)($cfg['after'] ?? 0) : 0);
    }

    /** Text cut to $max characters (UTF-8 safe when mbstring is there). */
    public static function cut($text, $max = 300)
    {
        $text = (string)$text;
        if (function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') . '…' : $text;
        }
        return strlen($text) > $max ? substr($text, 0, $max) . '...' : $text;
    }
}
