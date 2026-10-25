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
 * Where updates come from, the fixed limits of the updater, the owner's two settings and the test mode.
 *
 * Source (UPDATER decision U1, amendment A1 of 2026-10-23: GitHub only): the GitHub releases of avdbapi-com/javcms - the
 * API api.github.com and the release assets of github.com, which redirects to release-assets.githubusercontent.com
 * (measured 2026-10-22; objects.githubusercontent.com is kept for older links). No other host is ever contacted, and no
 * address comes from a request or a setting. When GitHub cannot be reached, the check and the update stop with a clear
 * message and change nothing (a package the owner uploads into runtime/update/inbox/ is the offline way).
 *
 * Test mode: the environment variable JAVCMS_UPDATER_TEST names a JSON file with other endpoints (a local mock), a CA
 * file, TEST public keys and fault switches. Every host in it must be a loopback address, and a web request cannot set
 * an environment variable of the server process, so production code can never be pointed anywhere else from the web.
 */
final class Config
{
    const REPO = 'avdbapi-com/javcms';
    const API = 'https://api.github.com';
    const WEB = 'https://github.com';
    /** Hosts an update request may reach (every redirect hop is checked against this list). */
    const HOSTS = array('api.github.com', 'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com');

    /** Automatic checks: at most every 12 hours. */
    const CHECK_EVERY = 43200;
    /** A manual "Check now" waits at least this long after the previous request to GitHub. */
    const CHECK_MIN_GAP = 60;
    /** Seconds of work per step (UPDATER-SCOUT-HOST rule 4: about 10 s, hard cap 15 s; the task: no step over about 20 s). */
    const STEP_BUDGET = 10.0;
    /** Backups, the downloaded package and the "Roll back" button stay this many days. */
    const KEEP_DAYS = 7;
    /** Without a step for this long while visitors see the maintenance page, a visitor's request rolls the run back. */
    const STALL_SECONDS = 180;
    /** An armed run whose page was closed before the first step expires after this long. */
    const ARM_SECONDS = 7200;
    /** Name of the environment variable of the test mode. */
    const TEST_ENV = 'JAVCMS_UPDATER_TEST';

    /** @var array|null|false cached test configuration (false = production) */
    private static $test = null;
    /** @var array|null key list of the release tools (command line only, see useKeys()) */
    private static $toolKeys = null;

    /**
     * Release tools only (verify_manifest.php, push_javcms_release.sh): check signatures against a given key list instead
     * of Keys.php, with the same rules the sites apply (revoked keys, one-hop endorsements). Never available to a web
     * request: a site always uses its compiled keys (or the test keys of the test mode).
     *
     * @param array $trusted key id => base64 public key
     * @param array $revoked key ids
     */
    public static function useKeys(array $trusted, array $revoked = array())
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }
        self::$toolKeys = array('trusted' => $trusted, 'revoked' => array_values(array_map('strval', $revoked)));
        return true;
    }

    /** The release tools' key list (null on sites). */
    public static function toolKeys()
    {
        return PHP_SAPI === 'cli' ? self::$toolKeys : null;
    }

    /** Databases up to this size are backed up whole (the test mode may lower it to test the partial backup of a big site). */
    public static function fullDbBytes()
    {
        $t = self::test();
        if ($t !== null && isset($t['full_db_bytes']) && is_numeric($t['full_db_bytes'])) {
            return max(0, (int)$t['full_db_bytes']);
        }
        return Backup::FULL_DB_BYTES;
    }

    /** On a bigger database, tables up to this size are still backed up with their data (test mode may change it). */
    public static function smallTableBytes()
    {
        $t = self::test();
        if ($t !== null && isset($t['small_table_bytes']) && is_numeric($t['small_table_bytes'])) {
            return max(0, (int)$t['small_table_bytes']);
        }
        return Backup::SMALL_TABLE_BYTES;
    }

    /**
     * The test configuration or null (production). Valid only when every endpoint host is loopback; a file that names any
     * other host is ignored completely (production endpoints and keys stay in force).
     */
    public static function test()
    {
        if (self::$test === null) {
            self::$test = false;
            $file = getenv(self::TEST_ENV);
            if (is_string($file) && $file !== '' && is_file($file)) {
                $cfg = json_decode((string)@file_get_contents($file), true);
                if (is_array($cfg) && self::loopbackOnly($cfg)) {
                    self::$test = $cfg;
                }
            }
        }
        return self::$test === false ? null : self::$test;
    }

    /** Forgets the cached test configuration and the release tools' key list (unit tests switch them). */
    public static function reset()
    {
        self::$test = null;
        self::$toolKeys = null;
    }

    private static function loopbackOnly(array $cfg)
    {
        $urls = array();
        foreach (array('api', 'web') as $k) {
            if (isset($cfg[$k])) {
                $urls[] = (string)$cfg[$k];
            }
        }
        if (!$urls) {
            return false;
        }
        foreach ($urls as $u) {
            $host = strtolower((string)parse_url($u, PHP_URL_HOST));
            if (!in_array($host, array('127.0.0.1', 'localhost', '[::1]', '::1'), true)) {
                return false;
            }
        }
        foreach ((array)($cfg['hosts'] ?? array()) as $h) {
            $host = strtolower(preg_replace('/:\d+$/', '', (string)$h));
            if (!in_array($host, array('127.0.0.1', 'localhost', '[::1]', '::1'), true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Endpoints in force: api, web (base URLs without trailing slash), hosts (allowed host[:port] list), cafile
     * (extra CA file or ''), http (plain http allowed: test mode on loopback only), repo.
     */
    public static function endpoints()
    {
        $t = self::test();
        if ($t !== null) {
            return array(
                'api' => rtrim((string)($t['api'] ?? ''), '/'),
                'web' => rtrim((string)($t['web'] ?? ''), '/'),
                'hosts' => array_values(array_map('strval', (array)($t['hosts'] ?? array()))),
                'cafile' => (string)($t['cafile'] ?? ''),
                'http' => !empty($t['allow_http']),
                'repo' => (string)($t['repo'] ?? self::REPO),
                'test' => true,
            );
        }
        return array('api' => self::API, 'web' => self::WEB, 'hosts' => self::HOSTS, 'cafile' => '',
            'http' => false, 'repo' => self::REPO, 'test' => false);
    }

    /** Seconds without a step before visitors' requests roll a stalled run back (test mode may shorten it). */
    public static function stallSeconds()
    {
        $t = self::test();
        if ($t !== null && isset($t['stall_seconds']) && (int)$t['stall_seconds'] >= 5) {
            return (int)$t['stall_seconds'];
        }
        return self::STALL_SECONDS;
    }

    /**
     * Test-mode fault switch (always false/0 in production): the faults of the test configuration and of its fault file
     * (re-read on every call, so the end-to-end tests switch faults without restarting the server).
     */
    public static function fault($name)
    {
        $f = self::faults();
        return $f[$name] ?? false;
    }

    /** Re-reads the fault file on every call (faults change between steps in the end-to-end tests). */
    public static function faults()
    {
        $t = self::test();
        if ($t === null) {
            return array();
        }
        $f = is_array($t['faults'] ?? null) ? $t['faults'] : array();
        if (!empty($t['fault_file']) && is_file((string)$t['fault_file'])) {
            $more = json_decode((string)@file_get_contents((string)$t['fault_file']), true);
            if (is_array($more)) {
                $f = array_merge($f, $more);
            }
        }
        return $f;
    }

    /** User-Agent of every request (GitHub answers 403 without one). No site address is sent. */
    public static function userAgent()
    {
        return 'JavCMS-Updater/' . self::installedVersion() . ' (+https://github.com/' . self::REPO . ')';
    }

    /**
     * Installed version: the 'code' of application/extra/version.php (the release stamps it; an update changes only
     * that value). '' when unreadable.
     */
    public static function installedVersion()
    {
        $file = Util::abs('application/extra/version.php');
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return '';
        }
        if (preg_match("/['\"]code['\"]\s*=>\s*['\"]([^'\"]{1,40})['\"]/", $raw, $m)) {
            return $m[1];
        }
        return '';
    }

    /** Owner settings (application/extra/updater.php, site-owned): auto_check (default on). */
    public static function settings()
    {
        $file = Util::abs('application/extra/updater.php');
        $s = array();
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            // the file is plain data (var_export); it is parsed, never included, so a damaged copy cannot run code here
            if (is_string($raw) && preg_match("/'auto_check'\s*=>\s*'?([01])'?/", $raw, $m)) {
                $s['auto_check'] = $m[1] === '1';
            }
        }
        return array('auto_check' => array_key_exists('auto_check', $s) ? $s['auto_check'] : true);
    }

    /** Saves the owner settings (application/extra/updater.php is created when missing; updates never replace it). */
    public static function saveSettings(array $s)
    {
        $data = array('auto_check' => !empty($s['auto_check']) ? '1' : '0');
        $php = "<?php\n// One-click updater settings (Admins & backup > Updates). Site file: updates never replace it.\nreturn "
            . var_export($data, true) . ";\n";
        return Util::writeAtomic(Util::abs('application/extra/updater.php'), $php, 0644);
    }
}
