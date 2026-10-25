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
namespace app\admin\controller;

use think\Db;
use app\common\model\Jcx as JcxModel;
use app\common\util\JcxBuild;

/**
 * Ads manager (ADS-BRIEF 4, 12.b): Overview, My ads, Add / edit (3 steps), Places, Video ads, Network & files,
 * Stats, Settings. Data goes only through the engine API (app\common\model\Jcx, app\common\util\JcxBuild):
 * this controller never writes the jcx tables, c.json or live.php itself. One exception (R5-01): the step-3 preview
 * drafts. They are off rows marked item_ex_code = 'DRF', checked by JcxModel::clean() and written here WITHOUT a build
 * (an off row never prints), so opening a live ad in the editor never changes what visitors see; they are deleted on
 * save / Cancel / tab close and swept after a day.
 *
 * Security (11.1): every write is a POST with the session CSRF token (__token__, compared with hash_equals);
 * code, html, html5, P15 items and root files also need the right jcx/code (check_auth, admin id 1 always);
 * uploads go through Jcx::upload() (extension + finfo whitelist, size limits, fixed folder).
 * Values from the request are plain text (default_filter is empty); the engine stores plain text and every
 * view escapes once with htmlspecialchars.
 */
class Jcx extends Base
{
    /** Actions without an own permission row share one (4.1 has 17 rows): bulk = status (delete = del), reset / removeall = settings. */
    const PERM_ALIAS = ['bulk' => 'status', 'reset' => 'settings', 'removeall' => 'settings'];
    /** item_ex_code of a preview draft row (R5-01). */
    const DRAFT = 'DRF';
    /** Industry names per card ("Also called", R6-06): cards with an *_aka key. */
    const AKA = ['image', 'code', 'preroll', 'feed', 'sticky', 'pop', 'push', 'link', 'expand', 'corner', 'full', 'overlay', 'rails', 'masthead', 'widget', 'html5', 'outstream', 'sponsored', 'webpush'];

    /** Step 1 cards: id => unit, kind, icon, main (one of the 8 big cards), risk (red Chrome chip). */
    const CARDS = [
        'image' => ['U01', 'image', 'ai-image', 1, 0], 'code' => ['U01', 'code', 'ai-braces', 1, 0], 'preroll' => ['U15', 'video', 'ai-monitor-play', 1, 0],
        'feed' => ['U12', 'native', 'ai-layout-dashboard', 1, 0], 'sticky' => ['U03', 'image', 'ai-panel-left-close', 1, 0], 'pop' => ['U10', 'code', 'ai-external-link', 1, 1],
        'push' => ['U07', 'native', 'ai-bell', 1, 0], 'link' => ['U19', 'link', 'ai-link', 1, 0],
        'expand' => ['U05', 'image', 'ai-chevrons-right', 0, 0], 'corner' => ['U06', 'image', 'ai-message-square-text', 0, 0], 'full' => ['U08', 'image', 'ai-app-window', 0, 0],
        'overlay' => ['U09', 'image', 'ai-layers', 0, 1], 'rails' => ['U04', 'image', 'ai-files', 0, 0], 'masthead' => ['U18', 'image', 'ai-heading', 0, 0],
        'widget' => ['U13', 'native', 'ai-newspaper', 0, 0], 'html5' => ['U17', 'html5', 'ai-file-code', 0, 0], 'outstream' => ['U16', 'video', 'ai-film', 0, 0],
        'sponsored' => ['U14', 'text', 'ai-star', 0, 0], 'webpush' => ['U11', 'code', 'ai-bell', 0, 0],
    ];
    /** Network drop-down order (judge N3) and the adult line of each network (4.5). */
    const NETS = ['trafficstars' => ['TrafficStars', 'yes'], 'popcash' => ['PopCash', 'yes'], 'ezmob' => ['EZmob', 'yes'], 'exoclick' => ['ExoClick', 'yes'],
        'juicyads' => ['JuicyAds', 'yes'], 'trafficjunky' => ['TrafficJunky', 'yes'], 'hilltopads' => ['HilltopAds', 'yes'], 'clickadu' => ['Clickadu', 'yes'],
        'adsterra' => ['Adsterra', 'maybe'], 'richads' => ['RichAds', 'unclear'], 'monetag' => ['Monetag / PropellerAds', 'no'], 'other' => ['', '']];
    const NOT_WEB = ['app', 'tv', 'ar', 'search', 'offerwall', 'audio', 'dooh', 'email', 'lead', 'midpost'];
    const GEO_HEADERS = ['HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE'];
    /** Item fields the editor may send (everything else is ignored). */
    const FIELDS = ['item_name', 'item_unit', 'item_kind', 'item_place', 'item_preset', 'item_network', 'item_weight', 'item_dev', 'item_pages', 'item_cats',
        'item_langs', 'item_geo', 'item_who', 'item_start', 'item_end', 'item_days', 'item_hours', 'item_cap_n', 'item_cap_h', 'item_cap_gap', 'item_skip_first',
        'item_delay', 'item_w', 'item_h', 'item_wm', 'item_hm', 'item_media', 'item_url', 'item_alt', 'item_title', 'item_text', 'item_brand', 'item_cta',
        'item_code', 'item_code_m', 'item_code_mode', 'item_opts', 'item_risk_ok', 'item_note'];
    /** Language keys the page scripts need (prefix match under admin/ux/jcx/). */
    const JS_KEYS = ['err_', 'conflict', 'chrome_risk', 'type_on', 'saved_', 'draft_saved', 'master_auto_on', 'picked', 'pick_', 'density', 'phones', 'computers',
        'both', 'summary', 'sum_', 'often_', 'pl_', 'pt_', 'u_', 'v_test_', 'v_reward_default', 'uploading', 'uploaded', 'root_', 'h_', 'preview_', 'step', 'next',
        'save_', 'choose_', 'switched_', 'deleted', 'delete_confirm', 'bulk_delete_confirm', 'size_detected', 'code_looks_', 'code_docwrite', 'card_n', 'too_wide',
        'no_sidebar', 'place_saved', 'anchor_saved', 'examples_', 'reset_done', 'removed_all', 'set_saved', 'set_safe_off', 'remove_all_confirm', 'reset_stats_confirm',
        'duplicated', 'sitecode_moved', 'old_imported', 'v_card_saved', 'pages_all', 'picker_failed', 'place_fixed', 'remove_file', 'chk_done', 'empty_space_warn',
        'u08_', 'u09_', 'button_label', 'link', 'share', 'picker_', 'bulk_', 'remove_', 'ph_', 'brand', 'install_', 'title_f', 'net_adult_', 'net_monetag_no', 'see_on_site', 'empty',
        'draft_', 'show_more', 'places_pk'];

    private $jcxReady = false;

    public function __construct()
    {
        parent::__construct();
        // First open of the manager installs the module (3.5): tables, place seed, extra/jcx.php, c.json.
        if (!JcxBuild::ready()) {
            $res = JcxModel::install();
            if (empty($res['code'])) {
                $this->assign('jcx_install_error', (string)($res['msg'] ?? ''));
            }
        }
        $this->jcxReady = JcxBuild::ready();
    }

    protected function check_auth($c, $a)
    {
        $c = strtolower((string)$c);
        $a = strtolower((string)$a);
        if ($c === 'jcx' && isset(self::PERM_ALIAS[$a])) {
            $a = ($a === 'bulk' && (string)input('post.op') === 'delete') ? 'del' : self::PERM_ALIAS[$a];
        }
        return parent::check_auth($c, $a);
    }

    /* ================================================================== helpers */

    private function canCode()
    {
        return (bool)$this->check_auth('jcx', 'code');
    }

    private function adminId()
    {
        return (int)($this->_admin['admin_id'] ?? 0);
    }

    private static function h($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function t($key, $arg = null)
    {
        $full = 'admin/ux/jcx/' . $key;
        $s = lang($full);
        if (!is_string($s) || $s === $full || $s === strtolower($full)) { $s = JcxModel::ERR[$key] ?? $key; }
        // Only %s is a placeholder: texts such as "about %s % of page views" contain a literal "%" that sprintf would misread.
        return $arg === null ? $s : str_replace('%s', (string)$arg, $s);
    }

    /** POST + session CSRF token (the per-session admin token; see the report for why not the one-time __token__). */
    private function tokenOk()
    {
        if (!$this->request->isPost()) { return false; }
        $sent = (string)input('post.__token__', '', null);
        return $sent !== '' && hash_equals((string)mac_admin_csrf_token(), $sent);
    }

    private function answer(array $res, array $extra = [])
    {
        $code = !empty($res['code']) ? 1 : 0;
        $out = ['code' => $code, 'msg' => (string)($res['text'] ?? ($code ? '' : self::t('err_token'))), 'key' => (string)($res['msg'] ?? ''),
            'field' => (string)($res['field'] ?? ''), '__token__' => mac_admin_csrf_token()];
        foreach (['warn', 'need_confirm', 'need_risk', 'conflicts', 'id', 'auto_on', 'download', 'file'] as $k) { if (isset($res[$k])) { $out[$k] = $res[$k]; } }
        // R5r2-01: the row is saved, but the public files could not be rebuilt: never say "Visitors see it now".
        if ($code && isset($res['build']) && is_array($res['build']) && empty($res['build']['code'])) {
            $out['msg'] = self::t('build_failed');
            $out['build_failed'] = 1;
        }
        return json($out + $extra);
    }

    private function refuse($key, $field = '', $arg = null)
    {
        return $this->answer(['code' => 0, 'msg' => 'admin/ux/jcx/' . $key, 'text' => self::t($key, $arg), 'field' => $field]);
    }

    private function badToken()
    {
        return $this->refuse('err_token', '__token__');
    }

    /** Raw value of a POST field (plain text; arrays stay arrays). */
    private function post($k, $def = '')
    {
        // Raw request value: the admin input filter is empty (plain text), and ThinkPHP's "/a" modifier would cast strings to arrays.
        return isset($_POST[$k]) ? $_POST[$k] : $def;
    }

    private function langKeys()
    {
        $out = [];
        $file = APP_PATH . 'admin/lang/en-us/40-jcx.php';
        $all = is_file($file) ? (array)include $file : [];
        foreach (array_keys($all) as $full) {
            if (strpos($full, 'admin/ux/jcx/') !== 0) { continue; }
            $k = substr($full, 13);
            foreach (self::JS_KEYS as $p) {
                if (strpos($k, $p) === 0) { $out[$k] = self::t($k); break; }
            }
        }
        return $out;
    }

    private function themes()
    {
        $out = [];
        foreach (glob(ROOT_PATH . 'template/*/info.ini') ?: [] as $f) {
            $slug = basename(dirname($f));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $slug) && is_file(ROOT_PATH . 'template/' . $slug . '/html/index/index.html')) { $out[] = $slug; }
        }
        sort($out);
        return $out;
    }

    private function activeTheme()
    {
        $t = (string)($GLOBALS['config']['site']['template_dir'] ?? '');
        return $t !== '' ? $t : (string)JcxBuild::site('template_dir');
    }

    private function adminLang()
    {
        $l = function_exists('cookie') ? (string)cookie('javcms_admin_lang') : '';
        if (!preg_match('/^[a-z]{2}-[a-z]{2}$/', $l)) { $l = (string)config('default_lang'); }
        return preg_match('/^[a-z]{2}-[a-z]{2}$/', $l) ? $l : 'en-us';
    }

    private function can()
    {
        $out = [];
        foreach (['index', 'lists', 'info', 'save', 'status', 'del', 'upload', 'preview', 'places', 'video', 'network', 'stats', 'settings', 'examples', 'root', 'anchor', 'code'] as $a) {
            $out[$a] = (bool)$this->check_auth('jcx', $a);
        }
        return $out;
    }

    /** Assigns what every page needs and renders the view. */
    private function page($view, $tab, array $vars = [])
    {
        $conf = $this->jcxReady ? JcxBuild::publicConf() : [];
        $cfg = [
            'tab' => $tab, 'urls' => [], 'lang' => $this->langKeys(), 'token' => mac_admin_csrf_token(), 'base' => JcxBuild::base(),
            'al' => $this->adminLang(), 'samples' => JcxBuild::sampleUrls(), 'theme' => $this->activeTheme(), 'themes' => $this->themes(),
            'on' => (int)($conf['on'] ?? 0), 'safe' => (int)($conf['safe'] ?? 1), 'can' => $this->can(),
            'pl' => [], 'pt' => [],
        ];
        foreach (['index', 'lists', 'info', 'save', 'status', 'del', 'bulk', 'upload', 'preview', 'places', 'anchor', 'video', 'network', 'root', 'stats', 'settings', 'examples', 'reset', 'removeall'] as $a) {
            $cfg['urls'][$a] = url('jcx/' . $a);
        }
        foreach (JcxModel::PLACES as $p) { $cfg['pl'][$p] = self::t('pl_' . $p); }
        foreach (['home', 'list', 'detail', 'play', 'search', 'art', 'other', '404'] as $p) { $cfg['pt'][$p] = self::t('pt_' . $p); }
        $tabs = [];
        foreach (['index' => 'tab_overview', 'lists' => 'tab_list', 'places' => 'tab_places', 'video' => 'tab_video', 'network' => 'tab_network', 'stats' => 'tab_stats', 'settings' => 'tab_settings'] as $a => $k) {
            if ($cfg['can'][$a]) { $tabs[] = ['a' => $a, 'url' => url('jcx/' . $a), 'label' => self::t($k), 'cur' => $a === $tab]; }
        }
        $this->assign('jx_tabs', $tabs);
        $this->assign('jx_cfg', array_merge($cfg, $vars['js'] ?? []));
        unset($vars['js']);
        $this->assign('jx_conf', $conf);
        $this->assign('jx_ready', $this->jcxReady ? 1 : 0);
        $this->assign('title', self::t('title'));
        foreach ($vars as $k => $v) { $this->assign($k, $v); }
        return $this->fetch('admin@jcx/' . $view);
    }

    private function examplesCount()
    {
        return $this->jcxReady ? (int)Db::name('jcx_item')->where('item_example', 1)->count() : 0;
    }

    /** Thumbnail path (root-relative) for an item, or ''. */
    private static function thumb(array $r)
    {
        $m = json_decode((string)$r['item_media'], true) ?: [];
        foreach (['src', 'srcm', 'still', 'poster'] as $k) {
            if (!empty($m[$k]) && is_string($m[$k]) && preg_match('~\.(?:jpe?g|png|gif|webp)$~i', $m[$k]) && !preg_match('~^https?://~i', $m[$k])) { return JcxBuild::base() . ltrim($m[$k], '/'); }
        }
        if (!empty($m['cards'][0]['img'])) { return JcxBuild::base() . ltrim((string)$m['cards'][0]['img'], '/'); }
        return '';
    }

    private static function devText($d)
    {
        $d = (int)$d;
        return $d === 1 ? self::t('computers') : ($d === 2 ? self::t('phones') : self::t('both'));
    }

    private static function schedText(array $r)
    {
        if ((int)$r['item_end'] > 0) { return self::t('until', date('Y-m-d', (int)$r['item_end'])); }
        if ((int)$r['item_start'] > time()) { return self::t('from_date', date('Y-m-d', (int)$r['item_start'])); }
        return self::t('always');
    }

    private static function isRisk(array $r)
    {
        return in_array($r['item_unit'], JcxModel::RISK, true) || ($r['item_unit'] === 'U08' && ((int)$r['item_dev'] & 2));
    }

    /** Card id of the editor for a stored item. */
    private static function cardOf(array $r)
    {
        $map = ['U02' => 'image', 'U03' => 'sticky', 'U04' => 'rails', 'U05' => 'expand', 'U06' => 'corner', 'U07' => 'push', 'U08' => 'full', 'U09' => 'overlay',
            'U10' => 'pop', 'U11' => 'webpush', 'U12' => 'feed', 'U13' => 'widget', 'U14' => 'sponsored', 'U15' => 'preroll', 'U16' => 'outstream', 'U17' => 'html5',
            'U18' => 'masthead', 'U19' => 'link'];
        if ($r['item_unit'] === 'U01') { return in_array($r['item_kind'], ['code', 'html'], true) ? 'code' : 'image'; }
        if (in_array($r['item_unit'], ['U12', 'U13', 'U16'], true) && $r['item_kind'] === 'code') { return 'code'; }
        return $map[$r['item_unit']] ?? 'image';
    }

    /**
     * Default ad name in the admin's language, without internal codes (R4-12): "Banner 300×250 · Under the player",
     * "Code from an ad network · Code in the page head", "Before the video". $r needs item_unit, item_kind, item_place,
     * item_w, item_h (item_opts for video ads).
     */
    private static function niceName(array $r)
    {
        $unit = (string)($r['item_unit'] ?? 'U01');
        $kind = (string)($r['item_kind'] ?? '');
        if ($unit === 'U15') {
            $o = is_array($r['item_opts'] ?? null) ? $r['item_opts'] : (json_decode((string)($r['item_opts'] ?? ''), true) ?: []);
            return self::t((string)($o['roll'] ?? 'pre') === 'reward' ? 'v_reward' : 'v_before');
        }
        $n = in_array($kind, ['code', 'html'], true) && in_array($unit, ['U01', 'U12', 'U13', 'U16'], true) ? self::t('card_code') : self::t('u_' . $unit);
        $w = (int)($r['item_w'] ?? 0);
        $h = (int)($r['item_h'] ?? 0);
        if ($w > 0 && $h > 0) { $n .= ' ' . $w . "\u{00D7}" . $h; }
        $pl = (string)($r['item_place'] ?? '');
        if ($pl !== '' && !in_array($pl, ['P08', 'P09', 'P10', 'P11'], true) && in_array($pl, JcxModel::PLACES, true)) { $n .= " \u{00B7} " . self::t('pl_' . $pl); }
        return mb_substr($n, 0, 80, 'UTF-8');
    }

    /**
     * UD4 (owner, 2026-10-14): every video plays in the provider's iframe player, so a video ad set to play during or
     * after the video (U15 roll mid / post, saved before UD4) can never show. The engine keeps it out of the site and the
     * preview and refuses to switch it on; the admin lists it with a note so the owner can delete it.
     */
    private static function rollGone(array $r)
    {
        if ((string)($r['item_unit'] ?? '') !== 'U15') { return false; }
        $o = is_array($r['item_opts'] ?? null) ? $r['item_opts'] : (json_decode((string)($r['item_opts'] ?? ''), true) ?: []);
        return in_array((string)($o['roll'] ?? ''), ['mid', 'post'], true);
    }

    /**
     * UD4: a switch-on whose selection holds an old roll mid / post item is refused as a whole BEFORE anything is
     * written (JcxModel::setStatus() writes row by row and would leave the rows before it On without a rebuild).
     * Returns the refusal, or null when the switch-on may go ahead. Example rows are skipped like setStatus() does.
     */
    private function rollGoneRefusal(array $ids)
    {
        if (!$ids || !$this->jcxReady) { return null; }
        foreach (Db::name('jcx_item')->where('item_id', 'in', array_values($ids))->where('item_example', 0)->field('item_id,item_unit,item_opts')->select() as $r) {
            if (self::rollGone($r)) {
                return $this->answer(['code' => 0, 'msg' => 'admin/ux/jcx/err_roll', 'text' => self::t('err_roll'), 'field' => 'opts.roll'], ['item_id' => (int)$r['item_id']]);
            }
        }
        return null;
    }

    /** R2r2-N2 (admin half): a preview draft row is never switched on from My ads / bulk; it would serve but be listed nowhere. */
    private function noDrafts(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !$this->jcxReady) { return $ids; }
        $drf = array_map('intval', Db::name('jcx_item')->where('item_id', 'in', $ids)->where('item_ex_code', self::DRAFT)->column('item_id'));
        return array_values(array_diff($ids, $drf));
    }

    /** True for a preview draft row (R5-01). */
    private static function isDraft(array $r)
    {
        return (string)($r['item_ex_code'] ?? '') === self::DRAFT;
    }

    /** Deletes preview draft rows (only off rows marked DRF) and their unpacked zip folders; no build (they never print). */
    private function dropDrafts(array $ids)
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids || !$this->jcxReady) { return 0; }
        $ids = Db::name('jcx_item')->where('item_id', 'in', $ids)->where('item_ex_code', self::DRAFT)->where('item_status', 0)->column('item_id');
        if (!$ids) { return 0; }
        $n = (int)Db::name('jcx_item')->where('item_id', 'in', $ids)->where('item_ex_code', self::DRAFT)->where('item_status', 0)->delete();
        foreach ($ids as $id) { JcxBuild::rmTree(APP_PATH . 'data/jcx/z/' . (int)$id); }
        return $n;
    }

    /** Preview drafts left behind by a closed tab: removed after a day (Overview / My ads). */
    private function sweepDrafts()
    {
        if (!$this->jcxReady) { return; }
        try {
            $old = Db::name('jcx_item')->where('item_ex_code', self::DRAFT)->where('item_status', 0)->where('item_edit', '<', time() - 86400)->column('item_id');
            if ($old) { $this->dropDrafts($old); }
        } catch (\Throwable $e) {}
    }

    private function statTotals(array $stats, array $ids = null)
    {
        $t = array_fill(1, 11, 0);
        foreach ($stats['items'] ?? [] as $id => $ev) {
            if ($ids !== null && !isset($ids[$id])) { continue; }
            foreach ($ev as $e => $n) { $t[(int)$e] = ($t[(int)$e] ?? 0) + (int)$n; }
        }
        return $t;
    }

    private static function ctr($views, $clicks)
    {
        return $views > 0 ? number_format(100 * $clicks / $views, 2) . ' %' : '—';
    }

    /** Status of each place in the active theme (4.2 Places): works / nearby / missing. */
    private function placeState($theme)
    {
        $map = is_file(APP_PATH . 'common/util/jcx_anchors.php') ? (array)include APP_PATH . 'common/util/jcx_anchors.php' : [];
        $prof = (array)($map[$theme] ?? []);
        $out = [];
        foreach (JcxModel::PLACES as $p) {
            $st = 'works';
            $near = '';
            if (array_key_exists($p, $prof) && $prof[$p] === null) { $st = 'missing'; }
            elseif ($p === 'P04' && !isset($prof['P04'])) { $st = 'nearby'; $near = self::t('pl_P03'); }
            elseif ($p === 'P14') { $st = 'missing'; }
            $out[$p] = ['state' => $st, 'near' => $near];
        }
        return $out;
    }

    /* ================================================================== pages */

    /** Overview (4.2, 4.10): status strip, beginner checklist, health card, 7-day cards, notices. */
    public function index()
    {
        $vars = ['counts' => ['on' => 0, 'ex' => 0, 'risk' => 0, 'all' => 0], 'chk' => [], 'health' => [], 'stat7' => [], 'top' => [], 'bars' => [],
            'video_on' => 0, 'old_files' => 0, 'risk_items' => []];
        if ($this->jcxReady) {
            $this->sweepDrafts();
            $rows = array_values(array_filter(JcxModel::listItems(['limit' => 2000])['list'], function ($r) { return !self::isDraft($r); }));
            $c = JcxBuild::conf();
            $hasOwn = false;
            $ownOn = false;
            foreach ($rows as $r) {
                if ((int)$r['item_example']) { $vars['counts']['ex']++; continue; }
                $hasOwn = true;
                $vars['counts']['all']++;
                if ((int)$r['item_status']) {
                    $ownOn = true;
                    $vars['counts']['on']++;
                    if (self::isRisk($r)) { $vars['counts']['risk']++; $vars['risk_items'][] = self::itemName($r); }
                    if ($r['item_unit'] === 'U15') { $vars['video_on'] = 1; }
                }
            }
            $done = array_map('intval', (array)($c['chk'] ?? []));
            $auto = [1 => $vars['counts']['ex'] > 0, 2 => in_array(2, $done, true), 3 => $hasOwn, 4 => $ownOn, 5 => in_array(5, $done, true)];
            foreach ($auto as $n => $d) { $vars['chk'][] = ['n' => $n, 'done' => $d || in_array($n, $done, true)]; }
            $vars['health'] = JcxBuild::health();
            $s7 = JcxModel::stats(7);
            $t = $this->statTotals($s7);
            $vars['stat7'] = ['views' => $t[1], 'clicks' => $t[3], 'ctr' => self::ctr($t[1], $t[3]), 'starts' => $t[5]];
            $max = 1;
            for ($i = 6; $i >= 0; $i--) {
                $d = (int)date('Ymd', time() - $i * 86400);
                $n = (int)($s7['by_day'][$d][1] ?? 0);
                $max = max($max, $n);
                $vars['bars'][] = ['day' => self::t('day_' . date('N', time() - $i * 86400)) . ' ' . date('j', time() - $i * 86400), 'n' => $n];
            }
            foreach ($vars['bars'] as &$b) { $b['pct'] = (int)round(100 * $b['n'] / $max); }
            unset($b);
            $names = [];
            foreach ($rows as $r) { $names[(int)$r['item_id']] = $r; }
            $top = [];
            foreach ($s7['items'] ?? [] as $id => $ev) { if (isset($names[$id]) && !(int)$names[$id]['item_example']) { $top[$id] = (int)($ev[1] ?? 0); } }
            arsort($top);
            foreach (array_slice($top, 0, 5, true) as $id => $v) {
                $cl = (int)($s7['items'][$id][3] ?? 0);
                $vars['top'][] = ['id' => $id, 'name' => self::itemName($names[$id]), 'views' => $v, 'clicks' => $cl, 'ctr' => self::ctr($v, $cl)];
            }
        }
        $vars['old_files'] = count(glob(ROOT_PATH . 'template/*/ads/*.js') ?: []);
        // master switch off: the pages carry no ad code at all (engine E9), so the browser check reports "off", not a warning
        $vars['js'] = ['health' => $vars['health'] ? ['crev' => (int)$vars['health']['crev'], 'cfg' => (string)$vars['health']['cfg'], 'on' => (int)($vars['health']['on'] ?? 1)] : null];
        // R3r3-05: page types whose c-<page>.json is over the 30 KB budget (engine health 'cbig': a list of page types)
        $vars['cbig'] = [];
        foreach ((array)($vars['health']['cbig'] ?? []) as $k => $v) {
            $pt = is_string($v) ? $v : (string)$k;
            $n = self::t('pt_' . $pt);
            $vars['cbig'][] = $n === 'pt_' . $pt ? $pt : $n;
        }
        return $this->page('index', 'index', $vars);
    }

    /** My ads (4.2): chips, toolbar, table, bulk, empty state. */
    public function lists()
    {
        // Owner feedback 2026-10-14 ("why have 116?"): the list is about the owner's own ads. Every chip except "Examples"
        // counts and lists only own ads (item_example = 0); the examples (always off, a library to copy from) have their
        // own last chip; the old "All" chip is gone (an old ?chip=all link falls back to "mine").
        // param., not get.: url() writes the chip into the path (lists/chip/on.html), which never reaches $_GET.
        $chip = (string)input('param.chip', 'mine');
        $chipList = ['mine', 'on', 'off', 'code', 'video', 'ending', 'risk', 'examples'];
        if (!in_array($chip, $chipList, true)) { $chip = 'mine'; }
        $rows = [];
        $counts = array_fill_keys($chipList, 0);
        if ($this->jcxReady) {
            $this->sweepDrafts();
            $res = JcxModel::listItems(['limit' => 2000]);
            $share = $res['share'];
            $s7 = JcxModel::stats(7);
            $now = time();
            foreach ($res['list'] as $r) {
                if (self::isDraft($r)) { continue; }
                $ex = (int)$r['item_example'];
                $own = !$ex;
                $tags = ['mine' => $own, 'on' => $own && (int)$r['item_status'], 'off' => $own && !(int)$r['item_status'],
                    'code' => $own && in_array($r['item_kind'], ['code', 'html'], true), 'video' => $own && in_array($r['item_unit'], ['U15', 'U16'], true),
                    'ending' => $own && (int)$r['item_end'] > $now && (int)$r['item_end'] <= $now + 7 * 86400, 'risk' => $own && self::isRisk($r), 'examples' => (bool)$ex];
                foreach ($tags as $k => $v) { if ($v) { $counts[$k]++; } }
                if (!$tags[$chip]) { continue; }
                $v = (int)($s7['items'][$r['item_id']][1] ?? 0);
                $cl = (int)($s7['items'][$r['item_id']][3] ?? 0);
                $rows[] = [
                    'id' => (int)$r['item_id'], 'name' => self::itemName($r), 'unit' => $r['item_unit'], 'format' => self::t('u_' . $r['item_unit']),
                    'where' => $r['item_place'] !== '' ? self::t('pl_' . $r['item_place']) : '—', 'place' => $r['item_place'],
                    'dev' => (int)$r['item_dev'], 'dev_text' => self::devText($r['item_dev']), 'sched' => self::schedText($r),
                    'share' => ((int)$r['item_status'] && !$ex && !empty($share[$r['item_id']])) ? self::t('share_pct', $share[$r['item_id']]) : '—',
                    'share_n' => ((int)$r['item_status'] && !$ex && !empty($share[$r['item_id']])) ? (int)$share[$r['item_id']] : 0,
                    'aka' => self::t(self::cardOf($r) . '_aka'),
                    'views' => $v, 'ctr' => self::ctr($v, $cl), 'on' => (int)$r['item_status'], 'ex' => $ex, 'x' => $r['item_ex_code'],
                    'thumb' => self::thumb($r), 'risk' => self::isRisk($r) ? 1 : 0, 'icon' => self::CARDS[self::cardOf($r)][2],
                    'gone' => self::rollGone($r) ? 1 : 0,
                ];
            }
        }
        $labels = [];
        foreach ($chipList as $c) { $labels[$c] = self::t($c === 'mine' ? 'chip_yours' : 'chip_' . $c); }
        return $this->page('lists', 'lists', ['rows' => $rows, 'chip' => $chip, 'chips' => $chipList, 'chip_labels' => $labels, 'counts' => $counts,
            'examples_n' => $counts['examples'], 'own_n' => $counts['mine']]);
    }

    /** Add / edit: the 3-step editor (4.3). */
    public function info()
    {
        $id = (int)input('param.id', 0);
        $item = null;
        if ($id > 0 && $this->jcxReady) {
            $g = JcxModel::getItem($id);
            if (empty($g['code'])) { return $this->error(self::t('err_notfound')); }
            $item = $g['item'];
        }
        $card = $item ? self::cardOf($item) : (string)input('param.card', '');
        if ($card !== '' && !isset(self::CARDS[$card])) { $card = ''; }
        $preset = $item ? (string)$item['item_preset'] : (string)input('param.preset', '');
        if ($preset !== '' && !in_array($preset, JcxModel::PRESETS, true)) { $preset = ''; }
        if ($preset === 'house') { $card = 'image'; }
        if ($preset !== '' && $card === '') { $card = $preset === 'vast' ? 'preroll' : ($preset === 'link' ? 'link' : 'code'); }
        $js = [
            'item' => $item ? $this->itemForJs($item) : null, 'card' => $card, 'preset' => $preset, 'place' => (string)input('param.place', ''),
            'cards' => [], 'allowed' => [], 'place_pages' => JcxModel::PLACE_PAGES, 'iab' => JcxModel::IAB, 'nonce' => $this->jcxReady ? JcxBuild::nonce($this->adminId(), 'pick') : '',
            'risk' => JcxModel::RISK, 'safe_cap' => JcxModel::SAFE_CAP, 'nets' => [], 'can_code' => $this->canCode(), 'geo' => $this->jcxReady ? (string)JcxBuild::conf()['geo_header'] : '',
            'tz' => (int)date('Z'), 'compress' => (int)($GLOBALS['config']['app']['compress'] ?? 0), 'place_weights' => [],
        ];
        if ($this->jcxReady) {
            foreach (JcxModel::listItems(['status' => 1, 'example' => 0, 'limit' => 2000])['list'] as $r) {
                if ((int)$r['item_id'] === $id) { continue; }
                $js['place_weights'][$r['item_place']] = ($js['place_weights'][$r['item_place']] ?? 0) + (int)$r['item_weight'];
            }
        }
        foreach (self::CARDS as $c => $d) {
            $js['cards'][$c] = ['unit' => $d[0], 'kind' => $d[1], 'risk' => $d[4]];
            $js['allowed'][$c] = JcxModel::allowedPlaces($d[0], $d[1]);
        }
        $js['allowed']['code'] = JcxModel::allowedPlaces('U01', 'code');
        foreach (self::NETS as $k => $n) { $js['nets'][$k] = ['name' => $n[0] !== '' ? $n[0] : self::t('net_other'), 'adult' => $n[1]]; }
        $cats = [];
        try {
            foreach (Db::name('type')->where('type_mid', 1)->where('type_status', 1)->order('type_sort asc,type_id asc')->field('type_id,type_name')->limit(300)->select() as $t) {
                $cats[] = ['id' => (int)$t['type_id'], 'name' => html_entity_decode((string)$t['type_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
            }
        } catch (\Throwable $e) {}
        return $this->page('info', 'lists', [
            'js' => $js, 'item' => $item, 'card' => $card, 'cats' => $cats, 'cards' => self::CARDS, 'nets' => self::NETS, 'not_web' => self::NOT_WEB,
            'can_code' => $this->canCode(), 'is_example' => $item && (int)$item['item_example'], 'gone' => $item && self::rollGone($item), 'langs' => JcxModel::LANGS, 'iab' => JcxModel::IAB,
            'geo' => $js['geo'], 'presets' => JcxModel::PRESETS, 'all_places' => JcxModel::PLACES, 'pg_list' => ['home', 'list', 'detail', 'play', 'search', 'art', 'other', '404'], 'day_list' => [1, 2, 3, 4, 5, 6, 7],
        ]);
    }

    private function itemForJs(array $r)
    {
        $out = [];
        foreach ($r as $k => $v) {
            if (in_array($k, ['item_media', 'item_opts'], true)) { $out[$k] = json_decode((string)$v, true) ?: new \stdClass(); continue; }
            if (in_array($k, ['item_code', 'item_code_m'], true) && !$this->canCode()) { $out[$k] = (string)$v; continue; }
            $out[$k] = is_numeric($v) && !in_array($k, ['item_hours', 'item_name', 'item_note', 'item_title', 'item_text', 'item_alt', 'item_brand', 'item_cta', 'item_url', 'item_geo'], true) ? (int)$v : (string)$v;
        }
        $out['thumb'] = self::thumb($r);
        if ((int)($r['item_example'] ?? 0)) { $out['item_name'] = self::itemName($r); }
        return $out;
    }

    /**
     * R6r2-05 / R6r2-02: the name an owner reads. An example shows its plain name in the admin's language (key exn_<Xnn>);
     * an old "Example Xnn · " prefix (manifests before round 2, copies made from them) is dropped: no internal codes.
     */
    private static function itemName(array $r)
    {
        $x = (string)($r['item_ex_code'] ?? '');
        if ((int)($r['item_example'] ?? 0) && preg_match('/^X[0-9]{2}$/D', $x)) {
            $t = self::t('exn_' . $x);
            if ($t !== 'exn_' . $x && $t !== '') { return $t; }
        }
        $n = preg_replace('/^Example\s+X[0-9]{2}\s*·\s*/u', '', (string)($r['item_name'] ?? ''));
        return $n === null || $n === '' ? (string)($r['item_name'] ?? '') : $n;
    }

    /** Places (4.2, 4.4): place table, switches, feed settings, anchor overrides. */
    public function places()
    {
        if ($this->request->isPost()) { return $this->placeSave(); }
        $theme = $this->activeTheme();
        $rows = [];
        $anchors = [];
        if ($this->jcxReady) {
            $p = JcxModel::places();
            $miss = [];
            foreach ((array)$p['miss'] as $k => $v) {
                list($th, $pl) = array_pad(explode('|', (string)$k), 2, '');
                if ($th === $theme) { $miss[$pl] = ($miss[$pl] ?? 0) + (int)(is_array($v) ? $v[0] : $v); }
            }
            $state = $this->placeState($theme);
            foreach ($p['places'] as $code => $r) {
                $rows[] = ['code' => $code, 'name' => self::t('pl_' . $code), 'on' => (int)$r['pl_status'], 'n_on' => (int)($r['items']['on'] ?? 0),
                    'n_off' => (int)($r['items']['off'] ?? 0), 'state' => $state[$code]['state'], 'near' => $state[$code]['near'], 'miss' => (int)($miss[$code] ?? 0),
                    'every' => (int)$r['pl_every'], 'max' => (int)$r['pl_max'], 'opts' => (array)$r['pl_opts']];
            }
            $anchors = JcxModel::anchors()['list'];
        }
        return $this->page('places', 'places', ['rows' => $rows, 'anchors' => $anchors, 'theme' => $theme, 'themes' => $this->themes(),
            'slots' => JcxModel::SLOTS, 'js' => ['nonce' => $this->jcxReady ? JcxBuild::nonce($this->adminId(), 'pick') : '',
                'pl_on' => (object)array_column(array_map(function ($r) { return [$r['code'], $r['n_on']]; }, $rows), 1, 0)]]);
    }

    /** Video ads (4.9, 7.6 admin half; UD4: every video plays in the provider's iframe player, so only the cards that play
     *  before it: pre-roll and the rewarded pre-roll). */
    public function video()
    {
        if ($this->request->isPost()) { return $this->videoSave(); }
        $cards = ['pre' => null, 'reward' => null];
        $report = [];
        if ($this->jcxReady) {
            $s30 = JcxModel::stats(30);
            foreach (JcxModel::listItems(['video' => 1, 'limit' => 2000])['list'] as $r) {
                if (self::isDraft($r)) { continue; }
                $o = json_decode((string)$r['item_opts'], true) ?: [];
                $roll = (string)($o['roll'] ?? ($r['item_unit'] === 'U15' ? 'pre' : ''));
                // R4-02: one card per roll shows the real item (an item that is on wins over the newest one that is off), so a
                // save edits it and the card's switch can turn it off. isset() was always false here: the slots hold null.
                if ($r['item_unit'] === 'U15' && !(int)$r['item_example'] && array_key_exists($roll, $cards)
                    && ($cards[$roll] === null || (!(int)$cards[$roll]['item_status'] && (int)$r['item_status']))) {
                    $cards[$roll] = $this->itemForJs($r);
                }
                // Owner feedback 2026-10-14: the report lists the owner's own video ads only (examples never play).
                if ((int)$r['item_example']) { continue; }
                $ev = $s30['items'][$r['item_id']] ?? [];
                $st = (int)($ev[5] ?? 0);
                $report[] = ['id' => (int)$r['item_id'], 'name' => self::itemName($r), 'ex' => (int)$r['item_example'], 'on' => (int)$r['item_status'], 'gone' => self::rollGone($r) ? 1 : 0, 'starts' => $st,
                    'complete' => $st ? (int)round(100 * (int)($ev[9] ?? 0) / $st) . ' %' : '—', 'skip' => $st ? (int)round(100 * (int)($ev[10] ?? 0) / $st) . ' %' : '—',
                    'errors' => (int)($ev[11] ?? 0)];
            }
        }
        $play = (array)($GLOBALS['config']['play'] ?? []);
        $old = '';
        foreach (['prestrain', 'buffer'] as $k) {
            $v = trim((string)($play[$k] ?? ''));
            if ($v !== '' && preg_match('~\.(?:mp4|webm|m3u8)(?:\?|$)~i', $v)) { $old = $v; break; }
        }
        $oldAny = trim((string)($play['prestrain'] ?? '')) !== '' || trim((string)($play['buffer'] ?? '')) !== '' || (int)($play['second'] ?? 0) > 0;
        return $this->page('video', 'video', ['cards' => $cards, 'report' => $report, 'old_any' => $oldAny ? 1 : 0, 'old_url' => $old,
            'js' => ['vcards' => $cards, 'old_url' => $old]]);
    }

    /** Network & files (4.2, 4.8): code items, presets, root files, conflicts, site-wide code. */
    public function network()
    {
        $items = [];
        $roots = ['adstxt' => null, 'sw' => null, 'verify' => null];
        $conflicts = [];
        $sub = JcxBuild::base() !== '/';
        if ($this->jcxReady) {
            foreach (JcxModel::listItems(['limit' => 2000])['list'] as $r) {
                if ((int)$r['item_example'] || self::isDraft($r)) { continue; }
                if (!in_array($r['item_place'], ['P15h', 'P15b'], true) && !in_array($r['item_unit'], ['U10', 'U11'], true) && !in_array($r['item_kind'], ['code', 'html'], true)) { continue; }
                $cf = (int)$r['item_status'] ? JcxModel::conflicts($r) : [];
                $items[] = ['id' => (int)$r['item_id'], 'name' => self::itemName($r), 'on' => (int)$r['item_status'], 'where' => $r['item_place'] !== '' ? self::t('pl_' . $r['item_place']) : '—',
                    'network' => self::NETS[$r['item_network']][0] ?? '', 'groups' => array_values(array_filter(explode(',', (string)$r['item_group']), function ($g) { return strpos($g, 'zone:') !== 0; })),
                    'conflicts' => $cf];
                foreach ($cf as $c) { $conflicts[] = ['name' => self::itemName($r), 'text' => $c['text']]; }
            }
            foreach (JcxModel::roots()['list'] as $rf) {
                if (array_key_exists($rf['rf_kind'], $roots)) {
                    $file = ROOT_PATH . $rf['rf_name'];
                    $state = !(int)$rf['rf_status'] ? 'off' : (!is_file($file) ? 'missing' : (hash_equals((string)$rf['rf_sha'], (string)@hash_file('sha256', $file)) ? 'same' : 'exists'));
                    $roots[$rf['rf_kind']] = ['id' => (int)$rf['rf_id'], 'name' => $rf['rf_name'], 'body' => (string)$rf['rf_body'], 'on' => (int)$rf['rf_status'], 'state' => $state];
                }
            }
        }
        $tj = html_entity_decode((string)JcxBuild::site('site_tj'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tjHost = '';
        foreach (JcxModel::TJ_HOSTS as $h => $n) { if ($tj !== '' && stripos($tj, $h) !== false) { $tjHost = $n; break; } }
        $home = (string)JcxBuild::site('site_url');
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || stripos($home, 'https://') === 0;
        $pushOn = false;
        foreach ($items as $i) { if ($i['on'] && in_array('sw', $i['groups'], true)) { $pushOn = true; } }
        return $this->page('network', 'network', ['items' => $items, 'roots' => $roots, 'conflicts' => $conflicts, 'tj' => $tj, 'tj_short' => mb_substr($tj, 0, 300, 'UTF-8'),
            'tj_host' => $tjHost, 'https' => $https ? 1 : 0, 'push_on' => $pushOn ? 1 : 0, 'sub' => $sub ? 1 : 0, 'presets' => JcxModel::PRESETS,
            'can_code' => $this->canCode(), 'tj_url' => url('system/config') . '?section=code']);
    }

    /** Stats (4.2): per ad and per video ad, 7 / 30 days. */
    public function stats()
    {
        $days = (int)input('param.days', 7) === 30 ? 30 : 7;
        $rows = [];
        $vrows = [];
        $bars = [];
        if ($this->jcxReady) {
            $s = JcxModel::stats($days);
            $all = JcxModel::listItems(['limit' => 2000])['list'];
            foreach ($all as $r) {
                if ((int)$r['item_example'] || self::isDraft($r)) { continue; }
                $ev = $s['items'][$r['item_id']] ?? [];
                $v = (int)($ev[1] ?? 0);
                $cl = (int)($ev[3] ?? 0);
                $rows[] = ['id' => (int)$r['item_id'], 'name' => self::itemName($r), 'on' => (int)$r['item_status'], 'views' => $v, 'seen' => (int)($ev[2] ?? 0), 'clicks' => $cl,
                    'ctr' => self::ctr($v, $cl), 'closes' => (int)($ev[4] ?? 0)];
                if (in_array($r['item_unit'], ['U15', 'U16'], true)) {
                    $st = (int)($ev[5] ?? 0);
                    $vrows[] = ['id' => (int)$r['item_id'], 'name' => self::itemName($r), 'starts' => $st, 'complete' => $st ? (int)round(100 * (int)($ev[9] ?? 0) / $st) . ' %' : '—',
                        'skip' => $st ? (int)round(100 * (int)($ev[10] ?? 0) / $st) . ' %' : '—', 'errors' => (int)($ev[11] ?? 0)];
                }
            }
            usort($rows, function ($a, $b) { return $b['views'] <=> $a['views']; });
            $max = 1;
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = (int)date('Ymd', time() - $i * 86400);
                $n = (int)($s['by_day'][$d][1] ?? 0);
                $max = max($max, $n);
                $bars[] = ['day' => date('m-d', time() - $i * 86400), 'n' => $n];
            }
            foreach ($bars as &$b) { $b['pct'] = (int)round(100 * $b['n'] / $max); }
            unset($b);
        }
        return $this->page('stats', 'stats', ['rows' => $rows, 'vrows' => $vrows, 'days' => $days, 'bars' => $bars]);
    }

    /** Settings (4.2): master switch, members, VIP, counters, country header, consent, safe limits, danger zone. */
    public function settings()
    {
        if ($this->request->isPost()) { return $this->settingsSave(); }
        $groups = [];
        try {
            foreach (Db::name('group')->field('group_id,group_name')->order('group_id asc')->select() as $g) {
                if ((int)$g['group_id'] > 2) { $groups[] = ['id' => (int)$g['group_id'], 'name' => html_entity_decode((string)$g['group_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')]; }
            }
        } catch (\Throwable $e) {}
        $headers = [];
        foreach (self::GEO_HEADERS as $h) { if (!empty($_SERVER[$h])) { $headers[] = $h; } }
        $conf = $this->jcxReady ? JcxBuild::publicConf() : JcxBuild::conf();
        if ($conf['geo_header'] !== '' && !in_array($conf['geo_header'], $headers, true)) { $headers[] = $conf['geo_header']; }
        $bytes = 0;
        $n = 0;
        if (is_dir(ROOT_PATH . 'upload/jcx')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT_PATH . 'upload/jcx', \FilesystemIterator::SKIP_DOTS)) as $f) { if ($f->isFile()) { $bytes += $f->getSize(); $n++; } }
        }
        $vip = array_map('intval', array_filter(explode(',', (string)($conf['vip'] ?? ''))));
        return $this->page('settings', 'settings', ['groups' => $groups, 'headers' => $headers, 'vip' => $vip, 'uploads' => $n . ' · ' . round($bytes / 1048576, 1) . ' MB']);
    }

    private function settingsSave()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $in = [];
        foreach (['on', 'stats', 'consent', 'member_free', 'safe'] as $k) { if (input('?post.' . $k)) { $in[$k] = (int)input('post.' . $k) ? 1 : 0; } }
        if (input('?post.geo_header')) { $in['geo_header'] = (string)input('post.geo_header', '', null); }
        if (input('?post.vip')) { $v = $this->post('vip', ''); $in['vip'] = is_array($v) ? implode(',', $v) : (string)$v; }
        if (input('?post.chk')) {
            $c = JcxBuild::conf();
            $in['chk'] = array_values(array_unique(array_merge(array_map('intval', (array)($c['chk'] ?? [])), [(int)input('post.chk')])));
        }
        $res = JcxModel::settings($in, ['typed' => (string)input('post.typed', '', null)]);
        if (!empty($res['code'])) { $res['text'] = self::t('set_saved'); }
        return $this->answer($res, ['on' => (int)JcxBuild::conf()['on']]);
    }

    /* ================================================================== writes */

    /** Collects the item fields of the editor (plain text; media / opts as JSON). */
    private function itemInput()
    {
        $in = [];
        foreach (self::FIELDS as $f) {
            if (!input('?post.' . $f)) { continue; }
            $v = $this->post($f, '');
            if (in_array($f, ['item_media', 'item_opts'], true) && is_string($v)) {
                $d = $v === '' ? [] : json_decode($v, true);
                $v = is_array($d) ? $d : [];
            }
            if (in_array($f, ['item_pages', 'item_cats', 'item_langs'], true) && is_array($v)) { $v = implode(',', $v); }
            $in[$f] = $v;
        }
        return $in;
    }

    /** jcx/save: insert / update an item (also step 3 drafts), duplicate, import old files, move site_tj. */
    public function save()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $op = (string)input('post.op', 'item');
        $ctx = ['can_code' => $this->canCode(), 'confirm_conflict' => (int)input('post.confirm_conflict', 0) ? 1 : 0, 'switch_on' => (int)input('post.switch_on', 0) ? 1 : 0];
        if ($op === 'duplicate') {
            $src = Db::name('jcx_item')->where('item_id', (int)input('post.id', 0))->find();
            // R2-F1: a copy of a code / html / html5 / P15 item needs the jcx/code right like any other save of it.
            if ($src && !$this->canCode() && (in_array($src['item_kind'], ['code', 'html', 'html5'], true) || in_array($src['item_place'], ['P15h', 'P15b'], true)
                || trim((string)$src['item_code']) !== '' || trim((string)$src['item_code_m']) !== '' || in_array((string)input('post.place', '', null), ['P15h', 'P15b'], true))) {
                return $this->refuse('code_no_right', 'item_code');
            }
            $res = JcxModel::duplicate((int)input('post.id', 0), (string)input('post.place', '', null), $this->adminId(), $this->canCode());
            if (!empty($res['code'])) {
                $res['text'] = self::t('duplicated');
                // R1r2-11 / R6r2-05: a copy is a normal ad: its name is the plain name (admin language for an example) + " (2)".
                if ($src && !empty($res['id'])) {
                    Db::name('jcx_item')->where('item_id', (int)$res['id'])->where('item_example', 0)
                        ->update(['item_name' => mb_substr(self::itemName($src) . ' (2)', 0, 80, 'UTF-8')]);
                }
            }
            return $this->answer($res, ['url' => !empty($res['id']) ? url('jcx/info', ['id' => $res['id']]) : '']);
        }
        if ($op === 'import_old') { return $this->importOld(); }
        if ($op === 'move_tj') { return $this->moveTj(); }
        if ($op === 'drop_draft') {
            $this->dropDrafts(explode(',', (string)input('post.id', '', null)));
            return json(['code' => 1, 'msg' => '', '__token__' => mac_admin_csrf_token()]);
        }
        $in = $this->itemInput();
        $id = (int)input('post.item_id', 0);
        if (array_key_exists('item_days', $in) && (int)$in['item_days'] === 0) { return $this->refuse('err_days', 'item_days'); }
        if ((int)input('post.draft', 0)) { return $this->saveDraft($in, $id); }
        $old = $id > 0 ? Db::name('jcx_item')->where('item_id', $id)->find() : null;
        if ($id > 0) { $in['item_id'] = $id; }
        if (!$ctx['switch_on']) { $in['item_status'] = 0; }
        $in = $this->withName($in, $old);
        // A new ad starts as a preview draft: clear its marker before the real save, so the build never sees a 'DRF' row on.
        $wasDraft = $old && self::isDraft($old);
        // R2r2-N1: promoting a draft is a create; a draft copy of a code / html / html5 / P15 item needs the jcx/code right
        // like duplicate() does (R2-F1), or a role without it could turn the copy into a second live code item.
        if ($wasDraft && !$this->canCode() && (in_array($old['item_kind'], ['code', 'html', 'html5'], true) || in_array($old['item_place'], ['P15h', 'P15b'], true)
            || trim((string)$old['item_code'] . (string)$old['item_code_m']) !== '')) {
            return $this->refuse('code_no_right', 'item_code');
        }
        if ($wasDraft) { Db::name('jcx_item')->where('item_id', $id)->update(['item_ex_code' => '']); }
        $res = JcxModel::saveItem($in, $this->adminId(), $ctx);
        $extra = [];
        if (!empty($res['code'])) {
            $dropId = (int)input('post.drop_draft', 0);
            if ($dropId > 0 && $dropId !== (int)$res['id']) { $this->dropDrafts([$dropId]); }
            $extra['url'] = url('jcx/info', ['id' => $res['id']]);
            $extra['on'] = (int)($res['row']['item_status'] ?? 0);
            if (!empty($res['auto_on'])) { $extra['auto_on_text'] = self::t('master_auto_on'); }
            // R1-14: an ad that is on but starts later is not visible "now".
            if ($extra['on'] && (int)($res['row']['item_start'] ?? 0) > time()) {
                $res['text'] = self::t('saved_later', date('Y-m-d H:i', (int)$res['row']['item_start']));
            }
        } elseif ($wasDraft) {
            Db::name('jcx_item')->where('item_id', $id)->update(['item_ex_code' => self::DRAFT]);
        }
        return $this->answer($res, $extra);
    }

    /**
     * Fills an empty name with the admin-language default (R4-12); an old automatic English name ("Banner 300×250 · P03")
     * is replaced too, an own name is kept.
     */
    private function withName(array $in, $old)
    {
        $name = array_key_exists('item_name', $in) ? trim((string)$in['item_name']) : ($old ? (string)$old['item_name'] : '');
        if ($name === '' || ($old && $name === JcxModel::autoName($old))) {
            $r = ['item_unit' => $in['item_unit'] ?? ($old['item_unit'] ?? 'U01'), 'item_kind' => $in['item_kind'] ?? ($old['item_kind'] ?? ''),
                'item_place' => $in['item_place'] ?? ($old['item_place'] ?? ''), 'item_w' => $in['item_w'] ?? ($old['item_w'] ?? 0),
                'item_h' => $in['item_h'] ?? ($old['item_h'] ?? 0), 'item_opts' => $in['item_opts'] ?? ($old['item_opts'] ?? '')];
            if ($r['item_unit'] === 'U14') { $r['item_place'] = ''; }
            $in['item_name'] = self::niceName($r);
        }
        return $in;
    }

    /**
     * Step-3 preview draft (R5-01). A new ad: the draft row itself (marked DRF, becomes the ad on the first real save).
     * An existing ad: a separate DRF copy (post draft_id re-uses it), so the live row, its status and the page cache are
     * never touched; nothing is rebuilt. Answers id (the ad being edited) and draft_id (the row to preview).
     */
    private function saveDraft(array $in, $id)
    {
        $orig = $id > 0 ? Db::name('jcx_item')->where('item_id', $id)->find() : null;
        if ($id > 0 && !$orig) { return $this->refuse('err_notfound', 'item_id'); }
        $target = null;
        if ($orig && self::isDraft($orig)) {
            $target = $orig;
        } else {
            $did = (int)input('post.draft_id', 0);
            // R2r2-N10: only the admin's own open preview draft is re-used.
            if ($did > 0) { $target = Db::name('jcx_item')->where('item_id', $did)->where('item_ex_code', self::DRAFT)->where('item_status', 0)->where('item_admin', $this->adminId())->find() ?: null; }
        }
        unset($in['item_id']);
        $in['item_status'] = 0;
        $in = $this->withName($in, $orig);
        $conf = JcxBuild::conf();
        $c = JcxModel::clean($in, ['old' => $orig ?: null, 'can_code' => $this->canCode(), 'safe' => (int)$conf['safe']]);
        if (empty($c['code'])) { return $this->answer($c); }
        $row = $c['row'];
        if ($row['item_kind'] === 'image' && (!$row['item_w'] || !$row['item_h'])) {
            $m = json_decode((string)$row['item_media'], true) ?: [];
            foreach ([['src', 'item_w', 'item_h'], ['srcm', 'item_wm', 'item_hm']] as $t) {
                if (!empty($m[$t[0]]) && !$row[$t[1]] && is_file(ROOT_PATH . $m[$t[0]])) {
                    $sz = @getimagesize(ROOT_PATH . $m[$t[0]]);
                    if ($sz) { $row[$t[1]] = min(1000, (int)$sz[0]); $row[$t[2]] = min(1050, (int)$sz[1]); }
                }
            }
        }
        $row['item_status'] = 0;
        $row['item_example'] = 0;
        $row['item_ex_code'] = self::DRAFT;
        $row['item_edit'] = time();
        $row['item_admin'] = $this->adminId();
        if ($target) {
            Db::name('jcx_item')->where('item_id', (int)$target['item_id'])->where('item_ex_code', self::DRAFT)->update($row);
            $tid = (int)$target['item_id'];
        } else {
            $row['item_add'] = time();
            $tid = (int)Db::name('jcx_item')->insertGetId($row);
        }
        $copy = $orig && !self::isDraft($orig);
        return $this->answer(['code' => 1, 'id' => $copy ? (int)$id : $tid, 'warn' => $c['warn'], 'text' => self::t($copy ? 'draft_preview' : 'draft_saved')],
            ['draft_id' => $tid, 'copy' => $copy ? 1 : 0]);
    }

    /** "Import as code ads" (3.5): one off code item per template/<theme>/ads/*.js file. */
    private function importOld()
    {
        if (!$this->canCode()) { return $this->refuse('code_no_right', 'item_code'); }
        $n = 0;
        foreach (array_slice(glob(ROOT_PATH . 'template/*/ads/*.js') ?: [], 0, 50) as $f) {
            $theme = basename(dirname(dirname($f)));
            $rel = 'template/' . $theme . '/ads/' . basename($f);
            if (!preg_match('~^template/[A-Za-z0-9_-]+/ads/[A-Za-z0-9._-]+\.js$~D', $rel)) { continue; }
            $code = '<script src="' . JcxBuild::base() . $rel . '"></script>';
            $r = JcxModel::saveItem(['item_unit' => 'U01', 'item_kind' => 'code', 'item_place' => 'P15b', 'item_status' => 0, 'item_code' => $code,
                'item_name' => mb_substr($theme . ' · ' . basename($f), 0, 80, 'UTF-8'), 'item_preset' => 'any'], $this->adminId(), ['can_code' => true, 'confirm_conflict' => 1]);
            if (!empty($r['code'])) { $n++; }
        }
        return json(['code' => 1, 'msg' => str_replace('{n}', (string)$n, self::t('old_imported')), '__token__' => mac_admin_csrf_token()]);
    }

    /** "Move this code into an ad (off)" (3.5): copies site_tj into an off P15b code item; site_tj is never changed. */
    private function moveTj()
    {
        if (!$this->canCode()) { return $this->refuse('code_no_right', 'item_code'); }
        $tj = html_entity_decode((string)JcxBuild::site('site_tj'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (trim($tj) === '') { return $this->refuse('err_need_code', 'item_code'); }
        $res = JcxModel::saveItem(['item_unit' => 'U01', 'item_kind' => 'code', 'item_place' => 'P15b', 'item_status' => 0, 'item_code' => $tj,
            'item_name' => self::t('sitecode_title'), 'item_preset' => 'any'], $this->adminId(), ['can_code' => true, 'confirm_conflict' => 1]);
        if (!empty($res['code'])) { $res['text'] = self::t('sitecode_moved'); }
        return $this->answer($res, ['url' => !empty($res['id']) ? url('jcx/info', ['id' => $res['id']]) : '']);
    }

    /** jcx/status: items on / off (ids, status, risk_ok, confirm_conflict) or a place switch (pl, status). */
    public function status()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $st = (int)input('post.status', 0) ? 1 : 0;
        $pl = (string)input('post.pl', '', null);
        if ($pl !== '') {
            if (!$this->check_auth('jcx', 'places')) { return $this->refuse('err_place', 'pl'); }
            $res = JcxModel::savePlace($pl, ['pl_status' => $st]);
            if (!empty($res['code'])) { $res['text'] = self::t($st ? 'switched_on' : 'switched_off'); }
            return $this->answer($res);
        }
        $ids = array_filter(array_map('intval', explode(',', (string)input('post.ids', '', null))));
        if ($st) { $ids = $this->noDrafts($ids); if (!$ids) { return $this->refuse('err_notfound'); } }
        if ($st && ($no = $this->rollGoneRefusal($ids))) { return $no; }
        $res = JcxModel::setStatus($ids, $st, ['risk_ok' => (int)input('post.risk_ok', 0), 'confirm_conflict' => (int)input('post.confirm_conflict', 0), 'switch_on' => $st]);
        if (!empty($res['code'])) { $res['text'] = self::t($st ? 'switched_on' : 'switched_off'); }
        $extra = [];
        if (!empty($res['auto_on'])) { $extra['auto_on_text'] = self::t('master_auto_on'); }
        if (isset($res['item_id'])) { $extra['item_id'] = (int)$res['item_id']; }
        return $this->answer($res, $extra);
    }

    /** jcx/del: delete items. */
    public function del()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $ids = array_filter(array_map('intval', explode(',', (string)input('post.ids', '', null))));
        $res = JcxModel::delItems($ids);
        if (!empty($res['code'])) { $res['text'] = self::t('deleted'); }
        return $this->answer($res);
    }

    /** jcx/bulk: op on | off | delete for many ids (permission: status, or del for delete). */
    public function bulk()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $op = (string)input('post.op', '');
        $ids = array_filter(array_map('intval', explode(',', (string)input('post.ids', '', null))));
        if ($op === 'delete') { return $this->del(); }
        if ($op !== 'on' && $op !== 'off') { return $this->refuse('err_notfound'); }
        if ($ids && $this->jcxReady) {
            $ex = array_map('intval', Db::name('jcx_item')->where('item_id', 'in', array_values($ids))->where('item_example', 1)->column('item_id'));
            if ($ex) {
                $ids = array_values(array_diff($ids, $ex));
                if (!$ids) { return $this->refuse('bulk_examples'); }
            }
        }
        if ($op === 'on') { $ids = $this->noDrafts($ids); if (!$ids) { return $this->refuse('err_notfound'); } }
        if ($op === 'on' && ($no = $this->rollGoneRefusal($ids))) { return $no; }
        $res = JcxModel::setStatus($ids, $op === 'on' ? 1 : 0, ['risk_ok' => (int)input('post.risk_ok', 0), 'confirm_conflict' => (int)input('post.confirm_conflict', 0), 'switch_on' => $op === 'on']);
        if (!empty($res['code'])) { $res['text'] = self::t($op === 'on' ? 'switched_on' : 'switched_off'); }
        return $this->answer($res, !empty($res['auto_on']) ? ['auto_on_text' => self::t('master_auto_on')] : []);
    }

    /** jcx/upload: one creative file (11.1 checks in Jcx::upload); a zip needs item_id (unpacked for jcx/h5). */
    public function upload()
    {
        // A body larger than post_max_size reaches PHP with empty $_POST / $_FILES (no token either): say "too large", not "page expired".
        if ($this->request->isPost() && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > self::iniBytes((string)ini_get('post_max_size'))) {
            return $this->refuse('err_size', 'file');
        }
        if (!$this->tokenOk()) { return $this->badToken(); }
        $file = $_FILES['file'] ?? null;
        if (is_array($file) && in_array((int)($file['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { return $this->refuse('err_size', 'file'); }
        if (!is_array($file) || is_array($file['name'] ?? null)) { return $this->refuse('err_need_file', 'file'); }
        $itemId = (int)input('post.item_id', 0);
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['zip'], true) && !$this->canCode()) { return $this->refuse('code_no_right', 'file'); }
        $res = JcxModel::upload($file, $itemId);
        $extra = [];
        if (!empty($res['code'])) {
            $res['text'] = self::t('uploaded', (string)$file['name']);
            foreach (['path', 'w', 'h', 'size', 'ext', 'files'] as $k) { if (isset($res[$k])) { $extra[$k] = $res[$k]; } }
            if (!empty($res['path'])) { $extra['url'] = JcxBuild::base() . $res['path']; }
        }
        return $this->answer($res, $extra);
    }

    /** jcx/preview: sets the signed preview cookies (4.7) and answers with the URL to open. */
    public function preview()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $scope = (string)input('post.scope', 'ex');
        if (!JcxBuild::validScope($scope)) { $scope = 'ex'; }
        $v = JcxBuild::sendPreviewCookies($this->adminId(), $scope);
        if ($v === '') { return $this->refuse('err_token'); }
        // R4-11 / R6-04: the preview panel answers in the admin's language on every page (the page chooser and the theme
        // switch drop the #al= hash). Not a secret: only a language code.
        if (!headers_sent()) {
            setcookie('jcxal', $this->adminLang(), ['expires' => time() + JcxBuild::PREVIEW_TTL, 'path' => JcxBuild::cookiePath(),
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => false, 'samesite' => 'Lax']);
        }
        $theme = (string)input('post.theme', '', null);
        if ($theme !== '' && \app\common\util\ThemePreview::validName($theme) && is_file(ROOT_PATH . 'template/' . $theme . '/info.ini')) {
            setcookie(\app\common\util\ThemePreview::COOKIE, \app\common\util\ThemePreview::sign($theme), ['expires' => time() + JcxBuild::PREVIEW_TTL,
                'path' => JcxBuild::cookiePath(), 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        } elseif ($theme === '-' && !headers_sent()) {
            setcookie(\app\common\util\ThemePreview::COOKIE, '', ['expires' => time() - 3600, 'path' => JcxBuild::cookiePath(), 'samesite' => 'Lax']);
        }
        $page = (string)input('post.page', '');
        $urls = JcxBuild::sampleUrls();
        if (!isset($urls[$page])) {
            $page = 'home';
            if (strpos($scope, 'place-') === 0 || strpos($scope, 'item-') === 0) {
                $place = strpos($scope, 'place-') === 0 ? substr($scope, 6) : (string)Db::name('jcx_item')->where('item_id', (int)substr($scope, 5))->value('item_place');
                $page = self::pageOf($place);
                if (!isset($urls[$page])) { $page = 'home'; }
            }
        }
        if ((int)input('post.chk', 0) >= 1) {
            $c = JcxBuild::conf();
            JcxModel::settings(['chk' => array_values(array_unique(array_merge(array_map('intval', (array)($c['chk'] ?? [])), [(int)input('post.chk')])))]);
        }
        return json(['code' => 1, 'msg' => self::t('preview_opening'), 'url' => $urls[$page], 'page' => $page, '__token__' => mac_admin_csrf_token()]);
    }

    private static function iniBytes($v)
    {
        $v = trim($v);
        $n = (int)$v;
        $u = strtolower(substr($v, -1));
        return $u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n));
    }

    /** The sample page type where a place shows (4.7 step 2). */
    private static function pageOf($place)
    {
        $m = ['P02' => 'home', 'P06' => 'home', 'P03' => 'play', 'P04' => 'play', 'P11' => 'play', 'P16' => 'play', 'P03d' => 'detail', 'P05' => 'list', 'P12' => 'search', 'P14' => 'list'];
        return $m[$place] ?? 'home';
    }

    /** jcx/anchor: save (anc_*) or delete (del=id) an anchor override (CF21 validation in the model). */
    public function anchor()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        if ((int)input('post.del', 0) > 0) {
            $res = JcxModel::delAnchor((int)input('post.del'));
            if (!empty($res['code'])) { $res['text'] = self::t('anchor_saved'); }
            return $this->answer($res);
        }
        $in = [];
        foreach (['anc_theme', 'anc_place', 'anc_at', 'anc_pos', 'anc_nth', 'anc_every', 'anc_max', 'anc_status'] as $k) { if (input('?post.' . $k)) { $in[$k] = (string)input('post.' . $k, '', null); } }
        $res = JcxModel::saveAnchor($in);
        if (!empty($res['code'])) { $res['text'] = self::t('anchor_saved'); }
        return $this->answer($res);
    }

    /** jcx/root: save or remove a root file (ads.txt, push worker, verification file). Needs jcx/code. */
    public function root()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        if (!$this->canCode()) { return $this->refuse('code_no_right', 'rf_body'); }
        if ((int)input('post.del', 0) > 0) {
            $res = JcxModel::delRoot((int)input('post.del'));
            if (!empty($res['code'])) { $res['text'] = self::t('root_removed'); }
            return $this->answer($res);
        }
        $in = ['rf_kind' => (string)input('post.rf_kind', '', null), 'rf_name' => (string)input('post.rf_name', '', null), 'rf_body' => (string)input('post.rf_body', '', null),
            'rf_status' => (int)input('post.rf_status', 1)];
        $res = JcxModel::saveRoot($in, ['can_code' => true]);
        if (!empty($res['code'])) {
            $state = (string)($res['file']['state'] ?? '');
            if ($state === 'exists') { $res['code'] = 0; $res['msg'] = 'admin/ux/jcx/root_exists'; $res['text'] = self::t('root_exists'); $res['field'] = 'rf_name'; }
            elseif ($state === 'notwritable') { $res['text'] = self::t('root_notwritable'); }
            else { $res['text'] = self::t('root_written', JcxBuild::base() . ($res['file']['name'] ?? $in['rf_name'])); }
        }
        return $this->answer($res, ['state' => (string)($res['file']['state'] ?? ''), 'name' => (string)($in['rf_kind'] === 'adstxt' ? 'ads.txt' : $in['rf_name'])]);
    }

    /** jcx/examples: install all, install the video examples only, or remove (op). */
    public function examples()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $op = (string)input('post.op', 'install');
        if ($op === 'remove') {
            $res = JcxBuild::removeExamples();
            return json(['code' => (int)!empty($res['code']), 'msg' => self::t('examples_removed'), 'n' => (int)($res['removed'] ?? 0), '__token__' => mac_admin_csrf_token()]);
        }
        $only = $op === 'video' ? ['X41', 'X42', 'X43', 'X44', 'X45', 'X48', 'X59', 'X60'] : [];
        $res = JcxBuild::installExamples(null, $only);
        if (empty($res['code'])) { return $this->refuse('err_install', '', (string)($res['msg'] ?? '')); }
        $c = JcxBuild::conf();
        JcxModel::settings(['chk' => array_values(array_unique(array_merge(array_map('intval', (array)($c['chk'] ?? [])), [1])))]);
        $n = (int)$this->examplesCount();
        return json(['code' => 1, 'msg' => str_replace('{n}', (string)$n, self::t('examples_installed')), 'n' => $n, 'installed' => (int)$res['installed'],
            'errors' => $res['errors'] ?? [], '__token__' => mac_admin_csrf_token()]);
    }

    /** jcx/reset: reset stats (permission settings). */
    public function reset()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $res = JcxModel::resetStats();
        if (!empty($res['code'])) { $res['text'] = self::t('reset_done'); }
        return $this->answer($res);
    }

    /** jcx/removeall: typed DELETE (permission settings). */
    public function removeall()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $res = JcxModel::removeAll((string)input('post.typed', '', null));
        if (!empty($res['code'])) { $res['text'] = self::t('removed_all'); }
        return $this->answer($res, ['url' => url('jcx/index')]);
    }

    /** Places tab POST: feed settings of P05 and the options of a place (pl, pl_every, pl_max, home_every, home_max). */
    private function placeSave()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $pl = (string)input('post.pl', '', null);
        if (!in_array($pl, JcxModel::PLACES, true)) { return $this->refuse('err_place', 'pl'); }
        $cur = JcxModel::places()['places'][$pl] ?? [];
        $opts = (array)($cur['pl_opts'] ?? []);
        foreach (['home_every', 'home_max'] as $k) { if (input('?post.' . $k)) { $opts[$k] = (int)input('post.' . $k); } }
        $in = ['pl_opts' => $opts];
        foreach (['pl_every', 'pl_max'] as $k) { if (input('?post.' . $k)) { $in[$k] = (int)input('post.' . $k); } }
        $res = JcxModel::savePlace($pl, $in);
        if (!empty($res['code'])) { $res['text'] = self::t('place_saved'); }
        return $this->answer($res, ['place' => $res['place'] ?? null]);
    }

    /** Video ads tab POST: one of the 2 cards as a U15 item in P11 (opts.roll pre | reward; UD4: no mid / post). */
    private function videoSave()
    {
        if (!$this->tokenOk()) { return $this->badToken(); }
        $card = (string)input('post.card', '');
        if (in_array($card, ['mid', 'post'], true)) { return $this->refuse('err_roll'); }
        if (!in_array($card, ['pre', 'reward'], true)) { return $this->refuse('err_unit'); }
        $on = (int)input('post.on', 0) ? 1 : 0;
        $what = (string)input('post.what', 'file') === 'vast' ? 'vast' : 'file';
        $src = trim((string)input('post.src', '', null));
        $vast = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)input('post.vast', '', null)))));
        $media = [];
        if ($what === 'vast') { $media['vast'] = array_slice($vast, 0, 5); }
        elseif ($src !== '') { $media['src'] = $src; }
        $poster = trim((string)input('post.poster', '', null));
        if ($poster !== '') { $media['poster'] = $poster; }
        $opts = ['roll' => $card];
        $skip = (int)input('post.skip', 5);
        $opts['skip'] = in_array($skip, [0, 5, 10], true) ? $skip : 5;
        if ($card === 'reward') { $opts['skip'] = 0; $opts['max_s'] = 30; $opts['reward_h'] = max(1, min(24, (int)input('post.reward_h', 1))); }
        $opts['start'] = (string)input('post.start', 'click') === 'muted' && $card === 'pre' ? 'muted' : 'click';
        if ($card === 'pre' && (int)input('post.bumper', 0)) { $opts['bumper'] = 1; $opts['skip'] = 0; $opts['max_s'] = 6; }
        elseif ($opts['skip'] === 0 && $card !== 'reward') { $opts['max_s'] = 15; }
        $podSrc = trim((string)input('post.pod_src', '', null));
        if ($card === 'pre' && (int)input('post.pod', 0) && $what === 'file' && $src !== '' && $podSrc !== '') {
            $opts['pod'] = 2;
            $media['pod'] = [['src' => $src, 'skip' => $opts['skip']], ['src' => $podSrc, 'skip' => $opts['skip']]];
        }
        if ($on && empty($media['src']) && empty($media['vast'])) { return $this->refuse('err_need_video', 'src'); }
        // How often (UD5, owner decision 2026-10-16): "Every video" (no limit) is the default and the fallback; 'keep' (the
        // "Current: ..." choice of an ad whose stored limit is none of the 4 choices: N per H hours, a gap of 15 min, not on
        // the first page) leaves all 4 limit fields as they are (P2-03: they were rewritten to 30 min / 0).
        $gap = ['every' => 0, '30m' => 30, '1h' => 60, 'day' => 1440];
        $often = (string)input('post.often', 'every');
        $in = ['item_unit' => 'U15', 'item_kind' => $what === 'vast' ? 'vast' : 'video', 'item_place' => 'P11', 'item_media' => $media, 'item_opts' => $opts,
            'item_url' => trim((string)input('post.url', '', null)), 'item_cap_n' => 0, 'item_cap_h' => 0, 'item_cap_gap' => $gap[$often] ?? 0, 'item_skip_first' => 0,
            'item_name' => self::t($card === 'reward' ? 'v_reward' : 'v_before'), 'item_status' => 0];
        if ($often === 'keep' && (int)input('post.item_id', 0) > 0) { unset($in['item_cap_n'], $in['item_cap_h'], $in['item_cap_gap'], $in['item_skip_first']); }
        if ($card === 'reward') { $in['item_text'] = (string)input('post.reward_text', '', null); }
        $id = (int)input('post.item_id', 0);
        if ($id > 0) {
            // R4-02: the card edits its own video ad (same unit, not an example, not a draft); an own name is kept.
            $cur = Db::name('jcx_item')->where('item_id', $id)->where('item_unit', 'U15')->where('item_example', 0)->where('item_ex_code', '<>', self::DRAFT)->find();
            if (!$cur) { return $this->refuse('err_notfound', 'item_id'); }
            $in['item_id'] = $id;
            if (trim((string)$cur['item_name']) !== '' && $cur['item_name'] !== JcxModel::autoName($cur)) { unset($in['item_name']); }
        }
        if (!$id && !$on && empty($media)) { return json(['code' => 1, 'msg' => self::t('v_card_saved'), '__token__' => mac_admin_csrf_token()]); }
        $res = JcxModel::saveItem($in, $this->adminId(), ['can_code' => $this->canCode(), 'switch_on' => $on, 'confirm_conflict' => 1]);
        if (!empty($res['code'])) { $res['text'] = self::t('v_card_saved'); }
        return $this->answer($res, !empty($res['auto_on']) ? ['auto_on_text' => self::t('master_auto_on')] : []);
    }
}
