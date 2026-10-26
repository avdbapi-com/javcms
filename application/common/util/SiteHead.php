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
 * Central head decorator (SEO-ADMIN-BRIEF C.1, B.4, C.9, F). Runs in All::fetch() after ThemeSwitcher::decorate and
 * before JcxPlacer::decorate (hand-over HO-S1), on cache misses only, before the page cache stores the HTML.
 *
 * It edits only <head> (charset first, the site icon set, manifest, theme-color, social de-duplication, exactly one
 * rating meta (+ RTA), hide-site robots, verification metas on the home page, the owner's head code / GA4, the age-gate
 * early script) and adds three optional body inserts: the legal links row, the age gate and the cookie notice, with
 * static/site/jv-site.css / .js. Nothing per visitor is printed: every choice is made in the browser (F.2, F.3).
 *
 * decorate() never throws: on any error it returns the input unchanged. It is idempotent (marker data-jv-head on the
 * rating meta, CF14). cacheKeyPart() / coreFiles() feed the page-cache key (hand-over HO-S2).
 */
class SiteHead
{
    /** Crawlers never get the age gate (F.2, CF21). Case-insensitive; every listed bot name contains one of these words. */
    const CRAWLERS = 'bot|crawl|spider|slurp|yeti|facebookexternalhit|inspectiontool|mediapartners|lighthouse';
    /** Front strings (catalog keys of I.2) with their English values until the catalogs carry them (HO-S3). */
    const STR = [
        'legal.footer_label' => 'Legal',
        'legal.terms' => 'Terms of use',
        'legal.privacy' => 'Privacy policy',
        'legal.dmca' => 'Copyright (DMCA)',
        'legal.2257' => '2257 statement',
        'legal.cookies' => 'Cookie policy',
        'age.title' => 'This site is for adults only',
        'age.text' => 'It contains sexually explicit material. Enter only if you are at least 18 years old (or the age of majority where you live).',
        'age.enter' => "I am 18 or older \u{2014} Enter",
        'age.leave' => 'Leave',
        'cookie.label' => 'Cookie notice',
        'cookie.text' => 'We use cookies for statistics and ads. You can accept or reject them.',
        'cookie.more' => 'Cookie policy',
        'cookie.accept' => 'Accept',
        'cookie.reject' => 'Reject',
    ];
    const ICON_RELS = ['icon', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'mask-icon'];

    /** Last decorate() facts for tools / tests: ['kind', 'skip', 'ms', 'inserts' => [...]]. */
    public static $last = [];
    private static $masked = [];

    // ------------------------------------------------------------------ cache key (G, HO-S2)

    /**
     * Page-cache key part: data files whose text prints into pages (SeoSettings::dataFiles(): seo/custom.php,
     * site/legal/*.json) by name + mtime, the core files' mtimes and the ads consent flag (CF15: the cookie notice in
     * "auto" mode depends on it). Files only, no database: it runs on cache hits too.
     */
    public static function cacheKeyPart()
    {
        $parts = [];
        foreach (SeoSettings::dataFiles() as $file) {
            $parts[] = basename($file) . ':' . (is_file($file) ? filemtime($file) : 0);
        }
        foreach (self::coreFiles() as $file) {
            $parts[] = is_file($file) ? filemtime($file) : 0;
        }
        $parts[] = 'consent:' . self::adsConsentFlag();
        if (defined('JV_SITEHEAD_TEE')) { $parts[] = 'tee:' . JV_SITEHEAD_TEE; }
        return 'sh' . substr(sha1(implode('|', $parts)), 0, 16);
    }

    /** Files whose edit must refresh cached HTML (their mtimes go into the key, HO-S2). */
    public static function coreFiles()
    {
        $root = SiteIcons::root();
        return [__FILE__, __DIR__ . '/SeoSettings.php', __DIR__ . '/LegalPages.php', __DIR__ . '/SiteIcons.php', __DIR__ . '/AvdbLanguage.php',
            $root . 'static/site/jv-site.js', $root . 'static/site/jv-site.css'];
    }

    /** CF15: (int)((JcxPlacer::conf() ?? [])['consent'] ?? 0). */
    public static function adsConsentFlag()
    {
        if (!class_exists('app\\common\\util\\JcxPlacer')) { return 0; }
        $conf = JcxPlacer::conf();
        return (int)((is_array($conf) ? $conf : [])['consent'] ?? 0);
    }

    // ------------------------------------------------------------------ page kinds (C.1 step 0)

    /** Template path as ThemeSwitcher::excluded() normalises it ('vod/play', 'public/close', ...). */
    public static function path($tpl)
    {
        $path = strtolower(str_replace('\\', '/', trim((string)$tpl)));
        $path = preg_replace('~\.html?\z~', '', $path);
        $path = preg_replace('~\A[a-z0-9_]+@~', '', $path);
        // An absolute template file (e.g. the legal fallback view): only its last two segments count.
        if (preg_match('~\A(?:[a-z]:/|/)~', $path)) { $path = implode('/', array_slice(explode('/', $path), -2)); }
        return ltrim($path, '/');
    }

    /** 'skip' (never decorated) | 'gate' | 'user' (icons, charset, rating, head code only) | 'page'. */
    public static function kind($tpl)
    {
        $path = self::path($tpl);
        if (preg_match('~(?:^|/)vod/(?:player|player_pwd|downer|downer_pwd)\z~', $path) || strpos($path, 'rss') !== false
            || strpos($path, 'ajax') !== false || preg_match('~(?:^|/)confirm\z~', $path)) {
            return 'skip';
        }
        if (preg_match('~(?:^|/)public/(?:close|jump|msg|browser|verify)\z~', $path)) { return 'gate'; }
        if (preg_match('~(?:^|/)user/~', $path)) { return 'user'; }
        return 'page';
    }

    // ------------------------------------------------------------------ decorate (C.1)

    public static function decorate($html, $tpl = '')
    {
        self::$last = [];
        if (!is_string($html) || $html === '') { return $html; }
        if (!defined('ENTRANCE') || ENTRANCE !== 'index') { return $html; }
        $t0 = microtime(true);
        try {
            $out = self::run($html, (string)$tpl);
        } catch (\Throwable $error) {
            self::$last = ['skip' => 'error: ' . $error->getMessage()];
            $out = $html;
        }
        self::$masked = [];
        self::$last['ms'] = round((microtime(true) - $t0) * 1000, 3);
        if (defined('JV_SITEHEAD_TEE')) { self::tee($html, $out, (string)$tpl); }
        return $out;
    }

    /**
     * Test hook (S-C1 head_matrix.php): when a check server defines the constant JV_SITEHEAD_TEE (a folder, through
     * auto_prepend_file; never set by the product or reachable by a visitor), the undecorated and decorated HTML of the
     * same render are written there, so the body can be compared byte for byte.
     */
    private static function tee($in, $out, $tpl)
    {
        $dir = (string)JV_SITEHEAD_TEE;
        if ($dir === '' || !is_dir($dir)) { return; }
        $name = sha1(($_SERVER['REQUEST_URI'] ?? '') . '|' . (defined('MAC_TEMPLATE_DIR') ? MAC_TEMPLATE_DIR : '') . '|' . $tpl . '|' . microtime(true));
        $dir = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR;
        @file_put_contents($dir . $name . '.in.html', $in);
        @file_put_contents($dir . $name . '.out.html', $out);
        @file_put_contents($dir . 'index.jsonl', json_encode(['uri' => $_SERVER['REQUEST_URI'] ?? '', 'theme' => defined('MAC_TEMPLATE_DIR') ? MAC_TEMPLATE_DIR : '',
            'tpl' => $tpl, 'file' => $name, 'last' => self::$last]) . "\n", FILE_APPEND);
    }

    private static function run($html, $tpl)
    {
        $kind = self::kind($tpl);
        self::$last = ['kind' => $kind, 'skip' => '', 'inserts' => []];
        if ($kind === 'skip') { self::$last['skip'] = 'template'; return $html; }
        $end = stripos($html, '</head>');
        if ($end === false) { self::$last['skip'] = 'no </head>'; return $html; }
        if (preg_match('~<html\b[^>]*\bdata-jv-head\s*=\s*["\']?off~i', substr($html, 0, 4096))) { self::$last['skip'] = 'off'; return $html; }
        $head = substr($html, 0, $end);
        $body = substr($html, $end);
        // Already decorated = one of our own tags carries data-jv-head. Inline script bodies and comments do not count: a theme
        // script may mention the attribute (e.g. to leave the owner's theme-color alone) without the head being decorated.
        $probe = preg_replace(['~(<script\b[^>]*>).*?</script>~is', '~<!--.*?-->~s'], ['$1</script>', ''], $head);
        if (stripos(is_string($probe) ? $probe : $head, 'data-jv-head') !== false) { self::$last['skip'] = 'decorated'; return $html; }

        $page = $kind === 'page';
        $legal = class_exists('app\\common\\util\\LegalPages', false) ? LegalPages::current() : '';
        $base = SiteIcons::base();
        // 2 charset in the first 1,024 bytes (measured on the unmasked head).
        $head = self::charset($head);
        $head = self::mask($head);
        $add = [];

        // 3 icons (B.4) + manifest.
        $icons = SiteIcons::active();
        $manifest = SiteIcons::manifest();
        $strip = $icons['set'] === 'theme' ? [] : self::ICON_RELS;
        if ($manifest !== '') { $strip[] = 'manifest'; }
        if ($strip) { $head = self::removeLinks($head, $strip); }
        foreach ($icons['links'] as $link) {
            $add[] = '<link rel="' . $link[0] . '" href="' . self::e($base . $link[1]) . '"' . ($link[2] !== '' ? ' type="' . $link[2] . '"' : '')
                . ($link[3] !== '' ? ' sizes="' . $link[3] . '"' : '') . ' data-jv-head>';
        }
        if ($manifest !== '') { $add[] = '<link rel="manifest" href="' . self::e($base . $manifest) . '" data-jv-head>'; }

        // 4 theme-color.
        $color = (string)SeoSettings::get('site.site_theme_color');
        if ($page && $color !== '') {
            $head = self::removeMetas($head, ['theme-color']);
            $add[] = '<meta name="theme-color" content="' . self::e($color) . '" data-jv-head>';
        }

        // 5 social de-duplication with the theme's own tags, then the jv-seo markers go.
        $head = self::social($head);

        // 6 exactly one rating meta (+ RTA); its data-jv-head is the idempotency marker (CF14).
        $head = self::removeMetas($head, ['rating']);
        $add[] = TemplateSeo::ratingMetas();

        // 7 hide site: every robots meta says noindex, nofollow.
        if (SeoSettings::bool('seo.hide_site')) {
            $count = 0;
            $head = preg_replace_callback('~<meta\b[^>]*>~i', function ($m) use (&$count) {
                if (strtolower(self::attr($m[0], 'name')) !== 'robots') { return $m[0]; }
                $count++;
                return '<meta name="robots" content="noindex, nofollow">';
            }, $head);
            if ($count === 0) { $add[] = '<meta name="robots" content="noindex, nofollow" data-jv-head>'; }
        }

        // 8 verification metas on the home page only.
        if ($page && self::path($tpl) === 'index/index') {
            $verify = TemplateSeo::verificationMetas();
            if ($verify !== '') { $add[] = $verify; }
        }

        // 9 head code (GA4 + the owner's code), held for consent when asked; then the age-gate early script.
        $code = self::headCode();
        $held = $code !== '' && SeoSettings::bool('site.site_code_consent');
        if ($code !== '') { $add[] = $held ? '<template data-jv-hold>' . $code . '</template>' : $code; }
        $gate = $page && $legal === '' && SeoSettings::bool('site.age_gate');
        if ($gate) { $add[] = self::gateEarly(); }

        $head = rtrim(self::unmask($head), " \t");
        $head .= (substr($head, -1) === "\n" ? '' : "\n") . implode("\n", $add) . "\n";

        // 10 body inserts.
        if ($kind !== 'gate') {
            $notice = self::noticeActive($code !== '' && $held);
            $legalPages = $page ? SeoSettings::legalPages() : [];
            $row = $legalPages && strpos($body, 'class="jv-legal"') === false;
            if ($row) {
                $body = self::insertBefore($body, '</footer>', self::legalRow($legalPages), true);
                self::$last['inserts'][] = 'legal';
            }
            $tail = '';
            if ($gate) { $tail .= self::gateMarkup($legalPages, $base); self::$last['inserts'][] = 'age'; }
            if ($notice) { $tail .= self::noticeMarkup($legalPages); self::$last['inserts'][] = 'cookie'; }
            if ($row || $gate || $notice || $held) {
                $tail .= self::assets($base);
                self::$last['inserts'][] = 'assets';
            }
            if ($tail !== '') { $body = self::insertBefore($body, '</body>', $tail, false); }
        }
        return $head . $body;
    }

    // ------------------------------------------------------------------ head helpers

    /** Hide <script>, <style>, <template>, <noscript> bodies from the tag edits (restored by unmask()). */
    private static function mask($head)
    {
        self::$masked = [];
        return preg_replace_callback('~<(script|style|template|noscript)\b[^>]*>.*?</\1\s*>~is', function ($m) {
            self::$masked[] = $m[0];
            return "\x00" . (count(self::$masked) - 1) . "\x00";
        }, $head);
    }

    private static function unmask($head)
    {
        return preg_replace_callback('~\x00(\d+)\x00~', function ($m) { return self::$masked[(int)$m[1]] ?? ''; }, $head);
    }

    /** Value of one attribute of a tag ('' when missing), decoded. */
    public static function attr($tag, $name)
    {
        if (preg_match('~\s' . preg_quote($name, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', $tag, $m)) {
            $value = $m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
            return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    /** C.1 step 2: when no <meta charset> ends within the first 1,024 bytes, move it right after the <head> tag. */
    private static function charset($head)
    {
        $isCharset = function ($tag) { return (bool)preg_match('~\scharset\s*=~i', preg_replace('~"[^"]*"|\'[^\']*\'~', '""', $tag)); };
        if (preg_match_all('~<meta\b[^>]*>~i', $head, $all, PREG_OFFSET_CAPTURE)) {
            foreach ($all[0] as $hit) {
                if ($isCharset($hit[0])) {
                    if ($hit[1] + strlen($hit[0]) <= 1024) { return $head; }
                    break;
                }
            }
        }
        if (!preg_match('~<head(?=[\s>])[^>]*>~i', $head, $open, PREG_OFFSET_CAPTURE)) { return $head; }
        $head = preg_replace_callback('~<meta\b[^>]*>[ \t]*(?:\r?\n)?~i', function ($m) use ($isCharset) {
            return $isCharset($m[0]) ? '' : $m[0];
        }, $head);
        preg_match('~<head(?=[\s>])[^>]*>~i', $head, $open, PREG_OFFSET_CAPTURE);
        $at = $open[0][1] + strlen($open[0][0]);
        return substr($head, 0, $at) . "\n" . '<meta charset="utf-8">' . substr($head, $at);
    }

    /** Remove <link> tags whose rel tokens contain one of $rels. */
    private static function removeLinks($html, array $rels)
    {
        return preg_replace_callback('~<link\b[^>]*>[ \t]*(?:\r?\n)?~i', function ($m) use ($rels) {
            $tokens = preg_split('/\s+/', strtolower(self::attr($m[0], 'rel')), -1, PREG_SPLIT_NO_EMPTY);
            return array_intersect($tokens, $rels) ? '' : $m[0];
        }, $html);
    }

    /**
     * Remove <meta> tags whose name or property matches one of $keys (lower case; 'og:image*' = prefix match).
     */
    private static function removeMetas($html, array $keys)
    {
        return preg_replace_callback('~<meta\b[^>]*>[ \t]*(?:\r?\n)?~i', function ($m) use ($keys) {
            return self::metaMatches($m[0], $keys) ? '' : $m[0];
        }, $html);
    }

    private static function metaMatches($tag, array $keys)
    {
        foreach (['name', 'property'] as $attribute) {
            $value = strtolower(self::attr($tag, $attribute));
            if ($value === '') { continue; }
            foreach ($keys as $key) {
                if (substr($key, -1) === '*' ? strpos($value, substr($key, 0, -1)) === 0 : $value === $key) { return true; }
            }
        }
        return false;
    }

    private static function hasMeta($html, array $keys)
    {
        if (!preg_match_all('~<meta\b[^>]*>~i', $html, $m)) { return false; }
        foreach ($m[0] as $tag) { if (self::metaMatches($tag, $keys)) { return true; } }
        return false;
    }

    /**
     * C.1 step 5 / C.9: one set of social tags. When the core block has og:image, the theme's og:image*, twitter:image*,
     * twitter:card go; og:site_name / og:locale* are core-only on every page. When the block has no image but the theme
     * prints its own (share.png), exactly one twitter:card stays: the theme's (or the block's, upgraded to large).
     */
    private static function social($head)
    {
        $open = strpos($head, '<!--jv-seo-->');
        $close = $open === false ? false : strpos($head, '<!--/jv-seo-->', $open);
        if ($open === false || $close === false) { return $head; }
        $before = substr($head, 0, $open);
        $block = substr($head, $open + 13, $close - $open - 13);
        $after = substr($head, $close + 14);
        $block = preg_replace('~\A\r?\n~', '', $block);
        $after = preg_replace('~\A\r?\n~', '', $after);
        $blockImage = self::hasMeta($block, ['og:image']);
        $strip = ['og:site_name', 'og:locale*'];
        if ($blockImage) { $strip = array_merge($strip, ['og:image*', 'twitter:image*', 'twitter:card']); }
        $before = self::removeMetas($before, $strip);
        $after = self::removeMetas($after, $strip);
        if (!$blockImage) {
            $outside = $before . $after;
            if (self::hasMeta($outside, ['twitter:card'])) {
                $block = self::removeMetas($block, ['twitter:card']);
            } elseif (self::hasMeta($outside, ['og:image'])) {
                $block = preg_replace_callback('~<meta\b[^>]*>~i', function ($m) {
                    return self::metaMatches($m[0], ['twitter:card']) ? '<meta name="twitter:card" content="summary_large_image">' : $m[0];
                }, $block);
            }
        }
        return $before . $block . $after;
    }

    /** GA4 snippet (A.7) + the owner's head code (raw by design; only system/code admins can save it). */
    private static function headCode()
    {
        $code = '';
        $ga = (string)SeoSettings::get('site.site_ga4');
        if ($ga !== '') {
            $id = self::e($ga);
            $code .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>'
                . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag(\'js\',new Date());gtag(\'config\',\'' . $id . '\');</script>';
        }
        $own = (string)SeoSettings::get('site.site_head_code');
        if (trim($own) !== '') { $code .= ($code !== '' ? "\n" : '') . trim($own); }
        return $code;
    }

    /** Age gate early class (≤ 300 B script, F.2) + the critical style that hides the page under the overlay. */
    private static function gateEarly()
    {
        return '<style data-jv-head>html.jv-age,html.jv-age body{overflow:hidden}#jv-age{display:none}html.jv-age #jv-age{display:flex;position:fixed;inset:0;z-index:2147483600;'
            . 'align-items:center;justify-content:center;background:#0b0b10;color:#f4f4f6}html.jv-age body>:not(#jv-age){visibility:hidden}</style>'
            . '<script id="jv-head">(function(d){try{if(!/(^|; )jv_age=1/.test(d.cookie)&&!/' . self::CRAWLERS . '/i.test(navigator.userAgent))'
            . 'd.documentElement.className+=\' jv-age\'}catch(e){}})(document)</script>';
    }

    // ------------------------------------------------------------------ body helpers (F)

    /** F.3 "when": on, or auto and something waits for consent (held head code, or the ads' "Wait for consent"). */
    private static function noticeActive($heldCode)
    {
        $mode = (string)SeoSettings::get('site.cookie_notice');
        if ($mode === 'on') { return true; }
        if ($mode !== 'auto') { return false; }
        if ($heldCode) { return true; }
        if (self::adsConsentFlag() !== 1) { return false; }
        $conf = class_exists('app\\common\\util\\JcxPlacer') ? JcxPlacer::conf() : null;
        return is_array($conf) && !empty($conf['on']);
    }

    /** Last position of a closing tag, case-insensitive (fast path: lower / upper case, then strripos). */
    private static function lastPos($body, $needle)
    {
        $lower = strrpos($body, $needle);
        $upper = strrpos($body, strtoupper($needle));
        if ($lower === false && $upper === false) { return strripos($body, $needle); }
        return $lower === false ? $upper : ($upper === false ? $lower : max($lower, $upper));
    }

    /** Insert $html before the last $needle of $body ($fallback: before the last </body> when $needle is missing). */
    private static function insertBefore($body, $needle, $html, $fallback)
    {
        $at = self::lastPos($body, $needle);
        if ($at === false && $fallback) { $at = self::lastPos($body, '</body>'); }
        if ($at === false) { return $body; }
        return substr($body, 0, $at) . $html . substr($body, $at);
    }

    /** Front string: catalog value when the catalog has the key, else the English built-in. Plain text. */
    public static function t($key)
    {
        $fallback = self::STR[$key] ?? $key;
        if (!class_exists('app\\common\\util\\TemplateText')) { return $fallback; }
        try {
            $text = TemplateText::raw($key);
            return is_string($text) && $text !== '' && $text !== $key ? $text : $fallback;
        } catch (\Throwable $error) {
            return $fallback;
        }
    }

    private static function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function legalLinks(array $slugs, array $only = [])
    {
        $links = [];
        foreach ($slugs as $slug) {
            if ($only && !in_array($slug, $only, true)) { continue; }
            $links[] = '<a href="' . self::e(LegalPages::url($slug)) . '">' . self::e(self::t('legal.' . $slug)) . '</a>';
        }
        return $links;
    }

    /** F.1 footer row (separators come from jv-site.css). */
    public static function legalRow(array $slugs)
    {
        return '<nav class="jv-legal" aria-label="' . self::e(self::t('legal.footer_label')) . '">' . implode('', self::legalLinks($slugs)) . '</nav>';
    }

    private static function gateMarkup(array $legalPages, $base)
    {
        $site = $GLOBALS['config']['site'] ?? [];
        $name = trim(html_entity_decode((string)($site['site_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $logo = trim((string)($site['site_logo'] ?? ''));
        $mark = '';
        if ($logo !== '' && !preg_match('~[\s"\'<>\\\\]~', $logo) && (preg_match('~^https?://~i', $logo) || !preg_match('~^[a-z][a-z0-9+.-]*:|^//~i', $logo))) {
            $src = preg_match('~^https?://~i', $logo) ? $logo : $base . ltrim($logo, '/');
            $mark = '<img class="jv-age-logo" src="' . self::e($src) . '" alt="' . self::e($name) . '" height="40">';
        } elseif ($name !== '') {
            $mark = '<p class="jv-age-name">' . self::e($name) . '</p>';
        }
        $days = max(1, min(365, SeoSettings::int('site.age_gate_days')));
        $links = self::legalLinks($legalPages, ['terms', 'privacy']);
        return '<div id="jv-age" role="dialog" aria-modal="true" aria-labelledby="jv-age-t" aria-describedby="jv-age-d" data-days="' . $days
            . '" data-path="' . self::e($base) . '"><div class="jv-age-in">' . $mark
            . '<p id="jv-age-t" class="jv-age-h">' . self::e(self::t('age.title')) . '</p>'
            . '<p id="jv-age-d">' . self::e(self::t('age.text')) . '</p>'
            . '<div class="jv-age-b"><button type="button" class="jv-btn jv-btn-p" data-jv-age="enter">' . self::e(self::t('age.enter')) . '</button>'
            . '<a class="jv-btn" href="' . self::e((string)SeoSettings::get('site.age_gate_leave')) . '" rel="nofollow noopener noreferrer">' . self::e(self::t('age.leave')) . '</a></div>'
            . ($links ? '<p class="jv-age-l">' . implode('', $links) . '</p>' : '')
            . '</div></div>';
    }

    private static function noticeMarkup(array $legalPages)
    {
        $more = in_array('cookies', $legalPages, true)
            ? ' <a href="' . self::e(LegalPages::url('cookies')) . '">' . self::e(self::t('cookie.more')) . '</a>' : '';
        return '<div id="jv-cookie" role="region" aria-label="' . self::e(self::t('cookie.label')) . '" data-path="' . self::e(SiteIcons::base()) . '" hidden>'
            . '<p>' . self::e(self::t('cookie.text')) . $more . '</p>'
            . '<div class="jv-cookie-b"><button type="button" class="jv-btn" data-jv-consent="0">' . self::e(self::t('cookie.reject')) . '</button>'
            . '<button type="button" class="jv-btn jv-btn-p" data-jv-consent="1">' . self::e(self::t('cookie.accept')) . '</button></div></div>';
    }

    /** jv-site.css + jv-site.js, root-relative through MAC_PATH (CF04), cache-busted by mtime. */
    private static function assets($base)
    {
        $root = SiteIcons::root();
        $css = is_file($root . 'static/site/jv-site.css') ? filemtime($root . 'static/site/jv-site.css') : 0;
        $js = is_file($root . 'static/site/jv-site.js') ? filemtime($root . 'static/site/jv-site.js') : 0;
        return '<link rel="stylesheet" href="' . self::e($base . 'static/site/jv-site.css?v=' . $css) . '">'
            . '<script defer src="' . self::e($base . 'static/site/jv-site.js?v=' . $js) . '"></script>';
    }
}
