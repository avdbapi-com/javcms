<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

/**
 * Auto-icons for every theme (ICON-SYSTEM-BRIEF.md, developer guide: AUTO-ICON.md).
 *
 * Every category, tag, performer, menu, footer and UI entry gets an icon without admin work. Markup is rendered on
 * the server, so icons show without JavaScript, never shift layout and live inside the page cache. Every method is
 * a pure function of (name, theme declaration template/<theme>/auto-icon.json, AutoIconRules.php): no database, no
 * network. The only file reads are auto-icon.json and the scene SVGs, memoised per request.
 *
 *   {:\\app\\common\\util\\AutoIcon::head()}                                  once, inside <head>, after theme.js
 *   {:\\app\\common\\util\\AutoIcon::icon('category', $vo['type_name'], ['i'=>$k])}
 *   {:\\app\\common\\util\\AutoIcon::navIcon('latest', mac_url('label/new'))}
 *   {:\\app\\common\\util\\AutoIcon::ui('search', ['label'=>'Search'])}
 *   {:\\app\\common\\util\\AutoIcon::avatar($vo['actor_name'], $vo['actor_pic'], ['size'=>'lg','decorative'=>true])}
 *   {:\\app\\common\\util\\AutoIcon::scene('not-found')}
 *
 * Names are untrusted text. Pass them exactly as stored (stored-encoded type_name / actor_name or plain vod_*): they
 * are decoded until stable (max 3 passes) before matching, and the only name-derived text ever printed is the
 * escaped initials and the escaped aria-label. Every method returns ready, escaped markup ({:...} prints raw).
 * PHP 7.4 - 8.5, mbstring + PCRE only (no intl). JavCMS Community License 1.0, JavCMS.
 */
final class AutoIcon
{
    /** Bump on every rules, sprite, CSS or JS build (cache-busting ?v=). */
    const VERSION = 11;
    const FAMILIES = ['lucide', 'phosphor-regular', 'phosphor-light', 'phosphor-bold', 'phosphor-fill', 'phosphor-duotone',
        'tabler-outline', 'tabler-filled', 'remix-line', 'remix-fill'];
    const STROKE_FAMILIES = ['lucide', 'tabler-outline'];
    const SIZES = ['xs', 'sm', 'md', 'lg', 'xl'];
    const AVATAR_PX = ['xs' => 20, 'sm' => 32, 'md' => 48, 'lg' => 96, 'xl' => 160];
    const MOTIONS = ['draw', 'swing', 'float', 'bounce', 'jelly', 'stamp', 'pulse', 'step', 'flip', 'glow', 'ripple', 'flicker',
        'prism', 'shimmer', 'orbit', 'tilt', 'morph'];
    const ENTERS = ['pop', 'rise', 'blur', 'step', 'spin', 'draw', 'none'];
    const PLATE_MOTIONS = ['turn', 'pulse', 'snap', 'snap60', 'snap90', 'drift', 'none'];
    const AV_SHAPES = ['circle', 'squircle', 'tile', 'square', 'hex', 'diamond', 'gem'];
    const AV_STYLES = ['gradient', 'mono', 'duotone', 'outline', 'heat', 'dither', 'ring', 'glass', 'neon', 'ink', 'spectral'];
    const CODE_RX = '/^(?:\d{0,4}[A-Z]{2,8}\d{0,5}[A-Z]?|[A-Z]{1,6}-?\d{2,6}|[A-Z0-9]{2,8}More|[A-Z]{2,6}s)$/';
    const ESC = ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5;

    private static $rules = null;
    private static $theme = null;
    private static $memo = [];
    private static $scenes = [];

    // ------------------------------------------------------------------------------------------------ rules + text

    private static function rules()
    {
        if (self::$rules === null) {
            $r = include __DIR__ . '/AutoIconRules.php';
            $r['stop_set'] = array_fill_keys($r['stop'], true);
            $r['studio_set'] = array_fill_keys($r['studios'], true);
            $r['person_set'] = array_fill_keys($r['person'], true);
            $r['placeholder_set'] = array_fill_keys($r['placeholders'], true);
            $r['id_set'] = array_fill_keys($r['ids'], true);
            $r['mirror_set'] = array_fill_keys($r['mirror'], true);
            self::$rules = $r;
        }
        return self::$rules;
    }

    /** Entity decoding (<= 3 passes), full-width ASCII fold, lowercase, every non letter/number/mark run -> one space. */
    public static function normalise($name)
    {
        // final sigma folded to sigma: PHP < 8.3 has no final-sigma rule, PHP >= 8.3 and JS do (keeps 7.4 = 8.5 = JS)
        $s = str_replace('ς', 'σ', mb_strtolower(self::fold(self::decode($name)), 'UTF-8'));
        $s = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', $s);
        return $s === null ? '' : trim($s);
    }

    /** Stored-encoded -> plain text. Invalid UTF-8 becomes U+FFFD, so later PCRE /u calls never fail on it. */
    private static function decode($s)
    {
        $s = self::str($s);
        if (!mb_check_encoding($s, 'UTF-8')) { $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8'); }
        // Pinned decoder, identical in engine.py and auto-icon-core.js: the htmlspecialchars set + nbsp + valid numeric
        // references (everything JavCMS stores). Unknown names, &#0; and surrogates stay literal. <= 3 passes.
        for ($i = 0; $i < 3 && strpos($s, '&') !== false; $i++) {
            $d = preg_replace_callback('/&(#[xX][0-9a-fA-F]{1,6}|#[0-9]{1,7}|[A-Za-z]{2,6});/', function ($m) {
                static $named = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'nbsp' => "\u{00A0}"];
                $e = $m[1];
                if ($e[0] !== '#') { return isset($named[$e]) ? $named[$e] : $m[0]; }
                $cp = ($e[1] === 'x' || $e[1] === 'X') ? hexdec(substr($e, 2)) : (int)substr($e, 1);
                return ($cp > 0 && $cp < 0x110000 && ($cp < 0xD800 || $cp > 0xDFFF)) ? mb_chr($cp, 'UTF-8') : $m[0];
            }, $s);
            if ($d === null || $d === $s) { break; }
            $s = $d;
        }
        return $s;
    }

    /** Scalar -> string without warnings: NAN/INF floats (PHP 8.5 warns on (string)NAN), arrays, objects, null -> ''. */
    private static function str($s)
    {
        if (is_string($s)) { return $s; }
        if (is_int($s) || is_bool($s) || (is_float($s) && is_finite($s))) { return (string)$s; }
        return '';
    }

    /** Plain text of a stored value (pinned decoder): for labels printed next to icons or in data-name attributes. */
    public static function text($s)
    {
        return self::decode($s);
    }

    private static function fold($s)
    {
        if (!preg_match('/[\x{FF01}-\x{FF5E}\x{3000}]/u', $s)) { return $s; }
        return preg_replace_callback('/[\x{FF01}-\x{FF5E}\x{3000}]/u', function ($m) {
            $o = mb_ord($m[0], 'UTF-8');
            return $o === 0x3000 ? ' ' : chr($o - 0xFEE0);
        }, $s);
    }

    private static function hasCjk($s)
    {
        return preg_match('/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{0E00}-\x{0E7F}\x{F900}-\x{FAFF}]/u', $s) === 1;
    }

    private static function esc($s)
    {
        return htmlspecialchars((string)$s, self::ESC, 'UTF-8');
    }

    /** FNV-1a 32 over UTF-8 bytes (identical to auto-icon-core.js via Math.imul and to engine.py). */
    public static function hash($s)
    {
        $h = 0x811C9DC5;
        $s = self::str($s);
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $h ^= ord($s[$i]);
            $h = ($h * 0x01000193) & 0xFFFFFFFF;
        }
        return $h;
    }

    /** Per-name seed: FNV-1a 32 of the normalised raw stored name (never the translated label). */
    public static function seed($name)
    {
        return self::hash(self::normalise($name));
    }

    /** Colour slot 1..4 (--ai-c1..--ai-c4). */
    public static function slot($name)
    {
        return 1 + self::seed($name) % 4;
    }

    private static function matchRules(array $R, $n)
    {
        $toks = explode(' ', $n);
        $cnt = count($toks);
        $best = PHP_INT_MAX;
        foreach ($toks as $i => $t) {
            $keys = mb_strlen($t, 'UTF-8') < 2 ? [mb_substr($t, 0, 1, 'UTF-8')] : [mb_substr($t, 0, 1, 'UTF-8'), mb_substr($t, 0, 2, 'UTF-8')];
            foreach ($keys as $key) {
                if (!isset($R['tok'][$key])) { continue; }
                foreach ($R['tok'][$key] as $entry) {
                    list($gi, $ptoks, $exact) = $entry;
                    $pl = count($ptoks);
                    if ($gi >= $best || $i + $pl > $cnt) { continue; }
                    $ok = true;
                    for ($j = 0; $j < $pl; $j++) {
                        $nt = $toks[$i + $j];
                        if (($exact >> $j) & 1) {
                            if ($nt !== $ptoks[$j]) { $ok = false; break; }
                        } elseif (strncmp($nt, $ptoks[$j], strlen($ptoks[$j])) !== 0) {
                            $ok = false; break;
                        }
                    }
                    if ($ok) { $best = $gi; }
                }
            }
        }
        if (self::hasCjk($n)) {
            $d = str_replace(' ', '', $n);
            foreach ($R['sub'] as $entry) {
                if ($entry[0] < $best && strpos($d, $entry[1]) !== false) { $best = $entry[0]; }
            }
        }
        return $best === PHP_INT_MAX ? null : $R['groups'][$best];
    }

    /** ['concept' => id, 'how' => empty|override|alias|rule|stop|studio|number|person|generic] */
    public static function explain($kind, $name)
    {
        $kind = $kind === 'category' ? 'category' : 'tag';
        $name = self::str($name);
        $key = $kind . "\0" . $name;
        if (isset(self::$memo[$key])) { return self::$memo[$key]; }
        $R = self::rules();
        // PHP trim() set only (" \t\n\r\0\x0B"); auto-icon-core.js ptrim() strips exactly the same set
        $raw = trim(self::fold(self::decode($name)));
        $n = self::normalise($name);
        $map = self::theme()['map'];
        if ($n === '') {
            $res = [$R['special']['empty'], 'empty'];
        } elseif (isset($map[$n])) {
            $res = [$map[$n], 'override'];
        } elseif (isset($R['aliases'][$n])) {
            $res = [$R['aliases'][$n], 'alias'];
        } elseif (($c = self::matchRules($R, $n)) !== null) {
            $res = [$c, 'rule'];
        } elseif (isset($R['stop_set'][$n])) {
            $res = [$R['special']['stop'], 'stop'];
        } else {
            $toks = explode(' ', $n);
            $allStudio = true;
            foreach ($toks as $t) { if (!isset($R['studio_set'][$t])) { $allStudio = false; break; } }
            if ($allStudio || ($kind === 'tag' && preg_match(self::CODE_RX, str_replace([' ', '.'], '', $raw)) === 1)) {
                $res = [$R['special']['studio'], 'studio'];
            } elseif (ctype_digit($n)) {
                $res = [$R['special']['number'], 'number'];
            } else {
                $person = count($toks) <= 3;
                foreach ($toks as $t) { if (!isset($R['person_set'][$t])) { $person = false; break; } }
                $res = $person ? [$R['special']['person'], 'person']
                    : [$R['generic'][self::hash($n) % count($R['generic'])], 'generic'];
            }
        }
        if (count(self::$memo) > 4096) { self::$memo = []; }
        return self::$memo[$key] = ['concept' => $res[0], 'how' => $res[1]];
    }

    /** Concept id for a category (type_name), a tag/genre (vod_class, vod_tag token) or a nav key. */
    public static function concept($kind, $name)
    {
        if ($kind === 'nav') { return self::nav(self::str($name)); }
        return self::explain($kind, $name)['concept'];
    }

    /** False for vod_tag noise (stop words such as "who"/"her", bare numbers, empty): themes skip those chips. */
    public static function keepTag($name)
    {
        $how = self::explain('tag', $name)['how'];
        return $how !== 'stop' && $how !== 'number' && $how !== 'empty';
    }

    /** Concept id of a menu/footer entry: by semantic key ('latest', 'favourites', 'colour-mode' ...) and/or URL path. */
    public static function nav($key = '', $url = '')
    {
        $R = self::rules();
        $theme = self::theme();
        $k = str_replace(' ', '-', self::normalise($key));
        if ($k !== '' && isset($theme['nav'][$k])) { return $theme['nav'][$k]; }
        if ($k !== '' && isset($R['nav_keys'][$k])) { return $R['nav_keys'][$k]; }
        $url = self::str($url);
        if ($url === '') { return $k !== '' ? 'link' : 'g-circle'; }
        $path = (string)preg_replace('~^[a-z][a-z0-9+.-]*://[^/]+~i', '', $url);
        $path = (string)preg_replace('~[?#].*$~s', '', $path);
        $path = rtrim((string)preg_replace('~\.(html?|xml|php)$~i', '', $path), '/');
        if ($path === '') { return $R['nav_keys']['home']; }
        $segs = explode('/', $path);
        foreach ($R['nav_paths'] as $row) {
            list($pat, $target) = $row;
            if ($pat === '/') { continue; }
            $ps = explode('/', rtrim($pat, '/'));
            if (count($ps) > count($segs)) { continue; }
            $ok = true;
            foreach ($ps as $i => $p) {
                if (substr($p, -1) === '*') {
                    if (strncmp($segs[$i], substr($p, 0, -1), strlen($p) - 1) !== 0) { $ok = false; break; }
                } elseif ($p !== $segs[$i]) { $ok = false; break; }
            }
            if ($ok && (count($ps) === count($segs) || substr(end($ps), -1) === '*')) {
                return $target === '@category' ? 'grid' : $R['nav_keys'][$target];
            }
        }
        return 'link';
    }

    // ------------------------------------------------------------------------------------------------ theme

    /** Site root (this file lives in application/common/util/). */
    private static function root()
    {
        return defined('ROOT_PATH') ? rtrim(str_replace('\\', '/', (string)ROOT_PATH), '/') . '/' : dirname(__DIR__, 3) . '/';
    }

    /**
     * For tests, the demo and previews only: replace the active theme declaration (null = read the active theme
     * again) and clear the per-request memo. Templates never call this.
     */
    public static function reset($declaration = null)
    {
        self::$theme = null;
        self::$memo = [];
        if (is_array($declaration)) { self::$theme = self::sanitise($declaration); }
    }

    /** Declaration of the active theme: template/<theme>/auto-icon.json (hashed into the page cache with the theme). */
    public static function theme()
    {
        if (self::$theme !== null) { return self::$theme; }
        $j = [];
        $dir = defined('MAC_TEMPLATE_DIR') ? (string)MAC_TEMPLATE_DIR : '';
        if ($dir !== '' && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $dir) === 1) {
            $file = self::root() . 'template/' . $dir . '/auto-icon.json';
            if (is_file($file)) {
                $d = json_decode((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($file)), true);
                if (is_array($d)) { $j = $d; }
            }
        }
        return self::$theme = self::sanitise($j);
    }

    private static function word($v, array $allowed, $default)
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : $default;
    }

    private static function sanitise(array $j)
    {
        $t = ['family' => 'lucide', 'stroke' => '', 'motion' => '', 'enter' => 'pop', 'plate' => 'none', 'plateMotion' => 'none',
              'avatar' => 'circle gradient', 'hue' => '', 'tint' => 'slot', 'sprite' => '', 'map' => [], 'nav' => [], 'tokens' => [],
              'enabled' => true];
        $t['family'] = self::word($j['family'] ?? '', self::FAMILIES, 'lucide');
        if (isset($j['stroke']) && is_numeric($j['stroke']) && $j['stroke'] >= 0.5 && $j['stroke'] <= 3) {
            $t['stroke'] = rtrim(rtrim(sprintf('%.2f', (float)$j['stroke']), '0'), '.');
        }
        $mo = [];
        foreach (preg_split('/\s+/', is_string($j['motion'] ?? null) ? $j['motion'] : '', -1, PREG_SPLIT_NO_EMPTY) as $m) {
            if (in_array($m, self::MOTIONS, true) && !in_array($m, $mo, true)) { $mo[] = $m; }
        }
        $t['motion'] = implode(' ', array_slice($mo, 0, 3));
        $t['enter'] = self::word($j['enter'] ?? ($j['entrance'] ?? ''), self::ENTERS, 'pop');
        $plates = self::rules()['plates'];
        $plate = $j['plate'] ?? 'none';
        if (is_string($plate) && strpos($plate, ' ') !== false) { list($plate, $pm) = explode(' ', $plate, 2); $j['plateMotion'] = $j['plateMotion'] ?? $pm; }
        $t['plate'] = self::word($plate, $plates, 'none');
        $t['plateMotion'] = $t['plate'] === 'none' ? 'none' : self::word($j['plateMotion'] ?? 'none', self::PLATE_MOTIONS, 'none');
        $av = preg_split('/\s+/', trim((is_string($j['avatar'] ?? null) ? $j['avatar'] : '') . ' ' . (is_string($j['avatarStyle'] ?? null) ? $j['avatarStyle'] : '')), -1, PREG_SPLIT_NO_EMPTY);
        $shape = 'circle';
        $style = 'gradient';
        foreach ($av as $w) {
            if (in_array($w, self::AV_SHAPES, true)) { $shape = $w; } elseif (in_array($w, self::AV_STYLES, true)) { $style = $w; }
        }
        $t['avatar'] = $shape . ' ' . $style;
        if (isset($j['hue']) && is_array($j['hue']) && count($j['hue']) === 2 && is_numeric($j['hue'][0]) && is_numeric($j['hue'][1])) {
            $t['hue'] = [((int)$j['hue'][0] % 360 + 360) % 360, max(10, min(360, (int)$j['hue'][1]))];
        }
        $t['tint'] = self::word($j['tint'] ?? 'slot', ['slot', 'accent', 'none'], 'slot');
        $sprite = $j['sprite'] ?? '';
        if (is_string($sprite) && preg_match('~\A/?[A-Za-z0-9_][A-Za-z0-9_./-]{0,199}\.svg\z~', $sprite) && strpos($sprite, '..') === false && strpos($sprite, '//') === false) {
            $t['sprite'] = $sprite;
        }
        foreach (['map', 'nav'] as $f) {
            if (!isset($j[$f]) || !is_array($j[$f])) { continue; }
            foreach ($j[$f] as $name => $cid) {
                if (!is_string($cid) || !preg_match('/\A[a-z0-9-]{1,40}\z/', $cid) || !isset(self::rules()['id_set'][$cid])) { continue; }
                $k = $f === 'map' ? self::normalise($name) : str_replace(' ', '-', self::normalise($name));
                if ($k !== '') { $t[$f][$k] = $cid; }
            }
        }
        // --ai-* token defaults: only var(--name) references (optionally with a #hex fallback), so the theme's own
        // dark/light variables keep deciding the colour; never a literal colour baked into markup.
        if (isset($j['tokens']) && is_array($j['tokens'])) {
            foreach ($j['tokens'] as $k => $v) {
                if (is_string($k) && preg_match('/\A--ai-(c[1-4]|ink|bg|plate|plate-o|pf|ps|sw|size|duo|duo-o)\z/', $k)
                    && is_string($v) && preg_match('/\A(var\(--[a-z0-9-]{1,40}(,\s?#[0-9a-fA-F]{3,8})?\)|0?\.\d{1,3}|1|[0-9.]{1,4}(em|px))\z/', $v)) {
                    $t['tokens'][$k] = $v;
                }
            }
        }
        if (isset($j['enabled']) && $j['enabled'] === false) { $t['enabled'] = false; }
        // Recovery switch (application/extra/maccms.php: 'site' => ['auto_icon' => '0']): every method returns ''.
        if (isset($GLOBALS['config']['site']['auto_icon']) && (string)$GLOBALS['config']['site']['auto_icon'] === '0') { $t['enabled'] = false; }
        return $t;
    }

    public static function enabled()
    {
        return self::theme()['enabled'];
    }

    /** Public URL path of the site root ('/' or '/sub/'), from MAC_PATH or site.install_dir. */
    private static function sitePath()
    {
        if (defined('MAC_PATH')) {
            $root = (string)MAC_PATH;
        } else {
            $root = isset($GLOBALS['config']['site']['install_dir']) ? (string)$GLOBALS['config']['site']['install_dir'] : '/';
        }
        // any absolute path without controls, spaces, quotes, angle brackets, backslash, ?, # or '..' (/~user/,
        // /jav%20cms/, non-ASCII folders); every printed URL is escaped by esc() or the JSON hex flags
        if (!preg_match('~\A/[^\x00-\x20\x7F"\'<>\\\\`?#]{0,200}\z~', $root) || !preg_match('//u', $root) || strpos($root, '..') !== false || strpos($root, '//') !== false) { $root = '/'; }
        return rtrim($root, '/') . '/';
    }

    private static function base()
    {
        return self::sitePath() . 'static/template-ui/';
    }

    /** URL of the theme's sprite (one request per page, cached). */
    public static function spriteUrl()
    {
        $t = self::theme();
        if ($t['sprite'] !== '') {
            return ($t['sprite'][0] === '/' ? '' : self::sitePath()) . $t['sprite'] . '?v=' . self::VERSION;
        }
        return self::base() . 'auto-icon/sprite-' . $t['family'] . '.svg?v=' . self::VERSION;
    }

    /** Attribute map for <html>: the CSS presets key off these (one stylesheet serves all 18 themes). */
    public static function rootAttrs()
    {
        $t = self::theme();
        if (!$t['enabled']) { return []; }
        $a = [
            'data-ai-family' => $t['family'],
            'data-ai-kind' => in_array($t['family'], self::STROKE_FAMILIES, true) ? 'stroke' : 'fill',
            'data-ai-motion' => $t['motion'],
            'data-ai-enter' => $t['enter'],
            'data-ai-plate' => $t['plate'] . ' ' . $t['plateMotion'],
            'data-ai-avatar' => $t['avatar'],
            'data-ai-tint' => $t['tint'],
            'data-ai-sprite' => self::spriteUrl(),
        ];
        return $a;
    }

    /** Inline custom properties for <html> (stroke width, avatar hue range, var() token defaults). */
    private static function rootStyle()
    {
        $t = self::theme();
        $s = [];
        if ($t['stroke'] !== '') { $s[] = '--ai-sw:' . $t['stroke']; }
        if (is_array($t['hue'])) { $s[] = '--ai-hue-base:' . $t['hue'][0]; $s[] = '--ai-hue-span:' . $t['hue'][1]; }
        foreach ($t['tokens'] as $k => $v) { $s[] = $k . ':' . $v; }
        return implode(';', $s);
    }

    /**
     * Once per page inside <head>, after theme.js: (1) a tiny inline script that copies the theme's data-ai-* attributes
     * onto <html> before <body> parses, (2) the stylesheet, (3) the deferred module, (4) #ai-config when the theme
     * overrides names or nav keys. '' when switched off. No sprite preload: an external <use href> never consumes an
     * as=image (or as=fetch) preload, so a preload would double the sprite request; the parser finds the first <use>.
     */
    public static function head()
    {
        if (!self::enabled()) { return ''; }
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $style = self::rootStyle();
        $js = 'var h=document.documentElement,a=' . json_encode(self::rootAttrs(), $flags) . ';for(var k in a)h.setAttribute(k,a[k]);'
            . ($style !== '' ? 'h.style.cssText+=' . json_encode(';' . $style, $flags) . ';' : '');
        $v = '?v=' . self::VERSION;
        $out = '<script>' . $js . '</script>'
             . '<link rel="stylesheet" href="' . self::esc(self::base() . 'auto-icon.css' . $v) . '">'
             . '<script type="module" src="' . self::esc(self::base() . 'auto-icon.js' . $v) . '"></script>';
        $t = self::theme();
        if ($t['map'] || $t['nav']) {
            $out .= '<script type="application/json" id="ai-config">'
                  . json_encode(['map' => (object)$t['map'], 'nav' => (object)$t['nav']], $flags | JSON_UNESCAPED_UNICODE)
                  . '</script>';
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------ markup

    /** Icon for a name. $kind: category | tag | nav (for nav, $name is the key and $o['url'] an optional URL). */
    public static function icon($kind, $name, array $o = [])
    {
        if (!self::enabled()) { return ''; }
        if ($kind === 'nav') {
            return self::markup(self::nav($name, $o['url'] ?? ''), $o, null, true);
        }
        return self::markup(self::concept($kind, $name), $o, self::seed($name), false);
    }

    /** Header, drawer, dock and footer entries by semantic key and/or URL (unknown -> link). */
    public static function navIcon($key, $url = '', array $o = [])
    {
        $o['url'] = $url;
        return self::icon('nav', $key, $o);
    }

    /** Controls and UI chrome by concept id of icon-concepts.json ('search', 'languages', 'contrast' ...). Unknown -> ''. */
    public static function ui($concept, array $o = [])
    {
        if (!self::enabled() || !is_string($concept) || !isset(self::rules()['id_set'][$concept])) { return ''; }
        return self::markup($concept, $o, null, false);
    }

    private static function size(array $o, $default)
    {
        return isset($o['size']) && in_array($o['size'], self::SIZES, true) ? $o['size'] : $default;
    }

    /** Boolean option: truthy like !empty(), but NAN is false (coercing NAN to bool warns on PHP 8.5). */
    private static function flag(array $o, $k)
    {
        if (!isset($o[$k])) { return false; }
        $v = $o[$k];
        return !(is_float($v) && is_nan($v)) && !empty($v);
    }

    private static function classes(array $o)
    {
        return isset($o['class']) && is_string($o['class']) && preg_match('/\A[A-Za-z0-9 _-]{1,100}\z/', $o['class']) ? ' ' . trim($o['class']) : '';
    }

    /** The one place icon markup is built (PHP); auto-icon.js builds the identical tree for late content. */
    private static function markup($cid, array $o, $h, $isNav)
    {
        $R = self::rules();
        $t = self::theme();
        $cid = isset($R['id_set'][$cid]) ? $cid : 'g-circle';
        $size = self::size($o, 'md');
        $plated = self::flag($o, 'plate') && ($size === 'lg' || $size === 'xl') && $t['plate'] !== 'none';
        $href = self::esc(self::spriteUrl());
        $attr = ' class="ai ai-' . $size . ($plated ? ' ai-plated' : '') . self::classes($o) . '" data-ai="' . $cid . '"';
        if ($h !== null) { $attr .= ' data-ai-s="' . (1 + $h % 4) . '"'; }
        $tint = isset($o['tint']) && in_array($o['tint'], ['slot', 'accent', 'none'], true) ? $o['tint'] : '';
        if ($tint !== '') { $attr .= ' data-ai-tint="' . $tint . '"'; }
        if (isset($R['mirror_set'][$cid])) { $attr .= ' data-ai-flip=""'; }
        if ($isNav && strpos(' ' . $t['motion'] . ' ', ' morph ') !== false && isset($R['morph'][$cid])) {
            $attr .= ' data-ai-morph="' . self::esc($R['morph'][$cid]) . '"';
        }
        $style = [];
        // clamp in float space: (int) of INF/NAN or an out-of-range float warns on PHP 8.5
        if (isset($o['i']) && is_numeric($o['i']) && is_finite((float)$o['i'])) { $style[] = '--ai-i:' . (int)max(0, min(23, (float)$o['i'])); }
        if ($plated) {
            $ph = $h !== null ? $h : self::hash($cid);
            $var = ($ph >> 8) % 3;
            if (in_array($t['plate'], $R['plate_rot'], true)) { $style[] = '--ai-rot:' . ((($ph >> 12) % 12) * 30) . 'deg'; }
        }
        if ($style) { $attr .= ' style="' . implode(';', $style) . '"'; }
        $label = isset($o['label']) && is_scalar($o['label']) ? trim(self::decode($o['label'])) : '';
        $attr .= $label !== '' ? ' role="img" aria-label="' . self::esc($label) . '"' : ' aria-hidden="true"';
        $orbit = strpos(' ' . $t['motion'] . ' ', ' orbit ') !== false;
        if ($plated) {
            $body = '<use class="ai-pl" href="' . $href . '#pl-' . $t['plate'] . '-' . $var . '" width="32" height="32"/>'
                  . '<use class="ai-g" href="' . $href . '#ai-' . $cid . '" x="7" y="7" width="18" height="18"/>'
                  . ($orbit ? '<circle class="ai-o" cx="16" cy="1.6" r="1.5"/><circle class="ai-o ai-o2" cx="16" cy="30.4" r="1.1"/>' : '');
            return '<svg' . $attr . ' focusable="false" viewBox="0 0 32 32">' . $body . '</svg>';
        }
        $body = '<use class="ai-g" href="' . $href . '#ai-' . $cid . '"/>'
              . ($orbit ? '<circle class="ai-o" cx="12" cy="1.6" r="1.5"/><circle class="ai-o ai-o2" cx="12" cy="22.4" r="1.1"/>' : '');
        return '<svg' . $attr . ' focusable="false" viewBox="0 0 24 24">' . $body . '</svg>';
    }

    /** Initials: the Latin part first ("Yuuki Hiiragi 柊ゆうき" -> "YH"), otherwise the first code point. No intl. */
    public static function initials($name)
    {
        // alias suffixes in brackets or quotes are dropped ("Ai & Yu \"Q\" <x>" -> "Ai & Yu"), unless nothing is left
        // C0/C1 controls -> space, invisible format characters (ZWSP, word joiner, BOM, MVS) dropped: same rule in JS
        $full = trim((string)preg_replace(['/[\x{0}-\x{1F}\x{7F}-\x{9F}]/u', '/[\x{200B}\x{2060}\x{FEFF}\x{180E}]/u'], [' ', ''], self::decode($name)));
        $s = trim((string)preg_replace('/\s*[(（\[<"「【].*$/us', '', $full));
        if ($s === '') { $s = $full; }
        $latin = (string)preg_replace('/[^\p{Latin}\s\'-]+/u', ' ', $s);
        $words = preg_split('/[\s\'-]+/u', trim($latin), -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) { $words = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY); }
        if (!$words) { return '?'; }
        $out = mb_substr($words[0], 0, 1, 'UTF-8');
        if (count($words) > 1 && preg_match('/\p{Latin}/u', $out)) { $out .= mb_substr(end($words), 0, 1, 'UTF-8'); }
        return mb_strtoupper($out, 'UTF-8');
    }

    /** Avatar visual seed: two hues, pattern 0-7, pattern rotation, gem cut 0-6. Same numbers in auto-icon-core.js. */
    public static function avatarSeed($name)
    {
        $h = self::seed($name);
        $h1 = $h % 360;
        return ['h1' => $h1, 'h2' => ($h1 + 40 + (($h >> 9) % 100)) % 360, 'pat' => ($h >> 17) % 8, 'rot' => (($h >> 20) % 12) * 30, 'cut' => ($h >> 24) % 7];
    }

    /**
     * Performer avatar. The generated face (gradient + CSS pattern layer + initials, or the cast glyph for placeholder
     * strings such as "Actresses & Casts") is always rendered; a non-empty picture is layered on top as the LAST child,
     * so a broken picture (alt="") shows the generated face even without JavaScript.
     * Options: size xs|sm|md|lg|xl (20/32/48/96/160 px), decorative (true when the name is printed next to it), class.
     */
    public static function avatar($name, $pic = '', array $o = [])
    {
        if (!self::enabled()) { return ''; }
        $R = self::rules();
        $size = self::size($o, 'md');
        $plain = trim(self::decode($name));
        $n = self::normalise($name);
        $a11y = self::flag($o, 'decorative') || $plain === '' ? ' aria-hidden="true"' : ' role="img" aria-label="' . self::esc($plain) . '"';
        $cls = 'ai-av ai-av-' . $size . self::classes($o);
        $picUrl = '';
        $pic = is_scalar($pic) ? trim(self::decode($pic)) : '';
        if ($pic !== '') {
            $u = function_exists('mac_url_img') ? (string)mac_url_img($pic) : $pic;
            $u = html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // only http(s), protocol-relative or path URLs; anything else (javascript:, data:) is dropped
            if ($u !== '' && !preg_match('/[\x00-\x1F\x7F\\\\]/', $u) && (preg_match('~\A(https?:)?//~i', $u) || !preg_match('~\A[a-z][a-z0-9+.-]*:~i', $u))) {
                $picUrl = $u;
            }
        }
        $img = '';
        if ($picUrl !== '') {
            $cls .= ' ai-av-img';
            $px = self::AVATAR_PX[$size];
            $img = '<img src="' . self::esc($picUrl) . '" alt="" loading="lazy" decoding="async" width="' . $px . '" height="' . $px . '">';
        }
        if ($n === '' || isset($R['placeholder_set'][$n])) {
            return '<span class="' . $cls . ' ai-av-cast"' . $a11y . '>' . self::markup('users', ['size' => 'md', 'tint' => 'none'], null, false) . $img . '</span>';
        }
        $s = self::avatarSeed($name);
        // v6: the pattern is a CSS gradient layer (.ai-av[data-pat]::before), not an external-sprite <svg><use>: 60
        // cloned <use> shadow trees cost an 80-114 ms first-render long task on a 4x throttled phone (REVIEW r2 P1-1)
        return '<span class="' . $cls . '"' . $a11y . ' style="--h1:' . $s['h1'] . ';--h2:' . $s['h2'] . ';--rot:' . $s['rot'] . 'deg" data-cut="' . $s['cut'] . '" data-pat="' . $s['pat'] . '">'
             . '<b>' . self::esc(self::initials($name)) . '</b>' . $img . '</span>';
    }

    /**
     * Animated scene for empty states and 404: one of the six vendored SVG twins (static/vendor/fx/svg-anims, original
     * JavCMS art), inlined so it takes the theme colours (--fx-accent ... mapped from --ai-* by auto-icon.css). The SVG
     * keeps its own reduced-motion rule. Options: class, label (accessible name; otherwise decorative).
     */
    public static function scene($id, array $o = [])
    {
        if (!self::enabled() || !is_string($id) || !in_array($id, self::rules()['scenes'], true)) { return ''; }
        if (!isset(self::$scenes[$id])) {
            $f = self::root() . 'static/vendor/fx/svg-anims/' . $id . '.svg';
            $svg = is_file($f) ? (string)file_get_contents($f) : '';
            $svg = (string)preg_replace('/<\?xml.*?\?>|<!--.*?-->/s', '', $svg);
            self::$scenes[$id] = preg_match('/\A\s*<svg\b/', $svg) && stripos($svg, '<script') === false && !preg_match('/\son[a-z]+\s*=/i', $svg) ? trim($svg) : '';
        }
        if (self::$scenes[$id] === '') { return ''; }
        $label = isset($o['label']) && is_scalar($o['label']) ? trim(self::decode($o['label'])) : '';
        $a11y = $label !== '' ? ' role="img" aria-label="' . self::esc($label) . '"' : ' aria-hidden="true"';
        return '<span class="ai-scene ai-scene-' . $id . self::classes($o) . '"' . $a11y . '>' . self::$scenes[$id] . '</span>';
    }
}
