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
 * Where the button shows (1.2.1, site.theme_switcher_show, see show()): "always" = the floating button on every
 * screen (default, also for a missing or unknown value), "header" = a button in the theme's header right before its
 * light/dark button (printed here by decorate(), see headerSlot(); the widget falls back to the floating button where
 * the header has no room for it), "end" = the floating button only at the end of the page (1.2.0 behaviour).
 *
 * Nothing per-visitor is ever written into cached HTML: the injected payload only holds data that is
 * already part of the page-cache key (rendered theme, offered list, locale, display mode); the widget reads
 * the visitor's own choice from document.cookie.
 */
final class ThemeSwitcher
{
    const COOKIE  = 'jv_theme';
    const STORAGE = 'javcms.theme';
    const MAX_AGE = 31536000;
    /** Page-cache lifetime cap (seconds) for pages rendered in a visitor-chosen theme. */
    const VISITOR_CACHE_TTL = 300;
    /** Display modes (site.theme_switcher_show); the first one is the default. */
    const SHOW_MODES = ['always', 'header', 'end'];
    /** The settings of the Themes page card (System::config switcher_only); nothing else of `site` is taken from it. */
    const ADMIN_KEYS = ['theme_switcher', 'theme_switcher_show', 'theme_switcher_pos', 'theme_switcher_themes'];
    /** At most this many ticked themes are read from one save (there are 18 themes; the rest is refused). */
    const MAX_POSTED_THEMES = 256;

    /** @var array|null per-request state set by Init (see begin()) */
    private static $state = null;
    private static $memo = [];

    public static function enabled(array $site)
    {
        $value = $site['theme_switcher'] ?? '0';
        return is_scalar($value) && (string)$value === '1';
    }

    public static function position(array $site)
    {
        $value = $site['theme_switcher_pos'] ?? '';
        return is_scalar($value) && (string)$value === 'bottom-start' ? 'bottom-start' : 'bottom-end';
    }

    /** Display mode: 'header' or 'end' when stored exactly so; everything else (missing, '', unknown, non-string) = 'always'. */
    public static function show(array $site)
    {
        $value = $site['theme_switcher_show'] ?? '';
        return is_string($value) && in_array($value, self::SHOW_MODES, true) ? $value : 'always';
    }

    public static function validName($value)
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $value) === 1;
    }

    /** Admin subset ("|"-joined slugs), invalid names dropped. Empty = no restriction. */
    public static function subset(array $site)
    {
        $out = [];
        $value = $site['theme_switcher_themes'] ?? '';
        foreach (explode('|', is_scalar($value) ? (string)$value : '') as $slug) {
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
     * Admin save (System::config POST, the Themes page card, "Use this theme"): normalise the four switcher fields
     * (on/off, display mode, corner, subset) and write the snapshot. theme_switcher_show is always written (a missing
     * or unknown value becomes 'always'), because SeoSettings::keepSiteKeys() never carries a theme_switcher* key over.
     * Malformed input is an error (null): a name that is no theme folder name, or a non-scalar toggle / mode / corner.
     * Names that are no longer selectable are dropped (and reported) so one stale checkbox never blocks the save.
     * @param callable $ready function(string $slug): bool
     * @return array|null [array $site, string[] $dropped] or null on malformed input
     */
    public static function adminSave(array $site, callable $ready, ?array $available = null)
    {
        foreach (['theme_switcher', 'theme_switcher_pos', 'theme_switcher_show'] as $key) {
            if (isset($site[$key]) && !is_scalar($site[$key])) {
                return null;
            }
        }
        $site['theme_switcher'] = (string)($site['theme_switcher'] ?? '0') === '1' ? '1' : '0';
        $site['theme_switcher_pos'] = (string)($site['theme_switcher_pos'] ?? '') === 'bottom-start' ? 'bottom-start' : 'bottom-end';
        $show = self::show(['theme_switcher_show' => isset($site['theme_switcher_show']) ? (string)$site['theme_switcher_show'] : '']);
        $site = self::put($site, 'theme_switcher_show', $show, 'theme_switcher_pos');
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
     * Site identity save (1.2.1): the switcher settings live on the Themes page card (saveOnly()), their single source
     * of truth. A switcher key the Site identity post still carries is ignored (a Site identity tab opened before the
     * update still has the 1.2.0 rows, and that page resends an out-of-date save by itself): every switcher key is
     * taken from the stored settings instead, cleaned the way the site reads them (a tampered value never blocks the
     * Site identity save); a key stored nowhere is not invented (adminSave() then writes its default). A posted
     * snapshot is dropped too: adminSave() rebuilds it for the theme this form posts.
     * The keys come back in the order the stored settings have them (fix round 2, VA2-3): a Site identity save never
     * reorders them in maccms.php (the card, "Use this theme" and tools/upgrade.php keep the mode right after the corner).
     */
    public static function keepStored(array $posted, array $stored)
    {
        unset($posted['theme_switcher_offered']);
        foreach (self::ADMIN_KEYS as $key) {
            unset($posted[$key]);
        }
        foreach (array_keys($stored) as $key) {
            if (!is_string($key) || !in_array($key, self::ADMIN_KEYS, true)) {
                continue;
            }
            if ($key === 'theme_switcher') {
                $posted[$key] = self::enabled($stored) ? '1' : '0';
            } elseif ($key === 'theme_switcher_show') {
                $posted[$key] = self::show($stored);
            } elseif ($key === 'theme_switcher_pos') {
                $posted[$key] = self::position($stored);
            } else {
                $posted[$key] = implode('|', self::subset($stored));
            }
        }
        return $posted;
    }

    /**
     * Revision of the four card settings as the site reads them (on / off, display mode, corner, offered subset), 16 hex
     * characters. The Themes page sends the revision it was loaded with, and System::switcherOnlySave() refuses a save
     * whose revision is no longer the stored one (revisionMatches()): a second, older tab of the card never reverts
     * settings saved after it was opened, not even through its automatic resend after a renewed form token. Only the
     * normalised values count, so "Use this theme", a Site identity save or tools/upgrade.php (adds the default mode
     * 'always', which a missing key already reads as) keep the revision.
     */
    public static function revision(array $site)
    {
        $values = [self::enabled($site) ? '1' : '0', self::show($site), self::position($site), implode('|', self::subset($site))];
        return substr(hash('sha256', 'jv-ts-rev|' . implode("\n", $values)), 0, 16);
    }

    /** True when a posted revision (Themes page) is the stored settings' revision; a missing or malformed one never is. */
    public static function revisionMatches(array $stored, $posted)
    {
        return is_string($posted) && preg_match('/\A[0-9a-f]{16}\z/D', $posted) === 1 && hash_equals(self::revision($stored), $posted);
    }

    /**
     * A card save with an outdated (well-formed) revision that would change nothing (fix round 3, SVVA3-P3-1):
     * saveOnly() of the post on the stored settings gives the same four normalised card values (the same revision).
     * That is the case when the answer to this page's own last save was lost after the server had written it, or when
     * another tab saved the very same settings: nothing would be reverted, so System::switcherOnlySave() answers it like
     * a save without writing anything and the page takes the stored revision. A save that would change something stays
     * refused (VA2-1), and so does every post without a well-formed revision (a page from before 1.2.1, a tool).
     * The four card values are the stored ones, but the offered-list snapshot is derived from the theme folders as well:
     * when a folder became selectable (or stopped being so) since the last save, the snapshot this save computes differs
     * from the stored one. Then 'write' is true and 'site' is the computed site, which System::switcherOnlySave() writes
     * like every other save (fix round 4, SVVA4-P3-1): "saved" also means the visitors' list is current again, and
     * nothing a newer save stored is reverted (its four values are the ones written). Otherwise nothing is written.
     * @param mixed $posted the posted `site` array
     * @param mixed $rev    the posted revision
     * @return array|null ['site' => array, 'dropped' => string[], 'write' => bool] (the stored settings with write false,
     *                    or the computed ones with write true) or null (refuse as stale)
     */
    public static function unchangedBy(array $stored, $posted, $rev, callable $ready, ?array $available = null)
    {
        if (!is_string($rev) || preg_match('/\A[0-9a-f]{16}\z/D', $rev) !== 1) {
            return null;
        }
        $result = self::saveOnly($stored, $posted, $ready, $available);
        if (empty($result['ok']) || self::revision($result['site']) !== self::revision($stored)) {
            return null;
        }
        $dropped = array_values((array)($result['dropped'] ?? []));
        $was = $stored['theme_switcher_offered'] ?? '';
        if (!is_string($was) || $was !== (string)($result['site']['theme_switcher_offered'] ?? '')) {
            return ['site' => $result['site'], 'dropped' => $dropped, 'write' => true];
        }
        return ['site' => $stored, 'dropped' => $dropped, 'write' => false];
    }

    /**
     * Themes page card (POST system/config with switcher_only=1): merges ONLY the four switcher fields into the
     * stored site settings and saves them like adminSave() (normalised values + the offered-list snapshot). Every
     * other stored key stays byte-identical; a posted key outside ADMIN_KEYS (template_dir, theme_switcher_offered,
     * site_name ...) is ignored. Strict: a value outside its set is refused ('value'), so is a malformed theme name
     * ('name'); a theme that is no longer selectable is dropped and reported. A field the post leaves out keeps its
     * stored value.
     * @param mixed $posted the posted `site` array
     * @return array ['ok' => true, 'site' => array, 'dropped' => string[]] or ['ok' => false, 'error' => 'value'|'name']
     */
    public static function saveOnly(array $stored, $posted, callable $ready, ?array $available = null)
    {
        if (!is_array($posted)) {
            return ['ok' => false, 'error' => 'value'];
        }
        $site = $stored;
        $sets = ['theme_switcher' => ['0', '1'], 'theme_switcher_show' => self::SHOW_MODES, 'theme_switcher_pos' => ['bottom-end', 'bottom-start']];
        foreach ($sets as $key => $allowed) {
            if (!array_key_exists($key, $posted)) {
                // kept from the stored settings (a tampered stored value is normalised by adminSave() below)
                if (isset($site[$key]) && !is_scalar($site[$key])) {
                    unset($site[$key]);
                }
                continue;
            }
            if (!is_string($posted[$key]) || !in_array($posted[$key], $allowed, true)) {
                return ['ok' => false, 'error' => 'value'];
            }
            // a key the stored settings do not have yet goes to its place (the mode right after the corner), as in
            // upgradeSite() and adminSave(): maccms.php keeps the switcher keys together
            $site = self::put($site, $key, $posted[$key], $key === 'theme_switcher' ? 'mob_ads_dir' : ($key === 'theme_switcher_show' ? 'theme_switcher_pos' : 'theme_switcher'));
        }
        if (array_key_exists('theme_switcher_themes', $posted)) {
            $themes = $posted['theme_switcher_themes'];
            if (is_string($themes)) {
                $themes = $themes === '' ? [] : explode('|', $themes);
            } elseif (!is_array($themes)) {
                return ['ok' => false, 'error' => 'value'];
            }
            if (count($themes) > self::MAX_POSTED_THEMES) {
                return ['ok' => false, 'error' => 'value'];
            }
            $list = [];
            foreach ($themes as $slug) {
                if (!is_string($slug)) {
                    return ['ok' => false, 'error' => 'name'];
                }
                $list[] = $slug;
            }
            $site['theme_switcher_themes'] = $list;
        } elseif (array_key_exists('theme_switcher_themes', $site)) {
            $site['theme_switcher_themes'] = implode('|', self::subset($site));
        }
        $saved = self::adminSave($site, $ready, $available);
        if ($saved === null) {
            return ['ok' => false, 'error' => 'name'];
        }
        return ['ok' => true, 'site' => $saved[0], 'dropped' => $saved[1]];
    }

    /**
     * tools/upgrade.php (1.2.1): adds theme_switcher_show = 'always' (after theme_switcher_pos) when the key is
     * missing. A stored value is never changed, not even an unknown one (the site reads that as 'always' anyway).
     * @return array [array $site, bool $changed]
     */
    public static function upgradeSite(array $site)
    {
        if (array_key_exists('theme_switcher_show', $site)) {
            return [$site, false];
        }
        return [self::put($site, 'theme_switcher_show', 'always', 'theme_switcher_pos'), true];
    }

    /**
     * tools/upgrade.php step 1b: upgradeSite() applied to the settings file (application/extra/maccms.php), in the
     * admin's format (mac_arr2file: "<?php\nreturn " . var_export(...) . ";"). Written IN PLACE like the admin's own save
     * (never a new file renamed over it): the file keeps its inode, owner, group, mode, ACLs and hard / symbolic links,
     * so a run as root or as the SSH user never takes the file away from the web server's PHP. Read back afterwards;
     * a failed or partial write puts the bytes read before back.
     * @return string 'added' | 'already' (key present, nothing written) | 'missing' | 'unreadable' | 'unwritable'
     */
    public static function upgradeFile($file)
    {
        $file = (string)$file;
        if (!is_file($file)) {
            return 'missing';
        }
        clearstatcache(true, $file);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
        $before = @file_get_contents($file);
        $cfg = is_string($before) ? include $file : null;
        if (!is_array($cfg) || !isset($cfg['site']) || !is_array($cfg['site'])) {
            return 'unreadable';
        }
        list($site, $added) = self::upgradeSite($cfg['site']);
        if (!$added) {
            return 'already';
        }
        $cfg['site'] = $site;
        $php = "<?php\nreturn " . var_export($cfg, true) . ";";
        if (!is_writable($file)) {
            return 'unwritable';
        }
        $written = @file_put_contents($file, $php, LOCK_EX) === strlen($php);
        clearstatcache(true, $file);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
        $check = $written && @file_get_contents($file) === $php ? include $file : null;
        if (is_array($check) && ($check['site']['theme_switcher_show'] ?? null) === 'always') {
            return 'added';
        }
        // the write failed part way (disk full, quota): the settings as they were, so the site keeps working
        @file_put_contents($file, $before, LOCK_EX);
        clearstatcache(true, $file);
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
        return 'unwritable';
    }

    /** Sets $key; a new key goes right after $after (or after theme_switcher, else at the end): maccms.php stays readable. */
    private static function put(array $site, $key, $value, $after)
    {
        if (array_key_exists($key, $site)) {
            $site[$key] = $value;
            return $site;
        }
        $anchor = array_key_exists($after, $site) ? $after : (array_key_exists('theme_switcher', $site) ? 'theme_switcher' : null);
        if ($anchor === null) {
            $site[$key] = $value;
            return $site;
        }
        $out = [];
        foreach ($site as $k => $v) {
            $out[$k] = $v;
            if ($k === $anchor) {
                $out[$key] = $value;
            }
        }
        return $out;
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
            'show' => self::show($site),
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

    /** Display mode of this request's state ('always' for a state without one, e.g. recorded before 1.2.1). */
    private static function stateShow(array $state)
    {
        return self::show(['theme_switcher_show' => $state['show'] ?? '']);
    }

    /** Cache-key component: everything that changes the injected widget (never per-visitor data). */
    public static function cacheKeyPart()
    {
        if (self::$state === null) {
            return '_ts0';
        }
        // Raw candidates (no disk check per request): a theme drafted after the save stays listed in cached
        // pages until their short TTL ends, and picking it falls back to the default (begin()).
        // The display mode changes the markup (header button) and the widget's behaviour: a page cached in
        // another mode must miss.
        return '_ts' . substr(md5(json_encode([self::$state['default'], self::$state['candidates'] ?? self::$state['offered'] ?? [], self::$state['pos'], self::$state['preview'] !== '', self::assetVersion(), self::stateShow(self::$state)])), 0, 12);
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
            'show' => self::stateShow($state),
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
        $at = self::lastBodyEnd($html);
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

    /**
     * Offset of the last "</body>" in any letter case, or false: what strripos() of the page and "</body>" gives, without its
     * lower-case copy of the whole page (PHP < 8.2: 2-8 ms per rendered page on PHP 7.4, the live runtime). The last
     * 2 KB are searched first (the tag sits a few bytes before the end of every page), the whole page only when they
     * have none. preg /i reads the page in place; the needle is ASCII without "i", so no locale folds it differently.
     */
    private static function lastBodyEnd($html)
    {
        $len = strlen($html);
        $from = $len > 2048 ? $len - 2048 : 0;
        while (true) {
            if (preg_match_all('~</body>~i', $html, $m, PREG_OFFSET_CAPTURE, $from) > 0) {
                return (int)$m[0][count($m[0]) - 1][1];
            }
            if ($from === 0) {
                return false;
            }
            // a "</body>" that begins before the window and ends inside it is found here too
            $from = 0;
        }
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
            if ($payload['show'] === 'header') {
                // Header mode: the header button is part of the page as rendered (no layout shift); only a page that
                // carries the widget gets it.
                $out = self::headerSlot($out, $payload, $current);
            }
            // Only an HTML page that carries the widget renews the visitor cookie (review P2-1).
            self::renewNow();
        }
        return $out;
    }

    // ------------------------------------------------------------------ header mode (site.theme_switcher_show = header)

    /** Marker attribute of the header button (the widget binds it; a page that has one is never decorated twice). */
    const SLOT_ATTR = 'data-jv-ts-slot';

    /**
     * Generic rule for themes without a recipe: classes of the colour toggle that only serve the light/dark switch,
     * e.g. "x-mode", "x-color", "mode-btn", "x-mode-row". Measured per theme (scout-front 4.2): a regex alone is wrong
     * for four themes (ci-mode, ox-mode, tz-mode, vp-mode carry look or visibility), so every shipped theme has a recipe.
     */
    const COLOR_CLASS_RX = '/(?:\A|-)(?:mode|color|colour)(?:-btn|-row)?\z/i';

    /**
     * Header-mode recipes, keyed by the rendered theme folder (MAC_TEMPLATE_DIR). Classes and icons per theme from
     * reports/SWITCHER-VISIBLE-SCOUT-FRONT.md 4.2-4.3; the no-room ranges from width scans of the real header button at
     * 320-1920 px in English and German (reports/SWITCHER-VISIBLE-BUILD.md, "Header mode per theme"), with a margin:
     *   drop   classes of the theme's colour toggle that are NOT copied (colour-mode only); [] = copy all
     *   icon   'svg' = the widget's palette icon inline (size = width/height in px where no theme rule sizes it,
     *          cls = class of the <svg>); 'ai' = AutoIcon::ui('palette') in the theme's own icon set (cls = its class;
     *          the inline icon when auto-icons are switched off)
     *   tint   'ai' icons: 'none' (default; the theme's own header icons next to it are untinted: honey, topaz, velvet)
     *          or 'theme' (the theme's own auto-icon tint, like its light/dark and tool icons: jav-modern)
     *   stroke line width of the inline icon (default: the line width of the theme's own light/dark icon, else 2)
     *   orb    honey: the icon sits in <span class="{orb}">; cap: a caption <span class="{cap}"> (ui.theme_switcher_short)
     *   li     the toggle is the first child of an <li>: the header button gets its own <li> (same classes) before it
     *   hide   media queries where that header row has no room: the header button is display:none there (CSS printed
     *          before </head>, so the row is laid out once, no shift) and the widget uses the floating button
     *   css    rules for that theme while the header button is shown (printed with the rest)
     */
    const HEADER_RECIPES = [
        'crimson' => ['drop' => [], 'icon' => 'svg'],
        'crimson-modern' => ['drop' => [], 'icon' => 'svg', 'size' => 22, 'hide' => ['(min-width:1240px)']],
        // ui.css sizes every svg of a .tui-color-toggle 20 px, ember draws its light/dark icon 18 px: the same here, and an
        // icon-only strip item at least 24 px wide (WCAG 2.5.8 target size; ember's own .e-top > .container .tui-color-toggle
        // sets min-width 0, so the rule names the same path)
        'ember' => ['drop' => [], 'icon' => 'svg', 'size' => 18, 'hide' => ['(max-width:767px)'],
            'css' => '.e-top>.container .e-top-btn.jv-ts-hbtn{min-width:24px;justify-content:center}.e-top-btn.jv-ts-hbtn svg{width:18px;height:18px}'],
        'ember-modern' => ['drop' => ['t-mode'], 'icon' => 'svg'],
        'honey' => ['drop' => [], 'icon' => 'ai', 'cls' => 'hn-i', 'orb' => 'hn-orb', 'cap' => 'hn-cap',
            'hide' => ['(max-width:479px)', '(min-width:1024px) and (max-width:1099px)']],
        'honey-modern' => ['drop' => ['nc-mode'], 'icon' => 'svg', 'size' => 22],
        'jav' => ['drop' => [], 'icon' => 'svg', 'li' => true, 'hide' => ['(max-width:369px)', '(min-width:768px) and (max-width:814px)']],
        'jav-modern' => ['drop' => ['tool-mode'], 'icon' => 'ai', 'tint' => 'theme', 'hide' => ['(max-width:394px)']],
        'javcms' => ['drop' => [], 'icon' => 'svg', 'hide' => ['(max-width:468px)', '(min-width:768px) and (max-width:860px)']],
        'javcms-modern' => ['drop' => [], 'icon' => 'svg', 'hide' => ['(max-width:379px)']],
        'neon' => ['drop' => ['nx-mode'], 'icon' => 'svg', 'cls' => 'nx-ico', 'hide' => ['(max-width:444px)']],
        'neon-modern' => ['drop' => ['nn-mode'], 'icon' => 'svg', 'hide' => ['(max-width:479px)']],
        'onyx' => ['drop' => ['mode-btn'], 'icon' => 'svg', 'size' => 20],
        'onyx-modern' => ['drop' => [], 'icon' => 'svg', 'hide' => ['(max-width:1799px)']],
        'topaz' => ['drop' => [], 'icon' => 'ai', 'cls' => 'tz-i'],
        'topaz-modern' => ['drop' => ['tp-mode'], 'icon' => 'svg', 'size' => 22],
        'velvet' => ['drop' => ['vv-color'], 'icon' => 'ai', 'cls' => 'vv-i', 'hide' => ['(max-width:389px)']],
        'velvet-modern' => ['drop' => [], 'icon' => 'svg', 'hide' => ['(max-width:1023px)']],
    ];

    /**
     * HTML elements whose content is text up to their own end tag, never markup (a <button> inside them is not a
     * button). <script> (script data states, see scriptEnd()) and <plaintext> (text up to the end of the document) are
     * handled apart. Inside svg / math these names are foreign elements with markup content.
     */
    const RAW_TEXT = ['style' => 1, 'textarea' => 1, 'title' => 1, 'xmp' => 1, 'iframe' => 1, 'noembed' => 1, 'noframes' => 1, 'noscript' => 1];

    /** HTML start tags that end an svg / math subtree ("in foreign content" of the HTML tree construction). */
    const BREAKOUT = ['b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'embed', 'h1',
        'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta', 'nobr', 'ol', 'p', 'pre',
        'ruby', 's', 'small', 'span', 'strong', 'strike', 'sub', 'sup', 'table', 'tt', 'u', 'ul', 'var'];

    /** HTML void elements (no end tag, never open). */
    const VOID = ['area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1, 'input' => 1, 'link' => 1, 'meta' => 1,
        'source' => 1, 'track' => 1, 'wbr' => 1, 'param' => 1, 'keygen' => 1, 'frame' => 1];

    /** Start tags whose attributes or self-closing flag the scanner reads (the others only need their end). */
    const ATTR_TAGS = ['button' => 1, 'li' => 1, 'svg' => 1, 'math' => 1, 'font' => 1];

    /**
     * HTML start tags that findToggle() handles apart (the toggle and its <li>, svg / math, script, raw text, <plaintext>,
     * <template>); every other HTML start tag outside svg / math is only counted, without reading its attributes.
     */
    const SCAN_SPECIAL = ['button' => 1, 'li' => 1, 'svg' => 1, 'math' => 1, 'script' => 1, 'plaintext' => 1, 'template' => 1,
        'style' => 1, 'textarea' => 1, 'title' => 1, 'xmp' => 1, 'iframe' => 1, 'noembed' => 1, 'noframes' => 1, 'noscript' => 1];

    /** HTML white space of the tokenizer (CR counts: the input stream turns it into LF). */
    const WS = " \t\n\f\r";

    /**
     * One whole tag after its "<" or "</" ("\G"), as the tokenizer's tag name and attribute states read it: white space
     * and "/" between attributes; a name (its first character may be "=", quotes and "<" belong to it); "=" with a
     * double- or single-quoted value, or an unquoted one (never starting with a quote; up to white space or ">"). All
     * quantifiers are possessive and a name followed by "=" must take a value, so a quote that never closes cannot be
     * read another way: such a tag does not match (the document ends inside it). Group 1 = the tag name.
     */
    const TAG_RX = '~\G([a-zA-Z][^\t\n\f\r />]*+)(?:[\t\n\f\r /]++|[^\t\n\f\r />][^\t\n\f\r />=]*+(?:[\t\n\f\r ]*+=[\t\n\f\r ]*+(?:"[^"]*+"|\'[^\']*+\'|(?!["\'])[^\t\n\f\r >]*+)|(?![\t\n\f\r ]*+=)))*+>~';

    /**
     * TAG_RX with the runs of white space and "/" between attributes captured (group 2): the tag is self-closing when the
     * last such run ends right at the ">" and ends with "/" (a "/" inside an unquoted value never counts), as readTag()
     * reads it. Used by selfClosing() for elements inside svg / math, whose attributes do not matter.
     */
    const TAG_SC_RX = '~\G([a-zA-Z][^\t\n\f\r />]*+)(?:([\t\n\f\r /]++)|[^\t\n\f\r />][^\t\n\f\r />=]*+(?:[\t\n\f\r ]*+=[\t\n\f\r ]*+(?:"[^"]*+"|\'[^\']*+\'|(?!["\'])[^\t\n\f\r >]*+)|(?![\t\n\f\r ]*+=)))*+>~';

    /** The recipe used for a theme folder (null = generic rule). */
    public static function headerRecipe($theme)
    {
        return is_string($theme) && isset(self::HEADER_RECIPES[$theme]) ? self::HEADER_RECIPES[$theme] : null;
    }

    /**
     * Header mode: puts the header button right before the theme's first <button ... data-color-toggle> start tag
     * (outside comments, raw-text elements and <template> contents) and its CSS before the first </head>.
     * Unchanged when the page already has a header button, has no colour toggle, or has no </head> before it
     * (then the widget simply uses the floating button).
     */
    public static function headerSlot($html, array $payload, $current)
    {
        $html = (string)$html;
        // any letter case, read in place (stripos() copies the whole page on PHP < 8.2: 2-8 ms per render on PHP 7.4)
        if (preg_match('~data-color-toggle~i', $html) !== 1) {
            return $html;
        }
        $hit = self::findToggle($html);
        if ($hit === null || $hit['head'] === null || $hit['at'] === null) {
            return $html;
        }
        $recipe = self::headerRecipe((string)$current);
        $button = self::slotMarkup($hit['attrs'], $recipe === null ? [] : $recipe, $recipe === null, $payload, (string)$current, $hit['inner']);
        $at = $hit['at'];
        $lead = '';
        $tail = '';
        if ($hit['li'] !== null && ($recipe === null || !empty($recipe['li']))) {
            // the toggle is the only start tag in its <li>: the header button gets an <li> of its own, inserted before it
            $at = $hit['li']['at'];
            $liClass = self::cleanClasses((string)($hit['li']['attrs']['class'] ?? ''), [], false);
            $lead = '<li class="' . self::esc(trim($liClass . ' jv-ts-hli')) . '">';
            $tail = '</li>';
        }
        $css = '<style data-jv-ts-slot-css>' . self::slotCss($recipe === null ? [] : $recipe) . '</style>';
        // insert the later offset first, so the earlier one stays valid
        $out = substr($html, 0, $at) . $lead . $button . $tail . substr($html, $at);
        return substr($out, 0, $hit['head']) . $css . substr($out, $hit['head']);
    }

    /**
     * One pass over the page in document order, following the HTML tokenizer where it decides what is markup.
     * Returns null when a header button is already there (before the toggle, or as a real button after it); otherwise
     * ['head' => offset of the first "</head" or null, 'at' => offset of the first colour toggle's "<button" or null,
     * 'attrs' => its attributes, 'li' => ['at' => offset, 'attrs' => ...] when that toggle is the first start tag of an
     * <li> (only white space between them), else null, 'inner' => the markup inside the toggle (at most 4 KB)].
     * Skipped as text: comments ("<!--" to "-->" or "--!>"; "<!-->" / "<!--->"), bogus comments ("<!x", "<?", "</ x" up
     * to the next ">"), raw-text element contents (RAW_TEXT), script contents (script data states incl. the escaped and
     * double-escaped ones), everything after <plaintext>, <template> contents, attribute values (tags are read with the
     * tokenizer's attribute states, see readTag()), CDATA sections in svg / math. Inside svg / math a <button> is a
     * foreign element (no toggle) except in an HTML integration point (svg title / desc / foreignObject, MathML text
     * integration points, annotation-xml as HTML), whose content is HTML. A tag cut off by the end of the document is
     * dropped like the browser does (the rest is not markup).
     */
    private static function findToggle($html)
    {
        $len = strlen($html);
        $pos = 0;
        $head = null;
        $template = 0;
        // the last start tag when it was an <li>: ['at', 'end', 'attrs'] (a toggle with only white space after it is the
        // first child of that <li>); any other start tag, end tag, comment or skipped text resets it to null
        $prevLi = null;
        // svg / math content: its open elements, innermost last, as [name, island]; island = an HTML integration point
        $stack = [];
        // HTML elements opened and not yet closed, by name (approximate: no implied end tags), so an end tag inside
        // svg / math can tell "closes an enclosing element" (ends the run) from "stray" (ignored by the browser)
        $open = [];
        while ($pos < $len) {
            $lt = strpos($html, '<', $pos);
            if ($lt === false || $lt + 1 >= $len) {
                break;
            }
            $foreign = $stack !== [] && !$stack[count($stack) - 1][1];
            $c = $html[$lt + 1];
            if (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')) {
                // a start tag (tag name and attribute states of the tokenizer: TAG_RX, see readTag())
                if (preg_match(self::TAG_RX, $html, $m, 0, $lt + 1) !== 1) {
                    break;
                }
                $end = $lt + 1 + strlen($m[0]);
                $name = strtolower($m[1]);
                if (!$foreign && !isset(self::SCAN_SPECIAL[$name])) {
                    // an ordinary HTML start tag (most of a page): only counted, and the last start tag is no <li>
                    if (!isset(self::VOID[$name])) {
                        $open[$name] = ($open[$name] ?? 0) + 1;
                    }
                    $prevLi = null;
                    $pos = $end;
                    continue;
                }
                $attrs = [];
                $selfClosing = false;
                if (isset(self::ATTR_TAGS[$name]) || ($foreign && $name === 'annotation-xml')) {
                    // attributes matter here: the full read (same end as TAG_RX)
                    $tag = self::readTag($html, $lt, false);
                    if ($tag === null) {
                        break;
                    }
                    list($end, $name, $attrs, $selfClosing) = $tag;
                } elseif ($foreign) {
                    // any other element in svg / math: only its self-closing flag matters
                    $selfClosing = self::selfClosing($html, $lt + 1);
                }
                if ($foreign) {
                    if (in_array($name, self::BREAKOUT, true) || ($name === 'font' && (isset($attrs['color']) || isset($attrs['face']) || isset($attrs['size'])))) {
                        // an HTML element ends the svg / math run (down to the nearest integration point): HTML from here
                        while ($stack !== [] && !$stack[count($stack) - 1][1]) {
                            array_pop($stack);
                        }
                    } else {
                        // a foreign element (a "button" here is no HTML button); an integration point holds HTML
                        if (!$selfClosing) {
                            $stack[] = [$name, self::island($stack, $name, $attrs)];
                        }
                        $pos = $end;
                        $prevLi = null;
                        continue;
                    }
                }
                if ($name === 'svg' || $name === 'math') {
                    if (!$selfClosing) {
                        $stack[] = [$name, false];
                    }
                    $pos = $end;
                    $prevLi = null;
                    continue;
                }
                if (!isset(self::VOID[$name])) {
                    $open[$name] = ($open[$name] ?? 0) + 1;
                }
                if ($name === 'plaintext') {
                    // everything after it is text, up to the end of the document
                    break;
                }
                if ($name === 'script') {
                    $close = self::scriptEnd($html, $end);
                    if ($close === null) {
                        break;
                    }
                    $pos = $close;
                    $prevLi = null;
                    continue;
                }
                if (isset(self::RAW_TEXT[$name])) {
                    if (preg_match('~</' . $name . '(?=[\t\n\f\r />])~i', $html, $cm, PREG_OFFSET_CAPTURE, $end) !== 1) {
                        break;
                    }
                    $pos = (int)$cm[0][1];
                    $prevLi = null;
                    continue;
                }
                if ($name === 'template') {
                    $template++;
                    $pos = $end;
                    $prevLi = null;
                    continue;
                }
                if ($name === 'button' && $template === 0) {
                    if (array_key_exists(self::SLOT_ATTR, $attrs)) {
                        return null;
                    }
                    if (array_key_exists('data-color-toggle', $attrs)) {
                        $li = null;
                        if ($prevLi !== null && trim(substr($html, $prevLi['end'], $lt - $prevLi['end'])) === '') {
                            $li = ['at' => $prevLi['at'], 'attrs' => $prevLi['attrs']];
                        }
                        // a page that already has a header button further down (a hand-made one) is left as it is
                        if (strpos($html, self::SLOT_ATTR, $end) !== false && self::hasSlotAfter($html, $end)) {
                            return null;
                        }
                        // the markup inside the toggle: up to its first "</button" (any case), at most 4 KB. Searched only in
                        // that window (4096 start offsets + the needle), read in place: the same stop as a search of the rest
                        // of the page, without stripos()'s lower-case copy of the whole page on PHP < 8.2
                        $close = preg_match('~</button~i', (string)substr($html, $end, 4096 + 7), $cm, PREG_OFFSET_CAPTURE) === 1 ? $end + (int)$cm[0][1] : false;
                        $stop = min($close === false ? $len : $close, $end + 4096);
                        return ['head' => $head, 'at' => $lt, 'attrs' => $attrs, 'li' => $li, 'inner' => (string)substr($html, $end, $stop - $end)];
                    }
                }
                $prevLi = $name === 'li' ? ['at' => $lt, 'end' => $end, 'attrs' => $attrs] : null;
                $pos = $end;
                continue;
            }
            if ($c === '/') {
                $n = $lt + 2 < $len ? $html[$lt + 2] : '';
                if (!self::alpha($n)) {
                    if ($n === '>') {
                        // "</>" is dropped
                        $pos = $lt + 3;
                    } elseif ($n === '') {
                        break;
                    } else {
                        // "</ x>", "</1>": a bogus comment up to the next ">"
                        $end = strpos($html, '>', $lt + 2);
                        if ($end === false) {
                            break;
                        }
                        $pos = $end + 1;
                    }
                    $prevLi = null;
                    continue;
                }
                if (preg_match(self::TAG_RX, $html, $m, 0, $lt + 2) !== 1) {
                    break;
                }
                $name = strtolower($m[1]);
                $asHtml = true;
                if ($foreign) {
                    // "</head>", "</body>", "</html>" never close an svg / math run ("in body" ignores them there)
                    list($stack, $asHtml) = self::foreignEnd($stack, $name, !empty($open[$name]) && $name !== 'head' && $name !== 'body' && $name !== 'html');
                }
                if ($asHtml) {
                    if (!empty($open[$name])) {
                        $open[$name]--;
                    }
                    if ($stack !== [] && $stack[count($stack) - 1][0] === $name) {
                        // the end of an integration point
                        array_pop($stack);
                    } elseif ($name === 'head' && $head === null && $template === 0 && $stack === []) {
                        $head = $lt;
                    } elseif ($name === 'template' && $template > 0) {
                        $template--;
                    }
                }
                $pos = $lt + 2 + strlen($m[0]);
                $prevLi = null;
                continue;
            }
            if ($c === '!') {
                if (substr_compare($html, '<!--', $lt, 4) === 0) {
                    $end = self::commentEnd($html, $lt);
                    if ($end === null) {
                        break;
                    }
                    $pos = $end;
                } elseif ($foreign && substr_compare($html, '<![CDATA[', $lt, 9) === 0) {
                    // svg / math: a real CDATA section (its text may hold ">" and "<button")
                    $end = strpos($html, ']]>', $lt + 9);
                    if ($end === false) {
                        break;
                    }
                    $pos = $end + 3;
                } else {
                    // doctype, CDATA in HTML, other bogus comments: up to the next ">"
                    $end = strpos($html, '>', $lt + 2);
                    if ($end === false) {
                        break;
                    }
                    $pos = $end + 1;
                }
                $prevLi = null;
                continue;
            }
            if ($c === '?') {
                // "<?...>" is a bogus comment up to the next ">" (never a tag)
                $end = strpos($html, '>', $lt + 2);
                if ($end === false) {
                    break;
                }
                $pos = $end + 1;
                $prevLi = null;
                continue;
            }
            // a "<" in text ("a < b"): not a tag
            $pos = $lt + 1;
        }
        return ['head' => $head, 'at' => null, 'attrs' => [], 'li' => null, 'inner' => ''];
    }

    /** A real button with the header-button attribute after $from (same rules as findToggle(), from a tag boundary). */
    private static function hasSlotAfter($html, $from)
    {
        $hit = self::findToggle(substr($html, $from) . '<button data-color-toggle>');
        return $hit === null;
    }

    /** ASCII letter (no ctype_alpha(): it follows the locale). */
    private static function alpha($c)
    {
        return $c !== '' && (($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z'));
    }

    /** Offset after the comment that starts at $lt ("<!--"), or null when it runs to the end of the document. */
    private static function commentEnd($html, $lt)
    {
        // "<!-->" and "<!--->" are complete (empty) comments
        if (substr_compare($html, '<!-->', $lt, 5) === 0) {
            return $lt + 5;
        }
        if (substr_compare($html, '<!--->', $lt, 6) === 0) {
            return $lt + 6;
        }
        // the first "-->" or "--!>" ends it
        $a = strpos($html, '-->', $lt + 4);
        $b = strpos($html, '--!>', $lt + 4);
        if ($a === false && $b === false) {
            return null;
        }
        return $b !== false && ($a === false || $b < $a) ? $b + 4 : $a + 3;
    }

    /**
     * Offset of the "</script" that ends a script element whose content starts at $from, or null (the rest of the
     * document is script text). Script data states of the tokenizer: "<!--" starts the escaped state, where "<script"
     * starts the double-escaped state (a "</script" there only returns to the escaped state); "-->" goes back to plain
     * script data.
     */
    private static function scriptEnd($html, $from)
    {
        $len = strlen($html);
        $pos = $from;
        $state = 0; // 0 script data, 1 escaped, 2 double escaped
        $ws = '(?=[\t\n\f\r />])';
        while ($pos < $len) {
            if ($state === 0) {
                if (preg_match('~</script' . $ws . '|<!--~i', $html, $m, PREG_OFFSET_CAPTURE, $pos) !== 1) {
                    return null;
                }
                $at = (int)$m[0][1];
                if ($m[0][0][1] === '/') {
                    return $at;
                }
                // "<!--" (escaped); its own dashes count: "<!-->" and "<!--->" go back at once
                $pos = $at + 4;
                $dashes = strspn($html, '-', $pos);
                if ($pos + $dashes < $len && $html[$pos + $dashes] === '>') {
                    $pos += $dashes + 1;
                } else {
                    $state = 1;
                }
                continue;
            }
            if ($state === 1) {
                if (preg_match('~</script' . $ws . '|<script' . $ws . '|-->~i', $html, $m, PREG_OFFSET_CAPTURE, $pos) !== 1) {
                    return null;
                }
                $at = (int)$m[0][1];
                if ($m[0][0] === '-->') {
                    $state = 0;
                    $pos = $at + 3;
                } elseif ($m[0][0][1] === '/') {
                    return $at;
                } else {
                    $state = 2;
                    $pos = $at + 7;
                }
                continue;
            }
            if (preg_match('~</script' . $ws . '|-->~i', $html, $m, PREG_OFFSET_CAPTURE, $pos) !== 1) {
                return null;
            }
            $at = (int)$m[0][1];
            if ($m[0][0] === '-->') {
                $state = 0;
                $pos = $at + 3;
            } else {
                $state = 1;
                $pos = $at + 8;
            }
        }
        return null;
    }

    /**
     * One start or end tag at $lt, read with the tokenizer's tag name and attribute states (a quoted value holds any
     * character; an unquoted one ends at white space or ">"; a quote elsewhere is part of a name). Returns
     * [offset after ">", lower-case name, attributes (lower-case names, first one wins, values decoded), self-closing]
     * or null when the document ends inside the tag (the browser drops such a tag).
     */
    private static function readTag($html, $lt, $isEnd)
    {
        $len = strlen($html);
        $i = $lt + ($isEnd ? 2 : 1);
        $n = strcspn($html, self::WS . '/>', $i);
        $name = strtolower(substr($html, $i, $n));
        $i += $n;
        $attrs = [];
        while (true) {
            // before attribute name; a "/" not directly before ">" is skipped like white space
            $slash = false;
            while ($i < $len) {
                $ch = $html[$i];
                if ($ch === '/') {
                    $slash = true;
                } elseif (strpos(self::WS, $ch) !== false) {
                    $slash = false;
                } else {
                    break;
                }
                $i++;
            }
            if ($i >= $len) {
                return null;
            }
            if ($html[$i] === '>') {
                return [$i + 1, $name, $attrs, $slash];
            }
            // attribute name: its first character belongs to it even when it is "="
            $a = $i;
            $i++;
            $i += strcspn($html, self::WS . '/>=', $i);
            $attr = strtolower(substr($html, $a, $i - $a));
            $i += strspn($html, self::WS, $i);
            if ($i >= $len) {
                return null;
            }
            $value = '';
            if ($html[$i] === '=') {
                $i++;
                $i += strspn($html, self::WS, $i);
                if ($i >= $len) {
                    return null;
                }
                $q = $html[$i];
                if ($q === '"' || $q === "'") {
                    $e = strpos($html, $q, $i + 1);
                    if ($e === false) {
                        return null;
                    }
                    $value = substr($html, $i + 1, $e - $i - 1);
                    $i = $e + 1;
                } elseif ($q !== '>') {
                    $v = strcspn($html, self::WS . '>', $i);
                    $value = substr($html, $i, $v);
                    $i += $v;
                }
            }
            if (!array_key_exists($attr, $attrs)) {
                $attrs[$attr] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
    }

    /** Self-closing flag of the start tag whose name begins at $from (after its "<"), the way readTag() reads it. */
    private static function selfClosing($html, $from)
    {
        if (preg_match(self::TAG_SC_RX, $html, $m, PREG_OFFSET_CAPTURE, $from) !== 1) {
            return false;
        }
        $gt = $from + strlen($m[0][0]) - 1; // the ">" that ends the tag
        return isset($m[2]) && $m[2][1] >= 0 && $m[2][1] + strlen($m[2][0]) === $gt && substr($m[2][0], -1) === '/';
    }

    /** Is a new svg / math element an HTML integration point (its content is HTML)? */
    private static function island(array $stack, $name, array $attrs)
    {
        $root = 'svg';
        for ($k = count($stack) - 1; $k >= 0; $k--) {
            if ($stack[$k][0] === 'svg' || $stack[$k][0] === 'math') {
                $root = $stack[$k][0];
                break;
            }
        }
        if ($root === 'svg') {
            return in_array($name, ['foreignobject', 'desc', 'title'], true);
        }
        if (in_array($name, ['mi', 'mo', 'mn', 'ms', 'mtext'], true)) {
            return true;
        }
        $encoding = strtolower(trim((string)($attrs['encoding'] ?? '')));
        return $name === 'annotation-xml' && ($encoding === 'text/html' || $encoding === 'application/xhtml+xml');
    }

    /**
     * An end tag inside svg / math content: [the stack after it, true when the tag is then handled as HTML].
     * $htmlOpen: an HTML element of that name is open around the run.
     */
    private static function foreignEnd(array $stack, $name, $htmlOpen)
    {
        if ($name !== 'p' && $name !== 'br') {
            // the nearest open element of that name in this run (down to the nearest integration point)
            for ($k = count($stack) - 1; $k >= 0; $k--) {
                if ($stack[$k][0] === $name) {
                    return [array_slice($stack, 0, $k), false];
                }
                if ($stack[$k][1]) {
                    break;
                }
            }
            if (!$htmlOpen) {
                // a stray end tag: the browser ignores it and the run goes on
                return [$stack, false];
            }
        }
        // "</p>", "</br>" or the end tag of an enclosing HTML element ("</a>", "</div>") ends the run (down to the
        // nearest integration point) and is then handled as HTML
        while ($stack !== [] && !$stack[count($stack) - 1][1]) {
            array_pop($stack);
        }
        return [$stack, true];
    }

    /** Class tokens to copy: valid names only, no switcher classes, minus $drop (or the generic rule), at most 12. */
    private static function cleanClasses($class, array $drop, $generic)
    {
        $out = [];
        foreach (preg_split('/\s+/', trim((string)$class)) ?: [] as $token) {
            if ($token === '' || !preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/D', $token) || in_array($token, $out, true)
                || strncasecmp($token, 'jv-ts', 5) === 0) {
                continue;
            }
            if ($generic ? preg_match(self::COLOR_CLASS_RX, $token) === 1 : in_array($token, $drop, true)) {
                continue;
            }
            $out[] = $token;
            if (count($out) >= 12) {
                break;
            }
        }
        return implode(' ', $out);
    }

    /** The header button: same accessible name and dialog as the floating button; cache-stable (no visitor data). */
    private static function slotMarkup(array $toggle, array $recipe, $generic, array $payload, $current, $inner = '')
    {
        $classes = self::cleanClasses((string)($toggle['class'] ?? ''), (array)($recipe['drop'] ?? []), $generic);
        $classes = trim($classes . ' jv-ts-hbtn');
        $open = trim((string)($payload['t']['open'] ?? ''));
        $open = $open === '' ? 'Change theme' : $open;
        $name = '';
        foreach ((array)($payload['themes'] ?? []) as $row) {
            if (is_array($row) && ($row['slug'] ?? null) === $current) {
                $name = (string)($row['name'] ?? '');
                break;
            }
        }
        if ($name === '' && self::validName($current)) {
            $name = self::name($current);
        }
        if ($name === '') {
            $first = (array)($payload['themes'][0] ?? []);
            $name = (string)($first['name'] ?? '');
        }
        $label = trim($name) === '' ? $open : $open . ': ' . trim($name);
        $icon = self::slotIcon($recipe, $generic, (string)$inner);
        if (!empty($recipe['orb']) && is_string($recipe['orb'])) {
            $icon = '<span class="' . self::esc($recipe['orb']) . '">' . $icon . '</span>';
        }
        $caption = '';
        if (!empty($recipe['cap']) && is_string($recipe['cap'])) {
            $short = trim(TemplateText::raw('ui.theme_switcher_short'));
            if ($short === '' || $short === 'ui.theme_switcher_short') {
                $short = trim((string)($payload['t']['title'] ?? ''));
            }
            if ($short !== '') {
                $caption = '<span class="' . self::esc($recipe['cap']) . '">' . self::esc($short) . '</span>';
            }
        }
        // inert until theme-switcher.js marks it live (with data-jv-ts-live, see slotCss()): no Tab stop, no click, not in
        // the accessibility tree, also for a widget of round 1 that a partial update or a cache still serves with this page
        // (it reads an inert header button as unusable and shows the floating button: fix round 3, SVVF3-P3-1 / SVVC3-P3-1)
        return '<button type="button" class="' . self::esc($classes) . '" ' . self::SLOT_ATTR . '="" inert="" aria-haspopup="dialog"'
            . ' aria-expanded="false" aria-controls="jv-ts" aria-label="' . self::esc($label) . '" title="' . self::esc($open) . '">'
            . $icon . $caption . '</button>';
    }

    /** Palette icon: the theme's auto-icon where the recipe says so, else the widget's line icon (Lucide, ISC). */
    private static function slotIcon(array $recipe, $generic, $inner = '')
    {
        $cls = isset($recipe['cls']) && is_string($recipe['cls']) && preg_match('/\A[A-Za-z0-9 _-]{1,100}\z/', $recipe['cls']) ? $recipe['cls'] : '';
        if (($recipe['icon'] ?? 'svg') === 'ai' && class_exists(AutoIcon::class)) {
            $options = [];
            // 'theme' = no tint option: the theme's own auto-icon tint, like its light/dark and tool icons
            $tint = isset($recipe['tint']) && is_string($recipe['tint']) ? $recipe['tint'] : 'none';
            if (in_array($tint, ['none', 'slot', 'accent'], true)) {
                $options['tint'] = $tint;
            }
            if ($cls !== '') {
                $options['class'] = $cls;
            }
            $markup = AutoIcon::ui('palette', $options);
            if (is_string($markup) && $markup !== '') {
                return $markup;
            }
        }
        $size = isset($recipe['size']) ? (int)$recipe['size'] : ($generic ? 20 : 0);
        $attrs = 'viewBox="0 0 24 24"' . ($size > 0 && $size <= 64 ? ' width="' . $size . '" height="' . $size . '"' : '')
            . ($cls !== '' ? ' class="' . self::esc($cls) . '"' : '')
            . ' fill="none" stroke="currentColor" stroke-width="' . self::slotStroke($recipe, (string)$inner) . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
        return '<svg ' . $attrs . '>'
            . '<path d="M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z"/>'
            . '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/>'
            . '<circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/>'
            . '</svg>';
    }

    /**
     * Line width of the inline icon: the recipe's 'stroke', else the one of the theme's own light/dark icon (the first
     * <svg> inside the toggle: its stroke-width attribute, or stroke-width / --fx-icon-stroke in its style), else 2.
     * Only plain numbers from 0.5 to 3 are taken.
     */
    private static function slotStroke(array $recipe, $inner)
    {
        $pick = function ($value) {
            $value = trim((string)$value);
            return preg_match('/\A(?:[0-2](?:\.[0-9]{1,3})?|3(?:\.0{1,3})?)\z/', $value) === 1 && (float)$value >= 0.5 ? $value : null;
        };
        if (isset($recipe['stroke']) && is_scalar($recipe['stroke']) && ($value = $pick($recipe['stroke'])) !== null) {
            return $value;
        }
        if ($inner !== '' && preg_match('~<svg(?=[\t\n\f\r />])~i', $inner, $m, PREG_OFFSET_CAPTURE) === 1) {
            $tag = self::readTag($inner, (int)$m[0][1], false);
            if ($tag !== null) {
                $attrs = $tag[2];
                if (isset($attrs['stroke-width']) && ($value = $pick($attrs['stroke-width'])) !== null) {
                    return $value;
                }
                if (isset($attrs['style']) && preg_match('/(?:\A|;)\s*(?:--fx-icon-stroke|stroke-width)\s*:\s*([0-9.]+)\s*(?:;|\z)/i', $attrs['style'], $sm) === 1
                    && ($value = $pick($sm[1])) !== null) {
                    return $value;
                }
            }
        }
        return '2';
    }

    /** CSS of the header button (no comments and no '/' + '*': app.compress strips those). */
    private static function slotCss(array $recipe)
    {
        $box = '.jv-ts-hbtn,.jv-ts-hli';
        // without JavaScript the header button could not open anything: not drawn then (no layout shift for the others).
        // With JavaScript it is drawn only once theme-switcher.js can open its panel (the widget removes inert and sets
        // data-jv-ts-live when its stylesheet has loaded): when the script or its stylesheet does not run (blocked, not
        // loaded, a partial update, an error) it is never a dead control. Visibility only: the header row keeps its layout
        // from the first paint, so nothing moves when the widget arrives or fails (CLS 0).
        // The icon inside is covered too: a theme rule such as pointer-events:all keeps SVG shapes clickable while hidden.
        $wait = '.jv-ts-hbtn:not([data-jv-ts-live])';
        $css = '.jv-ts-hbtn svg{flex:none}' . $wait . ',' . $wait . ' *{visibility:hidden!important;pointer-events:none!important}'
            . '@media print{' . $box . '{display:none!important}}@media (scripting:none){' . $box . '{display:none!important}}';
        foreach ((array)($recipe['hide'] ?? []) as $media) {
            if (is_string($media) && preg_match('/\A[a-z0-9():\-\s.]{1,120}\z/', $media)) {
                $css .= '@media ' . $media . '{' . $box . '{display:none!important}}';
            }
        }
        if (isset($recipe['css']) && is_string($recipe['css']) && strpos($recipe['css'], '<') === false && strpos($recipe['css'], '/*') === false) {
            $css .= $recipe['css'];
        }
        return $css;
    }

    private static function esc($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
