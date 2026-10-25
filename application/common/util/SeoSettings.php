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

/**
 * Site / SEO settings reader (SEO-ADMIN-BRIEF section A, C.1).
 *
 * Every reader of a site.* / seo.* key of SEO-ADMIN-BRIEF section A goes through get(): the built-in DEFAULTS below
 * apply whenever application/extra/maccms.php has no such key, so a fresh install needs no new key in the shipped file.
 * A stored value that fails its rule is ignored (the default is used), so a hand-edited file never breaks a page.
 *
 * Escaping contract (CONTRACT-FIELDS.md): maccms.php text values are HTML-encoded once by the admin form
 * (input('','','htmlentities')); get() decodes text values ONCE and returns plain text. Code fields (site_head_code)
 * are stored decoded and returned raw. Data files (seo/custom.php, site/legal/*.json) hold decoded text.
 * Callers escape once when they print.
 */
class SeoSettings
{
    /** The 17 interface languages, in menu order (AvdbLanguage::languages()). */
    const LOCALES = ['en','vi','th','id','ko','zh','ja','fr','de','es','pt','ru','ar','tr','hi','ms','tl'];
    const LEGAL_SLUGS = ['terms','privacy','dmca','2257','cookies'];
    const TITLE_SEPARATORS = [' - ', ' | ', " \u{2013} ", " \u{00B7} "];

    /** Built-in defaults (what a fresh install does with no setup). */
    const DEFAULTS = [
        'site' => [
            'site_home_title' => '',
            'site_icon_mode' => 'default',
            'site_icon' => '',
            'site_theme_color' => '',
            'site_share_image' => '',
            'site_share_w' => 0,
            'site_share_h' => 0,
            'site_social' => '',
            'site_ga4' => '',
            'site_head_code' => '',
            'site_code_consent' => 0,
            'site_icon_root_md5' => '',
            'site_manifest' => '',
            'lang_default' => 'en',
            'lang_offered' => '',
            'lang_indexed' => '',
            'lang_home' => [],
            'legal_pages' => 'terms,privacy,dmca,cookies',
            'legal_operator' => '',
            'legal_dmca_agent' => '',
            'legal_dmca_address' => '',
            'legal_custodian' => '',
            'legal_custodian_address' => '',
            'age_gate' => 0,
            'age_gate_days' => 30,
            'age_gate_leave' => 'https://www.google.com/',
            'cookie_notice' => 'auto',
        ],
        'seo' => [
            'title_brand' => 'end',
            'title_sep' => ' - ',
            'tpl_video' => '',
            'tpl_category' => '',
            'tpl_list' => '',
            'tpl_tag' => '',
            'tpl_actor' => '',
            'dtpl_video' => '',
            'hide_site' => 0,
            'landing' => 1,
            'landing_min' => 10,
            'noindex_after' => 0,
            'verify_google' => '',
            'verify_bing' => '',
            'verify_yandex' => '',
            'verify_naver' => '',
            'rta' => 1,
            'robots_mode' => 'recommended',
            'robots_custom' => '',
            'robots_ai' => 1,
            'sitemap_langs' => 1,
            'sitemap_video' => 1,
            'sitemap_per_file' => 5000,
            'indexnow' => 0,
            'indexnow_key' => '',
            'indexnow_since' => 0,
            'indexnow_daily' => 10000,
        ],
    ];

    /**
     * Rule per key: [type, ...options]. Types: text(max), code(max), bool, int(min,max[,zeroAllowed]), enum(list),
     * csv(list), regex(pattern,max), lines(max lines, line pattern, max per line), langhome, pattern(kind), verify, robots.
     */
    private static function rules()
    {
        return [
            'site.site_home_title' => ['text', 70],
            'site.site_icon_mode' => ['enum', ['default', 'custom', 'theme']],
            'site.site_icon' => ['regex', '~^upload/site/icon/[0-9a-f]{10}/$~D', 60],
            'site.site_theme_color' => ['regex', '~^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$~D', 7],
            'site.site_share_image' => ['regex', '~^upload/site/share/[0-9a-f]{10}\.(?:jpg|png)$~D', 60],
            'site.site_share_w' => ['int', 0, 10000],
            'site.site_share_h' => ['int', 0, 10000],
            'site.site_social' => ['lines', 10, '~^https://[^\s<>"\'\\\\]+$~D', 200],
            'site.site_ga4' => ['regex', '~^G-[A-Z0-9]{4,12}$~D', 14],
            'site.site_head_code' => ['code', 20000],
            'site.site_code_consent' => ['bool'],
            'site.site_icon_root_md5' => ['regex', '~^[0-9a-f]{32}$~D', 32],
            'site.site_manifest' => ['regex', '~^upload/site/manifest-[0-9a-f]{8}\.json$~D', 60],
            'site.lang_default' => ['enum', self::LOCALES],
            'site.lang_offered' => ['csv', self::LOCALES],
            'site.lang_indexed' => ['csv', self::LOCALES],
            'site.lang_home' => ['langhome'],
            'site.legal_pages' => ['csv', self::LEGAL_SLUGS],
            'site.legal_operator' => ['text', 120],
            'site.legal_dmca_agent' => ['text', 120],
            'site.legal_dmca_address' => ['text', 300, true],
            'site.legal_custodian' => ['text', 120],
            'site.legal_custodian_address' => ['text', 300, true],
            'site.age_gate' => ['bool'],
            'site.age_gate_days' => ['int', 1, 365],
            'site.age_gate_leave' => ['regex', '~^https?://[^\s<>"\'\\\\]+$~Di', 300],
            'site.cookie_notice' => ['enum', ['auto', 'on', 'off']],
            'seo.title_brand' => ['enum', ['end', 'none']],
            'seo.title_sep' => ['enum', self::TITLE_SEPARATORS],
            'seo.tpl_video' => ['pattern', 'video'],
            'seo.tpl_category' => ['pattern', 'category'],
            'seo.tpl_list' => ['pattern', 'list'],
            'seo.tpl_tag' => ['pattern', 'tag'],
            'seo.tpl_actor' => ['pattern', 'actor'],
            'seo.dtpl_video' => ['pattern', 'video_desc'],
            'seo.hide_site' => ['bool'],
            'seo.landing' => ['bool'],
            'seo.landing_min' => ['int', 3, 100],
            'seo.noindex_after' => ['int', 2, 500, true],
            'seo.verify_google' => ['verify'],
            'seo.verify_bing' => ['verify'],
            'seo.verify_yandex' => ['verify'],
            'seo.verify_naver' => ['verify'],
            'seo.rta' => ['bool'],
            'seo.robots_mode' => ['enum', ['recommended', 'open', 'custom']],
            'seo.robots_custom' => ['robots'],
            'seo.robots_ai' => ['bool'],
            'seo.sitemap_langs' => ['bool'],
            'seo.sitemap_video' => ['bool'],
            'seo.sitemap_per_file' => ['int', 1000, 50000],
            'seo.indexnow' => ['bool'],
            'seo.indexnow_key' => ['regex', '~^[a-f0-9]{32}$~D', 32],
            'seo.indexnow_since' => ['int', 0, 4102444800],
            'seo.indexnow_daily' => ['int', 100, 10000],
        ];
    }

    /** Placeholders allowed per pattern kind (C.2). */
    const TOKENS = [
        'video' => ['site', 'sep', 'title', 'code', 'actors', 'studio', 'category', 'year'],
        'video_desc' => ['site', 'sep', 'title', 'code', 'actors', 'studio', 'category', 'year', 'duration'],
        'category' => ['site', 'sep', 'page', 'category'],
        'list' => ['site', 'sep', 'page', 'list'],
        'tag' => ['site', 'sep', 'page', 'tag'],
        'actor' => ['site', 'sep', 'page', 'actor'],
        'home_paged' => ['site', 'sep', 'page', 'latest'],
        'tag_desc' => ['site', 'sep', 'tag', 'count'],
        'actor_desc' => ['site', 'sep', 'actor', 'count'],
    ];

    /** robots.txt user agents whose whole-site block needs the typed BLOCK (E.2): every crawler or a big search engine. */
    const ROBOTS_ENGINES = ['*', 'googlebot', 'bingbot', 'msnbot', 'yandex', 'yandexbot', 'duckduckbot', 'slurp', 'applebot', 'naverbot', 'yeti'];

    /** Temporary values that win over the stored ones (admin previews of unsaved form values). */
    private static $overrides = [];

    // ------------------------------------------------------------------ reading

    /** Stored settings array of a section: the request's (domain binding applied) on the front, else config('maccms'). */
    private static function section($section)
    {
        if (isset($GLOBALS['config'][$section]) && is_array($GLOBALS['config'][$section])) { return $GLOBALS['config'][$section]; }
        try {
            $all = config('maccms');
        } catch (\Throwable $error) {
            $all = [];
        }
        return is_array($all) && isset($all[$section]) && is_array($all[$section]) ? $all[$section] : [];
    }

    /** True when $path ('site.key' / 'seo.key') is one of the keys this class knows. */
    public static function known($path)
    {
        if (!is_string($path) || strpos($path, '.') === false) { return false; }
        list($section, $key) = explode('.', $path, 2);
        return isset(self::DEFAULTS[$section]) && array_key_exists($key, self::DEFAULTS[$section]);
    }

    /** Built-in default of a key (null for an unknown key). */
    public static function defaultValue($path)
    {
        if (!self::known($path)) { return null; }
        list($section, $key) = explode('.', $path, 2);
        return self::DEFAULTS[$section][$key];
    }

    /**
     * Effective value of a section A key: the stored value when it passes its rule, else the built-in default.
     * Text values come back decoded once (plain text); code values raw; lists as normalised CSV strings.
     */
    public static function get($path)
    {
        if (!self::known($path)) { return null; }
        if (array_key_exists($path, self::$overrides)) {
            $result = self::validate($path, self::$overrides[$path], false); // decoded admin input
            return $result['ok'] ? $result['value'] : self::defaultValue($path);
        }
        list($section, $key) = explode('.', $path, 2);
        $stored = self::section($section);
        // '' = "from the catalog size" for seo.noindex_after (HP-H-4 ships it so; the admin form saves a number)
        if (!array_key_exists($key, $stored) || ($path === 'seo.noindex_after' && $stored[$key] === '')) { return self::tierDefault($path); }
        $result = self::validate($path, $stored[$key], true);
        return $result['ok'] ? $result['value'] : self::tierDefault($path);
    }

    /**
     * True when application/extra/maccms.php holds a value for $path that passes its rule (missing, '' or invalid = not
     * stored: the built-in or size-dependent default applies). Sitemap::perFile() uses it (10,000 above 5 M videos).
     */
    public static function isStored($path)
    {
        if (!self::known($path)) { return false; }
        list($section, $key) = explode('.', $path, 2);
        $stored = self::section($section);
        if (!array_key_exists($key, $stored) || $stored[$key] === '' || $stored[$key] === null) { return false; }
        return self::validate($path, $stored[$key], true)['ok'];
    }

    /**
     * Default when nothing valid is stored. seo.noindex_after follows the catalog size (XVID-SCALE-BRIEF L9, HP-H-4 stores
     * ''): 50 from 200,000 videos, else 0 (all list pages indexable), as ScaleTier computes it. Every other key: DEFAULTS.
     */
    private static function tierDefault($path)
    {
        if ($path === 'seo.noindex_after' && class_exists(__NAMESPACE__ . '\ScaleTier')) {
            try {
                $tier = ScaleTier::info();
                if (isset($tier['noindex_after']) && (int)$tier['noindex_after'] >= 0) { return (int)$tier['noindex_after']; }
            } catch (\Throwable $error) {
                // tier unknown: the built-in default
            }
        }
        return self::defaultValue($path);
    }

    public static function int($path) { return (int)self::get($path); }

    public static function bool($path) { return (int)self::get($path) === 1; }

    /** A CSV key as a list (order of the allowed values). */
    public static function csv($path)
    {
        $value = (string)self::get($path);
        return $value === '' ? [] : explode(',', $value);
    }

    /**
     * Run $fn with temporary values (['seo.title_sep' => ' | ', ...]) that win over the stored ones; used by
     * TemplateSeo::preview() for unsaved admin form values. Values pass the same rules (decoded input).
     */
    public static function withOverrides(array $values, callable $fn)
    {
        $before = self::$overrides;
        foreach ($values as $path => $value) {
            if (self::known($path)) { self::$overrides[$path] = $value; }
        }
        try {
            return $fn();
        } finally {
            self::$overrides = $before;
        }
    }

    /** Home page title and description for one language (lang_home row), decoded; '' when not set. */
    public static function langHome($locale)
    {
        $rows = self::get('site.lang_home');
        $row = is_array($rows) && is_string($locale) && isset($rows[$locale]) && is_array($rows[$locale]) ? $rows[$locale] : [];
        return ['title' => (string)($row['title'] ?? ''), 'desc' => (string)($row['desc'] ?? '')];
    }

    /** Enabled legal page slugs (C.2 legal branch, F.1). */
    public static function legalPages()
    {
        return self::csv('site.legal_pages');
    }

    // ------------------------------------------------------------------ validation (admin and reader)

    /** Decode a stored / posted text once (maccms.php values are HTML-encoded once by the admin form). */
    public static function decode($value)
    {
        return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** One line of plain text: control characters removed, spaces collapsed, trimmed. */
    private static function plain($value, $multiline = false)
    {
        $value = (string)$value;
        if (!preg_match('//u', $value)) { $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8'); }
        if ($multiline) {
            $value = str_replace(["\r\n", "\r"], "\n", $value);
            $value = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $value);
            $value = preg_replace('/[ \t]+/u', ' ', $value);
            $value = preg_replace("/\n{3,}/", "\n\n", $value);
            return trim($value);
        }
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private static function length($value)
    {
        return function_exists('mb_strlen') ? mb_strlen((string)$value, 'UTF-8') : strlen((string)$value);
    }

    /**
     * Check one value against the rule of its key.
     * $value: for the admin the POSTED value DECODED ONCE (html_entity_decode of the input('','','htmlentities') value);
     * $stored = true: $value is the raw maccms.php value (text gets decoded here).
     * Returns ['ok' => bool, 'value' => normalised plain value, 'store' => value to write into maccms.php
     * (text HTML-encoded once like the admin form does, everything else as is), 'error' => '' | reason key].
     */
    public static function validate($path, $value, $stored = false)
    {
        $rules = self::rules();
        if (!isset($rules[$path])) { return ['ok' => false, 'value' => null, 'store' => null, 'error' => 'unknown_key']; }
        $rule = $rules[$path];
        $type = $rule[0];
        $bad = function ($error) { return ['ok' => false, 'value' => null, 'store' => null, 'error' => $error]; };
        $good = function ($plain, $store = null) { return ['ok' => true, 'value' => $plain, 'store' => $store === null ? $plain : $store]; };
        $encode = function ($plain) { return htmlentities((string)$plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
        if (is_array($value) && $type !== 'langhome') { return $bad('type'); }
        if ($type !== 'langhome' && !is_scalar($value) && $value !== null) { return $bad('type'); }
        switch ($type) {
            case 'text':
                $text = self::plain($stored ? self::decode($value) : $value, !empty($rule[2]));
                if (self::length($text) > $rule[1]) { return $bad('too_long'); }
                return $good($text, $encode($text));
            case 'code':
                $code = str_replace("\0", '', (string)$value);
                if (strlen($code) > $rule[1]) { return $bad('too_long'); }
                return $good($code);
            case 'bool':
                $v = (string)$value;
                if (!in_array($v, ['0', '1', ''], true) && !is_bool($value)) { return $bad('bool'); }
                return $good((int)($v === '1' || $value === true));
            case 'int':
                $v = trim((string)$value);
                if (!preg_match('/^\d{1,10}$/D', $v)) { return $bad('number'); }
                $n = (int)$v;
                if (!empty($rule[3]) && $n === 0) { return $good(0); }
                if ($n < $rule[1] || $n > $rule[2]) { return $bad('range'); }
                return $good($n);
            case 'enum':
                $v = $stored ? self::decode($value) : (string)$value;
                if (!in_array($v, $rule[1], true)) {
                    // Separators: an admin field may trim the spaces around the character.
                    if ($path === 'seo.title_sep') {
                        foreach ($rule[1] as $choice) { if (trim($choice) === trim($v)) { return $good($choice, $encode($choice)); } }
                    }
                    return $bad('choice');
                }
                return $good($v, $path === 'seo.title_sep' ? $encode($v) : $v);
            case 'csv':
                $parts = array_filter(array_map('trim', explode(',', (string)$value)), 'strlen');
                foreach ($parts as $part) { if (!in_array($part, $rule[1], true)) { return $bad('choice'); } }
                $list = array_values(array_intersect($rule[1], $parts)); // allowed order, duplicates dropped
                return $good(implode(',', $list));
            case 'regex':
                $v = trim($stored ? self::decode($value) : (string)$value);
                if ($v === '') { return $good(''); }
                if (strlen($v) > $rule[2] || !preg_match($rule[1], $v)) { return $bad('format'); }
                return $good($v, $path === 'site.age_gate_leave' ? $encode($v) : $v);
            case 'lines':
                $text = str_replace(["\r\n", "\r"], "\n", $stored ? self::decode($value) : (string)$value);
                $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), 'strlen'));
                if (count($lines) > $rule[1]) { return $bad('too_many'); }
                foreach ($lines as $line) {
                    if (strlen($line) > $rule[3] || !preg_match($rule[2], $line) || filter_var($line, FILTER_VALIDATE_URL) === false) { return $bad('format'); }
                }
                $plain = implode("\n", $lines);
                return $good($plain, $encode($plain));
            case 'langhome':
                if ($value === '' || $value === null) { return $good([]); }
                if (!is_array($value)) { return $bad('type'); }
                $rows = [];
                foreach ($value as $locale => $row) {
                    if (!in_array($locale, self::LOCALES, true) || !is_array($row)) { return $bad('choice'); }
                    $title = self::plain($stored ? self::decode($row['title'] ?? '') : (string)($row['title'] ?? ''));
                    $desc = self::plain($stored ? self::decode($row['desc'] ?? '') : (string)($row['desc'] ?? ''));
                    if (self::length($title) > 70 || self::length($desc) > 300) { return $bad('too_long'); }
                    if ($title === '' && $desc === '') { continue; }
                    $rows[$locale] = ['title' => $title, 'desc' => $desc];
                }
                $store = [];
                foreach ($rows as $locale => $row) { $store[$locale] = ['title' => $encode($row['title']), 'desc' => $encode($row['desc'])]; }
                return $good($rows, $store);
            case 'pattern':
                $check = self::validTitlePattern($stored ? self::decode($value) : (string)$value, $rule[1]);
                if (!$check['ok']) { return $bad($check['error']); }
                return $good($check['value'], $encode($check['value']));
            case 'verify':
                $code = self::verificationCode($stored ? self::decode($value) : (string)$value);
                if ($code === null) { return $bad('verify'); }
                return $good($code);
            case 'robots':
                $check = self::robotsCustom((string)$value); // stored decoded (decoded once on save)
                if (!$check['ok']) { return $bad($check['error']); }
                return $good($check['value']);
        }
        return $bad('type');
    }

    /** Placeholders a pattern kind may use (admin token chips). */
    public static function tokens($kind)
    {
        return self::TOKENS[$kind] ?? [];
    }

    /**
     * Title / description pattern check (A.5.1): at most 120 characters (descriptions 300), only the placeholders of
     * its kind. Returns ['ok', 'value' (trimmed plain text), 'error' ('' | too_long | unknown_token), 'unknown' => [..]].
     */
    public static function validTitlePattern($pattern, $kind)
    {
        $value = self::plain((string)$pattern);
        $allowed = self::TOKENS[$kind] ?? null;
        if ($allowed === null) { return ['ok' => false, 'value' => '', 'error' => 'unknown_kind', 'unknown' => []]; }
        $max = substr($kind, -5) === '_desc' ? 300 : 120;
        if (self::length($value) > $max) { return ['ok' => false, 'value' => '', 'error' => 'too_long', 'unknown' => []]; }
        $unknown = [];
        if (preg_match_all('/\{([^{}]*)\}/u', $value, $m)) {
            foreach ($m[1] as $name) { if (!in_array($name, $allowed, true)) { $unknown[] = '{' . $name . '}'; } }
        }
        if ($unknown) { return ['ok' => false, 'value' => '', 'error' => 'unknown_token', 'unknown' => array_values(array_unique($unknown))]; }
        return ['ok' => true, 'value' => $value, 'error' => '', 'unknown' => []];
    }

    /**
     * Verification code (A.5.3): the bare code or the whole <meta ... content="..."> tag pasted by the owner.
     * Returns the code, '' for an empty field, or null when it does not look like a verification code.
     */
    public static function verificationCode($input)
    {
        $value = trim((string)$input);
        if ($value === '') { return ''; }
        if (stripos($value, '<meta') !== false) {
            if (!preg_match('~\scontent\s*=\s*(["\'])(.*?)\1~is', $value, $m)) { return null; }
            $value = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return preg_match('~^[A-Za-z0-9_\-:.=+/]{4,128}$~D', $value) ? $value : null;
    }

    /**
     * Custom robots.txt rules (E.2): at most 64 KB, only rule lines, comments and blank lines.
     * Returns ['ok', 'value' (LF lines, trimmed), 'error' ('' | too_long | line), 'line' (1-based bad line, 0),
     * 'blocks_all' (a "Disallow: /" or "Disallow: /*" in a group for "*" or a big engine (ROBOTS_ENGINES): the admin
     * asks for a typed BLOCK confirm)].
     */
    public static function robotsCustom($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
        if (strlen($text) > 65536) { return ['ok' => false, 'value' => '', 'error' => 'too_long', 'line' => 0, 'blocks_all' => false]; }
        $lines = [];
        $agents = [];
        $inRules = false;
        $blocksAll = false;
        foreach (explode("\n", $text) as $number => $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $line)) {
                return ['ok' => false, 'value' => '', 'error' => 'line', 'line' => $number + 1, 'blocks_all' => false];
            }
            if ($line === '' || $line[0] === '#') { $lines[] = $line; continue; }
            if (!preg_match('/^(User-agent|Allow|Disallow|Sitemap|Crawl-delay|Clean-param|Host)\s*:\s*(.*)$/i', $line, $m)) {
                return ['ok' => false, 'value' => '', 'error' => 'line', 'line' => $number + 1, 'blocks_all' => false];
            }
            $field = strtolower($m[1]);
            $rest = trim(preg_replace('/#.*$/', '', $m[2])); // crawlers end a rule at any "#"
            if ($field === 'user-agent') {
                if ($inRules) { $agents = []; $inRules = false; }
                $agents[] = $rest;
            } elseif ($field === 'allow' || $field === 'disallow') {
                $inRules = true;
                // "Disallow: /", "/*", "/*$" (any number of *) block every address; for all crawlers or one big engine.
                if ($field === 'disallow' && preg_match('~^/\*{0,8}\$?$~D', $rest) && $rest !== '/$'
                    && array_intersect(array_map('strtolower', $agents), self::ROBOTS_ENGINES)) { $blocksAll = true; }
            }
            $lines[] = $line;
        }
        return ['ok' => true, 'value' => trim(implode("\n", $lines)), 'error' => '', 'line' => 0, 'blocks_all' => $blocksAll];
    }

    // ------------------------------------------------------------------ admin save helper (CF06)

    /**
     * Site identity save guard (A.10 "Save semantics", CF06): System::config() replaces the whole `site` array with the
     * posted one. This copies EVERY stored site key that the post does not contain, except
     *  - the visitor theme switcher keys (ThemeSwitcher::adminSave() rebuilt them and returns the final ones),
     *  - keys named in $switcherDropped (dropped by the switcher save),
     *  - site_icon_root_md5 / site_manifest (recomputed by this save, B.1 steps 6-7),
     *  - keys in $formOwned: fields of this form that a browser may legitimately leave out (an unticked checkbox).
     */
    public static function keepSiteKeys(array $posted, array $stored, array $switcherDropped = [], array $formOwned = [])
    {
        $recomputed = ['site_icon_root_md5', 'site_manifest'];
        foreach ($stored as $key => $value) {
            if (!is_string($key) || array_key_exists($key, $posted)) { continue; }
            if (strpos($key, 'theme_switcher') === 0 || in_array($key, $recomputed, true)
                || in_array($key, $switcherDropped, true) || in_array($key, $formOwned, true)) { continue; }
            $posted[$key] = $value;
        }
        return $posted;
    }

    // ------------------------------------------------------------------ data files (page-cache key, G)

    public static function customFile()
    {
        return APP_PATH . 'data' . DIRECTORY_SEPARATOR . 'seo' . DIRECTORY_SEPARATOR . 'custom.php';
    }

    public static function legalDir()
    {
        return APP_PATH . 'data' . DIRECTORY_SEPARATOR . 'site' . DIRECTORY_SEPARATOR . 'legal' . DIRECTORY_SEPARATOR;
    }

    /**
     * Data files whose content prints into pages (seo/custom.php, site/legal/*.json). SiteHead::cacheKeyPart() puts
     * their names and mtimes into the page-cache key, so an edit shows without a cache clear. Existing files only, sorted.
     */
    public static function dataFiles()
    {
        $files = [];
        if (is_file(self::customFile())) { $files[] = self::customFile(); }
        foreach (glob(self::legalDir() . '*.json') ?: [] as $file) {
            if (preg_match('/^[a-z0-9]+\.json$/D', basename($file))) { $files[] = $file; }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /** Own titles / descriptions for tag and actress pages (A.5.1), decoded text: [['kind','name','title','desc'], ...]. */
    public static function customRows()
    {
        static $memo = [];
        $file = self::customFile();
        $stamp = is_file($file) ? filemtime($file) . ':' . filesize($file) : '-';
        if (isset($memo[$stamp])) { return $memo[$stamp]; }
        $rows = [];
        if ($stamp !== '-') {
            try {
                $data = include $file;
            } catch (\Throwable $error) {
                $data = [];
            }
            foreach (is_array($data) ? $data : [] as $row) {
                $clean = self::customRow($row);
                if ($clean !== null) { $rows[] = $clean; }
                if (count($rows) >= 200) { break; }
            }
        }
        return $memo[$stamp] = $rows;
    }

    /** One validated custom row (decoded input) or null. */
    public static function customRow($row)
    {
        if (!is_array($row)) { return null; }
        $kind = (string)($row['kind'] ?? '');
        $name = self::plain($row['name'] ?? '');
        $title = self::plain($row['title'] ?? '');
        $desc = self::plain($row['desc'] ?? '');
        if (!in_array($kind, ['tag', 'actor'], true) || $name === '' || self::length($name) > 80
            || self::length($title) > 70 || self::length($desc) > 300 || ($title === '' && $desc === '')) { return null; }
        return ['kind' => $kind, 'name' => $name, 'title' => $title, 'desc' => $desc];
    }

    /** The custom row of a tag / actor name (case-insensitive, spaces collapsed), or null. */
    public static function custom($kind, $name)
    {
        $needle = self::fold($name);
        if ($needle === '') { return null; }
        foreach (self::customRows() as $row) {
            if ($row['kind'] === $kind && self::fold($row['name']) === $needle) { return $row; }
        }
        return null;
    }

    /** Lower-case, trimmed, single-spaced (tag / actor spelling comparisons, C.3 Landing). */
    public static function fold($value)
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string)$value));
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * Write the custom rows (admin, decoded input). Invalid rows are refused as a whole.
     * Returns ['ok' => bool, 'error' => '' | 'row:<n>' | 'too_many' | 'write'].
     */
    public static function writeCustom(array $rows)
    {
        $clean = [];
        foreach (array_values($rows) as $i => $row) {
            $value = self::customRow($row);
            if ($value === null) { return ['ok' => false, 'error' => 'row:' . ($i + 1)]; }
            $clean[] = $value;
        }
        if (count($clean) > 200) { return ['ok' => false, 'error' => 'too_many']; }
        $file = self::customFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return ['ok' => false, 'error' => 'write']; }
        $tmp = $dir . DIRECTORY_SEPARATOR . '.custom.' . bin2hex(random_bytes(4)) . '.tmp';
        $code = "<?php\n// Own titles and descriptions for tag and actress pages (admin: Search engines > Titles). Decoded text.\nreturn "
            . var_export($clean, true) . ";\n";
        if (@file_put_contents($tmp, $code, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'write'];
        }
        return ['ok' => true, 'error' => ''];
    }
}
