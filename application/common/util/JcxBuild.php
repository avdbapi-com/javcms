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
namespace app\common\util;

use think\Db;

/**
 * Ads manager build (ADS-BRIEF 2.1, 2.3, 3, 5.2, 9.1).
 *
 * run() is called after every admin save. It writes
 *   static/jcx/c.json            public config: enabled, non-example items, rules (fallback upload/jcx/c.json)
 *   application/data/jcx/live.php what the placer may print per page type (OPcache, no DB at render)
 * and hashes the print set (canonical JSON of live.php's 'print' part, CF10). lrev (in the page-cache key) is
 * bumped only when that hash changes, so most saves never re-key the page cache.
 *
 * Public API (also used by the admin controller, 12.b):
 *   conf(), confSet($a), publicConf()            application/extra/jcx.php (JcxConf)
 *   ready(), install(), uninstall()             tables + config + first build
 *   run()                                       build; returns ['code','crev','lrev','rekey','bytes','files','err']
 *   refreshAll($rekey = true)                   run() + a forced page-cache re-key (admin "Clear cache", P2-04)
 *   h5Ver($itemId)                              8-hex version of an HTML5 creative folder (its URL path, P2-08)
 *   rootFiles(), rootNameAllowed($name)          root files (3.4)
 *   installExamples($manifest = null, $only = []), removeExamples()   the shipped examples (9.1)
 *   upgrade(), deadRoll($row), dropDeadRolls()  UD4: U15 roll mid / post never play (provider iframe player)
 *   unpackZip($zipFile, $itemId)                HTML5 zip into application/data/jcx/z/<id>/ (11.1 rules)
 *   previewToken($adminId, $scope), previewCheck($value), sendPreviewCookies($adminId, $scope), clearPreviewCookies()
 *   nonce($adminId, $purpose), nonceCheck($nonce, $purpose)
 *   previewData($scope)                         items for jcx/pv (examples and off items included)
 *   pub($row)                                   one item in the public c.json format
 *   missLog()                                   runtime/jcx/miss.json (anchors that did not resolve)
 */
class JcxBuild
{
    /** c.json budget (11.3: 30 KB raw per file) and the size from which the build splits it per page type (80 %, R3r3-05). */
    const C_LIMIT = 30720;
    const C_SPLIT = 24576;
    /** Unit default caps [shows, per hours, gap minutes, not on page view 1] (section 6): U06 3/day, U07 4/day 1 min apart,
     *  U08 3/day 3 min apart never on page view 1, U09 1 per 24 h. */
    const CAP_DEFAULT = ['U06' => [3, 24, 0, 0], 'U07' => [4, 24, 1, 0], 'U08' => [3, 24, 3, 1], 'U09' => [1, 24, 0, 0]];
    const PREVIEW_TTL = 1800;
    const NONCE_TTL = 600;
    private static $ready = null;

    /* ------------------------------------------------------------------ paths */

    public static function base()
    {
        $b = defined('MAC_PATH') ? (string)MAC_PATH : (string)(function_exists('config') ? (self::site('install_dir') ?: '/') : '/');
        $b = '/' . trim(str_replace('\\', '/', $b), '/');
        return $b === '/' ? '/' : $b . '/';
    }

    /** One value of the site settings (ThinkPHP 5.0 config() reads only two key levels). */
    public static function site($key)
    {
        if (isset($GLOBALS['config']['site'][$key])) { return $GLOBALS['config']['site'][$key]; }
        $s = function_exists('config') ? config('maccms.site') : null;
        return is_array($s) && isset($s[$key]) ? $s[$key] : '';
    }

    public static function confFile() { return JcxConf::file(); }
    public static function liveFile() { return APP_PATH . 'data/jcx/live.php'; }
    public static function zDir() { return APP_PATH . 'data/jcx/z/'; }
    public static function runtimeDir() { return (defined('RUNTIME_PATH') ? RUNTIME_PATH : ROOT_PATH . 'runtime/') . 'jcx/'; }

    /* ------------------------------------------------------------------ config */

    public static function conf() { return JcxConf::get(); }

    public static function confSet(array $set) { return JcxConf::set($set); }

    /** Config without the secret (for admin views). */
    public static function publicConf()
    {
        $c = self::conf();
        unset($c['k']);
        return $c;
    }

    /* ------------------------------------------------------------------ install */

    public static function ready()
    {
        if (self::$ready !== null) { return self::$ready; }
        if (!is_file(self::confFile())) { return self::$ready = false; }
        try {
            foreach (['jcx_item', 'jcx_place', 'jcx_anchor', 'jcx_stat', 'jcx_root'] as $t) { Db::name($t)->limit(1)->select(); }
            return self::$ready = true;
        } catch (\Throwable $e) {
            return self::$ready = false;
        }
    }

    public static function install()
    {
        try {
            $prefix = (string)config('database.prefix');
            if (!preg_match('/^[a-z0-9_]{1,32}$/D', $prefix)) { return ['code' => 0, 'msg' => 'database prefix']; }
            $sql = (string)@file_get_contents(APP_PATH . 'install/sql/jcx.sql');
            $names = ['jcx_item', 'jcx_place', 'jcx_anchor', 'jcx_stat', 'jcx_root'];
            if (substr_count($sql, 'CREATE TABLE IF NOT EXISTS') !== 5) { return ['code' => 0, 'msg' => 'jcx.sql']; }
            foreach ($names as $n) { if (strpos($sql, '`javcms_' . $n . '`') === false) { return ['code' => 0, 'msg' => 'jcx.sql']; } }
            foreach (explode(';', str_replace('`javcms_', '`' . $prefix, $sql)) as $st) {
                if (trim($st) !== '') { Db::execute($st); }
            }
            self::seedPlaces();
            foreach ([APP_PATH . 'data/jcx', self::zDir(), self::runtimeDir()] as $d) { if (!is_dir($d)) { @mkdir($d, 0755, true); } }
            $ht = APP_PATH . 'data/jcx/.htaccess';
            if (!is_file($ht)) { @file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"); }
            if (!is_file(self::confFile())) {
                JcxConf::set(['k' => bin2hex(random_bytes(32)), 'cfg' => self::cfgWritable() ? 'static/jcx/c.json' : 'upload/jcx/c.json'], true);
            } elseif ((string)self::conf()['k'] === '') {
                JcxConf::set(['k' => bin2hex(random_bytes(32))]);
            }
            self::$ready = null;
            $b = self::run();
            return ['code' => 1, 'build' => $b];
        } catch (\Throwable $e) {
            return ['code' => 0, 'msg' => $e->getMessage()];
        }
    }

    private static function seedPlaces()
    {
        $seed = ['P01' => [0, 0, ['phone_play' => 0]], 'P02' => [0, 0, []], 'P03' => [0, 0, []], 'P03d' => [0, 0, []], 'P04' => [0, 0, []],
            'P05' => [8, 2, ['home_every' => 4, 'home_max' => 1]], 'P06' => [0, 0, []], 'P07' => [0, 0, []], 'P08' => [0, 0, ['min_gutter' => 180]],
            'P09' => [0, 0, ['close_mem' => 'session']], 'P10' => [0, 0, []], 'P11' => [0, 0, []], 'P12' => [0, 0, []], 'P13' => [0, 0, ['after_p' => 3]],
            'P14' => [0, 0, []], 'P15h' => [0, 0, []], 'P15b' => [0, 0, []], 'P16' => [0, 0, []]];
        $have = [];
        foreach (Db::name('jcx_place')->field('pl_code')->select() as $r) { $have[$r['pl_code']] = 1; }
        foreach ($seed as $code => $s) {
            if (isset($have[$code])) { continue; }
            Db::name('jcx_place')->insert(['pl_code' => $code, 'pl_status' => 1, 'pl_every' => $s[0], 'pl_max' => $s[1], 'pl_opts' => $s[2] ? json_encode($s[2]) : '', 'pl_edit' => time()]);
        }
    }

    /** Future schema changes: ALTERs by number (schema 1 = the 5 tables of 3.2). */
    public static function upgrade()
    {
        $c = self::conf();
        $moved = 0;
        try {
            // An HTML5 creative (U17) in P10 never rendered (R1-10); the place is no longer offered: such rows go to P03.
            if (self::ready()) { $moved = (int)Db::name('jcx_item')->where('item_unit', 'U17')->where('item_place', 'P10')->update(['item_place' => 'P03', 'item_pages' => '']); }
        } catch (\Throwable $e) {}
        $roll = ['off' => 0, 'examples' => 0];
        try { if (self::ready()) { $roll = self::dropDeadRolls(); } } catch (\Throwable $e) {}
        return ['code' => 1, 'schema' => (int)$c['schema'], 'moved' => $moved, 'roll_off' => $roll['off'], 'roll_examples' => $roll['examples']];
    }

    /**
     * UD4 (owner, 2026-10-14): every video plays in the provider's iframe player, so a video ad set to play during or
     * after the video (U15 roll mid / post) can never show. Such rows never reach c.json, live.php, #jcx-v or the preview.
     */
    public static function deadRoll(array $r)
    {
        if ((string)($r['item_unit'] ?? '') !== 'U15') { return false; }
        $o = $r['item_opts'] ?? '';
        $o = is_array($o) ? $o : (json_decode((string)$o, true) ?: []);
        return in_array((string)($o['roll'] ?? ''), ['mid', 'post'], true);
    }

    /**
     * UD4 clean-up: switch off the owner's old mid / post-roll items that are still on (they stay in the list with a note,
     * so the owner can delete them) and delete the old shipped examples of them (X46 / X47, item_example = 1).
     */
    public static function dropDeadRolls()
    {
        $off = [];
        $ex = [];
        foreach (Db::name('jcx_item')->where('item_unit', 'U15')->field('item_id,item_unit,item_opts,item_status,item_example')->select() as $r) {
            if (!self::deadRoll($r)) { continue; }
            if ((int)$r['item_example']) { $ex[] = (int)$r['item_id']; }
            elseif ((int)$r['item_status']) { $off[] = (int)$r['item_id']; }
        }
        if ($off) { Db::name('jcx_item')->where('item_id', 'in', $off)->where('item_status', 1)->update(['item_status' => 0, 'item_edit' => time()]); }
        if ($ex) { Db::name('jcx_item')->where('item_id', 'in', $ex)->where('item_example', 1)->delete(); }
        return ['off' => count($off), 'examples' => count($ex)];
    }

    private static function cfgWritable()
    {
        $d = ROOT_PATH . 'static/jcx/';
        return is_dir($d) && is_writable($d);
    }

    /** Settings > Danger zone > Remove all ads data (3.5). */
    public static function uninstall()
    {
        $removed = [];
        try {
            if (self::ready()) {
                foreach (Db::name('jcx_root')->select() as $r) {
                    $f = ROOT_PATH . $r['rf_name'];
                    if ($r['rf_sha'] !== '' && is_file($f) && hash_equals($r['rf_sha'], hash_file('sha256', $f))) { @unlink($f); $removed[] = $r['rf_name']; }
                }
            }
            foreach (array_merge(glob(ROOT_PATH . 'static/jcx/c*.json') ?: [], glob(ROOT_PATH . 'upload/jcx/c*.json') ?: []) as $f) {
                if (preg_match('~/c(?:-[a-z0-9]+)?\.json$~', str_replace('\\', '/', $f))) { @unlink($f); $removed[] = basename($f); }
            }
            if (is_file(self::liveFile())) { @unlink(self::liveFile()); if (function_exists('opcache_invalidate')) { @opcache_invalidate(self::liveFile(), true); } $removed[] = 'live.php'; }
            self::rmTree(self::zDir());
            $prefix = (string)config('database.prefix');
            if (preg_match('/^[a-z0-9_]{1,32}$/D', $prefix)) {
                foreach (['jcx_item', 'jcx_place', 'jcx_anchor', 'jcx_stat', 'jcx_root'] as $t) { Db::execute('DROP TABLE IF EXISTS `' . $prefix . $t . '`'); }
            }
            $c = self::conf();
            JcxConf::set(['on' => 0, 'lrev' => (int)$c['lrev'] + 1, 'phash' => '', 'built' => time(), 'err' => '']);
            self::$ready = null;
            return ['code' => 1, 'removed' => $removed];
        } catch (\Throwable $e) {
            return ['code' => 0, 'msg' => $e->getMessage(), 'removed' => $removed];
        }
    }

    /* ------------------------------------------------------------------ build */

    /** Visitor rules that keep a creative out of the server print (5.5, 2.3). */
    public static function visitorRule(array $r)
    {
        return (int)$r['item_cap_n'] > 0 || (int)$r['item_cap_gap'] > 0 || (int)$r['item_skip_first'] > 0 || (string)$r['item_geo'] !== ''
            || (int)$r['item_who'] > 0 || (int)$r['item_days'] !== 127 || (string)$r['item_hours'] !== '';
    }

    private static function pages(array $r)
    {
        $allowed = \app\common\model\Jcx::PLACE_PAGES[$r['item_place']] ?? [];
        $p = (string)$r['item_pages'] === '' ? $allowed : array_values(array_intersect(explode(',', $r['item_pages']), $allowed));
        return $p;
    }

    private static function csvList($v, $int = false)
    {
        $o = [];
        foreach (explode(',', (string)$v) as $x) { $x = trim($x); if ($x !== '') { $o[] = $int ? (int)$x : $x; } }
        return $o;
    }

    private static function path($p, $base)
    {
        $p = (string)$p;
        if ($p === '' || preg_match('~^https?://~i', $p)) { return $p; }
        return $base . ltrim($p, '/');
    }

    /**
     * A VAST tag on this site's own host written as an absolute URL (the example installer and copies of it store
     * https://<site>/static/jcx/x/<hash>.xml) becomes root-relative at build time (R3r3-09): a visitor on the other
     * scheme or host name (www / no www, http / https) then fetches it same-origin, with no CORS. Other hosts stay as
     * they are. The XML itself keeps its absolute media / tracking URLs (vast-client needs them).
     */
    public static function ownTag($v)
    {
        $v = (string)$v;
        if (!preg_match('~^https?://([^/?#]+)(/[^#]*)$~i', $v, $m)) { return $v; }
        $host = strtolower(preg_replace('~^https?://~i', '', rtrim((string)self::site('site_url'), '/')));
        $host = preg_replace('~/.*$~', '', $host);
        $hosts = array_filter([$host, strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))]);
        $h = strtolower($m[1]);
        foreach ($hosts as $x) { if ($h === $x || $h === 'www.' . $x || 'www.' . $h === $x) { return $m[2]; } }
        // a shipped / installed VAST file of this engine (static/jcx/x/<hex>.xml) that exists here, written with another
        // host (a copy of the site, a moved domain): this site's own copy
        if (preg_match('~/static/jcx/x/([0-9a-f]{8}\.xml)$~D', $m[2], $f) && is_file(ROOT_PATH . 'static/jcx/x/' . $f[1])) { return self::base() . 'static/jcx/x/' . $f[1]; }
        return $v;
    }

    private static function media(array $r, $base)
    {
        $m = json_decode((string)$r['item_media'], true) ?: [];
        foreach (['src', 'srcm', 'still', 'poster', 'hls', 'webm'] as $k) { if (!empty($m[$k])) { $m[$k] = self::path($m[$k], $base); } }
        if (!empty($m['vast'])) { foreach ($m['vast'] as $i => $v) { $t = self::ownTag($v); $m['vast'][$i] = $t !== (string)$v ? $t : self::path($v, $base); } }
        if (!empty($m['cards'])) { foreach ($m['cards'] as $i => $c) { $m['cards'][$i]['img'] = self::path($c['img'] ?? '', $base); } }
        if (!empty($m['pod'])) { foreach ($m['pod'] as $i => $c) { $m['pod'][$i]['src'] = self::path($c['src'] ?? '', $base); if (!empty($c['poster'])) { $m['pod'][$i]['poster'] = self::path($c['poster'], $base); } } }
        return $m;
    }

    /** One item in the public c.json format (5.2). Empty values are left out; defaults: w 10, d 3, dy 127, md 0. */
    public static function pub(array $r, $minimal = false, $base = null)
    {
        $base = $base === null ? self::base() : $base;
        $o = ['id' => (int)$r['item_id'], 'u' => $r['item_unit'], 'k' => $r['item_kind'], 'p' => $r['item_place']];
        $pg = self::pages($r);
        if (count($pg) < count(\app\common\model\Jcx::PAGES)) { $o['pg'] = $pg; }
        $sz = [(int)$r['item_w'], (int)$r['item_h'], (int)$r['item_wm'], (int)$r['item_hm']];
        if (array_sum($sz)) { $o['sz'] = $sz; }
        if ((int)$r['item_dev'] !== 3) { $o['d'] = (int)$r['item_dev']; }
        if ($minimal) { $o['sv'] = 1; return $o; }
        if ((int)$r['item_weight'] !== 10) { $o['w'] = (int)$r['item_weight']; }
        if ((string)$r['item_langs'] !== '') { $o['lg'] = self::csvList($r['item_langs']); }
        if ((string)$r['item_cats'] !== '') { $o['ct'] = self::csvList($r['item_cats'], true); }
        if ((string)$r['item_geo'] !== '') {
            $g = (string)$r['item_geo'];
            if ($g[0] === '!') { $o['cx'] = 1; $g = substr($g, 1); }
            $o['cc'] = self::csvList($g);
        }
        if ((int)$r['item_who']) { $o['who'] = (int)$r['item_who']; }
        if ((int)$r['item_start']) { $o['s'] = (int)$r['item_start']; }
        if ((int)$r['item_end']) { $o['e'] = (int)$r['item_end']; }
        if ((int)$r['item_days'] !== 127) { $o['dy'] = (int)$r['item_days']; }
        if ((string)$r['item_hours'] !== '') { $o['hr'] = $r['item_hours']; }
        $cap = [(int)$r['item_cap_n'], (int)$r['item_cap_h'], (int)$r['item_cap_gap'], (int)$r['item_skip_first']];
        // 0 = unit default (section 6): the effective cap is written, so the runtime needs no defaults table.
        if (!array_sum($cap) && isset(self::CAP_DEFAULT[$r['item_unit']])) { $cap = self::CAP_DEFAULT[$r['item_unit']]; }
        if (array_sum($cap)) { $o['cap'] = $cap; }
        if ((int)$r['item_delay']) { $o['dl'] = (int)$r['item_delay']; }
        $m = self::media($r, $base);
        if ($m) { $o['m'] = $m; }
        foreach (['item_url' => 'u2', 'item_alt' => 'alt', 'item_title' => 'ti', 'item_text' => 'tx', 'item_brand' => 'br', 'item_cta' => 'cta'] as $f => $k) {
            if ((string)$r[$f] !== '') { $o[$k] = (string)$r[$f]; }
        }
        $md = (int)$r['item_code_mode'];
        if ($md) { $o['md'] = $md; }
        if ($md !== 1) {
            if ((string)$r['item_code'] !== '') { $o['c'] = (string)$r['item_code']; }
            if ((string)$r['item_code_m'] !== '') { $o['cm'] = (string)$r['item_code_m']; }
        }
        $op = json_decode((string)$r['item_opts'], true) ?: [];
        if (!empty($op['wallpaper'])) { $op['wallpaper'] = self::path($op['wallpaper'], $base); }
        if (!empty($op['expand_img'])) { $op['expand_img'] = self::path($op['expand_img'], $base); }
        if ($op) { $o['o'] = $op; }
        if (!empty($op['target_id']) && in_array($r['item_unit'], ['U12', 'U14'], true) && ($t = self::vodTarget($op['target_id']))) {
            if ($r['item_unit'] === 'U14') { $o['tg'] = $t['tg']; }
            else {
                // Promoted video card: the site's own thumbnail, title and page (X36).
                $o['m'] = ($o['m'] ?? []) + ['src' => $t['pic']];
                if (empty($o['u2'])) { $o['u2'] = $t['url']; }
                if (empty($o['ti'])) { $o['ti'] = $t['name']; }
            }
        }
        // P2-08: the creative folder's version is part of the path, so its files can be cached for 30 days at the browser
        // and at Cloudflare (a new zip = a new version = new URLs; the unversioned path stayed stale for 30 days)
        if ($r['item_kind'] === 'html5') { $o['h5'] = $base . 'index.php/jcx/h5/' . (int)$r['item_id'] . '/' . self::h5Ver($r['item_id']) . '/index.html'; }
        return $o;
    }

    private static function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Version of an HTML5 creative: 8 hex digits of the newest mtime in application/data/jcx/z/<id>/ ('00000000' = none). */
    public static function h5Ver($id)
    {
        $dir = self::zDir() . (int)$id;
        $t = 0;
        if (is_dir($dir)) {
            try {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) { if ($f->isFile()) { $t = max($t, (int)$f->getMTime()); } }
            } catch (\Throwable $e) {}
        }
        return sprintf('%08x', $t & 0xffffffff);
    }

    /** URL key of a video (rewrite.vod_id: 1 = vod_sub, 2 = encoded id, else vod_id), its thumbnail and name (U12 / U14). */
    public static function vodTarget($id)
    {
        $id = (int)$id;
        if ($id < 1) { return null; }
        try { $v = Db::name('vod')->where('vod_id', $id)->field('vod_id,vod_sub,vod_name,vod_pic')->find(); } catch (\Throwable $e) { return null; }
        if (!$v) { return null; }
        $rw = $GLOBALS['config']['rewrite'] ?? (function_exists('config') ? (array)config('maccms.rewrite') : []);
        $mode = (string)($rw['vod_id'] ?? '0');
        $key = (string)$v['vod_id'];
        if ($mode === '1' && (string)$v['vod_sub'] !== '') { $key = (string)$v['vod_sub']; }
        elseif ($mode === '2' && function_exists('mac_alphaID')) { $key = (string)mac_alphaID($v['vod_id'], false, $rw['encode_len'] ?? 6, $rw['encode_key'] ?? ''); }
        if (!preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $key)) { return null; }
        $pretty = (string)($rw['status'] ?? '0') === '1';
        $url = self::base() . ($pretty ? 'detail/' . $key . '/' : 'index.php/vod/detail/id/' . (int)$v['vod_id'] . '.html');
        $pic = (string)$v['vod_pic'];
        if ($pic !== '' && !preg_match('~^https?://~i', $pic)) { $pic = self::base() . ltrim($pic, '/'); }
        return ['tg' => $key, 'url' => $url, 'pic' => $pic, 'name' => html_entity_decode((string)$v['vod_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    }

    /**
     * Server-printed markup of a single own creative (image or text link), or '' (2.5, D05, D06).
     * A picture is printed inside <noscript> (repair round 2, R3r2-01 / -02 / -03): with JS off it shows as a plain lazy
     * <a><picture><img loading=lazy></a>; with JS on the browser keeps it as text (nothing is parsed, styled or
     * downloaded) and jcx.js inserts it when the slot comes near the viewport on the device that shows it, through the
     * LCP guard (a creative that could be the largest paint waits for the first input). The srcdoc frame of R3-04 is
     * gone: each frame was a 70-110 ms task on a phone at 4x CPU, also when its slot was hidden on that device, and the
     * hidden device's picture was downloaded. A text link (U19) stays plain markup: one line of text is never the LCP.
     */
    public static function srvMarkup(array $r, $base)
    {
        if (self::visitorRule($r)) { return ''; }
        $url = (string)$r['item_url'];
        if ($url === '') { return ''; }
        if ($r['item_kind'] === 'link' && $r['item_unit'] === 'U19') {
            $t = (string)$r['item_text'] !== '' ? $r['item_text'] : $r['item_title'];
            return '<a class="jcx-a" href="' . self::e($url) . '" rel="sponsored noopener" target="_blank">' . self::e($t) . '</a>';
        }
        if ($r['item_kind'] !== 'image' || !in_array($r['item_unit'], ['U01', 'U02', 'U18'], true)) { return ''; }
        if ($r['item_unit'] === 'U18' && json_decode((string)$r['item_opts'], true)) {
            $op = json_decode((string)$r['item_opts'], true);
            if (!empty($op['wallpaper'])) { return ''; }
        }
        $m = self::media($r, $base);
        foreach (['src', 'srcm', 'still'] as $k) { if (!empty($m[$k]) && preg_match('/\.(?:mp4|webm|m3u8)$/i', $m[$k])) { return ''; } }
        $dev = (int)$r['item_dev'];
        $src = $m['src'] ?? '';
        $srcm = $m['srcm'] ?? '';
        $w = (int)$r['item_w']; $h = (int)$r['item_h']; $wm = (int)$r['item_wm']; $hm = (int)$r['item_hm'];
        if ($dev === 2 && $srcm !== '') { $src = $srcm; $w = $wm; $h = $hm; $srcm = ''; }
        if ($src === '') { $src = $srcm; $w = $wm; $h = $hm; $srcm = ''; }
        if ($src === '') { return ''; }
        $img = '<img src="' . self::e($src) . '"' . ($w && $h ? ' width="' . $w . '" height="' . $h . '"' : '') . ' alt="' . self::e($r['item_alt']) . '"'
            . ' loading="lazy" decoding="async">';
        $srcs = '';
        if (!empty($m['still'])) { $srcs .= '<source media="(prefers-reduced-motion: reduce)" srcset="' . self::e($m['still']) . '">'; }
        if ($srcm !== '' && $dev === 3) { $srcs .= '<source media="(max-width:767px)" srcset="' . self::e($srcm) . '"' . ($wm && $hm ? ' width="' . $wm . '" height="' . $hm . '"' : '') . '>'; }
        $pic = $srcs !== '' ? '<picture>' . $srcs . $img . '</picture>' : $img;
        // %JCXL% = the slot label in the visitor's language (the placer fills it in): visitors without JS see the label
        // with the picture, the slot's own label waits for jcx.js (critical style, repair round 3)
        return '<noscript><span class="jcx-l">%JCXL%</span><a href="' . self::e($url) . '" rel="sponsored noopener" target="_blank">' . $pic . '</a></noscript>';
    }

    /**
     * Shared-catalog keys (ui.jcx_*) that pasted / example code asks for through window.JcxT('jcx_...', fallback)
     * (CONTRACT-3 E8, the X34 push mock): literal jcx_<name> tokens (catalog key ui.jcx_<name>) and template_ui_<name>
     * tokens (catalog key as is, e.g. template_ui_close) in the code itself and in the local script files it loads from
     * static/jcx/x/<hex>.js (read here, at most 256 KB each). Only keys that exist in the English catalog count; the
     * placer prints their text in the visitor's language. Returns the sorted key list (as the code writes them).
     */
    public static function textKeys(array $codes)
    {
        static $en = null;
        if ($en === null) { $en = json_decode((string)@file_get_contents(APP_PATH . 'common/template_lang/en.json'), true) ?: []; }
        $txt = '';
        foreach ($codes as $c) {
            $c = (string)$c;
            if ($c === '') { continue; }
            $txt .= $c;
            if (preg_match_all('~static/jcx/x/([0-9a-f]{6,16})\.js~', $c, $m)) {
                foreach (array_unique($m[1]) as $h) { $f = ROOT_PATH . 'static/jcx/x/' . $h . '.js'; if (is_file($f) && filesize($f) <= 262144) { $txt .= (string)file_get_contents($f); } }
            }
        }
        $out = [];
        if ($txt !== '' && preg_match_all('~\b(?:jcx|template_ui)_[a-z0-9_]{2,40}\b~', $txt, $m)) {
            foreach (array_unique($m[0]) as $k) { $c = JcxPlacer::catKey($k); if ($c !== '' && isset($en[$c]) && is_string($en[$c])) { $out[] = $k; } }
        }
        sort($out);
        return array_slice($out, 0, 40);
    }

    /** Parse-mode print: one device's code chosen at parse time with document.write (D27). */
    public static function parseMarkup($code, $codeM, $dev)
    {
        $f = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $c = ($dev & 1) ? (string)$code : '';
        $m = ($dev & 2) ? ((string)$codeM !== '' ? (string)$codeM : (string)$code) : '';
        if ($m === $c) { return '<script>document.write(' . json_encode($c, $f) . ')</script>'; }
        return '<script>document.write(matchMedia(\'(max-width:767px)\').matches?' . json_encode($m, $f) . ':' . json_encode($c, $f) . ')</script>';
    }

    /** Held code (consent / visitor rules): inert <template> blocks, one per device variant (CF11). */
    public static function holdMarkup(array $r)
    {
        $id = (int)$r['item_id'];
        $dev = (int)$r['item_dev'];
        $c = (string)$r['item_code'];
        $m = (string)$r['item_code_m'];
        $md = (int)$r['item_code_mode'];
        $mode = $md === 1 ? ' data-jcx-md="2"' : ($md === 2 ? ' data-jcx-md="2"' : '');
        if ($m === '' || $dev !== 3) {
            $code = ($dev === 2 && $m !== '') ? $m : $c;
            $dv = $dev === 3 ? '' : ' data-jcx-dv="' . $dev . '"';
            return '<template data-jcx-hold data-jcx-i="' . $id . '"' . $dv . $mode . '>' . $code . '</template>';
        }
        return '<template data-jcx-hold data-jcx-i="' . $id . '" data-jcx-dv="1"' . $mode . '>' . $c . '</template>'
            . '<template data-jcx-hold data-jcx-i="' . $id . '" data-jcx-dv="2"' . $mode . '>' . $m . '</template>';
    }

    private static function canon($v)
    {
        if (is_array($v)) {
            $assoc = array_keys($v) !== range(0, count($v) - 1);
            if ($assoc) { ksort($v, SORT_STRING); }
            foreach ($v as $k => $x) { $v[$k] = self::canon($x); }
        }
        return $v;
    }

    /**
     * Hash of what the placer can print (R5-03): slot entries are projected to [p, dv, sz, s, e, lg, ct, expandable] and de-duplicated,
     * the item id is kept only where the markup prints it (srv / pm / hold). Switching a further rotating slot item on or
     * off in a place that already prints its slot then leaves the hash (and the page-cache key) unchanged.
     */
    public static function printHash(array $print)
    {
        foreach ((array)($print['pt'] ?? []) as $page => $d) {
            $set = [];
            foreach ((array)($d['e'] ?? []) as $e) {
                $x = [$e['p'] ?? '', (int)($e['dv'] ?? 3), $e['sz'] ?? [], (int)($e['s'] ?? 0), (int)($e['e'] ?? 0), $e['lg'] ?? [], $e['ct'] ?? [], ($e['u'] ?? '') === 'U05' ? 1 : 0];
                if (!empty($e['srv']) || !empty($e['pm']) || !empty($e['hold'])) { $x[] = (int)$e['id']; $x[] = $e['srv'] ?? ''; $x[] = $e['pm'] ?? ''; $x[] = $e['hold'] ?? ''; }
                $set[json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] = 1;
            }
            ksort($set, SORT_STRING);
            $print['pt'][$page]['e'] = array_keys($set);
        }
        return hash('sha256', json_encode(self::canon($print), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Build c.json + live.php from the database. Never puts an example or an off item into a public file
     * (assertion: the build fails and keeps the old files). Returns ['code'=>1,'crev','lrev','rekey','bytes','files'].
     */
    public static function run()
    {
        $lock = null;
        try {
            $rt = self::runtimeDir();
            if (!is_dir($rt)) { @mkdir($rt, 0755, true); }
            $lock = @fopen($rt . 'build.lock', 'c');
            if ($lock) { @flock($lock, LOCK_EX); }
            JcxConf::flush();
            $conf = self::conf();
            $base = self::base();
            $places = [];
            foreach (Db::name('jcx_place')->select() as $p) { $p['o'] = json_decode((string)$p['pl_opts'], true) ?: []; $places[$p['pl_code']] = $p; }
            $exampleIds = [];
            foreach (Db::name('jcx_item')->where('item_example', 1)->field('item_id')->select() as $x) { $exampleIds[(int)$x['item_id']] = 1; }
            $rows = [];
            $q = Db::name('jcx_item')->where('item_status', 1)->where('item_example', 0)->order('item_sort desc,item_id asc')->select();
            foreach ($q as $r) {
                if ($r['item_place'] !== '' && isset($places[$r['item_place']]) && !(int)$places[$r['item_place']]['pl_status']) { continue; }
                if ((int)$r['item_end'] && (int)$r['item_end'] < time()) { continue; }
                // An HTML5 creative in P10 has no renderer (R1-10): the model refuses it now; old rows never print.
                if ($r['item_unit'] === 'U17' && $r['item_place'] === 'P10') { continue; }
                // UD4: a video ad during / after the video can never show on the provider's iframe player: never printed.
                if (self::deadRoll($r)) { continue; }
                $rows[] = $r;
            }
            // Place settings used by the placer and the runtime.
            $pl = [];
            $p5 = $places['P05'] ?? ['pl_every' => 8, 'pl_max' => 2, 'o' => []];
            $pl['P05'] = ['e' => (int)$p5['pl_every'] ?: 8, 'm' => (int)$p5['pl_max'] ?: 2, 'he' => (int)($p5['o']['home_every'] ?? 4), 'hm' => (int)($p5['o']['home_max'] ?? 1)];
            if ((int)$conf['safe']) { $pl['P05']['e'] = max(6, $pl['P05']['e']); $pl['P05']['m'] = min(2, $pl['P05']['m']); $pl['P05']['he'] = max(4, $pl['P05']['he']); $pl['P05']['hm'] = min(1, $pl['P05']['hm']); }
            $pl['P01'] = ['pp' => (int)($places['P01']['o']['phone_play'] ?? 0)];
            $pl['P08'] = ['g' => (int)($places['P08']['o']['min_gutter'] ?? 180)];
            $pl['P09'] = ['cm' => (string)($places['P09']['o']['close_mem'] ?? 'session')];
            $pl['P13'] = ['ap' => (int)($places['P13']['o']['after_p'] ?? 3)];
            // Count items per page type and place (R5-05): an item is server-printed only where it is the single item of
            // its place on that page type (P04 counts with P03: without a sidebar the placer shows P04 under the player).
            $perPP = [];
            $rw = 0;
            $edges = [];
            foreach ($rows as $r) {
                $k = $r['item_place'] === 'P04' ? 'P03' : $r['item_place'];
                foreach (self::pages($r) as $page) { $perPP[$page][$k] = ($perPP[$page][$k] ?? 0) + 1; }
                if ($r['item_unit'] === 'U15' && ((json_decode((string)$r['item_opts'], true) ?: [])['roll'] ?? '') === 'reward') { $rw = 1; }
                foreach (['item_start', 'item_end'] as $f) { if ((int)$r[$f] > time()) { $edges[(int)$r[$f]] = 1; } }
            }
            ksort($edges);
            $edges = array_slice(array_keys($edges), 0, 100);
            $items = [];
            $ids = [];
            $pt = [];
            $consent = (int)$conf['consent'];
            foreach ($rows as $r) {
                $id = (int)$r['item_id'];
                if (isset($exampleIds[$id]) || (int)$r['item_example']) { throw new \RuntimeException('example reached the public config'); }
                $srv = in_array($r['item_place'], \app\common\model\Jcx::SLOTS, true) ? self::srvMarkup($r, $base) : '';
                $k = $r['item_place'] === 'P04' ? 'P03' : $r['item_place'];
                $single = [];
                foreach (self::pages($r) as $page) { $single[$page] = ($perPP[$page][$k] ?? 0) === 1; }
                // c.json carries only the minimal entry when every page of the item prints it from the server
                $minimal = $srv !== '' && $single && !in_array(false, $single, true);
                $items[] = self::pub($r, $minimal, $base);
                $ids[] = $id;
                $win = [(int)$r['item_start'], (int)$r['item_end']];
                $lg = self::csvList($r['item_langs']);
                $ct = self::csvList($r['item_cats'], true);
                $dev = (int)$r['item_dev'];
                $place = $r['item_place'];
                foreach (self::pages($r) as $page) {
                    if (!isset($pt[$page])) { $pt[$page] = ['rt' => [], 'e' => [], 'code' => [], 'v' => []]; }
                    $pt[$page]['rt'][] = $win;
                    if ($r['item_unit'] === 'U15') {
                        $pt[$page]['v'][] = ['id' => $id, 'lg' => $lg, 'ct' => $ct, 's' => $win[0], 'e' => $win[1]];
                        continue;
                    }
                    if ($place === 'P15h' || $place === 'P15b') {
                        $hold = $consent || self::visitorRule($r) || $win[0] || $win[1];
                        $html = $hold ? self::holdMarkup($r) : (((string)$r['item_code_m'] === '' && $dev === 3) ? (string)$r['item_code'] : self::parseMarkup($r['item_code'], $r['item_code_m'], $dev));
                        $pt[$page]['code'][] = ['id' => $id, 'p' => $place, 'lg' => $lg, 'ct' => $ct, 's' => $win[0], 'e' => $win[1], 'html' => $html, 'hold' => $hold ? 1 : 0];
                        continue;
                    }
                    if (!in_array($place, \app\common\model\Jcx::SLOTS, true)) { continue; }
                    $sz = [(int)$r['item_w'], (int)$r['item_h'], (int)$r['item_wm'], (int)$r['item_hm']];
                    $ent = ['id' => $id, 'p' => $place, 'u' => $r['item_unit'], 'dv' => $dev, 'lg' => $lg, 'ct' => $ct, 's' => $win[0], 'e' => $win[1], 'sz' => $sz];
                    // Server print only for the single item of a place: a rotating item's creative never enters the print set,
                    // so changing its picture or link does not re-key the page cache (T4 save 3).
                    if ($srv !== '' && !empty($single[$page])) { $ent['srv'] = $srv; }
                    if (in_array($r['item_kind'], ['code', 'html'], true) && (int)$r['item_code_mode'] === 1) {
                        if ($consent || self::visitorRule($r)) { $ent['hold'] = self::holdMarkup($r); }
                        else { $ent['pm'] = self::parseMarkup($r['item_code'], $r['item_code_m'], $dev); }
                    }
                    if ($page === 'play' && $place === 'P01' && !$pl['P01']['pp']) {
                        // P01 is off on phone watch pages by default (D40).
                        $ent['dv'] = $dev & 1;
                        if (!$ent['dv']) { continue; }
                    }
                    $pt[$page]['e'][] = $ent;
                }
            }
            foreach ($pt as $page => $d) { $pt[$page]['rt'] = self::mergeWindows($d['rt']); }
            $anchors = [];
            foreach (Db::name('jcx_anchor')->where('anc_status', 1)->select() as $a) {
                $anchors[$a['anc_theme']][$a['anc_place']] = ['at' => $a['anc_at'], 'pos' => $a['anc_pos'], 'nth' => (int)$a['anc_nth'] ?: 1, 'every' => (int)$a['anc_every'], 'max' => (int)$a['anc_max']];
            }
            $vip = self::csvList($conf['vip'], true);
            $cjson = ['v' => 1, 'crev' => (int)$conf['crev'] + 1, 'tz' => (int)round(((int)date('Z')) / 60),
                'set' => ['stats' => (int)$conf['stats'], 'consent' => $consent, 'member_free' => (int)$conf['member_free'], 'vip' => $vip, 'safe' => (int)$conf['safe'], 'geo' => (string)$conf['geo_header'] !== '' ? 1 : 0],
                'pl' => ['P05' => $pl['P05'], 'P01' => $pl['P01'], 'P08' => $pl['P08'], 'P09' => $pl['P09'], 'P13' => $pl['P13']], 'it' => $items];
            // Second assertion over the encoded output.
            foreach ($items as $it) { if (isset($exampleIds[$it['id']])) { throw new \RuntimeException('example reached the public config'); } }
            $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
            $json = json_encode($cjson, $flags);
            $cfgRel = self::cfgWritable() ? 'static/jcx/c.json' : 'upload/jcx/c.json';
            $files = [];
            $cfgs = [];
            $big = [];
            if (strlen($json) > self::C_SPLIT) {
                // Split per page type (5.1, R3r3-05): each file holds the items of that page type; it starts at 80 % of the
                // budget so the files keep headroom. A file still over the 30 KB budget is named in jcx.php 'cbig' (health
                // card: too many ads on that page). c.json itself then keeps only crev / settings (the admin health check
                // reads its crev; a page type without items gets no runtime), so the full list is never served.
                foreach (array_keys($pt) as $page) {
                    $sub = $cjson;
                    $sub['it'] = array_values(array_filter($items, function ($it) use ($page) {
                        $pg = $it['pg'] ?? (\app\common\model\Jcx::PLACE_PAGES[$it['p']] ?? []);
                        return in_array($page, $pg, true);
                    }));
                    $rel = preg_replace('~c\.json$~', 'c-' . $page . '.json', $cfgRel);
                    $sj = json_encode($sub, $flags);
                    if (strlen($sj) > self::C_LIMIT) { $big[] = $page; }
                    self::writeAtomic(ROOT_PATH . $rel, $sj);
                    $cfgs[$page] = $base . $rel;
                    $files[] = $rel;
                }
                $json = json_encode(['it' => [], 'split' => 1] + $cjson, $flags);
            }
            // c-<page>.json files of an earlier split that this build does not write any more (stale, public, unused) go
            foreach (glob(ROOT_PATH . preg_replace('~c\.json$~', 'c-*.json', $cfgRel)) ?: [] as $f) {
                if (preg_match('~/c-([a-z0-9]+)\.json$~', str_replace('\\', '/', $f), $m) && !isset($cfgs[$m[1]])) { @unlink($f); }
            }
            self::writeAtomic(ROOT_PATH . $cfgRel, $json);
            $files[] = $cfgRel;
            $print = ['v' => 1, 'on' => 1, 'consent' => $consent, 'safe' => (int)$conf['safe'], 'mf' => (int)$conf['member_free'], 'vip' => $vip,
                'cfg' => $base . $cfgRel, 'cfgs' => $cfgs, 'pl' => ['P05' => $pl['P05']], 'anchors' => $anchors, 'pt' => $pt, 'rw' => $rw];
            // visitor-language strings that the ON items' code asks for through window.JcxT (CONTRACT-3 E8): printed by the placer
            $tk = self::textKeys(array_merge(array_column($rows, 'item_code'), array_column($rows, 'item_code_m')));
            if ($tk) { $print['tk'] = $tk; }
            $live = ['print' => $print, 'ids' => $ids, 'built' => time()];
            self::writeAtomic(self::liveFile(), "<?php\nreturn " . var_export($live, true) . ";\n", true);
            $files[] = 'application/data/jcx/live.php';
            $hash = self::printHash($print);
            // edges: future start / end times; the page-cache key counts the ones passed, so a cached page never shows an
            // empty box for an item that has not started and never keeps a finished one (R1-07, JcxPlacer::cacheKeyPart).
            $set = ['crev' => (int)$conf['crev'] + 1, 'built' => time(), 'err' => '', 'cfg' => $cfgRel, 'edges' => $edges, 'cbig' => $big];
            if (PHP_SAPI !== 'cli') { try { $set['sch'] = Sitemap::requestIsHttps() ? 'https' : 'http'; } catch (\Throwable $e) {} }
            $rekey = $hash !== (string)$conf['phash'];
            if ($rekey) { $set['phash'] = $hash; $set['lrev'] = (int)$conf['lrev'] + 1; }
            JcxConf::set($set);
            return ['code' => 1, 'crev' => $set['crev'], 'lrev' => $rekey ? $set['lrev'] : (int)$conf['lrev'], 'rekey' => $rekey, 'bytes' => strlen($json), 'files' => $files, 'items' => count($items)];
        } catch (\Throwable $e) {
            try { JcxConf::set(['err' => substr($e->getMessage(), 0, 200), 'built' => time()]); } catch (\Throwable $x) {}
            return ['code' => 0, 'err' => $e->getMessage()];
        } finally {
            if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
        }
    }

    /**
     * The admin "Clear cache" for the ads (P2-04): a fresh build (c.json + live.php) and a forced lrev + 1, so every
     * cached page re-keys and prints the current slots and #jcx-v. Returns the run() result (lrev = the new value).
     * $rekey = false (the scheduled "cache" task, api entry): the build only; the page cache re-keys only when the
     * print set changed, as after any save (a cron job never drops the whole hot page cache).
     */
    public static function refreshAll($rekey = true)
    {
        $r = self::run();
        if (!empty($r['code']) && $rekey) {
            JcxConf::flush();
            $lrev = (int)(self::conf()['lrev'] ?? 1) + 1;
            JcxConf::set(['lrev' => $lrev]);
            $r['lrev'] = $lrev;
            $r['rekey'] = true;
        }
        return $r;
    }

    /** Union of [start, end] windows (0 = open). An open window swallows the rest. */
    public static function mergeWindows(array $w)
    {
        if (!$w) { return []; }
        foreach ($w as $x) { if (!$x[0] && !$x[1]) { return [[0, 0]]; } }
        $u = [];
        foreach ($w as $x) { $k = $x[0] . '-' . $x[1]; $u[$k] = $x; }
        ksort($u);
        return array_values($u);
    }

    /**
     * Atomic write: *.tmp + rename(); OPcache invalidated for PHP files. On Windows rename() over a file that another
     * process has open fails for a moment (R5-02): retry up to 20 x 25 ms. PHP files (live.php, jcx.php) never fall
     * back to copy(), which truncates first, so a reader can never include a half-written file: the build fails and
     * keeps the old files instead. Other files (c.json) may still use the copy fallback.
     */
    public static function writeAtomic($file, $content, $php = false)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { throw new \RuntimeException('cannot create ' . basename($dir)); }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) { @unlink($tmp); throw new \RuntimeException('cannot write ' . basename($file)); }
        $ok = false;
        for ($i = 0; $i < 20 && !($ok = @rename($tmp, $file)); $i++) { usleep(25000); clearstatcache(true, $file); }
        if (!$ok) {
            if ($php || !@copy($tmp, $file)) { @unlink($tmp); throw new \RuntimeException('cannot replace ' . basename($file)); }
            @unlink($tmp);
        }
        if ($php && function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
        return true;
    }

    /** Copy a folder (HTML5 creative files of a duplicated item, L1 / R1-09). Links are skipped. */
    public static function copyTree($from, $to)
    {
        $from = rtrim(str_replace('\\', '/', (string)$from), '/');
        $to = rtrim(str_replace('\\', '/', (string)$to), '/');
        if ($from === '' || $to === '' || !is_dir($from) || is_link($from)) { return false; }
        if (!is_dir($to) && !@mkdir($to, 0755, true)) { return false; }
        foreach (scandir($from) ?: [] as $f) {
            if ($f === '.' || $f === '..' || is_link($from . '/' . $f)) { continue; }
            if (is_dir($from . '/' . $f)) { if (!self::copyTree($from . '/' . $f, $to . '/' . $f)) { return false; } }
            elseif (!@copy($from . '/' . $f, $to . '/' . $f)) { return false; }
        }
        return true;
    }

    public static function rmTree($dir)
    {
        $dir = rtrim(str_replace('\\', '/', (string)$dir), '/');
        if ($dir === '' || !is_dir($dir) || is_link($dir)) { return; }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') { continue; }
            $p = $dir . '/' . $f;
            if (is_dir($p) && !is_link($p)) { self::rmTree($p); } else { @unlink($p); }
        }
        @rmdir($dir);
    }

    /* ------------------------------------------------------------------ root files (3.4) */

    public static function rootNameAllowed($name)
    {
        $n = strtolower((string)$name);
        if (!preg_match('/^[a-z0-9._-]{1,64}$/D', $n) || $n[0] === '.') { return false; }
        if (preg_match('/^index\./', $n) || in_array($n, ['robots.txt', '.htaccess', 'web.config', 'favicon.ico', 'manifest.json', 'crossdomain.xml', 'license', 'readme.md'], true)) { return false; }
        return true;
    }

    /** Write / remove root files from jcx_root. Returns [name => ['state'=>written|same|exists|notwritable|removed|kept|off]]. */
    public static function rootFiles()
    {
        $out = [];
        foreach (Db::name('jcx_root')->select() as $r) {
            $name = (string)$r['rf_name'];
            if (!self::rootNameAllowed($name)) { $out[$name] = ['state' => 'refused']; continue; }
            $f = ROOT_PATH . $name;
            $body = (string)$r['rf_body'];
            $sha = hash('sha256', $body);
            $cur = is_file($f) ? hash_file('sha256', $f) : '';
            if ((int)$r['rf_status']) {
                if ($cur !== '' && $cur !== (string)$r['rf_sha'] && $cur !== $sha) { $out[$name] = ['state' => 'exists']; continue; }
                // A file with the same content that the engine did not write (rf_sha empty) is not adopted, so switching
                // the row off later can never delete a foreign file (R2-F2).
                if ($cur !== '' && (string)$r['rf_sha'] === '') { $out[$name] = ['state' => $cur === $sha ? 'same' : 'exists']; continue; }
                if ($cur === $sha) { $out[$name] = ['state' => 'same']; continue; }
                if (!is_writable(ROOT_PATH) || ($cur !== '' && !is_writable($f))) { $out[$name] = ['state' => 'notwritable', 'body' => $body]; continue; }
                try { self::writeAtomic($f, $body); } catch (\Throwable $e) { $out[$name] = ['state' => 'notwritable', 'body' => $body]; continue; }
                Db::name('jcx_root')->where('rf_id', $r['rf_id'])->update(['rf_sha' => $sha]);
                $out[$name] = ['state' => 'written', 'url' => self::base() . $name];
            } else {
                if ($cur !== '' && (string)$r['rf_sha'] !== '' && hash_equals((string)$r['rf_sha'], $cur)) {
                    @unlink($f);
                    Db::name('jcx_root')->where('rf_id', $r['rf_id'])->update(['rf_sha' => '']);
                    $out[$name] = ['state' => 'removed'];
                } else {
                    $out[$name] = ['state' => $cur === '' ? 'off' : 'kept'];
                }
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ HTML5 zips (11.1) */

    /** Unpack an HTML5 zip into application/data/jcx/z/<id>/. Returns ['code'=>1,'files'=>n] or ['code'=>0,'msg'=>'admin/ux/jcx/err_zip']. */
    public static function unpackZip($zipFile, $itemId)
    {
        $bad = ['code' => 0, 'msg' => 'admin/ux/jcx/err_zip', 'text' => \app\common\model\Jcx::msg('err_zip')];
        $itemId = (int)$itemId;
        if ($itemId < 1 || !is_file($zipFile) || filesize($zipFile) > 1048576 || !class_exists('ZipArchive')) { return $bad; }
        $z = new \ZipArchive();
        if ($z->open($zipFile) !== true) { return $bad; }
        $n = $z->numFiles;
        if ($n < 1 || $n > 200) { $z->close(); return $bad; }
        $ok = ['html', 'css', 'js', 'json', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'woff2', 'mp4'];
        $list = [];
        $hasIndex = false;
        $total = 0;
        for ($i = 0; $i < $n; $i++) {
            $st = $z->statIndex($i);
            $name = str_replace('\\', '/', (string)$st['name']);
            if (substr($name, -1) === '/') { continue; }
            $attr = 0; $os = 0;
            if (method_exists($z, 'getExternalAttributesIndex') && $z->getExternalAttributesIndex($i, $os, $attr) && $os === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) { $z->close(); return $bad; }
            if ($name === '' || $name[0] === '/' || preg_match('~(^|/)\.\.(/|$)|^[A-Za-z]:|[\x00-\x1F]~', $name) || strlen($name) > 160
                || !preg_match('~^[A-Za-z0-9._/-]+$~', $name)) { $z->close(); return $bad; }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $ok, true)) { $z->close(); return $bad; }
            $total += (int)$st['size'];
            if ($total > 4194304) { $z->close(); return $bad; }
            if ($name === 'index.html') { $hasIndex = true; }
            $list[$i] = $name;
        }
        if (!$hasIndex) { $z->close(); return $bad; }
        $dir = self::zDir() . $itemId . '/';
        self::rmTree($dir);
        @mkdir($dir, 0755, true);
        foreach ($list as $i => $name) {
            $data = $z->getFromIndex($i);
            if ($data === false) { $z->close(); self::rmTree($dir); return $bad; }
            $dst = $dir . $name;
            if (!is_dir(dirname($dst))) { @mkdir(dirname($dst), 0755, true); }
            file_put_contents($dst, $data);
        }
        $z->close();
        return ['code' => 1, 'files' => count($list), 'dir' => 'application/data/jcx/z/' . $itemId . '/'];
    }

    /* ------------------------------------------------------------------ examples (9.1) */

    private static function siteHome()
    {
        $host = (string)self::site('site_url');
        $scheme = 'https://';
        try { $scheme = Sitemap::scheme(); } catch (\Throwable $e) {}
        $pref = (string)($GLOBALS['config']['rewrite']['canonical_scheme'] ?? 'auto');
        if (PHP_SAPI === 'cli' && !in_array($pref, ['http', 'https'], true)) {
            // CLI / cron has no request (L2): use the scheme of the last build that ran in a web request (jcx.php 'sch'),
            // else https unless the site URL itself says http://. Never http:// for an https site.
            $sch = (string)(self::conf()['sch'] ?? '');
            $scheme = $sch === 'http' ? 'http://' : ($sch === 'https' ? 'https://' : (preg_match('~^http://~i', $host) ? 'http://' : 'https://'));
        }
        if ($host === '') { $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost'); }
        $host = preg_replace('~^https?://~i', '', rtrim($host, '/'));
        return $scheme . $host . self::base();
    }

    private static function newestVod()
    {
        try {
            $v = Db::name('vod')->where('vod_status', 1)->order('vod_id desc')->field('vod_id,vod_name,vod_pic')->find();
            return $v ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /**
     * Upsert the shipped examples (application/data/jcx/examples.json, written by the examples builder 12.d) by
     * item_ex_code, all off, item_example = 1. Examples never reach live.php / c.json.
     * Manifest (schema 1): {"schema":1,"vast":{"file":"static/jcx/x/<8hex>.xml","fallback":"upload/jcx/<8hex>.xml","xml":"..."},
     *   "items":[{"x":"X01","name":"..","unit":"U01","kind":"image","place":"P03","dev":2,"pages":"",
     *   "size":{"w":300,"h":250,"wm":300,"hm":250},"media":{"src":"static/jcx/x/<8hex>.webp",...},
     *   "texts":{"url":"{home}","alt":"..","title":"..","text":"..","brand":"..","cta":".."},
     *   "code":{"code":"","code_m":"","mode":0},"opts":{},"note":".."}]}
     * Flat keys (w, h, url, alt, html, html_m, mode, ...) and item_* keys are accepted as well. An html5 item's
     * media.src .zip (or "zip") is unpacked to application/data/jcx/z/<id>/ and dropped from the media.
     * Tokens: {b} / {base} install dir, {abs} absolute base URL, {home} absolute home URL, {newest_vod_id} (int),
     * {vod_url}, {vod_pic}, {vod_name}. $only = X codes to install (e.g. the video examples of the Video ads tab).
     * Returns ['code'=>1,'installed'=>n,'examples'=>total,'errors'=>[x=>text],'build'=>run()].
     */
    public static function installExamples($manifest = null, array $only = [])
    {
        if (!self::ready()) { $i = self::install(); if (empty($i['code'])) { return $i; } }
        $file = $manifest ?: APP_PATH . 'data/jcx/examples.json';
        $data = is_array($file) ? $file : json_decode((string)@file_get_contents($file), true);
        $list = is_array($data) ? ($data['items'] ?? $data) : null;
        if (!is_array($list)) { return ['code' => 0, 'msg' => 'manifest']; }
        $home = self::siteHome();
        $abs = rtrim($home, '/') . '/';
        $vod = self::newestVod();
        $vodUrl = $home;
        if ($vod && function_exists('mac_url_vod_detail')) { try { $vodUrl = rtrim(preg_replace('~^(https?://[^/]+).*$~', '$1', $home), '/') . mac_url_vod_detail($vod); } catch (\Throwable $e) {} }
        $rep = ['{home}' => $home, '{abs}' => $abs, '{b}' => self::base(), '{base}' => self::base(), '{vod_url}' => $vodUrl,
            '{vod_pic}' => $vod ? (string)$vod['vod_pic'] : '', '{vod_name}' => (string)($vod['vod_name'] ?? ''), '{newest_vod_id}' => (string)(int)($vod['vod_id'] ?? 0)];
        // The VAST example tag is written with absolute URLs (vast-client drops relative tracking URLs, 7.4).
        $vastFrom = $vastTo = '';
        if (is_array($data) && !empty($data['vast']['file']) && isset($data['vast']['xml'])) {
            $dst = (string)$data['vast']['file'];
            $fb = (string)($data['vast']['fallback'] ?? '');
            if (preg_match('~^static/jcx/x/[0-9a-f]{8}\.xml$~D', $dst)) {
                $body = strtr((string)$data['vast']['xml'], $rep);
                try { self::writeAtomic(ROOT_PATH . $dst, $body); }
                catch (\Throwable $ex) {
                    if (preg_match('~^upload/jcx/[0-9a-f]{8}\.xml$~D', $fb)) { self::writeAtomic(ROOT_PATH . $fb, $body); $vastFrom = $abs . $dst; $vastTo = $abs . $fb; }
                }
            }
        }
        $sub = function ($v) use (&$sub, $rep, $vastFrom, $vastTo) {
            if (is_string($v)) { $v = strtr($v, $rep); return $vastFrom !== '' ? str_replace($vastFrom, $vastTo, $v) : $v; }
            if (is_array($v)) { foreach ($v as $k => $y) { $v[$k] = $sub($y); } }
            return $v;
        };
        $n = 0;
        $err = [];
        foreach ($list as $e) {
            if (!is_array($e)) { continue; }
            $x = (string)($e['x'] ?? ($e['item_ex_code'] ?? ''));
            if (!preg_match('/^X[0-9]{2}$/D', $x) || ($only && !in_array($x, $only, true))) { continue; }
            $e = $sub($e);
            foreach (['size', 'texts'] as $grp) { if (isset($e[$grp]) && is_array($e[$grp])) { $e += $e[$grp]; } }
            if (isset($e['code']) && is_array($e['code'])) {
                $e += ['html' => (string)($e['code']['code'] ?? ''), 'html_m' => (string)($e['code']['code_m'] ?? ''), 'mode' => (int)($e['code']['mode'] ?? 0)];
            }
            $zip = (string)($e['zip'] ?? '');
            if (isset($e['media']['src']) && preg_match('~\.zip$~i', (string)$e['media']['src'])) { $zip = (string)$e['media']['src']; unset($e['media']['src'], $e['media']['type']); }
            if (isset($e['opts']['target_id']) && !is_int($e['opts']['target_id'])) { $e['opts']['target_id'] = (int)$e['opts']['target_id']; }
            $in = ['item_ex_code' => $x];
            $map = ['unit' => 'item_unit', 'kind' => 'item_kind', 'place' => 'item_place', 'dev' => 'item_dev', 'w' => 'item_w', 'h' => 'item_h', 'wm' => 'item_wm', 'hm' => 'item_hm',
                'pages' => 'item_pages', 'media' => 'item_media', 'url' => 'item_url', 'alt' => 'item_alt', 'title' => 'item_title', 'text' => 'item_text', 'brand' => 'item_brand',
                'cta' => 'item_cta', 'html' => 'item_code', 'html_m' => 'item_code_m', 'mode' => 'item_code_mode', 'opts' => 'item_opts', 'name' => 'item_name', 'note' => 'item_note',
                'weight' => 'item_weight', 'delay' => 'item_delay', 'preset' => 'item_preset', 'network' => 'item_network'];
            foreach ($map as $k => $f) { if (array_key_exists($k, $e) && !is_array($e[$k]) || in_array($k, ['media', 'opts'], true) && isset($e[$k])) { $in[$f] = $e[$k]; } }
            foreach ($e as $k => $v) { if (strpos((string)$k, 'item_') === 0) { $in[$k] = $v; } }
            $c = \app\common\model\Jcx::clean($in, ['safe' => 0, 'can_code' => true, 'installer' => true]);
            if (!$c['code']) { $err[$x] = ($c['text'] ?? $c['msg']) . ' (' . ($c['field'] ?? '') . ')'; continue; }
            $row = $c['row'];
            $row['item_example'] = 1;
            $row['item_status'] = 0;
            $row['item_ex_code'] = $x;
            $row['item_edit'] = time();
            $old = Db::name('jcx_item')->where('item_example', 1)->where('item_ex_code', $x)->find();
            if ($old) { Db::name('jcx_item')->where('item_id', $old['item_id'])->update($row); $id = (int)$old['item_id']; }
            else { $row['item_add'] = time(); $id = (int)Db::name('jcx_item')->insertGetId($row); }
            if ($zip !== '') {
                $zr = ltrim($zip, '/');
                if (!preg_match('~^static/jcx/x/[A-Za-z0-9_-]+\.zip$~D', $zr)) { $err[$x] = 'zip path'; }
                else { $u = self::unpackZip(ROOT_PATH . $zr, $id); if (empty($u['code'])) { $err[$x] = 'zip'; } }
            }
            $n++;
        }
        // UD4: the old mid / post-roll examples (X46 / X47) left the manifest; an earlier install's rows go too.
        try { self::dropDeadRolls(); } catch (\Throwable $e) {}
        $cnt = (int)Db::name('jcx_item')->where('item_example', 1)->count();
        return ['code' => 1, 'installed' => $n, 'examples' => $cnt, 'errors' => $err, 'build' => self::run()];
    }

    public static function removeExamples()
    {
        if (!self::ready()) { return ['code' => 1, 'removed' => 0]; }
        $ids = [];
        foreach (Db::name('jcx_item')->where('item_example', 1)->field('item_id')->select() as $r) { $ids[] = (int)$r['item_id']; }
        if ($ids) { Db::name('jcx_item')->where('item_id', 'in', $ids)->delete(); foreach ($ids as $id) { self::rmTree(self::zDir() . $id); } }
        return ['code' => 1, 'removed' => count($ids), 'build' => self::run()];
    }

    /* ------------------------------------------------------------------ preview (4.7, 11.1) */

    private static function key()
    {
        $k = (string)self::conf()['k'];
        if (strlen($k) < 32) { throw new \RuntimeException('jcx secret missing'); }
        return $k;
    }

    /** Scope: all | ex | onoff | item-<id> | place-<code> (dots are the cookie separator). */
    public static function validScope($s)
    {
        return is_string($s) && preg_match('/^(?:all|ex|onoff|item-[1-9][0-9]{0,9}|place-P(?:0[1-9]|1[0-6]|03d|15h|15b))$/D', $s) === 1;
    }

    /** Cookie value jcxp = adminId.expiry.scope.HMAC-SHA256(k). */
    public static function previewToken($adminId, $scope = 'all', $ttl = self::PREVIEW_TTL)
    {
        $adminId = (int)$adminId;
        if ($adminId < 1 || !self::validScope($scope)) { return ''; }
        $exp = time() + max(60, min(self::PREVIEW_TTL, (int)$ttl));
        $body = $adminId . '.' . $exp . '.' . $scope;
        return $body . '.' . hash_hmac('sha256', 'jcxp|' . $body, self::key());
    }

    /** Returns ['admin'=>id,'exp'=>t,'scope'=>s] or null. */
    public static function previewCheck($value)
    {
        if (!is_string($value) || strlen($value) > 200 || !preg_match('/^([1-9][0-9]{0,9})\.([0-9]{10})\.([a-z0-9-]{1,20}|place-P[0-9a-z]{2,4})\.([0-9a-f]{64})$/D', $value, $m)) { return null; }
        if ((int)$m[2] < time() || !self::validScope($m[3])) { return null; }
        try { $k = self::key(); } catch (\Throwable $e) { return null; }
        $body = $m[1] . '.' . $m[2] . '.' . $m[3];
        if (!hash_equals(hash_hmac('sha256', 'jcxp|' . $body, $k), $m[4])) { return null; }
        return ['admin' => (int)$m[1], 'exp' => (int)$m[2], 'scope' => $m[3]];
    }

    public static function cookiePath()
    {
        return self::base();
    }

    private static function secure()
    {
        try { return Sitemap::requestIsHttps(); } catch (\Throwable $e) { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }
    }

    /** Sets jcxp (HttpOnly) and the script-visible hint jcxh=1. Returns the jcxp value. */
    public static function sendPreviewCookies($adminId, $scope = 'all')
    {
        $v = self::previewToken($adminId, $scope);
        if ($v === '' || headers_sent()) { return $v; }
        $exp = time() + self::PREVIEW_TTL;
        $o = ['expires' => $exp, 'path' => self::cookiePath(), 'secure' => self::secure(), 'httponly' => true, 'samesite' => 'Lax'];
        setcookie('jcxp', $v, $o);
        $o['httponly'] = false;
        setcookie('jcxh', '1', $o);
        return $v;
    }

    public static function clearPreviewCookies()
    {
        if (headers_sent()) { return; }
        $o = ['expires' => time() - 3600, 'path' => self::cookiePath(), 'secure' => self::secure(), 'httponly' => true, 'samesite' => 'Lax'];
        setcookie('jcxp', '', $o);
        $o['httponly'] = false;
        setcookie('jcxh', '', $o);
        // the admin language of the preview panel (A1: set by jcx/preview next to jcxp / jcxh)
        setcookie('jcxal', '', $o);
    }

    /** Picker / theme nonce bound to the admin id and 10 minutes: adminId.expiry.hmac16. */
    public static function nonce($adminId, $purpose = 'pick')
    {
        $exp = time() + self::NONCE_TTL;
        $body = (int)$adminId . '.' . $exp;
        return $body . '.' . substr(hash_hmac('sha256', 'jcxn|' . $purpose . '|' . $body, self::key()), 0, 32);
    }

    public static function nonceCheck($nonce, $purpose = 'pick', $adminId = null)
    {
        if (!is_string($nonce) || !preg_match('/^([1-9][0-9]{0,9})\.([0-9]{10})\.([0-9a-f]{32})$/D', $nonce, $m)) { return false; }
        if ((int)$m[2] < time() || ($adminId !== null && (int)$m[1] !== (int)$adminId)) { return false; }
        try { $k = self::key(); } catch (\Throwable $e) { return false; }
        return hash_equals(substr(hash_hmac('sha256', 'jcxn|' . $purpose . '|' . $m[1] . '.' . $m[2], $k), 0, 32), $m[3]);
    }

    /** Items for the preview endpoint: examples and off items included (never on public files). */
    public static function previewData($scope)
    {
        if (!self::ready() || !self::validScope($scope)) { return []; }
        $q = Db::name('jcx_item')->order('item_ex_code asc,item_id asc');
        if ($scope === 'ex') { $q->where('item_example', 1); }
        elseif ($scope === 'onoff') { $q->where('item_example', 0); }
        // drafts of the editor (item_ex_code 'DRF', always off) belong to one admin tab: only scope item-<id> shows one
        if ($scope === 'onoff' || $scope === 'all') { $q->where('item_ex_code', '<>', 'DRF'); }
        elseif (strpos($scope, 'item-') === 0) { $q->where('item_id', (int)substr($scope, 5)); }
        elseif (strpos($scope, 'place-') === 0) { $q->where('item_place', substr($scope, 6)); }
        $out = [];
        $base = self::base();
        foreach ($q->limit(300)->select() as $r) {
            // UD4: an old mid / post-roll item would play as a pre-roll in the preview gate: left out like on the site.
            if (self::deadRoll($r)) { continue; }
            $o = self::pub($r, false, $base);
            $o['x'] = (string)$r['item_ex_code'];
            $o['st'] = (int)$r['item_status'];
            $o['nm'] = (string)$r['item_name'];
            if ((int)$r['item_example']) { $o['ex'] = 1; }
            if ((int)$r['item_code_mode'] === 1) {
                // Preview cannot document.write after load: show parse-mode code in a box.
                $o['md'] = 2;
                if ((string)$r['item_code'] !== '') { $o['c'] = (string)$r['item_code']; }
                if ((string)$r['item_code_m'] !== '') { $o['cm'] = (string)$r['item_code_m']; }
            }
            $out[] = $o;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ admin helpers */

    /**
     * Sample front URLs for the preview launcher and the place picker (4.4, 4.7): home, list (category of the newest
     * video), detail, play, search. Built from the shipped routes (application/route.php) and rewrite.vod_id.
     */
    public static function sampleUrls()
    {
        $b = self::base();
        $out = ['home' => $b];
        try {
            $v = Db::name('vod')->where('vod_status', 1)->order('vod_id desc')->field('vod_id,vod_sub,type_id')->find();
            $t = $v ? self::vodTarget($v['vod_id']) : null;
            $rw = $GLOBALS['config']['rewrite'] ?? (array)config('maccms.rewrite');
            $pretty = (string)($rw['status'] ?? '0') === '1';
            $type = $v ? Db::name('type')->where('type_id', (int)$v['type_id'])->field('type_id,type_en')->find() : null;
            if ($t) {
                $out['detail'] = $t['url'];
                $out['play'] = $pretty ? $b . 'watch/' . $t['tg'] . '-sv1-ep-1/' : $b . 'index.php/vod/play/id/' . (int)$v['vod_id'] . '/sid/1/nid/1.html';
            }
            if ($type) {
                $tk = (string)($rw['type_id'] ?? '0') === '1' && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', (string)$type['type_en']) ? $type['type_en'] : (string)$type['type_id'];
                $out['list'] = $pretty ? $b . 'category/' . $tk . '/' : $b . 'index.php/vod/type/id/' . (int)$type['type_id'] . '.html';
            }
            $out['search'] = $pretty ? $b . 'search/wd/a/' : $b . 'index.php/vod/search/wd/a.html';
        } catch (\Throwable $e) {}
        return $out;
    }

    /** Server-side facts for the health card (4.10); the browser-side checks (home page, c.json crev) run in admin_jcx.js. */
    public static function health()
    {
        $c = self::conf();
        $out = ['ready' => self::ready(), 'on' => (int)$c['on'], 'crev' => (int)$c['crev'], 'lrev' => (int)$c['lrev'], 'built' => (int)$c['built'], 'err' => (string)$c['err'], 'cbig' => array_values((array)$c['cbig']),
            'cfg' => self::base() . $c['cfg'], 'cfg_fallback' => strpos((string)$c['cfg'], 'upload/') === 0, 'static_writable' => self::cfgWritable(),
            'subfolder' => self::base() !== '/', 'redirect_ok' => (int)$c['redirect_ok'], 'safe' => (int)$c['safe'], 'miss' => [], 'risk' => [], 'conflicts' => [], 'examples_public' => 0];
        if (!$out['ready']) { return $out; }
        $theme = (string)($GLOBALS['config']['site']['template_dir'] ?? self::site('template_dir'));
        $on = Db::name('jcx_item')->where('item_status', 1)->where('item_example', 0)->select();
        $places = [];
        foreach ($on as $r) { $places[$r['item_place']] = 1; }
        foreach (self::missLog() as $k => $v) {
            list($th, $pl, $pg) = array_pad(explode('|', $k), 3, '');
            if ($th === $theme && isset($places[$pl])) { $out['miss'][] = ['place' => $pl, 'page' => $pg, 'n' => $v[0], 'last' => $v[1]]; }
        }
        foreach ($on as $r) {
            if (in_array($r['item_unit'], \app\common\model\Jcx::RISK, true) || ($r['item_unit'] === 'U08' && ((int)$r['item_dev'] & 2))) { $out['risk'][] = [(int)$r['item_id'], $r['item_name']]; }
            $cf = \app\common\model\Jcx::conflicts($r);
            if ($cf) { $out['conflicts'][] = ['id' => (int)$r['item_id'], 'name' => $r['item_name'], 'with' => $cf]; }
        }
        $live = is_file(self::liveFile()) ? include self::liveFile() : [];
        $ex = Db::name('jcx_item')->where('item_example', 1)->column('item_id');
        $out['examples_public'] = count(array_intersect(array_map('intval', $ex), array_map('intval', (array)($live['ids'] ?? []))));
        $out['push_on'] = (bool)Db::name('jcx_item')->where('item_status', 1)->where('item_unit', 'U11')->count();
        $out['https'] = strpos(self::siteHome(), 'https://') === 0;
        return $out;
    }

    /* ------------------------------------------------------------------ miss log */

    public static function missLog()
    {
        $f = self::runtimeDir() . 'miss.json';
        $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
        return is_array($d) ? $d : [];
    }
}

/** application/extra/jcx.php reader / writer with defaults (3.1). Never printed. */
final class JcxConf
{
    const DEFAULTS = ['schema' => 1, 'on' => 0, 'lrev' => 1, 'phash' => '', 'crev' => 1, 'k' => '', 'safe' => 1, 'stats' => 1, 'geo_header' => '',
        'consent' => 0, 'member_free' => 0, 'vip' => '', 'cfg' => 'static/jcx/c.json', 'built' => 0, 'err' => '', 'chk' => [], 'redirect_ok' => 0,
        'edges' => [], 'sch' => '', 'cbig' => []];
    private static $memo = null;
    /** Tests only: another config file instead of application/extra/jcx.php. */
    public static $path = null;

    public static function file() { return self::$path ?: APP_PATH . 'extra/jcx.php'; }

    public static function flush() { self::$memo = null; }

    public static function get()
    {
        if (self::$memo !== null) { return self::$memo; }
        $d = [];
        if (is_file(self::file())) { $d = include self::file(); }
        return self::$memo = array_merge(self::DEFAULTS, is_array($d) ? array_intersect_key($d, self::DEFAULTS) : []);
    }

    /** Merge + atomic write (var_export, *.tmp, rename, opcache_invalidate). $create allows writing a new file. */
    public static function set(array $set, $create = false)
    {
        if (!is_file(self::file()) && !$create) { throw new \RuntimeException('jcx.php missing'); }
        self::$memo = null;
        $c = array_merge(self::get(), array_intersect_key($set, self::DEFAULTS));
        JcxBuild::writeAtomic(self::file(), "<?php\nreturn " . var_export($c, true) . ";\n", true);
        self::$memo = $c;
        return $c;
    }
}
