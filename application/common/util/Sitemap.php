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

use think\Cache;
use think\Db;

/**
 * Read-only, bounded sitemap feeds (SEO-ADMIN-BRIEF E.1). No installer, user session or image downloads.
 *
 * Index /sitemap.xml: pages, videos of the default language (seo.sitemap_per_file videos per file, video: blocks when
 * seo.sitemap_video = 1), the same files per indexed non-default language (seo.sitemap_langs = 1), actors / topics /
 * articles (200 per file, only when the module has rows). Every child carries <lastmod> from real change dates.
 * Child URLs keep the query form sitemap.xml?feed=videos&bucket=<n>[&lang=<code>] (CF12); bucket n holds the videos
 * with vod_id in (n * size, (n + 1) * size]. Files are cached gzip-compressed (Content-Encoding for clients that
 * accept it). While seo.hide_site = 1 there is no sitemap at all: payload() returns null and the endpoint answers 404 (CF02).
 *
 * 1.2.0 at scale (XVID-SCALE-BRIEF D-L9, L7): no visitor request aggregates the video table. The per-bucket lastmod / count
 * table is the stored {pre}sitemap_bucket (bucket, max_time, cnt), rebuilt per dirty bucket by housekeeping through
 * refreshBuckets() (new ids, rows whose vod_time moved, a rotating sweep for deletions and hides; PK ranges only); the
 * per-language lastmod comes from runtime/scale/sitemap_lang.json written by the same job; category lastmod comes from
 * scale_count (type_mod_<id>, ScaleCounts). Video files are built by PK range and cached 24 h under a key that holds the
 * bucket's (max_time, cnt), so a changed bucket gets a new file without any cache tag. perFile() is 5,000 (10,000 above
 * 5 M videos) unless the owner set seo.sitemap_per_file. A missing or not yet built bucket table makes the index answer
 * 503 Retry-After 300 (the endpoint's error path); a small site (tier 1) builds it inline once.
 */
class Sitemap
{
    const BUCKET_SIZE = 200;            // actors, topics, articles (unchanged)
    const CACHE_SECONDS = 300;          // the index and the entity files; also the HTTP max-age of every sitemap
    const LAST_GOOD_SECONDS = 604800;   // a failed rebuild answers with the last good copy up to 7 days old (owner decision 2026-10-17)
    const VIDEO_CACHE_SECONDS = 3600;   // the pages file (E.1)
    const VIDEO_FILE_SECONDS = 86400;   // video files (D-L9: PK range, keyed by the bucket's max_time and cnt)
    const PREBUILD_AGE = 72000;         // prebuild(): a stored default-language video file older than 20 h is built again (verify r2 R2-12)
    const WANT_MAX = 5000;              // verify r3 N20: video files answered from an older copy, remembered for prebuild()
    const VISITOR_FILES = 1;            // verify r3 N20: files one visitor-request slice may build (hosts without cron / finish-request)
    const BUILD_GUESS = 0.8;            // seconds a video file build is assumed to take before one was measured on this site
    /**
     * site-r3 N20 (never-built file): a video file with no stored copy and no last good copy at all is answered with its lite
     * build (the same URLs and lastmod, without the video: blocks, whose per-row text is most of the build: about 0.1 s per
     * 5,000 URLs instead of 0.4-0.8 s), cached LITE_FILE_SECONDS and put on the wanted list; prebuild() builds the full file
     * next (cron, the run after a finished response, a visitor slice). Only buckets of at least LITE_MIN_URLS videos (a small
     * file is built whole at once).
     */
    const LITE_FILE_SECONDS = 900;
    const LITE_MIN_URLS = 1000;
    /**
     * flood-r4 F3 / G9 (cold never-built file): per bucket a compact list of its published rows (vod_id, vod_time, type_id,
     * vod_sub, the play flags and first-source facts the canonical URL needs), runtime/scale/sitemap_rows/<bucket>.gz, written
     * by the bucket refresh while it reads the bucket anyway (rebuildRange(): one PK range read) and by rowsBackfill() for
     * buckets without one. The lite build of a never-built default-language file (English default language) reads it instead
     * of the bucket's 10,000 wide rows (a cold pool read 2,900-3,400 table pages for it: 0.39-0.47 s here, about 0.95 s on a
     * VPS SSD). A list belongs to one bucket version (newest vod_time and count, file size, copyright rule): any other version
     * is ignored and the lite build reads the table as before. ROWS_SOURCES: sources whose first episode is flagged (a row
     * with more sources reads its play list when its first source is not usable, as watchTargets() does).
     */
    const ROWS_VERSION = 1;
    const ROWS_SOURCES = 4;
    const ROWS_MIN_FREE = 1073741824;   // no list is written while the disk holding runtime/ has less than 1 GB free
    const BIG_SITE_ROWS = 5000000;      // above this many videos the default file size is 10,000 (D-L9)
    const BUILD_CHUNK = 20;             // buckets per statement while the table is first built (command line)
    const WEB_CHUNK = 2;                // buckets per statement in a web housekeeping slice (verify r2 R2-8 / R2-9)
    const INLINE_CHUNK = 1;             // buckets per statement in a slice inside a visitor request (no finish-request)
    const LANG_ROWS = 20000;            // translation rows per statement of the per-language lastmod (command line)
    const LANG_ROWS_WEB = 5000;         // the same in web slices (R2-8: one GROUP BY over 1.6 M rows ran past 50 s)
    const LANG_INDEX_FACTOR = 5;        // verify r3 R3-8: x5 rows per statement when the windows read idx_tr_lastmod only
    const LANG_FLUSH = 20;              // buckets per write of sitemap_lang.json while the language queue is worked off
    const LANG_PENDING_MAX = 50000;     // buckets whose language part waits for a later run
    const SWEEP_SECONDS = 2;            // rotating re-check of old buckets per housekeeping run (deletions, hides)
    /** verify r3 R3-8: command-line runs pause this share of each statement's time (1 core: visitors share it; else like
     * FtIndex's rebuild throttle), so a sitemap build never holds the database for the whole run (pauseShare()). */
    const PAUSE_ONE_CORE = 1.0;
    const PAUSE_MULTI_CORE = 0.25;
    const STATE_VERSION = 1;
    const VIDEO_TAGS = 32;
    const GZIP_LEVEL = 2;               // verify r2 R2-12: level 2 = 38 ms for a 9.7 MB video file (level 5: 71 ms, 9 % smaller)
    const NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    const VIDEO_NS = 'http://www.google.com/schemas/sitemap-video/1.1';
    /**
     * Columns that decide the canonical video URL (C.3): a has_play flag instead of the play-list text (CF12), plus
     * only the first episode of the first source (its player URL becomes video:player_loc).
     */
    const VIDEO_URL_FIELDS = "vod_id,type_id,vod_sub,vod_en,vod_name,vod_time,vod_time_add,vod_play_from,vod_pwd_play,vod_points_play,vod_trysee,(vod_play_url <> '') AS has_play,SUBSTRING_INDEX(SUBSTRING_INDEX(vod_play_url,'$$$',1),'#',1) AS first_ep";

    private static $detailRows = [];
    private static $types = [];
    private static $categoryMetadata;
    private static $pageLocales;
    private static $bucketMemo = null;
    private static $feedBase = [];
    private static $urlTemplates = [];   // videoUrl(): [template, sentinel, rows checked] | false, per request
    private static $absBase = [];        // absolute(): validated scheme + host per request (R2-11)
    private static $httpsMemo = [];      // requestIsHttps(): trusted-peer answers of this request (R2-11)
    private static $langMemo = null;     // languageTable() of this request (verify r3 N20: part of the language file keys)
    private static $building = false;    // prebuild() is building: a cache miss builds the file (never the older copy)
    private static $langTimedOut = false; // langQueue() stopped on a statement timeout in this call (refreshBuckets reports it)
    private static $rowsOff = false;      // flood-r4 G9: the video table lacks the columns of the rows lists (no lists in this process)

    public static function validBucket($value)
    {
        if (!is_string($value) && !is_int($value)) { return null; }
        $value = (string) $value;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,7})$/D', $value) || (float) $value > 21474835) { return null; }
        return (int) $value;
    }

    public static function xmlEscape($value)
    {
        $value = (string) $value;
        // the regex only runs when a control character is there (most values have none; 7 M-video files: 10,000 calls)
        if (strpbrk($value, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0b\x0c\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f") !== false) {
            $value = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $value);
        }
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function lastmod($timestamp)
    {
        $timestamp = (int) $timestamp;
        return $timestamp > 0 && $timestamp <= time() ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : '';
    }

    // ------------------------------------------------------------------ settings (E.1, A.8)

    /**
     * Videos per video file: seo.sitemap_per_file when the owner set it (1,000-50,000), else 5,000, or 10,000 when the
     * site holds more than 5 M videos (D-L9, ScaleTier rows).
     */
    public static function perFile()
    {
        $stored = SeoSettings::isStored('seo.sitemap_per_file') ? (int) SeoSettings::int('seo.sitemap_per_file') : 0;
        return self::sizeFor($stored, (int) (ScaleTier::get()['rows'] ?? 0));
    }

    /** Pure: the file size for a stored setting (0 = not set) and a catalog size. */
    public static function sizeFor($stored, $rows)
    {
        $stored = (int) $stored;
        if ($stored >= 1000 && $stored <= 50000) { return $stored; }
        return (int) $rows > self::BIG_SITE_ROWS ? 10000 : 5000;
    }

    /** Languages with their own video files: indexed, not the default, and only while seo.sitemap_langs = 1 (SD3). */
    public static function languageFiles()
    {
        if (!SeoSettings::bool('seo.sitemap_langs')) { return []; }
        return array_values(array_diff(AvdbLanguage::indexedCodes(), [AvdbLanguage::defaultLocale()]));
    }

    /** A requested language file: an indexed non-default code, else null (the endpoint answers 404). */
    public static function validLang($value)
    {
        return is_string($value) && in_array($value, self::languageFiles(), true) ? $value : null;
    }

    /** seo.hide_site = 1: no sitemap exists (CF02). */
    public static function hidden()
    {
        return SeoSettings::bool('seo.hide_site');
    }

    /** Bucket of a video id: bucket n holds vod_id in (n * size, (n + 1) * size] (CF12). */
    public static function bucketOf($id, $size = null)
    {
        $size = $size === null ? self::perFile() : max(1, (int) $size);
        return intdiv(max(1, (int) $id) - 1, $size);
    }

    /** True when an Accept-Encoding header allows gzip (a "gzip" or "x-gzip" token without q=0). */
    public static function acceptsGzip($header)
    {
        if (!is_string($header) || $header === '' || !function_exists('gzencode')) { return false; }
        foreach (explode(',', strtolower($header)) as $item) {
            $parts = explode(';', $item);
            $name = trim($parts[0]);
            if ($name !== 'gzip' && $name !== 'x-gzip') { continue; }
            $quality = 1.0;
            foreach (array_slice($parts, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $parameter, $match)) { $quality = (float) $match[1]; }
            }
            return $quality > 0;
        }
        return false;
    }

    // ------------------------------------------------------------------ URL variants (D2, core-1)

    /**
     * URL variants per language (D2): the default language (site.lang_default) gets the bare URL, English (the data
     * base) always exists and gets ?ui_lang=en when it is not the default, other offered languages only with real
     * translated metadata rows. Do not manufacture missing translations from fallback metadata.
     */
    public static function variants($base, array $rows, $localizedBase = null)
    {
        if (!is_string($base) || strlen($base) > 2000 || !filter_var($base, FILTER_VALIDATE_URL)
            || !preg_match('~^https?://~i', $base) || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $base)) { return []; }
        $localizedBase = $localizedBase === null ? $base : $localizedBase;
        if (!is_string($localizedBase) || !filter_var($localizedBase, FILTER_VALIDATE_URL) || strlen($localizedBase) > 2000
            || !preg_match('~^https?://~i', $localizedBase) || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $localizedBase)) { return []; }
        $default = AvdbLanguage::defaultLocale();
        $codes = AvdbLanguage::offeredCodes();
        $result = [$default => ['url' => $base, 'updated_at' => 0]];
        if ($default !== 'en' && in_array('en', $codes, true)) {
            $result['en'] = ['url' => $localizedBase . (strpos($localizedBase, '?') === false ? '?' : '&') . 'ui_lang=en', 'updated_at' => 0];
        }
        foreach ($rows as $row) {
            $locale = $row['locale'] ?? '';
            if (!in_array($locale, $codes, true)) { continue; }
            $payload = json_decode($row['payload'] ?? '', true);
            if (!is_array($payload)) { continue; }
            $hasMetadata = false;
            foreach (['vod_name','vod_content','vod_blurb'] as $field) {
                if (isset($payload[$field]) && is_string($payload[$field]) && trim(strip_tags($payload[$field])) !== '') { $hasMetadata = true; break; }
            }
            if (!$hasMetadata) { continue; }
            if (isset($result[$locale])) { $result[$locale]['updated_at'] = (int) ($row['updated_at'] ?? 0); continue; }
            $result[$locale] = ['url' => $localizedBase . (strpos($localizedBase, '?') === false ? '?' : '&') . 'ui_lang=' . $locale,
                'updated_at' => (int) ($row['updated_at'] ?? 0)];
        }
        return $result;
    }

    /** Absolute URL of a built path with the checks variants() applies to its base; '' when it must not be listed. */
    private static function bareUrl($path)
    {
        $base = self::absolute($path);
        if (!is_string($base) || $base === '' || strlen($base) > 2000 || !preg_match('~^https?://~i', $base)
            || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $base) || !filter_var($base, FILTER_VALIDATE_URL)) { return ''; }
        return $base;
    }

    /**
     * Absolute default-language URL of a video's watch page (episode 1 of source $sid) or, with $sid 0, its detail page:
     * exactly bareUrl(mac_url_vod_play(...)) / bareUrl(mac_url_vod_detail(...)), built about 10x faster for video files
     * (verify F6 / F3: ThinkPHP's route lookup in url() was 1.2 s of a 1.9 s 9,758-URL file). With dynamic URLs
     * (view < 2) and rewrite.vod_id 0 (id) or 1 (vod_sub) the URL depends only on that id, sid and nid, so it is built once
     * for a sentinel id and copied with the row's id put in; ids outside [A-Za-z0-9_-], static paths, alphaID ids or a
     * language prefix take the normal call. The first rows of every template are checked against the normal call; one
     * difference switches the template off for the rest of the request.
     */
    private static function videoUrl(array $vod, $sid)
    {
        $sid = (int) $sid;
        $slow = function () use ($vod, $sid) {
            return self::bareUrl($sid > 0 ? mac_url_vod_play(self::withType($vod), ['sid' => $sid, 'nid' => 1])
                : mac_url_vod_detail(self::withType($vod)));
        };
        $cfg = $GLOBALS['config'] ?? [];
        $mode = isset($cfg['rewrite']['vod_id']) ? (int) $cfg['rewrite']['vod_id'] : -1;
        $view = (int) ($cfg['view'][$sid > 0 ? 'vod_play' : 'vod_detail'] ?? 0);
        $field = $mode === 1 ? 'vod_sub' : ($mode === 0 ? 'vod_id' : '');
        $value = $field === '' ? '' : (string) ($vod[$field] ?? '');
        if ($field === '' || ($sid > 0 ? $view >= 2 : $view === 2) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value)
            || (isset($_GET['translang']) && $_GET['translang'] !== '' && $_GET['translang'] !== 'en')) {
            return $slow();
        }
        $key = $field . ':' . $sid;
        if (!isset(self::$urlTemplates[$key])) {
            $sentinel = $mode === 1 ? 'jvsitemapq7z' : '987654321987';
            $probe = ['vod_id' => $mode === 0 ? $sentinel : 987654321987, 'vod_sub' => $mode === 1 ? $sentinel : 'jvsitemapq7z',
                'type_id' => (int) ($vod['type_id'] ?? 0), 'vod_time' => (int) ($vod['vod_time'] ?? 0), 'vod_en' => '', 'vod_name' => ''];
            try {
                $url = self::bareUrl($sid > 0 ? mac_url_vod_play(self::withType($probe), ['sid' => $sid, 'nid' => 1])
                    : mac_url_vod_detail(self::withType($probe)));
            } catch (\Throwable $e) {
                $url = '';
            }
            self::$urlTemplates[$key] = ($url !== '' && substr_count($url, $sentinel) === 1) ? [$url, $sentinel, 0] : false;
        }
        $t = self::$urlTemplates[$key];
        if ($t === false) {
            return $slow();
        }
        $fast = str_replace($t[1], $value, $t[0]);
        if ($t[2] < 3) {
            $real = $slow();
            if ($real !== $fast) {
                self::$urlTemplates[$key] = false;
                return $real;
            }
            self::$urlTemplates[$key][2]++;
        }
        return $fast;
    }

    /** The variants a sitemap lists for one page: the default language, plus the language files' languages (E.1). */
    private static function sitemapVariants(array $variants)
    {
        $default = AvdbLanguage::defaultLocale();
        $languages = self::languageFiles();
        $listed = [];
        foreach ($variants as $locale => $variant) {
            if ($locale === $default || in_array($locale, $languages, true)) { $listed[$locale] = $variant; }
        }
        return $listed;
    }

    // ------------------------------------------------------------------ XML

    /**
     * <urlset> of records ['variants' => [locale => ['url', 'updated_at']], 'updated_at' => int, 'video' => array|null].
     * hreflang lives only in the page head (D7): no xhtml:link alternates here, which also keeps the files small.
     */
    public static function urlset(array $records)
    {
        $video = false;
        foreach ($records as $record) { if (!empty($record['video'])) { $video = true; break; } }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="' . self::NS . '"'
            . ($video ? ' xmlns:video="' . self::VIDEO_NS . '"' : '') . '>' . "\n";
        $count = 0;
        foreach ($records as $record) {
            $block = empty($record['video']) ? '' : self::videoXml($record['video']);
            foreach ($record['variants'] as $variant) {
                $modified = self::lastmod(max((int) ($record['updated_at'] ?? 0), (int) ($variant['updated_at'] ?? 0)));
                $xml .= '<url><loc>' . self::xmlEscape($variant['url']) . '</loc>' . ($modified === '' ? '' : '<lastmod>' . $modified . '</lastmod>')
                    . $block . "</url>\n";
                if (++$count > 50000 || strlen($xml) > 49 * 1024 * 1024) { throw new \RuntimeException('Sitemap exceeds protocol limits.'); }
            }
        }
        return $xml . '</urlset>';
    }

    /**
     * One <video:video> block (Google video sitemap): thumbnail_loc, title, description and player_loc are required;
     * without any of them the block is left out. duration 1-28,800 s, publication_date W3C, family_friendly no,
     * up to 32 tags. No retired tags (category, price, gallery_loc, player_loc attributes).
     */
    public static function videoXml(array $video)
    {
        foreach (['thumbnail', 'title', 'description', 'player'] as $key) {
            if (!isset($video[$key]) || !is_string($video[$key]) || trim($video[$key]) === '') { return ''; }
        }
        static $printable = null;
        if ($printable === null) { $printable = implode('', array_map('chr', range(0x20, 0x7e))); }
        $cut = function ($text, $limit) use ($printable) {
            $text = (string) $text;
            // printable ASCII (most tags and many titles): valid UTF-8, no tab/newline, byte cut = character cut
            if (strspn($text, $printable) === strlen($text)) {
                if (strpos($text, '  ') !== false) { $text = preg_replace('/\s+/u', ' ', $text); }
                $text = trim((string) $text);
                return strlen($text) <= $limit ? $text : substr($text, 0, $limit);
            }
            // same result as before: invalid UTF-8 -> '' (the /u replace failed), whitespace runs collapsed (only when there
            // is one), cut only when it can be too long
            if (!preg_match('//u', $text)) { return ''; }
            if (strpbrk($text, "\t\n\x0b\f\r") !== false || strpos($text, '  ') !== false) { $text = preg_replace('/\s+/u', ' ', $text); }
            $text = trim((string) $text);
            if (strlen($text) <= $limit) { return $text; }
            return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
        };
        $xml = '<video:video><video:thumbnail_loc>' . self::xmlEscape($video['thumbnail']) . '</video:thumbnail_loc>'
            . '<video:title>' . self::xmlEscape($cut($video['title'], 200)) . '</video:title>'
            . '<video:description>' . self::xmlEscape($cut($video['description'], 2048)) . '</video:description>'
            . '<video:player_loc>' . self::xmlEscape($video['player']) . '</video:player_loc>';
        $duration = (int) ($video['duration'] ?? 0);
        if ($duration >= 1 && $duration <= 28800) { $xml .= '<video:duration>' . $duration . '</video:duration>'; }
        $published = (string) ($video['published'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2}))?$/D', $published)) {
            $xml .= '<video:publication_date>' . $published . '</video:publication_date>';
        }
        $xml .= '<video:family_friendly>no</video:family_friendly>';
        $tags = 0;
        foreach ((array) ($video['tags'] ?? []) as $tag) {
            $tag = $cut($tag, 100);
            if ($tag === '' || ++$tags > self::VIDEO_TAGS) { continue; }
            $xml .= '<video:tag>' . self::xmlEscape($tag) . '</video:tag>';
        }
        return $xml . '</video:video>';
    }

    /** <sitemapindex> of child URLs: strings or [url, lastmod timestamp] pairs (every child gets <lastmod> when known). */
    public static function indexXml(array $urls)
    {
        if (count($urls) > 50000) { throw new \RuntimeException('Sitemap index exceeds protocol limits.'); }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="' . self::NS . '">' . "\n";
        foreach ($urls as $url) {
            $modified = is_array($url) ? self::lastmod($url[1] ?? 0) : '';
            $loc = is_array($url) ? (string) $url[0] : (string) $url;
            $xml .= '<sitemap><loc>' . self::xmlEscape($loc) . '</loc>' . ($modified === '' ? '' : '<lastmod>' . $modified . '</lastmod>') . "</sitemap>\n";
            if (strlen($xml) > 49 * 1024 * 1024) { throw new \RuntimeException('Sitemap index exceeds protocol limits.'); }
        }
        return $xml . '</sitemapindex>';
    }

    // ------------------------------------------------------------------ absolute URLs

    public static function absolute($path)
    {
        // verify r2 R2-11 / R2-12: the host part is validated once per (host, scheme) and request; the path gets exactly the
        // checks of UrlSettings::canonicalUrl() (an index lists 700+ children, a video file 10,000 thumbnails)
        $host = $GLOBALS['config']['site']['site_url'] ?? '';
        $scheme = self::scheme();
        if (!is_string($host) || !is_string($path)) { return ''; }
        $memo = $scheme . '|' . $host;
        if (!array_key_exists($memo, self::$absBase)) {
            $root = UrlSettings::canonicalUrl($host, '/', $scheme);
            self::$absBase[$memo] = $root === '' ? '' : substr($root, 0, -1);
            if (count(self::$absBase) > 16) { self::$absBase = [$memo => self::$absBase[$memo]]; }
        }
        $base = self::$absBase[$memo];
        if ($base === '' || $path === '' || $path[0] !== '/' || substr($path, 0, 2) === '//' || preg_match('/[\x00-\x20\x7f\\\\<>"\']/', $path)) { return ''; }
        return $base . $path;
    }

    /**
     * Scheme for canonical, sitemap, feed and robots URLs: the configured one ("http"/"https"),
     * otherwise ("auto") https whenever the request arrived over TLS, directly or through a proxy/CDN.
     */
    public static function scheme()
    {
        $preference = $GLOBALS['config']['rewrite']['canonical_scheme'] ?? 'auto';
        if (in_array($preference, ['http', 'https'], true)) {
            return $preference . '://';
        }
        return self::requestIsHttps() || ($GLOBALS['http_type'] ?? '') === 'https://' ? 'https://' : 'http://';
    }

    public static function requestIsHttps(?array $server = null)
    {
        $server = $server ?? $_SERVER;
        $value = function ($name) use ($server) {
            return isset($server[$name]) && is_scalar($server[$name]) ? strtolower(trim((string)$server[$name])) : '';
        };
        $https = $value('HTTPS');
        if ($https !== '' && $https !== 'off' && $https !== '0') { return true; }
        if ($value('REQUEST_SCHEME') === 'https' || $value('SERVER_PORT') === '443') { return true; }
        // only a request with a forwarded scheme header needs the trusted-peer check; its answer is remembered for this
        // request (verify r2 R2-11: scheme() runs for every child of the index and every thumbnail of a video file, and the
        // check reads the proxy settings and the Cloudflare ranges each time: 90 ms of a 719-child index rebuild)
        $headers = '';
        foreach (['HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SSL', 'HTTP_FRONT_END_HTTPS', 'HTTP_X_FORWARDED_SCHEME', 'HTTP_CF_VISITOR', 'HTTP_FORWARDED'] as $h) {
            $headers .= '|' . $value($h);
        }
        if (trim($headers, '|') === '') { return false; }
        $memo = $value('REMOTE_ADDR') . $headers;
        if (isset(self::$httpsMemo[$memo])) { return self::$httpsMemo[$memo]; }
        $result = false;
        // forwarded scheme headers only from a Cloudflare peer or a trusted proxy (edge-cache poisoning, se-R12)
        if (self::forwardedTrusted($server)) {
            $forwarded = explode(',', $value('HTTP_X_FORWARDED_PROTO'));
            $result = trim($forwarded[0]) === 'https'
                || $value('HTTP_X_FORWARDED_SSL') === 'on' || $value('HTTP_FRONT_END_HTTPS') === 'on' || $value('HTTP_X_FORWARDED_SCHEME') === 'https'
                || strpos($value('HTTP_CF_VISITOR'), '"https"') !== false
                || (bool)preg_match('/(?:^|[;,\s])proto=https(?:$|[;,\s])/', $value('HTTP_FORWARDED'));
        }
        if (count(self::$httpsMemo) >= 32) { self::$httpsMemo = []; }
        return self::$httpsMemo[$memo] = $result;
    }

    /** The peer may set the forwarded scheme headers: a Cloudflare edge or a configured trusted proxy (L0, se-R12). */
    private static function forwardedTrusted(array $server)
    {
        try {
            if (!class_exists(PublicRequestGuard::class)) {
                require_once __DIR__ . '/PublicRequestGuard.php';
            }
            $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3) . '/';
            return PublicRequestGuard::trustedPeer($root, $server);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function indexUrl()
    {
        return self::feedUrl('index');
    }

    private static function feedUrl($action, $bucket = null, $lang = null)
    {
        $root = defined('MAC_PATH') ? MAC_PATH : '/';
        // Child feeds live at the installation root, so every detail URL is in scope.
        // Retain the existing path endpoints as aliases for previously submitted URLs.
        $path = $root . 'sitemap.xml';
        if (empty($GLOBALS['config']['rewrite']['status'])) {
            $separator = $GLOBALS['config']['app']['pathinfo_depr'] ?? '/';
            if (!in_array($separator, ['/', '-', '_'], true)) { $separator = '/'; }
            $path = $root . 'index.php?s=' . rawurlencode('sitemap' . $separator . 'index');
        }
        // The absolute base is built once per request (the index lists 1,400+ children at 7 M videos); the feed parameters
        // are plain ASCII appended to it, exactly as before.
        $memo = $path . '|' . self::scheme() . '|' . ($GLOBALS['config']['site']['site_url'] ?? '');
        if (!isset(self::$feedBase[$memo])) { self::$feedBase[$memo] = self::absolute($path); }
        $url = self::$feedBase[$memo];
        if ($url === '') { throw new \RuntimeException('Configure a valid site domain before using sitemaps.'); }
        if ($action !== 'index') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'feed=' . rawurlencode($action);
            if ($bucket !== null) { $url .= '&bucket=' . (int) $bucket; }
            if ($lang !== null) { $url .= '&lang=' . rawurlencode($lang); }
        }
        return $url;
    }

    // ------------------------------------------------------------------ cache

    private static function cacheKey($part)
    {
        $legal = $part === 'pages' ? [SeoSettings::legalPages(), class_exists(__NAMESPACE__ . '\\LegalPages'),
            array_map(function ($file) { return [basename($file), (int) @filemtime($file)]; }, SeoSettings::dataFiles())] : [];
        $video = strpos($part, 'videos:') === 0 ? [(int) ($GLOBALS['config']['app']['encrypt'] ?? 0), (int) ($GLOBALS['config']['user']['trysee'] ?? 0),
            config('vodplayer'), defined('MAC_PLAYER_SORT') ? MAC_PLAYER_SORT : ''] : [];
        return 'avdb_sitemap_v19_' . sha1(json_encode([$part, config('database.database'), config('database.prefix'),
            $GLOBALS['config']['site']['site_url'] ?? '', $GLOBALS['config']['site']['install_dir'] ?? '/', self::scheme(),
            $GLOBALS['config']['view'] ?? [], $GLOBALS['config']['path'] ?? [], $GLOBALS['config']['rewrite'] ?? [],
            $GLOBALS['config']['user']['status'] ?? 0, $GLOBALS['config']['user']['art_points_type'] ?? 0, $GLOBALS['config']['app']['copyright_status'] ?? 0,
            $GLOBALS['config']['app']['pathinfo_depr'] ?? '/', [AvdbLanguage::defaultLocale(), AvdbLanguage::offeredCodes(), AvdbLanguage::indexedCodes()],
            [self::perFile(), SeoSettings::bool('seo.sitemap_video'), SeoSettings::bool('seo.sitemap_langs'), self::hidden()], $legal, $video,
            $part === 'pages' ? [self::browsingLocales(), self::pageTemplateAvailability(), self::emptyModules()] : [], self::publicTypes(), self::publicTypes(1), self::publicTypes(2, 2), self::publicTypes(2, 8), self::publicTypes(1, 2), self::publicTypes(1, 8), self::categoryMetadata()]));
    }

    /**
     * A cached file as ['gz' => gzip bytes] (or ['xml' => text] without zlib). Only the gzip string is stored: the
     * plain text is decoded on demand for the few clients without gzip, so 1,785 files of 3-6 MB do not fill the cache.
     */
    private static function cachedPayload($part, callable $make, $seconds, $stable = null, ?callable $lite = null)
    {
        $key = self::cacheKey($part);
        $payload = Cache::get($key);
        if (is_array($payload) && ((isset($payload['gz']) && is_string($payload['gz']) && $payload['gz'] !== '')
            || (isset($payload['xml']) && is_string($payload['xml']) && $payload['xml'] !== ''))) {
            // a lite build (site-r3 N20) answers requests until prebuild() replaces it; prebuild itself builds the full file
            if (empty($payload['lite']) || !self::$building) {
                return empty($payload['lite']) ? $payload : array_diff_key($payload, ['lite' => 1]);
            }
        }
        // owner decision 2026-10-17 (search engines are never turned away): the last good copy of this file (same feed,
        // bucket and language, at most LAST_GOOD_SECONDS old) answers when a rebuild fails (statement time limit under
        // load, a busy database) instead of the endpoint's 503
        $last = self::lastGoodFile($stable === null ? $part : $stable);
        $video = $stable !== null && strpos((string)$stable, 'videos:') === 0;
        // verify r3 N20 (b): a video file that is not in the cache answers with its last good copy (at most LAST_GOOD_SECONDS
        // old) instead of being built in the crawler's request. A copy of the same bucket version (an expired or evicted cache
        // entry) is put back into the cache; a copy of an older version (the bucket changed) is answered as it is and the file
        // is remembered for prebuild(): housekeeping builds it next (cron, the run after a finished response; on a host
        // without cron and finish-request one visitor slice per claim). Built inline only when no copy exists, and always
        // inside prebuild() itself.
        if ($video && !self::$building && $last !== '') {
            $copy = self::lastGoodCopy($key, $last);
            if ($copy !== null) {
                if ($copy['same']) {
                    Cache::set($key, ['gz' => $copy['gz']], $seconds);
                } else {
                    self::wantFile((string)$stable);
                }
                return ['gz' => $copy['gz']];
            }
        }
        // site-r3 N20: a never-built video file (no stored copy, no last good copy) is answered with its lite build at once
        // and the full file is wanted (prebuild); the lite bytes never become a last good copy or a build record
        if ($video && !self::$building && $lite !== null && function_exists('gzencode')) {
            try {
                $xml = $lite();
                $gz = gzencode($xml, self::GZIP_LEVEL);
                if (is_string($gz) && $gz !== '') {
                    Cache::set($key, ['gz' => $gz, 'lite' => 1], self::LITE_FILE_SECONDS);
                    self::wantFile((string)$stable);
                    // no full copy exists any more: a stale build record must not make prebuild() take the lite entry in the
                    // cache for a current file
                    self::forgetRecord((string)$stable);
                    return ['gz' => $gz];
                }
            } catch (\Throwable $e) {
                // the full build below
            }
        }
        $t0 = microtime(true);
        try {
            $xml = $make();
        } catch (\Throwable $e) {
            $gzOld = $last !== '' && is_file($last) && (int)@filemtime($last) > time() - self::LAST_GOOD_SECONDS ? @file_get_contents($last) : false;
            if (is_string($gzOld) && $gzOld !== '') {
                return ['gz' => $gzOld];
            }
            throw $e;
        }
        $gz = function_exists('gzencode') ? gzencode($xml, self::GZIP_LEVEL) : false;
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $payload = is_string($gz) && $gz !== '' ? ['gz' => $gz] : ['xml' => $xml];
        // No cache tag (D-L12: tag index rewrites cost 17-86 ms at 50-200 k keys); keys carry what changes the content.
        Cache::set($key, $payload, $seconds);
        if ($last !== '' && isset($payload['gz'])) {
            // the version the copy belongs to (key signature) beside it: lastGoodCopy() tells "same" from "older" with one
            // small read, without the whole sitemap_files.json
            if (ScaleTier::writeAtomic($last, $payload['gz']) !== false && $video) {
                ScaleTier::writeAtomic($last . '.k', substr(sha1((string)$key), 0, 16));
            }
        }
        if ($video) {
            self::recordFile((string)$stable, $key, $ms);
        }
        return $payload;
    }

    /**
     * The last good copy of a video file (verify r3 N20): ['gz' => bytes, 'same' => true when it was built for $key, i.e. the
     * bucket did not change since] or null (no copy, older than LAST_GOOD_SECONDS, unreadable).
     */
    private static function lastGoodCopy($key, $last)
    {
        clearstatcache(true, $last);
        $m = is_file($last) ? (int)@filemtime($last) : 0;
        if ($m <= 0 || $m <= time() - self::LAST_GOOD_SECONDS) { return null; }
        $gz = @file_get_contents($last);
        if (!is_string($gz) || strlen($gz) < 18) { return null; }
        $sig = @file_get_contents($last . '.k');
        return ['gz' => $gz, 'same' => is_string($sig) && $sig === substr(sha1((string)$key), 0, 16)];
    }

    /** true when the last good copy of a video file exists for exactly this key (prebuild(): an expired copy needs no build). */
    private static function lastGoodSame($stable, $key)
    {
        $last = self::lastGoodFile($stable);
        if ($last === '') { return false; }
        clearstatcache(true, $last);
        if (!is_file($last) || (int)@filemtime($last) <= time() - self::LAST_GOOD_SECONDS) { return false; }
        $sig = @file_get_contents($last . '.k');
        return is_string($sig) && $sig === substr(sha1((string)$key), 0, 16);
    }

    /**
     * runtime/scale/sitemap_files.json: [stable file name => ['k' => key signature, 'at' => built, 'ms' => build time]]
     * (prebuild(): what is current, and how long a file takes, so a visitor slice starts only a build that fits).
     */
    private static function recordFile($stable, $key, $ms = 0, $at = null)
    {
        self::withRecords(function () use ($stable, $key, $ms, $at) {
            $file = ScaleTier::runtime('scale') . 'sitemap_files.json';
            $files = ScaleTier::readJson($file);
            $files = is_array($files) ? $files : [];
            unset($files[$stable]);
            $files[$stable] = ['k' => substr(sha1((string)$key), 0, 16), 'at' => $at === null ? time() : (int)$at, 'ms' => max(0, (int)$ms)];
            if (count($files) > 100000) { $files = array_slice($files, -50000, null, true); }
            ScaleTier::writeAtomic($file, json_encode($files));
        });
    }

    /**
     * Runs $fn (a read-modify-write of sitemap_files.json or sitemap_want.json) under runtime/scale/sitemap_records.lock, so a
     * crawler request and housekeeping writing at the same moment do not drop each other's entries (site-r3 N20). Waits at
     * most 2 s for the lock, then runs anyway (a lost record is repaired: prebuild() adopts a cached file without record).
     * Never throws.
     */
    private static function withRecords(callable $fn)
    {
        $lock = null;
        try {
            $lock = @fopen(ScaleTier::runtime('scale') . 'sitemap_records.lock', 'c');
            if ($lock) {
                $until = microtime(true) + 2.0;
                while (!@flock($lock, LOCK_EX | LOCK_NB)) {
                    if (microtime(true) >= $until) {
                        @fclose($lock);
                        $lock = null;
                        break;
                    }
                    usleep(10000);
                }
            }
            $fn();
        } catch (\Throwable $e) {
        } finally {
            if ($lock) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /**
     * prebuild() (site-r3 N20): a full copy of $key in the cache (not a lite build) gets its build record and its last good
     * copy without a build. Its time is the cache file's (file cache), else old enough that a default-language file is built
     * again at the next prebuild() (PREBUILD_AGE). false when the cache holds no full copy of $key. Never throws.
     */
    private static function adoptCached($stable, $key, $rec)
    {
        try {
            $path = self::cacheFilePath($key);
            if ($path !== null && !is_file($path)) { return false; }
            $payload = Cache::get($key);
            if (!is_array($payload) || !empty($payload['lite'])) { return false; }
            $gz = isset($payload['gz']) && is_string($payload['gz']) ? $payload['gz'] : '';
            if ($gz === '' && !(isset($payload['xml']) && is_string($payload['xml']) && $payload['xml'] !== '')) { return false; }
            $at = $path !== null ? (int)@filemtime($path) : 0;
            if ($at <= 0 || $at > time()) { $at = time() - self::PREBUILD_AGE; }
            self::recordFile((string)$stable, $key, is_array($rec) ? (int)($rec['ms'] ?? 0) : 0, $at);
            if ($gz !== '' && !self::lastGoodSame($stable, $key)) {
                $last = self::lastGoodFile($stable);
                if ($last !== '' && ScaleTier::writeAtomic($last, $gz) !== false) {
                    ScaleTier::writeAtomic($last . '.k', substr(sha1((string)$key), 0, 16));
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Drop the build record of a file (site-r3 N20: its only copy is a lite build; prebuild() treats it as never built). */
    private static function forgetRecord($stable)
    {
        self::withRecords(function () use ($stable) {
            $file = ScaleTier::runtime('scale') . 'sitemap_files.json';
            $files = ScaleTier::readJson($file);
            if (!is_array($files) || !isset($files[$stable])) { return; }
            unset($files[$stable]);
            ScaleTier::writeAtomic($file, json_encode((object)$files));
        });
    }

    /** [stable file name => record] of recordFile() ([] when none). */
    private static function fileRecords()
    {
        try {
            $files = ScaleTier::readJson(ScaleTier::runtime('scale') . 'sitemap_files.json');
            return is_array($files) ? $files : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Pure (site-r3 N20): the median measured build time in ms of the recorded default-language files ('default') and of the
     * language files ('lang'), 0 when none of that kind was measured (prebuild()'s guess for a file never built).
     */
    public static function medianBuildMs(array $records)
    {
        $ms = ['default' => [], 'lang' => []];
        foreach ($records as $stable => $rec) {
            if (!is_array($rec) || (int)($rec['ms'] ?? 0) <= 0 || !preg_match('/^videos:[0-9]+:(.*)$/D', (string)$stable, $m)) { continue; }
            $ms[$m[1] === '' ? 'default' : 'lang'][] = (int)$rec['ms'];
        }
        $out = [];
        foreach ($ms as $kind => $list) {
            sort($list);
            $c = count($list);
            $out[$kind] = $c === 0 ? 0 : ($c % 2 ? $list[intdiv($c, 2)] : (int)round(($list[$c / 2 - 1] + $list[$c / 2]) / 2));
        }
        return $out;
    }

    /** Remember a video file answered from an older copy (runtime/scale/sitemap_want.json, at most WANT_MAX names). */
    private static function wantFile($stable)
    {
        self::withRecords(function () use ($stable) {
            $file = ScaleTier::runtime('scale') . 'sitemap_want.json';
            $want = ScaleTier::readJson($file);
            $want = is_array($want) ? $want : [];
            if (isset($want[$stable])) { return; }
            $want[$stable] = time();
            if (count($want) > self::WANT_MAX) { $want = array_slice($want, -self::WANT_MAX, null, true); }
            ScaleTier::writeAtomic($file, json_encode($want));
        });
    }

    /** [stable file name => first asked] of wantFile() ([] when none). */
    public static function wantedFiles()
    {
        try {
            $want = ScaleTier::readJson(ScaleTier::runtime('scale') . 'sitemap_want.json');
            return is_array($want) ? $want : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Forget handled names of the wanted list (built, current again, or no longer in the index). */
    private static function dropWanted(array $names)
    {
        self::withRecords(function () use ($names) {
            $file = ScaleTier::runtime('scale') . 'sitemap_want.json';
            $want = ScaleTier::readJson($file);
            if (!is_array($want) || !$want) { return; }
            foreach ($names as $n) { unset($want[$n]); }
            ScaleTier::writeAtomic($file, json_encode((object)$want));
        });
    }

    /**
     * The cache-key part of one video file: the bucket's newest change and count; a language file also carries the newest
     * translation change of its bucket (languageTable(): a new or edited translation makes a new language file, the same
     * date the index prints as its lastmod; verify r3 N20).
     */
    private static function videoPart($bucket, $lang, array $table, ?array $langTable = null)
    {
        $part = 'videos:' . $bucket . ':' . (string) $lang . ':' . $table[$bucket]['modified'] . ':' . $table[$bucket]['total'];
        if ($lang !== null && $lang !== 'en') {
            if ($langTable === null) {
                if (self::$langMemo === null) { self::$langMemo = self::languageTable(); }
                $langTable = self::$langMemo;
            }
            $part .= ':' . (int) ($langTable[$lang][$bucket] ?? 0);
        }
        return $part;
    }

    /** The stored copy of $key exists: the file cache's own file (ThinkPHP File driver layout); other stores: assumed. */
    private static function cacheFilePresent($key)
    {
        $path = self::cacheFilePath($key);
        return $path === null ? true : is_file($path);
    }

    /** The file of $key in the file cache (ThinkPHP File driver layout); null for another store or when it cannot be told. */
    private static function cacheFilePath($key)
    {
        try {
            if (strtolower((string)config('cache.type')) !== 'file') { return null; }
            $path = (string)config('cache.path');
            if ($path === '') { $path = defined('CACHE_PATH') ? CACHE_PATH : (defined('RUNTIME_PATH') ? RUNTIME_PATH . 'cache' . DIRECTORY_SEPARATOR : ''); }
            if ($path === '') { return null; }
            $name = md5((string)$key);
            $subdir = config('cache.cache_subdir');
            if ($subdir === null || $subdir) { $name = substr($name, 0, 2) . DIRECTORY_SEPARATOR . substr($name, 2); }
            $prefix = (string)config('cache.prefix');
            if ($prefix !== '') { $name = $prefix . DIRECTORY_SEPARATOR . $name; }
            return rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $name . '.php';
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The scheme the search engines fetch the index with (a web request of the index; canonical_scheme 'auto'), for the
     * command-line prebuild (tools/housekeeping.php): runtime/scale/sitemap_scheme.json, written only when it changes.
     */
    private static function rememberScheme()
    {
        if (PHP_SAPI === 'cli') { return; }
        try {
            $scheme = self::scheme() === 'https://' ? 'https' : 'http';
            $file = ScaleTier::runtime('scale') . 'sitemap_scheme.json';
            $seen = ScaleTier::readJson($file);
            if (!is_array($seen) || ($seen['scheme'] ?? '') !== $scheme) {
                ScaleTier::writeAtomic($file, json_encode(['scheme' => $scheme, 'at' => time()]));
            }
        } catch (\Throwable $e) {
        }
    }

    /** Video files can be built here: the framework with the front-end URL context is loaded (ENTRANCE index). */
    public static function canPrebuild()
    {
        // inside the framework (THINK_VERSION) the Db / Cache facades may not be loaded yet: autoload them there, never outside
        $boot = defined('THINK_VERSION');
        return defined('ENTRANCE') && ENTRANCE === 'index' && class_exists('\think\Db', $boot) && class_exists('\think\Cache', $boot)
            && function_exists('mac_url_vod_detail') && function_exists('config') && isset($GLOBALS['config']['site']) && is_array($GLOBALS['config']['site']);
    }

    /**
     * G9 (verify r2 R2-12, F3; verify r3 N20): build and store what a crawler would otherwise wait for, after the bucket
     * refresh: the index, then the video files in this order until $deadline (a started file is finished):
     *   1. files a crawler was answered from an older copy (sitemap_want.json, cachedPayload()),
     *   2. the default-language file of every bucket whose stored copy is missing, belongs to an older version of the bucket
     *      (its newest time and count are part of the key) or is older than PREBUILD_AGE (built again before its 24 h cache
     *      time ends), newest buckets first,
     *   3. the language files the index lists (languageFiles() x buckets; a non-English file only where the bucket has
     *      translations) whose copy is missing or belongs to an older version (bucket or its newest translation changed),
     *      newest buckets first. An expired copy of the same version needs no build: cachedPayload() puts it back.
     * Each file is built by payload(), exactly as a sitemap request builds it, so the stored bytes are the ones the request
     * would have produced. Needs the framework with the front-end URL context (canPrebuild()): a page view that went through
     * it (the run after a finished FPM / LiteSpeed response, or a visitor slice) or tools/housekeeping.php (front-end boot).
     * The framework connection gets this run's deadline (DbTimeout: a web request's own database deadline is spent by now,
     * and every statement ends by $deadline + 2 s). $opt: 'max' files to build, 'wanted_only' (only list 1), 'visitor'
     * (site-r3 N20, a visitor slice on a host without cron / finish-request: list 1, then the files that were never built
     * (no build record), newest buckets first, default-language files before language files), 'guard' (start a file only
     * when its estimated build time x 1.3 fits into the time left: its last measured time, else the median of the measured
     * files of its kind, else BUILD_GUESS), 'no_index'. Single flight (runtime/scale/sitemap_prebuild.lock).
     * Returns ['state', 'built', 'lang_built', 'wanted', 'never_built' (files without a record, visitor mode), 'fresh',
     * 'left', 'ms'].
     */
    public static function prebuild($deadline, array $opt = [])
    {
        $t0 = microtime(true);
        $out = ['state' => 'done', 'built' => 0, 'lang_built' => 0, 'wanted' => 0, 'fresh' => 0, 'left' => 0, 'ms' => 0];
        if (!self::canPrebuild()) { return ['state' => 'no_framework'] + $out; }
        $max = isset($opt['max']) ? max(0, (int)$opt['max']) : PHP_INT_MAX;
        $visitor = !empty($opt['visitor']);
        $wantedOnly = !empty($opt['wanted_only']) && !$visitor;
        $guard = !empty($opt['guard']);
        if ($wantedOnly && !self::wantedFiles()) { return ['state' => 'nothing_wanted'] + $out; }
        $lock = @fopen(ScaleTier::runtime('scale') . 'sitemap_prebuild.lock', 'c');
        if (!$lock) { return ['state' => 'error'] + $out; }
        if (!@flock($lock, LOCK_EX | LOCK_NB)) { @fclose($lock); return ['state' => 'busy'] + $out; }
        $timeout = __NAMESPACE__ . '\DbTimeout';
        $hasTimeout = class_exists($timeout, false);
        $prevLeft = $hasTimeout ? $timeout::remaining() : null;
        self::$building = true;
        try {
            if ($hasTimeout) { $timeout::setDeadline((float)$deadline + 2.0, null); }
            if (self::hidden()) { return ['state' => 'hidden'] + $out; }
            self::$bucketMemo = null;
            self::$langMemo = null;
            if (!$wantedOnly && !$visitor && empty($opt['no_index'])) { self::withoutHide(function () { return self::payload('index'); }); }
            $table = self::bucketTable();
            $langTable = self::languageTable();
            self::$langMemo = $langTable;
            $languages = self::languageFiles();
            $files = self::fileRecords();
            $want = self::wantedFiles();
            $now = time();
            $todo = [];
            foreach (array_keys($want) as $stable) { $todo[(string)$stable] = true; }
            if (!$wantedOnly) {
                $newest = array_reverse(array_keys($table));
                // a visitor slice (site-r3 N20): only files that were never built (no record): a crawler would otherwise
                // wait for their first build; files built once are answered from their copy and rebuilt when wanted
                $never = function ($stable) use ($visitor, $files) { return !$visitor || !isset($files[$stable]); };
                foreach ($newest as $bucket) {
                    if ($never('videos:' . $bucket . ':')) { $todo['videos:' . $bucket . ':'] = true; }
                }
                foreach ($newest as $bucket) {
                    foreach ($languages as $code) {
                        if ($code !== 'en' && !isset($langTable[$code][$bucket])) { continue; }
                        if ($never('videos:' . $bucket . ':' . $code)) { $todo['videos:' . $bucket . ':' . $code] = true; }
                    }
                }
                if ($visitor) { $out['never_built'] = count($todo) - count(array_intersect_key($todo, $want)); }
            }
            if ($visitor && !$todo) {
                return ['state' => 'nothing'] + $out;
            }
            $median = self::medianBuildMs($files);
            $handled = [];
            foreach (array_keys($todo) as $stable) {
                if (!preg_match('/^videos:([0-9]{1,9}):([a-z]{2,3}(?:-[a-z0-9]{2,8})?)?$/D', $stable, $m)) { $handled[] = $stable; continue; }
                $bucket = (int)$m[1];
                $lang = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
                if (!isset($table[$bucket]) || ($lang !== null && (!in_array($lang, $languages, true) || ($lang !== 'en' && !isset($langTable[$lang][$bucket]))))) {
                    $handled[] = $stable;   // no longer in the index
                    continue;
                }
                $key = self::cacheKey(self::videoPart($bucket, $lang, $table, $langTable));
                $rec = $files[$stable] ?? null;
                $same = is_array($rec) && ($rec['k'] ?? '') === substr(sha1($key), 0, 16);
                $aged = $lang === null && $now - (int)($rec['at'] ?? 0) >= self::PREBUILD_AGE;
                if ($same && !$aged && (self::cacheFilePresent($key) || self::lastGoodSame($stable, $key))) {
                    $out['fresh']++;
                    $handled[] = $stable;
                    continue;
                }
                $left = (float)$deadline - microtime(true);
                // a current full copy in the cache without its build record (the record lost to a concurrent write, a copy put
                // back from its last good copy) gets its record instead of a build: one cache read (site-r3 N20: a visitor
                // slice would otherwise take the same cached file for "never built" on every slice)
                if (!$same && $left > ($guard ? 0.2 : 0.0) && self::adoptCached($stable, $key, $rec)) {
                    $out['fresh']++;
                    $out['adopted'] = (int)($out['adopted'] ?? 0) + 1;
                    $handled[] = $stable;
                    continue;
                }
                $guess = is_array($rec) && (int)($rec['ms'] ?? 0) > 0 ? (int)$rec['ms'] / 1000
                    : (($median[$lang === null ? 'default' : 'lang'] ?? 0) > 0 ? $median[$lang === null ? 'default' : 'lang'] / 1000 : self::BUILD_GUESS);
                $need = $guard ? max(0.2, $guess * 1.3) : 0.0;
                if ($left <= $need || $out['built'] >= $max) {
                    $out['left']++;
                    continue;
                }
                if ($same) { Cache::rm($key); }   // an aging copy: built again now, not by the next crawler request
                self::withoutHide(function () use ($bucket, $lang) { return self::payload('videos', $bucket, $lang); });
                $out['built']++;
                if ($lang !== null) { $out['lang_built']++; }
                if (isset($want[$stable])) { $out['wanted']++; }
                $handled[] = $stable;
            }
            if ($want && $handled) { self::dropWanted(array_values(array_intersect($handled, array_map('strval', array_keys($want))))); }
        } catch (\Throwable $e) {
            $out['state'] = 'error';
            $out['error'] = substr((string)preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
        } finally {
            self::$building = false;
            if ($hasTimeout) { $timeout::setDeadline($prevLeft === null ? null : microtime(true) + $prevLeft, null); }
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
        $out['ms'] = (int)round((microtime(true) - $t0) * 1000);
        return $out;
    }

    /** runtime/scale/sitemap_last/<sha1>.gz for a stable file name ('' when the folder cannot be made). */
    private static function lastGoodFile($stable)
    {
        try {
            $dir = ScaleTier::runtime('scale/sitemap_last');
            return $dir . sha1(self::cacheKey((string)$stable)) . '.gz';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Plain XML of a payload. */
    public static function xmlOf(array $payload)
    {
        if (isset($payload['xml'])) { return (string) $payload['xml']; }
        $xml = function_exists('gzdecode') ? gzdecode((string) ($payload['gz'] ?? '')) : false;
        if (!is_string($xml)) { throw new \RuntimeException('Sitemap cache is unreadable.'); }
        return $xml;
    }

    // ------------------------------------------------------------------ catalog helpers

    /** Performer, collection and article modules without published rows (their listing pages are noindex). */
    private static function emptyModules()
    {
        $empty = [];
        foreach (['actor', 'topic', 'art'] as $section) {
            if (TemplateSeo::moduleEmpty($section)) { $empty[] = $section; }
        }
        return $empty;
    }

    private static function categoryMetadata()
    {
        if (self::$categoryMetadata === null) {
            self::$categoryMetadata = [];
            foreach (Db::name('type')->field('type_id,type_en,type_pid,type_status,type_mid')->order('type_id')->select() as $row) {
                self::$categoryMetadata[(int) $row['type_id']] = $row;
            }
        }
        return self::$categoryMetadata;
    }

    private static function publicTypes($permission = 2, $mid = 1)
    {
        $key = $mid . ':' . $permission;
        if (isset(self::$types[$key])) { return self::$types[$key]; }
        $types = [];
        foreach (self::categoryMetadata() as $id => $type) {
            if ((int) $type['type_mid'] === (int) $mid && (int) $type['type_status'] === 1) { $types[] = $id; }
        }
        if (!empty($GLOBALS['config']['user']['status'])) {
            $group = Db::name('group')->where('group_id', 1)->find();
            $members = explode(',', trim($group['group_type'] ?? '', ','));
            $permissions = json_decode($group['group_popedom'] ?? '', true);
            $types = array_values(array_filter($types, function ($id) use ($members, $permissions, $permission) {
                return in_array((string) $id, $members, true) && !empty($permissions[$id][$permission]);
            }));
        }
        return self::$types[$key] = $types;
    }

    private static function published()
    {
        $query = Db::name('vod')->where('vod_status', 1)->where('vod_recycle_time', 0)
            ->where('type_id', 'in', self::publicTypes() ?: [0])->where('vod_pwd', '')->where('vod_jumpurl', '');
        if ((int) ($GLOBALS['config']['app']['copyright_status'] ?? 0) === 2) { $query->where('vod_copyright', 0); }
        return $query;
    }

    /** Native topic pages have no category/password/paywall columns. */
    private static function publishedEntity($part)
    {
        if ($part === 'topics') { return Db::name('topic')->where('topic_status', 1); }
        if ($part === 'actors') {
            return Db::name('actor')->where('actor_status', 1)->where('actor_jumpurl', '')
                ->where('type_id', 'in', self::publicTypes(2, 8) ?: [0]);
        }
        if ($part === 'articles') {
            $query = Db::name('art')->where('art_status', 1)->where('art_pwd', '')->where('art_jumpurl', '')
                ->where('type_id', 'in', self::publicTypes(2, 2) ?: [0]);
            if (!empty($GLOBALS['config']['user']['status'])) {
                $price = (string) ($GLOBALS['config']['user']['art_points_type'] ?? '0') === '1' ? 'art_points' : 'art_points_detail';
                $query->where($price, 0);
            }
            return $query;
        }
        throw new \InvalidArgumentException('Invalid public entity feed.');
    }

    private static function entityKind($part)
    {
        $kinds = ['actors'=>'actor', 'topics'=>'topic', 'articles'=>'art'];
        if (!is_string($part) || !isset($kinds[$part])) { throw new \InvalidArgumentException('Invalid public entity feed.'); }
        return $kinds[$part];
    }

    private static function entityVariants($kind, array $row)
    {
        if ($kind !== 'topic') {
            $metadata = self::categoryMetadata();
            $row['type'] = $metadata[(int) ($row['type_id'] ?? 0)] ?? ['type_id'=>0,'type_en'=>'','type_pid'=>0];
            $row['type_1'] = $metadata[(int) $row['type']['type_pid']] ?? ['type_id'=>0,'type_en'=>''];
        }
        $had = array_key_exists('translang', $_GET); $language = $_GET['translang'] ?? null;
        unset($_GET['translang']);
        try {
            if ($kind === 'actor') { $path = mac_url_actor_detail($row); }
            elseif ($kind === 'topic') { $path = mac_url_topic_detail($row); }
            else { $path = mac_url_art_detail($row, ['page'=>1]); }
            // Match TemplateSeo's canonical policy: these entities have no translated metadata.
            return self::variants(self::absolute($path), []);
        } finally { if ($had) { $_GET['translang'] = $language; } }
    }

    // ------------------------------------------------------------------ lastmod tables (E.1 "Bucket lastmod")

    /**
     * [bucket => ['modified' => newest vod_time, 'total' => videos]] from the stored {pre}sitemap_bucket table (D-L9), read
     * once per request (one primary-key scan of about 1,400 rows at 7 M videos). Throws (the endpoint answers 503 with
     * Retry-After 300) when the table is missing or not built for the current file size yet; a tier-1 site builds it inline.
     */
    public static function bucketTable()
    {
        if (self::$bucketMemo !== null) { return self::$bucketMemo; }
        $cfg = self::bucketConfig();
        $state = self::bucketState();
        if (empty($state['built']) || ($state['sig'] ?? '') !== $cfg['sig']) {
            // inline build only on a small site, checked against the real table (MAX(vod_id): one primary-key dive), never
            // against the tier snapshot alone: a wiped or missing snapshot says tier 1 / 0 rows on any site (verify F5: a
            // 7.1 M database built buckets inline for 10 s per visitor request, then answered 503)
            $tier = ScaleTier::get();
            $small = (int) $tier['tier'] === 1 && (int) $tier['rows'] < ScaleTier::T2_ROWS;
            if ($small) {
                try {
                    $r = Db::query('SELECT MAX(vod_id) AS m FROM ' . self::prefix() . 'vod');
                    $small = isset($r[0]) && (int) $r[0]['m'] < ScaleTier::T2_ROWS;
                } catch (\Throwable $e) {
                    $small = false;
                }
            }
            $pdo = $small ? ScaleSchema::connect(true) : null;
            $result = $pdo ? self::refreshBuckets($pdo, ScaleSchema::configPrefix(), microtime(true) + 10, ['size' => $cfg['size'], 'copyright' => $cfg['copyright'], 'rows' => false]) : [];
            $state = self::bucketState();
            if (empty($state['built']) || ($state['sig'] ?? '') !== $cfg['sig']) {
                throw new \RuntimeException('Sitemap buckets are not built yet' . (isset($result['state']) ? ' (' . $result['state'] . ')' : '') . '.');
            }
        }
        $table = [];
        foreach (Db::query('SELECT bucket, max_time, cnt FROM ' . self::prefix() . 'sitemap_bucket ORDER BY bucket') as $row) {
            if ((int) $row['cnt'] < 1) { continue; }
            $bucket = self::validBucket((string) $row['bucket']);
            if ($bucket === null) { throw new \RuntimeException('Unsupported sitemap bucket.'); }
            $table[$bucket] = ['modified' => (int) $row['max_time'], 'total' => (int) $row['cnt']];
        }
        return self::$bucketMemo = $table;
    }

    /**
     * [locale => [bucket => newest translation updated_at]] for the language files, from runtime/scale/sitemap_lang.json
     * (written by refreshBuckets() together with the bucket rows); [] without translations.
     */
    private static function languageTable()
    {
        $codes = array_values(array_diff(self::languageFiles(), ['en']));
        if (!$codes) { return []; }
        $data = ScaleTier::readJson(ScaleTier::runtime('scale') . 'sitemap_lang.json');
        if (!is_array($data) || (int) ($data['size'] ?? 0) !== self::perFile() || !isset($data['l']) || !is_array($data['l'])) { return []; }
        $table = [];
        foreach ($codes as $code) {
            if (!isset($data['l'][$code]) || !is_array($data['l'][$code])) { continue; }
            foreach ($data['l'][$code] as $bucket => $modified) { $table[$code][(int) $bucket] = (int) $modified; }
        }
        return $table;
    }

    /** Newest vod_time of the published videos (the site's last real change). */
    private static function siteModified()
    {
        $newest = 0;
        foreach (self::bucketTable() as $row) { $newest = max($newest, $row['modified']); }
        return $newest;
    }

    /**
     * [type_id => newest change] of categories: the category and its sub-categories (videos, articles, performers), from
     * scale_count (type_mod_<id>, written by the counts job, D-L9); [] while never counted (no lastmod printed then).
     */
    private static function typeModified()
    {
        return ScaleCounts::typeModified() ?? [];
    }

    /** Newest change of a secondary module (its listing page). */
    private static function entityModified($part)
    {
        $kind = self::entityKind($part);
        try { return (int) self::publishedEntity($part)->max($kind . '_time'); }
        catch (\Throwable $error) { return 0; }
    }

    // ------------------------------------------------------------------ the endpoint (application/index/controller/Sitemap.php)

    /**
     * The cached file for one sitemap URL, or null when it does not exist (404): hidden site (CF02), unknown feed,
     * a bucket past the last one or without videos, a lang that is not an indexed non-default language (CF12).
     * $lang: null = default-language file. Returns ['gz' => bytes] or ['xml' => text].
     */
    public static function payload($part, $bucket = null, $lang = null)
    {
        if (self::hidden()) { return null; }
        if ($lang !== null && ($part !== 'videos' || self::validLang($lang) === null)) { return null; }
        if ($part === 'index') {
            self::rememberScheme();
            return self::cachedPayload('index', function () { return self::buildIndex(); }, self::CACHE_SECONDS);
        }
        if ($part === 'pages') { return self::cachedPayload('pages', function () { return self::buildPages(); }, self::VIDEO_CACHE_SECONDS); }
        if ($part === 'videos') {
            $bucket = self::validBucket($bucket);
            if ($bucket === null) { return null; }
            $table = self::bucketTable();
            if (!isset($table[$bucket])) { return null; }
            // The bucket's newest change and count are part of the key: a changed bucket gets a new file, no tag needed (a
            // language file also its newest translation change: videoPart()). site-r3 N20: a big file that was never built
            // is answered with its lite build (no video: blocks) until prebuild() builds it whole.
            $entry = $table[$bucket];
            $lite = (int) $entry['total'] >= self::LITE_MIN_URLS && SeoSettings::bool('seo.sitemap_video')
                ? function () use ($bucket, $lang, $entry) { return self::buildVideoFile($bucket, $lang, true, $entry); } : null;
            return self::cachedPayload(self::videoPart($bucket, $lang, $table),
                function () use ($bucket, $lang) { return self::buildVideoFile($bucket, $lang); }, self::VIDEO_FILE_SECONDS, 'videos:' . $bucket . ':' . (string) $lang, $lite);
        }
        if (in_array($part, ['actors', 'topics', 'articles'], true)) {
            $kind = self::entityKind($part);
            $bucket = self::validBucket($bucket);
            if ($bucket === null) { return null; }
            return self::cachedPayload($part . ':' . $bucket, function () use ($part, $kind, $bucket) {
                $start = $bucket * self::BUCKET_SIZE;
                $fields = $kind . '_id,' . $kind . '_en,' . $kind . '_time,' . $kind . '_time_add';
                if ($kind !== 'topic') { $fields .= ',type_id'; }
                $rows = self::publishedEntity($part)->where($kind . '_id', '>', $start)
                    ->where($kind . '_id', '<=', $start + self::BUCKET_SIZE)->field($fields)
                    ->order($kind . '_id')->limit(self::BUCKET_SIZE)->select();
                $records = [];
                foreach ($rows as $row) { $records[] = ['variants'=>self::sitemapVariants(self::entityVariants($kind, $row)), 'updated_at'=>$row[$kind . '_time']]; }
                return self::urlset($records);
            }, self::CACHE_SECONDS);
        }
        return null;
    }

    /** At most 200 stable IDs per feed; gaps/deletions never shift another bucket. */
    public static function entityFeed($part, $bucket)
    {
        self::entityKind($part);
        if (self::validBucket($bucket) === null) { throw new \InvalidArgumentException('Invalid sitemap bucket.'); }
        return self::xmlOf(self::withoutHide(function () use ($part, $bucket) { return self::payload($part, $bucket); }));
    }

    /** The index XML (also used by the admin "Rebuild" button, which works while the site is hidden). */
    public static function indexFeed()
    {
        return self::xmlOf(self::withoutHide(function () { return self::payload('index'); }));
    }

    public static function pagesFeed()
    {
        return self::xmlOf(self::withoutHide(function () { return self::payload('pages'); }));
    }

    /** One video file ($lang null = default language). Throws InvalidArgumentException when the file does not exist. */
    public static function videoFeed($bucket, $lang = null)
    {
        $payload = self::withoutHide(function () use ($bucket, $lang) { return self::payload('videos', $bucket, $lang); });
        if ($payload === null) { throw new \InvalidArgumentException('Invalid sitemap bucket.'); }
        return self::xmlOf($payload);
    }

    private static function withoutHide(callable $fn)
    {
        $payload = SeoSettings::withOverrides(['seo.hide_site' => '0'], $fn);
        if ($payload === null) { throw new \InvalidArgumentException('Invalid sitemap.'); }
        return $payload;
    }

    /** Count line of the admin Sitemap tab (E.1): "105 files, 524,375 videos, 16 languages". */
    public static function stats()
    {
        $table = self::bucketTable();
        $languages = self::languageFiles();
        return ['files' => count($table), 'language_files' => count($table) * count($languages),
            'videos' => array_sum(array_column($table, 'total')), 'languages' => count($languages), 'per_file' => self::perFile()];
    }

    // ------------------------------------------------------------------ index and pages

    private static function buildIndex()
    {
        $table = self::bucketTable();
        $children = [[self::feedUrl('pages'), self::pagesModified()]];
        foreach ($table as $bucket => $row) { $children[] = [self::feedUrl('videos', $bucket), $row['modified']]; }
        $languages = self::languageFiles();
        if ($languages) {
            $translated = self::languageTable();
            foreach ($languages as $code) {
                foreach ($table as $bucket => $row) {
                    $modified = $row['modified'];
                    if ($code !== 'en') {
                        // English is the data base (every video has it); other languages only where translations exist.
                        if (!isset($translated[$code][$bucket])) { continue; }
                        $modified = max($modified, $translated[$code][$bucket]);
                    }
                    $children[] = [self::feedUrl('videos', $bucket, $code), $modified];
                }
            }
        }
        foreach (['actors','topics','articles'] as $part) {
            $kind = self::entityKind($part);
            $buckets = self::publishedEntity($part)->field('FLOOR((' . $kind . '_id-1)/' . self::BUCKET_SIZE . ') AS bucket,MAX(' . $kind . '_time) AS modified')
                ->group('bucket')->order('bucket')->select();
            foreach ($buckets as $row) {
                $bucket = self::validBucket((string) $row['bucket']);
                if ($bucket === null) { throw new \RuntimeException('Unsupported sitemap bucket.'); }
                $children[] = [self::feedUrl($part, $bucket), (int) $row['modified']];
            }
        }
        return self::indexXml($children);
    }

    /** Last change of the pages file: the newest video, a newer enabled legal page text. */
    private static function pagesModified()
    {
        $newest = self::siteModified();
        foreach (self::legalRecords() as $record) { $newest = max($newest, (int) $record['updated_at']); }
        return $newest;
    }

    /** Enabled legal pages (F.1) at /label/<slug>/, default language only (no hreflang, C.2 legal branch). */
    private static function legalRecords()
    {
        $class = __NAMESPACE__ . '\\LegalPages';
        if (!class_exists($class) || !method_exists($class, 'seo')) { return []; }
        $records = [];
        foreach (SeoSettings::legalPages() as $slug) {
            try { $page = $class::seo($slug); } catch (\Throwable $error) { $page = null; }
            if (!is_array($page)) { continue; }
            $url = self::absolute(mac_url('label/' . $slug));
            if ($url === '') { continue; }
            // Last change of the text: the owner's saved text, else the shipped template (LegalPages::source()).
            $file = SeoSettings::legalDir() . $slug . '.json';
            $updated = method_exists($class, 'source') ? (int) ($class::source($slug)['updated'] ?? 0) : (is_file($file) ? (int) filemtime($file) : 0);
            $records[] = ['variants' => [AvdbLanguage::defaultLocale() => ['url' => $url, 'updated_at' => 0]], 'updated_at' => $updated];
        }
        return $records;
    }

    private static function buildPages()
    {
        $siteModified = self::siteModified();
        $records = [['variants' => self::sitemapVariants(self::homeVariants()), 'updated_at' => $siteModified]];
        $browseTypes = array_values(array_unique(array_merge(self::publicTypes(1), self::publicTypes(1, 2), self::publicTypes(1, 8))));
        $types = Db::name('type')->where('type_id', 'in', $browseTypes ?: [0])->order('type_id')->select();
        $typeModified = self::typeModified();
        $had = array_key_exists('translang', $_GET); $language = $_GET['translang'] ?? null; unset($_GET['translang']);
        try {
            foreach ($types as $type) {
                $records[] = ['variants' => self::sitemapVariants(self::categoryVariants($type)), 'updated_at' => $typeModified[(int) $type['type_id']] ?? 0];
            }
            // vod/index is a noindex near-copy of /label/sort/ (C.4): not listed.
            $interfaces = ['actor/index'=>'actor_index','topic/index'=>'topic_index','art/index'=>'art_index',
                'map/index'=>'map','label/new'=>'label','label/hot'=>'label','label/sort'=>'label','label/up'=>'label','label/score'=>'label'];
            $modules = ['actor' => 'actors', 'topic' => 'topics', 'art' => 'articles'];
            $templates = self::pageTemplateAvailability();
            $emptyModules = self::emptyModules();
            foreach ($interfaces as $action => $viewKey) {
                $section = strstr($action, '/', true);
                // Listings of modules without published rows answer "noindex": the sitemap must not submit them.
                if (in_array($section, $emptyModules, true)) { continue; }
                if ($viewKey === 'label') {
                    $file = substr($action, strlen('label/'));
                    if (!$templates || in_array(false, array_column($templates, $file), true)) { continue; }
                }
                $base = self::absolute(mac_url($action));
                // Static interface files cannot translate a query; advertise only their real base page.
                $variants = (int) ($GLOBALS['config']['view'][$viewKey] ?? 1) === 2 ? self::variants($base, []) : self::pageVariants($base, self::browsingLocales());
                $records[] = ['variants' => self::sitemapVariants($variants),
                    'updated_at' => isset($modules[$section]) ? self::entityModified($modules[$section]) : $siteModified];
            }
            foreach (self::legalRecords() as $record) { $records[] = $record; }
        } finally { if ($had) { $_GET['translang'] = $language; } }
        return self::urlset($records);
    }

    // ------------------------------------------------------------------ video URLs and files

    private static function translationRows(array $ids, ?array $locales = null)
    {
        if (!$ids) { return []; }
        try {
            $query = Db::name('avdb_translation')->where('vod_id', 'in', $ids);
            if ($locales !== null) { $query->where('locale', 'in', $locales ?: ['']); }
            return $query->field('vod_id,locale,payload,updated_at')->order('vod_id,locale')->select();
        } catch (\Throwable $error) {
            // A fresh installation can legitimately have no optional translation schema.
            if (strpos($error->getMessage(), '42S02') !== false || strpos($error->getMessage(), '1146') !== false) { return []; }
            throw $error;
        }
    }

    /** Category data the static URL builders need. */
    private static function withType(array $vod)
    {
        if (!isset($vod['type']) || !is_array($vod['type'])) {
            $metadata = self::categoryMetadata();
            $vod['type'] = $metadata[(int) ($vod['type_id'] ?? 0)] ?? ['type_id'=>0,'type_en'=>'','type_pid'=>0];
        }
        if (!isset($vod['type_1']) || !is_array($vod['type_1'])) {
            $metadata = self::categoryMetadata();
            $vod['type_1'] = $metadata[(int) ($vod['type']['type_pid'] ?? 0)] ?? ['type_id'=>0,'type_en'=>''];
        }
        return $vod;
    }

    private static function detailBase(array $vod)
    {
        $vod = self::withType($vod);
        $had = array_key_exists('translang', $_GET); $language = $_GET['translang'] ?? null;
        unset($_GET['translang']);
        try { return self::absolute(mac_url_vod_detail($vod)); }
        finally { if ($had) { $_GET['translang'] = $language; } }
    }

    public static function detailVariants(array $vod)
    {
        $id = (int) ($vod['vod_id'] ?? 0);
        if (!isset(self::$detailRows[$id])) { self::$detailRows[$id] = self::translationRows([$id]); }
        return self::vodVariants($vod, self::$detailRows[$id]);
    }

    /** Episode URLs share only the translations actually supplied for this video ($translations: rows already loaded). */
    public static function playbackVariants(array $vod, array $parameters, ?array $translations = null)
    {
        $id = (int) ($vod['vod_id'] ?? 0);
        if ($translations === null) {
            if (!isset(self::$detailRows[$id])) { self::$detailRows[$id] = self::translationRows([$id]); }
            $translations = self::$detailRows[$id];
        }
        $vod = self::withType($vod);
        $parameters = ['sid' => max(1, (int) ($parameters['sid'] ?? 1)), 'nid' => max(1, (int) ($parameters['nid'] ?? 1))];
        $had = array_key_exists('translang', $_GET); $language = $_GET['translang'] ?? null;
        unset($_GET['translang']);
        try {
            $base = self::absolute(mac_url_vod_play($vod, $parameters));
            $localized = $base;
            if ((int) ($GLOBALS['config']['view']['vod_play'] ?? 1) === 2 && $translations) {
                $before = $GLOBALS['config']['view']['vod_play'];
                $GLOBALS['config']['view']['vod_play'] = 1;
                try { $localized = self::absolute(mac_url_vod_play($vod, $parameters)); }
                finally { $GLOBALS['config']['view']['vod_play'] = $before; }
            }
        } finally { if ($had) { $_GET['translang'] = $language; } }
        return self::variants($base, $translations, $localized);
    }

    private static function vodVariants(array $vod, array $translations)
    {
        $base = self::detailBase($vod);
        $localized = $base;
        // Static files cannot render a language query. Their translations use the public PHP detail route.
        if ((int) ($GLOBALS['config']['view']['vod_detail'] ?? 1) === 2 && $translations) {
            $before = $GLOBALS['config']['view']['vod_detail'];
            $GLOBALS['config']['view']['vod_detail'] = 1;
            try { $localized = self::detailBase($vod); }
            finally { $GLOBALS['config']['view']['vod_detail'] = $before; }
        }
        return self::variants($base, $translations, $localized);
    }

    /**
     * site-r3 N20: the variant of one video in a language file ($locale is not the default language) without a route lookup
     * per row, or null when the slow path must decide (a static watch / detail page: its translations use the dynamic route).
     * Equal to (playbackVariants() / vodVariants())[$locale]: the default URL (videoUrl(): the same URL and checks as
     * bareUrl(mac_url_vod_play / mac_url_vod_detail)) + ?ui_lang=<code> (& after a query), updated_at of the translation row;
     * English (the data base) needs no row; another language a row with real metadata (variants()). Returns [$locale =>
     * ['url', 'updated_at']], or [] when the video is not listed in that language.
     */
    private static function languageUrl(array $vod, $sid, $locale, array $rows, array $offered)
    {
        $view = (int) ($GLOBALS['config']['view'][(int) $sid > 0 ? 'vod_play' : 'vod_detail'] ?? 1);
        if ($view === 2) { return null; }
        if (!in_array($locale, $offered, true)) { return []; }
        $updated = 0;
        $found = $locale === 'en';
        foreach ($rows as $row) {
            if ((string) ($row['locale'] ?? '') !== $locale || !self::hasMetadata($row)) { continue; }
            $found = true;
            $updated = (int) ($row['updated_at'] ?? 0);
        }
        if (!$found) { return []; }
        $base = self::videoUrl($vod, (int) $sid);
        if ($base === '') { return []; }
        return [$locale => ['url' => $base . (strpos($base, '?') === false ? '?' : '&') . 'ui_lang=' . $locale, 'updated_at' => $updated]];
    }

    /** A translation row with real metadata (variants(): a non-empty vod_name, vod_content or vod_blurb after strip_tags). */
    private static function hasMetadata(array $row)
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        if (!is_array($payload)) { return false; }
        foreach (['vod_name', 'vod_content', 'vod_blurb'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field]) && trim(strip_tags($payload[$field])) !== '') { return true; }
        }
        return false;
    }

    /** Player URL of one episode text "name$url[$from]" (mac_play_list_one): the URL part, else the name part. */
    private static function episodeUrl($segment)
    {
        $parts = explode('$', (string) $segment, 3);
        $url = trim((string) ($parts[1] ?? ''));
        return $url !== '' ? $url : trim((string) ($parts[0] ?? ''));
    }

    /**
     * Canonical video page of each row (C.3, CF01; the same choice as TemplateSeo's detail branch): the watch page of
     * the first source when its player is enabled and it has an episode 1, else the first other source with an episode 1,
     * else the detail page. Rows need VIDEO_URL_FIELDS. Returns [vod_id => ['sid' => n (0 = detail), 'url', 'from']].
     * Only rows whose first source is unusable read their full play list (rare).
     */
    public static function watchTargets(array $rows)
    {
        $players = (array) config('vodplayer');
        $enabled = function ($from) use ($players) {
            return isset($players[$from]) && is_array($players[$from]) && (string) ($players[$from]['status'] ?? '0') === '1';
        };
        $targets = [];
        $full = [];
        foreach ($rows as $vod) {
            $id = (int) $vod['vod_id'];
            if (empty($vod['has_play'])) { $targets[$id] = ['sid' => 0, 'url' => '', 'from' => '']; continue; }
            $from = explode('$$$', (string) ($vod['vod_play_from'] ?? ''))[0];
            $first = (string) ($vod['first_ep'] ?? '');
            if ($first !== '' && $enabled($from)) { $targets[$id] = ['sid' => 1, 'url' => self::episodeUrl($first), 'from' => $from]; }
            else { $full[] = $id; }
        }
        $sorted = defined('MAC_PLAYER_SORT') && (string) MAC_PLAYER_SORT === '1';
        foreach (array_chunk($full, 500) as $chunk) {
            foreach (Db::name('vod')->where('vod_id', 'in', $chunk)->field('vod_id,vod_play_from,vod_play_url')->select() as $row) {
                $froms = explode('$$$', (string) $row['vod_play_from']);
                $urls = explode('$$$', (string) $row['vod_play_url']);
                $candidates = [];
                foreach ($froms as $k => $from) {
                    if (!$enabled($from)) { continue; }
                    $first = explode('#', (string) ($urls[$k] ?? ''))[0];
                    if ($first === '') { continue; }
                    $candidates[] = ['sid' => $k + 1, 'url' => self::episodeUrl($first), 'from' => $from, 'sort' => (int) ($players[$from]['sort'] ?? 0)];
                }
                $choice = ['sid' => 0, 'url' => '', 'from' => ''];
                foreach ($candidates as $candidate) { if ($candidate['sid'] === 1) { $choice = $candidate; } }
                if ($choice['sid'] === 0 && $candidates) {
                    if ($sorted) { usort($candidates, function ($a, $b) { return $b['sort'] <=> $a['sort'] ?: $a['sid'] <=> $b['sid']; }); }
                    $choice = $candidates[0];
                }
                unset($choice['sort']);
                $targets[(int) $row['vod_id']] = $choice;
            }
        }
        return $targets;
    }

    /**
     * Canonical default-language URLs of videos (C.3, the URL the video file lists), for IndexNow (E.3):
     * [vod_id => ['url' => absolute URL, 'time' => vod_time]]. Rows that cannot build a URL are left out.
     */
    public static function videoUrls(array $ids)
    {
        $clean = [];
        foreach ($ids as $id) { if (is_numeric($id) && (int) $id > 0) { $clean[(int) $id] = (int) $id; } }
        $default = AvdbLanguage::defaultLocale();
        $result = [];
        foreach (array_chunk(array_values($clean), 1000) as $chunk) {
            $rows = Db::name('vod')->where('vod_id', 'in', $chunk)->field(self::VIDEO_URL_FIELDS)->select();
            $targets = self::watchTargets($rows);
            foreach ($rows as $vod) {
                $id = (int) $vod['vod_id'];
                $sid = (int) ($targets[$id]['sid'] ?? 0);
                $variants = $sid > 0 ? self::playbackVariants($vod, ['sid' => $sid, 'nid' => 1], []) : self::vodVariants($vod, []);
                if (isset($variants[$default]['url'])) { $result[$id] = ['url' => $variants[$default]['url'], 'time' => (int) $vod['vod_time']]; }
            }
        }
        return $result;
    }

    /** Absolute poster URL (TemplateSeo::absoluteAsset rules), '' when it must not be published. */
    private static function assetUrl($path)
    {
        $path = function_exists('mac_url_img') ? mac_url_img($path) : (string) $path;
        if (!is_string($path) || $path === '' || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $path)) { return ''; }
        if (strpos($path, '//') === 0) { $path = 'https:' . $path; }
        if (preg_match('~^https?://~i', $path)) { return filter_var($path, FILTER_VALIDATE_URL) ? $path : ''; }
        return substr($path, 0, 1) === '/' ? self::absolute($path) : '';
    }

    /**
     * video:player_loc: the URL the player frame loads for episode 1 of the canonical source, with the same rules as the
     * watch page's VideoObject embedUrl (TemplateSeo::playerMediaUrl): no encrypted, paid, trial or parse-wrapped source.
     */
    private static function playerUrl(array $vod, array $target)
    {
        if ((int) ($GLOBALS['config']['app']['encrypt'] ?? 0) !== 0 || (int) ($vod['vod_points_play'] ?? 0) > 0) { return ''; }
        $trysee = (int) ($vod['vod_trysee'] ?? 0) > 0 ? (int) $vod['vod_trysee'] : (int) ($GLOBALS['config']['user']['trysee'] ?? 0);
        if ($trysee > 0) { return ''; }
        $players = (array) config('vodplayer');
        $player = $players[(string) ($target['from'] ?? '')] ?? null;
        if (!is_array($player) || (string) ($player['ps'] ?? '0') === '1') { return ''; }
        $url = trim((string) ($target['url'] ?? ''));
        if (!preg_match('~^https?://[^\s"\'<>]+$~i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) { return ''; }
        return $url;
    }

    /**
     * One video file: the canonical URL of each published video of the bucket in one language ($lang null = the
     * default language: bare URLs; else that language's ?ui_lang= URLs, only for videos translated into it), with
     * <lastmod> = vod_time (or a newer translation) and a video: block in that language (seo.sitemap_video).
     * $lite (site-r3 N20): the same URLs and lastmod without the video: blocks (a never-built file answered at once).
     * Language URLs (site-r3 N20, H's per-row cost 0.149 s per 5,000): where the watch / detail URL is dynamic (no static
     * page), the localized URL is the default URL (videoUrl()) + ?ui_lang=<code> exactly as playbackVariants() / vodVariants()
     * build it, without the per-row route lookup; static pages keep the slow path.
     * flood-r4 F3 / G9: the lite build of a default-language file (English default language) reads the bucket's rows list
     * ($entry = the bucket table row: its version) when one of that version exists (liteFromRows(): the same bytes, no table
     * read); otherwise the table as before.
     */
    private static function buildVideoFile($bucket, $lang, $lite = false, ?array $entry = null)
    {
        $size = self::perFile();
        $start = $bucket * $size;
        $locale = $lang === null ? AvdbLanguage::defaultLocale() : $lang;
        if ($lite && $lang === null && $locale === 'en' && $entry !== null) {
            $fromRows = self::liteFromRows((int) $bucket, $entry);
            if ($fromRows !== null) { return $fromRows; }
        }
        $withVideo = !$lite && SeoSettings::bool('seo.sitemap_video');
        $fields = self::VIDEO_URL_FIELDS . ($withVideo ? ',vod_pic,vod_blurb,LEFT(vod_content,3000) AS vod_content,vod_remarks,vod_duration,vod_pubdate,vod_actor,vod_writer,vod_tag,vod_class' : '');
        $videos = self::published()->where('vod_id', '>', $start)->where('vod_id', '<=', $start + $size)
            ->field($fields)->order('vod_id')->limit($size)->select();
        $translations = [];
        if ($locale !== 'en') {
            foreach (self::translationRows(array_column($videos, 'vod_id'), [$locale]) as $row) { $translations[(int) $row['vod_id']][] = $row; }
        }
        $targets = self::watchTargets($videos);
        $metadata = self::categoryMetadata();
        $defaultLocale = AvdbLanguage::defaultLocale();
        $offered = AvdbLanguage::offeredCodes();
        $records = [];
        $had = array_key_exists('translang', $_GET); $language = $_GET['translang'] ?? null; unset($_GET['translang']);
        try {
            foreach ($videos as $vod) {
                $id = (int) $vod['vod_id'];
                $target = $targets[$id] ?? ['sid' => 0, 'url' => '', 'from' => ''];
                // A play password makes the watch page noindex (C.4) and the detail page points to it: nothing to submit.
                if ($target['sid'] > 0 && (string) ($vod['vod_pwd_play'] ?? '') !== '') { continue; }
                $rows = $translations[$id] ?? [];
                // One URL per video (C.3, CF01): never /detail/ when a play list exists. Default-language file and no
                // translation rows (most rows at scale): only the bare URL is listed, so it is built directly (same URL
                // and the same validation as variants(), without the per-row language lists).
                if (!$rows && $locale === $defaultLocale) {
                    $url = self::videoUrl($vod, (int) $target['sid']);
                    $variants = $url === '' ? [] : [$locale => ['url' => $url, 'updated_at' => 0]];
                } elseif ($locale !== $defaultLocale && ($fast = self::languageUrl($vod, (int) $target['sid'], $locale, $rows, $offered)) !== null) {
                    $variants = $fast;
                } else {
                    $variants = $target['sid'] > 0 ? self::playbackVariants($vod, ['sid' => $target['sid'], 'nid' => 1], $rows) : self::vodVariants($vod, $rows);
                }
                if (!isset($variants[$locale])) { continue; }
                $record = ['variants' => [$locale => $variants[$locale]], 'updated_at' => (int) $vod['vod_time']];
                if ($withVideo && $target['sid'] > 0) {
                    $player = self::playerUrl($vod, $target);
                    $thumbnail = $player === '' ? '' : self::assetUrl($vod['vod_pic'] ?? '');
                    if ($player !== '' && $thumbnail !== '') {
                        $payloads = [];
                        foreach ($rows as $row) { $payload = json_decode($row['payload'] ?? '', true); if (is_array($payload)) { $payloads[$row['locale']] = $payload; } }
                        $shown = AvdbLanguage::decorate($vod, $payloads, $locale);
                        $shown['type'] = $metadata[(int) $vod['type_id']] ?? [];
                        $facts = TemplateSeo::videoText($shown, $locale, self::VIDEO_TAGS);
                        $record['video'] = ['thumbnail' => $thumbnail, 'title' => $facts['title'], 'description' => $facts['description'],
                            'player' => $player, 'duration' => $facts['duration'], 'published' => $facts['upload'], 'tags' => $facts['tags']];
                    }
                }
                $records[] = $record;
            }
        } finally { if ($had) { $_GET['translang'] = $language; } }
        return self::urlset($records);
    }

    // ------------------------------------------------------------------ bucket rows lists (flood-r4 F3 / G9)

    /**
     * The lite build of a never-built default-language file from the bucket's rows list (flood-r4 F3 / G9): the URLs and lastmod
     * buildVideoFile($bucket, null, true) builds from the table (published rows of public categories, watch page of the
     * canonical source else the detail page, no play-password watch pages), from one file read. null when no list of this
     * bucket version exists (or it cannot be read): the caller reads the table. A row whose first source is not usable and
     * that has more than ROWS_SOURCES sources reads its play list (as watchTargets() does for such rows).
     */
    private static function liteFromRows($bucket, array $entry)
    {
        try {
            $cfg = self::bucketConfig();
            $rows = self::rowsLoad($bucket, $cfg, (int) ($entry['modified'] ?? -1), (int) ($entry['total'] ?? -1));
            if ($rows === null) { return null; }
            $public = array_flip(self::publicTypes() ?: [0]);
            $players = (array) config('vodplayer');
            $enabled = function ($from) use ($players) {
                return isset($players[$from]) && is_array($players[$from]) && (string) ($players[$from]['status'] ?? '0') === '1';
            };
            $sorted = defined('MAC_PLAYER_SORT') && (string) MAC_PLAYER_SORT === '1';
            $kept = [];
            $full = [];
            foreach ($rows as $r) {
                if (!isset($public[(int) $r[2]])) { continue; }
                $flags = (int) $r[4];
                $sid = 0;
                if ($flags & 2) {
                    $froms = explode('$$$', (string) $r[5]);
                    $eps = (int) $r[6];
                    if (($eps & 1) && $enabled($froms[0])) {
                        $sid = 1;
                    } elseif ($flags & 4) {
                        $full[] = (int) $r[0];   // more sources than flagged: decided from its play list below
                        $sid = -1;
                    } else {
                        // watchTargets(): the sources with an enabled player and an episode 1 (source 1 is not usable here)
                        $candidates = [];
                        foreach ($froms as $k => $from) {
                            if ($k >= self::ROWS_SOURCES || !($eps & (1 << $k)) || !$enabled($from)) { continue; }
                            $candidates[] = ['sid' => $k + 1, 'sort' => (int) ($players[$from]['sort'] ?? 0)];
                        }
                        if ($candidates) {
                            if ($sorted) { usort($candidates, function ($a, $b) { return $b['sort'] <=> $a['sort'] ?: $a['sid'] <=> $b['sid']; }); }
                            $sid = (int) $candidates[0]['sid'];
                        }
                    }
                }
                $kept[] = [$r, $sid];
            }
            $targets = [];
            if ($full) {
                $targets = self::watchTargets(array_map(function ($id) { return ['vod_id' => $id, 'has_play' => 1, 'vod_play_from' => '', 'first_ep' => '']; }, $full));
            }
            $records = [];
            $had = array_key_exists('translang', $_GET); $language = $_GET['translang'] ?? null; unset($_GET['translang']);
            try {
                foreach ($kept as $item) {
                    list($r, $sid) = $item;
                    $id = (int) $r[0];
                    if ($sid < 0) { $sid = (int) ($targets[$id]['sid'] ?? 0); }
                    if ($sid > 0 && ((int) $r[4] & 1)) { continue; }   // a play password makes the watch page noindex (C.4)
                    $url = self::videoUrl(['vod_id' => $id, 'vod_sub' => (string) $r[3], 'type_id' => (int) $r[2], 'vod_time' => (int) $r[1],
                        'vod_en' => '', 'vod_name' => ''], $sid);
                    if ($url === '') { continue; }
                    $records[] = ['variants' => ['en' => ['url' => $url, 'updated_at' => 0]], 'updated_at' => (int) $r[1]];
                }
            } finally { if ($had) { $_GET['translang'] = $language; } }
            return self::urlset($records);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** runtime/scale/sitemap_rows/<bucket>.gz ('' when the folder cannot be made). */
    private static function rowsFile($bucket)
    {
        try {
            return ScaleTier::runtime('scale/sitemap_rows') . (int) $bucket . '.gz';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The header of a bucket's rows list (['v', 'size', 'b', 'm', 'c', 'cr', 'at']) or null (no list, unreadable). */
    public static function rowsHeader($bucket)
    {
        $file = self::rowsFile($bucket);
        if ($file === '' || !is_file($file) || !function_exists('gzopen')) { return null; }
        $h = @gzopen($file, 'rb');
        if (!$h) { return null; }
        $line = @gzgets($h, 4096);
        @gzclose($h);
        $head = is_string($line) ? json_decode(trim($line), true) : null;
        return is_array($head) && (int) ($head['v'] ?? 0) === self::ROWS_VERSION ? $head : null;
    }

    /** Pure: a rows-list header belongs to bucket $bucket at version ($m, $c) of the configuration $cfg (null = any version). */
    public static function rowsHeaderValid($head, array $cfg, $bucket, $m = null, $c = null)
    {
        return is_array($head) && (int) ($head['v'] ?? 0) === self::ROWS_VERSION && (int) ($head['size'] ?? 0) === (int) $cfg['size']
            && (int) ($head['b'] ?? -1) === (int) $bucket && (bool) ($head['cr'] ?? false) === (bool) $cfg['copyright']
            && ($m === null || (int) ($head['m'] ?? -1) === (int) $m) && ($c === null || (int) ($head['c'] ?? -1) === (int) $c);
    }

    /** The rows of a bucket's list for version ($m, $c), or null (no list of that version, unreadable, count mismatch). */
    private static function rowsLoad($bucket, array $cfg, $m, $c)
    {
        $file = self::rowsFile($bucket);
        if ($file === '' || !is_file($file) || !function_exists('gzdecode')) { return null; }
        $raw = @file_get_contents($file);
        $text = is_string($raw) && $raw !== '' ? @gzdecode($raw) : false;
        if (!is_string($text) || $text === '') { return null; }
        $lines = explode("\n", $text);
        $head = json_decode((string) array_shift($lines), true);
        if (!self::rowsHeaderValid($head, $cfg, $bucket, $m, $c)) { return null; }
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') { continue; }
            $r = json_decode($line, true);
            if (!is_array($r) || count($r) < 7) { return null; }
            $rows[] = $r;
        }
        return count($rows) === (int) $c ? $rows : null;
    }

    /**
     * SELECT of one bucket's published rows for its rows list (rowsFetch): the facts the canonical URL needs, the play flags
     * (pw: play password, hp: play list, the first episode of each of the first ROWS_SOURCES sources) and the number of play
     * list segments, so no play-list text leaves the server.
     */
    private static function rowsSql($p, $copyright)
    {
        $eps = [];
        for ($k = 1; $k <= self::ROWS_SOURCES; $k++) {
            $seg = $k === 1 ? 'SUBSTRING_INDEX(vod_play_url,\'$$$\',1)' : 'SUBSTRING_INDEX(SUBSTRING_INDEX(vod_play_url,\'$$$\',' . $k . '),\'$$$\',-1)';
            $eps[] = '(SUBSTRING_INDEX(' . $seg . ',\'#\',1) <> \'\') AS e' . $k;
        }
        return 'SELECT vod_id, vod_time, type_id, vod_sub, (vod_pwd_play <> \'\') AS pw, (vod_play_url <> \'\') AS hp, vod_play_from,'
            . ' (LENGTH(vod_play_url) - LENGTH(REPLACE(vod_play_url,\'$$$\',\'\'))) DIV 3 + 1 AS un, ' . implode(', ', $eps)
            . ' FROM ' . $p . 'vod WHERE vod_id BETWEEN ? AND ? AND ' . self::publishedSql((bool) $copyright) . ' ORDER BY vod_id';
    }

    /**
     * One bucket's published rows as rows-list lines (PK range, the same rows the bucket table counts):
     * ['m' => newest vod_time, 'c' => count, 'lines' => JSON arrays [vod_id, vod_time, type_id, vod_sub, flags (1 play password,
     * 2 play list, 4 more sources than flagged), vod_play_from, episode bits (bit k: source k + 1 has an episode 1)]].
     */
    private static function rowsFetch(\PDO $pdo, $p, array $cfg, $bucket)
    {
        $size = (int) $cfg['size'];
        $st = $pdo->prepare(self::rowsSql($p, $cfg['copyright']));
        $st->execute([(int) $bucket * $size + 1, ((int) $bucket + 1) * $size]);
        $m = 0;
        $lines = [];
        while (($r = $st->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $from = (string) $r['vod_play_from'];
            $nFrom = $from === '' ? 1 : substr_count($from, '$$$') + 1;
            $segments = max(1, (int) $r['un']);
            $bits = 0;
            for ($k = 1; $k <= self::ROWS_SOURCES; $k++) {
                // a segment the play list does not have is no episode (watchTargets(): $urls[$k] ?? '')
                if ($k <= $segments && !empty($r['e' . $k])) { $bits |= 1 << ($k - 1); }
            }
            $flags = (!empty($r['pw']) ? 1 : 0) | (!empty($r['hp']) ? 2 : 0) | ($nFrom > self::ROWS_SOURCES || $segments > self::ROWS_SOURCES ? 4 : 0);
            $m = max($m, (int) $r['vod_time']);
            $lines[] = json_encode([(int) $r['vod_id'], (int) $r['vod_time'], (int) $r['type_id'], (string) $r['vod_sub'], $flags, $from, $bits],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        $st->closeCursor();
        return ['m' => $m, 'c' => count($lines), 'lines' => $lines];
    }

    /**
     * Write a bucket's rows list for version ($r['m'], $r['c']) (a header-only list for an empty bucket, so "no list" always
     * means "not written yet"); nothing while the disk holding runtime/ has less than ROWS_MIN_FREE free. true = written.
     */
    private static function rowsWrite($bucket, array $cfg, array $r)
    {
        $file = self::rowsFile($bucket);
        if ($file === '' || !function_exists('gzencode')) { return false; }
        $free = @disk_free_space(dirname($file));
        if ($free !== false && $free < self::ROWS_MIN_FREE) { return false; }
        $head = json_encode(['v' => self::ROWS_VERSION, 'size' => (int) $cfg['size'], 'b' => (int) $bucket, 'm' => (int) $r['m'], 'c' => (int) $r['c'],
            'cr' => (bool) $cfg['copyright'], 'at' => time()]);
        $gz = gzencode($head . "\n" . implode("\n", $r['lines']), self::GZIP_LEVEL);
        return is_string($gz) && ScaleTier::writeAtomic($file, $gz) !== false;
    }

    /** Remove a bucket's rows list (the bucket left the table). */
    private static function rowsDrop($bucket)
    {
        $file = self::rowsFile($bucket);
        if ($file !== '' && is_file($file)) { @unlink($file); }
    }

    /** true when a bucket of [$a, $b] has no rows list for the configuration $cfg (any version). */
    private static function rowsMissingIn($a, $b, array $cfg)
    {
        for ($x = (int) $a; $x <= (int) $b; $x++) {
            if (!self::rowsHeaderValid(self::rowsHeader($x), $cfg, $x)) { return true; }
        }
        return false;
    }

    /**
     * flood-r4 F3 / G9: write the rows lists the bucket table's buckets lack (or whose version is not the table's), newest
     * buckets first, until $deadline (microtime; a started bucket is finished), and remove the lists of buckets past the last
     * one. For sites whose bucket table was built before the lists existed (tools/upgrade.php, housekeeping runs after the
     * bucket refresh); new and changed buckets get theirs from the refresh itself. Single flight with refreshBuckets()
     * (sitemap.lock). $opt: size / copyright (tests), pause (share of each read's time to sleep after it). Returns
     * ['state' => 'done'|'partial'|'busy'|'not_built'|'error', 'written', 'fresh', 'left', 'ms'].
     */
    public static function rowsBackfill(\PDO $pdo, $prefix, $deadline, array $opt = [])
    {
        $t0 = microtime(true);
        $out = ['state' => 'done', 'written' => 0, 'fresh' => 0, 'left' => 0];
        if (!preg_match('/^[A-Za-z0-9_]*$/D', (string) $prefix)) { return ['state' => 'error', 'error' => 'invalid prefix'] + $out; }
        $lock = @fopen(ScaleTier::runtime('scale') . 'sitemap.lock', 'c');
        if (!$lock) { return ['state' => 'error', 'error' => 'lock file'] + $out; }
        if (ScaleTier::lock($lock, LOCK_EX, 0) === false) { @fclose($lock); return ['state' => 'busy'] + $out; }
        try {
            $cfg = self::bucketConfig($opt);
            $state = self::bucketState();
            if (empty($state['built']) || ($state['sig'] ?? '') !== $cfg['sig']) { return ['state' => 'not_built'] + $out; }
            $pause = max(0.0, min(4.0, (float) ($opt['pause'] ?? 0)));
            $table = $pdo->query('SELECT bucket, max_time, cnt FROM ' . $prefix . 'sitemap_bucket ORDER BY bucket DESC')->fetchAll(\PDO::FETCH_ASSOC);
            $top = $table ? (int) $table[0]['bucket'] : -1;
            foreach (glob(ScaleTier::runtime('scale/sitemap_rows') . '*.gz') ?: [] as $file) {
                if (preg_match('/(\d+)\.gz$/', $file, $mm) && (int) $mm[1] > $top) { @unlink($file); }
            }
            // temporary files of a write a killed process left behind (ScaleTier::writeAtomic: <name>.<pid>.<hex>.tmp)
            foreach (glob(ScaleTier::runtime('scale/sitemap_rows') . '*.tmp') ?: [] as $file) {
                if ((int) @filemtime($file) < time() - 3600) { @unlink($file); }
            }
            foreach ($table as $row) {
                $x = (int) $row['bucket'];
                if (self::rowsHeaderValid(self::rowsHeader($x), $cfg, $x, (int) $row['max_time'], (int) $row['cnt'])) { $out['fresh']++; continue; }
                if (microtime(true) >= (float) $deadline) { $out['left']++; continue; }
                $t1 = microtime(true);
                $r = self::rowsFetch($pdo, $prefix, $cfg, $x);
                self::pauseAfter($t1, $pause);
                if (self::rowsWrite($x, $cfg, $r)) { $out['written']++; } else { $out['left']++; }
            }
            if ($out['left'] > 0) { $out['state'] = 'partial'; }
        } catch (\Throwable $e) {
            $out['state'] = class_exists(__NAMESPACE__ . '\DbTimeout') && DbTimeout::is($e) ? 'partial' : 'error';
            $out['error'] = substr((string) preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
        $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
        return $out;
    }

    // ------------------------------------------------------------------ stored bucket table (D-L9, housekeeping)

    /** Table prefix of the framework connection (validated). */
    private static function prefix()
    {
        $prefix = (string) config('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) { throw new \RuntimeException('Invalid database prefix.'); }
        return $prefix;
    }

    /**
     * File size, copyright rule and the signature of the stored table. Works without the framework (housekeeping CLI,
     * the pre-framework trigger): then application/extra/maccms.php is read directly. $override: ['size', 'copyright'].
     */
    public static function bucketConfig(array $override = [])
    {
        if (isset($override['size'], $override['copyright'])) {
            $size = (int) $override['size'];
            $copyright = (bool) $override['copyright'];
        } elseif (isset($GLOBALS['config']['seo']) && function_exists('config')) {
            $size = self::perFile();
            $copyright = (int) ($GLOBALS['config']['app']['copyright_status'] ?? 0) === 2;
        } else {
            $file = ScaleTier::root() . 'application/extra/maccms.php';
            $m = is_file($file) ? (static function ($path) { return include $path; })($file) : [];
            $m = is_array($m) ? $m : [];
            $stored = $m['seo']['sitemap_per_file'] ?? '';
            $size = self::sizeFor(is_numeric($stored) ? (int) $stored : 0, (int) (ScaleTier::get()['rows'] ?? 0));
            $copyright = (int) ($m['app']['copyright_status'] ?? 0) === 2;
        }
        $size = max(1000, min(50000, $size));
        return ['size' => $size, 'copyright' => $copyright, 'sig' => self::STATE_VERSION . ':' . $size . ':' . ($copyright ? 1 : 0)];
    }

    /** runtime/scale/sitemap.json: v, sig, built, cursor, max_id, since, sweep, pending, started, built_at. */
    public static function bucketState()
    {
        $state = ScaleTier::readJson(ScaleTier::runtime('scale') . 'sitemap.json');
        return is_array($state) ? $state : [];
    }

    /** SQL condition of a published, listable video (published() without the per-group category permission filter). */
    private static function publishedSql($copyright)
    {
        return "vod_status = 1 AND vod_recycle_time = 0 AND vod_pwd = '' AND vod_jumpurl = ''" . ($copyright ? ' AND vod_copyright = 0' : '');
    }

    /**
     * Housekeeping job (D-L9): keeps {pre}sitemap_bucket current on an own connection, without the framework. First build:
     * the whole id range in chunks of BUILD_CHUNK buckets (PK ranges), resumable across runs. Afterwards per run: buckets of
     * new ids (MAX(vod_id) grew), buckets of rows whose vod_time moved since the last run (only when an index on vod_time
     * exists), bucket rows above a lowered MAX(vod_id) removed, then a rotating sweep of old buckets for SWEEP_SECONDS
     * (deletions and hides). Single flight (lock file); stops at $deadline (microtime) and continues on the next run.
     * $opt: now, size / copyright (tests, the small-site inline build), sweep_seconds, chunk (buckets per statement: BUILD_CHUNK
     * on the command line, WEB_CHUNK / INLINE_CHUNK in web slices), lang_rows (translation rows per statement of the
     * per-language lastmod, LANG_ROWS / LANG_ROWS_WEB), pause (share of each statement's time to sleep after it: command-line
     * callers on a shared host, pauseShare()), rows (flood-r4 F3 / G9, default true: keep the buckets' rows lists while their
     * rows are read; false in a visitor request's slice and the small-site inline build).
     * Per-language lastmod (verify r3 R3-8): every rebuilt bucket joins the language queue 'lang_pending' (first in, first
     * out, a bucket already queued keeps its place); langQueue() works it off at the start of a call and again with the time
     * left at its end, bucket by bucket, with one resume position (the bucket at the head), so no window is ever read twice
     * and the queue always converges; no rotating sweep runs while the queue is not empty (a sweep would add buckets whose
     * language part could not finish either). The windows read the translation table's covering index idx_tr_lastmod when it
     * exists (5x the rows per statement), else the rows along its primary key; no statement reads more than lang_rows (x5)
     * translations (verify r2 R2-8).
     * A statement timeout (web slices run under the remaining budget) ends the call like the deadline (state 'partial' /
     * 'building', 'timeout' set); the next run continues where this one stopped.
     * Returns ['state' => 'built'|'updated'|'partial'|'building'|'busy'|'error', 'buckets' => n rebuilt, 'lang_pending' => n,
     * 'lang_done' => n buckets finished, 'lang_index' => bool, 'ms' => ..., ...].
     */
    public static function refreshBuckets(\PDO $pdo, $prefix, $deadline, array $opt = [])
    {
        $t0 = microtime(true);
        if (!preg_match('/^[A-Za-z0-9_]*$/D', (string) $prefix)) { return ['state' => 'error', 'error' => 'invalid prefix']; }
        $lock = @fopen(ScaleTier::runtime('scale') . 'sitemap.lock', 'c');
        if (!$lock) { return ['state' => 'error', 'error' => 'lock file']; }
        if (ScaleTier::lock($lock, LOCK_EX, 0) === false) { @fclose($lock); return ['state' => 'busy']; }
        $stats = ['state' => 'updated', 'buckets' => 0, 'new' => 0, 'moved' => 0, 'swept' => 0, 'removed' => 0, 'lang_done' => 0];
        $chunk = max(1, min(self::BUILD_CHUNK, (int) ($opt['chunk'] ?? self::BUILD_CHUNK)));
        $langRows = max(500, (int) ($opt['lang_rows'] ?? self::LANG_ROWS));
        $pause = max(0.0, min(4.0, (float) ($opt['pause'] ?? 0)));
        $rowsOn = !array_key_exists('rows', $opt) || !empty($opt['rows']);
        $state = null;
        $stateFile = '';
        self::$langTimedOut = false;
        try {
            $cfg = self::bucketConfig($opt);
            $size = $cfg['size'];
            $p = (string) $prefix;
            $now = (int) ($opt['now'] ?? time());
            $stateFile = ScaleTier::runtime('scale') . 'sitemap.json';
            $state = self::bucketState();
            if (($state['sig'] ?? '') !== $cfg['sig'] || (int) ($state['v'] ?? 0) !== self::STATE_VERSION) {
                $pdo->exec('DELETE FROM ' . $p . 'sitemap_bucket');
                $langFile = ScaleTier::runtime('scale') . 'sitemap_lang.json';
                if (is_file($langFile)) { @unlink($langFile); }
                $partFile = ScaleTier::runtime('scale') . 'sitemap_lang_part.json';
                if (is_file($partFile)) { @unlink($partFile); }
                $state = ['v' => self::STATE_VERSION, 'sig' => $cfg['sig'], 'built' => false, 'cursor' => 0, 'max_id' => 0, 'since' => 0,
                    'sweep' => 0, 'pending' => [], 'started' => $now, 'lang_pending' => []];
            }
            $maxId = (int) $pdo->query('SELECT MAX(vod_id) FROM ' . $p . 'vod')->fetchColumn();
            $top = $maxId > 0 ? self::bucketOf($maxId, $size) : -1;
            $lang = self::langTableExists($pdo, $p);
            $langOpt = ['rows' => $langRows, 'pause' => $pause, 'index' => $lang ? self::langIndex($pdo, $p) : ''];
            $stats['lang_index'] = $langOpt['index'] !== '';
            // the language queue first: buckets an earlier run rebuilt, oldest first, the head bucket continued where it stopped;
            // at most half of the time left, so new and changed buckets (the default-language files) are never starved by a
            // language backlog (the queue gets the rest of the time again at the end)
            if ($lang && !empty($state['lang_pending'])) {
                $before = count((array) $state['lang_pending']);
                $half = microtime(true) + max(0.0, ((float) $deadline - microtime(true)) / 2);
                $state['lang_pending'] = self::langQueue($pdo, $p, $size, (array) $state['lang_pending'], $half, $langOpt);
                $stats['lang_done'] += $before - count($state['lang_pending']);
                ScaleTier::writeAtomic($stateFile, json_encode($state));
            }
            if (self::$langTimedOut) {
                // the queue's window ran into the slice's statement limit: the time is spent, the buckets wait for the next run
                $stats['state'] = empty($state['built']) ? 'building' : 'partial';
            } elseif (empty($state['built'])) {
                $cursor = (int) $state['cursor'];
                if ($cursor === 0) { $state['started'] = $now; }
                while ($cursor <= $top && microtime(true) < $deadline) {
                    $end = min($top, $cursor + $chunk - 1);
                    $stats['buckets'] += self::rebuildRange($pdo, $p, $cfg, $cursor, $end, $lang, $deadline, $langRows, $state, $pause, $rowsOn);
                    $cursor = $end + 1;
                    $state['cursor'] = $cursor;
                    ScaleTier::writeAtomic($stateFile, json_encode($state));
                }
                if ($cursor > $top) {
                    // rows changed while building are picked up by the first update run (since = build start)
                    $state['built'] = true;
                    $state['max_id'] = $maxId;
                    $state['since'] = (int) $state['started'];
                    $state['built_at'] = $now;
                    $stats['state'] = 'built';
                } else {
                    $stats['state'] = 'building';
                }
            } else {
                $dirty = [];
                foreach ((array) ($state['pending'] ?? []) as $b) { $dirty[(int) $b] = true; }
                $oldMax = (int) $state['max_id'];
                if ($maxId > $oldMax) {
                    for ($b = $oldMax > 0 ? self::bucketOf($oldMax, $size) : 0; $b <= $top; $b++) { $dirty[$b] = true; $stats['new']++; }
                } elseif ($maxId < $oldMax) {
                    $stats['removed'] = (int) $pdo->exec('DELETE FROM ' . $p . 'sitemap_bucket WHERE bucket > ' . (int) $top);
                    if ($top >= 0) { $dirty[$top] = true; }
                    for ($b = $top + 1; $b <= self::bucketOf($oldMax, $size); $b++) { self::rowsDrop($b); }
                }
                if ((int) $state['since'] > 0 && self::timeIndexExists($pdo, $p)) {
                    $st = $pdo->prepare('SELECT DISTINCT FLOOR((vod_id - 1) / ' . $size . ') AS b FROM ' . $p . 'vod WHERE vod_time >= ? LIMIT 20000');
                    $st->execute([(int) $state['since'] - 1]);
                    foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $b) {
                        if (!isset($dirty[(int) $b])) { $dirty[(int) $b] = true; $stats['moved']++; }
                    }
                }
                ksort($dirty);
                $list = array_keys($dirty);
                $done = 0;
                // the pending list is saved before the slow part: a statement timeout below keeps every unfinished bucket
                $state['pending'] = array_slice($list, 0, 50000);
                $state['max_id'] = $maxId;
                $state['since'] = $now;
                foreach (self::ranges($list, $chunk) as $range) {
                    if (microtime(true) >= $deadline) { break; }
                    $stats['buckets'] += self::rebuildRange($pdo, $p, $cfg, $range[0], $range[1], $lang, $deadline, $langRows, $state, $pause, $rowsOn);
                    $done += $range[1] - $range[0] + 1;
                    $state['pending'] = array_slice($list, $done, 50000);
                }
                // rotating sweep: deletions, hides and edits that kept vod_time are found within hours even at 7 M; never
                // while the language queue is not empty (verify r3 R3-8: each sweep chunk joined the queue and the queue
                // never emptied on a slow host)
                $sweepEnd = min((float) $deadline, microtime(true) + (float) ($opt['sweep_seconds'] ?? self::SWEEP_SECONDS));
                $cursor = (int) ($state['sweep'] ?? 0);
                $sweep = !($lang && !empty($state['lang_pending']));
                if (!$sweep) { $stats['sweep'] = 'after_lang_queue'; }
                // at most one round over all buckets per run (a small site is not re-read again and again)
                while ($sweep && $top >= 0 && $stats['swept'] <= $top && microtime(true) < $sweepEnd) {
                    if ($cursor > $top) { $cursor = 0; }
                    $end = min($top, $cursor + $chunk - 1);
                    $stats['swept'] += self::rebuildRange($pdo, $p, $cfg, $cursor, $end, $lang, $sweepEnd, $langRows, $state, $pause, $rowsOn);
                    $cursor = $end + 1;
                    $state['sweep'] = $cursor;
                }
                $state['sweep'] = $cursor;
                if ($state['pending']) { $stats['state'] = 'partial'; }
            }
            // the language queue again with the time left (the buckets this call rebuilt or swept)
            if ($lang && !empty($state['lang_pending']) && !self::$langTimedOut && microtime(true) < $deadline) {
                ScaleTier::writeAtomic($stateFile, json_encode($state));
                $before = count((array) $state['lang_pending']);
                $state['lang_pending'] = self::langQueue($pdo, $p, $size, (array) $state['lang_pending'], $deadline, $langOpt);
                $stats['lang_done'] += $before - count($state['lang_pending']);
            }
            ScaleTier::writeAtomic($stateFile, json_encode($state));
            if (self::$langTimedOut) {
                $stats['timeout'] = true;
                if ($stats['state'] === 'updated') { $stats['state'] = 'partial'; }
            }
            $stats['top'] = $top;
            $stats['size'] = $size;
            $stats['lang_pending'] = count((array) ($state['lang_pending'] ?? []));
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if (class_exists(__NAMESPACE__ . '\DbTimeout') && DbTimeout::is($e) && is_array($state) && $stateFile !== '') {
                // a web slice's statement limit (the remaining budget): what is done stays done, the rest waits for the next run
                ScaleTier::writeAtomic($stateFile, json_encode($state));
                $stats['state'] = empty($state['built']) ? 'building' : 'partial';
                $stats['timeout'] = true;
                $stats['lang_pending'] = count((array) ($state['lang_pending'] ?? []));
            } else {
                $stats = ['state' => 'error', 'error' => substr((string) preg_replace('/\s+/', ' ', $e->getMessage()), 0, 300)];
                if (class_exists(__NAMESPACE__ . '\StorageGuard') && method_exists(__NAMESPACE__ . '\StorageGuard', 'trip')) {
                    try { StorageGuard::trip($e); } catch (\Throwable $ignored) {}
                }
            }
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
        $stats['ms'] = (int) round((microtime(true) - $t0) * 1000);
        self::$bucketMemo = null;
        return $stats;
    }

    /** Sorted bucket numbers merged into [first, last] ranges of at most $chunk (BUILD_CHUNK) consecutive buckets. */
    public static function ranges(array $buckets, $chunk = self::BUILD_CHUNK)
    {
        $chunk = max(1, (int) $chunk);
        $out = [];
        foreach ($buckets as $b) {
            $b = (int) $b;
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last][1] === $b - 1 && $b - $out[$last][0] < $chunk) { $out[$last][1] = $b; }
            else { $out[] = [$b, $b]; }
        }
        return $out;
    }

    /**
     * Rebuild buckets $a..$b: one PK-range SELECT (a plain read, no row locks on the video table), then one transaction
     * replacing those bucket rows; empty buckets disappear. When the translation table exists the buckets then join the
     * language queue $state['lang_pending'] (verify r3 R3-8: first in, first out, a bucket already queued keeps its place;
     * langQueue() computes their per-language lastmod). $pause: share of the statement's time to sleep after it (command
     * line on a shared host). $rowsOn (flood-r4 F3 / G9): keep the buckets' rows lists: a range with a bucket that has no list
     * reads its rows bucket by bucket (the same PK range; the counts come from the same rows) and writes every list; otherwise
     * the counts as before and a list only for a bucket whose version changed (its pages were just read). Returns the number
     * of buckets looked at.
     */
    private static function rebuildRange(\PDO $pdo, $p, array $cfg, $a, $b, $lang, $deadline = null, $langRows = self::LANG_ROWS, ?array &$state = null, $pause = 0.0, $rowsOn = false)
    {
        $size = (int) $cfg['size'];
        $from = (int) $a * $size + 1;
        $to = ((int) $b + 1) * $size;
        $group = 'FLOOR((vod_id - 1) / ' . $size . ')';
        $rows = null;
        $rowsOn = $rowsOn && !self::$rowsOff;
        if ($rowsOn && self::rowsMissingIn($a, $b, $cfg)) {
            try {
                $rows = [];
                for ($x = (int) $a; $x <= (int) $b; $x++) {
                    $t0 = microtime(true);
                    $r = self::rowsFetch($pdo, $p, $cfg, $x);
                    self::pauseAfter($t0, (float) $pause);
                    self::rowsWrite($x, $cfg, $r);
                    if ($r['c'] > 0) { $rows[] = ['b' => $x, 'm' => $r['m'], 'c' => $r['c']]; }
                }
            } catch (\Throwable $e) {
                if (class_exists(__NAMESPACE__ . '\DbTimeout') && DbTimeout::is($e)) { throw $e; }
                // a table without the columns the lists need (an old or modified schema): the counts as before, no lists
                self::$rowsOff = true;
                $rowsOn = false;
                $rows = null;
            }
        }
        if ($rows === null) {
            $st = $pdo->prepare('SELECT ' . $group . ' AS b, MAX(vod_time) AS m, COUNT(*) AS c FROM ' . $p . 'vod'
                . ' WHERE vod_id BETWEEN ? AND ? AND ' . self::publishedSql($cfg['copyright']) . ' GROUP BY ' . $group);
            $t0 = microtime(true);
            $st->execute([$from, $to]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
            self::pauseAfter($t0, (float) $pause);
            if ($rowsOn) {
                try {
                    $seen = [];
                    foreach ($rows as $row) {
                        $x = (int) $row['b'];
                        $seen[$x] = true;
                        if (!self::rowsHeaderValid(self::rowsHeader($x), $cfg, $x, (int) $row['m'], (int) $row['c'])) {
                            self::rowsWrite($x, $cfg, self::rowsFetch($pdo, $p, $cfg, $x));
                        }
                    }
                    for ($x = (int) $a; $x <= (int) $b; $x++) {
                        if (!isset($seen[$x]) && !self::rowsHeaderValid(self::rowsHeader($x), $cfg, $x, 0, 0)) {
                            self::rowsWrite($x, $cfg, ['m' => 0, 'c' => 0, 'lines' => []]);
                        }
                    }
                } catch (\Throwable $e) {
                    if (class_exists(__NAMESPACE__ . '\DbTimeout') && DbTimeout::is($e)) { throw $e; }
                    self::$rowsOff = true;
                }
            }
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM ' . $p . 'sitemap_bucket WHERE bucket BETWEEN ? AND ?')->execute([(int) $a, (int) $b]);
            if ($rows) {
                $values = [];
                $bind = [];
                foreach ($rows as $row) {
                    $values[] = '(?, ?, ?)';
                    array_push($bind, (int) $row['b'], max(0, (int) $row['m']), (int) $row['c']);
                }
                $pdo->prepare('INSERT INTO ' . $p . 'sitemap_bucket (bucket, max_time, cnt) VALUES ' . implode(',', $values))->execute($bind);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        if ($lang && is_array($state)) {
            // the bucket rows are written; their language lastmod joins the queue (langQueue(), in this call or a later one)
            $state['lang_pending'] = self::queueBuckets((array) ($state['lang_pending'] ?? []), range((int) $a, (int) $b));
        }
        return (int) $b - (int) $a + 1;
    }

    /**
     * Pure (verify r3 R3-8): $queue with $buckets appended, first in first out; a bucket already queued keeps its place; at
     * most LANG_PENDING_MAX entries (the oldest stay: buckets cut off are found again by the rotating sweep).
     */
    public static function queueBuckets(array $queue, array $buckets)
    {
        $out = [];
        $seen = [];
        foreach (array_merge($queue, $buckets) as $b) {
            $b = (int) $b;
            if ($b < 0 || isset($seen[$b])) { continue; }
            $seen[$b] = true;
            $out[] = $b;
            if (count($out) >= self::LANG_PENDING_MAX) { break; }
        }
        return $out;
    }

    /**
     * verify r3 R3-8: the share of each statement's time a command-line sitemap run sleeps after it: PAUSE_ONE_CORE where the
     * site has one CPU core (the visitors' requests share it; app.cpu_cores or the detected cores), else PAUSE_MULTI_CORE
     * (like FtIndex's rebuild throttle: the database is shared with the visitors anyway). Web slices pass 0 (their budget is
     * the limit).
     */
    public static function pauseShare()
    {
        try {
            $cores = (int) (ScaleTier::settings()['cores'] ?? 0);
        } catch (\Throwable $e) {
            $cores = 0;
        }
        return $cores === 1 ? self::PAUSE_ONE_CORE : self::PAUSE_MULTI_CORE;
    }

    /** Sleep $share x the time since $t0 (at most 2 s); nothing when $share is 0. */
    private static function pauseAfter($t0, $share)
    {
        $share = (float) $share;
        if ($share <= 0) { return; }
        $spent = microtime(true) - (float) $t0;
        if ($spent > 0) { usleep((int) min(2000000, $spent * $share * 1000000)); }
    }

    /**
     * Name of the translation table's covering index (an index on exactly vod_id, updated_at: ScaleSchema::TRANSLATION_INDEXES),
     * '' when there is none (the windows then read the rows along the primary key). One information_schema read per call.
     */
    private static function langIndex(\PDO $pdo, $p)
    {
        try {
            $st = $pdo->prepare('SELECT INDEX_NAME AS n, COLUMN_NAME AS c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
            $st->execute([$p . 'avdb_translation']);
            $have = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) { $have[(string) $row['n']][] = strtolower((string) $row['c']); }
            foreach ($have as $name => $cols) {
                if ($cols === ['vod_id', 'updated_at'] && preg_match('/^[A-Za-z0-9_]{1,64}$/D', (string) $name)) { return (string) $name; }
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    /**
     * Per-language lastmod (newest avdb_translation.updated_at per locale) of the queued buckets into sitemap_lang.json,
     * verify r3 R3-8: the queue is worked off strictly from its head, one bucket at a time, in vod_id windows of about
     * $o['rows'] translations (rows / languages per video; x LANG_INDEX_FACTOR when $o['index'] names the covering index
     * idx_tr_lastmod: the window then reads only that index, FORCE INDEX). Every statement is short (verify r2 R2-8) and
     * $deadline (microtime, null = none) is checked between them; every call reads at least one window, so each call
     * progresses. One resume position: when the deadline stops the head bucket, its read position and what was found so far
     * stay in runtime/scale/sitemap_lang_part.json and the next call continues that bucket first (no window is read twice;
     * in round 3 a single position shared by 20-bucket ranges was replaced whenever another range came first, and a 1-core
     * MySQL 5.7 host never finished). Finished buckets replace their old values in sitemap_lang.json every LANG_FLUSH
     * buckets and at the end (the data file is written before the caller writes the shorter queue: a crash repeats work,
     * it never loses it). $o['pause']: share of each window's time to sleep after it (command line on a shared host).
     * Returns the queue left (the buckets not finished, head first).
     */
    private static function langQueue(\PDO $pdo, $p, $size, array $queue, $deadline, array $o = [])
    {
        $size = (int) $size;
        $queue = self::queueBuckets($queue, []);
        if (!$queue) { return []; }
        $file = ScaleTier::runtime('scale') . 'sitemap_lang.json';
        $partFile = ScaleTier::runtime('scale') . 'sitemap_lang_part.json';
        $data = ScaleTier::readJson($file);
        if (!is_array($data) || (int) ($data['size'] ?? 0) !== $size || !isset($data['l']) || !is_array($data['l'])) { $data = ['size' => $size, 'l' => []]; }
        // languages per video: the codes seen so far (at least 1, at most 64; 17 before the first measurement)
        $codes = count($data['l']);
        $perVideo = $codes > 0 ? min(64, $codes + 1) : 17;
        $index = (string) ($o['index'] ?? '');
        $rows = max(500, (int) ($o['rows'] ?? self::LANG_ROWS)) * ($index !== '' ? self::LANG_INDEX_FACTOR : 1);
        $window = max(1, intdiv($rows, $perVideo));
        $pause = (float) ($o['pause'] ?? 0);
        $st = $pdo->prepare('SELECT locale, MAX(updated_at) AS m FROM ' . $p . 'avdb_translation'
            . ($index !== '' ? ' FORCE INDEX (`' . $index . '`)' : '') . ' WHERE vod_id BETWEEN ? AND ? GROUP BY locale');
        $part = ScaleTier::readJson($partFile);
        if (!is_array($part) || (int) ($part['v'] ?? 0) !== 2 || (int) ($part['size'] ?? 0) !== $size || !isset($part['found']) || !is_array($part['found'])) {
            $part = null;
        }
        $results = [];
        $flush = function () use (&$results, &$data, $file, $size) {
            if (!$results) { return; }
            // read again (the file is written by this job only, under the sitemap lock; the reread keeps other buckets as stored)
            $fresh = ScaleTier::readJson($file);
            if (is_array($fresh) && (int) ($fresh['size'] ?? 0) === $size && isset($fresh['l']) && is_array($fresh['l'])) { $data = $fresh; }
            foreach ($results as $bucket => $found) {
                foreach ($data['l'] as $code => $map) {
                    if (is_array($map) && array_key_exists($bucket, $map)) { unset($data['l'][$code][$bucket]); }
                    if (empty($data['l'][$code])) { unset($data['l'][$code]); }
                }
                foreach ($found as $code => $m) { $data['l'][$code][$bucket] = (int) $m; }
            }
            ScaleTier::writeAtomic($file, json_encode($data));
            $results = [];
        };
        $first = true;
        while ($queue) {
            $bucket = (int) $queue[0];
            $from = $bucket * $size + 1;
            $to = ($bucket + 1) * $size;
            $lo = $from;
            $found = [];
            if ($part !== null && (int) ($part['bucket'] ?? -1) === $bucket && (int) ($part['lo'] ?? 0) > $from && (int) $part['lo'] <= $to + 1) {
                $lo = (int) $part['lo'];
                foreach ($part['found'] as $code => $m) { $found[(string) $code] = (int) $m; }
            }
            $part = null;   // only the head bucket can be partial: a position of another bucket is stale
            for (; $lo <= $to; $lo += $window) {
                if (!$first && $deadline !== null && microtime(true) >= (float) $deadline) {
                    $flush();
                    ScaleTier::writeAtomic($partFile, json_encode(['v' => 2, 'size' => $size, 'bucket' => $bucket, 'lo' => $lo, 'found' => (object) $found, 'at' => time()]));
                    return $queue;
                }
                $first = false;
                $t0 = microtime(true);
                try {
                    $st->execute([$lo, min($to, $lo + $window - 1)]);
                } catch (\Throwable $e) {
                    // a web slice's statement limit (the remaining budget): what is finished is written, the head bucket keeps
                    // its position, the next run continues here (any other error goes up to the caller)
                    if (!(class_exists(__NAMESPACE__ . '\DbTimeout') && DbTimeout::is($e))) { throw $e; }
                    $flush();
                    ScaleTier::writeAtomic($partFile, json_encode(['v' => 2, 'size' => $size, 'bucket' => $bucket, 'lo' => $lo, 'found' => (object) $found, 'at' => time()]));
                    self::$langTimedOut = true;
                    return $queue;
                }
                foreach ($st->fetchAll(\PDO::FETCH_NUM) as $row) {
                    $code = (string) $row[0];
                    if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D', $code)) { continue; }
                    $found[$code] = max((int) ($found[$code] ?? 0), (int) $row[1]);
                }
                self::pauseAfter($t0, $pause);
            }
            $results[$bucket] = $found;
            array_shift($queue);
            if (count($results) >= self::LANG_FLUSH) { $flush(); }
        }
        $flush();
        if (is_file($partFile)) { @unlink($partFile); }
        return [];
    }

    private static function langTableExists(\PDO $pdo, $p)
    {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $st->execute([$p . 'avdb_translation']);
        return (bool) $st->fetchColumn();
    }

    /** An index whose first column is vod_time (stock `vod_time`, kept by the slim set): the moved-rows scan is a range read. */
    private static function timeIndexExists(\PDO $pdo, $p)
    {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'vod_time' AND SEQ_IN_INDEX = 1 LIMIT 1");
        $st->execute([$p . 'vod']);
        return (bool) $st->fetchColumn();
    }

    /** Test hook: forget per-request memos. */
    public static function resetMemo()
    {
        self::$rowsOff = false;
        self::$bucketMemo = null;
        self::$langMemo = null;
        self::$feedBase = [];
        self::$urlTemplates = [];
        self::$absBase = [];
        self::$httpsMemo = [];
        self::$types = [];
        self::$categoryMetadata = null;
    }

    // ------------------------------------------------------------------ page variants (TemplateSeo heads use these too)

    /** Catalog locales also work during admin static generation, without changing UI language. */
    private static function browsingLocales()
    {
        // Interface pages are translated offline even before movies are imported.
        // Video alternatives still use only real per-video metadata rows.
        if (self::$pageLocales === null) {
            self::$pageLocales = array_keys(TemplateText::languages());
            sort(self::$pageLocales, SORT_STRING);
        }
        return self::$pageLocales;
    }

    /** Localized browsing pages use the supported, translated interface languages. */
    public static function pageVariants($base, array $locales, $localizedBase = null)
    {
        $localizedBase = $localizedBase === null ? $base : $localizedBase;
        $variants = self::variants($base, [], $localizedBase);
        if (!$variants) { return []; }
        foreach ($locales as $locale) {
            if (!is_string($locale) || isset($variants[$locale]) || !isset(AvdbLanguage::languages()[$locale])) { continue; }
            $variants[$locale] = ['url' => $localizedBase . (strpos($localizedBase, '?') === false ? '?' : '&') . 'ui_lang=' . $locale, 'updated_at' => 0];
        }
        return $variants;
    }

    public static function homeVariants()
    {
        $root = defined('MAC_PATH') ? MAC_PATH : '/';
        $base = self::absolute($root);
        $localized = $base;
        if ((int) ($GLOBALS['config']['view']['index'] ?? 1) === 2) {
            $localized = self::absolute($root . 'index.php');
        }
        return self::pageVariants($base, self::browsingLocales(), $localized);
    }

    public static function categoryVariants(array $type, $page = 1)
    {
        $page = max(1, (int) $page);
        $metadata = self::categoryMetadata();
        // The static URL builder requires parent metadata as well as the category.
        $type['type'] = $type;
        $type['type_1'] = $metadata[(int) ($type['type_pid'] ?? 0)] ?? ['type_id'=>0,'type_en'=>''];
        $had = array_key_exists('translang', $_GET); $language = $_GET['translang'] ?? null;
        unset($_GET['translang']);
        $keys = [1=>'vod_type',2=>'art_type',8=>'actor_type'];
        $viewKey = $keys[(int) ($type['type_mid'] ?? 1)] ?? 'vod_type';
        $hadView = array_key_exists($viewKey, $GLOBALS['config']['view']);
        $before = $GLOBALS['config']['view'][$viewKey] ?? 1;
        try {
            $base = self::absolute(mac_url_type($type, ['page'=>$page]));
            $localized = $base;
            if ((int) $before === 2) {
                $GLOBALS['config']['view'][$viewKey] = 1;
                $localized = self::absolute(mac_url_type($type, ['page'=>$page]));
            }
            return self::pageVariants($base, self::browsingLocales(), $localized);
        } finally {
            if ($hadView) { $GLOBALS['config']['view'][$viewKey] = $before; }
            else { unset($GLOBALS['config']['view'][$viewKey]); }
            if ($had) { $_GET['translang'] = $language; }
        }
    }

    /** Label routes require an actual label/<file>.html in each applicable public template. */
    private static function pageTemplateAvailability()
    {
        $site = $GLOBALS['config']['site'] ?? [];
        $roots = [];
        $effective = $GLOBALS['MAC_ROOT_TEMPLATE'] ?? (defined('MAC_ROOT_TEMPLATE') ? MAC_ROOT_TEMPLATE : '');
        if (is_string($effective) && $effective !== '') { $roots[] = $effective; }
        if (defined('ROOT_PATH')) {
            $roots[] = ROOT_PATH . 'template/' . ($site['template_dir'] ?? '') . '/' . ($site['html_dir'] ?? 'html') . '/';
            $domains = config('domain');
            // Init applies a domain-specific desktop template and disables UA switching on that host.
            $mappedHost = is_array($domains) && !empty($domains[$_SERVER['HTTP_HOST'] ?? '']);
            $mobileStatus = (int) ($site['mob_status'] ?? 0);
            $sameHostMobile = $mobileStatus === 2 || ($mobileStatus === 1
                && ($site['site_wapurl'] ?? '') !== '' && ($site['site_wapurl'] ?? '') === ($site['site_url'] ?? ''));
            if (!$mappedHost && $sameHostMobile) {
                $roots[] = ROOT_PATH . 'template/' . ($site['mob_template_dir'] ?? '') . '/' . ($site['mob_html_dir'] ?? 'html') . '/';
            }
        }
        $roots = array_values(array_unique(array_map(function ($root) { return rtrim(str_replace('\\', '/', $root), '/') . '/'; }, $roots)));
        sort($roots, SORT_STRING);
        $availability = [];
        foreach ($roots as $root) {
            $files = [];
            foreach (['new', 'hot', 'sort', 'up', 'score'] as $file) { $files[$file] = is_file($root . 'label/' . $file . '.html'); }
            $availability[$root] = $files;
        }
        return $availability;
    }
}
