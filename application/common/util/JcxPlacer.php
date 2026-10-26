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
namespace app\common\util;

/**
 * Ad slot placer (ADS-BRIEF 2.2-2.5, 8). Runs in All::fetch() on cache misses only (hand-over H1), before the
 * page cache stores the HTML. It reads application/extra/jcx.php and application/data/jcx/live.php (both through
 * OPcache; never the database), resolves the anchors of the active theme (hook marker > admin override > theme
 * profile > default map) on a light tag index of the rendered HTML, and inserts size-reserved slot boxes, the
 * head snippet, the critical style, P15 code and the runtime tag. Nothing per visitor is printed.
 *
 * cacheKeyPart() / coreFiles() feed the page-cache key (hand-over H2): 'jcx' . lrev . '.1' . '.' . passed edges,
 * 'jcxoff' while the master switch is off, 'jcx0' while the module is not installed, 'jcxE' when a build file
 * could not be read (that copy is never served to a normal request).
 */
class JcxPlacer
{
    const VOID = ['area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1, 'input' => 1, 'link' => 1, 'meta' => 1, 'param' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1];
    const RAW = ['script' => 1, 'style' => 1, 'textarea' => 1, 'template' => 1, 'title' => 1, 'svg' => 1];
    const IN_CONTENT = ['P03' => 1, 'P05' => 1, 'P06' => 1, 'P13' => 1, 'P16' => 1];
    /** The phone layout (P2-05): narrow screens and phones in landscape; the same query as jcx.js (PH) and jcx.css. */
    const PHONE_MQ = '(max-width:767px),(pointer:coarse) and (max-height:500px)';
    /** Its complement: the desktop layout (wide, and not a touch screen with a low height). */
    const DESK_MQ = '(min-width:768px) and (min-height:501px),(min-width:768px) and (pointer:fine)';
    /** Slot places per page type (2.4, 8.2). */
    const PAGE_SLOTS = ['home' => ['P01', 'P02', 'P05', 'P06', 'P07'], 'list' => ['P01', 'P05', 'P07', 'P14'], 'search' => ['P01', 'P05', 'P07', 'P12'],
        'detail' => ['P01', 'P03d', 'P07', 'P16'], 'play' => ['P01', 'P03', 'P04', 'P07', 'P16'], 'art' => ['P01', 'P07', 'P13'], 'artlist' => ['P01', 'P07'], 'other' => ['P01', 'P07'], '404' => ['P01', 'P07']];
    const STR = [
        'l' => ['ui.jcx_label', 'Ad'], 'r' => ['ui.jcx_region', 'Advertisement'], 'x' => ['ui.jcx_close', 'Close advertisement'],
        'c' => ['ui.jcx_continue', 'Continue to page'], 'sp' => ['ui.jcx_sponsored', 'Sponsored'], 'ln' => ['ui.jcx_links', 'Sponsored links'],
        // carousel arrows: their own words, not the pager's ("Suiv" / "下一页", i18n F6)
        'ex' => ['ui.jcx_expand', 'Expand'], 'co' => ['ui.jcx_collapse', 'Collapse'], 'pv' => ['ui.jcx_prev_slide', 'Previous slide'], 'nx' => ['ui.jcx_next_slide', 'Next slide'],
        'sl' => ['ui.jcx_slide', '{i} of {n}'], 'ca' => ['ui.jcx_carousel', 'carousel'], 'pl' => ['ui.jcx_play', 'Play video'], 'pa' => ['ui.jcx_pause', 'Pause video'],
        'so' => ['ui.jcx_sound_on', 'Turn sound on'], 'sf' => ['ui.jcx_sound_off', 'Turn sound off'],
    ];
    const VSTR = [
        'label' => ['ui.jcx_label', 'Ad'], 'skip_in' => ['ui.jcx_skip_in', 'Skip ad in {n}'], 'skip' => ['ui.jcx_skip', 'Skip ad'],
        'visit' => ['ui.jcx_visit', 'Visit advertiser'], 'visit_short' => ['ui.jcx_visit_short', 'Visit'], 'pod' => ['ui.jcx_pod', 'Ad {i} of {n}'],
        'sound_on' => ['ui.jcx_sound_on', 'Turn sound on'], 'sound_off' => ['ui.jcx_sound_off', 'Turn sound off'], 'play' => ['ui.jcx_play', 'Play video'],
        'reward' => ['ui.jcx_reward', 'Watch a short video to hide ads for {h} hour'], 'reward_done' => ['ui.jcx_reward_done', 'Thank you. Ads are hidden for {h} hour.'],
        // plural forms for h != 1 (i18n F6: "{h} hour" was printed for any number)
        'reward_n' => ['ui.jcx_reward_n', 'Watch a short video to hide ads for {h} hours'], 'reward_done_n' => ['ui.jcx_reward_done_n', 'Thank you. Ads are hidden for {h} hours.'],
        'region' => ['ui.jcx_region', 'Advertisement'],
    ];

    /** Test / tool hook: ['live'=>[...], 'conf'=>[...], 'now'=>t, 'ttl'=>s, 'locale'=>'en', 'dir'=>'ltr', 'cat'=>[id,pid], 'base'=>'/', 'all'=>bool]. */
    public static $env = null;
    /** Last decorate() result for tools: ['page','theme','hits'=>[place=>'hit'|'miss'|'off'|'P03'],'slots'=>[[place,pos]],'ms'] */
    public static $last = [];
    /** True when a build file could not be read during this request: the page must not be stored in the page cache. */
    public static $nocache = false;
    private static $memo = [];

    private $html;
    private $els = [];
    private $kids = null;
    private $grids = null;
    private $am = [];

    /* ------------------------------------------------------------------ cache key */

    public static function conf()
    {
        if (self::$env !== null && array_key_exists('conf', self::$env)) { return self::$env['conf']; }
        if (array_key_exists('conf', self::$memo)) { return self::$memo['conf']; }
        return self::$memo['conf'] = self::readArray(APP_PATH . 'extra/jcx.php');
    }

    /**
     * include of a build file that must return an array. On Windows a reader can meet the file while the writer
     * replaces it (R5-02): when the file exists but does not return an array, wait 20 ms and read once more; still
     * bad -> null and $nocache (All::label_fetch then does not store this page, so no broken page is cached).
     */
    public static function readArray($f)
    {
        if (!is_file($f)) { return null; }
        $v = @include $f;
        if (!is_array($v)) {
            clearstatcache(true, $f);
            usleep(20000);
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($f, true); }
            $v = is_file($f) ? @include $f : null;
        }
        if (!is_array($v)) { self::$nocache = true; return null; }
        return $v;
    }

    /**
     * Page-cache key part. 'jcxoff' while the master switch is off (saves then never re-key, R5-06). With the switch on,
     * 'jcx<lrev>.1' plus the number of item start / end times already passed ('edges', written by the build): the key
     * changes the moment an item starts or ends, so a page never keeps an empty box for an item that has not started
     * and never keeps a finished item (R1-07). A jcx.php that exists but cannot be read gives 'jcxE' and no caching.
     */
    public static function cacheKeyPart()
    {
        $c = self::conf();
        if (!$c) { return self::$nocache ? 'jcxE' : 'jcx0'; }
        // master switch off: pages carry no ad code at all (release P3); only the admin's browser with the preview hint
        // cookie gets the head snippet (it loads the preview / picker), under its own cache key 'jcxoffp'
        if (empty($c['on'])) { return self::hint() ? 'jcxoffp' : 'jcxoff'; }
        // live.php met while the build replaces it (R5-02): the page rendered now would have no slots. It goes under
        // the key 'jcxE', which a normal request never reads, so the broken copy is never served from the cache.
        if (self::live() === null && self::$nocache) { return 'jcxE'; }
        $k = 'jcx' . (int)($c['lrev'] ?? 1) . '.1';
        $edges = (array)($c['edges'] ?? []);
        if ($edges) {
            $now = (int)self::env('now', time());
            $n = 0;
            foreach ($edges as $t) { if ((int)$t <= $now) { $n++; } }
            $k .= '.' . $n;
        }
        return $k;
    }

    /** The admin preview hint cookie jcxh=1 (set next to the HMAC cookie jcxp by jcx/preview; script-visible, carries no right). */
    public static function hint()
    {
        return (bool)self::env('hint', (string)($_COOKIE['jcxh'] ?? '') === '1');
    }

    public static function coreFiles()
    {
        // static/js/player.js (P3-07, F1 follow-up): the page prints its version (All.php: ?t=filemtime), so an edit of
        // it re-keys the cached HTML too; jcx-a.js: the VAST part of jcx-v.js (same build)
        return [__FILE__, __DIR__ . '/jcx_anchors.php', ROOT_PATH . 'static/jcx/jcx.js', ROOT_PATH . 'static/jcx/jcx.css', ROOT_PATH . 'static/jcx/jcx-l.js', ROOT_PATH . 'static/jcx/jcx-m.js', ROOT_PATH . 'static/jcx/jcx-p.js', ROOT_PATH . 'static/jcx/jcx-v.js', ROOT_PATH . 'static/jcx/jcx-a.js', ROOT_PATH . 'static/js/player.js'];
    }

    public static function reset()
    {
        self::$memo = [];
        self::$nocache = false;
    }

    /* ------------------------------------------------------------------ page types (2.4) */

    public static function pageType($tpl)
    {
        $t = strtolower(str_replace('\\', '/', trim((string)$tpl)));
        $t = preg_replace('~\.html?\z~', '', $t);
        $t = preg_replace('~\A[a-z0-9_]+@~', '', $t);
        $t = ltrim($t, '/');
        if ($t === '' || preg_match('~(?:^|/)(?:user|seo)/~', $t) || preg_match('~(?:^|/)label/(?:login|signup|reg)~', $t)
            || preg_match('~(?:^|/)public/(?:jump|msg|close|browser|verify)\z~', $t)
            || preg_match('~(?:^|/)vod/(?:player|player_pwd|downer|downer_pwd|detail_pwd|play_pwd|down|copyright)\z~', $t)
            || preg_match('~(?:^|/)[a-z]+/(?:confirm|rss)\z~', $t) || preg_match('~(?:^|/)rss(?:/|\z)~', $t)
            || preg_match('~(?:^|/)ajax(?:_[a-z0-9_]*)?\z~', $t) || preg_match('~/ajax_[a-z0-9_]+\z~', $t)) {
            return '';
        }
        if (preg_match('~(?:^|/)index/index\z~', $t)) { return 'home'; }
        if (preg_match('~(?:^|/)vod/(?:type|show)\z~', $t) || preg_match('~(?:^|/)label/~', $t) || preg_match('~(?:^|/)(?:actor|topic)/index\z~', $t)) { return 'list'; }
        if (preg_match('~(?:^|/)vod/search\z~', $t) || preg_match('~(?:^|/)search/~', $t)) { return 'search'; }
        if (preg_match('~(?:^|/)vod/detail\z~', $t)) { return 'detail'; }
        if (preg_match('~(?:^|/)vod/play\z~', $t)) { return 'play'; }
        if (preg_match('~(?:^|/)art/detail\z~', $t)) { return 'art'; }
        if (preg_match('~(?:^|/)art/(?:index|type|show)\z~', $t)) { return 'artlist'; }
        if (preg_match('~(?:^|/)public/404\z~', $t)) { return '404'; }
        return 'other';
    }

    /* ------------------------------------------------------------------ anchor map (8.2, 8.3) */

    public static function anchorsFile() { return __DIR__ . '/jcx_anchors.php'; }

    private static function allMaps()
    {
        if (!isset(self::$memo['maps'])) { $m = include self::anchorsFile(); self::$memo['maps'] = is_array($m) ? $m : []; }
        return self::$memo['maps'];
    }

    public static function themeSlug($dir)
    {
        $t = trim(basename(rtrim(str_replace('\\', '/', (string)$dir), '/')));
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $t) ? $t : '';
    }

    /** UI hints of a theme profile for the runtime (5.9). */
    public static function ui($theme)
    {
        $all = self::allMaps();
        return array_merge($all['_default']['_ui'] ?? [], $all[$theme]['_ui'] ?? []);
    }

    /** Merged anchor map for a theme and page type: default < profile < admin override. $p05 = place settings. */
    public static function map($theme, $page, array $override = [], array $p05 = [])
    {
        $all = self::allMaps();
        $m = $all['_default'] ?? [];
        foreach (($all[$theme] ?? []) as $k => $v) {
            if ($k === '_ui') { continue; }
            if ($v === null) { unset($m[$k]); continue; }
            $on = array_replace($m[$k]['on'] ?? [], $v['on'] ?? []);
            $m[$k] = array_merge($m[$k] ?? [], $v);
            if ($on) { $m[$k]['on'] = $on; }
        }
        unset($m['_ui']);
        foreach ($m as $k => $a) {
            if (isset($a['on'][$page])) { $m[$k] = array_merge($a, $a['on'][$page]); }
            unset($m[$k]['on']);
        }
        if (isset($m['P05']) && $p05) {
            if ($page === 'home') { $m['P05']['every'] = (int)($p05['he'] ?? 4); $m['P05']['max'] = (int)($p05['hm'] ?? 1); }
            else { $m['P05']['every'] = (int)($p05['e'] ?? 8); $m['P05']['max'] = (int)($p05['m'] ?? 2); }
        }
        foreach ($override as $k => $o) {
            $base = $m[$k] ?? [];
            unset($base['fallback']);
            $o = array_filter($o, function ($v) { return $v !== 0 && $v !== '' && $v !== null; });
            $m[$k] = array_merge(['hd' => 90, 'hm' => 100], $base, $o);
        }
        return $m;
    }

    /* ------------------------------------------------------------------ decorate */

    private static function env($k, $def = null)
    {
        return self::$env !== null && array_key_exists($k, self::$env) ? self::$env[$k] : $def;
    }

    private static function live()
    {
        if (self::$env !== null && isset(self::$env['live'])) { return self::$env['live']; }
        if (array_key_exists('live', self::$memo)) { return self::$memo['live']; }
        return self::$memo['live'] = self::readArray(APP_PATH . 'data/jcx/live.php');
    }

    private static function base()
    {
        $b = self::env('base');
        if ($b === null) { $b = defined('MAC_PATH') ? (string)MAC_PATH : '/'; }
        $b = '/' . trim(str_replace('\\', '/', (string)$b), '/');
        return $b === '/' ? '/' : $b . '/';
    }

    private static function locale()
    {
        $l = self::env('locale');
        if ($l !== null) { return $l; }
        return class_exists('app\\common\\util\\TemplateText') ? TemplateText::locale() : 'en';
    }

    private static function dir()
    {
        $d = self::env('dir');
        if ($d !== null) { return $d; }
        return class_exists('app\\common\\util\\TemplateText') ? TemplateText::direction() : 'ltr';
    }

    private static function t($key, $fallback, $locale = null)
    {
        $s = $fallback;
        if (class_exists('app\\common\\util\\TemplateText')) {
            try { $r = TemplateText::raw($key, [], $locale); if (is_string($r) && $r !== '' && $r !== $key) { $s = $r; } } catch (\Throwable $e) {}
        }
        return $s;
    }

    public static function strings(array $set)
    {
        $o = [];
        foreach ($set as $k => $v) { $o[$k] = self::t($v[0], $v[1]); }
        return $o;
    }

    /** Catalog key of a JcxT key: jcx_<name> -> ui.jcx_<name>, template_ui_<name> as is; '' = not allowed. */
    public static function catKey($k)
    {
        $k = (string)$k;
        if (preg_match('/^jcx_[a-z0-9_]{2,40}$/D', $k)) { return 'ui.' . $k; }
        return preg_match('/^template_ui_[a-z0-9_]{2,40}$/D', $k) ? $k : '';
    }

    /** Texts for window.JcxT (keys from JcxBuild::textKeys); a key without a text is left out (JcxT then gives the fallback). */
    public static function textStrings(array $keys, $locale = null)
    {
        $o = [];
        foreach ($keys as $k) {
            $c = self::catKey($k);
            if ($c === '') { continue; }
            $s = self::t($c, '', $locale);
            if ($s !== '') { $o[(string)$k] = $s; }
        }
        return $o;
    }

    private static function ttl()
    {
        $t = self::env('ttl');
        if ($t !== null) { return (int)$t; }
        $t = (int)($GLOBALS['config']['app']['cache_time_page'] ?? 3600);
        if (class_exists('app\\common\\util\\ThemeSwitcher', false) && ThemeSwitcher::visitorTheme()) { $t = min($t, ThemeSwitcher::VISITOR_CACHE_TTL); }
        return max(0, $t);
    }

    private static function json($v)
    {
        return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private static function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private static function ver($rel)
    {
        $f = ROOT_PATH . $rel;
        return is_file($f) ? (string)filemtime($f) : '0';
    }

    /**
     * Head snippet (≤ 350 B gzip, + ≤ 150 B for the flags): click shield, preview / picker loader (5.1, 5.6) and the
     * flags that set html.jcx-free before the first paint, so the critical style hides every slot, server-printed
     * creatives included: members / VIP groups (CF12) and, whenever the ads are on ($print given), the JCX.free() time
     * that a rewarded video stores in localStorage jcx.f (R1-08, R3-07, R4-10, R6-08). P2-07: the free time outlives
     * the rewarded item (switched off, or not on this page), so the check is printed with every runtime, not only
     * while a rewarded item is on (CLS 0.53 on desktop watch pages: the boxes folded after the first paint).
     */
    public static function headSnippet($page, $theme, ?array $print = null)
    {
        $b = self::base();
        $mf = '';
        if ($print) { $mf .= 'try{JSON.parse(localStorage[\'jcx.f\']).free>Date.now()/1e3&&L.add(\'jcx-free\')}catch(e){}'; }
        if ($print && (!empty($print['mf']) || !empty($print['vip']))) {
            $cond = [];
            if (!empty($print['mf'])) { $cond[] = '/(^|; )user_id=[1-9]/.test(c)'; }
            if (!empty($print['vip'])) { $cond[] = '(m=c.match(/(?:^|; )group_id=(\d+)/))&&' . self::json(array_values(array_map('intval', $print['vip']))) . '.indexOf(+m[1])>-1'; }
            $mf .= 'if(' . implode('||', $cond) . ')L.add(\'jcx-free\');';
        }
        // base() keeps only /[A-Za-z0-9/_.-]/ here, so the URL is safe inside a JS string literal (and never holds "/*").
        $p = preg_replace('~[^A-Za-z0-9/_.-]~', '', $b) . 'static/jcx/jcx-p.js?v=' . self::ver('static/jcx/jcx-p.js');
        $js = '(function(w,d){var c=d.cookie,m' . ($mf !== '' ? ',L=d.documentElement.classList' : '') . ';w.JCXQ=[];w.addEventListener(\'click\',function(e){var a=e.target.closest(\'[data-jcx-act]\');'
            . 'a&&(e.stopPropagation(),w.JCX?JCX._act(a,e):JCXQ.push([a,e]))},!0);' . $mf
            . 'if(/(^|; )jcxh=1/.test(c)||!location.hash.indexOf(\'#jcx-pick=\')){var s=d.createElement(\'script\');s.src=\'' . $p . '\';d.head.appendChild(s)}})(window,document)';
        return '<script id="jcx-h" data-b="' . self::e($b) . '" data-t="' . self::e($page) . '" data-th="' . self::e($theme) . '">' . $js . '</script>';
    }

    /** Critical slot style (≤ 490 B; 450 before the landscape-phone queries of P2-05): geometry, reserved heights,
     *  device-only slots, members (2.5, CF12, CF13). */
    public static function critCss()
    {
        // flex:none, not flex:0 0 100% (R4-01): in a column flexbox (crimson .ct-wmain) a 100 % basis took the whole
        // main axis and squeezed the player to 0 px; width:100% still fills a line of a wrapping row flexbox.
        // The label has no opacity (R4-03: >= 4.5:1 in every theme); jcx.css gives the slot label its own chip (R4r2-03).
        // Repair round 3 (owner decision R4r3-06): the "Ad" label shows only together with a creative (data-jcx-f, set by
        // jcx.js when the creative is in, or printed by the server with content that needs no script); a box that is
        // still loading is an empty reserved space, never an empty box with only the label. With JS off the <noscript>
        // print carries its own label. The slot needs no box-sizing (no padding / border); the label font is system-ui
        // (every browser the runtime supports has it).
        return '<style id="jcx-cs">.jcx-s{display:block;width:100%;min-width:0;align-self:stretch;grid-column:1/-1;flex:none;contain:layout;min-height:var(--jcx-hd);margin:12px auto;text-align:center;position:relative}'
            . '.jcx-l{display:block;height:16px;margin:0 auto 4px;max-width:var(--jcx-wd,100%);font:11px/16px system-ui;text-align:start}'
            . '.jcx-s img{display:block;margin:auto;max-width:100%;height:auto}.jcx-cv{display:block;aspect-ratio:16/9}'
            // in-feed cell (R4-04): the label is a badge over the thumbnail (abspos at its static place, inline-start + 6 px),
            // so the card lines up with its neighbours
            . '.jcx-c{position:relative}.jcx-c>.jcx-l{position:absolute;z-index:2;margin:6px;padding:1px 6px;border-radius:3px;background:#000b;color:#fff}'
            . ':is(.jcx-s,.jcx-c):not([data-jcx-f])>.jcx-l{visibility:hidden}'
            . '@media' . self::PHONE_MQ . '{.jcx-s{min-height:var(--jcx-hm)}.jcx-s>.jcx-l{max-width:var(--jcx-wm,100%)}[data-jcx-dv="1"]{display:none}}'
            . '@media ' . self::DESK_MQ . '{[data-jcx-dv="2"]{display:none}}.jcx-free :is(.jcx-s,.jcx-c){display:none}</style>';
    }

    /**
     * Entries of one page type that apply now (language, category, start <= now < end). The page-cache key changes
     * at every start / end time (cacheKeyPart 'edges'), so a cached page never outlives the window it was rendered
     * for: an item that has not started gets no box at all (R1-07), a finished one disappears at its end.
     * Without edges in jcx.php (a build from before this change) the old look-ahead of one cache TTL is kept.
     */
    private static function eligible(array $list, $locale, array $cat, $now, $ttl)
    {
        $out = [];
        $ahead = self::edgesKnown() ? 0 : $ttl;
        foreach ($list as $e) {
            if (!empty($e['lg']) && !in_array($locale, $e['lg'], true)) { continue; }
            if (!empty($e['ct']) && !array_intersect($cat, $e['ct'])) { continue; }
            if (!empty($e['e']) && $e['e'] <= $now) { continue; }
            if (!empty($e['s']) && $e['s'] > $now + $ahead) { continue; }
            $out[] = $e;
        }
        return $out;
    }

    private static function windowOk(array $wins, $now, $ttl)
    {
        $ahead = self::edgesKnown() ? 0 : $ttl;
        foreach ($wins as $w) {
            if ((!$w[1] || $w[1] > $now) && (!$w[0] || $w[0] <= $now + $ahead)) { return true; }
        }
        return false;
    }

    /** True when jcx.php carries the build's 'edges' list (the cache key then follows start / end times). */
    private static function edgesKnown()
    {
        $c = self::conf();
        return is_array($c) && array_key_exists('edges', $c);
    }

    /**
     * Front-controller hook (All::fetch, hand-over H1). Returns $html unchanged when the module is not installed,
     * the page type never shows ads (user pages, gate pages, player iframe, feeds, AJAX) or <html data-jcx="off">.
     */
    public static function decorate($html, $tpl, $themeDir = '')
    {
        if (!is_string($html)) { return $html; }
        $t0 = microtime(true);
        self::$last = [];
        try {
            $conf = self::conf();
            if (!$conf) { return $html; }
            $page = self::pageType($tpl);
            if ($page === '') { return $html; }
            // SEO legal pages (/label/terms|privacy|dmca|2257|cookies/) render the theme's art/detail: no in-content
            // places there (R1-03, R3-06, R4-08, R5-04, R6-02). Page type 'legal' prints the head snippet and the
            // site-wide P15 code of page type art, nothing else.
            $legal = self::env('legal');
            if ($legal === null) { $legal = class_exists('app\\common\\util\\LegalPages', false) && (string)LegalPages::current() !== ''; }
            if ($legal) { $page = 'legal'; }
            $head = stripos($html, '</head>');
            $bodyEnd = strripos($html, '</body>');
            if ($head === false || $bodyEnd === false || $bodyEnd < $head) { return $html; }
            if (preg_match('~<html\b[^>]*\bdata-jcx\s*=\s*["\']?off~i', substr($html, 0, 4096))) { return $html; }
            if (strpos($html, 'id="jcx-h"') !== false) { return $html; }
            $theme = self::themeSlug($themeDir);
            $live = self::live();
            $print = is_array($live) ? ($live['print'] ?? null) : null;
            $on = !empty($conf['on']) && is_array($print);
            $snippet = self::headSnippet($page, $theme, $on ? $print : null);
            if (!$on) {
                // Master switch off (or no build yet): no ad script, no head snippet, nothing (release P3; fresh installs
                // start off). The admin's own browser (preview hint cookie, cache key 'jcxoffp') still gets the snippet,
                // so "See my ads on my site" and the place picker work before the ads are switched on.
                self::$last = ['page' => $page, 'theme' => $theme, 'on' => 0, 'ms' => round((microtime(true) - $t0) * 1000, 2)];
                return self::hint() ? substr($html, 0, $head) . $snippet . substr($html, $head) : $html;
            }
            $now = (int)self::env('now', time());
            $ttl = self::ttl();
            $locale = self::locale();
            $cat = self::env('cat');
            if ($cat === null) { $cat = [defined('MAC_TYPE_ID') ? (int)MAC_TYPE_ID : 0, defined('MAC_TYPE_PID') ? (int)MAC_TYPE_PID : 0]; }
            $cat = array_values(array_filter(array_map('intval', (array)$cat)));
            // legal: only the inline site-wide code of page type art (held code would need the runtime: left out)
            $pt = $page === 'legal' ? ['rt' => [], 'e' => [], 'v' => [], 'code' => array_values(array_filter($print['pt']['art']['code'] ?? [], function ($c) { return empty($c['hold']); }))]
                : ($print['pt'][$page] ?? ['rt' => [], 'e' => [], 'code' => [], 'v' => []]);
            $all = (bool)self::env('all', false);
            $entries = $all ? $pt['e'] : self::eligible($pt['e'], $locale, $cat, $now, $ttl);
            $code = self::eligible($pt['code'], $locale, $cat, $now, $ttl);
            $video = self::eligible($pt['v'], $locale, $cat, $now, $ttl);
            $rt = $all || self::windowOk($pt['rt'], $now, $ttl);
            $byPlace = [];
            foreach ($entries as $e) { $byPlace[$e['p']][] = $e; }
            if ($all) { foreach (self::PAGE_SLOTS[$page] ?? [] as $p) { if (!isset($byPlace[$p])) { $byPlace[$p] = []; } } }
            $res = self::plan($html, $theme, $page, array_keys($byPlace), $print['anchors'][$theme] ?? [], $print['pl']['P05'] ?? []);
            $ins = [];
            $hits = $res['hits'];
            // P04 without a sidebar in this theme: its items show under the player (8.2).
            if (isset($byPlace['P04']) && ($byPlace['P04'] || $all) && ($hits['P04'] ?? '') !== 'hit') {
                if (isset($res['pos']['P03'][0]) || ($hits['P03'] ?? '') === 'hit') {
                    $byPlace['P03'] = array_merge($byPlace['P03'] ?? [], $byPlace['P04']);
                    $hits['P04'] = 'P03';
                    $res['alt']['P03'] = 'P04';
                } elseif (!isset($res['pos']['P03'])) {
                    $res2 = self::plan($html, $theme, $page, ['P03'], $print['anchors'][$theme] ?? [], []);
                    if (!empty($res2['pos']['P03'])) {
                        $res['pos']['P03'] = $res2['pos']['P03'];
                        $res['map']['P03'] = $res2['map']['P03'];
                        $byPlace['P03'] = $byPlace['P04'];
                        $hits['P04'] = 'P03';
                        $res['alt']['P03'] = 'P04';
                    }
                }
                unset($byPlace['P04']);
            }
            $str = self::strings(['l' => self::STR['l'], 'r' => self::STR['r']]);
            $safe = !empty($print['safe']);
            // Order slots by position to apply the phone density limit (≤ 3 in-content slots per phone page, 11.4).
            $slots = [];
            foreach ($byPlace as $place => $list) {
                if (empty($res['pos'][$place])) { continue; }
                if ($place === 'P05' && $list && !$all) {
                    // never more feed cells than items one device can get (R1-11: no empty second "Ad" card)
                    $n1 = 0; $n2 = 0;
                    foreach ($list as $e) { if ((int)$e['dv'] & 1) { $n1++; } if ((int)$e['dv'] & 2) { $n2++; } }
                    $res['pos'][$place] = array_slice($res['pos'][$place], 0, max(1, $n1, $n2));
                }
                foreach ($res['pos'][$place] as $i => $pos) { $slots[] = [$pos, $place, $i]; }
            }
            usort($slots, function ($a, $b) { return $a[0] <=> $b[0]; });
            $phoneIn = 0;
            $p05counted = false;
            $out = [];
            foreach ($slots as $s) {
                list($pos, $place, $i) = $s;
                $list = $byPlace[$place];
                $dv = 0;
                foreach ($list as $e) { $dv |= (int)$e['dv']; }
                if ($all && !$list) { $dv = 3; }
                if ($safe && !$all && isset(self::IN_CONTENT[$place]) && ($dv & 2)) {
                    if ($place !== 'P05' || !$p05counted) { $phoneIn++; if ($place === 'P05') { $p05counted = true; } }
                    if ($phoneIn > 3) { $dv &= 1; if (!$dv) { continue; } }
                }
                $html1 = $place === 'P05'
                    ? self::cellHtml($res['cell'] ?? ['div', ''], $dv, $str)
                    : self::slotHtml($place, $list, $res['map'][$place] ?? [], $dv, $str, $now, $ttl, $res['alt'][$place] ?? '', $all);
                $ins[] = [$pos, $html1, $place];
                $out[] = [$place, $pos];
            }
            // #jcx-v before the player script (play pages, 7.2). P3-03: printed whenever the page type has an ON video
            // item ($pt['v']), not only when one passes the server's filter: the gate decides on c-play.json (no-cache)
            // and its rules, so a page rendered before an item starts still gets it; the id list is only a hint.
            // RV-01: only together with the runtime ($rt), else the gate would wait for a JCX that never comes (T0 + 6 s)
            $vpre = '';
            if ($page === 'play' && !empty($pt['v']) && $rt && isset($res['player'])) {
                $vj = ['v' => 1, 'it' => array_values(array_map(function ($e) { return (int)$e['id']; }, $video)), 'b' => self::base(),
                    'api' => self::base() . 'index.php/jcx/', 'cfg' => self::cfgUrl($print, $page), 'l' => $locale, 'dir' => self::dir(), 's' => self::strings(self::VSTR), 'jv' => self::ver('static/jcx/jcx-v.js')];
                $ins[] = [$res['player'], '<script type="application/json" id="jcx-v">' . self::json($vj) . '</script>', 'v'];
                // P2-01: jcx-v.js is fetched with the page (the same URL player.js asks for), not after the player starts
                $vpre = '<link rel="preload" as="script" href="' . self::e(self::base() . 'static/jcx/jcx-v.js?v=' . rawurlencode($vj['jv'])) . '">';
            }
            // From the end backwards; at the same offset the later place in document order goes in first.
            $ord = array_flip(['P01', 'P02', 'P12', 'P03d', 'P03', 'P04', 'P05', 'P06', 'P13', 'P14', 'P16', 'P07', 'v']);
            usort($ins, function ($a, $b) use ($ord) { return $b[0] <=> $a[0] ?: ($ord[$b[2]] ?? 0) <=> ($ord[$a[2]] ?? 0); });
            foreach ($ins as $x) { $html = substr_replace($html, $x[1], $x[0], 0); }
            // Head: snippet, critical style, jcx.css (when the runtime runs), P15h code. Body end: P15b, payload, runtime.
            // jcx.css is a non-blocking <link media=print> switched to all on load, so it arrives with the page's own CSS:
            // injected later by jcx.js it made the browser restyle the whole page once more (about 90 ms TBT on a phone at
            // 4x CPU, R3-03 / R3-12).
            $runtime = ($rt || $out || $code) && $page !== 'legal';
            $headAdd = $snippet . $vpre . ($out ? self::critCss() : '')
                . ($runtime ? '<link rel="stylesheet" href="' . self::e(self::base() . 'static/jcx/jcx.css?v=' . self::ver('static/jcx/jcx.css')) . '" media="print" onload="this.media=\'all\'">' : '');
            $bodyAdd = '';
            foreach ($code as $c) {
                if ($c['p'] === 'P15h') { $headAdd .= $c['html']; } else { $bodyAdd .= $c['html']; }
            }
            if ($runtime) {
                $ui = self::ui($theme);
                $d = ['t' => $page, 'th' => $theme, 'l' => $locale, 'dir' => self::dir(), 'b' => self::base(), 'api' => self::base() . 'index.php/jcx/',
                    'cfg' => self::cfgUrl($print, $page), 'c' => $cat ?: 0, 's' => self::strings(self::STR),
                    'pr' => ['bt' => (string)($ui['bt'] ?? ''), 'lf' => (string)($ui['lf'] ?? ''), 'nav' => (string)($ui['nav'] ?? ''), 'rs' => self::railSel($html, $ui['rs'] ?? '')],
                    'v' => ['l' => self::ver('static/jcx/jcx-l.js'), 'm' => self::ver('static/jcx/jcx-m.js'), 'c' => self::ver('static/jcx/jcx.css'), 'v' => self::ver('static/jcx/jcx-v.js')]];
                // window.JcxT(key, fallback) strings for the code of the ON items (CONTRACT-3 E8), visitor language
                if (!empty($print['tk'])) { $d['k'] = self::textStrings((array)$print['tk'], $locale); }
                $bodyAdd .= '<script type="application/json" id="jcx-d">' . self::json($d) . '</script>'
                    . '<script defer src="' . self::e(self::base() . 'static/jcx/jcx.js?v=' . self::ver('static/jcx/jcx.js')) . '"></script>';
            }
            $head = stripos($html, '</head>');
            $html = substr($html, 0, $head) . $headAdd . substr($html, $head);
            $bodyEnd = strripos($html, '</body>');
            $html = substr($html, 0, $bodyEnd) . $bodyAdd . substr($html, $bodyEnd);
            // Misses of places that have items (health card, 4.10).
            $miss = [];
            foreach ($byPlace as $place => $list) {
                if ($list && ($hits[$place] ?? 'miss') === 'miss') { $miss[] = $place; }
            }
            if ($miss && !$all && self::$env === null) { self::logMiss($theme, $page, $miss); }
            self::$last = ['page' => $page, 'theme' => $theme, 'on' => 1, 'hits' => $hits, 'slots' => $out, 'miss' => $miss, 'ms' => round((microtime(true) - $t0) * 1000, 2)];
            return $html;
        } catch (\Throwable $e) {
            self::$last = ['error' => $e->getMessage()];
            return $html;
        }
    }

    private static function cfgUrl(array $print, $page)
    {
        return (string)($print['cfgs'][$page] ?? $print['cfg']);
    }

    /**
     * Side rails (U04 / P08) measure the gutters of the page's visible content column, not of #main-content, which is
     * full width in most themes (R6-01, R1-05, R3-08, R4-06). The profile lists the theme's content wrappers in order
     * ('_ui' => ['rs' => ['div.wrap', ...]]); the first one whose class (or id) occurs in this page is printed for
     * jcx-l.js. '' = #main-content.
     */
    public static function railSel($html, $list)
    {
        foreach ((array)$list as $sel) {
            $sel = (string)$sel;
            if (!preg_match('/\A[a-z]{0,10}([.#])([A-Za-z][A-Za-z0-9_-]{0,60})\z/D', $sel, $m)) { continue; }
            $re = $m[1] === '.' ? '~\sclass\s*=\s*["\'](?:[^"\']*\s)?' . preg_quote($m[2], '~') . '(?:\s|["\'])~' : '~\sid\s*=\s*["\']' . preg_quote($m[2], '~') . '["\']~';
            if (preg_match($re, (string)$html)) { return $sel; }
        }
        return '';
    }

    /** Slot markup (2.5). $list: entries of this place on this page. */
    private static function slotHtml($place, array $list, array $a, $dv, array $str, $now, $ttl, $alt, $all)
    {
        $hd = 0; $hm = 0; $wd = 0; $wm = 0;
        foreach ($list as $e) {
            $sz = $e['sz'] ?? [0, 0, 0, 0];
            // an expandable banner (U05) shows its "Expand" button under the picture: 40 px more (CLS)
            $xb = ($e['u'] ?? '') === 'U05' ? 40 : 0;
            if ((int)$e['dv'] & 1) { $hd = max($hd, (int)$sz[1] + $xb); $wd = max($wd, (int)$sz[0]); }
            if ((int)$e['dv'] & 2) {
                $h2 = (int)$sz[3] ?: ((int)$sz[1] && (int)$sz[0] && (int)$sz[0] <= 420 ? (int)$sz[1] : 0);
                $hm = max($hm, $h2 + ($h2 ? $xb : 0)); $wm = max($wm, (int)$sz[2] ?: ((int)$sz[0] <= 420 ? (int)$sz[0] : 0));
            }
        }
        if (!$hd) { $hd = (int)($a['hd'] ?? 90); }
        if (!$hm) { $hm = (int)($a['hm'] ?? 100); }
        $style = '--jcx-hd:' . ($hd + 20) . 'px;--jcx-hm:' . ($hm + 20) . 'px' . ($wd ? ';--jcx-wd:' . $wd . 'px' : '') . ($wm ? ';--jcx-wm:' . $wm . 'px' : '');
        $inner = '';
        $iattr = '';
        if (!$all) {
            $one = count($list) === 1 ? $list[0] : null;
            $until = self::edgesKnown() ? $now + 1 : $now + $ttl;
            foreach ($list as $e) {
                // parse-mode code writes its creative while the page loads: the label shows from the first paint (data-jcx-f)
                if (!empty($e['pm']) && (empty($e['s']) || $e['s'] <= $now) && (empty($e['e']) || $e['e'] >= $until)) { $inner = $e['pm']; $iattr = ' data-jcx-i="' . (int)$e['id'] . '" data-jcx-f="' . (int)$e['id'] . '"'; break; }
                if (!empty($e['hold'])) { $inner .= $e['hold']; }
            }
            if ($inner === '' && $one && !empty($one['srv']) && (empty($one['s']) || $one['s'] <= $now) && (empty($one['e']) || $one['e'] >= $until)) {
                // a picture comes inside <noscript> (jcx.js inserts it), with its own label %JCXL% for visitors without
                // JS (the slot's label waits for data-jcx-f); a text link is plain markup: label from the first paint.
                // %JCXR% = "Advertisement" (kept for a live.php built by an older version)
                $inner = str_replace(['%JCXR%', '%JCXL%'], [self::e($str['r']), self::e($str['l'])], $one['srv']);
                $iattr = ' data-jcx-i="' . (int)$one['id'] . '"' . (strpos($inner, '<noscript') === 0 ? '' : ' data-jcx-f="' . (int)$one['id'] . '"');
            }
        }
        $dva = $dv === 1 || $dv === 2 ? ' data-jcx-dv="' . $dv . '"' : '';
        $alta = $alt !== '' ? ' data-jcx-x="' . self::e($alt) . '"' : '';
        return '<div class="jcx-s" data-jcx="' . self::e($place) . '"' . $iattr . $dva . $alta . ' style="' . $style . '" role="region" aria-label="' . self::e($str['r']) . '">'
            . '<span class="jcx-l">' . self::e($str['l']) . '</span>' . $inner . '</div>';
    }

    /** In-feed cell: the grid's first child tag + class + jcx-c (D64). */
    private static function cellHtml(array $cell, $dv, array $str)
    {
        $tag = preg_match('/^[a-z][a-z0-9]{0,10}$/', $cell[0]) ? $cell[0] : 'div';
        $cls = trim(preg_replace('/[^A-Za-z0-9_ -]/', '', (string)$cell[1]) . ' jcx-c');
        $dva = $dv === 1 || $dv === 2 ? ' data-jcx-dv="' . $dv . '"' : '';
        return '<' . $tag . ' class="' . self::e($cls) . '" data-jcx="P05"' . $dva . ' role="region" aria-label="' . self::e($str['r']) . '">'
            . '<span class="jcx-l">' . self::e($str['l']) . '</span><span class="jcx-cv"></span></' . $tag . '>';
    }

    private static function logMiss($theme, $page, array $places)
    {
        try {
            $dir = (defined('RUNTIME_PATH') ? RUNTIME_PATH : ROOT_PATH . 'runtime/') . 'jcx/';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $f = $dir . 'miss.json';
            $h = @fopen($f, 'c+');
            if (!$h) { return; }
            if (@flock($h, LOCK_EX)) {
                $d = json_decode((string)stream_get_contents($h), true);
                $d = is_array($d) ? $d : [];
                foreach ($places as $p) {
                    $k = $theme . '|' . $p . '|' . $page;
                    $d[$k] = [(int)($d[$k][0] ?? 0) + 1, time()];
                }
                if (count($d) > 500) { uasort($d, function ($a, $b) { return $b[1] <=> $a[1]; }); $d = array_slice($d, 0, 500, true); }
                ftruncate($h, 0); rewind($h); fwrite($h, json_encode($d));
                @flock($h, LOCK_UN);
            }
            fclose($h);
        } catch (\Throwable $e) {}
    }

    /**
     * Resolve the places of a page on raw HTML (no insertion). Used by decorate() and the parity / offline tests.
     * Returns ['pos'=>[place=>[offsets]], 'hits'=>[place=>'hit'|'miss'|'off'], 'map'=>[place=>anchor], 'cell'=>[tag,class], 'player'=>offset].
     */
    public static function plan($html, $theme, $page, array $places, array $override = [], array $p05 = [])
    {
        $map = self::map($theme, $page, $override, $p05);
        $p = new self($html);
        $out = ['pos' => [], 'hits' => [], 'map' => [], 'alt' => []];
        $markers = $p->markers();
        foreach ($places as $code) {
            if (isset($markers[$code])) {
                $out['pos'][$code] = [$markers[$code]];
                $out['hits'][$code] = 'hit';
                $out['map'][$code] = ($map[$code] ?? []) + ['hd' => 90, 'hm' => 100];
                continue;
            }
            if (!isset($map[$code])) { $out['hits'][$code] = 'off'; continue; }
            $a = $map[$code];
            $r = $p->resolve($a);
            if ($r === null && !empty($a['fallback'])) { $a2 = array_merge($a, $a['fallback']); unset($a2['nth']); $r = $p->resolve($a2); }
            // A grid with fewer cards than 'every' (an empty search, a short list) is not a theme miss.
            if ($r === []) { $out['hits'][$code] = 'short'; continue; }
            if ($r === null) { $out['hits'][$code] = 'miss'; continue; }
            $out['pos'][$code] = (array)$r;
            $out['hits'][$code] = 'hit';
            $out['map'][$code] = $a;
            if ($code === 'P05' && isset($a['_cell'])) { $out['cell'] = $a['_cell']; }
        }
        if ($page === 'play') { $s = $p->playerScript(); if ($s !== null) { $out['player'] = $s; } }
        return $out;
    }

    /* ------------------------------------------------------------------ tag index */

    public function __construct($html)
    {
        $this->html = (string)$html;
        $this->index();
    }

    private function index()
    {
        $h = $this->html; $n = strlen($h); $i = 0; $stack = [];
        // Tag scanner: comments and raw-text elements (script, style, textarea, template, title, svg) are skipped,
        // so markup inside them never becomes an element and nothing is ever inserted there.
        $re = '~<(/?)([a-zA-Z][a-zA-Z0-9-]*)((?:\s+[^\s=>/]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?)*)\s*(/?)>~A';
        while ($i < $n) {
            $lt = strpos($h, '<', $i);
            if ($lt === false) { break; }
            $c2 = $h[$lt + 1] ?? '';
            if ($c2 === '!' || $c2 === '?') {
                if (substr($h, $lt, 4) === '<!--') { $e = strpos($h, '-->', $lt + 4); $i = $e === false ? $n : $e + 3; }
                else { $e = strpos($h, '>', $lt); $i = $e === false ? $n : $e + 1; }
                continue;
            }
            if (!preg_match($re, $h, $m, 0, $lt)) { $i = $lt + 1; continue; }
            $end = $lt + strlen($m[0]);
            $tag = strtolower($m[2]);
            if ($m[1] === '/') {
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($this->els[$stack[$k]][0] === $tag) {
                        for ($j = count($stack) - 1; $j >= $k; $j--) {
                            $id = $stack[$j];
                            if ($this->els[$id][4] === null) { $this->els[$id][4] = $lt; $this->els[$id][5] = $j === $k ? $end : $lt; }
                        }
                        array_splice($stack, $k);
                        break;
                    }
                }
                $i = $end;
                continue;
            }
            $id = count($this->els);
            $parent = $stack ? $stack[count($stack) - 1] : -1;
            $this->els[] = [$tag, $m[3], $lt, $end, null, null, $parent];
            if (isset(self::VOID[$tag]) || $m[4] === '/') { $this->els[$id][4] = $end; $this->els[$id][5] = $end; $i = $end; continue; }
            if (isset(self::RAW[$tag])) {
                $c = stripos($h, '</' . $tag, $end);
                $c = $c === false ? $n : $c;
                $ce = strpos($h, '>', $c);
                $ce = $ce === false ? $n : $ce + 1;
                $this->els[$id][4] = $c; $this->els[$id][5] = $ce; $i = $ce;
                continue;
            }
            $stack[] = $id;
            $i = $end;
        }
    }

    private function attr($id, $name)
    {
        $k = $id . ' ' . $name;
        if (array_key_exists($k, $this->am)) { return $this->am[$k]; }
        return $this->am[$k] = $this->attrRaw($id, $name);
    }

    private function attrRaw($id, $name)
    {
        if (stripos($this->els[$id][1], $name) === false) { return null; }
        $s = ' ' . $this->els[$id][1];
        if (preg_match('~\s' . preg_quote($name, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $s, $m)) {
            $v = isset($m[3]) && $m[3] !== '' ? $m[3] : ((isset($m[2]) && $m[2] !== '') ? $m[2] : $m[1]);
            return html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return preg_match('~\s' . preg_quote($name, '~') . '(?=\s|$|/)~i', $s . ' ') ? '' : null;
    }

    /** Compound selector: tag#id.c1.c2[attr][attr=value] */
    private static $sel = [];

    /** Parsed compound selector: [tag, quick tokens, parts] or false (memoised). */
    private static function compound($sel)
    {
        if (isset(self::$sel[$sel])) { return self::$sel[$sel]; }
        if (!preg_match('~^([a-z0-9-]*|\*)((?:[#.][\w-]+|\[[\w-]+(?:=[\w-]+)?\])*)$~i', $sel, $m) || ($m[1] === '' && $m[2] === '')) { return self::$sel[$sel] = false; }
        preg_match_all('~[#.\[]([\w-]+)~', $m[2], $qq);
        preg_match_all('~([#.])([\w-]+)|\[([\w-]+)(?:=([\w-]+))?\]~', $m[2], $parts, PREG_SET_ORDER);
        return self::$sel[$sel] = [$m[1] === '*' ? '' : strtolower($m[1]), $qq[1], $parts];
    }

    private function matchCompound($id, $sel)
    {
        $c = self::compound($sel);
        if (!$c || ($c[0] !== '' && $c[0] !== $this->els[$id][0])) { return false; }
        // Quick reject: every class / id / attribute name must occur in the raw attribute text.
        foreach ($c[1] as $q) { if (strpos($this->els[$id][1], $q) === false) { return false; } }
        foreach ($c[2] as $pp) {
            if (!empty($pp[3])) {
                $v = $this->attr($id, $pp[3]);
                if ($v === null) { return false; }
                if (isset($pp[4]) && $pp[4] !== '' && $v !== $pp[4]) { return false; }
                continue;
            }
            if ($pp[1] === '#') { if ($this->attr($id, 'id') !== $pp[2]) { return false; } continue; }
            $cls = ' ' . preg_replace('~\s+~', ' ', (string)$this->attr($id, 'class')) . ' ';
            if (strpos($cls, ' ' . $pp[2] . ' ') === false) { return false; }
        }
        return true;
    }

    private function matches($id, $sel)
    {
        $parts = preg_split('~\s+~', trim($sel));
        $last = array_pop($parts);
        if (!$this->matchCompound($id, $last)) { return false; }
        $p = $this->els[$id][6];
        while ($parts && $p >= 0) {
            if ($this->matchCompound($p, end($parts))) { array_pop($parts); }
            $p = $this->els[$p][6];
        }
        return !$parts;
    }

    private function find($sel, $nth = 1)
    {
        $c = 0;
        $nth = max(1, (int)$nth);
        foreach ($this->els as $id => $e) {
            if ($this->matches($id, $sel) && ++$c === $nth) { return $id; }
        }
        return null;
    }

    private function hidden($id)
    {
        for ($p = $id; $p >= 0; $p = $this->els[$p][6]) { if ($this->attr($p, 'hidden') !== null) { return true; } }
        return false;
    }

    private function grid($n)
    {
        if ($this->grids === null) {
            $this->grids = [];
            foreach ($this->els as $id => $e) {
                if (strpos($e[1], 'data-grid-fill') === false || $this->attr($id, 'data-grid-fill') === null) { continue; }
                if (!$this->hidden($id)) { $this->grids[] = $id; }
            }
        }
        return $this->grids[$n - 1] ?? null;
    }

    private function contains($a, $pos)
    {
        $e = $this->els[$a];
        return $e[2] <= $pos && $e[5] !== null && $pos < $e[5];
    }

    private function children($id)
    {
        if ($this->kids === null) {
            $this->kids = [];
            foreach ($this->els as $cid => $c) { if ($c[6] >= 0) { $this->kids[$c[6]][] = $cid; } }
        }
        return $this->kids[$id] ?? [];
    }

    /** Offset of the <script> that holds "var player_aaaa" (or null). */
    public function playerScript()
    {
        foreach ($this->els as $id => $e) {
            if ($e[0] === 'script' && $e[4] !== null && strpos(substr($this->html, $e[3], min(400, $e[4] - $e[3])), 'player_aaaa') !== false) { return $e[2]; }
        }
        return null;
    }

    private function special($at)
    {
        if ($at === '@player') {
            foreach ($this->els as $id => $e) {
                if ($e[0] === 'script' && $e[4] !== null && strpos(substr($this->html, $e[3], min(400, $e[4] - $e[3])), 'player_aaaa') !== false) { return $e[6] >= 0 ? $e[6] : null; }
            }
            return null;
        }
        if (preg_match('~^@grid:(\d{1,2})$~', $at, $m)) { return $this->grid(max(1, (int)$m[1])); }
        if (preg_match('~^@section-of-grid:(\d{1,2})$~', $at, $m)) {
            $k = max(1, (int)$m[1]);
            $g = $this->grid($k);
            if ($g === null) { return null; }
            $prev = $k > 1 ? $this->grid($k - 1) : null;
            $best = $g;
            $p = $this->els[$g][6];
            while ($p >= 0) {
                if ($this->els[$p][0] === 'main' || $this->attr($p, 'id') === 'main-content' || $this->els[$p][0] === 'body') { break; }
                if ($prev !== null && $this->contains($p, $this->els[$prev][2])) { break; }
                $best = $p;
                $p = $this->els[$p][6];
            }
            return $best;
        }
        return null;
    }

    /** Hook markers <div data-jcx-at="P16"></div>: place => insertion offset (after the marker). */
    public function markers()
    {
        $out = [];
        foreach ($this->els as $id => $e) {
            if ($e[0] !== 'div' || strpos($e[1], 'data-jcx-at') === false) { continue; }
            $p = $this->attr($id, 'data-jcx-at');
            if (is_string($p) && preg_match('/^P(?:0[1-9]|1[0-6]|03d)$/D', $p) && !isset($out[$p]) && $e[5] !== null) { $out[$p] = $e[5]; }
        }
        return $out;
    }

    /** Insertion offset(s) for an anchor, or null. In-feed anchors (every > 0) return a list. */
    public function resolve(array &$a)
    {
        $at = (string)($a['at'] ?? '');
        if ($at === '' || strlen($at) > 200 || !preg_match('/^(?:@player|@grid:\d{1,2}|@section-of-grid:\d{1,2}|[A-Za-z0-9_#.\-\[\]= ]{1,200})$/D', $at)) { return null; }
        $nth = (int)($a['nth'] ?? 1);
        $id = $at[0] === '@' ? $this->special($at) : $this->find($at, $nth);
        if ($id === null) { return null; }
        $e = $this->els[$id];
        if (!empty($a['every'])) {
            $kids = $this->children($id);
            $every = max(1, (int)$a['every']);
            $max = max(1, (int)($a['max'] ?? 1));
            if (count($kids) <= $every) { return []; }
            $first = $kids[0];
            $a['_cell'] = [$this->els[$first][0], !empty($a['ic']) || !array_key_exists('ic', $a) ? (string)$this->attr($first, 'class') : ''];
            $out = [];
            for ($k = $every; $k < count($kids) && count($out) < $max; $k += $every) { $out[] = $this->els[$kids[$k]][2]; }
            return $out;
        }
        if ($e[5] === null) { return null; }
        switch ($a['pos'] ?? 'afterend') {
            case 'beforebegin': return $e[2];
            case 'afterbegin': return $e[3];
            case 'beforeend': return $e[4];
            default: return $e[5];
        }
    }
}
