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

use app\admin\util\ThemeGate;

/**
 * Visitor theme switcher (spec: THEME-SWITCHER-SPEC.md + -REVIEW.md).
 *
 * The visitor's choice travels in the plain cookie `jv_theme` (a theme folder slug). The server
 * never trusts it as a path: it must pass the slug regex AND be in the offered allow-list, whose
 * members are themselves re-checked (complete, not draft, inside template/) on every request.
 * The admin's "Website template" stays the default for first access; picking the default deletes
 * the cookie, so a later change of the default reaches that visitor too.
 *
 * Offered list = snapshot written by the admin save (site.theme_switcher_offered, "|"-joined),
 * or, while no snapshot exists yet (fresh install, release package), the operator allow-list
 * deployment/reviewed-themes.json; both narrowed by the optional admin subset, default always first.
 * No think\Cache is used here: this runs in app_init before the cache driver is configured.
 *
 * Nothing per-visitor is ever written into cached HTML: the injected payload only holds data that is
 * already part of the page-cache key (rendered theme, offered list, locale); the widget reads the
 * visitor's own choice from document.cookie.
 */
final class ThemeSwitcher
{
    const COOKIE  = 'jv_theme';
    const STORAGE = 'javcms.theme';
    const MAX_AGE = 31536000;
    /** Page-cache lifetime cap (seconds) for pages rendered in a visitor-chosen theme. */
    const VISITOR_CACHE_TTL = 300;

    /** @var array|null per-request state set by Init (see begin()) */
    private static $state = null;
    private static $memo = [];

    public static function enabled(array $site)
    {
        return (string)($site['theme_switcher'] ?? '0') === '1';
    }

    public static function position(array $site)
    {
        return (string)($site['theme_switcher_pos'] ?? '') === 'bottom-start' ? 'bottom-start' : 'bottom-end';
    }

    public static function validName($value)
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $value) === 1;
    }

    /** Admin subset ("|"-joined slugs), invalid names dropped. Empty = no restriction. */
    public static function subset(array $site)
    {
        $out = [];
        foreach (explode('|', (string)($site['theme_switcher_themes'] ?? '')) as $slug) {
            if (self::validName($slug) && !in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    /** A theme a visitor may be served right now: complete, inside template/, not draft. */
    public static function selectable($slug)
    {
        if (!self::validName($slug)) {
            return false;
        }
        $key = 'sel:' . $slug;
        if (!array_key_exists($key, self::$memo)) {
            self::$memo[$key] = ThemeGate::structure($slug, 'html') !== null && !ThemeGate::isDraft($slug);
        }
        return self::$memo[$key];
    }

    /**
     * Snapshot computed at admin save: ThemeGate::available() (complete + not draft + scan OK or
     * reviewed) narrowed by the subset; the default is not stored (it is always offered).
     */
    public static function snapshot(array $site, ?array $available = null)
    {
        $available = $available === null ? ThemeGate::available() : $available;
        $subset = self::subset($site);
        $default = (string)($site['template_dir'] ?? '');
        $out = [];
        foreach ($available as $slug) {
            if (self::validName($slug) && $slug !== $default && ($subset === [] || in_array($slug, $subset, true))) {
                $out[] = $slug;
            }
        }
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return implode('|', array_values(array_unique($out)));
    }

    /**
     * Admin save (System::config POST): normalise the three posted fields and write the snapshot.
     * Malformed names are an error (null); names that are no longer selectable are dropped (and
     * reported) so one stale checkbox never blocks saving the whole settings form.
     * @param callable $ready function(string $slug): bool
     * @return array|null [array $site, string[] $dropped] or null on a malformed name
     */
    public static function adminSave(array $site, callable $ready, ?array $available = null)
    {
        $site['theme_switcher'] = (string)($site['theme_switcher'] ?? '0') === '1' ? '1' : '0';
        $site['theme_switcher_pos'] = (string)($site['theme_switcher_pos'] ?? '') === 'bottom-start' ? 'bottom-start' : 'bottom-end';
        $posted = $site['theme_switcher_themes'] ?? [];
        $posted = is_array($posted) ? $posted : ($posted === '' ? [] : explode('|', (string)$posted));
        $subset = [];
        $dropped = [];
        foreach ($posted as $slug) {
            if (!self::validName($slug)) {
                return null;
            }
            if (!$ready($slug)) {
                $dropped[] = $slug;
                continue;
            }
            if (!in_array($slug, $subset, true)) {
                $subset[] = $slug;
            }
        }
        $site['theme_switcher_themes'] = implode('|', $subset);
        $site['theme_switcher_offered'] = self::snapshot($site, $available);
        return [$site, $dropped];
    }

    /**
     * Offered candidates WITHOUT the per-theme filesystem check (cheap: string work only, plus one read of
     * reviewed-themes.json while no snapshot exists): valid, de-duplicated, narrowed by the subset, natural
     * order, default excluded. Used on every request (cookie decision, cache key); offered() adds the
     * selectable() check and runs only when a page is rendered (cache MISS).
     * $extra: slugs offered in addition (the desktop default when a phone renders a separate mobile default).
     */
    public static function candidates(array $site, array $extra = [])
    {
        $default = (string)($site['template_dir'] ?? '');
        // Key absent = settings never saved with the switcher (fresh install / upgrade): fall back to the
        // operator allow-list. Key present (even '') = the admin's snapshot, '' meaning "default only".
        $hasSnapshot = array_key_exists('theme_switcher_offered', $site);
        $snapshot = (string)($site['theme_switcher_offered'] ?? '');
        $subset = self::subset($site);
        $memoKey = 'cand:' . json_encode([$default, $hasSnapshot, $snapshot, $subset, $extra]);
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }
        $source = $hasSnapshot ? ($snapshot === '' ? [] : explode('|', $snapshot)) : ThemeGate::reviewed();
        $rest = [];
        foreach (array_merge($source, $extra) as $slug) {
            if (!is_string($slug) || $slug === $default || in_array($slug, $rest, true) || !self::validName($slug)) {
                continue;
            }
            if ($subset !== [] && !in_array($slug, $subset, true) && !in_array($slug, $extra, true)) {
                continue;
            }
            $rest[] = $slug;
        }
        sort($rest, SORT_NATURAL | SORT_FLAG_CASE);
        return self::$memo[$memoKey] = $rest;
    }

    /** Ordered slugs the visitor may pick: default first, then natural order (each one re-checked on disk). */
    public static function offered(array $site, array $extra = [])
    {
        $default = (string)($site['template_dir'] ?? '');
        $rest = array_values(array_filter(self::candidates($site, $extra), [self::class, 'selectable']));
        return self::validName($default) ? array_merge([$default], $rest) : $rest;
    }

    /**
     * The theme this request renders when the visitor has made no choice: the separate mobile template on a
     * phone view (site.mob_status), otherwise the "Website template".
     */
    public static function defaultFor(array $site, $mobileView)
    {
        $mobile = (string)($site['mob_template_dir'] ?? '');
        if ($mobileView && $mobile !== 'no' && self::validName($mobile)) {
            return $mobile;
        }
        return (string)($site['template_dir'] ?? '');
    }

    /**
     * Raw cookie value when it is a string of at most 64 bytes; '' when absent or not a string
     * (jv_theme[]=x); an oversized string becomes the invalid marker '!' so resolve() expires it.
     */
    public static function cookieValue(?array $cookies = null)
    {
        $cookies = $cookies === null ? $_COOKIE : $cookies;
        $value = $cookies[self::COOKIE] ?? '';
        if (!is_string($value)) {
            return '';
        }
        return strlen($value) <= 64 ? $value : '!';
    }

    /**
     * Pure decision for one request.
     * @return array ['theme' => slug to render or '' (default), 'clear' => expire the cookie, 'renew' => re-send it]
     */
    public static function resolve($cookie, array $offered, $default)
    {
        $cookie = is_string($cookie) ? $cookie : '';
        if ($cookie === '') {
            return ['theme' => '', 'clear' => false, 'renew' => false];
        }
        if (!self::validName($cookie) || $cookie === (string)$default || !in_array($cookie, $offered, true)) {
            // malformed, "site default" (never pinned), or no longer offered
            return ['theme' => '', 'clear' => true, 'renew' => false];
        }
        return ['theme' => $cookie, 'clear' => false, 'renew' => true];
    }

    /** Cookie path: the install directory ("/" or "/sub"); Init runs before MAC_PATH exists, so begin() passes it. */
    private static $installDir = null;

    public static function cookiePath()
    {
        $path = self::$installDir !== null ? self::$installDir : (defined('MAC_PATH') ? (string)MAC_PATH : '/');
        $path = '/' . trim($path, '/');
        // '#' delimiter: '~' is a member of the class (a '~' delimiter made preg_match() fail -> always '/').
        return preg_match('#\A/[A-Za-z0-9._~/-]*\z#D', $path) ? $path : '/';
    }

    /** Test seam: the install directory begin() would record. */
    public static function useInstallDir($dir)
    {
        self::$installDir = $dir === null ? null : (string)$dir;
    }

    public static function sendCookie($value, $expires)
    {
        if (headers_sent()) {
            return false;
        }
        return setcookie(self::COOKIE, (string)$value, [
            'expires' => (int)$expires,
            'path' => self::cookiePath(),
            'secure' => Sitemap::requestIsHttps(),
            'httponly' => false, // the widget reads and writes it; it carries no secret
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Called from Init (ENTRANCE index) after the preview block. Returns the visitor theme to render
     * ('' = keep the configured/preview theme) and records the request state for the page cache
     * and the widget payload.
     *
     * Cheap on purpose (it runs on page-cache hits too): the cookie is matched against the raw offered
     * candidates and only the cookie's own theme is checked on disk (selectable()). The full, checked list
     * is built lazily by offeredNow() when a page is actually rendered.
     * A renewal of an accepted cookie is only recorded here and sent by renewNow() for HTML pages that get
     * the widget, never on robots.txt / sitemap / JSON answers (publicly cacheable responses).
     *
     * @param array $site       site settings BEFORE the preview override
     * @param bool  $mobileView this request renders the separate mobile template (site.mob_status)
     */
    public static function begin(array $site, $previewTheme, $mobileView = false)
    {
        self::$state = null;
        self::$installDir = (string)($site['install_dir'] ?? '/');
        if (!self::enabled($site)) {
            return '';
        }
        // The default is the theme this request renders without a choice (the mobile template on a phone
        // view); the desktop "Website template" then stays selectable on phones.
        $default = self::defaultFor($site, $mobileView);
        $desktop = (string)($site['template_dir'] ?? '');
        $extra = $default !== $desktop && self::validName($desktop) ? [$desktop] : [];
        $siteEff = array_merge($site, ['template_dir' => $default]);
        $candidates = self::candidates($siteEff, $extra);
        $previewTheme = (string)$previewTheme;
        $visitor = ['theme' => '', 'clear' => false, 'renew' => false];
        if ($previewTheme === '') {
            // The signed preview cookie wins and its session must not touch the visitor cookie.
            $visitor = self::resolve(self::cookieValue(), array_merge([$default], $candidates), $default);
            if ($visitor['theme'] !== '' && !self::selectable($visitor['theme'])) {
                // drafted or deleted after the admin save
                $visitor = ['theme' => '', 'clear' => true, 'renew' => false];
            }
            if ($visitor['clear']) {
                self::sendCookie('', time() - 86400);
            }
        }
        self::$state = [
            'default' => $default,
            'candidates' => $candidates,
            'site' => $siteEff,
            'extra' => $extra,
            'offered' => null,
            'pos' => self::position($site),
            'preview' => $previewTheme,
            'visitor' => $visitor['theme'],
            // Server-set renewal (not capped to 7 days by Safari ITP like a script-written cookie): renewNow().
            'renew' => $visitor['renew'] ? $visitor['theme'] : '',
        ];
        return $visitor['theme'];
    }

    /** The checked offered list of this request (built once, only when a page is rendered). */
    public static function offeredNow()
    {
        if (self::$state === null) {
            return [];
        }
        if (!is_array(self::$state['offered'] ?? null)) {
            self::$state['offered'] = self::offered((array)(self::$state['site'] ?? []), (array)(self::$state['extra'] ?? []));
        }
        return self::$state['offered'];
    }

    /**
     * Sends the pending renewal of the visitor cookie once. Called for HTML pages that carry the widget:
     * from decorate() (rendered pages) and from All::load_page_cache() (pages served from the page cache).
     */
    public static function renewNow($tpl = null, $html = '')
    {
        if (self::$state === null || empty(self::$state['renew'])) {
            return false;
        }
        if ($tpl !== null && (self::excluded($tpl, (string)$html) || strpos((string)$html, self::MARKER) === false)) {
            // a cached response without the widget (feed, fragment, opted-out page) never renews
            return false;
        }
        $slug = self::$state['renew'];
        self::$state['renew'] = '';
        return self::sendCookie($slug, time() + self::MAX_AGE);
    }

    public static function state()
    {
        return self::$state;
    }

    /** Test seam. */
    public static function reset(?array $state = null)
    {
        self::$state = $state;
        self::$memo = [];
    }

    /** True when this request renders a visitor-chosen theme (page cache: untagged, short TTL). */
    public static function visitorTheme()
    {
        return self::$state !== null && self::$state['visitor'] !== '';
    }

    /** Cache-key component: everything that changes the injected widget (never per-visitor data). */
    public static function cacheKeyPart()
    {
        if (self::$state === null) {
            return '_ts0';
        }
        // Raw candidates (no disk check per request): a theme drafted after the save stays listed in cached
        // pages until their short TTL ends, and picking it falls back to the default (begin()).
        return '_ts' . substr(md5(json_encode([self::$state['default'], self::$state['candidates'] ?? self::$state['offered'] ?? [], self::$state['pos'], self::$state['preview'] !== '', self::assetVersion()])), 0, 12);
    }

    /** Files whose edits must refresh cached pages (theme-switcher assets and this class). */
    public static function coreFiles()
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
        return [
            __FILE__,
            $root . 'static/template-ui/theme-switcher.js',
            $root . 'static/template-ui/theme-switcher.css',
        ];
    }

    public static function assetVersion()
    {
        if (isset(self::$memo['ver'])) {
            return self::$memo['ver'];
        }
        $parts = [];
        foreach (array_slice(self::coreFiles(), 1) as $file) {
            $parts[] = is_file($file) ? filemtime($file) . ':' . filesize($file) : '0';
        }
        return self::$memo['ver'] = substr(md5(implode('|', $parts)), 0, 10);
    }

    /** Templates that never get the widget (iframe documents, feeds, AJAX fragments, gate pages). */
    public static function excluded($tpl, $html = '')
    {
        $path = strtolower(str_replace('\\', '/', trim((string)$tpl)));
        $path = preg_replace('~\.html?\z~', '', $path);
        $path = preg_replace('~\A[a-z0-9_]+@~', '', $path);
        $path = ltrim($path, '/');
        if ($path === ''
            || preg_match('~(?:^|/)vod/(?:player|player_pwd|downer|downer_pwd)\z~', $path)
            || preg_match('~(?:^|/)rss(?:/|\z)~', $path)
            || preg_match('~(?:^|/)ajax(?:_[a-z0-9_]*)?\z~', $path)
            || preg_match('~(?:^|/)public/(?:close|browser|verify)\z~', $path)) {
            return true;
        }
        if ($html !== '' && preg_match('~<html\b[^>]*\bdata-jv-switcher\s*=\s*["\']?off~i', substr((string)$html, 0, 4096))) {
            return true;
        }
        return false;
    }

    /** Display name without a trailing "(Modern)" (the badge says it). */
    public static function name($slug)
    {
        $name = ThemeGate::displayName($slug);
        $name = trim(preg_replace('/\s*\((?:modern|classic)\)\s*\z/i', '', $name));
        return $name === '' ? (string)$slug : $name;
    }

    public static function kind($slug)
    {
        $info = ThemeGate::info($slug);
        $variant = strtolower(trim((string)($info['variant'] ?? '')));
        if ($variant === 'modern' || $variant === 'classic') {
            return $variant;
        }
        return substr((string)$slug, -7) === '-modern' ? 'modern' : 'classic';
    }

    /** Widget payload (cache-stable data only). */
    public static function payload(array $state, $current)
    {
        $base = defined('MAC_PATH') ? (string)MAC_PATH : '/';
        $base = '/' . trim($base, '/');
        $base = $base === '/' ? '/' : $base . '/';
        $themes = [];
        $current = (string)$current;
        $preview = (string)($state['preview'] ?? '');
        $offered = (array)($state['offered'] ?? []);
        if ($preview !== '' && !in_array($preview, $offered, true) && self::validName($preview)) {
            // A previewed theme that visitors cannot pick is listed first, not selectable.
            $themes[] = ['slug' => $preview, 'name' => self::name($preview), 'kind' => self::kind($preview),
                'preview' => $base . 'template/' . $preview . '/', 'locked' => 1];
        }
        foreach ($offered as $slug) {
            if (!self::validName($slug)) {
                continue;
            }
            $themes[] = ['slug' => $slug, 'name' => self::name($slug), 'kind' => self::kind($slug),
                'preview' => $base . 'template/' . $slug . '/'];
        }
        $t = [];
        foreach ([
            'open' => 'ui.theme_switcher_open', 'title' => 'ui.theme_switcher_title', 'hint' => 'ui.theme_switcher_hint',
            'current' => 'ui.theme_current', 'default' => 'ui.theme_default', 'modern' => 'ui.theme_modern',
            'classic' => 'ui.theme_classic', 'switching' => 'ui.theme_switching', 'preview' => 'ui.theme_previewing',
            'close' => 'template_ui_close',
        ] as $id => $key) {
            $t[$id] = TemplateText::raw($key);
        }
        return [
            'v' => 1,
            'base' => $base,
            'css' => $base . 'static/template-ui/theme-switcher.css?v=' . self::assetVersion(),
            'pos' => (string)($state['pos'] ?? 'bottom-end'),
            'current' => $current,
            'default' => (string)($state['default'] ?? ''),
            'preview' => $preview !== '' ? 1 : 0,
            'themes' => $themes,
            't' => $t,
            'lang' => TemplateText::locale(),
            'dir' => TemplateText::direction(),
        ];
    }

    /** Exact loader marker (page text such as a comment quoting "data-jv-theme=" never matches it). */
    const MARKER = '" data-jv-theme="{';

    /** Appends the loader before the last </body>; unchanged when there is none or it is already there. */
    public static function inject($html, array $payload, $scriptUrl)
    {
        $html = (string)$html;
        $at = strripos($html, '</body>');
        if ($at === false || strpos($html, self::MARKER) !== false) {
            return $html;
        }
        // Slashes stay escaped (\/): app.compress=1 (mac_compress_html) strips "/*...*/" after fetch(), so the
        // payload must never contain "/*".
        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return $html;
        }
        $tag = '<script defer src="' . htmlspecialchars((string)$scriptUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" data-jv-theme="' . htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></script>';
        return substr($html, 0, $at) . $tag . substr($html, $at);
    }

    /** Front-controller hook (All::fetch): inject into a rendered public page when enabled. */
    public static function decorate($html, $tpl, $current)
    {
        if (self::$state === null || !is_string($html) || self::excluded($tpl, $html)) {
            return $html;
        }
        $state = self::$state;
        $state['offered'] = self::offeredNow();
        $payload = self::payload($state, $current);
        $base = $payload['base'];
        $out = self::inject($html, $payload, $base . 'static/template-ui/theme-switcher.js?v=' . self::assetVersion());
        if ($out !== $html) {
            // Only an HTML page that carries the widget renews the visitor cookie (review P2-1).
            self::renewNow();
        }
        return $out;
    }
}
