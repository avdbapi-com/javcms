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
 * Shared, escaped public-page metadata for all bundled themes (their seo/*.html includes call head()).
 * Rules: SEO-ADMIN-BRIEF C.2 titles / descriptions, C.3 canonical, C.4 robots, C.5 Open Graph / X, C.6 JSON-LD,
 * C.7 rating / verification data (printed by SiteHead), C.8 pagination, D languages. Settings: SeoSettings.
 * Values are decoded once (text()) and escaped once when printed (escape()).
 */
class TemplateSeo
{
    /** English built-in patterns; the catalog keys of the same name (template_lang, I.2) translate them. */
    const BUILTIN = [
        'seo.tpl_video' => '{title}{sep}{site}',
        'seo.tpl_category' => '{category}{sep}{page}{sep}{site}',
        'seo.tpl_list' => '{list}{sep}{page}{sep}{site}',
        'seo.tpl_tag' => '{tag} videos{sep}{page}{sep}{site}',
        'seo.tpl_actor' => '{actor}{sep}{page}{sep}{site}',
        'seo.tpl_home_paged' => '{latest}{sep}{page}{sep}{site}',
        'seo.dtpl_video' => '{title}.',
        'seo.dtpl_video_code' => '{code}: {title}.',
        'seo.d_with' => 'With {actors}.',
        'seo.d_studio' => '{studio}.',
        'seo.d_minutes' => '{duration} min.',
        'seo.dtpl_tag' => '{count} videos tagged {tag}.',
        'seo.dtpl_actor' => '{count} videos with {actor}.',
        'seo.breadcrumb_home' => 'Home',
        'legal.terms' => 'Terms of use',
        'legal.privacy' => 'Privacy policy',
        'legal.dmca' => 'Copyright (DMCA)',
        'legal.2257' => '2257 statement',
        'legal.cookies' => 'Cookie policy',
    ];

    /** og:locale per interface language (C.5). */
    const OG_LOCALES = ['en'=>'en_US','vi'=>'vi_VN','th'=>'th_TH','id'=>'id_ID','ko'=>'ko_KR','zh'=>'zh_CN','ja'=>'ja_JP',
        'fr'=>'fr_FR','de'=>'de_DE','es'=>'es_ES','pt'=>'pt_BR','ru'=>'ru_RU','ar'=>'ar_AR','tr'=>'tr_TR','hi'=>'hi_IN',
        'ms'=>'ms_MY','tl'=>'tl_PH'];

    const RTA = 'RTA-5042-1996-1400-1577-RTA';

    const VERIFY_METAS = ['verify_google'=>'google-site-verification', 'verify_bing'=>'msvalidate.01',
        'verify_yandex'=>'yandex-verification', 'verify_naver'=>'naver-site-verification'];

    const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Locale forced by preview() (the admin has no visitor language); null on the front. */
    private static $forcedLocale = null;

    /**
     * Site-relative URL of the page head() last described (path + canonical query, no ui_lang), or null. The language
     * menu links are built from it (TemplateText::languageUrl()), never from REQUEST_URI: a page-cache entry is shared
     * by every URL that maps to the same parameters (/category/x/index-2/, /category/x/?junk=1), so links copied from
     * the request would be stored for everyone (core-polish, QA g3 C2).
     */
    private static $languageBase = null;
    /**
     * Round 8 (flood-r8 R8-1): facts keys (landingInfo()'s cache key) of the landing values whose facts the request's database deadline
     * cut short in this request without making the page indexable (nothing remembered); head() sends a verified crawler of such a
     * landing page to the time-limit fallback.
     * Round 9 (flood-r9 R9-1b, orchestrator O-20): also the values whose facts in this request are a lower bound or nothing (a late
     * read's _part facts, none at all) and neither make the page indexable nor prove it thin: only a finished read's facts may
     * tell a verified crawler noindex. landingCutSeen() tells EdgeCache::finish() not to store such a render (lane H).
     */
    private static $landingCut = [];

    /** See $languageBase. */
    public static function languageBase()
    {
        return self::$languageBase;
    }

    /** Footer / brand tagline in the visitor's language: the site description for the default language, else the
     * per-language home description (Settings > SEO > Language homes), else ''. Plain text (decoded once). */
    public static function siteTagline(array $site = [])
    {
        if (!$site) { $site = isset($GLOBALS['config']['site']) && is_array($GLOBALS['config']['site']) ? $GLOBALS['config']['site'] : []; }
        return trim(AvdbLanguage::decodeEntities(self::siteDescriptionFor($site)));
    }

    // ------------------------------------------------------------------ small helpers

    /**
     * Round-3 verify G9 (HO-H-R3-3): per-process memos of pure helpers the sitemap's video rows call thousands of times
     * (text / names of short values, release dates, durations, category texts, description patterns). Each is a pure
     * function of its inputs (and of the catalogs, loaded once per process); bounded, emptied when full.
     */
    private static $textMemo = [];
    private static $namesMemo = [];
    private static $dateMemo = [];
    private static $durationMemo = [];
    private static $categoryMemo = [];
    private static $patternMemo = [];
    private static $partMemo = [];
    private static $uploadMemo = [];
    private static $partOutMemo = [];
    const MEMO_MAX = 4096;
    const MEMO_LEN = 320;
    /** text() remembers only short values (names, studios, categories repeat across rows; titles and descriptions do not). */
    const TEXT_MEMO_LEN = 64;
    /**
     * Round-3 G9: bytes after which text()'s decode / strip_tags / white-space steps could change a value: "&", "<", NUL,
     * the ASCII controls TAB..CR, two spaces in a row, and the UTF-8 forms of the other characters "\s" matches under "/u"
     * (U+0085, U+00A0, U+1680, U+180E, U+2000-U+200F, U+2028-U+202F, U+205F-U+206F, U+3000, U+FEFF: a superset, so a
     * value without any of them is only trimmed by those steps). Byte mode: no Unicode property lookups.
     */
    const TEXT_STEPS = '/[&<\x00\t\n\x0B\x0C\r]|  |\xC2[\x85\xA0]|\xE1\x9A\x80|\xE1\xA0\x8E|\xE2\x80[\x80-\x8F\xA8-\xAF]|\xE2\x81[\x9F-\xAF]|\xE3\x80\x80|\xEF\xBB\xBF/';
    /**
     * Round-4 G9 (vi): TEXT_STEPS and the UTF-8 validity check in ONE pass: the same characters as one "/u" class (in valid
     * UTF-8 each TEXT_STEPS byte sequence is exactly one of these code points) plus two spaces in a row. preg_match() = 0:
     * valid UTF-8 without any of them; 1: one of them; false: invalid UTF-8. A Vietnamese / Thai text holds a lead byte
     * \xE1 / \xE0 in almost every word, which made the byte alternation try its branches at every one of them (8,842 vi
     * values: 7.6 -> 5.8 ms, the same verdict on all of them and on 20,144 generated edge values).
     */
    const TEXT_STEPS_U = '/[&<\x00\t\n\x0B\x0C\r\x{85}\x{A0}\x{1680}\x{180E}\x{2000}-\x{200F}\x{2028}-\x{202F}\x{205F}-\x{206F}\x{3000}\x{FEFF}]|  /u';
    /** Round-3 G9: one byte scan for "not printable ASCII without '&', '<' or two spaces in a row" (the HO-H-8 fast path's
     *  three tests: TAB..CR are outside \x20-\x7E). */
    const TEXT_NOT_PLAIN = '/[^\x20-\x25\x27-\x3B\x3D-\x7E]|  /';

    /** Round-4 G9: one string known to be a fixed point of text() (an identity cache: fixedness is a property of the string). */
    private static $lastFixed = null;

    /**
     * Round-4 G9: true when $s (a text() output, or a composition of them) is a fixed point of text() for every limit of at
     * least its length: no "&", "<" or NUL (nothing to decode or strip) and no space at either end. text() outputs hold no
     * white space but single spaces (both fast paths exclude every other "\s"; the slow path collapses them) and are ASCII or
     * valid UTF-8, so for them only a trailing space left by a cut, an entity or a tag can make a second text() differ.
     * $single: also no two spaces in a row (a composition of such values joined by single spaces).
     */
    private static function fixedText($s, $single = false)
    {
        return is_string($s) && strpbrk($s, "&<\0") === false && ($s === '' || ($s[0] !== ' ' && substr($s, -1) !== ' '))
            && (!$single || strpos($s, '  ') === false);
    }

    /** Round-4 G9: text() of a fixed point ($s passed fixedText()): the cut alone, exactly as text() would make it. */
    private static function cutFixed($s, $limit)
    {
        return strlen($s) <= $limit ? $s : (function_exists('mb_substr') ? mb_substr($s, 0, $limit, 'UTF-8') : substr($s, 0, $limit));
    }

    /** $fixed (round-4 G9): set to fixedText() of the result (the callers that compose values skip their own second pass). */
    private static function text($value, $limit = 200, &$fixed = null)
    {
        $want = func_num_args() > 2; // only callers that compose values ask for $fixed
        $fixed = false;
        if (!is_scalar($value)) { $fixed = true; return ''; }
        // HO-H-8 (b): printable ASCII without entities, tags or runs of white space needs none of the steps below (same
        // result; 2-3x faster per sitemap video row)
        $value = (string)$value;
        if (!preg_match(self::TEXT_NOT_PLAIN, $value)) {
            $value = trim($value, ' ');
            $value = strlen($value) > $limit ? substr($value, 0, $limit) : $value;
            if ($want) { $fixed = $value === '' || substr($value, -1) !== ' '; }
            return $value;
        }
        $key = strlen($value) <= self::TEXT_MEMO_LEN ? $limit . "\x1F" . $value : null;
        if ($key !== null && isset(self::$textMemo[$key])) { if ($want) { $fixed = self::fixedText(self::$textMemo[$key]); } return self::$textMemo[$key]; }
        // round-3 G9: the same steps, a step skipped only where it cannot change the value (no "&": nothing to decode; no
        // "<" or NUL: nothing for strip_tags; at most $limit bytes: at most $limit characters, nothing to cut)
        $clean = $value;
        if (preg_match(self::TEXT_STEPS_U, $clean) === 0) {
            // valid UTF-8 without any byte of TEXT_STEPS: decode, strip_tags and the "/u" white-space pass leave it as it is
            // (no "\s" but single spaces), so only the trim below applies (round-3 G9: no Unicode-property pass per value)
            $clean = trim($clean);
        } else {
            // AvdbLanguage::decodeEntities(): decode until stable, at most 3 passes; a text without "&" is stable already
            for ($pass = 0; $pass < 3 && strpos($clean, '&') !== false; $pass++) {
                $decoded = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($decoded === $clean) { break; }
                $clean = $decoded;
            }
            if (strpbrk($clean, "<\0") !== false) { $clean = strip_tags($clean); }
            $clean = trim((string)preg_replace('/\s+/u', ' ', $clean));
        }
        if (strlen($clean) > $limit) {
            $clean = function_exists('mb_substr') ? mb_substr($clean, 0, $limit, 'UTF-8') : substr($clean, 0, $limit);
        }
        if ($key !== null) {
            if (count(self::$textMemo) >= self::MEMO_MAX) { self::$textMemo = []; }
            self::$textMemo[$key] = $clean;
        }
        if ($want) { $fixed = self::fixedText($clean); }
        return $clean;
    }

    /**
     * Meta description: whole words only, with an ellipsis when shortened. $fixed (round-4 G9): the caller proved $value a
     * fixed point of text() with single spaces (fixedText($value, true)): its first pass is the cut alone.
     */
    private static function summary($value, $limit = 200, $fixed = false)
    {
        $full = $fixed && is_string($value) ? self::cutFixed($value, 100000) : self::text($value, 100000);
        // round-3 G9: at most $limit bytes = at most $limit characters (no length count)
        if (strlen($full) <= $limit) { return $full; }
        if (is_int($limit) && $limit >= 2 && function_exists('mb_strlen') && function_exists('mb_substr') && strpbrk($full, "&<\0") === false) {
            // round-4 G9 (vi): text() output without "&", "<" or NUL is printable ASCII or valid UTF-8 with single spaces.
            // ASCII (no byte >= 0x80): characters = bytes, more than $limit of them here. UTF-8: ONE match takes the first
            // $limit - 1 characters and proves two more follow (= more than $limit characters, the mb_strlen test), no
            // match = at most $limit characters. The cut holds exactly $limit - 1 characters either way, so the character
            // position of its last space is ($limit - 2) - (characters after that space): what mb_strrpos() returns,
            // without a count over the whole cut. Same result as the steps below (they stay for every other value).
            $cut = null;
            if (!preg_match('/[\x80-\xFF]/', $full)) {
                $cut = substr($full, 0, $limit - 1);
            } else {
                $hit = preg_match('/^(.{' . ($limit - 1) . '}).{2}/su', $full, $m);
                if ($hit === 0) {
                    return $full;
                }
                if ($hit === 1) {
                    $cut = $m[1];
                }
            }
            if ($cut !== null) {
                $b = strrpos($cut, ' ');
                if ($b !== false && ($limit - 2) - mb_strlen(substr($cut, $b + 1), 'UTF-8') > $limit * 0.6) {
                    $cut = substr($cut, 0, $b);
                }
                return rtrim($cut, " ,.;:-") . "\u{2026}";
            }
        }
        $length = function_exists('mb_strlen') ? mb_strlen($full, 'UTF-8') : strlen($full);
        if ($length <= $limit) { return $full; }
        // text() of its own clean output without "&", "<" or NUL is only the cut (round-3 G9: no second decode / strip /
        // white-space pass over the whole description)
        if (strpbrk($full, "&<\0") !== false) {
            $cut = self::text($full, $limit - 1);
        } elseif ($length === strlen($full)) {
            // round-3 G9: one byte per character (ASCII; or no mbstring: bytes as before): the byte cut is the character cut
            $cut = substr($full, 0, $limit - 1);
        } elseif (function_exists('mb_substr') && is_int($limit) && $limit >= 2 && preg_match('/^.{' . ($limit - 1) . '}/su', $full, $m)) {
            // round-3 G9: text() output is valid UTF-8, so the first $limit - 1 characters are one match ("." = one character
            // under "/su"; more than $limit characters exist here) = mb_substr($full, 0, $limit - 1)
            $cut = $m[0];
        } else {
            $cut = function_exists('mb_substr') ? mb_substr($full, 0, $limit - 1, 'UTF-8') : substr($full, 0, $limit - 1);
        }
        $space = function_exists('mb_strrpos') ? mb_strrpos($cut, ' ', 0, 'UTF-8') : strrpos($cut, ' ');
        if ($space !== false && $space > $limit * 0.6) {
            // the last space of the cut is its last " " byte as well (ASCII in UTF-8): a byte cut there = mb_substr($cut, 0, $space)
            $cut = function_exists('mb_substr') ? substr($cut, 0, (int)strrpos($cut, ' ')) : substr($cut, 0, $space);
        }
        return rtrim($cut, " ,.;:-") . "\u{2026}";
    }

    private static function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function locale()
    {
        return self::$forcedLocale !== null ? self::$forcedLocale : TemplateText::locale();
    }

    private static function raw($key)
    {
        return TemplateText::raw($key, [], self::locale());
    }

    /**
     * Pattern source (C.2): the owner's maccms.php pattern (one text for all languages) -> the catalog key of the
     * same name in the visitor's language -> the English built-in.
     */
    private static function pattern($key, $owner = '')
    {
        $owner = trim((string)$owner);
        if ($owner !== '') { return $owner; }
        // round-3 G9: the catalog's pattern per language (the catalogs are loaded once per process)
        $mk = self::locale() . "\x1F" . $key;
        if (isset(self::$patternMemo[$mk])) { return self::$patternMemo[$mk]; }
        $text = self::raw($key);
        $out = is_string($text) && trim($text) !== '' && $text !== $key ? $text : (self::BUILTIN[$key] ?? '');
        if (count(self::$patternMemo) >= self::MEMO_MAX) { self::$patternMemo = []; }
        return self::$patternMemo[$mk] = $out;
    }

    /**
     * Assembly (C.2): substitute (plain str_replace, never template evaluation) -> collapse repeated separators ->
     * trim separators and spaces at both ends -> at most $limit characters. With seo.title_brand = none the
     * "{sep}{site}" suffix is removed from built-in and owner patterns.
     */
    private static function assemble($pattern, array $tokens, $brand = true, $limit = 350)
    {
        $pattern = (string)$pattern;
        if (!$brand || SeoSettings::get('seo.title_brand') === 'none') { $pattern = str_replace('{sep}{site}', '', $pattern); }
        $mark = "\x1F";
        $map = ['{sep}' => $mark];
        // round-3 G9: only the tokens the pattern uses are cleaned (strtr ignores the others)
        // round-4 G9: the pattern's placeholder names are read once per pattern (every token key is an identifier: the
        // same entries as looking for each token's "{name}" in the pattern), and each cleaned token says whether it is a
        // fixed point of text() (fixedText)
        $allFixed = true;
        foreach (self::placeholders($pattern) as $name) {
            if (!array_key_exists($name, $tokens)) { continue; }
            if (self::$lastFixed !== null && $tokens[$name] === self::$lastFixed) {
                // round-4 G9: this exact string is a known fixed point of text() (videoText()'s title): the cut alone
                $v = self::cutFixed(self::$lastFixed, 1000);
                $f = true;
            } else {
                $v = self::text($tokens[$name], 1000, $f);
            }
            if (strpos($v, $mark) !== false) {
                $v = str_replace($mark, '', $v);
                $f = false;
            }
            $map['{' . $name . '}'] = $v;
            $allFixed = $allFixed && $f;
        }
        $text = strtr($pattern, $map);
        // round-3 G9: separators to collapse only where one is left (the cleaned tokens and the catalog pattern are valid UTF-8)
        // round-4 G9: the cleaned tokens are ASCII or valid UTF-8 (text()), so the result is valid whenever the pattern is
        // (checked once per pattern: mb_check_encoding() costs 3 us per title on PHP 7.4); for an invalid pattern the
        // collapse runs, which leaves a valid text without a separator unchanged, as before
        if (strpos($text, $mark) !== false || !self::validPattern($pattern)) {
            $text = preg_replace('/' . $mark . '(?:\s*' . $mark . ')+/u', $mark, $text);
        }
        $text = trim($text, " \t" . $mark);
        // round-3 G9: the separator setting is read only when a separator is left to replace (description parts have none)
        if (strpos($text, $mark) !== false) {
            $text = str_replace($mark, (string)SeoSettings::get('seo.title_sep'), $text);
            $allFixed = false; // the owner's separator text can be anything
        }
        // round-4 G9: fixed points of text() (the cleaned tokens) put into a pattern that is valid UTF-8 without any
        // TEXT_STEPS character, with single spaces only and no space at either end, make a text text() leaves as it is
        // but for the cut: the cut alone instead of a second pass over the whole text (a sitemap row's title part)
        if ($allFixed && self::cleanPattern($pattern) && strpos($text, '  ') === false && self::fixedText($text)) {
            return self::cutFixed($text, $limit);
        }
        return self::text($text, $limit);
    }

    /** Round-4 G9: placeholder names of a pattern ({name}, innermost braces), once per pattern. */
    private static $placeholderMemo = [];

    private static function placeholders($pattern)
    {
        if (!isset(self::$placeholderMemo[$pattern])) {
            if (count(self::$placeholderMemo) >= self::MEMO_MAX) { self::$placeholderMemo = []; }
            self::$placeholderMemo[$pattern] = preg_match_all('/\{([^{}]*)\}/', $pattern, $m) ? array_values(array_unique($m[1])) : [];
        }
        return self::$placeholderMemo[$pattern];
    }

    /** Round-4 G9: true when a pattern is valid UTF-8 without any TEXT_STEPS character or two spaces in a row (once per pattern). */
    private static $patternCleanMemo = [];

    private static function cleanPattern($pattern)
    {
        if (!isset(self::$patternCleanMemo[$pattern])) {
            if (count(self::$patternCleanMemo) >= self::MEMO_MAX) { self::$patternCleanMemo = []; }
            self::$patternCleanMemo[$pattern] = preg_match(self::TEXT_STEPS_U, $pattern) === 0;
        }
        return self::$patternCleanMemo[$pattern];
    }

    /** Round-4 G9: mb_check_encoding() of assemble() patterns, once per pattern. */
    private static $patternValidMemo = [];

    private static function validPattern($pattern)
    {
        $pattern = (string)$pattern;
        if (!isset(self::$patternValidMemo[$pattern])) {
            if (count(self::$patternValidMemo) >= self::MEMO_MAX) { self::$patternValidMemo = []; }
            self::$patternValidMemo[$pattern] = mb_check_encoding($pattern, 'UTF-8');
        }
        return self::$patternValidMemo[$pattern];
    }

    /** One description part (C.2, CF09): printed only when every token it uses has a value. */
    private static function part($key, array $tokens)
    {
        $pattern = self::pattern($key);
        // round-4 G9: per pattern once: [token names that must have a value (all but sep / site), output memo allowed, all
        // token names] (the round-3 rules below, decided once instead of per row)
        if (!isset(self::$partMemo[$pattern])) {
            if (count(self::$partMemo) >= self::MEMO_MAX) { self::$partMemo = []; }
            $names = preg_match_all('/\{([a-z]+)\}/', $pattern, $m) ? $m[1] : [];
            $need = [];
            $memo = strpos($pattern, '{sep}') === false && strpos($pattern, "\x1F") === false;
            foreach ($names as $name) {
                if ($name !== 'sep' && $name !== 'site') { $need[] = $name; }
                if ($name === 'title' || $name === 'code') { $memo = false; }
            }
            self::$partMemo[$pattern] = [$need, $memo, $names];
        }
        $info = self::$partMemo[$pattern];
        foreach ($info[0] as $name) {
            if (!isset($tokens[$name]) || trim((string)$tokens[$name]) === '') { return ''; }
        }
        // round-3 G9: a part is a pure function of its pattern and the values of the tokens the pattern names (no brand,
        // no separator setting when the pattern has no {sep}): the parts that repeat across rows ("with {actors}", "by
        // {studio}", "{duration} minutes") are remembered; a part naming the title or the code is new for every row
        $key = null;
        if ($info[1]) {
            $key = $pattern;
            foreach ($info[2] as $name) {
                $key .= "\x1E" . (array_key_exists($name, $tokens) ? (is_scalar($tokens[$name]) ? (string)$tokens[$name] : "\x00a") : "\x00m");
            }
            if (strlen($key) > 512) { $key = null; }
            elseif (isset(self::$partOutMemo[$key])) { return self::$partOutMemo[$key]; }
        }
        $out = self::fixStops(self::assemble($pattern, $tokens, false, 1000));
        if ($key !== null && is_string($out)) {
            if (count(self::$partOutMemo) >= self::MEMO_MAX) { self::$partOutMemo = []; }
            self::$partOutMemo[$key] = $out;
        }
        return $out;
    }

    /** "Title!." -> "Title!" : a token that already ends a sentence does not get a second full stop. */
    private static function fixStops($text)
    {
        return preg_replace('/([.!?\x{2026}\x{3002}\x{FF01}\x{FF1F}])\s*\./u', '$1', (string)$text);
    }

    private static function pageToken($page)
    {
        return $page > 1 ? self::raw('page') . ' ' . (int)$page : '';
    }

    /** The site description in the visitor's language (CF10): the default language uses site_description, other
     *  languages their lang_home description, else nothing (never the description of another language). */
    private static function siteDescriptionFor(array $site)
    {
        $locale = self::locale();
        if ($locale === AvdbLanguage::defaultLocale()) { return (string)($site['site_description'] ?? ''); }
        return SeoSettings::langHome($locale)['desc'];
    }

    /**
     * Description of a listing page without its own text: it starts with the page name (and the
     * page number) so listings never share one meta description, then the site description (CF10).
     */
    private static function listingSummary($name, array $site, $page = 1, $videos = false)
    {
        $parts = [];
        foreach ([self::text($name, 180), $videos ? self::raw('ui.latest_videos') : '',
            $page > 1 ? self::raw('page') . ' ' . (int)$page : ''] as $part) {
            $part = trim((string)$part);
            if ($part !== '' && !in_array($part, $parts, true)) { $parts[] = $part; }
        }
        $lead = implode(' - ', $parts);
        $rest = self::text(self::siteDescriptionFor($site), 100000);
        if ($lead === '') { return self::summary($rest); }
        return self::summary($rest === '' ? $lead : $lead . '. ' . $rest);
    }

    /** HH:MM:SS, MM:SS or whole minutes -> seconds; 0 when unknown. */
    private static function durationSeconds($value)
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        // round-3 G9: one answer per distinct value (the sitemap row asks twice; many videos share a duration)
        if (isset(self::$durationMemo[$value])) { return self::$durationMemo[$value]; }
        if (preg_match('/^(\d{1,3}):([0-5]?\d):([0-5]?\d)$/', $value, $m)) { $seconds = $m[1] * 3600 + $m[2] * 60 + $m[3]; }
        elseif (preg_match('/^(\d{1,4}):([0-5]?\d)$/', $value, $m)) { $seconds = $m[1] * 60 + $m[2]; }
        elseif (preg_match('/^(\d{1,4})\s*(?:min|mins|minutes|m)?$/i', $value, $m)) { $seconds = $m[1] * 60; }
        else { $seconds = 0; }
        $seconds = (int)$seconds;
        $out = ($seconds <= 0 || $seconds > 86400) ? 0 : $seconds;
        if (strlen($value) <= 32) {
            if (count(self::$durationMemo) >= self::MEMO_MAX) { self::$durationMemo = []; }
            self::$durationMemo[$value] = $out;
        }
        return $out;
    }

    /** HH:MM:SS, MM:SS or whole minutes -> ISO 8601 duration (PT1H58M10S); '' when unknown. */
    private static function isoDuration($value)
    {
        $seconds = self::durationSeconds($value);
        if ($seconds <= 0) { return ''; }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;
        return 'PT' . ($hours ? $hours . 'H' : '') . ($minutes ? $minutes . 'M' : '') . ($rest ? $rest . 'S' : '');
    }

    /**
     * Release date of a video (C.2 {year}, C.6 uploadDate, CF08): vod_pubdate parsed ONLY as 'Y-m-d' or 'j M, Y'
     * in UTC (never strtotime(): it reads "2 Mar, 2009" as 2026-03-02 20:09). A future date or any other form -> null.
     */
    public static function releaseDate($value)
    {
        // the same row asks twice (video tokens, then uploadDate) and many rows share a release date: remember the answers of
        // this second per value (round-3 G9; "in the future" depends on the second)
        $now = time();
        $k = is_scalar($value) ? (string)$value : null;
        if ($k !== null && isset(self::$dateMemo[$k]) && self::$dateMemo[$k][0] === $now) {
            return self::$dateMemo[$k][1] ? clone self::$dateMemo[$k][1] : null;
        }
        $date = self::parseReleaseDate($value);
        if ($k !== null && strlen($k) <= 64) {
            if (count(self::$dateMemo) >= self::MEMO_MAX) { self::$dateMemo = []; }
            self::$dateMemo[$k] = [$now, $date ? clone $date : null];
        }
        return $date;
    }

    private static function parseReleaseDate($value)
    {
        $value = is_scalar($value) ? trim(html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        if ($value === '') { return null; }
        $utc = new \DateTimeZone('UTC');
        foreach (['!Y-m-d', '!j M, Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $value, $utc);
            if (!$date) { continue; }
            $errors = \DateTime::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) { continue; }
            if ($date->getTimestamp() > time()) { return null; }
            return $date;
        }
        return null;
    }

    /** uploadDate of a video: the release date when it parses, else the time it was added (ISO 8601, +00:00). */
    private static function uploadDate(array $obj)
    {
        // round-3 G9: the formatted release date per value and second (the rows of a sitemap file share release dates)
        $value = $obj['vod_pubdate'] ?? '';
        $now = time();
        $k = is_scalar($value) && strlen((string)$value) <= 64 ? (string)$value : null;
        if ($k !== null && isset(self::$uploadMemo[$k]) && self::$uploadMemo[$k][0] === $now) {
            $iso = self::$uploadMemo[$k][1];
        } else {
            if ($k !== null && strlen($k) === 10 && preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $k, $dm) && (int)$dm[1] >= 1000
                && checkdate((int)$dm[2], (int)$dm[3], (int)$dm[1])) {
                // a valid plain Y-m-d value is what releaseDate() parses ('!Y-m-d' in UTC = midnight, no warning): its ISO form
                // without a DateTime, under the same "not in the future" rule
                $iso = gmmktime(0, 0, 0, (int)$dm[2], (int)$dm[3], (int)$dm[1]) > time() ? '' : $k . 'T00:00:00+00:00';
            } else {
                $date = self::releaseDate($value);
                $iso = $date ? $date->format('c') : '';
            }
            if ($k !== null) {
                if (count(self::$uploadMemo) >= self::MEMO_MAX) { self::$uploadMemo = []; }
                self::$uploadMemo[$k] = [$now, $iso];
            }
        }
        if ($iso !== '') { return $iso; }
        $added = (int)($obj['vod_time_add'] ?? 0);
        return $added > 0 && $added <= time() ? gmdate('c', $added) : '';
    }

    /** Release code (C.2 {code}, CF09): the first "ABC-123" of vod_remarks, upper-cased, without -SUB / -LEAK tails. */
    public static function releaseCode($remarks)
    {
        $remarks = is_scalar($remarks) ? (string)$remarks : '';
        // round-4 G9: html_entity_decode() changes only a text holding "&"
        $remarks = trim(strpos($remarks, '&') === false ? $remarks : html_entity_decode($remarks, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return preg_match('/^[A-Z0-9]{2,10}-\d{2,6}/i', $remarks, $m) ? strtoupper($m[0]) : '';
    }

    /** Names from a stored list (comma, slash, pipe, full-width comma, ideographic comma): trimmed, unique, at most $limit. */
    private static function names($value, $limit = 10)
    {
        // round-3 G9: one answer per distinct short list (genre / tag lists repeat across the rows of a sitemap file)
        $key = is_scalar($value) && strlen((string)$value) <= self::MEMO_LEN ? $limit . "\x1F" . (string)$value : null;
        if ($key !== null && isset(self::$namesMemo[$key])) { return self::$namesMemo[$key]; }
        $raw = is_scalar($value) ? (string)$value : '';
        if (!preg_match(self::TEXT_NOT_PLAIN, $raw)) {
            // round-3 G9: text()'s fast path inline (printable ASCII without entities, tags or runs of white space: trimmed,
            // at most 2000 bytes), and such a list is plain without a second scan
            $value = trim($raw, ' ');
            if (strlen($value) > 2000) { $value = substr($value, 0, 2000); }
            $plain = true;
        } else {
            $value = self::text($raw, 2000);
            // a cleaned list of printable ASCII without "&" or "<": every trimmed piece is what text() returns for it (its
            // fast path: at most 120 bytes kept)
            $plain = strpbrk($value, "&<") === false && !preg_match('/[^\x20-\x7E]/', $value);
        }
        $names = [];
        if ($plain && $limit >= 1 && strlen($value) <= 120 && strpbrk($value, '/|') === false) {
            // round-4 G9: a plain list of at most 120 bytes (every name kept whole) without "/" or "|": the pieces between
            // commas without their spaces (it holds single spaces only and none at its ends), empty ones dropped, the first
            // occurrence of each kept in order (strict equality), the first $limit of them: what the loop below keeps
            $names = array_values(array_unique(preg_split('/ *, */', $value, -1, PREG_SPLIT_NO_EMPTY) ?: []));
            if (count($names) > $limit) { $names = array_slice($names, 0, $limit); }
            if ($key !== null) {
                if (count(self::$namesMemo) >= self::MEMO_MAX) { self::$namesMemo = []; }
                self::$namesMemo[$key] = $names;
            }
            return $names;
        }
        // text() left single spaces as the only white space: a list without "/", "|", U+FF0C or U+3001 splits on commas alone
        // (the pieces are trimmed below), the same pieces as the separator pattern (a plain list holds no U+FF0C / U+3001)
        $pieces = strpbrk($value, '/|') === false && ($plain || (strpos($value, "\u{FF0C}") === false && strpos($value, "\u{3001}") === false))
            ? explode(',', $value) : (preg_split('/\s*(?:[,\/|\x{FF0C}\x{3001}])\s*/u', $value) ?: []);
        foreach ($pieces as $name) {
            $name = trim($name);
            if ($name !== '' && !in_array($name, $names, true)) { $names[] = $plain ? (strlen($name) > 120 ? substr($name, 0, 120) : $name) : self::text($name, 120); }
            if (count($names) >= $limit) { break; }
        }
        if ($key !== null) {
            if (count(self::$namesMemo) >= self::MEMO_MAX) { self::$namesMemo = []; }
            self::$namesMemo[$key] = $names;
        }
        return $names;
    }

    /** True when a secondary module (actor, topic, art) has no published rows: its listings stay out of the index
     *  (and out of the sitemap, see Sitemap::pagesFeed). */
    public static function moduleEmpty($section)
    {
        static $memo = [];
        if (!in_array($section, ['actor', 'topic', 'art'], true)) { return false; }
        if (isset($memo[$section])) { return $memo[$section]; }
        $key = 'tpl_seo_rows_' . $section . '_' . substr(sha1((string)config('database.database') . '|' . (string)config('database.prefix')), 0, 12);
        $count = \think\Cache::get($key);
        if (!is_numeric($count)) {
            try {
                $count = (int)\think\Db::name($section)->where($section . '_status', 1)->count();
            } catch (\Throwable $error) {
                return $memo[$section] = false;
            }
            \think\Cache::set($key, $count, 600);
        }
        return $memo[$section] = ((int)$count === 0);
    }

    /** Title of a theme-defined label page: catalog key ui.label_<file> (shared or theme catalog), a shared key
     *  for the usual account pages, else the file name in words. */
    private static function labelTitle($file)
    {
        $slug = str_replace('-', '_', strtolower((string)$file));
        $aliases = ['user'=>'ui.my_account', 'account'=>'ui.my_account', 'member'=>'ui.my_account', 'my'=>'ui.my_account',
            'favs'=>'favorites', 'fav'=>'favorites', 'favorites'=>'favorites', 'favourites'=>'favorites', 'history'=>'history'];
        foreach (['ui.label_' . $slug, $aliases[$slug] ?? ''] as $key) {
            if ($key === '') { continue; }
            $text = self::text(self::raw($key), 180);
            if ($text !== '' && $text !== $key) { return $text; }
        }
        return ucwords(str_replace(['_', '-'], ' ', (string)$file));
    }

    /** Absolute http(s) URL that the player of the current episode loads, or '' when it must not be published. */
    private static function playerMediaUrl(array $obj)
    {
        $player = $obj['player_info'] ?? null;
        if (!is_array($player) || (int)($player['encrypt'] ?? 0) !== 0 || (int)($player['points'] ?? 0) > 0
            || (int)($player['trysee'] ?? 0) > 0) { return ''; }
        $source = $obj['vod_play_list'][(int)($player['sid'] ?? 0)]['player_info'] ?? [];
        // A parse interface wraps the source: the frame does not load this URL itself.
        if (is_array($source) && (string)($source['ps'] ?? '0') === '1') { return ''; }
        $url = trim((string)($player['url'] ?? ''));
        if (!preg_match('~^https?://[^\s"\'<>]+$~i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) { return ''; }
        return $url;
    }

    private static function absoluteAsset($path)
    {
        if (!is_string($path) || $path === '' || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $path)) { return ''; }
        if (strpos($path, '//') === 0) { $path = 'https:' . $path; }
        if (preg_match('~^https?://~i', $path)) { return filter_var($path, FILTER_VALIDATE_URL) ? $path : ''; }
        return substr($path, 0, 1) === '/' ? Sitemap::absolute($path) : '';
    }

    private static function root()
    {
        return defined('MAC_PATH') ? MAC_PATH : '/';
    }

    /** Owner share picture (A.6): [absolute URL, width, height] or null. */
    private static function shareImage()
    {
        $path = (string)SeoSettings::get('site.site_share_image');
        if ($path === '' || !defined('ROOT_PATH') || !is_file(ROOT_PATH . $path)) { return null; }
        $url = Sitemap::absolute(self::root() . $path);
        return $url === '' ? null : [$url, SeoSettings::int('site.site_share_w'), SeoSettings::int('site.site_share_h')];
    }

    /** Absolute site logo for Organization.logo when it is a local picture of at least 112 px (C.6). */
    private static function logoUrl(array $site)
    {
        $logo = trim(html_entity_decode((string)($site['site_logo'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $logo = ltrim(preg_replace('~^' . preg_quote(self::root(), '~') . '~', '', $logo), '/');
        if ($logo === '' || !defined('ROOT_PATH') || preg_match('~(^|/)\.\.(/|$)|^[a-z]+:|[\x00-\x20<>"\'\\\\]~i', $logo)) { return ''; }
        $file = ROOT_PATH . $logo;
        if (!is_file($file)) { return ''; }
        $size = @getimagesize($file);
        if (!is_array($size) || $size[0] < 112 || $size[1] < 112) { return ''; }
        return Sitemap::absolute(self::root() . $logo);
    }

    // ------------------------------------------------------------------ public helpers

    public static function links(array $variants, $locale = null)
    {
        $locale = $locale === null ? TemplateText::locale() : $locale;
        $default = AvdbLanguage::defaultLocale();
        $canonical = $variants[$locale]['url'] ?? ($variants[$default]['url'] ?? '');
        if ($canonical === '') { return ''; }
        $html = '<link rel="canonical" href="' . self::escape($canonical) . '" />' . "\n";
        if (count($variants) > 1 && isset($variants[$default])) {
            foreach ($variants as $language => $row) {
                $html .= '<link rel="alternate" hreflang="' . self::escape($language) . '" href="' . self::escape($row['url']) . '" />' . "\n";
            }
            $html .= '<link rel="alternate" hreflang="x-default" href="' . self::escape($variants[$default]['url']) . '" />' . "\n";
        }
        return $html;
    }

    public static function interfaceVariants($path)
    {
        return Sitemap::pageVariants(Sitemap::absolute($path), array_keys(TemplateText::languages()));
    }

    /**
     * XVID-NOLIMIT-BRIEF B3 / C3: the continuation parameter of this request as 'after=<v>.<id>' (category lists) or
     * 'cursor=<doc id>' (search pages), validated exactly like the list code reads it; '' when there is none.
     */
    public static function continuation($action)
    {
        $action = (string)$action;
        if (in_array($action, ['vod/type', 'vod/show'], true) && isset($_GET['after']) && is_string($_GET['after'])
            && preg_match('/^[0-9]{1,10}\.[0-9]{1,10}$/D', $_GET['after'])) {
            return 'after=' . $_GET['after'];
        }
        if ($action === 'vod/search' && isset($_GET['cursor']) && is_string($_GET['cursor']) && preg_match('/^[1-9][0-9]{0,17}$/D', $_GET['cursor'])) {
            return 'cursor=' . $_GET['cursor'];
        }
        // round-3 verify N10: the "Older results" pages of a keyword search inside a category (/category/<slug>/wd/<word>/)
        if (in_array($action, ['vod/type', 'vod/show'], true) && isset($_GET['cursor']) && is_string($_GET['cursor'])
            && preg_match('/^[1-9][0-9]{0,17}$/D', $_GET['cursor']) && self::categoryWord() !== '') {
            return 'cursor=' . $_GET['cursor'];
        }
        return '';
    }

    /** Round-3 verify N10: the normalized keyword of a category / show list request ('' when it has none). */
    private static function categoryWord()
    {
        if (!function_exists('mac_param_url') || !class_exists('\app\common\model\Vod')) { return ''; }
        $param = mac_param_url();
        return !empty($param['wd']) && is_scalar($param['wd']) ? \app\common\model\Vod::normalizeSearchWord($param['wd']) : '';
    }

    /**
     * Tag / actress landing request (C.3, C.4): exactly one of `tag` / `actor`, no other search or filter parameter
     * (the page number is allowed). Returns ['tag'|'actor', decoded value] or null. Also used by
     * index/controller/Base.php check_search(): landing pages skip the search interval and the search captcha.
     */
    public static function landing(array $param)
    {
        $found = [];
        foreach ($param as $key => $value) {
            if ($key === 'page' || (is_string($key) && $key !== '' && $key[0] === '_') || $value === null || $value === '' || $value === 0 || $value === '0') { continue; }
            if (!is_scalar($value)) { return null; }
            if ($key !== 'tag' && $key !== 'actor') { return null; }
            $found[$key] = trim(preg_replace('/\s+/u', ' ', html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        if (count($found) !== 1) { return null; }
        $kind = key($found);
        $value = $found[$kind];
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length < 1 || $length > 100 || strpos($value, ',') !== false || preg_match('/[\x00-\x1F\x7F]/', $value)) { return null; }
        return [$kind, $value];
    }

    /**
     * Landing facts, the same for every visitor and cached 24 h (C.4): number of published videos whose tag (actor)
     * column holds the value (a bounded LIKE count of at most 10,001 rows with the FilterMatch pre-filter, the visitor
     * list's rows: fp-port; 1.2.0 D-L7 counted the search table's token), and the stored spelling: the first comma-separated
     * token of the newest matching rows that equals the value case-insensitively (CF11; '' when the LIKE only hit
     * a longer word, e.g. "Anal" inside "Analingus").
     * fp-port-b (FINISH-PORT-REVIEW P2-2): 'exact' tells whether 'count' is the bounded count (more than COUNT_BOUND rows
     * is printed as "10000+", never as the bound itself); a CJK / kana / Hangul / Thai value counts through the item path
     * (FilterMatch::items); when the count runs out of LANDING_SQL_SEC the facts of the last read that finished (kept
     * LANDING_GOOD_TTL) are used, else the newest matching rows give the stored spelling and a lower bound ('exact' false:
     * no "N videos" text, indexable when that bound reaches seo.landing_min), and when even those rows are too far apart to
     * read in time, the rows holding the value as a whole item through the search table's field token (landingTokenRows):
     * no value turns noindex only for being slow while the search table is ready.
     * fp-port-c (FINISH-PORT-REVIEW-b P3-b): the facts of the last finished read stand in only for the spelling and the
     * indexability ('exact' false): they are older than the 24 h facts cache (whose entry would have answered), so their
     * count is never printed as the number of videos. P3-a: model() asks for the value the list itself filters by (cut at
     * search_len like the list) when it differs from the requested one, so a printed number is the count of the list shown.
     */
    /** Seconds the landing count / spelling read of a value without a search-index token may run (round 2). */
    const LANDING_SQL_SEC = 0.5;
    /** Seconds a landing whose LIKE read ran out of LANDING_SQL_SEC answers "no facts" without reading again. */
    const LANDING_LATE_TTL = 600;
    const LANDING_LATE = 'jv-landing-late';
    /** fp-port-b: seconds the facts of the last finished read stand in for a read that runs out of time. */
    const LANDING_GOOD_TTL = 604800;
    /** fp-port-b: rows the spelling read takes at least (a lower bound of the count when the count ran out of time). */
    const LANDING_ROWS = 50;
    /** fp-port-b: candidate ids one field-token read takes (landingTokenRows). */
    const LANDING_TOKEN_IDS = 2000;

    /** Rows of one landing SELECT under LANDING_SQL_SEC (statement limit; message-only timeout test); null = out of time. */
    private static function landingQuery(string $sql): ?array
    {
        if (!class_exists(__NAMESPACE__ . '\\FtIndex') || !method_exists(__NAMESPACE__ . '\\FtIndex', 'isTimeout')) {
            return \think\Db::query($sql);
        }
        try {
            return \think\Db::query(FtIndex::limited($sql, self::LANDING_SQL_SEC));
        } catch (\Throwable $e) {
            if (FtIndex::isTimeout($e)) {
                return null;
            }
            throw $e;
        }
    }

    public static function landingInfo($kind, $value)
    {
        $field = $kind === 'actor' ? 'vod_actor' : 'vod_tag';
        $folded = SeoSettings::fold($value);
        // fp-port-c: the facts are kept per exact value, not per folded value: mb_strtolower() folds spellings the column's
        // collation keeps apart ("İstanbul" -> "i̇stanbul", i + U+0307, which LIKE-matches other rows), so a folded key served
        // one spelling's count for another (found on MySQL 5.7: "2 videos" over an empty list); the spelling check still folds
        $key = 'seo_landing_' . sha1($kind . '|' . $value . '|' . (string)config('database.database') . '|' . (string)config('database.prefix'));
        $none = ['count' => 0, 'spelling' => '', 'exact' => false];
        try {
            $hit = \think\Cache::get($key);
            if (is_array($hit) && isset($hit['count'], $hit['spelling'])) {
                return $hit + ['exact' => true]; // facts of a finished read (entries cached before fp-port-b were exact counts too)
            }
            // round 2: a value whose read ran out of time a short while ago is not read again for LANDING_LATE_TTL; fp-port-b:
            // meanwhile it keeps the facts of the last finished read, else what the spelling read proved
            if (\think\Cache::get($key . '_late')) {
                $good = \think\Cache::get($key . '_good');
                if (is_array($good) && isset($good['count'], $good['spelling'])) {
                    // fp-port-c (FINISH-PORT-REVIEW-b P3-b): older than the 24 h facts (else the entry above answered): its count
                    // decides indexability and the spelling, but it is never printed as the number of videos
                    return ['exact' => false] + $good;
                }
                $part = \think\Cache::get($key . '_part');
                $lateFacts = is_array($part) && isset($part['count'], $part['spelling']) ? $part + ['exact' => false] : $none;
                // round 9 (flood-r9 R9-1b, orchestrator O-20): what the late read proved (a lower bound, or nothing) is not a
                // finished read's facts: when it neither makes the page indexable nor proves it thin, head() sends a verified
                // crawler to the time-limit fallback (stale copy or the lighter page) instead of a noindex page; visitors keep
                // the noindex page, which EdgeCache never stores (landingCutSeen())
                if (self::landingUnproven($lateFacts)) {
                    self::$landingCut[$key] = true;
                }
                return $lateFacts;
            }
        } catch (\Throwable $e) {
            // no cache: read below
        }
        $late = function (array $facts, bool $finished = false) use ($key) {
            // round 8 (flood-r8 R8-1, orchestrator O-12): the landing reads end by the request's database deadline now
            // (FtIndex::capSec). When that deadline, not the value, ran the time out (less than LANDING_SQL_SEC + one CAP_STEP
            // left, or a statement refused for a spent request) and the facts found do not make the page indexable, nothing is
            // remembered: the next visit reads with its own time instead of answering noindex for LANDING_LATE_TTL (a deadline
            // says nothing about the value); facts that make the page indexable are kept as before
            if (!self::landingIndexable($facts) && self::landingDeadlineNear()) {
                self::$landingCut[$key] = true;
                return $facts;
            }
            try {
                \think\Cache::set($key . '_late', 1, self::LANDING_LATE_TTL);
                if (!empty($facts['count'])) {
                    \think\Cache::set($key . '_part', $facts, self::LANDING_LATE_TTL);
                }
            } catch (\Throwable $e) {
                // not remembered: the next request reads again (still bounded)
            }
            // round 9 (flood-r9 R9-1b, orchestrator O-20): the read ran out of LANDING_SQL_SEC on its own (the deadline was not
            // near) and what it found is a lower bound or nothing that neither makes the page indexable nor proves it thin:
            // this request's verified crawler takes the time-limit fallback too, as the late-cache branch above does for the
            // next LANDING_LATE_TTL seconds. $finished: the facts of the last finished read (_good) stand in; they decide for
            // everyone (thin values stay noindex)
            if (!$finished && self::landingUnproven($facts)) {
                self::$landingCut[$key] = true;
            }
            return $facts;
        };
        try {
            // round 5 (owner decision D-1 (c)): a tag value FtPlan::tagRoute() proves LIKE-exact counts through its term map
            // (the visitor list pages through it too): the exact total, bounded like the LIKE count (COUNT_BOUND + 1), and the
            // stored spelling from its newest rows, each read with the same LIKE; no bounded LIKE walk over a dense value
            if ($kind !== 'actor' && class_exists(__NAMESPACE__ . '\\FtPlan') && method_exists(__NAMESPACE__ . '\\FtPlan', 'tagRoute')
                && ($routeFacts = self::landingRouteFacts((string)$value, $folded)) !== null) {
                try {
                    \think\Cache::set($key, $routeFacts, 86400);
                    \think\Cache::set($key . '_good', $routeFacts, self::LANDING_GOOD_TTL);
                } catch (\Throwable $e) {
                    // not cached: read again next time
                }
                return $routeFacts;
            }
            // fp-port (import-r3 filter-like-parity): the count is the visitor list's, the bounded LIKE with the FilterMatch
            // pre-filter (v2 vocabulary groups make single words index-fast), whatever state the search index is in; the field
            // token of a whole item counted other rows (tg_ also holds vod_class items; a whole item can be part of a longer
            // one: tag "Big" 240 LIKE rows, 2 token rows). fp-port-b: a CJK / kana / Hangul / Thai value counts through the
            // item path (FilterMatch::items: the ids of the rows holding a matching item, exact, '(1=0)' when none), which
            // replaces the round-2 "absent" shortcut (FtPlan::valueAbsent: it also answered 0 for a value inside a long run)
            $like = '%' . addcslashes($value, '%_\\') . '%';
            $ft = '';
            if (class_exists(__NAMESPACE__ . '\\FilterMatch') && strpbrk($value, '%_\\') === false) {
                $ft = FilterMatch::prefilter($field, $value);
                if ($ft === '' && method_exists(__NAMESPACE__ . '\\FilterMatch', 'items')) {
                    $ft = FilterMatch::items($field, $value); // the count below lists visible rows only, as items() requires
                }
            }
            $q = \think\Db::name('vod')->field('1')->where('vod_status', 1)->where('vod_recycle_time', 0)->where($field, 'like', $like);
            // round 5 (synth S-3, flood-r5 R5-2): a value without an index path (no pre-filter: a dense value, no vocabulary
            // group) counts window by window through the newest rows (Vod::windowLikeCount: the windows its list page 1 reads)
            // while the rows this request's statements stepped through stay under the walk's budget (round 6: session
            // counters, Vod::WALK_COUNT_EXAMINE); rows, not only LANDING_SQL_SEC, bound the read; a warm or small site counts
            // as before. Round 6 (flood-r6 R6-1, orchestrator S-R6-1): a count that stopped on the budget is a lower bound
            // ('exact' false: no "N videos") and the page stays indexable from seo.landing_min: the stored spelling comes from
            // the newest matching rows the walk read (no further read), else from the value's MATCH rows (Vod::textMatchIds:
            // exact, shared with page 1 of the list), else from the rows holding it as a whole item (landingTokenRows)
            // round 10 (search-r10 FR-1 / FR-4, orchestrator O-26 (a) / (d)): the windowed count belongs to big catalogs
            // (FilterMatch::bigCatalog(): scale tier 3, where it was measured and needed); below tier 3 a value without a
            // pre-filter keeps the deployed 1.2.0 count, the bounded LIKE count under LANDING_SQL_SEC below (live copy, 525,975
            // videos: class "Hd" counted 3,823 rows exactly that way, its spelling read found no stored item "Hd": noindex by the
            // spelling rule on complete facts; the windowed walk stopped at 426 rows and left a cut read, S-R6-1)
            $wc = null;
            $bigCatalog = class_exists(__NAMESPACE__ . '\\FilterMatch') && method_exists(__NAMESPACE__ . '\\FilterMatch', 'bigCatalog')
                && FilterMatch::bigCatalog();
            if ($ft === '' && $bigCatalog && method_exists('\app\common\model\Vod', 'windowLikeCount')) {
                $wc = \app\common\model\Vod::windowLikeCount($field, $like);
            }
            if ($wc !== null) {
                $exact = !empty($wc['exact']);
                $count = (int)$wc['count'];
                $walkSpelling = self::spellingOf((array)($wc['rows'] ?? []), $field, $folded);
                if ($exact) {
                    // the walk counted every match (or more than the bound): the spelling from its newest rows, no extra read
                    $facts = ['count' => $count, 'spelling' => $walkSpelling, 'exact' => true];
                    try {
                        \think\Cache::set($key, $facts, 86400);
                        \think\Cache::set($key . '_good', $facts, self::LANDING_GOOD_TTL);
                    } catch (\Throwable $e) {
                        // not cached: read again next time
                    }
                    return $facts;
                }
                $min = max(1, SeoSettings::int('seo.landing_min'));
                if ($walkSpelling !== '' && $count >= $min) {
                    return $late(['count' => $count, 'spelling' => $walkSpelling, 'exact' => false]); // indexable lower bound
                }
                try {
                    $good = \think\Cache::get($key . '_good');
                    if (is_array($good) && isset($good['count'], $good['spelling'])) {
                        return $late(['exact' => false] + $good, true); // stale facts: spelling and indexability, no number
                    }
                } catch (\Throwable $e) {
                    // no cache: the MATCH rows / item rows below
                }
                // the walk stopped with too few matches for the rule: the value's MATCH rows (also page 1 of its list)
                $m = method_exists('\app\common\model\Vod', 'textMatchIds')
                    ? \app\common\model\Vod::textMatchIds(['vod_status' => ['eq', 1], $field => ['like', [$like], 'OR'], 'vod_recycle_time' => 0], '', 0) : null;
                if ($m !== null) {
                    $mCount = min((int)$m['total'], \app\common\model\Vod::COUNT_BOUND + 1);
                    $ids = array_slice($m['ids'], 0, self::LANDING_ROWS);
                    $rows = $ids ? \think\Db::name('vod')->field('vod_id,' . $field)->where('vod_id', 'in', $ids)->select() : [];
                    $rows = is_array($rows) ? $rows : (is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : []);
                    $facts = ['count' => $mCount, 'spelling' => self::spellingOf($rows, $field, $folded), 'exact' => true];
                    try {
                        \think\Cache::set($key, $facts, 86400);
                        \think\Cache::set($key . '_good', $facts, self::LANDING_GOOD_TTL);
                    } catch (\Throwable $e) {
                        // not cached: read again next time
                    }
                    return $facts;
                }
                // the rows holding the value as a whole item (search table field token, each checked with the same LIKE)
                $tok = self::landingTokenRows($kind, $field, $value, $like);
                $tokSpelling = $tok ? self::spellingOf($tok, $field, $folded) : '';
                return $late(['count' => max($count, $tok ? count($tok) : 0), 'spelling' => $walkSpelling !== '' ? $walkSpelling : $tokSpelling, 'exact' => false]);
            } else {
                if ($ft !== '') {
                    $q->where($ft);
                }
                // round 2 (O-S1 CJK / single-word values, HO-H-O4): under LANDING_SQL_SEC, never the whole statement limit
                $r = self::landingQuery('SELECT COUNT(*) AS c FROM ' . $q->limit(\app\common\model\Vod::COUNT_BOUND + 1)->buildSql() . ' bounded_count');
                $exact = $r !== null;
                $count = $exact ? (int)($r[0]['c'] ?? 0) : 0;
            }
            if (!$exact) {
                // fp-port-b: out of time (a value without an index path on a large or busy site): the facts of the last read
                // that finished, as long as LANDING_GOOD_TTL keeps them, stand in (the list itself stays exact)
                try {
                    $good = \think\Cache::get($key . '_good');
                    if (is_array($good) && isset($good['count'], $good['spelling'])) {
                        // fp-port-c (P3-b): stale (the 24 h facts are gone): spelling and indexability, no printed number
                        return $late(['exact' => false] + $good, true);
                    }
                } catch (\Throwable $e) {
                    // no cache: the spelling read below
                }
                // round 7 (flood-r7 R7-1, S-R6-1 in this branch: a landing never turns noindex because a count budget ran
                // out): the count through the pre-filter ran out of LANDING_SQL_SEC (a cold FULLTEXT index and its rows): the
                // value's MATCH rows under their own time limit (Vod::textMatchIds(): the same condition, at most
                // Vod::MATCH_IDS_MAX ids, cached and shared with page 1 of the list) give the exact facts as in the windowed
                // branch above (cold 7.1 M u set: Rikichi Riccosuskel's count and both spelling reads ran out, noindex)
                if ($ft !== '' && strpos($ft, FilterMatch::ITEMS_MARK) === false && method_exists('\app\common\model\Vod', 'textMatchIds')) {
                    $m = \app\common\model\Vod::textMatchIds(['vod_status' => ['eq', 1], $field => ['like', [$like], 'OR'], 'vod_recycle_time' => 0], '', 0);
                    if ($m !== null) {
                        $ids = array_slice($m['ids'], 0, self::LANDING_ROWS);
                        $rows = $ids ? \think\Db::name('vod')->field('vod_id,' . $field)->where('vod_id', 'in', $ids)->select() : [];
                        $rows = is_array($rows) ? $rows : (is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : []);
                        $facts = ['count' => min((int)$m['total'], \app\common\model\Vod::COUNT_BOUND + 1), 'spelling' => self::spellingOf($rows, $field, $folded), 'exact' => true];
                        try {
                            \think\Cache::set($key, $facts, 86400);
                            \think\Cache::set($key . '_good', $facts, self::LANDING_GOOD_TTL);
                        } catch (\Throwable $e) {
                            // not cached: read again next time
                        }
                        return $facts;
                    }
                }
            }
            $spelling = '';
            $got = [];
            if (!$exact || $count > 0) {
                // the stored spelling from the newest matching rows (visible rows, as counted); fp-port-b: without a count the
                // rows read are a lower bound of it (at least seo.landing_min of them make the page indexable)
                $need = $exact ? min(self::LANDING_ROWS, $count) : max(self::LANDING_ROWS, SeoSettings::int('seo.landing_min'));
                $q = \think\Db::name('vod')->field('vod_id,' . $field)->where('vod_status', 1)->where('vod_recycle_time', 0)->where($field, 'like', $like);
                if ($ft !== '' && $exact && $count <= \app\common\model\Vod::COUNT_BOUND) {
                    // round 7 (flood-r7 R7-1): the count above read every row of the pre-filter in time (at most COUNT_BOUND
                    // matches), so the spelling rows come through the same pre-filter, never by walking the primary key:
                    // ftIdsCheaper() only knew "more than the probe" for /search/actor/Rikichi+Riccosuskel/ (2,156 rows, MATCH
                    // 4,552 rows, 0.07 s) and the walk read 95,188 rows past LANDING_SQL_SEC (noindex on a cold visit)
                    $q->where($ft);
                } elseif ($ft !== '' && !$exact && \app\common\model\Vod::ftIdsCheaper($ft, $need, null, null)) {
                    $q->where($ft); // newest matches through the pre-filter when that is cheaper than walking the primary key
                }
                // at most $count rows exist: the walk stops at the last one instead of reading on
                $got = self::landingQuery('SELECT * FROM ' . $q->order('vod_id desc')->limit(max(1, $need))->buildSql() . ' landing_rows');
                if ($got === null) {
                    // fp-port-b (P2-2): the matching rows are too far apart for that walk (a rare value without an index path):
                    // the rows holding the value as a whole item, through the search table's field token, each checked with the
                    // same LIKE (landingTokenRows); out of time there too: the count without its spelling (or nothing at all):
                    // shown, not indexable, not kept for a day
                    $got = self::landingTokenRows($kind, $field, $value, $like);
                    if ($got === null) {
                        return $late(['count' => $count, 'spelling' => '', 'exact' => $exact]);
                    }
                    $byToken = true;
                }
                usort($got, function ($a, $b) { return (int)$b['vod_id'] <=> (int)$a['vod_id']; });
                foreach (array_column($got, $field) as $stored) {
                    foreach (explode(',', AvdbLanguage::decodeEntities((string)$stored)) as $token) {
                        $token = trim(preg_replace('/\s+/u', ' ', $token));
                        if ($token !== '' && SeoSettings::fold($token) === $folded) { $spelling = $token; break 2; }
                    }
                }
            }
            if (!$exact) {
                return $late(['count' => count($got), 'spelling' => $spelling, 'exact' => false]);
            }
            if (!empty($byToken)) {
                // the exact count with the spelling of the item rows (not the newest LIKE rows): shown, kept LANDING_LATE_TTL
                return $late(['count' => $count, 'spelling' => $spelling, 'exact' => true]);
            }
            $facts = ['count' => $count, 'spelling' => $spelling, 'exact' => true];
            try {
                \think\Cache::set($key, $facts, 86400);
                \think\Cache::set($key . '_good', $facts, self::LANDING_GOOD_TTL);
            } catch (\Throwable $e) {
                // not cached: read again next time
            }
            return $facts;
        } catch (\Throwable $error) {
            // round 8 (flood-r8 R8-1): a statement the request's deadline stopped (the session limit DbTimeout keeps under the time
            // left): no facts and nothing remembered, as before; head() sends a verified crawler to the time-limit fallback instead
            // of a noindex page
            if (class_exists(__NAMESPACE__ . '\\FtIndex') && FtIndex::isTimeout($error) && self::landingDeadlineNear()) {
                self::$landingCut[$key] = true;
            }
            return $none;
        }
    }

    /**
     * Round 7 (flood-r7 R7-3): the facts landingInfo() keeps 24 h for $kind / $value after a finished read (the bounded count,
     * 'exact'), or null when none are kept; never reads the database. Vod's numbered deep pages of the same list take a count
     * above COUNT_BOUND from them ("10,000+") instead of running a new bounded LIKE count per page.
     */
    public static function landingFactsKnown($kind, $value)
    {
        try {
            $key = 'seo_landing_' . sha1($kind . '|' . $value . '|' . (string)config('database.database') . '|' . (string)config('database.prefix'));
            $hit = \think\Cache::get($key);
            return is_array($hit) && isset($hit['count'], $hit['spelling']) ? $hit + ['exact' => true] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Round 8 (R8-1): landing facts that make the page indexable (a stored spelling and at least seo.landing_min videos). */
    private static function landingIndexable(array $facts): bool
    {
        return (string)($facts['spelling'] ?? '') !== '' && (int)($facts['count'] ?? 0) >= max(1, SeoSettings::int('seo.landing_min'));
    }

    /**
     * Round 9 (flood-r9 R9-1b, orchestrator O-20): facts of a read that did not finish (a late read's lower bound or nothing) that
     * neither make the page indexable nor prove it thin. An exact count (the bounded count finished; only the spelling came from
     * a shorter read) below seo.landing_min proves the page thin whatever the spelling: noindex for everyone, as a finished read's
     * facts below seo.landing_min (thin pages stay out of the index). Only called for facts that are not a finished read's (_good).
     */
    private static function landingUnproven(array $facts): bool
    {
        if (self::landingIndexable($facts)) {
            return false;
        }
        return empty($facts['exact']) || (int)($facts['count'] ?? 0) >= max(1, SeoSettings::int('seo.landing_min'));
    }

    /**
     * Round 9 (flood-r9 R9-1, orchestrator O-20): true when a landing's facts in this request were cut short by the deadline or
     * are a late read's lower bound / nothing (self::$landingCut): EdgeCache::finish() stores no copy of such a render, so a
     * visitor's noindex page never reaches a verified crawler as a cached copy (lane H).
     */
    public static function landingCutSeen(): bool
    {
        return !empty(self::$landingCut);
    }

    /**
     * Round 8 (R8-1): true when this request's database deadline (DbTimeout, the front end) limits the landing reads: less than
     * LANDING_SQL_SEC + one CAP_STEP left (FtIndex::capSec() cuts the next read's limit) or a statement timeout seen already
     * (a statement refused for a spent request included). No deadline (command line, admin): false.
     */
    private static function landingDeadlineNear(): bool
    {
        if (!class_exists(__NAMESPACE__ . '\\DbTimeout') || !method_exists(__NAMESPACE__ . '\\DbTimeout', 'remaining')) {
            return false;
        }
        $left = DbTimeout::remaining();
        if ($left === null) {
            return false;
        }
        return DbTimeout::seen() || $left < self::LANDING_SQL_SEC + DbTimeout::CAP_STEP;
    }

    /**
     * Round 8 (R8-1, head()'s time-limit fallback): this page is a tag / actor landing whose own facts the request's deadline cut short
     * (self::$landingCut, the key landingInfo() caches them under), the request comes from a verified crawler and the site lets
     * landing pages be indexed. Round 9 (R9-1b): or whose facts are a late read's lower bound / nothing (landingUnproven()); only
     * on pages whose noindex those facts decide.
     */
    private static function landingCrawlerFallback(array $param): bool
    {
        try {
            $landing = self::landing($param);
            if ($landing === null) {
                return false;
            }
            $key = 'seo_landing_' . sha1($landing[0] . '|' . $landing[1] . '|' . (string)config('database.database') . '|' . (string)config('database.prefix'));
            if (!(isset(self::$landingCut[$key]) && class_exists(__NAMESPACE__ . '\RequestGate') && RequestGate::crawler() !== ''
                && SeoSettings::bool('seo.landing') && !SeoSettings::bool('seo.hide_site'))) {
                return false;
            }
            // round 9 (flood-r9 R9-1b): the late-cache branch marks a value for up to LANDING_LATE_TTL, so the fallback is kept to
            // pages whose noindex the facts decide: a page that stays noindex whatever its facts say (a value of 1 or more than 60
            // characters, a list page after seo.noindex_after, a language search engines may not index: model()'s rules) keeps it
            $length = function_exists('mb_strlen') ? mb_strlen($landing[1], 'UTF-8') : strlen($landing[1]);
            $after = SeoSettings::int('seo.noindex_after');
            if ($length < 2 || $length > 60 || ($after > 0 && max(1, (int)($param['page'] ?? 1)) > $after)) {
                return false;
            }
            try {
                $indexed = AvdbLanguage::indexedCodes();
                if (is_array($indexed) && $indexed && !in_array(self::locale(), $indexed, true)) {
                    return false;
                }
            } catch (\Throwable $e) {
                // the language lists cannot answer: the rules above decide
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Round 6 (S-R6-1): the stored spelling of a landing value among rows ([vod_id, <column>] ...): the first comma item equal
     * to the value (folded) in the newest row (vod_id DESC, as the landing's LIKE read orders them); '' when every row holds
     * the value only inside a longer item.
     */
    private static function spellingOf(array $rows, string $field, string $folded): string
    {
        usort($rows, function ($a, $b) { return (int)($b['vod_id'] ?? 0) <=> (int)($a['vod_id'] ?? 0); });
        foreach ($rows as $r) {
            foreach (explode(',', AvdbLanguage::decodeEntities((string)($r[$field] ?? ''))) as $token) {
                $token = trim(preg_replace('/\s+/u', ' ', $token));
                if ($token !== '' && SeoSettings::fold($token) === $folded) {
                    return $token;
                }
            }
        }
        return '';
    }

    /**
     * Round 5 (D-1 (c)): landing facts of a tag value FtPlan::tagRoute() proves LIKE-exact, or null (the LIKE count decides):
     * count = the term map's exact total (= the LIKE count by the proof), at most COUNT_BOUND + 1 like the bounded count;
     * spelling = the first stored item equal to the value (folded) among the newest LANDING_ROWS rows of the token (read by
     * primary key with the landing's own LIKE, ordered by vod_id DESC as the LIKE read does).
     */
    private static function landingRouteFacts(string $value, string $folded): ?array
    {
        try {
            $tok = FtPlan::tagRoute($value);
            if ($tok === null) {
                return null;
            }
            $plan = FtPlan::search('', ['token' => $tok, 'page' => 1, 'per' => self::LANDING_ROWS, 'type_ids' => [], 'hidden' => [], 'order_time' => true]);
            if (($plan['path'] ?? '') !== 'map' || empty($plan['exact'])) {
                return null;
            }
            $count = min((int)$plan['total'], \app\common\model\Vod::COUNT_BOUND + 1);
            $ids = array_values(array_filter(array_map('intval', (array)($plan['ids'] ?? []))));
            $spelling = '';
            if ($ids) {
                $rows = \think\Db::name('vod')->field('vod_id,vod_tag')->where('vod_id', 'in', $ids)->where('vod_status', 1)->where('vod_recycle_time', 0)
                    ->where('vod_tag', 'like', '%' . addcslashes($value, '%_\\') . '%')->select();
                $rows = is_array($rows) ? $rows : (is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : []);
                if (count($rows) !== count($ids)) {
                    return null; // a token row the LIKE does not list: the LIKE count decides (the list falls back the same way)
                }
                usort($rows, function ($a, $b) { return (int)$b['vod_id'] <=> (int)$a['vod_id']; });
                foreach (array_column($rows, 'vod_tag') as $stored) {
                    foreach (explode(',', AvdbLanguage::decodeEntities((string)$stored)) as $token) {
                        $token = trim(preg_replace('/\s+/u', ' ', $token));
                        if ($token !== '' && SeoSettings::fold($token) === $folded) { $spelling = $token; break 2; }
                    }
                }
            }
            return ['count' => $count, 'spelling' => $spelling, 'exact' => true];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * fp-port-b (FINISH-PORT-REVIEW P2-2): visible rows, newest first, whose tag (actor) column holds the value as a whole item,
     * for a value whose LIKE walk ran out of LANDING_SQL_SEC (its matching rows are sparse): the search table's field token of
     * the item (FtText::fieldToken: the token 1.2.0 D-L7 counted, index-backed, any min token size) gives its newest candidate
     * ids (at most LANDING_TOKEN_IDS; the token's matches are few for such a value), each checked with the landing's own LIKE
     * on the visible rows, so every row returned is a row the count counts (a lower bound of it; a stale or missing search
     * document only makes it smaller). All checked candidates are returned: tg_ also holds vod_class items, so some rows hold
     * the value only inside a longer tag (the spelling loop skips them). [] = none; null = the search table cannot answer now
     * (absent, not ready, not in 'ft' mode, no ASCII field token (e.g. a stopword), out of LANDING_SQL_SEC, an error).
     */
    private static function landingTokenRows(string $kind, string $field, string $value, string $like): ?array
    {
        try {
            if (!class_exists(__NAMESPACE__ . '\\FtIndex') || !class_exists(__NAMESPACE__ . '\\FtText') || !FtIndex::installed()) {
                return null;
            }
            $s = FtIndex::state(true);
            if ((int)($s['ready'] ?? 0) !== 1 || (string)($s['mode'] ?? '') !== 'ft') {
                return null;
            }
            $tok = FtText::fieldToken($kind === 'actor' ? 'ac' : 'tg', $value);
            if (!preg_match('/^(?:tg|ac)_[a-z0-9_]{1,81}$/D', $tok)) {
                return null;
            }
            $ids = self::landingQuery('SELECT `vod_id` FROM ' . FtIndex::t(FtIndex::T_FT) . " WHERE MATCH(`ft_text`) AGAINST('+" . $tok . "' IN BOOLEAN MODE) ORDER BY `vod_id` DESC LIMIT " . self::LANDING_TOKEN_IDS);
            if ($ids === null) {
                return null;
            }
            $ids = array_values(array_unique(array_map('intval', array_column($ids, 'vod_id'))));
            if (!$ids) {
                return [];
            }
            $q = \think\Db::name('vod')->field('vod_id,' . $field)->where('vod_id', 'in', $ids)->where('vod_status', 1)->where('vod_recycle_time', 0)->where($field, 'like', $like);
            $rows = self::landingQuery('SELECT * FROM ' . $q->buildSql() . ' landing_token_rows');
            if ($rows !== null) {
                usort($rows, function ($a, $b) { return (int)$b['vod_id'] <=> (int)$a['vod_id']; }); // newest first
            }
            return $rows;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** A neutral category text of the visitor catalog ('' when the catalog does not ship the key yet). */
    private static function neutralText($key, $name, $siteName, $locale)
    {
        $text = TemplateText::raw($key, ['name' => $name, 'site' => $siteName], $locale);
        return $text === $key || strpos($text, '{') !== false ? '' : $text;
    }

    /** Rating metas (C.7): always rating=adult, plus the RTA label when seo.rta = 1. Printed by SiteHead (step 6). */
    public static function ratingMetas()
    {
        $html = '<meta name="rating" content="adult" data-jv-head>';
        if (SeoSettings::bool('seo.rta')) { $html .= "\n" . '<meta name="RATING" content="' . self::RTA . '" data-jv-head>'; }
        return $html;
    }

    /** Verification metas (A.5.3, C.7), only the filled ones; SiteHead prints them on the home page only (step 8). */
    public static function verificationMetas()
    {
        $html = [];
        foreach (self::VERIFY_METAS as $key => $name) {
            $code = (string)SeoSettings::get('seo.' . $key);
            if ($code !== '') { $html[] = '<meta name="' . $name . '" content="' . self::escape($code) . '" data-jv-head>'; }
        }
        return implode("\n", $html);
    }

    // ------------------------------------------------------------------ the page model

    /** Video tokens (C.2). $title: text() of the name at 300 when the caller has it already (round-4 G9: not cleaned twice). */
    private static function videoTokens(array $obj, $siteName, $title = null)
    {
        $name = is_string($title) ? $title : self::text($obj['vod_display_name'] ?? ($obj['vod_name'] ?? ''), 300);
        $actors = self::names($obj['vod_display_actor'] ?? ($obj['vod_actor'] ?? ''), 2);
        $seconds = self::durationSeconds($obj['vod_duration'] ?? '');
        $type = is_array($obj['type'] ?? null) ? $obj['type'] : [];
        return ['title' => $name, 'code' => self::releaseCode($obj['vod_remarks'] ?? ''), 'actors' => implode(', ', $actors),
            'studio' => self::text($obj['vod_display_writer'] ?? ($obj['vod_writer'] ?? ''), 120),
            'category' => $type ? self::categoryText($type) : '',
            'year' => self::releaseYear($obj['vod_pubdate'] ?? ''), 'duration' => $seconds >= 60 ? (string)intdiv($seconds, 60) : '',
            'site' => $siteName];
    }

    /** Round-4 G9: [value => [second, 'Y' of releaseDate() or '']] (rows share release dates; the "future" rule is per second). */
    private static $yearMemo = [];

    /** The release year of a video ('Y' of releaseDate(), '' when none), remembered per value and second like releaseDate(). */
    private static function releaseYear($value)
    {
        $now = time();
        $k = is_scalar($value) && strlen((string)$value) <= 64 ? (string)$value : null;
        if ($k !== null && isset(self::$yearMemo[$k]) && self::$yearMemo[$k][0] === $now) {
            return self::$yearMemo[$k][1];
        }
        $date = self::releaseDate($value);
        $year = $date ? $date->format('Y') : '';
        if ($k !== null) {
            if (count(self::$yearMemo) >= self::MEMO_MAX) { self::$yearMemo = []; }
            self::$yearMemo[$k] = [$now, $year];
        }
        return $year;
    }

    /**
     * TemplateText::category() of a category row in the current language, once per category and language (round-3 G9: the
     * English check walks the whole catalog for every sitemap row otherwise). It reads type_name / type_en only.
     */
    private static function categoryText(array $type)
    {
        $locale = self::locale();
        $k = $locale . "\x1F" . (is_scalar($type['type_name'] ?? null) ? (string)$type['type_name'] : '') . "\x1F"
            . (is_scalar($type['type_en'] ?? null) ? (string)$type['type_en'] : '');
        if (!isset(self::$categoryMemo[$k])) {
            if (count(self::$categoryMemo) >= self::MEMO_MAX) { self::$categoryMemo = []; }
            self::$categoryMemo[$k] = TemplateText::category($type, $locale);
        }
        return self::$categoryMemo[$k];
    }

    /** Video description (C.2 watch row, CF09). */
    private static function videoDescription(array $obj, array $tokens)
    {
        $description = self::summary($obj['vod_display_blurb'] ?? ($obj['vod_blurb'] ?? ''));
        if ($description === '') { $description = self::summary($obj['vod_display_content'] ?? ($obj['vod_content'] ?? '')); }
        if ($description !== '') { return $description; }
        return self::videoDescriptionBuilt($tokens);
    }

    /** Round-4 G9: preview() calls running (their SeoSettings overrides may hold another seo.dtpl_video). */
    private static $previewDepth = 0;
    /** Round-4 G9: [stored raw value key, SeoSettings::get('seo.dtpl_video')] of this process. */
    private static $ownerVideoMemo = null;

    /**
     * SeoSettings::get('seo.dtpl_video') once per stored value (round-4 G9: the getter validates the stored value on every
     * call, 1.8 us per built sitemap row). The answer is a function of the stored value in $GLOBALS['config']['seo'] (the
     * memo key) unless an override applies: overrides of this key come only from preview() (SeoSettings::withOverrides;
     * Sitemap overrides seo.hide_site alone), which bypasses the memo, as does a request without that config array.
     */
    private static function ownerVideoPattern()
    {
        if (self::$previewDepth > 0 || !isset($GLOBALS['config']['seo']) || !is_array($GLOBALS['config']['seo'])) {
            return (string)SeoSettings::get('seo.dtpl_video');
        }
        $raw = array_key_exists('dtpl_video', $GLOBALS['config']['seo']) ? $GLOBALS['config']['seo']['dtpl_video'] : null;
        if (!($raw === null || is_scalar($raw))) {
            return (string)SeoSettings::get('seo.dtpl_video');
        }
        $k = $raw === null ? "\x00" : gettype($raw) . ':' . (string)$raw;
        if (self::$ownerVideoMemo !== null && self::$ownerVideoMemo[0] === $k) {
            return self::$ownerVideoMemo[1];
        }
        $v = (string)SeoSettings::get('seo.dtpl_video');
        self::$ownerVideoMemo = [$k, $v];
        return $v;
    }

    /** The video description built from the tokens (no blurb, no content): the owner pattern, else the C.2 parts. */
    private static function videoDescriptionBuilt(array $tokens)
    {
        $owner = self::ownerVideoPattern();
        if ($owner !== '') {
            $description = self::summary(self::fixStops(self::assemble($owner, $tokens, false, 1000)));
            if ($description !== '') { return $description; }
        }
        $code = $tokens['code'];
        $lead = $code !== '' && stripos($tokens['title'], $code) === false ? 'seo.dtpl_video_code' : 'seo.dtpl_video';
        $parts = [];
        $part = self::part($lead, $tokens);
        if ($part !== '') { $parts[] = $part; }
        foreach (self::tailParts($tokens) as $part) { $parts[] = $part; }
        // round-4 G9: parts that are fixed points of text() with single spaces, joined by single spaces, make a text whose
        // first summary pass is the cut alone
        $fixed = true;
        foreach ($parts as $part) {
            if (!self::fixedText($part, true)) { $fixed = false; break; }
        }
        return self::summary(implode(' ', $parts), 200, $fixed);
    }

    /** Round-4 G9: per locale [memo allowed, token names] of the three description tail parts (with / studio / minutes). */
    private static $tailInfo = [];
    /** Round-4 G9: the non-empty tail parts per locale and the values of the tokens their patterns name. */
    private static $tailMemo = [];

    /**
     * The non-empty parts seo.d_with, seo.d_studio, seo.d_minutes in this order, as videoDescriptionBuilt() appends them.
     * Each part is a pure function of its pattern (per locale) and the values of the tokens it names (part()), so the three
     * are remembered together under one key (one lookup per sitemap row instead of three part() calls); not when a pattern
     * holds a separator or names the title or the code (then every row computes them, as part() would).
     */
    private static function tailParts(array $tokens)
    {
        $keys = ['seo.d_with', 'seo.d_studio', 'seo.d_minutes'];
        $loc = (string)self::locale();
        if (!isset(self::$tailInfo[$loc])) {
            $ok = true;
            $names = [];
            foreach ($keys as $k) {
                $p = self::pattern($k);
                if (strpos($p, '{sep}') !== false || strpos($p, "\x1F") !== false) { $ok = false; }
                foreach (preg_match_all('/\{([a-z]+)\}/', $p, $m) ? $m[1] : [] as $n) {
                    if ($n === 'title' || $n === 'code') { $ok = false; }
                    $names[$n] = true;
                }
            }
            if (count(self::$tailInfo) >= self::MEMO_MAX) { self::$tailInfo = []; }
            self::$tailInfo[$loc] = [$ok, array_keys($names)];
        }
        $info = self::$tailInfo[$loc];
        $key = null;
        if ($info[0]) {
            $key = $loc;
            foreach ($info[1] as $name) {
                $key .= "\x1E" . (array_key_exists($name, $tokens) ? (is_scalar($tokens[$name]) ? (string)$tokens[$name] : "\x00a") : "\x00m");
            }
            if (strlen($key) > 1024) { $key = null; }
            elseif (isset(self::$tailMemo[$key])) { return self::$tailMemo[$key]; }
        }
        $out = [];
        foreach ($keys as $k) {
            $part = self::part($k, $tokens);
            if ($part !== '') { $out[] = $part; }
        }
        if ($key !== null) {
            if (count(self::$tailMemo) >= self::MEMO_MAX) { self::$tailMemo = []; }
            self::$tailMemo[$key] = $out;
        }
        return $out;
    }

    private static function breadcrumbHome()
    {
        $name = self::raw('seo.breadcrumb_home');
        if ($name === '' || $name === 'seo.breadcrumb_home') { $name = self::raw('home'); }
        return ($name === '' || $name === 'home') ? self::BUILTIN['seo.breadcrumb_home'] : $name;
    }

    private static function pick(array $variants, $locale)
    {
        return $variants[$locale]['url'] ?? ($variants[AvdbLanguage::defaultLocale()]['url'] ?? '');
    }

    /** Breadcrumb trail (C.6): Home > Category (when known) > page name. */
    private static function breadcrumb(array $type, $name, $locale)
    {
        $items = [['name' => self::breadcrumbHome(), 'url' => self::pick(Sitemap::homeVariants(), $locale)]];
        if ($type && (int)($type['type_id'] ?? 0) > 0) {
            $url = '';
            try { $url = self::pick(Sitemap::categoryVariants($type), $locale); } catch (\Throwable $error) { $url = ''; }
            $items[] = ['name' => TemplateText::category($type, $locale), 'url' => $url];
        }
        if (self::text($name, 300) !== '') { $items[] = ['name' => self::text($name, 300), 'url' => '']; }
        return count($items) >= 2 ? $items : [];
    }

    /** Legal page facts (F.1): LegalPages::seo($slug) of step 9 when present, else the catalog page name. */
    private static function legalPage($slug)
    {
        if (class_exists('\app\common\util\LegalPages') && method_exists('\app\common\util\LegalPages', 'seo')) {
            try {
                $page = \app\common\util\LegalPages::seo($slug);
                if (is_array($page) && trim((string)($page['title'] ?? '')) !== '') {
                    return ['title' => (string)$page['title'], 'body' => (string)($page['body'] ?? '')];
                }
            } catch (\Throwable $error) { /* the catalog name below */ }
        }
        return ['title' => self::pattern('legal.' . $slug), 'body' => ''];
    }

    /**
     * Everything the head prints, as data (also used by preview()). $preview = true skips URL building (admin).
     * Keys: title, description, canonical, variants, hreflang, noindex, nofollow, schema, og_type, image, video,
     * breadcrumb, graph.
     */
    private static function model(array $site, array $obj, array $param, $preview = false)
    {
        $action = strtolower((string)($site['controller_action'] ?? 'index/index'));
        // /label-<file>/ is routed to label/index with file=<file>: rules and canonical follow the label file (C.3).
        if ($action === 'label/index' && isset($param['file']) && is_scalar($param['file'])
            && preg_match('/^[a-z0-9_-]{1,64}$/D', strtolower((string)$param['file']))) {
            $action = 'label/' . strtolower((string)$param['file']);
        }
        $page = max(1, (int)($param['page'] ?? 1));
        $pageParameters = $page > 1 ? ['page'=>$page] : [];
        $locale = self::locale();
        $default = AvdbLanguage::defaultLocale();
        $siteName = self::text($site['site_name'] ?? '', 160);
        $m = ['action' => $action, 'title' => $siteName, 'description' => '', 'variants' => [], 'canonical' => '',
            'hreflang' => true, 'noindex' => false, 'nofollow' => false, 'list' => false, 'schema' => 'WebPage',
            'og_type' => 'website', 'image' => '', 'video' => null, 'breadcrumb' => [], 'graph' => [], 'video_meta' => [],
            'name' => '', 'family' => strpos($action, 'vod/') === 0 || $action === 'index/index', 'self' => true, 'base' => null];
        $hadLanguage = array_key_exists('translang', $_GET);
        $oldLanguage = $_GET['translang'] ?? null;
        unset($_GET['translang']);
        try {
            $legalSlug = preg_match('/^label\/([a-z0-9]+)$/', $action, $lm) && in_array($lm[1], SeoSettings::legalPages(), true) ? $lm[1] : '';
            if ($action === 'index/index') {
                $m['family'] = true;
                if ($page > 1) {
                    // index-<n> lists the latest videos: its own title and description, never the home texts again.
                    $latest = self::raw('ui.latest_videos');
                    $m['title'] = self::assemble(self::pattern('seo.tpl_home_paged'), ['latest' => $latest, 'page' => self::pageToken($page), 'site' => $siteName]);
                    $m['description'] = self::listingSummary($latest, $site, $page);
                    $m['schema'] = 'CollectionPage';
                    $m['list'] = true;
                    // Same URL as the paging links (index-<n>), see mac_url('index/index') PAGELINK handling.
                    if (!$preview) { $m['variants'] = self::interfaceVariants(mac_url_page(mac_url('index/index', ['page'=>'PAGELINK']), $page)); }
                } else {
                    $home = $locale !== $default ? SeoSettings::langHome($locale) : ['title' => '', 'desc' => ''];
                    $homeTitle = self::text(isset($site['site_home_title']) ? $site['site_home_title'] : SeoSettings::get('site.site_home_title'), 160);
                    $m['title'] = $home['title'] !== '' ? self::text($home['title'], 160) : ($homeTitle !== '' ? $homeTitle : $siteName);
                    // CF10: never the description of another language. Empty here -> the generic fallback below
                    // (siteDescriptionFor(), then the title, which already falls back to the home title / site name).
                    $m['description'] = self::summary($home['desc'] !== '' ? $home['desc'] : self::siteDescriptionFor($site));
                    $m['schema'] = 'home';
                    if (!$preview) { $m['variants'] = Sitemap::homeVariants(); }
                }
            } elseif (in_array($action, ['vod/detail', 'vod/play'], true)) {
                $tokens = self::videoTokens($obj, $siteName);
                $m['name'] = $tokens['title'];
                $m['title'] = self::assemble(self::pattern('seo.tpl_video', SeoSettings::get('seo.tpl_video')), $tokens);
                $m['description'] = self::videoDescription($obj, $tokens);
                if ($m['description'] === '') { $m['description'] = $tokens['title'] !== '' ? $tokens['title'] : $siteName; }
                $m['og_type'] = 'video.movie';
                $m['family'] = true;
                $m['noindex'] = !empty($obj['vod_pwd']) || ($action === 'vod/play' && !empty($obj['vod_pwd_play']));
                $type = is_array($obj['type'] ?? null) ? $obj['type'] : [];
                $plays = is_array($obj['vod_play_list'] ?? null) ? $obj['vod_play_list'] : [];
                // One URL per video (C.3, CF01): the watch page of source 1 is canonical.
                $selfCanonical = true;
                $target = null;
                if ($action === 'vod/play') {
                    $sid = max(1, (int)($param['sid'] ?? 1));
                    $nid = max(1, (int)($param['nid'] ?? 1));
                    $target = ['sid' => $sid, 'nid' => $nid];
                    if ($sid > 1 && isset($plays[1]['urls'][$nid])) { $target = ['sid' => 1, 'nid' => $nid]; $selfCanonical = false; }
                } elseif ($plays) {
                    $first = isset($plays[1]['urls'][1]) ? 1 : 0;
                    if (!$first) { foreach ($plays as $sid => $row) { if (isset($row['urls'][1])) { $first = (int)$sid; break; } } }
                    if ($first > 0) { $target = ['sid' => $first, 'nid' => 1]; $selfCanonical = false; }
                }
                if (!$preview) {
                    $m['variants'] = $target !== null ? Sitemap::playbackVariants($obj, $target) : Sitemap::detailVariants($obj);
                    $m['breadcrumb'] = self::breadcrumb($type, $tokens['title'], $locale);
                    $m['image'] = self::absoluteAsset(mac_url_img($obj['vod_pic'] ?? ''));
                }
                // The detail page and other sources point to the watch page: no hreflang, no VideoObject there.
                $m['hreflang'] = $selfCanonical; $m['self'] = $selfCanonical;
                $m['schema'] = $selfCanonical ? 'video' : 'breadcrumb';
                $seconds = self::durationSeconds($obj['vod_duration'] ?? '');
                $upload = self::uploadDate($obj);
                $m['video_meta'] = ['duration' => $seconds, 'release' => $upload,
                    'tags' => self::names($obj['vod_display_tag'] ?? ($obj['vod_tag'] ?? ''), 5)];
                if ($selfCanonical && $m['image'] !== '' && $upload !== '') {
                    $video = ['@type' => 'VideoObject', 'name' => $tokens['title'], 'description' => $m['description'],
                        'thumbnailUrl' => [$m['image']], 'uploadDate' => $upload];
                    if ($action === 'vod/play') {
                        // embedUrl is the player of this episode, never the page the video lives on; a direct
                        // media file is a contentUrl. Encrypted, paid, trial or wrapped sources are left out.
                        $media = self::playerMediaUrl($obj);
                        if ($media !== '') {
                            $isFile = (bool)preg_match('~\.(?:m3u8|mp4|m4v|webm|mov|mpd|flv|ogv)(?:$|[?#])~i', $media);
                            $video[$isFile ? 'contentUrl' : 'embedUrl'] = $media;
                        }
                    }
                    $duration = self::isoDuration($obj['vod_duration'] ?? '');
                    if ($duration !== '') { $video['duration'] = $duration; }
                    $actors = self::names($obj['vod_display_actor'] ?? ($obj['vod_actor'] ?? ''));
                    if ($actors) { $video['actor'] = array_map(function ($name) { return ['@type'=>'Person','name'=>$name]; }, $actors); }
                    $genres = self::names($obj['vod_display_class'] ?? ($obj['vod_class'] ?? ''));
                    if (!$genres && $type) { $genres = [TemplateText::category($type, $locale)]; }
                    if ($genres) { $video['genre'] = count($genres) === 1 ? $genres[0] : $genres; }
                    $video['isFamilyFriendly'] = false;
                    $m['video'] = $video;
                }
            } elseif (in_array($action, ['vod/type','vod/show','art/type','art/show','actor/type','actor/show'], true)) {
                $siteDescription = self::text($site['site_description'] ?? '', 100000);
                $name = self::text($obj['type_title'] ?? '', 180);
                if ($name === '' || $name === $siteName) { $name = self::text(TemplateText::category($obj, $locale), 180); }
                $filtered = [];
                foreach (['class','area','lang','year','letter','level','state','version','actor','director','tag','by','order','score','sex','blood','starsign'] as $field) {
                    if (isset($param[$field]) && is_scalar($param[$field]) && (string)$param[$field] !== '' && (string)$param[$field] !== '0') { // mac_param_url HTML-escapes values once; URL builders need the original scalar.
                        $filtered[$field] = html_entity_decode((string)$param[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
                }
                $shown = $name;
                if (substr($action, -5) === '/show' && $filtered) {
                    $values = [];
                    foreach (['class','area','lang','year','letter','state','version','actor','director','tag'] as $field) { if (isset($filtered[$field])) { $values[] = self::text($filtered[$field], 60); } }
                    if ($values) { $shown = trim($name . ' ' . implode(' ', $values)); }
                }
                $m['title'] = self::assemble(self::pattern('seo.tpl_category', SeoSettings::get('seo.tpl_category')), ['category' => $shown, 'page' => self::pageToken($page), 'site' => $siteName]);
                // 1.2.0 (HO-E-14): a category without its own title (the neutral installer seed) reads "{name} videos - {site}"
                // in every language on its first page, when the visitor catalog ships the neutral texts (lane T).
                $ownTitle = self::text($obj['type_title'] ?? '', 180);
                $neutral = ($ownTitle === '' || $ownTitle === $siteName) && $page <= 1 && !$filtered ? self::neutralText('seo.cat_title_neutral', $name, $siteName, $locale) : '';
                if ($neutral !== '') { $m['title'] = self::text($neutral, 350); }
                $ownText = self::text($obj['type_des'] ?? '', 100000);
                // Category descriptions that only repeat the site description are not their own text.
                $m['description'] = $ownText !== '' && $ownText !== $siteDescription ? self::summary($ownText) : '';
                if ($m['description'] === '' && ($ownText === '' || $ownText === $siteDescription) && !$filtered) {
                    $neutralDesc = self::neutralText('seo.cat_desc_neutral', $name, $siteName, $locale);
                    if ($neutralDesc !== '') { $m['description'] = self::summary($neutralDesc); }
                }
                if ($m['description'] === '') { $m['description'] = self::listingSummary($shown, $site, $page, strpos($action, 'vod/') === 0); }
                $m['schema'] = 'CollectionPage';
                $m['list'] = true;
                if (strpos($action, 'vod/') !== 0 && self::moduleEmpty(explode('/', $action)[0])) { $m['noindex'] = true; }
                $catWord = in_array($action, ['vod/type', 'vod/show'], true) && !empty($param['wd']) && is_scalar($param['wd'])
                    ? \app\common\model\Vod::normalizeSearchWord($param['wd']) : '';
                if ($catWord !== '') {
                    // round-3 verify N10: a keyword search inside the category is a search result page: noindex, with a
                    // self-canonical of that search (its page number kept; never the plain category page)
                    $m['noindex'] = true;
                    if (!$preview) {
                        $catUrl = \app\common\model\Vod::categorySearchUrl($obj, substr($action, -4) === 'show' ? 'show' : 'type', $param, (int)$page);
                        if ($catUrl !== '') { $m['variants'] = self::interfaceVariants($catUrl); }
                    }
                } elseif ($action === 'vod/type') {
                    if (!$preview) {
                        $m['variants'] = Sitemap::categoryVariants($obj, $page);
                        $m['breadcrumb'] = self::breadcrumb([], $name, $locale);
                    }
                } else {
                    $m['noindex'] = $m['noindex'] || (bool)$filtered;
                    $flag = substr($action, -4) === 'show' ? 'show' : 'type';
                    if (!$preview) { $m['variants'] = self::interfaceVariants(mac_url_type($obj, array_merge($pageParameters, $filtered), $flag)); }
                }
            } elseif (in_array($action, ['actor/detail','topic/detail','art/detail'], true)) {
                $section = explode('/', $action)[0];
                $name = self::text($obj[$section . '_name'] ?? '', 300);
                $custom = $section === 'actor' ? SeoSettings::custom('actor', $name) : null;
                if ($section === 'topic' && self::text($obj['topic_title'] ?? '', 300) !== '') { $name = self::text($obj['topic_title'], 300); }
                $m['title'] = $custom && $custom['title'] !== '' ? self::text($custom['title'], 350)
                    : self::assemble(self::pattern('seo.tpl_list'), ['list' => $name, 'page' => self::pageToken($page), 'site' => $siteName]);
                $own = $custom && $custom['desc'] !== '' ? $custom['desc'] : ($section === 'topic' && self::text($obj['topic_des'] ?? '', 10) !== '' ? $obj['topic_des'] : '');
                $m['description'] = self::summary($own !== '' ? $own : ($obj[$section . '_blurb'] ?? ($obj[$section . '_content'] ?? $name)));
                if (!$preview) {
                    if ($section === 'actor') { $path = mac_url('actor/detail', $pageParameters, $obj); }
                    elseif ($section === 'topic') { $path = mac_url('topic/detail', $pageParameters, $obj); }
                    else { $path = mac_url_art_detail($obj, ['page'=>$page]); }
                    // These entities have no imported per-locale metadata; do not advertise invented translations.
                    $m['variants'] = Sitemap::variants(Sitemap::absolute($path), []);
                }
                $m['hreflang'] = false;
                $m['noindex'] = !empty($obj[$section . '_pwd']);
            } elseif ($legalSlug !== '') {
                // Legal pages (F.1, CF03) render the theme's art/detail template under label/<slug>: their own branch,
                // before the generic label branch (which keeps theme label pages out of the index).
                $legal = self::legalPage($legalSlug);
                $m['title'] = self::assemble('{list}{sep}{site}', ['list' => $legal['title'], 'site' => $siteName]);
                $m['description'] = $legal['body'] !== '' ? self::summary($legal['body'], 160) : self::listingSummary($legal['title'], $site);
                $m['hreflang'] = false; // English text only
                if (!$preview) {
                    $url = Sitemap::absolute(mac_url('label/' . $legalSlug));
                    $m['variants'] = $url === '' ? [] : [$locale => ['url' => $url, 'updated_at' => 0], $default => ['url' => $url, 'updated_at' => 0]];
                }
            } elseif ($action === 'vod/search' && ($landing = self::landing($param)) !== null) {
                list($kind, $value) = $landing;
                $info = $preview && isset($param['_count']) ? ['count' => (int)$param['_count'], 'spelling' => $value, 'exact' => true] : self::landingInfo($kind, $value);
                $spelling = $info['spelling'] !== '' ? $info['spelling'] : $value;
                $custom = SeoSettings::custom($kind, $spelling);
                // fp-port-c (FINISH-PORT-REVIEW-b P3-a): the list filters by the value cut at search_len (Vod::searchLenCheck, 1.1's
                // rule) while the page's links keep the requested one (Vod::searchLenParam): the printed number is the count of the
                // cut value, i.e. of the rows the page lists; spelling and indexability stay those of the requested value
                $shown = $info;
                if (!$preview && class_exists('\app\common\model\Vod') && method_exists('\app\common\model\Vod', 'searchLenCheck')) {
                    try {
                        $lp = $param; // every key mac_search_len_check() reads, so a short array never raises a notice
                        foreach (['wd', 'tag', 'class', 'letter', 'name', 'state', 'level', 'area', 'lang', 'version', 'actor', 'director', 'starsign', 'blood'] as $lk) {
                            if (!isset($lp[$lk]) || !is_scalar($lp[$lk])) { $lp[$lk] = ''; }
                        }
                        $cut = self::landing(\app\common\model\Vod::searchLenCheck($lp));
                        if ($cut !== null && $cut[0] === $kind && $cut[1] !== $value) {
                            $shown = self::landingInfo($kind, $cut[1]);
                        }
                    } catch (\Throwable $e) {
                        $shown = ['count' => 0, 'spelling' => '', 'exact' => false]; // no number rather than another list's count
                    }
                }
                // fp-port-b (FINISH-PORT-REVIEW P2-2): a count above the bound of the bounded count is "10000+", never the bound
                // itself; a count that is only a lower bound (the count ran out of time) prints no number
                $countText = empty($shown['exact']) ? '' : ((int)$shown['count'] > \app\common\model\Vod::COUNT_BOUND ? \app\common\model\Vod::COUNT_BOUND . '+' : (string)(int)$shown['count']);
                $tokens = [$kind => $spelling, 'page' => self::pageToken($page), 'site' => $siteName, 'count' => $countText];
                if ($custom && $custom['title'] !== '') {
                    $m['title'] = self::assemble('{list}{sep}{page}', ['list' => $custom['title'], 'page' => self::pageToken($page)], false);
                } else {
                    $m['title'] = self::assemble(self::pattern('seo.tpl_' . $kind, SeoSettings::get('seo.tpl_' . $kind)), $tokens);
                }
                if ($custom && $custom['desc'] !== '') {
                    $m['description'] = self::summary($custom['desc']);
                } else {
                    $lead = $countText !== '' && $shown['count'] > 0 ? self::fixStops(self::assemble(self::pattern('seo.dtpl_' . $kind), $tokens, false, 1000)) : '';
                    $rest = self::text(self::siteDescriptionFor($site), 100000);
                    $m['description'] = self::summary(trim($lead . ' ' . $rest));
                }
                $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
                // Indexable only in the stored spelling, with enough videos (C.4, CF11, SD5).
                $m['noindex'] = !(SeoSettings::bool('seo.landing') && $length >= 2 && $length <= 60 && $info['spelling'] === $value
                    && $info['count'] >= SeoSettings::int('seo.landing_min'));
                $m['schema'] = 'CollectionPage';
                $m['list'] = true;
                if (!$preview) {
                    $m['base'] = self::queryBase('vod/search', array_merge([$kind => $value], $pageParameters));
                    // A tag / actress holding "/" or "\" gets the query form (/search/?tag=...): Apache answers 404 for
                    // %2F / %5C in a path. Every other landing page keeps its indexed path form (/search/tag/<name>/).
                    $landingQuery = strpbrk($spelling, '/\\') !== false ? self::queryBase('vod/search', array_merge([$kind => $spelling], $pageParameters)) : '';
                    $m['variants'] = self::interfaceVariants($landingQuery !== '' ? $landingQuery : mac_url('vod/search', array_merge([$kind => $spelling], $pageParameters)));
                    $m['breadcrumb'] = self::breadcrumb([], $spelling, $locale);
                }
            } elseif (strpos($action, '/search') !== false || $action === 'vod/down' || strpos($action, 'user/') === 0
                || strpos($action, 'comment/') === 0 || strpos($action, 'gbook/') === 0) {
                $m['noindex'] = true;
                $key = $action === 'vod/down' ? 'downloads' : (strpos($action, '/search') !== false ? 'search_results' : 'account');
                $title = self::raw($key);
                if (!empty($param['wd']) && is_scalar($param['wd'])) { $title .= ': ' . self::text($param['wd'], 120); }
                $m['title'] = self::assemble('{list}{sep}{page}{sep}{site}', ['list' => $title, 'page' => self::pageToken($page), 'site' => $siteName]);
                $m['description'] = self::listingSummary($title, $site, $page);
                // Private, search and download flows are deliberately absent from the sitemap.
                if (strpos($action, '/search') !== false && !$preview) {
                    // Search pages stay noindex but carry a self-canonical that keeps the query and page number.
                    $query = [];
                    foreach (['wd','class','area','lang','year','letter','actor','director','tag','by','order','state','version','level'] as $field) {
                        if (isset($param[$field]) && is_scalar($param[$field]) && (string)$param[$field] !== '' && (string)$param[$field] !== '0') {
                            $query[$field] = html_entity_decode((string)$param[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
                    }
                    $m['base'] = self::queryBase($action, array_merge($query, $pageParameters));
                    // The self-canonical uses the query form too (/search/?wd=...): Apache answers 404 for a
                    // path-form word holding "/" (%2F).
                    $m['variants'] = self::interfaceVariants($m['base'] !== '' ? $m['base'] : mac_url($action, array_merge($query, $pageParameters)));
                }
            } else {
                $names = ['actor/index'=>'performers','topic/index'=>'collections','art/index'=>'articles',
                    'vod/index'=>'videos','map/index'=>'latest_updates','label/new'=>'recently_added',
                    'label/hot'=>'popular','label/sort'=>'videos','label/up'=>'most_liked','label/score'=>'highest_rated'];
                if (isset($names[$action])) {
                    $title = self::raw($names[$action]);
                    $m['title'] = self::assemble(self::pattern('seo.tpl_list', SeoSettings::get('seo.tpl_list')), ['list' => $title, 'page' => self::pageToken($page), 'site' => $siteName]);
                    $m['description'] = self::listingSummary($title, $site, $page);
                    if (!$preview) { $m['variants'] = self::interfaceVariants(mac_url($action, $pageParameters)); }
                    $m['schema'] = 'CollectionPage';
                    $m['list'] = true;
                    // Performers, collections and articles are empty on many installs: no thin pages in the index.
                    if (self::moduleEmpty(explode('/', $action)[0])) { $m['noindex'] = true; }
                    // vod/index is a near-copy of /label/sort/: self-canonical, but out of the index (C.3, C.4).
                    if ($action === 'vod/index') { $m['noindex'] = true; }
                } elseif (strpos($action, 'label/') === 0 && preg_match('/^label\/([a-z0-9_-]+)$/', $action, $label)) {
                    // Theme-defined label pages (label/<file>, often account or favorites prompts): translated title
                    // and a self-canonical with the page number, but kept out of the index like before.
                    $title = self::labelTitle($label[1]);
                    $m['title'] = self::assemble(self::pattern('seo.tpl_list'), ['list' => $title, 'page' => self::pageToken($page), 'site' => $siteName]);
                    $m['description'] = self::listingSummary($title, $site, $page);
                    if (!$preview) { $m['variants'] = self::interfaceVariants(mac_url($action, $pageParameters)); }
                    $m['schema'] = 'CollectionPage';
                    $m['noindex'] = true;
                } else { $m['noindex'] = true; }
            }
            // XVID-NOLIMIT-BRIEF B3 / C3 / C4: a continuation page (after= on category lists, cursor= on searches) keeps its
            // cursor in the canonical, appended last exactly as the pager's link (AvdbLanguage::cursorUrl) and
            // Vod::searchCanonical() put it, so the edge cache stores it instead of answering 301 to the first page; such
            // pages are never indexed
            $cont = self::continuation($action);
            if ($cont !== '' && !$preview) {
                foreach ($m['variants'] as $variantLang => $row) {
                    if (is_array($row) && isset($row['url']) && is_string($row['url']) && $row['url'] !== '') {
                        $m['variants'][$variantLang]['url'] = $row['url'] . (strpos($row['url'], '?') === false ? '?' : '&') . $cont;
                    }
                }
                if (is_string($m['base']) && $m['base'] !== '') {
                    $m['base'] .= (strpos($m['base'], '?') === false ? '?' : '&') . $cont;
                }
                $m['noindex'] = true;
                $m['hreflang'] = false;
            }
        } finally {
            if ($hadLanguage) { $_GET['translang'] = $oldLanguage; }
        }
        if ($m['description'] === '') { $m['description'] = self::summary(self::siteDescriptionFor($site)); }
        if ($m['description'] === '') { $m['description'] = $m['title']; }
        // Robots (C.4): list pages after page N, languages search engines may not index, the whole site hidden.
        $after = SeoSettings::int('seo.noindex_after');
        if ($m['list'] && $after > 0 && $page > $after) { $m['noindex'] = true; }
        $indexed = AvdbLanguage::indexedCodes();
        if (!in_array($locale, $indexed, true)) { $m['noindex'] = true; $m['hreflang'] = false; }
        if (SeoSettings::bool('seo.hide_site')) { $m['noindex'] = true; $m['nofollow'] = true; }
        $m['canonical'] = self::pick($m['variants'], $locale);
        // The page's own URL for the language menu (before the hreflang filter drops noindexed languages).
        if ($m['self'] && !$preview && $m['base'] === null) { $m['base'] = self::relativeBase($m['variants']); }
        // hreflang lists offered and indexed languages only (D4); reciprocal because every page builds the same list.
        $m['variants'] = array_intersect_key($m['variants'], array_flip($indexed));
        return $m;
    }

    /**
     * Site-relative URL of a search page in the query form (/search/?wd=...): a path-style search URL is refused by
     * Apache for a word with "/" (%2F) and reads "+" literally, so the language links keep the query form.
     */
    private static function queryBase($action, array $query)
    {
        $parts = parse_url((string)mac_url($action));
        if (!is_array($parts)) { return ''; }
        $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        if (substr($path, 0, 1) !== '/' || substr($path, 0, 2) === '//' || preg_match('/[\x00-\x20\x7f\\\\]/', $path)) { return ''; }
        $own = [];
        if (isset($parts['query']) && is_string($parts['query'])) { parse_str($parts['query'], $own); }
        $query = array_merge($own, $query);
        unset($query['ui_lang'], $query['translang']);
        return $query ? $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $path;
    }

    /**
     * Site-relative path + query of a page from its variants (url => absolute, built by the framework URL builders),
     * without ui_lang / translang: the localized base of any non-default variant, else the default URL. '' when none.
     */
    private static function relativeBase(array $variants)
    {
        if (!$variants) { return ''; }
        $default = AvdbLanguage::defaultLocale();
        $url = '';
        foreach ($variants as $language => $row) {
            if ($language !== $default && isset($row['url']) && is_string($row['url']) && strpos($row['url'], 'ui_lang=') !== false) { $url = $row['url']; break; }
        }
        if ($url === '') { $url = isset($variants[$default]['url']) && is_string($variants[$default]['url']) ? $variants[$default]['url'] : ''; }
        if ($url === '') { $first = reset($variants); $url = is_array($first) && isset($first['url']) && is_string($first['url']) ? $first['url'] : ''; }
        $parts = $url === '' ? false : parse_url($url);
        if (!is_array($parts)) { return ''; }
        $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        if (substr($path, 0, 1) !== '/' || substr($path, 0, 2) === '//' || preg_match('/[\x00-\x20\x7f\\\\]/', $path)) { return ''; }
        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) { parse_str($parts['query'], $query); }
        unset($query['ui_lang'], $query['translang']);
        return $query ? $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $path;
    }

    // ------------------------------------------------------------------ output

    /** Uses framework URL builders, never a visitor-provided host or arbitrary request URI. */
    public static function head(array $site, array $obj = [], array $param = [])
    {
        $m = self::model($site, $obj, $param);
        // round 8 (flood-r8 R8-1): a verified crawler's landing that is noindex only because the request's database deadline cut
        // its facts short takes the time-limit fallback instead (the stale copy or the lighter page, which never tells a crawler
        // noindex: owner decision 2026-10-17, a noindex could drop the page); before round 8 the landing reads ran past the
        // deadline and the statements refused after them led to that fallback
        if (self::$landingCut && $m['noindex'] && self::landingCrawlerFallback($param)) {
            self::$landingCut = [];
            throw DbTimeout::spentException();
        }
        self::$languageBase = $m['base'];
        $locale = self::locale();
        $default = AvdbLanguage::defaultLocale();
        $title = $m['title'];
        $description = $m['description'];
        $canonical = $m['canonical'];
        $html = '<!--jv-seo-->' . "\n"
            . '<title>' . self::escape($title) . '</title>' . "\n"
            . '<meta name="description" content="' . self::escape($description) . '" />' . "\n"
            . '<meta name="robots" content="' . ($m['noindex'] ? 'noindex, ' : 'index, ') . ($m['nofollow'] ? 'nofollow' : 'follow') . '" />' . "\n";
        // Non-indexable pages and pages pointing to another canonical keep only their canonical (no alternates).
        $alternates = !$m['noindex'] && $m['hreflang'] && count($m['variants']) > 1 && isset($m['variants'][$default]);
        if ($canonical !== '') {
            $html .= $alternates ? self::links($m['variants'], $locale) : '<link rel="canonical" href="' . self::escape($canonical) . '" />' . "\n";
        }
        if ($canonical === '') { return $html . '<!--/jv-seo-->' . "\n"; }
        $image = $m['image'];
        $imageSize = [0, 0];
        if ($image === '' && ($share = self::shareImage()) !== null) { list($image, $imageSize[0], $imageSize[1]) = $share; }
        $html .= '<meta property="og:type" content="' . $m['og_type'] . '" />' . "\n"
            . '<meta property="og:title" content="' . self::escape($title) . '" />' . "\n"
            . '<meta property="og:description" content="' . self::escape($description) . '" />' . "\n"
            . '<meta property="og:url" content="' . self::escape($canonical) . '" />' . "\n"
            . '<meta property="og:site_name" content="' . self::escape(self::text($site['site_name'] ?? '', 160)) . '" />' . "\n"
            . '<meta property="og:locale" content="' . (self::OG_LOCALES[$locale] ?? 'en_US') . '" />' . "\n";
        if ($alternates) {
            foreach (array_keys($m['variants']) as $language) {
                if ($language !== $locale && isset(self::OG_LOCALES[$language])) {
                    $html .= '<meta property="og:locale:alternate" content="' . self::OG_LOCALES[$language] . '" />' . "\n";
                }
            }
        }
        if ($image !== '') {
            $html .= '<meta property="og:image" content="' . self::escape($image) . '" />' . "\n"
                . '<meta property="og:image:alt" content="' . self::escape($title) . '" />' . "\n";
            if ($imageSize[0] > 0 && $imageSize[1] > 0) {
                $html .= '<meta property="og:image:width" content="' . (int)$imageSize[0] . '" />' . "\n"
                    . '<meta property="og:image:height" content="' . (int)$imageSize[1] . '" />' . "\n";
            }
        }
        if ($m['og_type'] === 'video.movie') {
            if ($m['video_meta']['duration'] > 0) { $html .= '<meta property="video:duration" content="' . (int)$m['video_meta']['duration'] . '" />' . "\n"; }
            if ($m['video_meta']['release'] !== '') { $html .= '<meta property="video:release_date" content="' . self::escape($m['video_meta']['release']) . '" />' . "\n"; }
            foreach ($m['video_meta']['tags'] as $tag) { $html .= '<meta property="video:tag" content="' . self::escape($tag) . '" />' . "\n"; }
        }
        $html .= '<meta name="twitter:card" content="' . ($image !== '' ? 'summary_large_image' : 'summary') . '" />' . "\n";
        if (!$m['noindex']) {
            $graph = self::graph($m, $site, $title, $description, $canonical, $locale);
            if ($graph) {
                $html .= '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], self::JSON_FLAGS) . '</script>' . "\n";
            }
        }
        return $html . '<!--/jv-seo-->' . "\n";
    }

    /** JSON-LD nodes (C.6): one @graph per page, only on indexable pages. */
    private static function graph(array $m, array $site, $title, $description, $canonical, $locale)
    {
        $graph = [];
        $siteName = self::text($site['site_name'] ?? '', 160);
        if ($m['schema'] === 'home') {
            $website = ['@type' => 'WebSite', '@id' => $canonical . '#website', 'name' => $siteName, 'url' => $canonical, 'inLanguage' => $locale];
            if ($title !== '' && $title !== $siteName) { $website['alternateName'] = $title; }
            $website['description'] = $description;
            $website['isFamilyFriendly'] = false;
            $organization = ['@type' => 'Organization', '@id' => $canonical . '#organization', 'name' => $siteName, 'url' => $canonical];
            $logo = self::logoUrl($site);
            if ($logo !== '') { $organization['logo'] = $logo; }
            $social = (string)SeoSettings::get('site.site_social');
            if ($social !== '') { $organization['sameAs'] = array_values(array_filter(explode("\n", $social), 'strlen')); }
            $email = trim(html_entity_decode((string)($site['site_email'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) { $organization['contactPoint'] = ['@type' => 'ContactPoint', 'contactType' => 'customer support', 'email' => $email]; }
            $website['publisher'] = ['@id' => $organization['@id']];
            $graph[] = $website;
            $graph[] = $organization;
        } elseif ($m['schema'] === 'video' || $m['schema'] === 'WebPage' || $m['schema'] === 'CollectionPage') {
            $node = ['@type' => $m['schema'] === 'CollectionPage' ? 'CollectionPage' : 'WebPage', 'url' => $canonical, 'name' => $title,
                'description' => $description, 'inLanguage' => $locale];
            if ($m['family']) { $node['isFamilyFriendly'] = false; }
            if ($m['video']) { $node['mainEntity'] = $m['video'] + ['url' => $canonical, 'inLanguage' => $locale]; }
            $graph[] = $node;
        }
        if ($m['breadcrumb']) {
            $items = [];
            $last = count($m['breadcrumb']) - 1;
            foreach ($m['breadcrumb'] as $position => $crumb) {
                $item = ['@type' => 'ListItem', 'position' => $position + 1, 'name' => $crumb['name']];
                if ($position < $last && $crumb['url'] !== '') { $item['item'] = $crumb['url']; }
                $items[] = $item;
            }
            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
        }
        return $graph;
    }

    /**
     * Admin previews (A.5.1): the title and description the site prints for one sample, with optional unsaved
     * settings ['seo.title_sep' => ' | ', 'seo.tpl_video' => '...', ...] (decoded values). Read-only.
     * $type: video | category | tag | actor | list | home. $sample: a vod row (video), a type row (category),
     * ['tag' => name] / ['actor' => name] (+ optional 'count'), ['list' => 'popular'], or [] for a real sample.
     * Returns ['title' => string, 'description' => string] (plain text) .
     */
    public static function preview($type, array $sample = [], array $settings = [])
    {
        return SeoSettings::withOverrides($settings, function () use ($type, $sample) {
            $before = self::$forcedLocale;
            self::$forcedLocale = AvdbLanguage::defaultLocale();
            self::$previewDepth++;
            try {
                $config = config('maccms');
                $site = isset($GLOBALS['config']['site']) && is_array($GLOBALS['config']['site']) ? $GLOBALS['config']['site']
                    : (is_array($config) && isset($config['site']) ? $config['site'] : []);
                foreach (['site_name', 'site_description', 'site_home_title'] as $key) { if (isset($sample[$key]) && is_scalar($sample[$key])) { $site[$key] = $sample[$key]; } }
                $obj = [];
                $param = ['page' => max(1, (int)($sample['page'] ?? 1))];
                if ($type === 'video') {
                    $obj = isset($sample['vod_name']) ? $sample : self::sampleVideo();
                    if (!isset($obj['type']) && isset($obj['type_id'])) { $obj['type'] = self::sampleType((int)$obj['type_id']); }
                    $site['controller_action'] = 'vod/play';
                } elseif ($type === 'category') {
                    $obj = isset($sample['type_name']) ? $sample : self::sampleType(0);
                    $site['controller_action'] = 'vod/type';
                } elseif ($type === 'tag' || $type === 'actor') {
                    $value = isset($sample[$type]) && is_scalar($sample[$type]) ? (string)$sample[$type] : self::sampleName($type);
                    $param[$type] = $value;
                    $param['_count'] = isset($sample['count']) ? (int)$sample['count'] : self::landingInfo($type, $value)['count'];
                    $site['controller_action'] = 'vod/search';
                } elseif ($type === 'list') {
                    $site['controller_action'] = isset($sample['list']) && is_scalar($sample['list']) ? 'label/' . preg_replace('/[^a-z0-9_-]/', '', (string)$sample['list']) : 'label/hot';
                } else {
                    $site['controller_action'] = 'index/index';
                }
                $model = self::model($site, $obj, $param, true);
                return ['title' => $model['title'], 'description' => $model['description']];
            } finally {
                self::$previewDepth--;
                self::$forcedLocale = $before;
            }
        });
    }

    private static function sampleVideo()
    {
        try {
            $row = \think\Db::name('vod')->where('vod_status', 1)->order('vod_id desc')->find();
            return is_array($row) ? $row : [];
        } catch (\Throwable $error) {
            return [];
        }
    }

    private static function sampleType($id)
    {
        try {
            $query = \think\Db::name('type')->where('type_status', 1)->where('type_mid', 1);
            $row = $id > 0 ? $query->where('type_id', $id)->find() : $query->order('type_sort asc,type_id asc')->find();
            return is_array($row) ? $row : [];
        } catch (\Throwable $error) {
            return [];
        }
    }

    private static function sampleName($kind)
    {
        $field = $kind === 'actor' ? 'vod_actor' : 'vod_tag';
        try {
            $value = (string)\think\Db::name('vod')->where('vod_status', 1)->where($field, '<>', '')->order('vod_id desc')->value($field);
        } catch (\Throwable $error) {
            $value = '';
        }
        $first = trim(explode(',', AvdbLanguage::decodeEntities($value))[0]);
        return $first !== '' ? $first : ($kind === 'actor' ? 'Actress' : 'Tag');
    }

    /**
     * Video facts for the video sitemap (E.1), from the same rules as the watch page (C.2 description, C.6 VideoObject):
     * ['title' => plain name, 'description' => C.2 description, 'duration' => seconds (0 = unknown),
     *  'upload' => ISO 8601 release / added date ('' = unknown), 'tags' => up to $tagLimit names from tags + genres].
     * $obj is a vod row decorated by AvdbLanguage::decorate() for $locale (vod_display_* fields). Plain text, not escaped.
     */
    public static function videoText(array $obj, $locale, $tagLimit = 32)
    {
        $before = self::$forcedLocale;
        self::$forcedLocale = is_string($locale) && $locale !== '' ? $locale : AvdbLanguage::defaultLocale();
        try {
            // round-3 G9: the sitemap prints the title, the description, the duration, the upload date and the tags; the other
            // video tokens (actors, studio, category, code, year) are built only for a row whose description comes from them
            // (no blurb, no content): what videoDescription() builds from the same tokens
            $title = self::text($obj['vod_display_name'] ?? ($obj['vod_name'] ?? ''), 300, $titleFixed);
            if ($titleFixed) {
                self::$lastFixed = $title; // round-4 G9: the built description's title token is this string (assemble())
            }
            // round-4 G9: summary('') and summary(null) are '' (no call for a row without a blurb or content)
            $blurb = $obj['vod_display_blurb'] ?? ($obj['vod_blurb'] ?? '');
            $description = $blurb === '' || $blurb === null ? '' : self::summary($blurb);
            if ($description === '') {
                $content = $obj['vod_display_content'] ?? ($obj['vod_content'] ?? '');
                $description = $content === '' || $content === null ? '' : self::summary($content);
            }
            if ($description === '') { $description = self::videoDescriptionBuilt(self::videoTokens($obj, '', $title)); }
            $tags = [];
            $seen = null;
            foreach ([$obj['vod_display_tag'] ?? ($obj['vod_tag'] ?? ''), $obj['vod_display_class'] ?? ($obj['vod_class'] ?? '')] as $list) {
                if ($list === '' || $list === null) { continue; } // names('') = [] (round-3 G9: no call)
                $listNames = self::names($list, $tagLimit);
                if (!$tags && $tagLimit >= 1) {
                    // round-4 G9: the first list with names: names() gives at most $tagLimit of them; when they are unique
                    // (strict equality = distinct array keys; a cleaned or cut name can repeat another) the merge below
                    // would keep exactly these, in this order
                    $flip = array_flip($listNames);
                    if (count($flip) === count($listNames)) {
                        $tags = $listNames;
                        $seen = $flip;
                        continue;
                    }
                }
                if ($seen === null) { $seen = array_flip($tags); }
                foreach ($listNames as $name) {
                    // a key per name = strict string equality (in_array(..., true)) without a scan of the list so far
                    if (count($tags) < $tagLimit && !isset($seen[$name])) { $tags[] = $name; $seen[$name] = true; }
                }
            }
            return ['title' => $title, 'description' => $description !== '' ? $description : $title,
                'duration' => self::durationSeconds($obj['vod_duration'] ?? ''), 'upload' => self::uploadDate($obj), 'tags' => $tags];
        } finally {
            self::$forcedLocale = $before;
        }
    }
}
