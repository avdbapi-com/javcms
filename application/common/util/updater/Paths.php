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
 * Which files an update may touch, and how.
 *
 * Every path an update handles comes from a signed manifest, and is still checked here (defence in depth, scout-threat):
 * no absolute path, drive letter, "..", ".", backslash, NUL / control character, colon (NTFS alternate data streams),
 * trailing dot or space (Windows drops them: "database.php." IS database.php), Windows device name (CON, NUL, COM1 ...),
 * 8.3 short-name form ("PROGRA~1") or non-ASCII name; all comparisons with the site-owned list ignore case, because on
 * Windows and macOS "Application/Database.php" is application/database.php.
 *
 * Classes of paths:
 * - site-owned: never written, replaced or deleted, even when a release lists them: the database settings, the install
 *   lock, uploads, runtime (caches, logs, the updater's own folder), backups, add-ons (addons/, static/addons/ - the
 *   javcms.net demo add-on lives there), the web server's own files (.user.ini, php.ini, .well-known/);
 * - seeds: shipped for new installs, created by an update when missing, never replaced (application/extra/*.php: the
 *   site's settings; application/data/crawlers/*.json: refreshed daily by housekeeping);
 * - merge-required: replaced only when the site never changed them; a changed copy is kept and the release's new copy
 *   is written to runtime/update/merge/<version>/ with a notice (.htaccess: javcms.net serves its download through a
 *   rule of its own; web.config; favicon.ico, manifest.json, crossdomain.xml, robots.txt: often the site's own);
 * - everything else listed in the manifest: release files.
 */
final class Paths
{
    /** Exact site-owned paths (lower case). */
    const OWNED_FILES = array(
        'application/database.php', 'application/data/install/install.lock', '.user.ini', 'php.ini', '.htpasswd',
        'javcms.net.zip', 'javcms.net.zip.sha256',
    );
    /** Site-owned folders (lower case, trailing slash). */
    const OWNED_DIRS = array(
        'runtime/', 'upload/', 'addons/', 'static/addons/', 'application/data/backup/', 'application/data/install/',
        '.well-known/', 'sess/', 'backup/', 'backups/',
    );
    /** Merge-required files (lower case). */
    const MERGE = array('.htaccess', 'web.config', 'favicon.ico', 'manifest.json', 'crossdomain.xml', 'robots.txt');
    /** Public entry scripts (the admin entry may be renamed by the site). */
    const ENTRIES = array('index.php', 'api.php', 'javcms.php', 'install.php');
    /**
     * Release files the updater itself needs (lower case): the entry scripts (the maintenance gate, the run hooks), the
     * command line, the two background jobs that hold the update's job lock (tools/housekeeping.php, tools/import.php: an
     * update waits for them before it replaces files), the Updates page (controller, its menu rows in auth.php, the
     * Overview's update chip in the Index controller and the update notice in the admin shell view), its scripts, and the
     * two files whose code the new release's upgrade steps run (ScaleSchema.php: the "indexes" step; tools/upgrade.php: the
     * command line's advice after an update) - a site's own older copy of these may call functions a host disables
     * (e2e-6 P3-1); with UPDATER_DIRS and the language files 51-*.php (updaterOwn()). See Planner::build.
     */
    const UPDATER_FILES = array('index.php', 'api.php', 'javcms.php', 'install.php', 'tools/update.php', 'tools/housekeeping.php', 'tools/import.php',
        'tools/upgrade.php', 'application/common/util/scaleschema.php',
        'application/admin/controller/update.php', 'application/admin/common/auth.php', 'application/admin/controller/index.php',
        'application/admin/view/index/index.html', 'static/js/admin_updates.js', 'static/js/admin_update_notice.js');
    /** Folders whose release files the updater itself needs: the engine and the views of the Updates page. */
    const UPDATER_DIRS = array('application/common/util/updater/', 'application/admin/view/update/');
    /** Windows device names, refused as a name or a name stem. */
    const DEVICES = array('con', 'prn', 'aux', 'nul', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9', 'clock$', 'conin$', 'conout$');

    /**
     * Why a manifest path is refused, or '' when it is acceptable. Paths are relative, '/'-separated, printable ASCII,
     * at most 240 characters with segments of at most 120.
     */
    public static function invalid($rel)
    {
        if (!is_string($rel) || $rel === '') {
            return 'empty';
        }
        if (strlen($rel) > 240) {
            return 'too long';
        }
        if (preg_match('/[^\x21-\x7E ]/', $rel)) {
            return 'not printable ASCII';
        }
        if ($rel[0] === '/' || preg_match('#^[A-Za-z]:#', $rel)) {
            return 'absolute';
        }
        if (strpos($rel, '\\') !== false) {
            return 'backslash';
        }
        if (strpos($rel, ':') !== false) {
            return 'colon';
        }
        if (preg_match('/[<>"|?*]/', $rel)) {
            return 'reserved character';
        }
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return 'empty or dot segment';
            }
            if (strlen($seg) > 120) {
                return 'segment too long';
            }
            $last = substr($seg, -1);
            if ($last === '.' || $last === ' ') {
                return 'trailing dot or space';
            }
            if ($seg[0] === ' ') {
                return 'leading space';
            }
            $stem = strtolower(explode('.', $seg)[0]);
            if (in_array($stem, self::DEVICES, true)) {
                return 'device name';
            }
            if (preg_match('/~\d/', $seg)) {
                return 'short-name form';
            }
        }
        return '';
    }

    /** Comparison key: lower case (file systems that ignore case, and the site-owned checks everywhere). */
    public static function key($rel)
    {
        return strtolower((string)$rel);
    }

    /** True for a site-owned path (never touched by an update). Case is ignored on every system. */
    public static function siteOwned($rel)
    {
        $k = self::key($rel);
        if (in_array($k, self::OWNED_FILES, true)) {
            return true;
        }
        foreach (self::OWNED_DIRS as $d) {
            if (strncmp($k, $d, strlen($d)) === 0) {
                return true;
            }
        }
        return false;
    }

    /** True for a seed: created when the site has no such file, never replaced or deleted. */
    public static function seed($rel)
    {
        $k = self::key($rel);
        if (preg_match('#^application/extra/[^/]+\.php$#', $k)) {
            return true;
        }
        if (preg_match('#^application/data/crawlers/[^/]+$#', $k)) {
            return true;
        }
        return false;
    }

    /** True for a merge-required file (root-level only: a theme's own favicon.ico is a normal release file). */
    public static function mergeRequired($rel)
    {
        return in_array(self::key($rel), self::MERGE, true);
    }

    /** True for a root entry script. */
    public static function isEntry($rel)
    {
        return in_array(self::key($rel), self::ENTRIES, true);
    }

    /**
     * True for a release file the updater itself needs (UPDATER decision D-U3). An update replaces these even when the
     * owner keeps the release files the site changed ("Keep my versions"): an old copy of one of them would leave a site
     * whose version number says the new release while its Updates page, its maintenance gate or its run hooks are those
     * of the old one (on the 1.2.0 bridge: "online updates are disabled" and no updater at all). The backup keeps the
     * site's copy, and the Review lists them.
     */
    public static function updaterOwn($rel)
    {
        $k = self::key($rel);
        if (in_array($k, self::UPDATER_FILES, true)) {
            return true;
        }
        foreach (self::UPDATER_DIRS as $d) {
            if (strncmp($k, $d, strlen($d)) === 0) {
                return true;
            }
        }
        return (bool)preg_match('#^application/admin/lang/[a-z]{2}-[a-z]{2}/51-[a-z0-9-]+\.php$#', $k);
    }

    /** The theme slug of template/<slug>/..., or ''. */
    public static function theme($rel)
    {
        if (preg_match('#^template/([^/]+)/#', (string)$rel, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Swap order (UPDATER-SCOUT-HOST rule 12, adapted): entry scripts first right after maintenance is on (index.php and
     * api.php of the new release hold the maintenance gate, so a site without it - 1.2.0 - gets it at once), then every
     * other file, then the admin entry (it holds the updater's own hook in both versions), and the web server's
     * configuration files last. Lower number = earlier.
     */
    public static function swapRank($rel)
    {
        $k = self::key($rel);
        if ($k === 'index.php' || $k === 'api.php') {
            return 0;
        }
        if ($k === '.htaccess' || $k === 'web.config') {
            return 4;
        }
        if ($k === 'javcms.php') {
            return 3;
        }
        if ($k === 'install.php') {
            return 2;
        }
        return 1;
    }

    /** Names a web server or PHP reads as configuration when they lie in a folder (case ignored). */
    const SERVER_CONFIG = array('.htaccess', 'web.config', '.user.ini', 'php.ini');

    /** True when the file name of $rel is one a web server (or PHP-CGI / FPM) reads as the configuration of its folder. */
    public static function serverConfig($rel)
    {
        return in_array(self::key(basename(str_replace('\\', '/', (string)$rel))), self::SERVER_CONFIG, true);
    }

    /**
     * The name a release file gets while it waits in the run's staging folder or in a merge folder: a server
     * configuration file (.htaccess, web.config, .user.ini, php.ini) would be read by the web server there - a release's
     * .htaccess with "RewriteEngine On" replaces the rewrite rules of the folders above it, so the root rule that denies
     * runtime/ would no longer cover the staging copy - so it is kept as "<name>.jcupd.txt" ("htaccess.jcupd.txt") and
     * gets its real name only when the swap writes it into the site. Every other path stays as it is.
     */
    public static function inertName($rel)
    {
        $rel = str_replace('\\', '/', (string)$rel);
        if (!self::serverConfig($rel)) {
            return $rel;
        }
        $dir = dirname($rel);
        $name = ltrim(basename($rel), '.') . '.jcupd.txt';
        return ($dir === '.' || $dir === '') ? $name : $dir . '/' . $name;
    }

    /**
     * True when the release's player configuration file is meant: the admin's "Clear cache" writes the site's player
     * list between two marker comments of static/js/playerconfig.js, so an update keeps that block (Swapper merges it).
     */
    public static function isPlayerConfig($rel)
    {
        return self::key($rel) === 'static/js/playerconfig.js';
    }
}
