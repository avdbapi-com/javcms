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
namespace app\common\model;

use think\Db;
use app\common\util\JcxBuild;

/**
 * Ads manager data model (ADS-BRIEF 3): five tables jcx_item / jcx_place / jcx_anchor / jcx_stat / jcx_root.
 * Server half of the double clamp (11.4): every save goes through clean(), which validates, clamps to the
 * Better Ads safe defaults (and the hard limits that stay on with safe limits off) and refuses what cannot be
 * made safe. The runtime clamps again on render.
 *
 * API for the admin controller (12.b) - every method returns an array with 'code' (1 ok, 0 refused):
 *   ready(), install()                                  module readiness / first install
 *   getItem($id), listItems($filter)                    read
 *   saveItem($in, $adminId, $ctx)                       insert or update one item, then JcxBuild::run()
 *   setStatus($ids, $status, $ctx), delItems($ids), duplicate($id, $place, $adminId)
 *   places(), savePlace($code, $in, $ctx)               place switches and feed settings
 *   anchors(), saveAnchor($in), delAnchor($id)          per-theme anchor overrides (CF21 validation)
 *   roots(), saveRoot($in, $ctx), delRoot($id)          ads.txt / push worker / verification files (3.4)
 *   statWrite($triples), stats($days)                   aggregated counters
 *   settings($in, $ctx), resetStats(), removeAll($typed)
 *   conflicts($row)                                     conflict groups pop / float / sw / zone (4.5)
 * $ctx keys: safe (default from jcx.php), can_code (check_auth('jcx','code') result), confirm_conflict,
 *   switch_on (the "Save and switch on" button), typed (typed confirmation text).
 * Values are plain text: decode the admin request once (CONTRACT-FIELDS) before passing them in.
 */
class Jcx extends Base
{
    protected $name = 'jcx_item';
    protected $createTime = '';
    protected $updateTime = '';

    const UNITS = ['U01', 'U02', 'U03', 'U04', 'U05', 'U06', 'U07', 'U08', 'U09', 'U10', 'U11', 'U12', 'U13', 'U14', 'U15', 'U16', 'U17', 'U18', 'U19'];
    const PLACES = ['P01', 'P02', 'P03', 'P03d', 'P04', 'P05', 'P06', 'P07', 'P08', 'P09', 'P10', 'P11', 'P12', 'P13', 'P14', 'P15h', 'P15b', 'P16'];
    const BANNER = ['P01', 'P02', 'P03', 'P03d', 'P04', 'P05', 'P06', 'P07', 'P12', 'P13', 'P14', 'P16'];
    const SLOTS = ['P01', 'P02', 'P03', 'P03d', 'P04', 'P05', 'P06', 'P07', 'P12', 'P13', 'P14', 'P16'];
    const KINDS = ['image', 'html5', 'html', 'code', 'video', 'vast', 'native', 'link', 'text'];
    const UNIT_KINDS = [
        'U01' => ['image', 'html', 'code'], 'U02' => ['image', 'code'], 'U03' => ['image', 'html', 'code'],
        // U06 has no 'video' kind any more: no sticky / pop-out video (11.4 hard limit, R3r3-01)
        'U04' => ['image', 'html', 'code'], 'U05' => ['image'], 'U06' => ['image', 'html', 'code', 'html5'],
        'U07' => ['native', 'code'], 'U08' => ['image', 'video', 'html', 'code', 'html5'], 'U09' => ['image', 'html', 'code', 'html5'],
        'U10' => ['code'], 'U11' => ['code'], 'U12' => ['native', 'code'], 'U13' => ['native', 'code'], 'U14' => ['text'],
        'U15' => ['video', 'vast'], 'U16' => ['video', 'code'], 'U17' => ['html5'], 'U18' => ['image', 'video'], 'U19' => ['link'],
    ];
    const PAGES = ['home', 'list', 'search', 'detail', 'play', 'art', 'artlist', 'other', '404'];
    const PLACE_PAGES = [
        'P01' => ['home', 'list', 'search', 'detail', 'play', 'art', 'artlist', 'other', '404'],
        'P02' => ['home'], 'P03' => ['play'], 'P03d' => ['detail'], 'P04' => ['play'], 'P05' => ['list', 'search', 'home'],
        'P06' => ['home'], 'P07' => ['home', 'list', 'search', 'detail', 'play', 'art', 'artlist', 'other', '404'],
        'P08' => ['home', 'list', 'search', 'detail', 'play', 'art', 'artlist', 'other'],
        'P09' => ['home', 'list', 'search', 'detail', 'play', 'art', 'artlist', 'other'],
        'P10' => ['home', 'list', 'search', 'detail', 'play', 'art', 'artlist', 'other'],
        'P11' => ['play'], 'P12' => ['search'], 'P13' => ['art'], 'P14' => ['list'],
        'P15h' => ['home', 'list', 'search', 'detail', 'play', 'art', 'artlist', 'other', '404'],
        'P15b' => ['home', 'list', 'search', 'detail', 'play', 'art', 'artlist', 'other', '404'],
        'P16' => ['detail', 'play'], '' => ['home', 'list', 'search', 'detail', 'play', 'art', 'artlist', 'other'],
    ];
    const LANGS = ['ar', 'de', 'en', 'es', 'fr', 'hi', 'id', 'ja', 'ko', 'ms', 'pt', 'ru', 'th', 'tl', 'tr', 'vi', 'zh'];
    const PRESETS = ['any', 'slot', 'pop', 'ipp', 'push', 'multi', 'exo', 'inter', 'vast', 'link', 'native', 'sticky', 'adstxt', 'verify', 'house'];
    const NETWORKS = ['exoclick', 'juicyads', 'trafficstars', 'trafficjunky', 'hilltopads', 'clickadu', 'ezmob', 'popcash', 'adsterra', 'richads', 'monetag', 'other'];
    /** Units that stay on the screen while the page scrolls: never a video file (11.4, R3r3-01). */
    const FIXED = ['U03', 'U04', 'U06', 'U07', 'U09'];
    /** Units that can get all ads of a site filtered by Chrome (red chip, risk confirm before switching on). */
    const RISK = ['U09', 'U10'];
    /** Safe-limit caps: [max shows, per hours, min gap minutes] (section 6). */
    const SAFE_CAP = ['U06' => [3, 24, 0], 'U07' => [4, 24, 1], 'U08' => [3, 24, 3], 'U09' => [1, 24, 0]];
    const STAT_EVENTS = 11;
    const ERR = [
        'err_url' => 'The link must start with https:// or http://.',
        'err_alt' => 'Add a text for blind visitors.',
        'err_size' => 'The size is too large for this place.',
        'err_file' => 'This file type is not allowed.',
        'err_zip' => 'The zip has a file type that is not allowed, or no index.html.',
        'err_place' => 'This format cannot go in this place.',
        'err_safe' => 'Changed to stay inside Chrome\'s ad rules (safe limits).',
        'err_unit' => 'This format is not available.',
        'err_kind' => 'This kind of creative does not fit this format.',
        'err_media' => 'This file path is not allowed.',
        'err_hours' => 'Use hours like 08-22.',
        'err_dates' => 'The end date must be after the start date.',
        'err_roll' => 'Video ads can only play before the video: your videos play in the provider\'s player, so an ad during or after the video never shows.',
        'err_fixed_video' => 'Video files can only play in a page slot (in-content video, out-stream or a phone video in the home masthead). A sticky bar, corner box, notification box, side rail or overlay must use a picture: choose a JPG, PNG, WebP or GIF file.',
        'err_days' => 'Pick at least one day.',
        'err_name' => 'This name is not allowed.',
        'err_notfound' => 'This ad does not exist any more.',
        'err_anchor' => 'This place selector is not allowed.',
        'err_theme' => 'This theme is not installed.',
        'code_no_right' => 'Only admins with the right "May paste HTML / JavaScript code" can change this.',
        'code_docwrite' => 'This code writes into the page while it loads. We will print it in the page so it works. It can slow the page a little.',
        'u08_trigger_load' => 'When a page opens (needs safe limits off)',
        'u08_welcome' => 'Once when a visitor arrives (needs safe limits off)',
        'u09_panel_help' => 'With safe limits on, this shows as a small panel at the bottom of the screen. The full-screen version needs safe limits off.',
        'chrome_risk_confirm' => 'Chrome can block all ads on your site if this format annoys visitors. Switch it on anyway?',
        'conflict' => 'Another popunder is already on. Two popunders earn less and can break each other.',
        'conflict_float' => 'Another notification-style box is already on.',
        'conflict_sw' => 'Another push network already owns the site root.',
        'conflict_zone' => 'This zone is already used by "%s".',
        'conflict_tj' => 'Your tracking code already contains a popunder from %s.',
        'help_still' => 'Add a still image for visitors who turn animation off.',
        'empty_space_warn' => 'Visitors who do not match will see an empty space here.',
        'root_exists' => 'A file with this name already exists on your server. Remove it first or choose another name.',
        'root_notwritable' => 'Your site folder is not writable. Download the file and upload it to your site root.',
        'subfolder' => 'ads.txt and push workers only work at the domain root.',
        'set_safe_off' => 'Turning this off can get all ads on your site filtered by Chrome. Type %s to continue.',
        'remove_all_confirm' => 'This deletes all ads, places, stats and root files we created. Type %s to continue.',
        'master_auto_on' => 'Ads on your site are now switched on.',
        'saved_live' => 'Saved. Visitors see it now.',
        'saved_off' => 'Saved. It is off.',
    ];
    const IAB = [[728, 90], [970, 90], [980, 90], [468, 60], [970, 250], [300, 250], [336, 280], [320, 100], [300, 600], [160, 600], [120, 600], [320, 50], [300, 50], [300, 100], [250, 250], [180, 150]];
    /** Known network hosts that mean "a popunder / in-page script" when found in site_tj (3.5). */
    const TJ_HOSTS = ['magsrv.com' => 'ExoClick', 'pemsrv.com' => 'ExoClick', 'popcash' => 'PopCash', 'juicyads' => 'JuicyAds', 'hilltopads' => 'HilltopAds', 'clickadu' => 'Clickadu', 'adsterra' => 'Adsterra', 'trafficstars' => 'TrafficStars', 'ezmob' => 'EZmob', 'qualiclicks' => 'EZmob'];

    /* ------------------------------------------------------------------ messages */

    public static function msg($key, $arg = null)
    {
        $text = self::ERR[$key] ?? $key;
        $full = 'admin/ux/jcx/' . $key;
        $l = function_exists('lang') ? lang($full) : $full;
        if (is_string($l) && $l !== '' && $l !== $full) { $text = $l; }
        if ($arg !== null) { $text = sprintf($text, $arg); }
        return $text;
    }

    private static function fail($key, $field = '', $arg = null, array $extra = [])
    {
        return ['code' => 0, 'msg' => 'admin/ux/jcx/' . $key, 'text' => self::msg($key, $arg), 'field' => $field] + $extra;
    }

    private static function warn(array &$w, $key, $field = '', $arg = null)
    {
        $w[] = ['key' => 'admin/ux/jcx/' . $key, 'text' => self::msg($key, $arg), 'field' => $field];
    }

    /* ------------------------------------------------------------------ readiness */

    public static function ready()
    {
        return JcxBuild::ready();
    }

    public static function install()
    {
        return JcxBuild::install();
    }

    /* ------------------------------------------------------------------ validation (pure, no DB) */

    private static function text($v, $max)
    {
        $v = is_scalar($v) ? (string)$v : '';
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
        if (!is_string($v)) { $v = ''; }
        $v = trim(str_replace(["\r", "\n", "\t"], ' ', $v));
        if (function_exists('mb_substr')) { return mb_substr($v, 0, $max, 'UTF-8'); }
        return substr($v, 0, $max);
    }

    private static function int($v, $min, $max, $def = 0)
    {
        if (is_bool($v)) { $v = (int)$v; }
        if (!is_numeric($v)) { return $def; }
        $v = (int)$v;
        return $v < $min ? $min : ($v > $max ? $max : $v);
    }

    /** Click URL: http(s) only, no control characters, ≤ 500. Returns '' for empty, false for bad. */
    public static function url($v)
    {
        $v = is_scalar($v) ? trim((string)$v) : '';
        if ($v === '') { return ''; }
        if (strlen($v) > 500 || preg_match('/[\x00-\x20\x7F"<>\\\\]/', $v) || !preg_match('~^https?://[^/?#\s]+~i', $v)) { return false; }
        return $v;
    }

    /** Media path: upload/jcx/... or static/jcx/x/... (relative, no traversal), or an http(s) URL when $remote. */
    public static function mediaPath($v, $remote = false)
    {
        $v = is_scalar($v) ? trim((string)$v) : '';
        if ($v === '') { return ''; }
        if ($remote && preg_match('~^https?://~i', $v)) { return self::url($v); }
        $v = ltrim($v, '/');
        if (strlen($v) > 300 || strpos($v, '..') !== false || strpos($v, '//') !== false
            || !preg_match('~^(?:upload/jcx|static/jcx/x)/[A-Za-z0-9._/-]+\.(?:jpe?g|png|gif|webp|mp4|webm|m3u8|xml|svg)$~D', $v)) { return false; }
        if (substr($v, -4) === '.svg' && strpos($v, 'static/jcx/x/') !== 0) { return false; }
        return $v;
    }

    private static function csv($v, ?array $allowed = null, $re = null, $max = 64)
    {
        $list = is_array($v) ? $v : explode(',', (string)$v);
        $out = [];
        foreach ($list as $x) {
            $x = trim((string)$x);
            if ($x === '') { continue; }
            if ($allowed !== null && !in_array($x, $allowed, true)) { continue; }
            if ($re !== null && !preg_match($re, $x)) { continue; }
            $out[$x] = 1;
            if (count($out) >= $max) { break; }
        }
        return implode(',', array_keys($out));
    }

    public static function allowedPlaces($unit, $kind = '')
    {
        $map = [
            'U01' => self::BANNER, 'U02' => self::BANNER, 'U03' => ['P09'], 'U04' => ['P08'], 'U05' => ['P01', 'P02', 'P06'],
            'U06' => ['P10'], 'U07' => ['P10'], 'U08' => ['P10'], 'U09' => ['P10'], 'U10' => ['P15h', 'P15b'], 'U11' => ['P15h'],
            'U12' => ['P05'], 'U13' => ['P03', 'P03d', 'P06', 'P07', 'P13', 'P16'], 'U14' => [''], 'U15' => ['P11'],
            'U16' => ['P03', 'P05', 'P06', 'P13'], 'U17' => self::BANNER, 'U18' => ['P02'], 'U19' => ['P01', 'P03', 'P05', 'P07'],
        ];
        $p = $map[$unit] ?? [];
        if ($unit === 'U01' && in_array($kind, ['code', 'html'], true)) { $p = array_merge($p, ['P15h', 'P15b']); }
        // 9.3 X06 / X12: a single skyscraper may use the rails place (shown as one rail).
        if ($unit === 'U01') { $p[] = 'P08'; }
        return $p;
    }

    private static function opts($unit, $raw, $safe, array &$w)
    {
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        $raw = is_array($raw) ? $raw : [];
        $o = [];
        if (isset($raw['skip'])) { $o['skip'] = in_array((int)$raw['skip'], [0, 5, 10], true) ? (int)$raw['skip'] : 5; }
        if (isset($raw['max_s'])) { $o['max_s'] = self::int($raw['max_s'], 1, 30, 15); }
        if (isset($raw['pod'])) { $o['pod'] = self::int($raw['pod'], 1, 2, 1); }
        if (isset($raw['start'])) { $o['start'] = $raw['start'] === 'muted' ? 'muted' : 'click'; }
        if (isset($raw['every'])) { $o['every'] = self::int($raw['every'], 2, 50, 8); }
        if (isset($raw['max'])) { $o['max'] = self::int($raw['max'], 1, 10, 2); }
        if (isset($raw['close_mem'])) { $o['close_mem'] = $raw['close_mem'] === '24h' ? '24h' : 'session'; }
        if (isset($raw['corner'])) { $o['corner'] = in_array($raw['corner'], ['auto', 'start', 'end'], true) ? $raw['corner'] : 'auto'; }
        if (isset($raw['trigger'])) { $o['trigger'] = in_array($raw['trigger'], ['delay', 'scroll', 'link', 'exit', 'load'], true) ? $raw['trigger'] : 'delay'; }
        if (isset($raw['scroll_pct'])) { $o['scroll_pct'] = self::int($raw['scroll_pct'], 10, 90, 50); }
        if (isset($raw['expand_img'])) { $p = self::mediaPath($raw['expand_img']); if ($p) { $o['expand_img'] = $p; } }
        if (isset($raw['expand_h'])) { $o['expand_h'] = self::int($raw['expand_h'], 50, 600, 250); }
        if (isset($raw['buttons']) && is_array($raw['buttons'])) {
            $b = [];
            foreach (array_slice($raw['buttons'], 0, 3) as $x) {
                $u = self::url($x['url'] ?? '');
                $l = self::text($x['label'] ?? '', 30);
                if ($u && $l !== '') { $b[] = ['label' => $l, 'url' => $u]; }
            }
            if ($b) { $o['buttons'] = $b; }
        }
        if (isset($raw['vertical'])) { $o['vertical'] = $raw['vertical'] ? 1 : 0; }
        if (isset($raw['reward_h'])) { $o['reward_h'] = self::int($raw['reward_h'], 1, 24, 1); }
        if (isset($raw['welcome'])) { $o['welcome'] = $raw['welcome'] ? 1 : 0; }
        if (isset($raw['wallpaper'])) { $p = self::mediaPath($raw['wallpaper']); if ($p) { $o['wallpaper'] = $p; } }
        if (isset($raw['target_id'])) { $o['target_id'] = self::int($raw['target_id'], 0, 2147483647, 0); }
        if (isset($raw['target_type'])) { $o['target_type'] = $raw['target_type'] === 'art' ? 'art' : 'vod'; }
        if (isset($raw['mode'])) { $o['mode'] = $raw['mode'] === 'box' ? 'box' : 'panel'; }
        // UD4: only pre / reward play (before the provider iframe). 'mid' / 'post' are kept here so clean() can refuse them.
        if (isset($raw['roll'])) { $o['roll'] = in_array($raw['roll'], ['pre', 'reward', 'mid', 'post'], true) ? $raw['roll'] : 'pre'; }
        if (isset($raw['layout'])) { $o['layout'] = in_array($raw['layout'], ['grid', 'carousel', 'collection'], true) ? $raw['layout'] : 'grid'; }
        if (isset($raw['bumper'])) { $o['bumper'] = $raw['bumper'] ? 1 : 0; }
        if (isset($raw['promo_vod'])) { $o['promo_vod'] = $raw['promo_vod'] ? 1 : 0; }
        // Unit rules (safe defaults and hard limits).
        if ($unit === 'U15') {
            if (!isset($o['skip'])) { $o['skip'] = 5; }
            if ($o['skip'] === 0) {
                // Non-skippable: at most 15 s per ad (safe and hard: Better Ads video rule), 30 s per break.
                if (!isset($o['max_s']) || $o['max_s'] > 15) { if (isset($o['max_s'])) { self::warn($w, 'err_safe', 'opts.max_s'); } $o['max_s'] = 15; }
            }
            if (isset($o['pod']) && $o['pod'] > 2) { $o['pod'] = 2; }
            if (!isset($o['start'])) { $o['start'] = 'click'; }
        }
        if ($unit === 'U16') { $o['muted'] = 1; unset($o['start']); }
        if ($unit === 'U05') { $o['trigger'] = 'click'; }
        if ($unit === 'U12' || $unit === 'U16') {
            if (isset($o['every']) && $safe && $o['every'] < 6) { $o['every'] = 6; self::warn($w, 'err_safe', 'opts.every'); }
            if (isset($o['max']) && $safe && $o['max'] > 2) { $o['max'] = 2; self::warn($w, 'err_safe', 'opts.max'); }
        }
        if ($unit === 'U09') {
            if (!isset($o['mode'])) { $o['mode'] = 'panel'; }
            if ($safe && $o['mode'] === 'box') { $o['mode'] = 'panel'; self::warn($w, 'u09_panel_help', 'opts.mode'); }
            if (isset($o['trigger']) && !in_array($o['trigger'], ['delay', 'scroll', 'exit'], true)) { $o['trigger'] = 'exit'; }
        }
        if ($unit === 'U06' || $unit === 'U07') {
            if (isset($o['trigger']) && !in_array($o['trigger'], ['delay', 'scroll'], true)) { $o['trigger'] = 'delay'; }
        }
        return $o;
    }

    /**
     * Validate + clamp one item (pure: no DB, no network). $ctx: safe (1), can_code (bool), old (row or null),
     * installer (bool, examples installer only). Returns ['code'=>1,'row'=>[...],'warn'=>[...]] or a refusal.
     */
    public static function clean(array $in, array $ctx = [])
    {
        $safe = !isset($ctx['safe']) || $ctx['safe'];
        $canCode = !empty($ctx['can_code']);
        $old = is_array($ctx['old'] ?? null) ? $ctx['old'] : [];
        $get = function ($k, $d = '') use ($in, $old) { return array_key_exists($k, $in) ? $in[$k] : (array_key_exists($k, $old) ? $old[$k] : $d); };
        $w = [];
        $r = [];
        $unit = (string)$get('item_unit', 'U01');
        if (!in_array($unit, self::UNITS, true)) { return self::fail('err_unit', 'item_unit'); }
        $kind = (string)$get('item_kind', self::UNIT_KINDS[$unit][0]);
        if (!in_array($kind, self::KINDS, true) || !in_array($kind, self::UNIT_KINDS[$unit], true)) { return self::fail('err_kind', 'item_kind'); }
        $place = (string)$get('item_place', '');
        $allowed = self::allowedPlaces($unit, $kind);
        if ($unit === 'U14') { $place = ''; }
        elseif ($place === '' && count($allowed) === 1) { $place = $allowed[0]; }
        if (!in_array($place, $allowed, true)) { return self::fail('err_place', 'item_place'); }
        $r['item_unit'] = $unit;
        $r['item_kind'] = $kind;
        $r['item_place'] = $place;
        $r['item_status'] = (int)$get('item_status', 0) ? 1 : 0;
        $r['item_name'] = self::text($get('item_name', ''), 80);
        $r['item_preset'] = in_array((string)$get('item_preset', ''), self::PRESETS, true) ? (string)$get('item_preset', '') : '';
        $r['item_network'] = in_array((string)$get('item_network', ''), self::NETWORKS, true) ? (string)$get('item_network', '') : '';
        $r['item_weight'] = self::int($get('item_weight', 10), 1, 100, 10);
        $dev = self::int($get('item_dev', 3), 1, 3, 3);
        $r['item_pages'] = self::csv($get('item_pages', ''), self::PLACE_PAGES[$place] ?? []);
        if ($place === 'P10' && $r['item_pages'] === '') { $r['item_pages'] = implode(',', array_diff(self::PLACE_PAGES['P10'], ['search'])); }
        $r['item_cats'] = self::csv($get('item_cats', ''), null, '/^[1-9][0-9]{0,9}$/D', 200);
        $r['item_langs'] = self::csv($get('item_langs', ''), self::LANGS);
        $geo = strtoupper(trim((string)$get('item_geo', '')));
        $neg = $geo !== '' && $geo[0] === '!';
        $geo = self::csv(ltrim($geo, '!'), null, '/^[A-Z]{2}$/D', 80);
        $r['item_geo'] = $geo === '' ? '' : ($neg ? '!' : '') . $geo;
        $r['item_who'] = self::int($get('item_who', 0), 0, 2, 0);
        $r['item_start'] = self::int($get('item_start', 0), 0, 4294967295, 0);
        $r['item_end'] = self::int($get('item_end', 0), 0, 4294967295, 0);
        if ($r['item_start'] && $r['item_end'] && $r['item_end'] <= $r['item_start']) { return self::fail('err_dates', 'item_end'); }
        $days = self::int($get('item_days', 127), 0, 127, 127);
        if ($days === 0) { return self::fail('err_days', 'item_days'); }
        $r['item_days'] = $days;
        $hours = trim((string)$get('item_hours', ''));
        if ($hours !== '' && !preg_match('/^(?:[01][0-9]|2[0-3])-(?:[01][0-9]|2[0-4])$/D', $hours)) { return self::fail('err_hours', 'item_hours'); }
        $r['item_hours'] = $hours;
        $r['item_cap_n'] = self::int($get('item_cap_n', 0), 0, 1000, 0);
        $r['item_cap_h'] = self::int($get('item_cap_h', 0), 0, 8760, 0);
        $r['item_cap_gap'] = self::int($get('item_cap_gap', 0), 0, 10080, 0);
        $r['item_skip_first'] = (int)$get('item_skip_first', 0) ? 1 : 0;
        $r['item_delay'] = self::int($get('item_delay', 0), 0, 600, 0);
        $r['item_w'] = self::int($get('item_w', 0), 0, 1000, 0);
        $r['item_h'] = self::int($get('item_h', 0), 0, 1050, 0);
        $r['item_wm'] = self::int($get('item_wm', 0), 0, 1000, 0);
        $r['item_hm'] = self::int($get('item_hm', 0), 0, 1050, 0);
        foreach (['item_w', 'item_h', 'item_wm', 'item_hm'] as $f) {
            if (array_key_exists($f, $in) && is_numeric($in[$f]) && (int)$in[$f] > ($f[5] === 'w' ? 1000 : 1050)) { self::warn($w, 'err_size', $f); }
        }
        // Media JSON.
        $m = $get('item_media', '');
        if (is_string($m)) { $m = $m === '' ? [] : json_decode($m, true); }
        if (!is_array($m)) { return self::fail('err_media', 'item_media'); }
        $remote = in_array($kind, ['video', 'vast'], true);
        $media = [];
        foreach (['src', 'srcm', 'still', 'poster', 'hls'] as $k) {
            if (!isset($m[$k]) || $m[$k] === '') { continue; }
            $p = self::mediaPath($m[$k], $remote || $k === 'hls');
            if ($p === false) { return self::fail('err_media', 'item_media.' . $k); }
            $media[$k] = $p;
        }
        foreach (['webm', 'srcm2'] as $k) {
            if (!empty($m[$k])) { $p = self::mediaPath($m[$k], true); if ($p === false) { return self::fail('err_media', 'item_media.' . $k); } $media[$k] = $p; }
        }
        if (isset($m['dur'])) { $media['dur'] = self::int($m['dur'], 0, 3600, 0); }
        if (isset($m['type'])) { $media['type'] = self::text($m['type'], 40); }
        if (isset($m['vast']) && is_array($m['vast'])) {
            $v = [];
            foreach (array_slice($m['vast'], 0, 5) as $x) {
                $p = self::mediaPath($x, true);
                if ($p === false) { return self::fail('err_url', 'item_media.vast'); }
                if ($p !== '') { $v[] = $p; }
            }
            if ($v) { $media['vast'] = $v; }
        }
        if (isset($m['pod']) && is_array($m['pod'])) {
            $pod = [];
            foreach (array_slice($m['pod'], 0, 2) as $c) {
                if (!is_array($c)) { continue; }
                $s = self::mediaPath($c['src'] ?? '', true);
                $po = self::mediaPath($c['poster'] ?? '');
                if (!$s || $po === false) { return self::fail('err_media', 'item_media.pod'); }
                $pod[] = ['src' => $s, 'type' => self::text($c['type'] ?? '', 40), 'dur' => self::int($c['dur'] ?? 0, 0, 600, 0),
                    'skip' => in_array((int)($c['skip'] ?? 5), [0, 5, 10], true) ? (int)($c['skip'] ?? 5) : 5, 'poster' => $po];
            }
            if ($pod) { $media['pod'] = $pod; }
        }
        if (isset($m['cards']) && is_array($m['cards'])) {
            $cards = [];
            foreach (array_slice($m['cards'], 0, 10) as $c) {
                if (!is_array($c)) { continue; }
                $img = self::mediaPath($c['img'] ?? '');
                $u = self::url($c['url'] ?? '');
                if ($img === false) { return self::fail('err_media', 'item_media.cards'); }
                if ($u === false) { return self::fail('err_url', 'item_media.cards'); }
                $cards[] = ['img' => $img, 'title' => self::text($c['title'] ?? '', 120), 'url' => $u, 'brand' => self::text($c['brand'] ?? '', 60)];
            }
            if ($cards) { $media['cards'] = $cards; }
        }
        $url = self::url($get('item_url', ''));
        if ($url === false) { return self::fail('err_url', 'item_url'); }
        $r['item_url'] = $url;
        $r['item_alt'] = self::text($get('item_alt', ''), 160);
        $r['item_title'] = self::text($get('item_title', ''), 120);
        $r['item_text'] = self::text($get('item_text', ''), 255);
        $r['item_brand'] = self::text($get('item_brand', ''), 60);
        $r['item_cta'] = self::text($get('item_cta', ''), 30);
        $r['item_note'] = self::text($get('item_note', ''), 255);
        $r['item_sort'] = self::int($get('item_sort', 0), -2147483647, 2147483647, 0);
        // 11.4 hard limit "no sticky / pop-out video" (R3r3-01): a unit that stays on the screen (sticky bar, rails, corner
        // / slide-up, notification box, overlay; any item in P08 / P09) never takes a video file as its picture. In-page
        // slots keep video (U18 phone video in the masthead, X56; U16 out-stream); U08 full-screen video is a modal the
        // visitor closes.
        if (in_array($unit, self::FIXED, true) || in_array($place, ['P08', 'P09'], true)) {
            foreach (['src', 'srcm'] as $k) {
                if (!empty($media[$k]) && preg_match('/\.(?:mp4|webm|m3u8)$/i', $media[$k])) { return self::fail('err_fixed_video', 'item_media.' . $k); }
            }
        }
        if ($kind === 'image') {
            if (empty($media['src']) && empty($media['srcm'])) { return self::fail('err_media', 'item_media.src'); }
            if ($r['item_alt'] === '') { return self::fail('err_alt', 'item_alt'); }
            if ($url === '') { return self::fail('err_url', 'item_url'); }
            foreach (['src', 'srcm'] as $k) {
                if (!empty($media[$k]) && preg_match('/\.gif$/i', $media[$k]) && empty($media['still'])) { self::warn($w, 'help_still', 'item_media.still'); break; }
            }
        }
        if ($kind === 'link' && ($url === '' || $r['item_text'] === '' && $r['item_title'] === '')) { return self::fail($url === '' ? 'err_url' : 'err_name', $url === '' ? 'item_url' : 'item_text'); }
        // Code: right jcx/code, parse mode on document.write (server half, CF11).
        $code = (string)$get('item_code', '');
        $codeM = (string)$get('item_code_m', '');
        if (strlen($code) > 65000 || strlen($codeM) > 65000) { return self::fail('err_size', 'item_code'); }
        $oldCode = (string)($old['item_code'] ?? '') . "\0" . (string)($old['item_code_m'] ?? '');
        $needsRight = trim($code) !== '' || trim($codeM) !== '' || in_array($kind, ['html', 'code', 'html5'], true) || $place === 'P15h' || $place === 'P15b';
        if ($needsRight && !$canCode) {
            // Without the right a role may still change other fields of an item whose code it does not touch.
            $untouched = $old && ($code . "\0" . $codeM) === $oldCode && (string)($old['item_kind'] ?? '') === $kind && (string)($old['item_place'] ?? '') === $place;
            if (!$untouched) { return self::fail('code_no_right', 'item_code'); }
        }
        if (in_array($kind, ['html', 'code'], true) && trim($code) === '' && trim($codeM) === '') { return self::fail('err_kind', 'item_code'); }
        $r['item_code'] = $code;
        $r['item_code_m'] = $codeM;
        $mode = self::int($get('item_code_mode', 0), 0, 2, 0);
        if (preg_match('/document\s*\.\s*write/i', $code . $codeM)) {
            if ($mode !== 1) { self::warn($w, 'code_docwrite', 'item_code_mode'); }
            $mode = 1;
        }
        $r['item_code_mode'] = $mode;
        $opts = self::opts($unit, $get('item_opts', ''), $safe, $w);
        // UD4: every video plays in the provider's iframe player, so a mid-roll / post-roll can never show: refused
        // (new items, edits and switching an old one on). Switching off and deleting never pass through clean().
        if (in_array($opts['roll'] ?? 'pre', ['mid', 'post'], true)) { return self::fail('err_roll', 'opts.roll'); }
        // ---- Better Ads safe defaults (safe on) + hard limits (always) ----
        if ($unit === 'U03') {
            // Sticky bar: phone ≤ 60 px (hard 100), computer ≤ 20 % of a 900 px viewport = 180 (hard 30 % = 270).
            $hm = $safe ? 60 : 100;
            $hd = $safe ? 180 : 270;
            if ($r['item_hm'] > $hm) { $r['item_hm'] = $hm; self::warn($w, $safe ? 'err_safe' : 'err_size', 'item_hm'); }
            if ($r['item_h'] > $hd) { $r['item_h'] = $hd; self::warn($w, $safe ? 'err_safe' : 'err_size', 'item_h'); }
        }
        if ($unit === 'U08') {
            $trig = $opts['trigger'] ?? 'link';
            $welcome = !empty($opts['welcome']);
            if ($safe && $trig === 'load') { return self::fail('u08_trigger_load', 'opts.trigger'); }
            if ($safe && $welcome) { return self::fail('u08_welcome', 'opts.welcome'); }
            if (!in_array($trig, ['link', 'load'], true)) { $trig = 'link'; }
            $opts['trigger'] = $trig;
            // Hard limit: never a prestitial on phones; "when a page opens" / welcome are computer only (CF01).
            if (($trig === 'load' || $welcome) && ($dev & 2)) { $dev = 1; self::warn($w, 'err_safe', 'item_dev'); }
        }
        if ($unit === 'U09') {
            if ($dev !== 1) { $dev = 1; }
            // Never on watch pages.
            $pg = $r['item_pages'] === '' ? self::PLACE_PAGES['P10'] : explode(',', $r['item_pages']);
            $pg = array_values(array_diff($pg, ['play']));
            $r['item_pages'] = implode(',', $pg);
        }
        if (isset(self::SAFE_CAP[$unit]) && $safe && $r['item_cap_n'] > 0) {
            list($mn, $mh, $mg) = self::SAFE_CAP[$unit];
            if ($r['item_cap_h'] < $mh) { $r['item_cap_h'] = $mh; self::warn($w, 'err_safe', 'item_cap_h'); }
            $maxN = (int)floor($mn * $r['item_cap_h'] / $mh);
            if ($r['item_cap_n'] > $maxN) { $r['item_cap_n'] = $maxN; self::warn($w, 'err_safe', 'item_cap_n'); }
            if ($r['item_cap_gap'] < $mg) { $r['item_cap_gap'] = $mg; }
        }
        if ($unit === 'U15' && isset($opts['max_s'])) {
            // Hard: non-skippable ≤ 30 s per break.
            $pod = $opts['pod'] ?? 1;
            if (($opts['skip'] ?? 5) === 0 && $opts['max_s'] * $pod > 30) { $opts['max_s'] = (int)floor(30 / $pod); }
        }
        $r['item_dev'] = $dev;
        $r['item_opts'] = $opts ? json_encode($opts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $r['item_media'] = $media ? json_encode($media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        // Risky units need the Chrome-risk confirm before they can be on.
        $risky = in_array($unit, self::RISK, true) || ($unit === 'U08' && ($dev & 2)) || ($unit === 'U08' && (($opts['trigger'] ?? '') === 'load' || !empty($opts['welcome'])));
        $r['item_risk_ok'] = (int)$get('item_risk_ok', 0) ? 1 : 0;
        if ($risky && $r['item_status'] === 1 && !$r['item_risk_ok']) { return self::fail('chrome_risk_confirm', 'item_risk_ok', null, ['need_risk' => 1]); }
        // Conflict groups (4.5).
        $r['item_group'] = self::groups($r);
        // Examples: rows of the installer keep example=1; an edit of media / link / code turns them into normal items.
        if (!empty($ctx['installer'])) {
            $r['item_example'] = 1;
            $r['item_ex_code'] = preg_match('/^X[0-9]{2}$/D', (string)($in['item_ex_code'] ?? '')) ? $in['item_ex_code'] : '';
            $r['item_status'] = 0;
        } elseif ($old) {
            $r['item_example'] = (int)($old['item_example'] ?? 0);
            $r['item_ex_code'] = (string)($old['item_ex_code'] ?? '');
            if ($r['item_example'] && ($r['item_media'] !== (string)($old['item_media'] ?? '') || $r['item_url'] !== (string)($old['item_url'] ?? '')
                || ($code . "\0" . $codeM) !== $oldCode)) {
                $r['item_example'] = 0;
            }
        } else {
            $r['item_example'] = 0;
            $r['item_ex_code'] = '';
        }
        if ($r['item_example'] && $r['item_status']) { $r['item_status'] = 0; }
        if ($r['item_name'] === '') { $r['item_name'] = self::autoName($r); }
        return ['code' => 1, 'row' => $r, 'warn' => $w];
    }

    public static function autoName(array $r)
    {
        $names = ['U01' => 'Banner', 'U02' => 'Banner pair', 'U03' => 'Sticky bar', 'U04' => 'Side rails', 'U05' => 'Expandable banner',
            'U06' => 'Floating corner', 'U07' => 'Notification-style box', 'U08' => 'Full-screen between pages', 'U09' => 'Overlay',
            'U10' => 'Popunder (network)', 'U11' => 'Web push (network)', 'U12' => 'Card in video lists', 'U13' => 'Recommendation widget',
            'U14' => 'Sponsored post', 'U15' => 'Video ad', 'U16' => 'Video in the page', 'U17' => 'HTML5 creative', 'U18' => 'Home masthead', 'U19' => 'Text link'];
        $n = $names[$r['item_unit']] ?? 'Ad';
        if (!empty($r['item_w']) && !empty($r['item_h'])) { $n .= ' ' . $r['item_w'] . "\u{00D7}" . $r['item_h']; }
        if (!empty($r['item_place'])) { $n .= " \u{00B7} " . $r['item_place']; }
        return self::text($n, 80);
    }

    /** Conflict groups: pop, float, sw, zone:<sha1(network+zone id)>. */
    public static function groups(array $r)
    {
        $g = [];
        $u = $r['item_unit'];
        $p = $r['item_preset'] ?? '';
        if ($u === 'U10' || $p === 'pop' || $p === 'multi') { $g[] = 'pop'; }
        if ($p === 'ipp' || $p === 'multi' || $p === 'inter' || ($u === 'U07' && $r['item_kind'] === 'code')) { $g[] = 'float'; }
        if ($u === 'U11' || $p === 'push') { $g[] = 'sw'; }
        $code = (string)($r['item_code'] ?? '') . ' ' . (string)($r['item_code_m'] ?? '');
        if (preg_match('/data-zoneid\s*=\s*["\']?([0-9]{3,12})/i', $code, $mm) || preg_match('/idzone\s*[=:]\s*["\']?([0-9]{3,12})/i', $code, $mm)) {
            $g[] = 'zone:' . sha1(($r['item_network'] ?: 'exoclick') . ':' . $mm[1]);
        }
        return implode(',', array_unique($g));
    }

    /** Suggested size snapped to the nearest IAB size (4.3). */
    public static function snap($w, $h)
    {
        $best = [$w, $h];
        $bd = PHP_INT_MAX;
        foreach (self::IAB as $s) {
            $d = abs($s[0] - $w) + abs($s[1] - $h);
            if ($d < $bd) { $bd = $d; $best = $s; }
        }
        return $bd <= 12 ? $best : [$w, $h];
    }

    /* ------------------------------------------------------------------ DB API */

    private static function guard()
    {
        if (!JcxBuild::ready()) {
            $res = JcxBuild::install();
            if (empty($res['code'])) { throw new \RuntimeException('jcx not installed: ' . ($res['msg'] ?? '')); }
        }
    }

    public static function getItem($id)
    {
        self::guard();
        $row = Db::name('jcx_item')->where('item_id', (int)$id)->find();
        return $row ? ['code' => 1, 'item' => $row] : self::fail('err_notfound');
    }

    /** $filter: status (0|1), example (0|1), unit, place, kind, q (name contains), code (1 = network code), video (1), ending (1), page, limit. */
    public static function listItems(array $f = [])
    {
        self::guard();
        $q = Db::name('jcx_item');
        if (isset($f['status']) && $f['status'] !== '') { $q->where('item_status', (int)$f['status']); }
        if (isset($f['example']) && $f['example'] !== '') { $q->where('item_example', (int)$f['example']); }
        if (!empty($f['unit']) && in_array($f['unit'], self::UNITS, true)) { $q->where('item_unit', $f['unit']); }
        if (!empty($f['place']) && in_array($f['place'], self::PLACES, true)) { $q->where('item_place', $f['place']); }
        if (!empty($f['kind']) && in_array($f['kind'], self::KINDS, true)) { $q->where('item_kind', $f['kind']); }
        if (!empty($f['code'])) { $q->where('item_kind', 'in', ['code', 'html']); }
        if (!empty($f['video'])) { $q->where('item_unit', 'in', ['U15', 'U16']); }
        if (!empty($f['ending'])) { $q->where('item_end', '>', time())->where('item_end', '<=', time() + 7 * 86400); }
        if (!empty($f['q']) && is_string($f['q'])) { $q->where('item_name', 'like', '%' . addcslashes(self::text($f['q'], 80), '%_') . '%'); }
        $limit = self::int($f['limit'] ?? 500, 1, 2000, 500);
        $page = self::int($f['page'] ?? 1, 1, 100000, 1);
        $list = $q->order('item_sort desc,item_id desc')->limit(($page - 1) * $limit, $limit)->select();
        $list = is_array($list) ? $list : (method_exists($list, 'toArray') ? $list->toArray() : []);
        return ['code' => 1, 'list' => $list, 'share' => self::shares($list)];
    }

    /** "about N % of page views" per item: weight / sum of weights of enabled items in the same place (4.6). */
    public static function shares(array $list)
    {
        $sum = [];
        foreach ($list as $r) { if ($r['item_status'] && !$r['item_example']) { $k = $r['item_place']; $sum[$k] = ($sum[$k] ?? 0) + $r['item_weight']; } }
        $out = [];
        foreach ($list as $r) {
            $s = $sum[$r['item_place']] ?? 0;
            $out[$r['item_id']] = $r['item_status'] && $s ? (int)round(100 * $r['item_weight'] / $s) : 0;
        }
        return $out;
    }

    /**
     * Insert or update one item. $in: item_* fields (+ item_id to update). Returns
     * ['code'=>1,'id'=>..,'row'=>..,'warn'=>[..],'build'=>JcxBuild::run() result,'auto_on'=>bool,'msg'=>saved_live|saved_off]
     * or a refusal ['code'=>0,'msg'=>key,'text'=>..,'field'=>..] / conflict ['code'=>0,'need_confirm'=>1,'conflicts'=>[..]].
     */
    public static function saveItem(array $in, $adminId = 0, array $ctx = [])
    {
        self::guard();
        $conf = JcxBuild::conf();
        if (!isset($ctx['safe'])) { $ctx['safe'] = (int)$conf['safe']; }
        $id = (int)($in['item_id'] ?? 0);
        $old = null;
        if ($id > 0) {
            $old = Db::name('jcx_item')->where('item_id', $id)->find();
            if (!$old) { return self::fail('err_notfound', 'item_id'); }
        }
        if (!empty($ctx['switch_on'])) { $in['item_status'] = 1; }
        $ctx['old'] = $old;
        $c = self::clean($in, $ctx);
        if (!$c['code']) { return $c; }
        $row = $c['row'];
        if ($row['item_kind'] === 'image' && (!$row['item_w'] || !$row['item_h'])) {
            $m = json_decode($row['item_media'], true) ?: [];
            foreach ([['src', 'item_w', 'item_h'], ['srcm', 'item_wm', 'item_hm']] as $t) {
                if (!empty($m[$t[0]]) && !$row[$t[1]] && is_file(ROOT_PATH . $m[$t[0]])) {
                    $sz = @getimagesize(ROOT_PATH . $m[$t[0]]);
                    if ($sz) { $row[$t[1]] = min(1000, (int)$sz[0]); $row[$t[2]] = min(1050, (int)$sz[1]); }
                }
            }
        }
        if ($row['item_status'] && empty($ctx['confirm_conflict'])) {
            $cf = self::conflicts($row + ['item_id' => $id]);
            if ($cf) { return ['code' => 0, 'need_confirm' => 1, 'msg' => 'admin/ux/jcx/conflict', 'text' => $cf[0]['text'], 'conflicts' => $cf]; }
        }
        $now = time();
        $row['item_edit'] = $now;
        $row['item_admin'] = (int)$adminId;
        if ($old) {
            Db::name('jcx_item')->where('item_id', $id)->update($row);
        } else {
            $row['item_add'] = $now;
            $id = (int)Db::name('jcx_item')->insertGetId($row);
        }
        $autoOn = false;
        if ($row['item_status'] && !empty($ctx['switch_on']) && empty($conf['on'])) {
            JcxBuild::confSet(['on' => 1]);
            $autoOn = true;
        }
        $build = JcxBuild::run();
        $saved = Db::name('jcx_item')->where('item_id', $id)->find();
        return ['code' => 1, 'id' => $id, 'row' => $saved, 'warn' => $c['warn'], 'build' => $build, 'auto_on' => $autoOn,
            'msg' => $row['item_status'] ? 'admin/ux/jcx/saved_live' : 'admin/ux/jcx/saved_off', 'text' => self::msg($row['item_status'] ? 'saved_live' : 'saved_off')];
    }

    /** Switch items on / off. Risky items need $ctx['risk_ok']; conflicts need $ctx['confirm_conflict']. */
    public static function setStatus($ids, $status, array $ctx = [])
    {
        self::guard();
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (!$ids) { return self::fail('err_notfound'); }
        $status = $status ? 1 : 0;
        $conf = JcxBuild::conf();
        $done = [];
        // Q8: every selected row is checked first (rules, then conflicts) and only then written, so one refused row
        // never leaves the others half switched on.
        $todo = [];
        $seen = [];
        foreach (Db::name('jcx_item')->where('item_id', 'in', $ids)->select() as $r) {
            if ($status && $r['item_example']) { continue; }
            $risk = (int)$r['item_risk_ok'];
            if ($status) {
                $in = ['item_status' => 1];
                if (!empty($ctx['risk_ok'])) { $in['item_risk_ok'] = 1; }
                $c = self::clean($in, ['old' => $r, 'safe' => (int)$conf['safe'], 'can_code' => true]);
                if (!$c['code']) { return $c + ['item_id' => (int)$r['item_id']]; }
                $risk = (int)$c['row']['item_risk_ok'];
                if (empty($ctx['confirm_conflict'])) {
                    $cf = self::conflicts($c['row'] + ['item_id' => $r['item_id']]);
                    // rows of this same batch count as switched on too (the writes come after all checks)
                    $mine = array_filter(explode(',', (string)($c['row']['item_group'] ?? '')));
                    foreach ($seen as $o) {
                        foreach (array_intersect($mine, $o[2]) as $g) {
                            $k = $g === 'pop' ? 'conflict' : ($g === 'float' ? 'conflict_float' : ($g === 'sw' ? 'conflict_sw' : 'conflict_zone'));
                            $cf[] = ['group' => $g, 'text' => self::msg($k, $k === 'conflict_zone' ? $o[1] : null), 'items' => [[$o[0], $o[1]]]];
                        }
                    }
                    if ($cf) { return ['code' => 0, 'need_confirm' => 1, 'msg' => 'admin/ux/jcx/conflict', 'text' => $cf[0]['text'], 'conflicts' => array_values($cf), 'item_id' => (int)$r['item_id']]; }
                    $seen[] = [(int)$r['item_id'], (string)$r['item_name'], $mine];
                }
            }
            $todo[(int)$r['item_id']] = $risk;
        }
        foreach ($todo as $id => $risk) {
            Db::name('jcx_item')->where('item_id', $id)->update($status ? ['item_status' => 1, 'item_risk_ok' => $risk, 'item_edit' => time()] : ['item_status' => 0, 'item_edit' => time()]);
            $done[] = $id;
        }
        $autoOn = false;
        if ($status && $done && !empty($ctx['switch_on']) && empty($conf['on'])) { JcxBuild::confSet(['on' => 1]); $autoOn = true; }
        return ['code' => 1, 'ids' => $done, 'auto_on' => $autoOn, 'build' => JcxBuild::run()];
    }

    public static function delItems($ids)
    {
        self::guard();
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (!$ids) { return self::fail('err_notfound'); }
        $n = Db::name('jcx_item')->where('item_id', 'in', $ids)->delete();
        foreach ($ids as $id) { JcxBuild::rmTree(APP_PATH . 'data/jcx/z/' . $id); }
        return ['code' => 1, 'deleted' => (int)$n, 'build' => JcxBuild::run()];
    }

    /**
     * "Duplicate" copies an item (off) into another place allowed for its unit. $canCode = the caller's jcx/code right
     * (R2-F1): without it a copy that carries code is refused like any other save. An HTML5 item's unpacked files
     * application/data/jcx/z/<id>/ are copied to the new id, so the copy is not an empty frame (L1, R1-09).
     */
    public static function duplicate($id, $place = '', $adminId = 0, $canCode = true)
    {
        self::guard();
        $r = Db::name('jcx_item')->where('item_id', (int)$id)->find();
        if (!$r) { return self::fail('err_notfound'); }
        $from = (int)$r['item_id'];
        unset($r['item_id']);
        $r['item_status'] = 0;
        $r['item_example'] = 0;
        if ((string)($r['item_ex_code'] ?? '') !== '') { $r['item_ex_code'] = ''; }
        if ($place !== '') { $r['item_place'] = $place; }
        $r['item_name'] = self::text($r['item_name'] . ' (2)', 80);
        $res = self::saveItem($r, $adminId, ['can_code' => (bool)$canCode, 'confirm_conflict' => 1]);
        if (!empty($res['code']) && $r['item_kind'] === 'html5') {
            $z = APP_PATH . 'data/jcx/z/';
            if (is_dir($z . $from) && !JcxBuild::copyTree($z . $from, $z . (int)$res['id'])) { $res['warn'][] = ['msg' => 'admin/ux/jcx/err_zip', 'field' => 'file']; }
        }
        return $res;
    }

    /** Conflicts of $row with enabled items (and pop hosts in site_tj). Returns a list of ['group','text','items'=>[[id,name]]]. */
    public static function conflicts(array $row)
    {
        $out = [];
        $groups = array_filter(explode(',', (string)($row['item_group'] ?? '')));
        if ($groups) {
            $q = Db::name('jcx_item')->where('item_status', 1)->where('item_example', 0);
            if (!empty($row['item_id'])) { $q->where('item_id', '<>', (int)$row['item_id']); }
            foreach ($q->field('item_id,item_name,item_group')->select() as $o) {
                $og = array_filter(explode(',', (string)$o['item_group']));
                foreach (array_intersect($groups, $og) as $g) {
                    $key = $g === 'pop' ? 'conflict' : ($g === 'float' ? 'conflict_float' : ($g === 'sw' ? 'conflict_sw' : 'conflict_zone'));
                    $out[$g]['group'] = $g;
                    $out[$g]['text'] = self::msg($key, $key === 'conflict_zone' ? $o['item_name'] : null);
                    $out[$g]['items'][] = [(int)$o['item_id'], $o['item_name']];
                }
            }
        }
        if (in_array('pop', $groups, true)) {
            $tj = strtolower(html_entity_decode((string)JcxBuild::site('site_tj'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            foreach (self::TJ_HOSTS as $h => $name) {
                if ($tj !== '' && strpos($tj, $h) !== false) { $out['tj'] = ['group' => 'tj', 'text' => self::msg('conflict_tj', $name), 'items' => []]; break; }
            }
        }
        return array_values($out);
    }

    /* ---- places ---- */

    public static function places()
    {
        self::guard();
        $rows = [];
        foreach (Db::name('jcx_place')->select() as $r) { $r['pl_opts'] = json_decode((string)$r['pl_opts'], true) ?: []; $rows[$r['pl_code']] = $r; }
        $count = [];
        foreach (Db::name('jcx_item')->where('item_example', 0)->field('item_place,item_status,count(*) n')->group('item_place,item_status')->select() as $c) {
            $count[$c['item_place']][$c['item_status'] ? 'on' : 'off'] = (int)$c['n'];
        }
        $out = [];
        foreach (self::PLACES as $p) { $out[$p] = ($rows[$p] ?? ['pl_code' => $p, 'pl_status' => 1, 'pl_every' => 0, 'pl_max' => 0, 'pl_opts' => []]) + ['items' => $count[$p] ?? []]; }
        return ['code' => 1, 'places' => $out, 'miss' => JcxBuild::missLog()];
    }

    /** $in: pl_status, pl_every, pl_max, pl_opts (array). Feed: safe minimum every 6, max 2 (D39, 11.4). */
    public static function savePlace($code, array $in, array $ctx = [])
    {
        self::guard();
        if (!in_array($code, self::PLACES, true)) { return self::fail('err_place'); }
        $conf = JcxBuild::conf();
        $safe = isset($ctx['safe']) ? (bool)$ctx['safe'] : (bool)$conf['safe'];
        $old = Db::name('jcx_place')->where('pl_code', $code)->find() ?: ['pl_status' => 1, 'pl_every' => 0, 'pl_max' => 0, 'pl_opts' => ''];
        $w = [];
        $r = ['pl_code' => $code, 'pl_edit' => time()];
        $r['pl_status'] = array_key_exists('pl_status', $in) ? ((int)$in['pl_status'] ? 1 : 0) : (int)$old['pl_status'];
        $r['pl_every'] = self::int($in['pl_every'] ?? $old['pl_every'], 0, 50, 0);
        $r['pl_max'] = self::int($in['pl_max'] ?? $old['pl_max'], 0, 10, 0);
        $opts = array_key_exists('pl_opts', $in) ? (is_array($in['pl_opts']) ? $in['pl_opts'] : (json_decode((string)$in['pl_opts'], true) ?: [])) : (json_decode((string)$old['pl_opts'], true) ?: []);
        $o = [];
        foreach (['phone_play' => [0, 1], 'home_every' => [2, 50], 'home_max' => [0, 10], 'min_gutter' => [120, 600], 'after_p' => [1, 20]] as $k => $lim) {
            if (isset($opts[$k])) { $o[$k] = self::int($opts[$k], $lim[0], $lim[1], $lim[0]); }
        }
        if (isset($opts['close_mem'])) { $o['close_mem'] = $opts['close_mem'] === '24h' ? '24h' : 'session'; }
        if ($code === 'P05') {
            $min = $safe ? 6 : 2;
            if ($r['pl_every'] && $r['pl_every'] < $min) { $r['pl_every'] = $min; self::warn($w, 'err_safe', 'pl_every'); }
            if ($safe && $r['pl_max'] > 2) { $r['pl_max'] = 2; self::warn($w, 'err_safe', 'pl_max'); }
            if (isset($o['home_every']) && $o['home_every'] < ($safe ? 4 : 2)) { $o['home_every'] = $safe ? 4 : 2; self::warn($w, 'err_safe', 'home_every'); }
            if ($safe && isset($o['home_max']) && $o['home_max'] > 1) { $o['home_max'] = 1; self::warn($w, 'err_safe', 'home_max'); }
        }
        $r['pl_opts'] = $o ? json_encode($o) : '';
        if (Db::name('jcx_place')->where('pl_code', $code)->find()) { Db::name('jcx_place')->where('pl_code', $code)->update($r); }
        else { Db::name('jcx_place')->insert($r); }
        return ['code' => 1, 'place' => $r, 'warn' => $w, 'build' => JcxBuild::run()];
    }

    /* ---- anchors (CF21) ---- */

    const ANCHOR_RE = '/^(?:@player|@grid:\d{1,2}|@section-of-grid:\d{1,2}|[A-Za-z0-9_#.\-\[\]= ]{1,200})$/D';

    public static function anchors()
    {
        self::guard();
        return ['code' => 1, 'list' => Db::name('jcx_anchor')->order('anc_theme,anc_place')->select()];
    }

    public static function saveAnchor(array $in)
    {
        self::guard();
        $theme = (string)($in['anc_theme'] ?? '');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $theme) || !is_file(ROOT_PATH . 'template/' . $theme . '/info.ini')) { return self::fail('err_theme', 'anc_theme'); }
        $place = (string)($in['anc_place'] ?? '');
        if (!in_array($place, self::SLOTS, true)) { return self::fail('err_place', 'anc_place'); }
        $at = trim((string)($in['anc_at'] ?? ''));
        if ($at === '' || strlen($at) > 200 || !preg_match(self::ANCHOR_RE, $at)) { return self::fail('err_anchor', 'anc_at'); }
        $pos = (string)($in['anc_pos'] ?? 'afterend');
        if (!in_array($pos, ['beforebegin', 'afterbegin', 'beforeend', 'afterend'], true)) { return self::fail('err_anchor', 'anc_pos'); }
        $r = ['anc_theme' => $theme, 'anc_place' => $place, 'anc_at' => $at, 'anc_pos' => $pos,
            'anc_nth' => self::int($in['anc_nth'] ?? 1, 0, 50, 1), 'anc_every' => self::int($in['anc_every'] ?? 0, 0, 50, 0),
            'anc_max' => self::int($in['anc_max'] ?? 0, 0, 50, 0), 'anc_status' => isset($in['anc_status']) ? ((int)$in['anc_status'] ? 1 : 0) : 1, 'anc_edit' => time()];
        $old = Db::name('jcx_anchor')->where(['anc_theme' => $theme, 'anc_place' => $place])->find();
        if ($old) { Db::name('jcx_anchor')->where('anc_id', $old['anc_id'])->update($r); $id = (int)$old['anc_id']; }
        else { $id = (int)Db::name('jcx_anchor')->insertGetId($r); }
        return ['code' => 1, 'id' => $id, 'build' => JcxBuild::run()];
    }

    public static function delAnchor($id)
    {
        self::guard();
        Db::name('jcx_anchor')->where('anc_id', (int)$id)->delete();
        return ['code' => 1, 'build' => JcxBuild::run()];
    }

    /* ---- root files (3.4) ---- */

    public static function roots()
    {
        self::guard();
        return ['code' => 1, 'list' => Db::name('jcx_root')->select(), 'subfolder' => trim((string)(defined('MAC_PATH') ? MAC_PATH : '/'), '/') !== ''];
    }

    /** $in: rf_kind adstxt|sw|verify, rf_name, rf_body, rf_status. Needs the code right. Writes / removes the file. */
    public static function saveRoot(array $in, array $ctx = [])
    {
        self::guard();
        if (empty($ctx['can_code'])) { return self::fail('code_no_right', 'rf_body'); }
        $kind = (string)($in['rf_kind'] ?? '');
        $name = trim((string)($in['rf_name'] ?? ''));
        if ($kind === 'adstxt') { $name = 'ads.txt'; }
        elseif ($kind === 'sw') { if (!preg_match('/^[A-Za-z0-9._-]{1,64}\.js$/D', $name)) { return self::fail('err_name', 'rf_name'); } }
        elseif ($kind === 'verify') { if (!preg_match('/^[A-Za-z0-9._-]{1,64}\.(?:txt|html)$/D', $name)) { return self::fail('err_name', 'rf_name'); } }
        else { return self::fail('err_name', 'rf_kind'); }
        if (!JcxBuild::rootNameAllowed($name)) { return self::fail('err_name', 'rf_name'); }
        $body = (string)($in['rf_body'] ?? '');
        if (strlen($body) > 262144) { return self::fail('err_size', 'rf_body'); }
        $status = (int)($in['rf_status'] ?? 1) ? 1 : 0;
        $old = Db::name('jcx_root')->where('rf_name', $name)->find();
        if ($kind === 'sw' && !$old) {
            if (Db::name('jcx_root')->where('rf_kind', 'sw')->find()) { return self::fail('conflict_sw', 'rf_name'); }
        }
        $r = ['rf_kind' => $kind, 'rf_name' => $name, 'rf_body' => $body, 'rf_status' => $status, 'rf_edit' => time()];
        if ($old) { Db::name('jcx_root')->where('rf_id', $old['rf_id'])->update($r); $id = (int)$old['rf_id']; }
        else { $r['rf_sha'] = ''; $id = (int)Db::name('jcx_root')->insertGetId($r); }
        $res = JcxBuild::rootFiles();
        $mine = $res[$name] ?? ['state' => 'unknown'];
        $out = ['code' => 1, 'id' => $id, 'file' => $mine, 'files' => $res];
        if ($mine['state'] === 'exists') { $out['warn'] = [['key' => 'admin/ux/jcx/root_exists', 'text' => self::msg('root_exists')]]; }
        if ($mine['state'] === 'notwritable') { $out['warn'] = [['key' => 'admin/ux/jcx/root_notwritable', 'text' => self::msg('root_notwritable')]]; $out['download'] = $body; }
        if (trim((string)(defined('MAC_PATH') ? MAC_PATH : '/'), '/') !== '') { $out['warn'][] = ['key' => 'admin/ux/jcx/subfolder', 'text' => self::msg('subfolder')]; }
        return $out;
    }

    public static function delRoot($id)
    {
        self::guard();
        $r = Db::name('jcx_root')->where('rf_id', (int)$id)->find();
        if (!$r) { return self::fail('err_notfound'); }
        Db::name('jcx_root')->where('rf_id', (int)$id)->update(['rf_status' => 0]);
        JcxBuild::rootFiles();
        Db::name('jcx_root')->where('rf_id', (int)$id)->delete();
        return ['code' => 1];
    }

    /* ---- uploads (11.1) ---- */

    const UPLOAD = ['jpg' => ['image/jpeg', 2097152], 'jpeg' => ['image/jpeg', 2097152], 'png' => ['image/png', 2097152], 'gif' => ['image/gif', 2097152],
        'webp' => ['image/webp', 2097152], 'mp4' => ['video/mp4', 20971520], 'webm' => ['video/webm', 20971520], 'zip' => ['application/zip', 1048576]];

    /**
     * Store one uploaded creative: $file = one $_FILES entry (name, tmp_name, size, error). Extension AND finfo MIME
     * whitelist, size limits (images 2 MB with dimensions read, video 20 MB, zip 1 MB), first / last 512 bytes scanned
     * like Upload.php (not zips), fixed folder upload/jcx/<Ymd>-<n>/<md5>.<ext> (no client folder), local storage only.
     * A zip is not stored under upload/: pass $itemId and it is unpacked to application/data/jcx/z/<id>/ (JcxBuild::unpackZip).
     * Returns ['code'=>1,'path'=>'upload/jcx/...','w'=>..,'h'=>..,'ext'=>..] (zip: ['code'=>1,'files'=>n]) or a refusal.
     */
    public static function upload(array $file, $itemId = 0, $isUploaded = true)
    {
        $name = (string)($file['name'] ?? '');
        $tmp = (string)($file['tmp_name'] ?? '');
        if ((int)($file['error'] ?? 0) !== 0 || $tmp === '' || !is_file($tmp) || ($isUploaded && !is_uploaded_file($tmp))) { return self::fail('err_file', 'file'); }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::UPLOAD[$ext])) { return self::fail('err_file', 'file'); }
        $size = (int)filesize($tmp);
        if ($size < 1 || $size > self::UPLOAD[$ext][1]) { return self::fail('err_size', 'file'); }
        $mime = function_exists('finfo_open') ? (string)finfo_file($f = finfo_open(FILEINFO_MIME_TYPE), $tmp) : '';
        if (isset($f) && $f) { finfo_close($f); }
        $okMime = [self::UPLOAD[$ext][0]];
        if ($ext === 'zip') { $okMime[] = 'application/x-zip-compressed'; }
        if ($ext === 'webm') { $okMime[] = 'audio/webm'; }
        if (!in_array($mime, $okMime, true)) { return self::fail('err_file', 'file'); }
        if ($ext === 'zip') {
            // only for an existing item (R2-F6: no orphan application/data/jcx/z/<id>/ folders)
            if ((int)$itemId < 1 || !Db::name('jcx_item')->where('item_id', (int)$itemId)->count()) { return self::fail('err_zip', 'file'); }
            return JcxBuild::unpackZip($tmp, (int)$itemId);
        }
        $h = fopen($tmp, 'rb');
        $hex = bin2hex((string)fread($h, 512));
        if ($size > 512) { fseek($h, $size - 512); $hex .= bin2hex((string)fread($h, 512)); }
        fclose($h);
        if (preg_match('/(3c25.*?28.*?29.*?253e)|(3c3f.*?28.*?29.*?3f3e)|(3C534352495054)|(2F5343524950543E)|(3C736372697074)|(2F7363726970743E)/is', $hex)) { return self::fail('err_file', 'file'); }
        $w = $hh = 0;
        if (strpos(self::UPLOAD[$ext][0], 'image/') === 0) {
            $sz = @getimagesize($tmp);
            if (!$sz || $sz[0] < 1 || $sz[1] < 1) { return self::fail('err_file', 'file'); }
            list($w, $hh) = $sz;
        }
        $day = date('Ymd');
        $n = 1;
        while (is_dir(ROOT_PATH . 'upload/jcx/' . $day . '-' . $n) && count(glob(ROOT_PATH . 'upload/jcx/' . $day . '-' . $n . '/*') ?: []) >= 500) { $n++; }
        $rel = 'upload/jcx/' . $day . '-' . $n . '/' . md5_file($tmp) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $dir = dirname(ROOT_PATH . $rel);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return self::fail('err_file', 'file'); }
        $ok = $isUploaded ? @move_uploaded_file($tmp, ROOT_PATH . $rel) : @copy($tmp, ROOT_PATH . $rel);
        if (!$ok) { return self::fail('err_file', 'file'); }
        // Suggested display size: the IAB size of the picture, or of half of it for pictures made at 2x.
        $snap = [0, 0];
        if ($w && $hh) {
            $snap = self::snap($w, $hh);
            $half = self::snap((int)round($w / 2), (int)round($hh / 2));
            if ($snap === [$w, $hh] && !in_array([$w, $hh], self::IAB, true) && in_array($half, self::IAB, true)) { $snap = $half; }
            if ($snap[0] > 1000 || $snap[1] > 1050) { $snap = [min(1000, $snap[0]), min(1050, $snap[1])]; }
        }
        return ['code' => 1, 'path' => $rel, 'ext' => $ext, 'w' => $w, 'h' => $hh, 'size' => $snap, 'bytes' => $size];
    }

    /* ---- stats ---- */

    /** $triples: [[item_id, event, count], ...] already validated by the endpoint. Daily aggregates only. */
    public static function statWrite(array $triples)
    {
        if (!$triples) { return 0; }
        $day = (int)date('Ymd');
        $prefix = (string)config('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]{0,32}$/D', $prefix)) { return 0; }
        $vals = [];
        $agg = [];
        foreach ($triples as $t) { $k = (int)$t[0] . '.' . (int)$t[1]; $agg[$k] = ($agg[$k] ?? 0) + (int)$t[2]; }
        foreach ($agg as $k => $n) { list($i, $e) = explode('.', $k); $vals[] = '(' . $day . ',' . (int)$i . ',' . (int)$e . ',' . (int)$n . ')'; }
        Db::execute('INSERT INTO `' . $prefix . 'jcx_stat` (st_day,st_item,st_ev,st_n) VALUES ' . implode(',', $vals) . ' ON DUPLICATE KEY UPDATE st_n = st_n + VALUES(st_n)');
        if (mt_rand(1, 100) === 1) { Db::name('jcx_stat')->where('st_day', '<', (int)date('Ymd', time() - 400 * 86400))->delete(); }
        return count($vals);
    }

    /** Per item and per day totals for the last $days days: ['items'=>[id=>[ev=>n]], 'days'=>[Ymd=>[ev=>n]]]. */
    public static function stats($days = 7)
    {
        self::guard();
        $days = self::int($days, 1, 400, 7);
        $from = (int)date('Ymd', time() - ($days - 1) * 86400);
        $items = [];
        $byDay = [];
        foreach (Db::name('jcx_stat')->where('st_day', '>=', $from)->select() as $r) {
            $items[$r['st_item']][$r['st_ev']] = ($items[$r['st_item']][$r['st_ev']] ?? 0) + (int)$r['st_n'];
            $byDay[$r['st_day']][$r['st_ev']] = ($byDay[$r['st_day']][$r['st_ev']] ?? 0) + (int)$r['st_n'];
        }
        return ['code' => 1, 'days' => $days, 'items' => $items, 'by_day' => $byDay];
    }

    public static function resetStats()
    {
        self::guard();
        Db::name('jcx_stat')->where('st_day', '>', 0)->delete();
        return ['code' => 1];
    }

    /* ---- settings ---- */

    /**
     * $in keys: on, safe, stats, geo_header, consent, member_free, vip (csv of ints), chk (list 1-5), redirect_ok.
     * Turning safe limits off needs $ctx['typed'] === 'I UNDERSTAND'.
     */
    public static function settings(array $in, array $ctx = [])
    {
        self::guard();
        $c = JcxBuild::conf();
        $set = [];
        foreach (['on', 'stats', 'consent', 'member_free', 'redirect_ok'] as $k) { if (array_key_exists($k, $in)) { $set[$k] = (int)$in[$k] ? 1 : 0; } }
        if (array_key_exists('safe', $in)) {
            $v = (int)$in['safe'] ? 1 : 0;
            if ($v === 0 && (int)$c['safe'] === 1 && ($ctx['typed'] ?? '') !== 'I UNDERSTAND') { return self::fail('set_safe_off', 'safe', 'I UNDERSTAND'); }
            $set['safe'] = $v;
        }
        if (array_key_exists('geo_header', $in)) {
            $set['geo_header'] = in_array($in['geo_header'], ['', 'HTTP_CF_IPCOUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE'], true) ? $in['geo_header'] : '';
        }
        if (array_key_exists('vip', $in)) { $set['vip'] = self::csv($in['vip'], null, '/^[1-9][0-9]{0,5}$/D', 50); }
        if (array_key_exists('chk', $in)) { $set['chk'] = array_values(array_unique(array_filter(array_map('intval', (array)$in['chk']), function ($x) { return $x >= 1 && $x <= 5; }))); }
        if (!$set) { return ['code' => 1, 'conf' => JcxBuild::publicConf()]; }
        JcxBuild::confSet($set);
        return ['code' => 1, 'conf' => JcxBuild::publicConf(), 'build' => JcxBuild::run()];
    }

    /** "Remove all ads data": typed DELETE. Deletes files we wrote (sha-checked), drops the tables, on=0, lrev+1. */
    public static function removeAll($typed)
    {
        if ($typed !== 'DELETE') { return self::fail('remove_all_confirm', 'typed', 'DELETE'); }
        return JcxBuild::uninstall();
    }
}
