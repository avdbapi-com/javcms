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
namespace app\index\controller;

use app\common\util\SeoSettings;

/**
 * /robots.txt, generated for the site that receives the request (SEO-ADMIN-BRIEF E.2).
 *
 * Every shipped server profile (Apache/OpenLiteSpeed .htaccess, IIS web.config, Nginx locations)
 * routes /robots.txt here, and the generic front-controller fallback of any panel does the same,
 * because the package ships no static robots.txt.
 *
 * Rules (seo.robots_mode): "recommended" (default: internal search queries and every search alias, `by=` list orders,
 * `after=` / `cursor=` continuation links (XVID-NOLIMIT-BRIEF H6),
 * api.php, member pages and the guestbook are not crawled; one group for every crawler and no Crawl-delay (owner decision
 * 2026-10-17: search engines are never rate-limited; 1.2.0 drafts slowed bingbot / Yandex to one page per 5 s, about
 * 17,000 pages a day, far too few for a large catalog); seo.robots_ai = 0 blocks
 * AI crawlers (default allowed, XVID-SCALE-BRIEF L9 / D9); tag / actor landing pages, static files and the admin entry are never listed), "open" (everything) or
 * "custom" (seo.robots_custom, rule lines only). An optional robots.txt in the site root still wins (its Sitemap
 * lines for this site's own sitemap are replaced). While seo.hide_site = 1 the output is "allow everything" without
 * a Sitemap line: blocked pages could not see their noindex (CF02).
 * The Sitemap line comes from Sitemap::indexUrl(): the configured site domain (domain bindings
 * included), the canonical scheme and the rewrite mode, never a raw Host header.
 *
 * Like the sitemap endpoint it extends think\Controller: no session, user or theme rendering.
 */
class Robots extends \think\Controller
{
    const MAX_BYTES = 65536;

    public function index()
    {
        if (strtolower((string)$this->request->ext()) !== 'txt') {
            // /robots or /robots.html are not robots files.
            throw new \think\exception\HttpException(404, 'Not Found');
        }
        // An explicit Expires keeps server-level expiry rules (mod_expires) from adding a second max-age.
        return response(self::text(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'Expires' => gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The robots.txt text. Also the admin preview (make/robots?preview=1): $mode / $custom override the stored
     * seo.robots_mode / seo.robots_custom (unsaved form values, decoded) without saving anything.
     */
    public static function text($mode = null, $custom = null)
    {
        $hidden = SeoSettings::bool('seo.hide_site');
        $sitemap = self::sitemapUrl();
        // Own Sitemap lines of a root file are replaced by ours, or dropped while hidden (no sitemap exists then).
        $lines = self::rootRules($hidden || $sitemap !== '');
        if ($lines === null) {
            $lines = $hidden ? self::openRules() : self::modeRules($mode === null ? (string)SeoSettings::get('seo.robots_mode') : (string)$mode, $custom);
        }
        if (!$hidden && $sitemap !== '' && !in_array('Sitemap: ' . $sitemap, $lines, true)) {
            if ($lines && end($lines) !== '') {
                $lines[] = '';
            }
            $lines[] = 'Sitemap: ' . $sitemap;
        }
        return implode("\n", $lines) . "\n";
    }

    /** True when a robots.txt file in the site root is used instead of the settings (admin notice robots_root_file). */
    public static function rootFile()
    {
        return self::rootRules(false) !== null;
    }

    /** Absolute URL of the sitemap index, or '' while the site domain is not configured. */
    protected static function sitemapUrl()
    {
        try {
            $url = \app\common\util\Sitemap::indexUrl();
        } catch (\Throwable $error) {
            return '';
        }
        return is_string($url) && preg_match('~^https?://[^\s]+$~i', $url) ? $url : '';
    }

    protected static function openRules()
    {
        return ['User-agent: *', 'Disallow:'];
    }

    protected static function modeRules($mode, $custom)
    {
        if ($mode === 'open') {
            return self::openRules();
        }
        if ($mode === 'custom') {
            $text = $custom === null ? (string)SeoSettings::get('seo.robots_custom') : (string)$custom;
            $check = SeoSettings::robotsCustom($text);
            $lines = $check['ok'] ? self::cleanLines($check['value'], true, true) : [];
            return $lines ?: self::openRules();
        }
        return self::recommendedRules();
    }

    /**
     * "Recommended": internal search result URLs, member pages and the guestbook are not crawled. Prefixes are built
     * from the live URL mode; a prefix is dropped when it is empty, the site root, or a prefix of a tag / actor
     * landing URL, so no URL mode can block the landing pages (CF13). The ?wd= / &wd= lines always stay.
     */
    public static function recommendedRules()
    {
        $landing = [self::path(mac_url('vod/search', ['tag' => 'JVQ'])), self::path(mac_url('vod/search', ['actor' => 'JVQ']))];
        $search = self::searchPrefix(mac_url('vod/search', ['wd' => 'JVQ']));
        $user = self::firstSegment(mac_url('user/index'));
        $gbook = self::firstSegment(mac_url('gbook/index'));
        $rules = [];
        if (self::keepPrefix($search, $landing)) { $rules[] = 'Disallow: ' . $search; }
        $rules[] = 'Disallow: /*?wd=';
        $rules[] = 'Disallow: /*&wd=';
        // 1.2.0 (XVID-SCALE-BRIEF L9, D9): every search alias, list orders other than the default, and the provider API
        // are never crawled; an alias that would also match a tag / actor landing URL in this URL mode is left out (CF13).
        foreach (self::searchAliases() as $pattern) {
            if (!self::matchesAny($pattern, $landing)) { $rules[] = 'Disallow: ' . $pattern; }
        }
        $rules[] = 'Disallow: /*?*by=';
        // XVID-NOLIMIT-BRIEF H6: continuation links of deep lists (after=) and searches (cursor=) are served (200, noindex)
        // but never crawled: each one is a fresh deep page and they never end
        $rules[] = 'Disallow: /*?*after=';
        $rules[] = 'Disallow: /*?*cursor=';
        $api = self::rootPath() . 'api.php';
        $rules[] = 'Disallow: ' . $api;
        foreach ([$user, $gbook] as $prefix) {
            if (self::keepPrefix($prefix, $landing)) { $rules[] = 'Disallow: ' . $prefix; }
        }
        $rules = array_values(array_unique($rules));
        $rules[] = 'Allow: /';
        // One group for every crawler, no Crawl-delay (owner decision 2026-10-17: search engines are never slowed down;
        // verified crawlers are also exempt from the per-visitor budgets, RequestGate).
        $lines = array_merge(['User-agent: *'], $rules);
        // AI crawler switch (seo.robots_ai, default 1 = allowed on HTML pages, D9): off -> those crawlers get nothing.
        if (!SeoSettings::bool('seo.robots_ai')) {
            $lines[] = '';
            foreach (self::AI_CRAWLERS as $agent) { $lines[] = 'User-agent: ' . $agent; }
            $lines[] = 'Disallow: /';
        }
        return $lines;
    }

    /** User agents of AI training / answer crawlers (blocked only when the owner switches seo.robots_ai off). */
    const AI_CRAWLERS = ['GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'anthropic-ai',
        'CCBot', 'Google-Extended', 'Applebot-Extended', 'PerplexityBot', 'Perplexity-User', 'Bytespider', 'Amazonbot',
        'meta-externalagent', 'cohere-ai', 'Diffbot', 'YouBot', 'Timpibot'];

    /** The installation root path with a trailing slash. */
    protected static function rootPath()
    {
        return defined('MAC_PATH') && is_string(MAC_PATH) && MAC_PATH !== '' ? rtrim(MAC_PATH, '/') . '/' : '/';
    }

    /**
     * Robots patterns for the search aliases of L3 (they all 301 to the canonical search URL): the PATH_INFO form, the
     * ?s= query forms, the .html suffix form and the GET form of the AJAX search.
     */
    public static function searchAliases()
    {
        $root = self::rootPath();
        return [$root . 'index.php/vod/search', $root . '*?s=/vod/search', $root . '*?s=vod/search', $root . '*&s=/vod/search',
            $root . 'vod/search.html', $root . 'vod/ajax_search', $root . 'index.php/vod/ajax_search'];
    }

    /** True when a robots pattern (prefix, "*" any run, "$" end) matches one of the paths. */
    public static function matchesAny($pattern, array $paths)
    {
        $regex = '~^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote((string)$pattern, '~')) . '~';
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && preg_match($regex, $path)) { return true; }
        }
        return false;
    }

    /** CF13 guard: a usable Disallow prefix that cannot block the site root or a landing page. */
    public static function keepPrefix($prefix, array $landing)
    {
        $root = defined('MAC_PATH') && is_string(MAC_PATH) && MAC_PATH !== '' ? MAC_PATH : '/';
        if (!is_string($prefix) || $prefix === '' || $prefix === '/' || $prefix === $root || $prefix[0] !== '/'
            || preg_match('/[\s\x00-\x1F\x7F*$#]/', $prefix)) {
            return false;
        }
        foreach ($landing as $url) {
            if (is_string($url) && $url !== '' && strpos($url, $prefix) === 0) { return false; }
        }
        // Never name an entry script (the admin entry is secret; index.php is the whole site without rewriting).
        return !preg_match('~(?:^|/)[^/]+\.php/?$~i', $prefix);
    }

    /** Path part of a built URL (absolute URLs from domain bindings included); '' when unusable. */
    protected static function path($url)
    {
        $url = (string)$url;
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) || strpos($url, '//') === 0) {
            $url = (string)parse_url($url, PHP_URL_PATH);
        }
        return $url;
    }

    /** The search URL cut before the query word ("/search/wd/JVQ/" -> "/search/wd/"); only its path part. */
    protected static function searchPrefix($url)
    {
        $url = self::path(strstr((string)$url, 'JVQ', true));
        $query = strpos($url, '?');
        return $query === false ? $url : substr($url, 0, $query);
    }

    /** The first path segment after the site root and an optional index.php/ ("/user/index/" -> "/user/"). */
    protected static function firstSegment($url)
    {
        $path = self::path($url);
        $query = strpos($path, '?');
        if ($query !== false) { $path = substr($path, 0, $query); }
        $root = defined('MAC_PATH') && is_string(MAC_PATH) && MAC_PATH !== '' ? MAC_PATH : '/';
        if (strpos($path, $root) !== 0) { return ''; }
        $rest = substr($path, strlen($root));
        $entry = '';
        if (stripos($rest, 'index.php/') === 0) { $entry = 'index.php/'; $rest = substr($rest, strlen($entry)); }
        $segment = explode('/', $rest)[0];
        $segment = preg_replace('/\.html?$/i', '', $segment);
        if ($segment === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $segment) || preg_match('/\.php$/i', $segment)) { return ''; }
        return $root . $entry . $segment . '/';
    }

    /** Custom rules from the root robots.txt, or null when there is no usable file (then the settings apply). */
    protected static function rootRules($stripOwnSitemap)
    {
        $file = ROOT_PATH . 'robots.txt';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $text = @file_get_contents($file, false, null, 0, self::MAX_BYTES);
        if (!is_string($text) || trim($text) === '') {
            return null;
        }
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }
        $lines = self::cleanLines($text, $stripOwnSitemap, false);
        return $lines ?: null;
    }

    /**
     * Lines without control characters; Sitemap lines for this site's own sitemap removed (announced below with the
     * right host and scheme, or not at all while hidden). Custom rules from the admin also lose every line that
     * names an admin entry file (it is secret).
     */
    protected static function cleanLines($text, $stripOwnSitemap, $dropAdmin)
    {
        $root = defined('MAC_PATH') && is_string(MAC_PATH) && MAC_PATH !== '' ? MAC_PATH : '/';
        $own = [strtolower($root . 'sitemap.xml'), strtolower($root . 'index.php')];
        $admin = $dropAdmin ? self::adminEntries() : [];
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
            $line = rtrim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $line));
            if (preg_match('/^\s*sitemap\s*:\s*(\S+)/i', $line, $match)) {
                $path = strtolower((string)parse_url($match[1], PHP_URL_PATH));
                if ($stripOwnSitemap && in_array($path, $own, true)) {
                    continue;
                }
            }
            foreach ($admin as $name) {
                if (stripos($line, $name) !== false) { continue 2; }
            }
            $lines[] = $line;
        }
        while ($lines && end($lines) === '') {
            array_pop($lines);
        }
        while ($lines && reset($lines) === '') {
            array_shift($lines);
        }
        return $lines;
    }

    /** File names of the admin entry scripts in the site root (any root *.php that defines ENTRANCE admin). */
    protected static function adminEntries()
    {
        $names = [];
        foreach (glob(ROOT_PATH . '*.php') ?: [] as $file) {
            $name = basename($file);
            if (in_array(strtolower($name), ['index.php', 'api.php', 'install.php'], true)) { continue; }
            $head = @file_get_contents($file, false, null, 0, 8192);
            if (is_string($head) && preg_match('/define\(\s*[\'"]ENTRANCE[\'"]\s*,\s*[\'"]admin[\'"]\s*\)/', $head)) { $names[] = $name; }
        }
        return $names;
    }
}
