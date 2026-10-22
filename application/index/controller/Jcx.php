<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\index\controller;

use app\common\util\JcxBuild;
use app\common\util\JcxPlacer;

/**
 * Public ad endpoints (ADS-BRIEF 2.1, 4.7, 11.1). Like Robots.php it extends think\Controller: no session, no user,
 * no theme rendering. The constructor runs PublicRequestGuard::enforceRoute itself (route aliases cannot skip it):
 *   POST index.php/jcx/b      stats beacon "id.event.count,..." (bucket jcxb; 204, never a body)
 *   GET  index.php/jcx/pv     preview config behind the HMAC cookie jcxp (no-store); ?theme=&n= sets the theme preview
 *   GET  index.php/jcx/h5/<id>/[<ver>/]<file>   HTML5 creative files from application/data/jcx/z/ with a CSP sandbox header
 *        (<ver> = JcxBuild::h5Ver, 8 hex: such a path is cached for 30 days, immutable, while it is the current
 *        version; index.html, an unversioned path and a stale version: no-cache, P2-08 / RV-02 / P3-D)
 *   GET  index.php/jcx/geo    {"cc":"XX"} from the server header chosen in Settings (no IP lookup)
 * (UD4: the former admin demo page index.php/jcx/demo is gone: every video plays in the provider's iframe player.)
 */
class Jcx extends \think\Controller
{
    const TYPES = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'woff2' => 'font/woff2', 'mp4' => 'video/mp4'];
    const CSP = 'sandbox allow-scripts allow-popups allow-popups-to-escape-sandbox';
    const PV_STR = ['pv_panel' => 'Preview', 'pv_density' => 'Ad space on this screen', 'pv_cls' => 'Page movement', 'pv_reset_caps' => 'Reset my frequency caps',
        'pv_exit' => 'Exit preview', 'pv_paused' => 'Live ads are paused in this browser while you preview. Exit preview or Clear cache ends it.', 'pv_phone' => 'Phone view', 'pv_theme' => 'Theme', 'pv_empty_place' => '%s (empty)', 'pv_show' => 'Show',
        'pv_play_with' => 'Play with', 'pv_layers' => 'Pop-ups and corner boxes on this page', 'example_chip' => 'Example', 'put_here' => 'Put it here',
        'pv_phone_only' => 'Phones only: use Phone view', 'pv_pc_only' => 'Computers only', 'pv_u08_hint' => 'Now click any link on this page to see it.',
        'pv_rails_no' => 'Side rails need %1 px free on each side; this page leaves %2 px at this width.', 'pv_stacked' => 'all examples stacked: your real pages show far fewer',
        // repair round 2 (CONTRACT-2 B2; ads-admin adds them to the 4 admin packs)
        'pv_on' => 'On', 'pv_off' => 'Off', 'pv_code_hint' => 'Click anywhere on the page to see what this code does. Esc closes it.',
        'pv_ok' => 'OK', 'pv_too_much' => 'Too much: visitors may leave', 'pv_cls_ok' => 'OK', 'pv_cls_bad' => 'Too much movement', 'pv_real' => 'As visitors see it',
        'pv_page' => 'Page', 'pv_pg_home' => 'Home page', 'pv_pg_list' => 'Video list', 'pv_pg_detail' => 'Video page', 'pv_pg_play' => 'Watch page', 'pv_pg_search' => 'Search results',
        'pl_P01' => 'Top of page', 'pl_P02' => 'Home: big banner on the first screen', 'pl_P03' => 'Under the player', 'pl_P03d' => 'Video page: above related videos',
        'pl_P04' => 'Beside the player', 'pl_P05' => 'Inside video lists', 'pl_P06' => 'Home: between rows', 'pl_P07' => 'Above the footer', 'pl_P08' => 'Side rails (wide screens)',
        'pl_P09' => 'Sticky bar', 'pl_P10' => 'Pop-ups and corner boxes', 'pl_P11' => 'Video player', 'pl_P12' => 'Top of search results', 'pl_P13' => 'Inside articles',
        'pl_P14' => 'List sidebar', 'pl_P15h' => 'Code in the page head', 'pl_P15b' => 'Code at the end of the page', 'pl_P16' => 'Before comments'];

    public function __construct()
    {
        \app\common\util\PublicRequestGuard::enforceRoute(ROOT_PATH, 'jcx', strtolower((string)request()->action()));
        parent::__construct();
    }

    private function method($m)
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === $m;
    }

    private function nobody($code = 204)
    {
        return response('', $code, ['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function json($data, $code = 200, array $extra = [])
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
        return response($body === false ? '{}' : $body, $code, $extra + ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Stats beacon (11.1): POST, same-origin, ≤ 2 KB, ≤ 40 triples, known ids, whitelisted events, count ≤ 50. Fail-closed: always 204. */
    public function b()
    {
        if (!$this->method('POST')) { return $this->nobody(405); }
        $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if ($site !== null && $site !== 'same-origin') { return $this->nobody(); }
        $len = $_SERVER['CONTENT_LENGTH'] ?? '';
        if ($len !== '' && (!ctype_digit((string)$len) || (int)$len > 2048)) { return $this->nobody(); }
        $raw = (string)file_get_contents('php://input', false, null, 0, 2049);
        if ($raw === '' || strlen($raw) > 2048 || !preg_match('/^[0-9]{1,10}\.[0-9]{1,2}\.[0-9]{1,3}(?:,[0-9]{1,10}\.[0-9]{1,2}\.[0-9]{1,3}){0,39}$/D', trim($raw))) { return $this->nobody(); }
        $conf = JcxPlacer::conf();
        if (!$conf || empty($conf['stats']) || empty($conf['on'])) { return $this->nobody(); }
        $live = is_file(APP_PATH . 'data/jcx/live.php') ? include APP_PATH . 'data/jcx/live.php' : [];
        $ids = array_flip(array_map('intval', (array)($live['ids'] ?? [])));
        $ok = [];
        $sum = [];
        foreach (explode(',', trim($raw)) as $t) {
            list($id, $ev, $n) = array_map('intval', explode('.', $t));
            if (!isset($ids[$id]) || $ev < 1 || $ev > \app\common\model\Jcx::STAT_EVENTS || $n < 1 || $n > 50) { continue; }
            // R2-F4: a repeated id.event in one body is merged and capped at 50 (the runtime sends each pair once)
            $sum[$id . '.' . $ev] = min(50, ($sum[$id . '.' . $ev] ?? 0) + $n);
        }
        foreach ($sum as $k => $n) {
            list($id, $ev) = array_map('intval', explode('.', $k));
            // a click (3) counts only next to an impression (1) of the same ad in the same page view
            if ($ev === 3 && !isset($sum[$id . '.1'])) { continue; }
            $ok[] = [$id, $ev, $n];
        }
        if ($ok) { try { \app\common\model\Jcx::statWrite($ok); } catch (\Throwable $e) {} }
        return $this->nobody();
    }

    /** Admin preview data (4.7): examples and off items, only with a valid jcxp cookie (hash_equals). */
    public function pv()
    {
        if (!$this->method('GET')) { return $this->nobody(405); }
        $tok = JcxBuild::ready() ? JcxBuild::previewCheck($_COOKIE['jcxp'] ?? '') : null;
        if (isset($_GET['exit'])) {
            JcxBuild::clearPreviewCookies();
            if (!empty($_GET['th']) && !headers_sent()) { setcookie(\app\common\util\ThemePreview::COOKIE, '', ['expires' => time() - 3600, 'path' => JcxBuild::cookiePath(), 'samesite' => 'Lax']); }
            return $this->json(['v' => 1, 'exit' => 1]);
        }
        if (!$tok || !$this->adminActive($tok['admin'])) {
            JcxBuild::clearPreviewCookies();
            return $this->json(new \stdClass());
        }
        if (isset($_GET['theme'])) {
            // Theme picker of the preview panel (CF09): the nonce authorises only this.
            $theme = (string)$_GET['theme'];
            $back = (string)($_GET['r'] ?? '');
            $base = JcxBuild::base();
            if (!JcxBuild::nonceCheck((string)($_GET['n'] ?? ''), 'theme', $tok['admin']) || !\app\common\util\ThemePreview::validName($theme)
                || !is_file(ROOT_PATH . 'template/' . $theme . '/info.ini')) { return $this->json(new \stdClass(), 403); }
            if (!headers_sent()) {
                setcookie(\app\common\util\ThemePreview::COOKIE, \app\common\util\ThemePreview::sign($theme), ['expires' => $tok['exp'], 'path' => JcxBuild::cookiePath(), 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
            }
            $to = (strpos($back, $base) === 0 && !preg_match('~[\x00-\x20\\\\]|^//|://~', $back)) ? $back : $base;
            return response('', 302, ['Location' => $to, 'Cache-Control' => 'no-store']);
        }
        $scope = isset($_GET['sc']) && JcxBuild::validScope((string)$_GET['sc']) ? (string)$_GET['sc'] : $tok['scope'];
        $theme = JcxPlacer::themeSlug((string)($_GET['th'] ?? ''));
        $page = in_array((string)($_GET['t'] ?? ''), \app\common\model\Jcx::PAGES, true) ? (string)$_GET['t'] : 'home';
        $live = is_file(APP_PATH . 'data/jcx/live.php') ? include APP_PATH . 'data/jcx/live.php' : [];
        $print = (array)($live['print'] ?? []);
        $map = JcxPlacer::map($theme, $page, (array)($print['anchors'][$theme] ?? []), (array)($print['pl']['P05'] ?? []));
        $anchors = [];
        foreach ($map as $code => $a) {
            // only the places of this page type (2.4); P04 without a sidebar shows under the player
            if (!in_array($code, JcxPlacer::PAGE_SLOTS[$page] ?? [], true)) { continue; }
            $anchors[$code] = array_intersect_key($a, array_flip(['at', 'pos', 'nth', 'every', 'max', 'hd', 'hm', 'fallback']));
        }
        $conf = JcxBuild::conf();
        $items = $this->exNames(JcxBuild::previewData($scope));
        $codes = [];
        foreach ($items as $o) { $codes[] = (string)($o['c'] ?? ''); $codes[] = (string)($o['cm'] ?? ''); }
        $out = ['v' => 1, 'scope' => $scope, 'admin' => $tok['admin'], 'exp' => $tok['exp'], 'safe' => (int)$conf['safe'],
            'nonce' => JcxBuild::nonce($tok['admin'], 'pick'), 'tnonce' => JcxBuild::nonce($tok['admin'], 'theme'),
            'anchors' => $anchors, 'ui' => JcxPlacer::ui($theme), 'themes' => $this->themes(),
            's' => $this->strings(), 'fs' => JcxPlacer::strings(JcxPlacer::STR), 'vs' => JcxPlacer::strings(JcxPlacer::VSTR), 'it' => $items, 'pages' => JcxBuild::sampleUrls(),
            // window.JcxT strings for the previewed code (it runs in a sealed frame: jcx-p.js defines JcxT there), CONTRACT-3 E8
            'tk' => (object)JcxPlacer::textStrings(JcxBuild::textKeys($codes))];
        return $this->json($out);
    }

    /** Example names in the admin's language: admin key exn_<Xnn> when the pack has it (CONTRACT-2 B5), else the row name. */
    private function exNames(array $it)
    {
        $keys = [];
        foreach ($it as $o) { if (!empty($o['ex']) && preg_match('/^X[0-9]{2}$/D', (string)($o['x'] ?? ''))) { $keys['exn_' . $o['x']] = (string)$o['nm']; } }
        if (!$keys) { return $it; }
        $tr = $this->strings($keys);
        foreach ($it as $i => $o) { if (!empty($o['ex']) && isset($tr['exn_' . ($o['x'] ?? '')])) { $it[$i]['nm'] = $tr['exn_' . $o['x']]; } }
        return $it;
    }

    private function themes()
    {
        $out = [];
        foreach (glob(ROOT_PATH . 'template/*/info.ini') ?: [] as $f) {
            $slug = basename(dirname($f));
            if (\app\common\util\ThemePreview::validName($slug) && is_file(ROOT_PATH . 'template/' . $slug . '/html/index/index.html')) { $out[] = $slug; }
        }
        return $out;
    }

    /** One indexed query: the preview token's admin must still exist and be enabled (R2-F3). */
    private function adminActive($id)
    {
        try { return (bool)\think\Db::name('admin')->where('admin_id', (int)$id)->where('admin_status', 1)->count(); } catch (\Throwable $e) { return false; }
    }

    /** Admin language of the preview: ?al=, else the jcxal cookie that jcx/preview sets (A1, R4-11, R6-04); en-us. */
    private function adminLang()
    {
        foreach ([(string)($_GET['al'] ?? ''), (string)($_COOKIE['jcxal'] ?? '')] as $l) {
            if (preg_match('/^[a-z]{2}-[a-z]{2}$/D', $l) && is_dir(APP_PATH . 'admin/lang/' . $l)) { return $l; }
        }
        return 'en-us';
    }

    /**
     * Preview panel strings = admin keys (12.b owns them); English fallbacks until the lang parts exist.
     * Keys are compared lower-case (R4-21, R6-11): a pack regenerated with lower-case keys still matches pl_P01.
     */
    private function strings(?array $keys = null)
    {
        $s = $keys ?? self::PV_STR;
        $lang = $this->adminLang();
        foreach (glob(APP_PATH . 'admin/lang/' . $lang . '/*-jcx*.php') ?: [] as $f) {
            $arr = include $f;
            if (!is_array($arr)) { continue; }
            $low = array_change_key_case($arr, CASE_LOWER);
            foreach (array_keys($s) as $k) {
                $full = strtolower('admin/ux/jcx/' . $k);
                if (isset($low[$full]) && is_string($low[$full])) { $s[$k] = $low[$full]; }
            }
        }
        return $s;
    }

    /** HTML5 creative files (11.1, D21, CF21): <id>/[<ver>/]<file> from the raw path info, fixed content type, CSP sandbox. */
    public function h5()
    {
        if (!$this->method('GET') && !$this->method('HEAD')) { return $this->nobody(405); }
        $path = str_replace('\\', '/', (string)request()->pathinfo());
        if (!preg_match('~(?:^|/)jcx/h5/([1-9][0-9]{0,9})/(?:([0-9a-f]{8})/)?([A-Za-z0-9._/-]{1,160})$~D', $path, $m) || strpos($m[3], '..') !== false || strpos($m[3], '//') !== false) {
            return $this->nobody(404);
        }
        $ext = strtolower(pathinfo($m[3], PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) { return $this->nobody(404); }
        $dir = realpath(APP_PATH . 'data/jcx/z/' . $m[1]);
        $file = $dir ? realpath($dir . '/' . $m[3]) : false;
        if (!$dir || !$file || !is_file($file) || strpos(str_replace('\\', '/', $file), str_replace('\\', '/', $dir) . '/') !== 0) { return $this->nobody(404); }
        // P2-08: one Cache-Control. A versioned file never changes (a new zip gets a new version): 30 days, immutable,
        // but only when the version in the URL is the folder's current one (RV-02: an old or made-up version was cached
        // for 30 days with whatever the folder held then). index.html (the frame document, its URL sits in c.json), an
        // unversioned path from an older page and a stale version: no-cache. P3-D: an Expires header goes with it, so
        // the server's mod_expires adds no second max-age (it only acts on a response without Expires).
        $long = $m[2] !== '' && $ext !== 'html' && hash_equals(JcxBuild::h5Ver($m[1]), $m[2]);
        $h = ['Content-Type' => self::TYPES[$ext], 'Content-Security-Policy' => self::CSP, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $long ? 'public, max-age=2592000, immutable' : 'no-cache', 'Expires' => gmdate('D, d M Y H:i:s', time() + ($long ? 2592000 : 0)) . ' GMT',
            'Referrer-Policy' => 'no-referrer'];
        // CORP same-origin on the document only: the creative runs in an opaque origin (sandbox without allow-same-origin),
        // so its own app.js / app.css requests come from origin "null" and CORP would block them (tested in A10).
        if ($ext === 'html') { $h['Cross-Origin-Resource-Policy'] = 'same-origin'; }
        return response((string)file_get_contents($file), 200, $h);
    }

    /** Country from the configured server header only (Cloudflare and similar); no IP lookup, no geo database. */
    public function geo()
    {
        if (!$this->method('GET')) { return $this->nobody(405); }
        $c = JcxPlacer::conf();
        $h = (string)($c['geo_header'] ?? '');
        $cc = '';
        // 1.2.0 (se-R13f): the Cloudflare country header counts only when the peer is a Cloudflare edge or a trusted proxy.
        if (in_array($h, ['HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE'], true)
            && ($h !== 'HTTP_CF_IPCOUNTRY' || \app\common\util\PublicRequestGuard::trustedPeer(ROOT_PATH, $_SERVER))) {
            $v = strtoupper(trim((string)($_SERVER[$h] ?? '')));
            if (preg_match('/^[A-Z]{2}$/D', $v)) { $cc = $v; }
        }
        return $this->json(['cc' => $cc], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function _empty()
    {
        return $this->nobody(404);
    }
}
