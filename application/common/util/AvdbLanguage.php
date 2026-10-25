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

/** Request-only presentation adapter. It never changes canonical VOD metadata. */
class AvdbLanguage
{
    private static $available;
    private static $current;
    private static $tableReady = false;
    private static $rows = [];
    /** The text fields decorate() adds a display value for (DISPLAY_KEYS holds them with their keys, in this order). */
    private static $fields = ['vod_name','vod_en','vod_blurb','vod_content','vod_class','vod_tag','vod_area','vod_state','vod_actor','vod_director','vod_writer'];

    public static function enabled()
    {
        return defined('ENTRANCE') && ENTRANCE === 'index';
    }

    public static function languages()
    {
        return ['en'=>'English','vi'=>'Tiếng Việt','th'=>'ไทย','id'=>'Bahasa Indonesia','ko'=>'한국어','zh'=>'中文','ja'=>'日本語','fr'=>'Français','de'=>'Deutsch','es'=>'Español','pt'=>'Português','ru'=>'Русский','ar'=>'العربية','tr'=>'Türkçe','hi'=>'हिन्दी','ms'=>'Bahasa Melayu','tl'=>'Filipino'];
    }

    /** A section A setting (SeoSettings); loads the class when this file was included without the autoloader (tests). */
    private static function setting($path, $csv = false)
    {
        if (!class_exists(__NAMESPACE__ . '\\SeoSettings')) { require_once __DIR__ . '/SeoSettings.php'; }
        return $csv ? SeoSettings::csv($path) : SeoSettings::get($path);
    }

    /** Offered interface languages (site.lang_offered, '' = all 17), in menu order; always holds the default language. */
    public static function offeredCodes()
    {
        $all = array_keys(self::languages());
        $list = array_values(array_intersect($all, self::setting('site.lang_offered', true)));
        if (!$list) { return $all; }
        $default = self::setting('site.lang_default');
        if (is_string($default) && in_array($default, $all, true) && !in_array($default, $list, true)) {
            $list = array_values(array_intersect($all, array_merge($list, [$default])));
        }
        return $list;
    }

    /** Language of the plain addresses (D1): site.lang_default when valid and offered, else 'en' (or the first offered one). */
    public static function defaultLocale()
    {
        $offered = self::offeredCodes();
        $default = self::setting('site.lang_default');
        if (is_string($default) && in_array($default, $offered, true)) { return $default; }
        return in_array('en', $offered, true) ? 'en' : $offered[0];
    }

    /** Languages search engines may index (D4): site.lang_indexed ('' = all offered) within the offered ones, plus the default. */
    public static function indexedCodes()
    {
        $offered = self::offeredCodes();
        $stored = self::setting('site.lang_indexed', true);
        $list = $stored ? array_values(array_intersect($offered, $stored)) : $offered;
        $default = self::defaultLocale();
        if (!in_array($default, $list, true)) { $list = array_values(array_intersect($offered, array_merge($list, [$default]))); }
        return $list;
    }

    public static function resolve($parameter, $cookie, array $available)
    {
        $default = self::defaultLocale();
        foreach ([$parameter,$cookie,$default] as $locale) {
            if (is_string($locale) && isset(self::languages()[$locale]) && in_array($locale,$available,true)) { return $locale; }
        }
        return $default;
    }

    public static function available()
    {
        if (!self::enabled()) { return ['en'=>'English']; }
        if (self::$available === null) {
            // UI languages do not depend on imported movie translations; the owner may offer a subset (D3, site.lang_offered).
            self::$available = array_intersect_key(self::languages(), array_flip(self::offeredCodes()));
            try {
                \think\Db::name('avdb_translation')->limit(1)->column('locale');
                self::$tableReady = true;
            } catch (\Throwable $e) { /* Optional schema has not been installed. */ }
        }
        return self::$available;
    }

    public static function current()
    {
        if (!self::enabled()) { return 'en'; }
        if (self::$current === null) {
            self::$current = self::resolve($_GET['ui_lang'] ?? null,$_COOKIE['avdb_ui_lang'] ?? null,array_keys(self::available()));
            // HO-H-1 (xvid scale build): only the language switcher (ui_lang_set=1) stores the choice; a plain ?ui_lang= link
            // (hreflang alternate, crawler, shared link) shows that language once without changing the visitor's cookie
            if (isset($_GET['ui_lang'], $_GET['ui_lang_set']) && is_string($_GET['ui_lang']) && $_GET['ui_lang_set'] === '1' && $_GET['ui_lang'] === self::$current && !headers_sent()) {
                setcookie('avdb_ui_lang',self::$current,['expires'=>time()+31536000,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
            }
        }
        return self::$current;
    }

    public static function cacheSuffix($locale = null)
    {
        $locale = $locale === null ? self::current() : $locale;
        $available = array_keys(self::available());
        sort($available,SORT_STRING);
        $protocol = json_encode([$GLOBALS['config']['rewrite']['canonical_scheme'] ?? 'auto', $GLOBALS['http_type'] ?? 'http://']);
        return '_avdb_ui_modern_v2_'.substr(sha1($protocol),0,8).'_'.(isset(self::languages()[$locale]) ? $locale : 'en').'_'.substr(sha1(implode(',',$available)),0,12);
    }

    public static function escape($text)
    {
        return htmlspecialchars((string)$text,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');
    }

    /** Hangul, CJK punctuation, kana and ideographs: text meant for zh/ja/ko visitors only. */
    const CJK_TEXT = '/[\x{1100}-\x{11FF}\x{3000}-\x{30FF}\x{3130}-\x{318F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}]/u';

    /**
     * Imported text is sometimes stored escaped two or three times (&amp;#039;); decode until stable, at most 3 passes.
     * A value without "&" is stable (html_entity_decode() returns it as is): no further pass (verify site-r4 N20-G9).
     */
    public static function decodeEntities($value)
    {
        $value = (string)$value;
        for ($pass = 0; $pass < 3; $pass++) {
            if (strpos($value, '&') === false) {
                break;
            }
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }
        return $value;
    }

    /**
     * Adds the display values of a video row: vod_display_<field> for the 11 text fields (the translation of the page
     * language, else the English payload, else the stored value; entities decoded, tags stripped, trimmed; placeholder
     * items dropped from the list fields; area names translated), vod_display_code and vod_text_lang. Sitemap video files
     * (5,000 / 10,000 rows per file), every list and every detail page run it once per row.
     * verify site-r4 N20-G9 (CONTRACT HO-H-R3-3; T's HO-H-9 concern kept: every value as before): the same values through
     * cheaper steps, proven identical with the round-3 code (tests/display_decorate.php: a reference copy of the old
     * function over fuzzed rows; fix/T/r4: every row x 17 languages of a box, 51 sitemap files byte for byte): a row
     * without a payload for the page language and English skips the translation loop; an empty value stays empty; a value
     * without "&", "<" or NUL is only trimmed (html_entity_decode() and strip_tags() return such a value unchanged); the
     * CJK test of a translation runs its /u pattern only when a byte that can start one of its code points is there;
     * repeated short values (genre / tag / performer / studio lists, area names) take the decoded text, the placeholder
     * filter and the area translation from a per-process memo (DECO_MEMO_MAX entries each, then it starts again); the
     * language candidates and the display keys are computed once.
     */
    public static function decorate(array $vod, array $translations, $locale)
    {
        // Some imported rows of non-CJK locales still hold the untranslated CJK original (e.g. the vi/ar/de title of JD062).
        // That is not a translation: the record's own (English) value is shown instead whenever it has no CJK text.
        $cjkLocale = in_array($locale, ['zh','ja','ko'], true);
        // the payload arrays of the candidates array_unique([$locale, 'en']) gave (in its order; 'en' once for 'en') that are
        // set: none = every field shows its stored value (the loop finds nothing)
        $payloads = [];
        foreach ((string)$locale === 'en' ? [$locale] : [$locale, 'en'] as $candidate) {
            if (isset($translations[$candidate])) { $payloads[] = [$candidate, $translations[$candidate]]; }
        }
        if (!$payloads) {
            return self::decorateStored($vod, $locale);
        }
        $areaLocale = $locale !== 'en';
        $nameFrom = '';
        // the last translated value examined and its analysis (vod_en repeats the vod_name text in nearly every payload)
        $seen = null;
        $seenTrim = null;
        $seenBlank = false;
        $seenCjk = false;
        $seenPlain = false;
        // one array payload (the page language; the importers store no English rows): a field it does not hold has no
        // translation (`$payload[$field] ?? null` is null), so that field skips the candidate loop
        $single = count($payloads) === 1 && is_array($payloads[0][1]) ? $payloads[0][1] : null;
        foreach (self::DISPLAY_KEYS as $field => $displayKey) {
            $value = isset($vod[$field]) && is_scalar($vod[$field]) ? (string)$vod[$field] : '';
            $from = '';
            $display = null;
            $baseClean = null;
            foreach ($single === null || isset($single[$field]) ? $payloads : [] as $pair) {
                $translated = $pair[1][$field] ?? null;
                if (!is_string($translated)) { continue; }
                if ($translated !== $seen) {
                    $seen = $translated;
                    if (preg_match('/[&<\x00\xE3-\xED\xEF]|\xE1[\x84-\x87]/', $translated) !== 1) {
                        // no "&", "<", NUL and no byte that can start a CJK_TEXT code point: strip_tags() and the entity
                        // decoding return it as it is, the /u pattern cannot match it
                        $seenTrim = trim($translated);
                        $seenBlank = $seenTrim === '';
                        $seenCjk = false;
                        $seenPlain = true;
                    } else {
                        // trim(strip_tags($translated)) === '' (strip_tags() keeps a value without "<" and NUL as it is)
                        $seenTrim = strpos($translated, '<') === false && strpos($translated, "\0") === false ? trim($translated) : null;
                        $seenBlank = ($seenTrim !== null ? $seenTrim : trim(strip_tags($translated))) === '';
                        // preg_match(self::CJK_TEXT, $translated) === 1: each of its code points is a UTF-8 sequence led by a
                        // byte E3-ED / EF or by E1 84-87; without one the /u pattern cannot match (valid or invalid UTF-8),
                        // so the byte scan (no /u) decides first. Only asked on pages in a non-CJK language.
                        $seenCjk = !$seenBlank && !$cjkLocale && preg_match('/[\xE3-\xED\xEF]|\xE1[\x84-\x87]/', $translated) === 1
                            && preg_match(self::CJK_TEXT, $translated) === 1;
                        $seenPlain = $seenTrim !== null && strpos($translated, '&') === false;
                    }
                }
                if ($seenBlank) { continue; }
                if ($seenCjk) {
                    if ($baseClean === null) { $baseClean = trim(strip_tags($value)) !== '' && preg_match(self::CJK_TEXT, $value) === 0; }
                    if ($baseClean) { continue; }
                }
                $value = $translated;
                $from = $pair[0];
                // a translation without "&", "<" and NUL shows trimmed (areas of the English payload are translated below)
                if ($seenPlain && $field !== 'vod_area') { $display = $seenTrim; }
                break;
            }
            if ($display === null) {
                if ($value === '') {
                    $display = ''; // '' decoded, stripped, trimmed and area-translated is ''
                } else {
                    if ($areaLocale && $field === 'vod_area' && $from !== $locale) { $value = self::areaNameMemo($value, $locale); }
                    $display = strpos($value, '&') === false && strpos($value, '<') === false && strpos($value, "\0") === false
                        ? trim($value) : self::displayText($value);
                }
            }
            if ($display !== '' && isset(self::$listFields[$field])) {
                $display = self::$placeholderMemo[$display] ?? self::dropPlaceholdersMemo($display);
            }
            $vod[$displayKey] = $display;
            if ($field === 'vod_name') { $nameFrom = $from; }
        }
        // xvid scale build (2.11 rule 1, THM 5.1): code chip only from a release-code-shaped vod_remarks ('' for numeric
        // provider ids such as 73341265 and for quality strings such as 4K)
        $vod['vod_display_code'] = self::codeOf($vod['vod_remarks'] ?? '');
        // 2.7 [pr-R8.1]: language of the shown title when it is the untranslated original in another language than the page
        $vod['vod_text_lang'] = $nameFrom === $locale ? '' : self::textLang($vod['vod_lang'] ?? '', $locale);
        return $vod;
    }

    /**
     * decorate() of a row without a payload for the page language and English (verify site-r4 N20-G9: the cheap path; every
     * default-language sitemap file, most xvid rows): each field shows its stored value, the same keys in the same order
     * and the same values as the loop of decorate() gives such a row (area names translated on pages in another language
     * than English, placeholder items dropped from the list fields).
     */
    private static function decorateStored(array $vod, $locale)
    {
        $vod['vod_display_name'] = self::storedText($vod['vod_name'] ?? null);
        $vod['vod_display_en'] = self::storedText($vod['vod_en'] ?? null);
        $vod['vod_display_blurb'] = self::storedText($vod['vod_blurb'] ?? null);
        $vod['vod_display_content'] = self::storedText($vod['vod_content'] ?? null);
        $vod['vod_display_class'] = self::storedText($vod['vod_class'] ?? null);
        $vod['vod_display_tag'] = self::storedText($vod['vod_tag'] ?? null);
        $area = $vod['vod_area'] ?? null;
        if ($locale !== 'en' && $locale !== '' && is_scalar($area) && (string)$area !== '') {
            $area = self::areaNameMemo((string)$area, $locale); // the loop's rule: $from ('') !== $locale && $locale !== 'en'
        }
        $vod['vod_display_area'] = self::storedList($area);
        $vod['vod_display_state'] = self::storedText($vod['vod_state'] ?? null);
        $vod['vod_display_actor'] = self::storedList($vod['vod_actor'] ?? null);
        $vod['vod_display_director'] = self::storedList($vod['vod_director'] ?? null);
        $vod['vod_display_writer'] = self::storedList($vod['vod_writer'] ?? null);
        $vod['vod_display_code'] = self::codeOf($vod['vod_remarks'] ?? '');
        // no translated title: the language of the stored one ($nameFrom '' === $locale only for an empty $locale)
        $vod['vod_text_lang'] = $locale === '' ? '' : self::textLang($vod['vod_lang'] ?? '', $locale);
        return $vod;
    }

    /** The display text of a stored value (isset + is_scalar, then decoded, stripped and trimmed as decorate() does). */
    private static function storedText($value)
    {
        if (!is_scalar($value) || ($value = (string)$value) === '') { return ''; }
        return strpos($value, '&') === false && strpos($value, '<') === false && strpos($value, "\0") === false ? trim($value) : self::displayText($value);
    }

    /** storedText() of a list field without its placeholder items. */
    private static function storedList($value)
    {
        $display = self::storedText($value);
        return $display === '' ? '' : (self::$placeholderMemo[$display] ?? self::dropPlaceholdersMemo($display));
    }

    /** decorate(): field => display key (str_replace('vod_', 'vod_display_', field)), in self::$fields order. */
    const DISPLAY_KEYS = ['vod_name' => 'vod_display_name', 'vod_en' => 'vod_display_en', 'vod_blurb' => 'vod_display_blurb',
        'vod_content' => 'vod_display_content', 'vod_class' => 'vod_display_class', 'vod_tag' => 'vod_display_tag',
        'vod_area' => 'vod_display_area', 'vod_state' => 'vod_display_state', 'vod_actor' => 'vod_display_actor',
        'vod_director' => 'vod_display_director', 'vod_writer' => 'vod_display_writer'];

    /** decorate() memos: values up to DECO_MEMO_LEN bytes, at most DECO_MEMO_MAX entries per memo (a full memo starts again). */
    const DECO_MEMO_LEN = 200;
    const DECO_MEMO_MAX = 4096;
    private static $displayMemo = [];
    private static $placeholderMemo = [];
    private static $areaMemo = [];

    /** trim(strip_tags(decodeEntities($value))) of a value holding "&", "<" or NUL; memoised for repeated short values. */
    private static function displayText($value)
    {
        if (strlen($value) > self::DECO_MEMO_LEN) { return self::cleanText($value); }
        if (isset(self::$displayMemo[$value])) { return self::$displayMemo[$value]; }
        if (count(self::$displayMemo) >= self::DECO_MEMO_MAX) { self::$displayMemo = []; }
        return self::$displayMemo[$value] = self::cleanText($value);
    }

    /** trim(strip_tags(decodeEntities($value))), strip_tags() only when the decoded text holds "<" or NUL. */
    private static function cleanText($value)
    {
        $value = self::decodeEntities($value);
        return trim(strpos($value, '<') === false && strpos($value, "\0") === false ? $value : strip_tags($value));
    }

    /** dropPlaceholders() of a display list, memoised for repeated short lists. */
    private static function dropPlaceholdersMemo($value)
    {
        if (strlen($value) > self::DECO_MEMO_LEN) { return self::dropPlaceholders($value); }
        if (isset(self::$placeholderMemo[$value])) { return self::$placeholderMemo[$value]; }
        if (count(self::$placeholderMemo) >= self::DECO_MEMO_MAX) { self::$placeholderMemo = []; }
        return self::$placeholderMemo[$value] = self::dropPlaceholders($value);
    }

    /** areaName() memoised per language and value (area lists repeat on every row). */
    private static function areaNameMemo($value, $locale)
    {
        if (strlen($value) > self::DECO_MEMO_LEN || !is_string($locale)) { return self::areaName($value, $locale); }
        $key = $locale . "\x1F" . $value;
        if (isset(self::$areaMemo[$key])) { return self::$areaMemo[$key]; }
        if (count(self::$areaMemo) >= self::DECO_MEMO_MAX) { self::$areaMemo = []; }
        return self::$areaMemo[$key] = self::areaName($value, $locale);
    }

    /** List fields whose placeholder items ("Updating", "Unknown", ...) are dropped from the vod_display_* value. */
    private static $listFields = ['vod_actor'=>1,'vod_director'=>1,'vod_writer'=>1,'vod_area'=>1];

    /** Placeholder values providers send for "no value" (compared lower case, trimmed). */
    const PLACEHOLDERS = ['updating','unknown','n/a','na','-','--','đang cập nhật','none','null'];

    /**
     * A comma / slash / pipe separated display list without placeholder items (2.11 rule 3, THM 5.1 Fix B). The value
     * comes back unchanged when it holds no placeholder, so AVDB lists keep their own separators.
     */
    public static function dropPlaceholders($value)
    {
        $value = (string)$value;
        if ($value === '') { return ''; }
        // "/" separates only with spaces around it ("Taro / Jiro"), so "N/A" stays one item
        $parts = preg_split('/\s*[,，、|]\s*|\s+\/\s+/u', $value);
        if (!is_array($parts)) { return $value; }
        $keep = [];
        $dropped = false;
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            if (in_array(function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part), self::PLACEHOLDERS, true)) { $dropped = true; continue; }
            $keep[] = $part;
        }
        return $dropped ? implode(', ', $keep) : $value;
    }

    /** English language names (vod_lang as the importers store it) -> ISO 639-1 code. */
    const LANG_CODES = ['english'=>'en','vietnamese'=>'vi','thai'=>'th','indonesian'=>'id','korean'=>'ko','chinese'=>'zh','mandarin'=>'zh',
        'cantonese'=>'zh','japanese'=>'ja','french'=>'fr','german'=>'de','spanish'=>'es','portuguese'=>'pt','russian'=>'ru','arabic'=>'ar',
        'turkish'=>'tr','hindi'=>'hi','malay'=>'ms','filipino'=>'tl','tagalog'=>'tl','italian'=>'it','dutch'=>'nl','polish'=>'pl',
        'czech'=>'cs','hungarian'=>'hu','romanian'=>'ro','swedish'=>'sv','danish'=>'da','norwegian'=>'no','finnish'=>'fi','greek'=>'el',
        'ukrainian'=>'uk','hebrew'=>'he','persian'=>'fa','bengali'=>'bn','urdu'=>'ur','tamil'=>'ta','bulgarian'=>'bg','serbian'=>'sr',
        'croatian'=>'hr','slovak'=>'sk','slovenian'=>'sl','lithuanian'=>'lt','latvian'=>'lv','estonian'=>'et','catalan'=>'ca'];

    /**
     * vod_text_lang (2.7): the ISO code of vod_lang (a language name such as "Portuguese", or already a code such as
     * "pt" / "pt-BR") when it is set and differs from the page language; '' for empty, und, mul and unknown names.
     * Themes print lang="<code>" only when this is not ''. Only [a-z]{2,3}(-[A-Za-z0-9]{2,8})? can come out.
     */
    public static function textLang($vodLang, $locale)
    {
        $raw = is_scalar($vodLang) ? trim((string)$vodLang) : '';
        if ($raw === '' || strlen($raw) > 40) { return ''; }
        $lower = strtolower($raw);
        if (isset(self::LANG_CODES[$lower])) {
            $code = self::LANG_CODES[$lower];
        } elseif (preg_match('/^([a-z]{2,3})(?:[-_]([a-z0-9]{2,8}))?$/D', $lower, $m)) {
            if (in_array($m[1], ['und','mul','zxx','mis'], true)) { return ''; }
            $code = $m[1] . (isset($m[2]) ? '-' . (strlen($m[2]) === 2 ? strtoupper($m[2]) : $m[2]) : '');
        } else {
            return '';
        }
        $page = strtolower(explode('-', (string)$locale)[0]);
        return explode('-', $code)[0] === $page ? '' : $code;
    }

    /**
     * Region names ("Japan", "China, Korea") the AVDB translations do not cover: the interface catalog's translation of
     * each comma-separated part (template_lang rows "Japan" -> "日本", keys area.*); unknown parts stay as they are.
     */
    private static function areaName($value, $locale)
    {
        $value = (string)$value;
        if ($value === '' || strlen($value) > 200 || preg_match('/[^\x20-\x7E]/', $value) || !class_exists(__NAMESPACE__ . '\\TemplateText')) { return $value; }
        $parts = [];
        foreach (preg_split('/\s*,\s*/', trim($value)) as $part) {
            $text = $part === '' ? '' : TemplateText::raw($part, [], $locale);
            $parts[] = is_string($text) && $text !== '' ? $text : $part;
        }
        return implode(', ', $parts);
    }

    // ------------------------------------------------------------------ shared display helpers (xvid scale build, lane T)

    /**
     * vod_display_code (2.11 rule 1): a code TemplateSeo::releaseCode() accepts ("ABC-123" shapes), shown as stored
     * (upper case, variant suffix such as -SUB kept); when that finds nothing, an AVDB catalog code it does not cover (FC2-PPV-4990004, JDSY093, H_1724P009G00008,
     * PACOPACOMAMA-100326-100) is kept as before. Always '' for numeric provider ids (73341265), quality strings (4K,
     * 1080P), the internal avdb-* ids and anything with spaces or longer than 24 characters. Plain text: escape it.
     */
    /** codeOf(): TemplateSeo checked (autoloaded or required) once per process. */
    private static $seoLoaded = false;

    public static function codeOf($remarks)
    {
        if (!self::$seoLoaded) {
            if (!class_exists(__NAMESPACE__ . '\\TemplateSeo')) { require_once __DIR__ . '/TemplateSeo.php'; }
            self::$seoLoaded = true;
        }
        $code = TemplateSeo::releaseCode($remarks);
        $raw = is_scalar($remarks) ? (string)$remarks : '';
        $raw = trim(strpos($raw, '&') === false ? $raw : self::decodeEntities($raw)); // decodeEntities() returns a value without "&" as is
        if ($code !== '') {
            // the stored code with its variant suffix (MIKR-014-SUB, HUNTB-545-UNCENSORED-LEAK) as the themes showed it
            return (strlen($raw) <= 40 && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $raw)) ? strtoupper($raw) : $code;
        }
        if ($raw === '' || strlen($raw) > 24 || stripos($raw, 'avdb-') === 0) { return ''; }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $raw) || !preg_match('/[A-Za-z]/', $raw) || !preg_match('/[0-9]/', $raw)) { return ''; }
        if (preg_match('/^(?:[0-9]{3,4}[pi]|[0-9]k|[0-9]{3,4}x[0-9]{3,4}|[0-9]d)$/iD', $raw)) { return ''; }
        return strtoupper($raw);
    }

    /**
     * Hero / slider rows (2.11 per-theme row "hero query prefers rows with vod_pic_slide != ''", pr-R14.3): rows with a
     * slide picture first, order kept inside both groups; rows without one stay (they get the lowres treatment).
     */
    public static function heroOrder($rows)
    {
        if (!is_array($rows)) { return []; }
        $with = [];
        $without = [];
        foreach ($rows as $row) {
            if (is_array($row) && trim((string)($row['vod_pic_slide'] ?? '')) !== '') { $with[] = $row; } else { $without[] = $row; }
        }
        return array_merge($with, $without);
    }

    /**
     * Category name as the themes print it (TemplateText::get(type_name)): the catalog translation of the category's own
     * English name when the catalog has one (so an owner's D20 choice "International" or "Western" shows everywhere),
     * else TemplateText::category() (cat.<slug>, then the stored name). Plain text: escape it.
     */
    public static function categoryName(array $type)
    {
        $name = isset($type['type_name']) && is_scalar($type['type_name']) ? trim(self::decodeEntities((string)$type['type_name'])) : '';
        if (!class_exists(__NAMESPACE__ . '\\TemplateText')) { return $name; }
        if ($name !== '') {
            if (TemplateText::locale() === 'en') { return $name; }
            $text = TemplateText::raw($name);
            if ($text !== '' && $text !== $name) { return $text; }
        }
        return TemplateText::category($type);
    }

    /**
     * ' lang="<code>"' for an element that prints the row's title when vod_text_lang is set (2.7, 2.11 rule 1), else ''.
     * The code is re-validated here, so only [a-z]{2,3}(-[A-Za-z0-9]{2,8})? can reach the page.
     */
    public static function langAttr($vod)
    {
        $code = is_array($vod) && isset($vod['vod_text_lang']) && is_string($vod['vod_text_lang']) ? $vod['vod_text_lang'] : '';
        if ($code === '' || !preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})?$/D', $code)) { return ''; }
        return ' lang="' . self::escape($code) . '"';
    }

    /** The row's vod_display_code (set by decorate()); rows that did not pass decorate() get the same value computed. */
    public static function displayCode($vod)
    {
        if (!is_array($vod)) { return ''; }
        if (isset($vod['vod_display_code']) && is_string($vod['vod_display_code'])) { return $vod['vod_display_code']; }
        return self::codeOf($vod['vod_remarks'] ?? '');
    }

    /**
     * A display list field (actor, director, writer, area) without placeholders: the vod_display_* value when the row
     * passed decorate() (also when that value is '' because only placeholders were stored), else the raw field cleaned.
     * Plain text: escape it.
     */
    public static function displayList($vod, $field)
    {
        if (!is_array($vod) || !isset(self::$listFields['vod_' . $field])) { return ''; }
        $key = 'vod_display_' . $field;
        if (isset($vod[$key]) && is_string($vod[$key])) { return $vod[$key]; }
        $raw = isset($vod['vod_' . $field]) && is_scalar($vod['vod_' . $field]) ? (string)$vod['vod_' . $field] : '';
        return self::dropPlaceholders(trim(strip_tags(self::decodeEntities($raw))));
    }

    /** True when a tag may become a chip (2.11 rule 4): >= 3 letters/digits, or an upper-case short tag such as 4K, VR, 3D. */
    public static function tagKeep($tag)
    {
        $tag = is_scalar($tag) ? trim(self::decodeEntities((string)$tag)) : '';
        if ($tag === '') { return false; }
        // the fragment rule is about cut Latin slugs ("am", "pussy-e"); CJK, Thai, Arabic ... tags of 1-2 characters stay
        if (preg_match('/[^\p{Latin}\p{N}\p{P}\p{Zs}\p{S}]/u', $tag)) { return true; }
        $chars = preg_match_all('/[\p{L}\p{N}]/u', $tag);
        if ($chars >= 3) { return true; }
        return $chars >= 1 && preg_match('/^[\p{Lu}\p{N}]+$/u', $tag) === 1 && preg_match('/\p{Lu}/u', $tag) === 1;
    }

    /**
     * Visitor label of a stored tag (2.11 rules 4 and 6): catalog key tag.<slug> (translated through the catalog) when
     * it exists, else the humanized tag ("big-ass" -> "Big Ass"; other spellings unchanged). The link keeps the stored
     * value. Plain text: escape it.
     */
    public static function tagLabel($tag)
    {
        $tag = is_scalar($tag) ? trim(self::decodeEntities((string)$tag)) : '';
        if ($tag === '') { return ''; }
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($tag)), '-');
        if ($slug !== '' && strlen($slug) <= 60 && class_exists(__NAMESPACE__ . '\\TemplateText')) {
            $key = 'tag.' . $slug;
            $text = TemplateText::raw($key);
            if ($text !== '' && $text !== $key) { return $text; }
        }
        if (preg_match('/^[a-z0-9]+(?:[-_ ][a-z0-9]+)*$/D', $tag) && preg_match('/[a-z]/', $tag)) {
            return ucwords(str_replace(['-', '_'], ' ', $tag));
        }
        return $tag;
    }

    /**
     * A theme's own tag list (strings, or rows whose stored value sits under $key) without fragments (tagKeep()), cut at
     * $limit (12 on detail pages, 2.11 rule 4). Order, keys of the rows and the stored values are kept.
     */
    public static function tagFilter($tags, $limit = 12, $key = null)
    {
        if (!is_array($tags)) { return []; }
        $out = [];
        foreach ($tags as $tag) {
            $value = ($key !== null && is_array($tag)) ? ($tag[$key] ?? '') : $tag;
            if (!is_scalar($value)) { continue; }
            $plain = trim((string)$value);
            if (in_array(strtolower($plain), ['hd','fhd','uhd','sd','4k','8k','vr','3d'], true)) { $plain = strtoupper($plain); }
            if (!self::tagKeep($plain)) { continue; }
            $out[] = $tag;
            if (count($out) >= max(1, (int)$limit)) { break; }
        }
        return $out;
    }

    /**
     * Tag chips of a detail page (2.11 rule 4): the theme's own list filtered by tagKeep(), de-duplicated by label and
     * cut at $limit (12 on detail pages). Each item: v (stored value, for the link) and l (label, plain text).
     */
    public static function tagChips(array $tags, $limit = 12)
    {
        $out = [];
        $seen = [];
        foreach ($tags as $tag) {
            $value = is_array($tag) ? ($tag['v'] ?? '') : $tag;
            if (is_scalar($value) && in_array(strtolower(trim((string)$value)), ['hd','fhd','uhd','sd','4k','8k','vr','3d'], true)) { $value = strtoupper(trim((string)$value)); }
            if (!is_scalar($value) || !self::tagKeep((string)$value)) { continue; }
            $label = self::tagLabel((string)$value);
            $k = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
            if ($label === '' || isset($seen[$k])) { continue; }
            $seen[$k] = 1;
            $out[] = ['v' => (string)$value, 'l' => $label];
            if (count($out) >= max(1, (int)$limit)) { break; }
        }
        return $out;
    }

    /**
     * Quality badge text from vod_version (2.11 rule 7): 8K, 4K, 1440P, 1080P, 720P, 480P, 360P, HD, SD or VR; '' for an
     * empty or unknown value, so a theme never prints a placeholder. Plain text: escape when printing.
     */
    public static function quality($vod)
    {
        $raw = is_array($vod) ? ($vod['vod_version'] ?? '') : $vod;
        $raw = is_scalar($raw) ? strtolower(trim(self::decodeEntities((string)$raw))) : '';
        if ($raw === '' || strlen($raw) > 40) { return ''; }
        if (preg_match('/\b(?:8k|4320p?)\b/', $raw)) { return '8K'; }
        if (preg_match('/\b(?:4k|uhd|2160p?)\b/', $raw)) { return '4K'; }
        if (preg_match('/\b(?:2k|1440p?|qhd)\b/', $raw)) { return '1440P'; }
        if (preg_match('/\b(?:1080[pi]?|fhd|full\s*hd)\b/', $raw)) { return '1080P'; }
        if (preg_match('/\b720p?\b/', $raw)) { return '720P'; }
        if (preg_match('/\b480p?\b/', $raw)) { return '480P'; }
        if (preg_match('/\b360p?\b/', $raw)) { return '360P'; }
        if (preg_match('/\bvr\b/', $raw)) { return 'VR'; }
        if (preg_match('/\bhd\b/', $raw)) { return 'HD'; }
        if (preg_match('/\bsd\b/', $raw)) { return 'SD'; }
        return '';
    }

    /**
     * Quality badge HTML (escaped): <span class="jv-q $class" data-q="4K">4K</span>, '' when there is no quality.
     * $class: the theme's own badge class(es), [A-Za-z0-9 _-] only.
     */
    public static function qualityBadge($vod, $class = '')
    {
        $q = self::quality($vod);
        if ($q === '') { return ''; }
        $class = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', (string)$class));
        return '<span class="jv-q' . ($class !== '' ? ' ' . self::escape($class) : '') . '" data-q="' . self::escape($q) . '">' . self::escape($q) . '</span>';
    }

    /**
     * Rows of the shared "subcats" chip row (2.11 rule 5): the visible children of the current category, or of its parent
     * on a child page (current one marked active). hide_empty applies (unknown counts hide nothing). [] when none.
     * Each row: type_id, name (cat.<slug> translation, plain text), url (unescaped), active, type (the category row).
     */
    public static function subcats($type, $limit = 60)
    {
        if (!is_array($type)) { return []; }
        $id = (int)($type['type_id'] ?? 0);
        if ($id < 1) { return []; }
        $pid = (int)($type['type_pid'] ?? 0);
        $parent = $pid > 0 ? $pid : $id;
        $lp = ['order'=>'asc','by'=>'sort','mid'=>'','ids'=>'','parent'=>(string)$parent,'format'=>'def','flag'=>'vod',
            'start'=>0,'num'=>max(1, min(200, (int)$limit)),'cachetime'=>'','not'=>'','hide_empty'=>'1'];
        $res = model('Type')->listCacheData($lp);
        $rows = [];
        foreach ((isset($res['list']) && is_array($res['list']) ? $res['list'] : []) as $row) {
            if (!is_array($row) || (int)($row['type_id'] ?? 0) < 1) { continue; }
            $rows[] = [
                'type_id' => (int)$row['type_id'],
                'name' => self::categoryName($row),
                'url' => (string)mac_url_type($row, [], 'type'),
                'active' => (int)$row['type_id'] === $id,
                'type' => $row,
            ];
        }
        return $rows;
    }

    /**
     * The shared "subcats" partial as escaped HTML: a horizontally scrolling chip row, '' when there are no visible
     * children. $opt: nav (extra nav classes), chip (extra chip classes), label (aria-label text; default ui.categories).
     */
    public static function subcatsHtml($type, array $opt = [])
    {
        $rows = self::subcats($type, isset($opt['limit']) ? (int)$opt['limit'] : 60);
        if (!$rows) { return ''; }
        $cls = function ($v) { return trim(preg_replace('/[^A-Za-z0-9 _-]/', '', (string)$v)); };
        $nav = $cls($opt['nav'] ?? '');
        $chip = $cls($opt['chip'] ?? '');
        $label = isset($opt['label']) && is_string($opt['label']) ? $opt['label'] : (class_exists(__NAMESPACE__ . '\\TemplateText') ? TemplateText::raw('ui.categories') : 'Categories');
        $html = '<nav class="jv-subcats' . ($nav !== '' ? ' ' . self::escape($nav) : '') . '" aria-label="' . self::escape($label) . '"><div class="jv-subcats-row">';
        foreach ($rows as $row) {
            $html .= '<a class="jv-subcat' . ($chip !== '' ? ' ' . self::escape($chip) : '') . ($row['active'] ? ' is-active' : '') . '" href="' . self::escape($row['url']) . '"'
                . ($row['active'] ? ' aria-current="page"' : '') . ' dir="auto">' . self::escape($row['name']) . '</a>';
        }
        return $html . '</div></nav>';
    }

    /** The list-result keys the visitor notes read (listNote(), resultCount()). */
    const NOTE_KEYS = ['search_msg', 'total_exact', 'total_about', 'total_more', 'total_all'];

    /**
     * The note keys of a list: $list's own (the list result), else those $__PAGING__ carries. In a theme the list's
     * volist leaves $__LIST__ as the bare row array, so after the list only $__PAGING__ can hold them (mac_page_param()
     * merges what Vod::listCacheData() hands over in $GLOBALS['mac_list_cursor'], CONTRACT HO-S-NL1 / HO-T-R4-1).
     */
    private static function noteKeys($list, $paging)
    {
        $out = [];
        foreach (self::NOTE_KEYS as $k) {
            if (is_array($list) && array_key_exists($k, $list)) { $out[$k] = $list[$k]; }
            elseif (is_array($paging) && array_key_exists($k, $paging)) { $out[$k] = $paging[$k]; }
        }
        return $out;
    }

    /**
     * Visitor notes of a list result (HO-S-2, HO-S-5, NOLIMIT T2): the search planner's message (`search_msg`
     * 'type_more'; 'partial': the recall ran out of time on a deeper page, HO-S-R2-2), "About {n} results" when the total
     * is not exact (`total_exact` false + `total_about`), "{n}+ videos" when a filtered list is bounded (`total_more` +
     * `total_all`). The old 'newest:<N>' dead end is gone (NOLIMIT C3: the pager offers "Older results" instead), so that
     * message prints nothing. An empty page that continues with older results (a capped walk that found nothing in its
     * budget, HO-H-R3-2: $__PAGING__ record_total 0 + page_cursor_next) gets the 'partial' line too: its Older / Oldest
     * links follow in the pager. $paging = $__PAGING__ (the keys after the list's volist). Lists without these keys
     * (1.1.0 list path, type lists) give []. Numbers in the interface language's format. Plain text lines: escape them
     * (listNoteHtml does).
     */
    public static function listNote($list, $locale = null, $paging = null)
    {
        if ((!is_array($list) && !is_array($paging)) || !class_exists(__NAMESPACE__ . '\\TemplateText')) { return []; }
        $keys = self::noteKeys($list, $paging);
        $locale = self::pagerLocale($locale);
        $num = function ($n) use ($locale) { return self::number(max(0, (int)$n), $locale); };
        $out = [];
        $msg = isset($keys['search_msg']) && is_string($keys['search_msg']) ? trim($keys['search_msg']) : '';
        $emptyMore = is_array($paging) && array_key_exists('record_total', $paging) && (int)$paging['record_total'] === 0
            && isset($paging['page_cursor_next']) && is_scalar($paging['page_cursor_next']) && trim((string)$paging['page_cursor_next']) !== '';
        if ($msg === 'type_more') {
            $out[] = TemplateText::raw('search.type_more', [], $locale);
        } elseif ($msg === 'partial' || $emptyMore) {
            $out[] = TemplateText::raw('search.partial', [], $locale);
        }
        $list = $keys;
        if (array_key_exists('total_exact', $list) && $list['total_exact'] === false && isset($list['total_about']) && is_numeric($list['total_about']) && (int)$list['total_about'] > 0) {
            $out[] = TemplateText::raw('search.about', ['n' => $num($list['total_about'])], $locale);
        } elseif (!empty($list['total_more']) && isset($list['total_all']) && is_numeric($list['total_all']) && (int)$list['total_all'] > 0) {
            $out[] = TemplateText::raw('list.more', ['n' => $num($list['total_all'])], $locale);
        }
        return $out;
    }

    /**
     * Result count for a search / list header (NOLIMIT T2): ['n' => int, 'exact' => bool, 'text' => plain text]. Exact
     * totals give the number in the interface language's format ("355,000"); a planner estimate (`total_exact` false,
     * `total_about` above the numbered range) gives "About 355,000 results"; 'short' = the number alone ("≈355,000" for an
     * estimate) for the themes' own counters (the list note then says "About N results"). Escape at output.
     */
    public static function resultCount($paging, $list = null, $locale = null)
    {
        $locale = self::pagerLocale($locale);
        $n = is_array($paging) && isset($paging['record_total']) && is_numeric($paging['record_total']) ? max(0, (int)$paging['record_total']) : 0;
        $exact = true;
        $list = self::noteKeys($list, $paging); // the list result's keys, else those $__PAGING__ carries (after the volist)
        if (is_array($list) && array_key_exists('total_exact', $list) && $list['total_exact'] === false && isset($list['total_about']) && is_numeric($list['total_about'])) {
            $n = max($n, (int)$list['total_about']);
            $exact = false;
        }
        $text = $exact || !class_exists(__NAMESPACE__ . '\\TemplateText') ? self::number($n, $locale) : TemplateText::raw('search.about', ['n' => self::number($n, $locale)], $locale);
        return ['n' => $n, 'exact' => $exact, 'text' => $text, 'short' => ($exact ? '' : "\u{2248}") . self::number($n, $locale)];
    }

    /* ------------------------------------------------------------------------------------------------------------
     * NOLIMIT D1 (lane T): one pager behaviour for the 18 themes. pager() builds the model, pagerHtml() renders it with a
     * theme "skin" (the theme's own markup per item, placeholders %url% %rel% %aria% %attr% %text% %label% %cur% %total%),
     * pagerMoreHtml() the shared jump links + "Go to page" form (static/template-ui/pager.js) + the open-mode links.
     * ------------------------------------------------------------------------------------------------------------ */

    /** Grouping separator per interface language (CLDR, Latin digits); 'es' groups from 5 digits, 'hi' in lakhs. */
    const NUMBER_SEP = ['de' => '.', 'es' => '.', 'id' => '.', 'pt' => '.', 'tr' => '.', 'vi' => '.', 'fr' => "\u{202F}", 'ru' => "\u{00A0}"];

    /** Page and jump steps of the shared pager (+/-10,000 only where the target exists). */
    const PAGER_JUMPS = [-10000, -1000, -100, -10, 10, 100, 1000, 10000];

    /** The shared pager shows jump links and the "Go to page" form from this many pages. */
    const PAGER_JUMP_FROM = 11;

    private static $pagerScript = false;
    private static $pagerSeq = 0;

    /** Cache-buster of a shared asset in static/template-ui/ (file time, so a changed file is never served stale). */
    public static function assetVersion($file)
    {
        $file = is_string($file) ? basename($file) : '';
        $path = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR) . 'static/template-ui/' . $file;
        return ($file !== '' && is_file($path)) ? (string)filemtime($path) : '1';
    }

    private static $pagerTexts = [];

    /** TemplateText::raw() once per key and language per request (a pager asks for about 30 labels), then {name} filled here. */
    private static function pagerText($key, array $vals, $locale)
    {
        if (!isset(self::$pagerTexts[$locale][$key])) {
            self::$pagerTexts[$locale][$key] = class_exists(__NAMESPACE__ . '\\TemplateText') ? TemplateText::raw($key, [], $locale) : $key;
        }
        $replace = [];
        foreach ($vals as $name => $value) { $replace['{' . $name . '}'] = (string)$value; }
        return $replace ? strtr(self::$pagerTexts[$locale][$key], $replace) : self::$pagerTexts[$locale][$key];
    }

    private static function pagerLocale($locale)
    {
        if (is_string($locale) && isset(self::languages()[$locale])) { return $locale; }
        if (class_exists(__NAMESPACE__ . '\\TemplateText')) { return TemplateText::locale(); }
        return 'en';
    }

    /** An integer in the interface language's format: 355,000 / 355.000 / 355 000 / 3,55,000 (hi). */
    public static function number($n, $locale = null)
    {
        $n = is_numeric($n) ? (int)$n : 0;
        $abs = $n < 0 ? -$n : $n;
        if ($abs < 0) { $abs = PHP_INT_MAX; }
        $locale = self::pagerLocale($locale);
        $digits = (string)$abs;
        if ($locale === 'hi' && strlen($digits) > 3) {
            $head = strrev(implode(',', str_split(strrev(substr($digits, 0, -3)), 2)));
            $out = $head . ',' . substr($digits, -3);
        } elseif ($locale === 'es' && $abs < 10000) {
            $out = $digits;
        } else {
            $out = number_format($abs, 0, '.', self::NUMBER_SEP[$locale] ?? ',');
        }
        return ($n < 0 ? '-' : '') . $out;
    }

    /** mac_url_page() (the theme's PAGELINK pattern) with a fallback for tests that do not load application/common.php. */
    public static function pageUrl($pattern, $page)
    {
        $pattern = is_string($pattern) ? $pattern : '';
        $page = max(1, (int)$page);
        if (function_exists('mac_url_page')) { return (string)mac_url_page($pattern, $page); }
        $sp = defined('MAC_PAGE_SP') ? (string)MAC_PAGE_SP : '-';
        $url = str_replace($sp . 'PAGELINK', $page > 1 ? $sp . $page : '', $pattern);
        return str_replace('PAGELINK', (string)$page, $url);
    }

    /**
     * A continuation link of the list (NOLIMIT B3 / C3): a same-site address (path + query starting with "/", or an
     * absolute http(s) address on this host) is used as given; a bare cursor value becomes a query parameter of the
     * list's first page: `<vod_time>.<vod_id>` -> after=, a FTS doc id -> cursor= (page/after/cursor of the base removed).
     * Anything else gives ''.
     */
    public static function cursorUrl($value, $pattern)
    {
        if (!is_string($value) && !is_int($value)) { return ''; }
        $v = trim((string)$value);
        if ($v === '' || strlen($v) > 2048 || preg_match('/[\x00-\x20\x7f\\\\<>"\']/', $v)) { return ''; }
        if ($v[0] === '/') { return (strlen($v) > 1 && $v[1] === '/') ? '' : $v; }
        if (preg_match('~^https?://([^/?#]+)(?:[/?#]|$)~i', $v, $m)) {
            $hosts = [];
            if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) { $hosts[] = strtolower($_SERVER['HTTP_HOST']); }
            if (is_string($pattern) && preg_match('~^https?://([^/?#]+)~i', $pattern, $p)) { $hosts[] = strtolower($p[1]); }
            return in_array(strtolower($m[1]), $hosts, true) && strpos($m[1], '@') === false ? $v : '';
        }
        if (!is_string($pattern) || strpos($pattern, 'PAGELINK') === false) { return ''; }
        return self::cursorUrlOn($v, self::pageUrl($pattern, 1));
    }

    /**
     * The bare cursor value $value (`<vod_time>.<vod_id>` -> after=, a FTS doc id -> cursor=) as a query parameter of the
     * list's first page $base (the pager pattern's page 1, or the canonical first page of an empty page; page / after /
     * cursor of the base removed). '' for any other value.
     */
    public static function cursorUrlOn($value, $base)
    {
        if (!is_string($value) && !is_int($value)) { return ''; }
        $v = trim((string)$value);
        if (preg_match('/^[0-9]{1,10}\.[0-9]{1,10}$/D', $v)) {
            $param = 'after';
        } elseif (preg_match('/^[1-9][0-9]{0,19}$/D', $v)) {
            $param = 'cursor';
        } else {
            return '';
        }
        list($path, $keep) = self::baseParts($base);
        $keep[] = $param . '=' . $v;
        return $path . '?' . implode('&', $keep);
    }

    /**
     * The list's first page on $base (the canonical of an empty page, TemplateSeo::languageBase()): the base with page /
     * after / cursor removed from its query, the other parameters kept in their order; '' for a non-string. verify r5
     * (site-r5 NEW): on an empty cursor page that canonical is the page itself (it carries cursor=), so the "First page"
     * button and the jv-pg-first link must not use it unchanged.
     */
    public static function firstOn($base)
    {
        list($path, $keep) = self::baseParts($base);
        return $keep ? $path . '?' . implode('&', $keep) : $path;
    }

    /** [path, query pairs without page / after / cursor] of $base (fragment dropped, empty pairs dropped, keys case-insensitive). */
    private static function baseParts($base)
    {
        $base = is_string($base) ? $base : '';
        $hash = strpos($base, '#');
        if ($hash !== false) { $base = substr($base, 0, $hash); }
        $parts = explode('?', $base, 2);
        $keep = [];
        foreach (isset($parts[1]) && $parts[1] !== '' ? explode('&', $parts[1]) : [] as $pair) {
            $key = strtolower(urldecode(explode('=', $pair, 2)[0]));
            if ($pair === '' || in_array($key, ['page', 'after', 'cursor'], true)) { continue; }
            $keep[] = $pair;
        }
        return [$parts[0], $keep];
    }

    /** verify r8 SR8-1: the filter fields of a search page's canonical (TemplateSeo::model(), Vod::SEARCH_FIELDS without wd). */
    const SEARCH_FILTERS = ['class', 'area', 'lang', 'year', 'letter', 'actor', 'director', 'tag', 'by', 'order', 'state', 'version', 'level'];

    /**
     * verify r8 SR8-1 (D-6 "every video stays reachable through Older"): the first page of a filter-only search list on the
     * list's own pager address, built as Vod::listCacheData() builds the page_url of the same list's pages with rows
     * (mac_url('vod/search', the filters + page PAGELINK), page 1: /search/class/QZ/page/1/), from $base = the page's
     * canonical first page (TemplateSeo::languageBase(): the query form /search/?class=QZ; never the request). The canonical
     * of a search page keeps cursor= but not after= (TemplateSeo::continuation()), so an after= link of an EMPTY page (no
     * page_url) on that base had the canonical's own path, and EdgeCache::canonical() answered 301 to page 1: the Older chain
     * of a class / director list looped back to page 1 after its first empty continuation page (tag / actor pages escaped it
     * only because their canonical is the landing path). '' = keep $base: no route builder (tests), the base is not the search
     * path (category lists keep after= in their canonical), it has no filter or a pair other than SEARCH_FILTERS (wd: a keyword
     * search's own pager is the query form, its cursor= is kept), a value is empty, '0', padded, not UTF-8 or holds a control
     * character, "/" or "\" (Apache refuses %2F / %5C in a path, TemplateSeo gives such values the query form), or the builder
     * gives no address of its own (the canonical's path again would be redirected the same way).
     */
    private static function searchOwnFirst($base)
    {
        if (!function_exists('mac_url') || !is_string($base) || $base === '') { return ''; }
        list($path, $pairs) = self::baseParts($base);
        $params = [];
        foreach ($pairs as $pair) {
            $kv = explode('=', $pair, 2);
            $key = strtolower(rawurldecode($kv[0]));
            $val = rawurldecode($kv[1] ?? '');
            if (!in_array($key, self::SEARCH_FILTERS, true) || isset($params[$key]) || $val === '' || $val === '0' || trim($val) !== $val
                || !preg_match('//u', $val) || preg_match('/[\x00-\x1f\x7f\/\\\\]/', $val)) {
                return '';
            }
            $params[$key] = $val;
        }
        if (!$params) { return ''; }
        try {
            $search = parse_url((string)mac_url('vod/search'), PHP_URL_PATH);
            $pattern = (string)mac_url('vod/search', $params + ['page' => 'PAGELINK']);
        } catch (\Throwable $e) {
            return '';
        }
        // the canonical builder leaves out mac_url()'s language path prefix (TemplateSeo::model() runs without translang)
        $paths = is_string($search) && $search !== '' ? [$search] : [];
        $lang = isset($_GET['translang']) && is_string($_GET['translang']) ? $_GET['translang'] : '';
        if ($paths && $lang !== '' && $lang !== 'en' && strpos($search, '/' . $lang . '/') === 0) { $paths[] = substr($search, strlen($lang) + 1); }
        if (!in_array($path, $paths, true) || strpos($pattern, 'PAGELINK') === false) { return ''; }
        $first = self::pageUrl($pattern, 1);
        if (strlen($first) > 2048 || !preg_match('~^/(?!/)~', $first) || preg_match('/[\x00-\x20\x7f\\\\<>"\'#]/', $first)) { return ''; }
        list($own) = self::baseParts($first);
        return in_array($own, $paths, true) || preg_match('/%(?:2f|5c)/i', $own) ? '' : $first;
    }

    /**
     * The shared pager model. $paging = mac_page_param() ($__PAGING__), $list = the list result ($__LIST__), which may
     * carry the open-mode keys page_cursor_next (older results), page_cursor_oldest, page_cursor_prev (newer results)
     * and page_cursor_on (this page is a cursor page: no numbers). Numbers mode: prev, first edge, gap, the theme's
     * window (page_num), gap, last edge, next; jumps -10,000..+10,000 that land inside 1..last and are not already
     * linked; the "Go to page" form. Open mode: the real numbered pages only (no last edge, no total), the next arrow
     * continues to the older results, links Newer / Older results / Oldest. Never a link past the last page.
     */
    public static function pager($paging, $list = null, $locale = null)
    {
        $pg = is_array($paging) ? $paging : [];
        $ls = is_array($list) ? $list : [];
        $locale = self::pagerLocale($locale);
        $t = function ($key, array $vals = []) use ($locale) { return self::pagerText($key, $vals, $locale); };
        $num = function ($n) use ($locale) { return self::number($n, $locale); };
        $pattern = isset($pg['page_url']) && is_string($pg['page_url']) ? $pg['page_url'] : '';
        $numbered = strpos($pattern, 'PAGELINK') !== false;
        $total = isset($pg['page_total']) && is_numeric($pg['page_total']) ? max(0, (int)$pg['page_total']) : 0;
        $cur = isset($pg['page_current']) && is_numeric($pg['page_current']) ? (int)$pg['page_current'] : 1;
        $cur = max(1, min($cur, max(1, $total)));
        // HO-H-R3-2: an empty page has no page_url (mac_page_param() returns only record_total and the C20 keys), so a bare
        // cursor value gets the page's canonical first page as its base (TemplateSeo::languageBase(): built by the canonical
        // builder, never from the request); a page with rows keeps its own pattern
        $base = '';
        if (!$numbered && class_exists(__NAMESPACE__ . '\\TemplateSeo') && method_exists(__NAMESPACE__ . '\\TemplateSeo', 'languageBase')) {
            $b = TemplateSeo::languageBase();
            // a same-site path + query only (the canonical builder's form): no scheme-relative or odd value
            $base = is_string($b) && $b !== '' && strlen($b) <= 2048 && $b[0] === '/' && (strlen($b) === 1 || $b[1] !== '/')
                && !preg_match('/[\x00-\x20\x7f\\\\<>"\']/', $b) ? $b : '';
        }
        // verify r8 SR8-1: an after= value of such a page goes on the list's own pager address when the base is a filter-only
        // search list (searchOwnFirst(): /search/class/QZ/page/1/?after=T, the form its pages with rows link), never on the
        // canonical query form, whose path EdgeCache answers with a 301 to page 1; cursor= values stay on the base (kept by
        // the search canonical); $own is built once, on the first after= value
        $own = null;
        $cursor = function ($key) use ($pg, $ls, $pattern, $base, &$own) {
            $v = array_key_exists($key, $ls) ? $ls[$key] : ($pg[$key] ?? '');
            $u = self::cursorUrl($v, $pattern);
            if ($u !== '' || $base === '') { return $u; }
            if (is_string($v) && preg_match('/^[0-9]{1,10}\.[0-9]{1,10}$/D', trim($v))) {
                if ($own === null) { $own = self::searchOwnFirst($base); }
                if ($own !== '') { return self::cursorUrlOn($v, $own); }
            }
            return self::cursorUrlOn($v, $base);
        };
        $older = $cursor('page_cursor_next');
        $oldest = $cursor('page_cursor_oldest');
        $newer = $cursor('page_cursor_prev');
        $onCursor = !empty($ls['page_cursor_on']) || !empty($pg['page_cursor_on']);
        $cursorPage = $onCursor; // this page itself is a cursor page (the flag below also covers pages without numbers)
        $open = $older !== '' || $oldest !== '' || $newer !== '' || $onCursor;
        $m = ['show' => false, 'open' => $open, 'on_cursor' => $onCursor, 'current' => $cur, 'total' => $total,
            'items' => [], 'jumps' => [], 'go' => null, 'links' => [], 'locale' => $locale, 'pattern' => $pattern,
            'dir' => class_exists(__NAMESPACE__ . '\\TemplateText') ? TemplateText::direction($locale) : 'ltr', 'max_shown' => 0];
        if (!$numbered) { $total = 0; $onCursor = $onCursor || $open; }
        if (!$open && $total <= 1) { return $m; }
        $url = function ($n) use ($pattern) { return self::pageUrl($pattern, $n); };
        $item = function ($type, $page, $href, array $extra = []) use ($num) {
            return $extra + ['type' => $type, 'page' => $page, 'url' => $href, 'label' => $page > 0 ? $num($page) : '', 'rel' => '', 'aria' => '', 'text' => ''];
        };
        $linked = [];
        if (!$onCursor && $total >= 1) {
            $win = [];
            foreach (isset($pg['page_num']) && is_array($pg['page_num']) ? $pg['page_num'] : [] as $n) {
                if (is_numeric($n) && (int)$n >= 1 && (int)$n <= $total) { $win[(int)$n] = (int)$n; }
            }
            if (!isset($win[$cur])) {
                $win = [];
                for ($n = max(1, min($cur - 2, $total - 4)); $n <= min($total, max($cur + 2, 5)); $n++) { $win[$n] = $n; }
            }
            ksort($win);
            $win = array_values($win);
            $wFirst = $win[0];
            $wLast = $win[count($win) - 1];
            if ($cur > 1) {
                $m['items'][] = $item('prev', $cur - 1, $url($cur - 1), ['rel' => 'prev', 'aria' => $t('ui.previous_page'), 'text' => $t('ui.previous_page')]);
            } else {
                $m['items'][] = ['type' => 'prev_off'];
            }
            if ($wFirst > 1) {
                $m['items'][] = $item('first', 1, $url(1), ['aria' => $t('ui.first_page')]);
                $linked[1] = true;
                if ($wFirst > 2) { $m['items'][] = ['type' => 'gap']; }
            }
            foreach ($win as $n) {
                $m['items'][] = $item($n === $cur ? 'cur' : 'num', $n, $n === $cur ? '' : $url($n), ['aria' => $t('ui.page_n', ['n' => $num($n)])]);
                $linked[$n] = true;
            }
            if ($wLast < $total && !$open) {
                if ($wLast < $total - 1) { $m['items'][] = ['type' => 'gap']; }
                $m['items'][] = $item('last', $total, $url($total), ['aria' => $t('ui.last_page') . ' (' . $num($total) . ')']);
                $linked[$total] = true;
            }
            $m['items'][] = ['type' => 'mid'];
            if ($cur < $total) {
                $m['items'][] = $item('next', $cur + 1, $url($cur + 1), ['rel' => 'next', 'aria' => $t('ui.next_page'), 'text' => $t('ui.next_page')]);
            } elseif ($older !== '') {
                $m['items'][] = $item('next', 0, $older, ['rel' => 'next', 'aria' => $t('search.older'), 'text' => $t('search.older')]);
            } else {
                $m['items'][] = ['type' => 'next_off'];
            }
            $m['max_shown'] = $open ? $wLast : $total;
            if (!$open && $total >= self::PAGER_JUMP_FROM) {
                foreach (self::PAGER_JUMPS as $d) {
                    $to = $cur + $d;
                    if ($to < 1 || $to > $total || isset($linked[$to])) { continue; }
                    $m['jumps'][] = ['delta' => $d, 'page' => $to, 'url' => $url($to),
                        'label' => ($d < 0 ? "\u{2212}" : '+') . $num(abs($d)),
                        'aria' => $t($d < 0 ? 'ui.pages_back' : 'ui.pages_forward', ['n' => $num(abs($d))]),
                        'title' => $t('ui.page_n', ['n' => $num($to)])];
                }
                $first = $url(1);
                $hash = strpos($first, '#');
                if ($hash !== false) { $first = substr($first, 0, $hash); }
                $parts = explode('?', $first, 2);
                $hidden = [];
                foreach (isset($parts[1]) && $parts[1] !== '' ? explode('&', $parts[1]) : [] as $pair) {
                    $kv = explode('=', $pair, 2);
                    $key = urldecode($kv[0]);
                    if ($key === '' || strtolower($key) === 'page') { continue; }
                    $hidden[] = [$key, urldecode($kv[1] ?? '')];
                }
                $m['go'] = ['pattern' => $pattern, 'sp' => isset($pg['page_sp']) && is_string($pg['page_sp']) ? $pg['page_sp'] : (defined('MAC_PAGE_SP') ? (string)MAC_PAGE_SP : '-'),
                    'max' => $total, 'current' => $cur, 'action' => $parts[0], 'hidden' => $hidden,
                    'label' => $t('ui.go_to_page'), 'button' => $t('ui.page_go'), 'of' => $t('ui.page_of', ['n' => $num($cur), 'total' => $num($total)])];
            }
        } else {
            // a cursor page: no numbers; back to the first page / newer results, on to the older results (an empty cursor
            // page: the first page is its canonical without page / after / cursor, verify r5)
            $back = $newer !== '' ? $newer : ($numbered ? $url(1) : ($cursorPage && $base !== '' ? self::firstOn($base) : ''));
            if ($back !== '') {
                $m['items'][] = $item('prev', 0, $back, $newer !== '' ? ['rel' => 'prev', 'aria' => $t('search.newer'), 'text' => $t('search.newer')] : ['aria' => $t('ui.first_page'), 'text' => $t('ui.first_page')]);
            } else {
                $m['items'][] = ['type' => 'prev_off'];
            }
            $m['items'][] = $older !== '' ? $item('next', 0, $older, ['rel' => 'next', 'aria' => $t('search.older'), 'text' => $t('search.older')]) : ['type' => 'next_off'];
        }
        if ($open) {
            if ($onCursor && $numbered) {
                $m['links'][] = ['kind' => 'first', 'url' => $url(1), 'text' => $t('ui.first_page')];
            } elseif ($cursorPage && $base !== '') {
                // an empty cursor page: its canonical carries cursor= (the page itself), the first page has none
                $m['links'][] = ['kind' => 'first', 'url' => self::firstOn($base), 'text' => $t('ui.first_page')];
            }
            if ($newer !== '') { $m['links'][] = ['kind' => 'newer', 'url' => $newer, 'text' => $t('search.newer')]; }
            if ($older !== '') { $m['links'][] = ['kind' => 'older', 'url' => $older, 'text' => $t('search.older')]; }
            if ($oldest !== '') { $m['links'][] = ['kind' => 'oldest', 'url' => $oldest, 'text' => $t('search.oldest')]; }
        }
        foreach ($m['items'] as $it) {
            if (in_array($it['type'], ['prev', 'next', 'num', 'first', 'last'], true)) { $m['show'] = true; break; }
        }
        $m['show'] = $m['show'] || (bool)$m['links'];
        return $m;
    }

    /**
     * The pager in a theme's markup. $skin: 'nav' (class), 'nav_attr', 'long_at' (adds data-long when the largest shown
     * number reaches it), 'open'/'close' (inner wrapper, e.g. <ul>), item templates 'prev', 'prev_off', 'next',
     * 'next_off', 'num', 'cur', 'first', 'last' (default 'num'), 'gap', 'mid' (after the numbers, numbers mode only),
     * 'after' (before </nav>, numbers mode only), 'pad' (2 = 01..09), 'jumps' / 'go' (false = the theme has its own).
     * Values are escaped here; skin strings are theme markup (trusted).
     */
    public static function pagerHtml($paging, $list = null, array $skin = [], $locale = null)
    {
        $m = self::pager($paging, $list, $locale);
        if (!$m['show']) { return ''; }
        $e = function ($v) { return self::escape($v); };
        $sk = $skin + [
            'nav' => 'jv-pager', 'nav_attr' => '', 'long_at' => 0, 'open' => '', 'close' => '',
            'prev' => '<a class="jv-pg"%rel% href="%url%" aria-label="%aria%">&lsaquo;</a>', 'prev_off' => '',
            'next' => '<a class="jv-pg"%rel% href="%url%" aria-label="%aria%">&rsaquo;</a>', 'next_off' => '',
            'num' => '<a class="jv-pg" href="%url%"%attr%>%label%</a>', 'cur' => '<span class="jv-pg is-on" aria-current="page">%label%</span>',
            'first' => null, 'last' => null, 'gap' => '<span class="jv-pg-gap" aria-hidden="true">&hellip;</span>',
            'mid' => '', 'after' => '', 'pad' => 0, 'jumps' => true, 'go' => true,
        ];
        $vars = function (array $it) use ($e, $sk, $m) {
            $label = (string)($it['label'] ?? '');
            if ((int)$sk['pad'] > 1 && isset($it['page']) && $it['page'] > 0 && $it['page'] < 10) { $label = str_pad($label, (int)$sk['pad'], '0', STR_PAD_LEFT); }
            $aria = (string)($it['aria'] ?? '');
            $attr = '';
            if ($aria !== '' && in_array($it['type'], ['first', 'last'], true)) { $attr = ' aria-label="' . $e($aria) . '" title="' . $e($aria) . '"'; }
            return ['%url%' => $e($it['url'] ?? ''), '%rel%' => !empty($it['rel']) ? ' rel="' . $e($it['rel']) . '"' : '', '%aria%' => $e($aria),
                '%attr%' => $attr, '%text%' => $e($it['text'] ?? ''), '%label%' => $e($label),
                '%cur%' => $e(self::number($m['current'], $m['locale'])), '%total%' => $e(self::number($m['total'], $m['locale']))];
        };
        $navAttr = (string)$sk['nav_attr'];
        if ((int)$sk['long_at'] > 0 && $m['max_shown'] >= (int)$sk['long_at']) { $navAttr .= ' data-long'; }
        $html = '<nav class="' . $e($sk['nav']) . '"' . $navAttr . ' aria-label="' . $e(class_exists(__NAMESPACE__ . '\\TemplateText') ? TemplateText::raw('ui.pagination', [], $m['locale']) : 'Pagination') . '">' . $sk['open'];
        foreach ($m['items'] as $it) {
            $type = $it['type'];
            if ($type === 'mid') { $tpl = $m['open'] ? '' : (string)$sk['mid']; }
            elseif ($type === 'first' || $type === 'last') { $tpl = $sk[$type] !== null ? (string)$sk[$type] : (string)$sk['num']; }
            else { $tpl = isset($sk[$type]) ? (string)$sk[$type] : ''; }
            if ($tpl !== '') { $html .= strtr($tpl, $vars($it + ['label' => '', 'url' => '', 'type' => $type])); }
        }
        $html .= $sk['close'];
        if (!$m['open'] && (string)$sk['after'] !== '') { $html .= strtr((string)$sk['after'], $vars(['type' => 'after', 'url' => $m['pattern']])); }
        $html .= '</nav>';
        return $html . self::pagerMoreHtml($m, !empty($sk['jumps']), !empty($sk['go']));
    }

    /** The shared second row: open-mode links, jump links (no JS needed) and the "Go to page" form (pager.js). */
    public static function pagerMoreHtml(array $m, $jumps = true, $go = true)
    {
        $e = function ($v) { return self::escape($v); };
        $t = function ($key, array $vals = []) use ($m) { return self::pagerText($key, $vals, self::pagerLocale($m['locale'] ?? null)); };
        $parts = '';
        foreach ($m['links'] ?? [] as $l) {
            // rel=prev / rel=next stay on the theme's arrows (one each per page)
            $parts .= '<a class="jv-pg-link jv-pg-' . $e($l['kind']) . '" href="' . $e($l['url']) . '" dir="auto">' . $e($l['text']) . '</a>';
        }
        if ($jumps && !empty($m['jumps'])) {
            $parts .= '<span class="jv-pg-jumps" role="group" aria-label="' . $e($t('ui.page_jump')) . '">';
            foreach ($m['jumps'] as $j) {
                $parts .= '<a class="jv-pg-jump" href="' . $e($j['url']) . '" aria-label="' . $e($j['aria']) . '" title="' . $e($j['title']) . '"><span dir="ltr">' . $e($j['label']) . '</span></a>';
            }
            $parts .= '</span>';
        }
        if ($go && !empty($m['go'])) {
            $g = $m['go'];
            $id = 'jv-pg-in-' . (++self::$pagerSeq);
            $parts .= '<form class="jv-pg-go" action="' . $e($g['action']) . '" method="get" data-pattern="' . $e($g['pattern']) . '" data-sp="' . $e($g['sp']) . '" data-max="' . (int)$g['max'] . '">'
                . '<label for="' . $id . '">' . $e($g['label']) . '</label>';
            foreach ($g['hidden'] as $h) { $parts .= '<input type="hidden" name="' . $e($h[0]) . '" value="' . $e($h[1]) . '">'; }
            $parts .= '<input id="' . $id . '" class="jv-pg-in" type="number" name="page" min="1" max="' . (int)$g['max'] . '" step="1" inputmode="numeric" required placeholder="' . (int)$g['current'] . '" aria-describedby="' . $id . '-of">'
                . '<span class="jv-pg-of" id="' . $id . '-of" dir="auto">' . $e($g['of']) . '</span>'
                . '<button class="jv-pg-btn" type="submit">' . $e($g['button']) . '</button></form>';
            if (!self::$pagerScript) {
                self::$pagerScript = true;
                $file = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR) . 'static/template-ui/pager.js';
                $ver = is_file($file) ? (string)filemtime($file) : '1';
                $base = defined('MAC_PATH') ? (string)MAC_PATH : '/';
                $parts .= '<script src="' . $e($base . 'static/template-ui/pager.js?v=' . $ver) . '" defer></script>';
            }
        }
        if ($parts === '') { return ''; }
        return '<div class="jv-pg-more" role="navigation" aria-label="' . $e($t('ui.page_jump')) . '">' . $parts . '</div>';
    }

    /**
     * listNote() as one escaped `<p class="jv-list-note">` (shared partial public/list_note, which passes $__LIST__ and
     * $__PAGING__), '' when there is nothing to say.
     */
    public static function listNoteHtml($list, $paging = null)
    {
        $lines = self::listNote($list, null, $paging);
        if (!$lines) { return ''; }
        $html = '<p class="jv-list-note" role="status">';
        foreach ($lines as $line) { $html .= '<span dir="auto">' . self::escape($line) . '</span>'; }
        return $html . '</p>';
    }

    /**
     * The shared "provider_block" (2.11, pr-R2.3): the provider parent (slug western = xvid, D20 display name) when it
     * AND another top-level video category both have videos (known counts only), else null. Row: type_id, name (plain),
     * url (unescaped), type. A guest group that cannot open the parent gets null.
     */
    public static function providerBlock($slug = 'western')
    {
        $slug = strtolower(trim((string)$slug));
        $list = model('Type')->getCache('type_list');
        if (!is_array($list) || $slug === '') { return null; }
        $mine = null;
        $other = false;
        foreach ($list as $row) {
            if (!is_array($row) || (int)($row['type_pid'] ?? 0) !== 0 || (int)($row['type_status'] ?? 0) !== 1 || (int)($row['type_mid'] ?? 1) !== 1) { continue; }
            $isMine = strtolower((string)($row['type_en'] ?? '')) === $slug;
            if (!\app\common\model\Type::hasVideos($row)) { continue; }
            if ($isMine) { $mine = $row; } else { $other = true; }
        }
        if ($mine === null || !$other) { return null; }
        $groupTypes = $GLOBALS['user']['group']['group_type'] ?? null;
        if (is_string($groupTypes) && strpos(',' . trim($groupTypes, ',') . ',', ',' . (int)$mine['type_id'] . ',') === false) { return null; }
        return [
            'type_id' => (int)$mine['type_id'],
            'name' => self::categoryName($mine),
            'url' => (string)mac_url_type($mine, [], 'type'),
            'type' => $mine,
        ];
    }

    // ------------------------------------------------------------------ related blocks at scale (verify r4 G7, D-L8)
    // The themes' "more to watch" / "same performer" / "same genre" blocks on detail and watch pages. Every lookup is bounded
    // on the slim index set (no B-tree vod_actor / vod_class / vod_score) at any catalog size, its ids are cached per lead /
    // per (category, genre) for cache_time (at least 600 s) including empty answers and failures (RELATED_FAIL_TTL), and
    // FORCE INDEX names only a key ScaleSchema confirmed (D-L1: a missing key is error 1176). The ids are rendered with
    // {maccms:vod ids=...} (status and the visitor group's hidden categories applied there).

    /** Seconds a failed related lookup is remembered (an empty answer is kept like a found one). */
    const RELATED_FAIL_TTL = 300;
    /** Newest rows of a category the genre picks choose from: one list-layer read per category and list revision, shared. */
    const RELATED_WINDOW = 48;
    /** A genre pick's FULLTEXT path (FilterMatch pre-filter + exact LIKE) runs only for a MATCH selecting at most this many rows. */
    const RELATED_MATCH_ROWS = 300;
    /**
     * verify r5 (R5-3, G7 at 7.1 M): rows the related lookups of one request may read from the search table with MATCH, in
     * one statement without ORDER BY (the server stops reading at its LIMIT; FtPlan's FULLTEXT path orders by FTS_DOC_ID and
     * so reads every match first: 251 matches = 666 cold page reads, 516 = 1,168). Measured cold at 7.1 M (MariaDB 11.4,
     * 512 MB pool): 2.5-3.1 page reads per row (a clustered B-tree level + the compressed leaf).
     * verify r7 (T-R7-1, FU-2): = FtIndex::FIELD_TERM_MIN (100). A performer token below it has no term-map row (field tokens
     * are kept from that df), so its MATCH reads EVERY match of the performer while the term statistics are current and the
     * block is its exact newest videos by (vod_time, vod_id); 80 read the 81 oldest of an 81-99-video performer (MATCH
     * without ORDER BY returns them in document order) and listed those. At most 100 rows (D-L8), about 300 cold reads. A
     * token with more matches and no usable term-map row (statistics older than its videos) gets the newest of the rows read.
     */
    const RELATED_FT_ROWS = 100;
    /** verify r5: statement limit (seconds) of that MATCH; a timeout is a failed lookup (RELATED_FAIL_TTL), never an error page. */
    const RELATED_FT_SEC = 0.5;
    /**
     * verify r5: the term map answers a related lookup (FtPlan's exact newest page: the token's map row + that page's
     * documents by primary key) only while at most this many documents lie above the row's cover (FtPlan reads those with
     * a LIKE scan; 2,000 documents ~ 45 pages); otherwise the bounded MATCH answers.
     */
    const RELATED_MAP_GAP = 2000;
    /** Placeholder performer names that never start a "same performer" block (also the AVDB group caption). */
    const RELATED_NO_LEAD = ['actresses & casts', 'unknown', 'updating', 'n/a', 'none', 'null', '-', '--'];
    /** The last related search lookup: path 'map' | 'match' | 'budget' | 'timeout' | 'none', rows read, complete (benches, tests). */
    public static $relatedLast = [];
    private static $relatedMemo = [];
    private static $relatedRows = [];
    /** verify r5: rows still allowed for related search-table MATCHes in this request (null = RELATED_FT_ROWS). */
    private static $relatedFtLeft = null;

    /** cache_time of the site, at least 600 s (the TTL of a found or empty related id list). */
    private static function relatedTtl()
    {
        return max(600, (int)($GLOBALS['config']['app']['cache_time'] ?? 3600));
    }

    /**
     * Ids of one related lookup, memoised per request and cached per $kind + $id: found and empty lists for relatedTtl(),
     * a failure ($build returned null or threw) for RELATED_FAIL_TTL, so a lookup that cannot answer is not repeated on
     * every view. Never throws.
     */
    private static function relatedCache($kind, $id, callable $build)
    {
        $key = (string)($GLOBALS['config']['app']['cache_flag'] ?? 'jv') . '_avl_rel_v1_' . $kind . '_' . md5((string)$id);
        if (isset(self::$relatedMemo[$key])) { return self::$relatedMemo[$key]; }
        $hit = null;
        try { $hit = \think\Cache::get($key); } catch (\Throwable $e) { $hit = null; }
        if (is_array($hit) && isset($hit['ids']) && is_array($hit['ids'])) {
            return self::$relatedMemo[$key] = array_values(array_filter(array_map('intval', $hit['ids'])));
        }
        try { $ids = $build(); } catch (\Throwable $e) { $ids = null; }
        $ok = is_array($ids);
        $ids = $ok ? array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }))), 0, 100) : [];
        try { \think\Cache::set($key, ['ids' => $ids, 'ok' => $ok, 'at' => time()], $ok ? self::relatedTtl() : self::RELATED_FAIL_TTL); } catch (\Throwable $e) { /* asked again next view */ }
        return self::$relatedMemo[$key] = $ids;
    }

    /** Full table name of the videos, '' when it is not a plain identifier. */
    private static function relatedTable()
    {
        $t = (string)\think\Config::get('database.prefix') . 'vod';
        return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $t) ? $t : '';
    }

    /**
     * "Same performer" ids (newest first, at most $limit) for the lead performer $lead (the first item of the stored
     * vod_actor, as stored): (1) the ac_ token of the search index (D-L8: every video listing this performer), bounded
     * (verify r5, R5-3): the term map's exact newest page where the token has a map row (relatedTokenIds), else ONE MATCH
     * that reads at most RELATED_FT_ROWS (100) rows: all of them when the performer has fewer (exact newest first by
     * (vod_time, vod_id), verify r7), else the rows read (still this performer's videos, newest first among them); (2) when
     * the search index is not ready, the stock vod_actor index where ScaleSchema confirmed it (videos whose stored list is
     * the name or starts with it), (3) else the FULLTEXT pre-filter (FilterMatch: its rows hold every LIKE row) with the
     * same exact "listed first" test, only for a MATCH of at most RELATED_MATCH_ROWS rows; else none (the block shows the
     * newest of the category). Cached per lead (a lookup that ran out of its row budget or time is a failure:
     * RELATED_FAIL_TTL).
     */
    public static function relatedActorIds($lead, $limit = 24)
    {
        $raw = is_scalar($lead) ? trim((string)$lead) : '';
        $plain = trim(self::decodeEntities($raw));
        $low = function_exists('mb_strtolower') ? mb_strtolower($plain, 'UTF-8') : strtolower($plain);
        $limit = max(1, min(60, (int)$limit));
        if ($raw === '' || $plain === '' || strlen($raw) > 300 || in_array($low, self::RELATED_NO_LEAD, true)) { return []; }
        return self::relatedCache('actor', $limit . "\x1F" . $raw, function () use ($raw, $plain, $limit) {
            $tbl = self::relatedTable();
            if ($tbl === '') { return []; }
            // (1) the field token of the search index: term map or one bounded MATCH (never FtPlan's unbounded paths)
            if (class_exists('\app\common\util\FtText')) {
                $tok = FtText::fieldToken('ac', $plain);
                if (is_string($tok) && preg_match('/^ac_[a-z0-9_]{1,81}$/D', $tok)) {
                    $ids = self::relatedTokenIds($tok, $limit);
                    if ($ids === false) { return null; } // row budget spent or out of time: a failure, asked again later
                    if (is_array($ids)) { return $ids; }
                }
            }
            $like = addcslashes($raw, '%_\\') . ',%';
            // (2) the stock B-tree vod_actor index, named only when ScaleSchema confirmed it
            $key = class_exists('\app\common\util\ScaleSchema') && method_exists('\app\common\util\ScaleSchema', 'indexName')
                ? (string)ScaleSchema::indexName('actor') : '';
            if ($key !== '' && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $key)) {
                return array_column(\think\Db::query('SELECT vod_id FROM `' . $tbl . '` FORCE INDEX (`' . $key . '`) WHERE (vod_actor = ? OR vod_actor LIKE ?)'
                    . ' AND vod_status = 1 AND vod_recycle_time = 0 ORDER BY vod_id DESC LIMIT ' . $limit, [$raw, $like]), 'vod_id');
            }
            // (3) a small MATCH + the exact test
            if (class_exists('\app\common\util\FilterMatch')) {
                $cond = FilterMatch::prefilter('vod_actor', $raw, $tbl);
                $rows = $cond !== '' ? FilterMatch::selected($cond, $tbl) : null;
                if (is_int($rows) && $rows >= 0 && $rows <= self::RELATED_MATCH_ROWS) {
                    return array_column(\think\Db::query('SELECT vod_id FROM `' . $tbl . '` WHERE ' . $cond . ' AND (vod_actor = ? OR vod_actor LIKE ?)'
                        . ' AND vod_status = 1 AND vod_recycle_time = 0 ORDER BY vod_id DESC LIMIT ' . $limit, [$raw, $like]), 'vod_id');
                }
            }
            return [];
        });
    }

    /**
     * verify r5 (R5-3): the newest $limit vod ids of search-index field token $tok (ac_ / tg_), with a bounded read:
     * (a) the token has a term-map row and the map covers all but RELATED_MAP_GAP of the newest documents: FtPlan's map
     * page (exact; map row + $limit documents by primary key); (b) else, in 'ft' mode, one MATCH reading at most the
     * request's RELATED_FT_ROWS (100 = FtIndex::FIELD_TERM_MIN) rows, no ORDER BY (the server stops at the LIMIT; FtPlan's
     * FULLTEXT path, whose ORDER BY FTS_DOC_ID read every match: 2.5-3.1 cold page reads per match at 7.1 M, is never run):
     * fewer rows than the LIMIT -> every match (a token without a term-map row has fewer than FIELD_TERM_MIN while the term
     * statistics are current), and the $limit newest of them by (vod_time, vod_id) = the plain LIKE's newest (verify r7,
     * T-R7-1: the rows come in document order, which an admin save or a re-import of an old video does not follow); rows up
     * to the LIMIT -> the newest $limit of the rows read. Returns ids, null (the search index cannot answer: the caller's
     * other paths) or false (row budget of the request spent / out of time: a failure for RELATED_FAIL_TTL). Never runs
     * FtPlan's walk or its unbounded FULLTEXT path. Details in $relatedLast.
     */
    private static function relatedTokenIds($tok, $limit)
    {
        self::$relatedLast = ['path' => 'none', 'token' => (string)$tok];
        if (!is_string($tok) || !preg_match('/^(ac|tg)_[a-z0-9_]{1,81}$/D', $tok) || !class_exists('\app\common\util\FtIndex') || !class_exists('\app\common\util\FtPlan')
            || !class_exists('\app\common\model\Vod') || !method_exists('\app\common\model\Vod', 'ftSearchMode')) {
            return null;
        }
        $limit = max(1, min(60, (int)$limit));
        $mode = \app\common\model\Vod::ftSearchMode();
        if ($mode === 'old') { return null; }
        if (self::relatedMapReady($tok)) {
            $plan = FtPlan::search('', ['token' => $tok, 'page' => 1, 'per' => $limit, 'type_ids' => [], 'hidden' => []]);
            if (is_array($plan) && ($plan['path'] ?? '') !== 'old') {
                self::$relatedLast = ['path' => (string)($plan['path'] ?? ''), 'token' => $tok, 'total' => (int)($plan['total'] ?? 0)];
                return array_slice(isset($plan['ids']) && is_array($plan['ids']) ? array_map('intval', $plan['ids']) : [], 0, $limit);
            }
            return null;
        }
        if ($mode !== 'ft') { return null; } // no FULLTEXT index on this server: no MATCH (the walk is not bounded enough here)
        $left = min(self::RELATED_FT_ROWS, self::$relatedFtLeft === null ? self::RELATED_FT_ROWS : (int)self::$relatedFtLeft);
        if ($left < 1) {
            self::$relatedLast = ['path' => 'budget', 'token' => $tok];
            return false;
        }
        $sql = FtIndex::limited('SELECT FTS_DOC_ID, vod_id, vod_time FROM ' . FtIndex::t(FtIndex::T_FT) . ' WHERE MATCH(ft_text) AGAINST('
            . FtIndex::pdo()->quote('+' . $tok) . ' IN BOOLEAN MODE) LIMIT ' . $left, self::RELATED_FT_SEC);
        try {
            $rows = FtIndex::q($sql);
        } catch (\Throwable $e) {
            if (method_exists('\app\common\util\FtIndex', 'isTimeout') && FtIndex::isTimeout($e)) {
                self::$relatedFtLeft = 0;
                self::$relatedLast = ['path' => 'timeout', 'token' => $tok];
                return false;
            }
            throw $e;
        }
        self::$relatedFtLeft = max(0, $left - count($rows));
        // newest first by (vod_time, vod_id), the order of the plain LIKE and of the term map's pages (not the document order)
        $keys = [];
        foreach ($rows as $r) {
            $id = (int)$r['vod_id'];
            if ($id > 0) { $keys[$id] = max(0, (int)($r['vod_time'] ?? 0)); }
        }
        uksort($keys, function ($a, $b) use ($keys) { return [$keys[$b], $b] <=> [$keys[$a], $a]; });
        $ids = array_slice(array_keys($keys), 0, $limit);
        self::$relatedLast = ['path' => 'match', 'token' => $tok, 'rows' => count($rows), 'cap' => $left, 'complete' => count($rows) < $left];
        return $ids;
    }

    /** verify r5: true when FtPlan answers $tok from the term map: usable map, a row for the token, at most RELATED_MAP_GAP documents above its cover. */
    private static function relatedMapReady($tok)
    {
        try {
            if (!method_exists('\app\common\util\FtIndex', 'mapUsable') || !method_exists('\app\common\util\FtIndex', 'mapRow')
                || !FtIndex::ready() || !FtIndex::mapUsable()) {
                return false;
            }
            $row = FtIndex::mapRow($tok);
            if (!is_array($row)) { return false; }
            $cover = (int)($row['cover'] ?? ($row['last_doc'] ?? 0));
            $max = (int)FtIndex::v('SELECT COALESCE(MAX(FTS_DOC_ID), 0) FROM ' . FtIndex::t(FtIndex::T_FT));
            return $max - $cover <= self::RELATED_MAP_GAP;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The newest RELATED_WINDOW rows of category $typeId through the list layer (Vod::listCacheData, by time: idx_type_list
     * with the ScaleSchema-confirmed FORCE INDEX, the list cache per list revision, the visitor group's hidden categories),
     * memoised per request.
     */
    public static function relatedWindow($typeId)
    {
        $typeId = (int)$typeId;
        if ($typeId <= 0 || !function_exists('model')) { return []; }
        if (!isset(self::$relatedRows[$typeId])) {
            $res = model('Vod')->listCacheData(['num' => (string)self::RELATED_WINDOW, 'type' => (string)$typeId, 'order' => 'desc', 'by' => 'time', 'paging' => 'no']);
            self::$relatedRows[$typeId] = isset($res['list']) && is_array($res['list']) ? array_values($res['list']) : [];
        }
        return self::$relatedRows[$typeId];
    }

    /**
     * "Same genre" ids (at most $limit) of category $typeId: among the newest RELATED_WINDOW rows of the category
     * (relatedWindow), those whose vod_class holds $genre (case-insensitive; $prefix: starts with it), ordered by
     * vod_<$by> (hits | score | time) desc, then newest. $match (neon): when the window holds fewer than $limit such rows,
     * the exact query (vod_class LIKE, the category, published, ORDER BY $by) runs with the FilterMatch pre-filter, only
     * for a MATCH of at most RELATED_MATCH_ROWS rows (a rare genre); its answer replaces the window's. $fill (neon): up to
     * $limit with the window's other rows in the same order (the newest of the category, as the block's own fill shows),
     * so the block does not fall back to a live sort over the whole category. Cached per (category, genre, order)
     * including empty answers and failures; the current video is dropped by the caller.
     */
    public static function relatedGenreIds($typeId, $genre, $limit = 10, $by = 'hits', $prefix = false, $match = false, $fill = false)
    {
        $typeId = (int)$typeId;
        $genre = is_scalar($genre) ? trim((string)$genre) : '';
        $limit = max(1, min(60, (int)$limit));
        $by = in_array($by, ['hits', 'score', 'time'], true) ? $by : 'hits';
        if ($typeId <= 0 || $genre === '' || (function_exists('mb_strlen') ? mb_strlen($genre, 'UTF-8') : strlen($genre)) > 60) { return []; }
        return self::relatedCache('genre', implode("\x1F", [$typeId, $genre, $limit, $by, $prefix ? 1 : 0, $match ? 1 : 0, $fill ? 1 : 0]),
            function () use ($typeId, $genre, $limit, $by, $prefix, $match, $fill) {
                $find = function ($hay) use ($genre) { return function_exists('mb_stripos') ? mb_stripos($hay, $genre, 0, 'UTF-8') : stripos($hay, $genre); };
                $pick = [];
                $rest = [];
                foreach (self::relatedWindow($typeId) as $i => $row) {
                    if (!is_array($row) || empty($row['vod_id'])) { continue; }
                    $pos = $find((string)($row['vod_class'] ?? ''));
                    $one = [(float)($row['vod_' . $by] ?? 0), -$i, (int)$row['vod_id']];
                    if ($prefix ? $pos === 0 : $pos !== false) { $pick[] = $one; } else { $rest[] = $one; }
                }
                rsort($pick); // vod_<by> desc, then the window's order (newest first)
                $ids = array_column($pick, 2);
                if ($match && count($ids) < $limit && class_exists('\app\common\util\FilterMatch') && ($tbl = self::relatedTable()) !== '') {
                    $cond = FilterMatch::prefilter('vod_class', $genre, $tbl);
                    $rows = $cond !== '' ? FilterMatch::selected($cond, $tbl) : null;
                    if (is_int($rows) && $rows >= 0 && $rows <= self::RELATED_MATCH_ROWS) {
                        $like = ($prefix ? '' : '%') . addcslashes($genre, '%_\\') . '%';
                        $ids = array_column(\think\Db::query('SELECT vod_id FROM `' . $tbl . '` WHERE ' . $cond . ' AND vod_class LIKE ? AND type_id = ?'
                            . ' AND vod_status = 1 AND vod_recycle_time = 0 ORDER BY vod_' . $by . ' DESC, vod_id DESC LIMIT ' . $limit, [$like, $typeId]), 'vod_id');
                    }
                }
                if ($fill && count($ids) < $limit) {
                    rsort($rest);
                    $ids = array_values(array_unique(array_merge(array_map('intval', $ids), array_column($rest, 2))));
                }
                return array_slice($ids, 0, $limit);
            });
    }

    public static function localizeMany(array $list)
    {
        if (!self::enabled()) { return $list; }
        $locale = self::current();
        if (!self::$tableReady) {
            foreach ($list as $key=>$vod) { $list[$key] = self::decorate($vod,[],$locale); }
            return $list;
        }
        $ids = [];
        foreach ($list as $vod) { $id=(int)($vod['vod_id'] ?? 0); if ($id>0 && !isset(self::$rows[$locale][$id])) { $ids[$id]=$id; } }
        if ($ids) {
            foreach ($ids as $id) { self::$rows[$locale][$id]=[]; }
            try {
                $rows=\think\Db::name('avdb_translation')->where('vod_id','in',array_values($ids))->where('locale','in',array_unique([$locale,'en']))->select();
                foreach ($rows as $row) { $payload=json_decode($row['payload'],true); if (is_array($payload)) { self::$rows[$locale][(int)$row['vod_id']][$row['locale']]=$payload; } }
            } catch (\Throwable $e) { /* Read-only fallback, never CREATE on GET. */ }
        }
        foreach ($list as $key=>$vod) { $list[$key]=self::decorate($vod,self::$rows[$locale][(int)($vod['vod_id'] ?? 0)] ?? [],$locale); }
        return $list;
    }

    public static function localize(array $vod)
    {
        $list=self::localizeMany([$vod]);
        return $list[0];
    }

    /** Correlated localized title predicate; hex literals keep input out of SQL syntax. */
    public static function titleSearch($keyword)
    {
        if (!self::enabled() || count(self::available())<2 || !self::$tableReady || !is_string($keyword) || $keyword==='') { return ''; }
        $table=\think\Db::name('avdb_translation')->getTable();
        $vodTable=\think\Db::name('vod')->getTable();
        if (!preg_match('/^[A-Za-z0-9_]+$/D',$table) || !preg_match('/^[A-Za-z0-9_]+$/D',$vodTable)) { return ''; }
        $locale=self::current();
        // English titles are the base record (vod.vod_name, matched by the caller): the importer never stores 'en'
        // rows (AvdbImport slices 'en' off its locale list), so English needs no subquery and other locales probe
        // only their own row. Probing 'en' too cost one extra primary-key lookup per video (vi search 2.5-3x slower).
        if ($locale==='en') { return '0=1'; }
        $pattern='%' . str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$keyword) . '%';
        $like="CONVERT(0x".bin2hex($pattern)." USING utf8mb4)";
        $title="JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(avdb_t.payload),avdb_t.payload,'{}'),'$.vod_name')) LIKE ".$like;
        // search-speed: on MariaDB JSON_VALUE reads the title in one parse (JSON_VALID + JSON_EXTRACT parsed every
        // payload twice: 3.3-4.9 s per search, now 1.5-1.8 s). Same rows: a JSON_VALUE hit counts only for valid JSON,
        // and every payload where JSON_VALUE gives NULL (missing / null / non-scalar title) still takes the old test.
        if (!class_exists(__NAMESPACE__ . '\\SearchMatch')) { require_once __DIR__ . '/SearchMatch.php'; }
        if (SearchMatch::mariadbAtLeast('10.2.3')) {
            $title="((JSON_VALUE(avdb_t.payload,'$.vod_name') LIKE ".$like." AND JSON_VALID(avdb_t.payload)) OR (JSON_VALUE(avdb_t.payload,'$.vod_name') IS NULL AND ".$title."))";
        }
        return "EXISTS (SELECT 1 FROM `".$table."` avdb_t WHERE avdb_t.vod_id=`".$vodTable."`.vod_id AND avdb_t.locale='".$locale."' AND ".$title.")";
    }

    public static function label($english)
    {
        $dictionary = self::labels();
        return self::escape($dictionary[self::current()][$english] ?? $english);
    }

    private static function labels()
    {
        return [
            'th'=>['Actors'=>'นักแสดง','Updated'=>'อัปเดต','Completed'=>'จบแล้ว','View More'=>'ดูเพิ่มเติม','Details'=>'รายละเอียด','Home'=>'หน้าแรก','Search'=>'ค้นหา','Menu'=>'เมนู','Clear'=>'ล้าง','Viewing history'=>'ประวัติการรับชม','Genres'=>'ประเภท','Region'=>'ภูมิภาค','Year'=>'ปี','Description'=>'เรื่องย่อ','Full description'=>'รายละเอียดทั้งหมด','PLAY NOW'=>'เล่นเลย','You may also like'=>'คุณอาจชอบ','Previous episode'=>'ตอนก่อนหน้า','Next episode'=>'ตอนถัดไป','Close menu'=>'ปิดเมนู','Please enter a keyword...'=>'กรอกคำค้นหา...','Language'=>'ภาษา'],
            'id'=>['Actors'=>'Pemeran','Updated'=>'Diperbarui','Completed'=>'Selesai','View More'=>'Lihat lainnya','Details'=>'Detail','Home'=>'Beranda','Search'=>'Cari','Menu'=>'Menu','Clear'=>'Hapus','Viewing history'=>'Riwayat tontonan','Genres'=>'Genre','Region'=>'Wilayah','Year'=>'Tahun','Description'=>'Deskripsi','Full description'=>'Deskripsi lengkap','PLAY NOW'=>'PUTAR','You may also like'=>'Rekomendasi','Previous episode'=>'Episode sebelumnya','Next episode'=>'Episode berikutnya','Close menu'=>'Tutup menu','Please enter a keyword...'=>'Masukkan kata kunci...','Language'=>'Bahasa'],
            'ko'=>['Actors'=>'출연진','Updated'=>'업데이트','Completed'=>'완결','View More'=>'더 보기','Details'=>'상세 정보','Home'=>'홈','Search'=>'검색','Menu'=>'메뉴','Clear'=>'삭제','Viewing history'=>'시청 기록','Genres'=>'장르','Region'=>'지역','Year'=>'연도','Description'=>'줄거리','Full description'=>'전체 설명','PLAY NOW'=>'재생','You may also like'=>'추천 영상','Previous episode'=>'이전 에피소드','Next episode'=>'다음 에피소드','Close menu'=>'메뉴 닫기','Please enter a keyword...'=>'검색어를 입력하세요...','Language'=>'언어'],
            'de'=>['Actors'=>'Darsteller','Updated'=>'Aktualisiert','Completed'=>'Abgeschlossen','View More'=>'Mehr anzeigen','Details'=>'Details','Home'=>'Startseite','Search'=>'Suche','Menu'=>'Menü','Clear'=>'Löschen','Viewing history'=>'Wiedergabeverlauf','Genres'=>'Genres','Region'=>'Region','Year'=>'Jahr','Description'=>'Beschreibung','Full description'=>'Vollständige Beschreibung','PLAY NOW'=>'ABSPIELEN','You may also like'=>'Empfehlungen','Previous episode'=>'Vorherige Folge','Next episode'=>'Nächste Folge','Close menu'=>'Menü schließen','Please enter a keyword...'=>'Suchbegriff eingeben...','Language'=>'Sprache'],
            'pt'=>['Actors'=>'Atores','Updated'=>'Atualizado','Completed'=>'Concluído','View More'=>'Ver mais','Details'=>'Detalhes','Home'=>'Início','Search'=>'Buscar','Menu'=>'Menu','Clear'=>'Limpar','Viewing history'=>'Histórico','Genres'=>'Gêneros','Region'=>'Região','Year'=>'Ano','Description'=>'Descrição','Full description'=>'Descrição completa','PLAY NOW'=>'REPRODUZIR','You may also like'=>'Recomendações','Previous episode'=>'Episódio anterior','Next episode'=>'Próximo episódio','Close menu'=>'Fechar menu','Please enter a keyword...'=>'Digite uma palavra-chave...','Language'=>'Idioma'],
            'ru'=>['Actors'=>'Актёры','Updated'=>'Обновлено','Completed'=>'Завершён','View More'=>'Показать ещё','Details'=>'Подробности','Home'=>'Главная','Search'=>'Поиск','Menu'=>'Меню','Clear'=>'Очистить','Viewing history'=>'История просмотров','Genres'=>'Жанры','Region'=>'Регион','Year'=>'Год','Description'=>'Описание','Full description'=>'Полное описание','PLAY NOW'=>'СМОТРЕТЬ','You may also like'=>'Рекомендации','Previous episode'=>'Предыдущая серия','Next episode'=>'Следующая серия','Close menu'=>'Закрыть меню','Please enter a keyword...'=>'Введите ключевое слово...','Language'=>'Язык'],
            'ar'=>['Actors'=>'الممثلون','Updated'=>'التحديث','Completed'=>'مكتمل','View More'=>'عرض المزيد','Details'=>'التفاصيل','Home'=>'الرئيسية','Search'=>'بحث','Menu'=>'القائمة','Clear'=>'مسح','Viewing history'=>'سجل المشاهدة','Genres'=>'الأنواع','Region'=>'المنطقة','Year'=>'السنة','Description'=>'الوصف','Full description'=>'الوصف الكامل','PLAY NOW'=>'شاهد الآن','You may also like'=>'قد يعجبك أيضاً','Previous episode'=>'الحلقة السابقة','Next episode'=>'الحلقة التالية','Close menu'=>'إغلاق القائمة','Please enter a keyword...'=>'أدخل كلمة للبحث...','Language'=>'اللغة'],
            'tr'=>['Actors'=>'Oyuncular','Updated'=>'Güncellendi','Completed'=>'Tamamlandı','View More'=>'Daha fazla','Details'=>'Ayrıntılar','Home'=>'Ana sayfa','Search'=>'Ara','Menu'=>'Menü','Clear'=>'Temizle','Viewing history'=>'İzleme geçmişi','Genres'=>'Türler','Region'=>'Bölge','Year'=>'Yıl','Description'=>'Açıklama','Full description'=>'Tam açıklama','PLAY NOW'=>'OYNAT','You may also like'=>'Öneriler','Previous episode'=>'Önceki bölüm','Next episode'=>'Sonraki bölüm','Close menu'=>'Menüyü kapat','Please enter a keyword...'=>'Anahtar kelime girin...','Language'=>'Dil'],
            'hi'=>['Actors'=>'कलाकार','Updated'=>'अपडेट','Completed'=>'पूर्ण','View More'=>'और देखें','Details'=>'विवरण','Home'=>'मुखपृष्ठ','Search'=>'खोज','Menu'=>'मेन्यू','Clear'=>'साफ़ करें','Viewing history'=>'देखने का इतिहास','Genres'=>'शैलियाँ','Region'=>'क्षेत्र','Year'=>'वर्ष','Description'=>'विवरण','Full description'=>'पूरा विवरण','PLAY NOW'=>'अभी देखें','You may also like'=>'सुझाव','Previous episode'=>'पिछला एपिसोड','Next episode'=>'अगला एपिसोड','Close menu'=>'मेन्यू बंद करें','Please enter a keyword...'=>'खोज शब्द दर्ज करें...','Language'=>'भाषा'],
            'ms'=>['Actors'=>'Pelakon','Updated'=>'Dikemas kini','Completed'=>'Selesai','View More'=>'Lihat lagi','Details'=>'Butiran','Home'=>'Laman utama','Search'=>'Cari','Menu'=>'Menu','Clear'=>'Padam','Viewing history'=>'Sejarah tontonan','Genres'=>'Genre','Region'=>'Wilayah','Year'=>'Tahun','Description'=>'Penerangan','Full description'=>'Penerangan penuh','PLAY NOW'=>'MAIN','You may also like'=>'Cadangan','Previous episode'=>'Episod sebelumnya','Next episode'=>'Episod seterusnya','Close menu'=>'Tutup menu','Please enter a keyword...'=>'Masukkan kata kunci...','Language'=>'Bahasa'],
            'tl'=>['Actors'=>'Mga artista','Updated'=>'Na-update','Completed'=>'Kumpleto','View More'=>'Tingnan pa','Details'=>'Mga detalye','Home'=>'Home','Search'=>'Hanapin','Menu'=>'Menu','Clear'=>'Burahin','Viewing history'=>'Kasaysayan ng panonood','Genres'=>'Mga genre','Region'=>'Rehiyon','Year'=>'Taon','Description'=>'Paglalarawan','Full description'=>'Buong paglalarawan','PLAY NOW'=>'IPLAY','You may also like'=>'Mga mungkahi','Previous episode'=>'Nakaraang episode','Next episode'=>'Susunod na episode','Close menu'=>'Isara ang menu','Please enter a keyword...'=>'Maglagay ng keyword...','Language'=>'Wika'],
            'ja'=>['Actors'=>'出演者','Updated'=>'更新日','Completed'=>'完結','View More'=>'もっと見る','Details'=>'詳細','Home'=>'ホーム','Search'=>'検索','Menu'=>'メニュー','Clear'=>'クリア','Viewing history'=>'視聴履歴','Genres'=>'ジャンル','Region'=>'地域','Year'=>'年','Description'=>'あらすじ','Full description'=>'全文を表示','PLAY NOW'=>'再生','You may also like'=>'おすすめ','Previous episode'=>'前のエピソード','Next episode'=>'次のエピソード','Close menu'=>'メニューを閉じる','Please enter a keyword...'=>'キーワードを入力...','Language'=>'言語'],
            'vi'=>['Actors'=>'Diễn viên','Updated'=>'Cập nhật','Completed'=>'Hoàn thành','View More'=>'Xem thêm','Details'=>'Chi tiết','Home'=>'Trang chủ','Search'=>'Tìm kiếm','Menu'=>'Danh mục','Clear'=>'Xóa','Viewing history'=>'Lịch sử xem','Genres'=>'Thể loại','Region'=>'Quốc gia','Year'=>'Năm','Description'=>'Nội dung','Full description'=>'Xem đầy đủ','PLAY NOW'=>'XEM NGAY','You may also like'=>'Có thể bạn thích','Previous episode'=>'Tập trước','Next episode'=>'Tập tiếp','Close menu'=>'Đóng danh mục','Please enter a keyword...'=>'Nhập từ khóa...','Language'=>'Ngôn ngữ'],
            'zh'=>['Actors'=>'演员','Updated'=>'更新','Completed'=>'已完结','View More'=>'查看更多','Details'=>'详情','Home'=>'首页','Search'=>'搜索','Menu'=>'菜单','Clear'=>'清除','Viewing history'=>'观看历史','Genres'=>'类型','Region'=>'地区','Year'=>'年份','Description'=>'简介','Full description'=>'完整简介','PLAY NOW'=>'立即播放','You may also like'=>'猜你喜欢','Previous episode'=>'上一集','Next episode'=>'下一集','Close menu'=>'关闭菜单','Please enter a keyword...'=>'请输入关键词...','Language'=>'语言'],
            'fr'=>['Actors'=>'Acteurs','Updated'=>'Mis à jour','Completed'=>'Terminé','View More'=>'Voir plus','Details'=>'Détails','Home'=>'Accueil','Search'=>'Recherche','Menu'=>'Menu','Clear'=>'Effacer','Viewing history'=>'Historique','Genres'=>'Genres','Region'=>'Région','Year'=>'Année','Description'=>'Description','Full description'=>'Description complète','PLAY NOW'=>'LIRE','You may also like'=>'Suggestions','Previous episode'=>'Épisode précédent','Next episode'=>'Épisode suivant','Close menu'=>'Fermer le menu','Please enter a keyword...'=>'Saisissez un mot-clé...','Language'=>'Langue'],
            'es'=>['Actors'=>'Actores','Updated'=>'Actualizado','Completed'=>'Completo','View More'=>'Ver más','Details'=>'Detalles','Home'=>'Inicio','Search'=>'Buscar','Menu'=>'Menú','Clear'=>'Borrar','Viewing history'=>'Historial','Genres'=>'Géneros','Region'=>'Región','Year'=>'Año','Description'=>'Descripción','Full description'=>'Descripción completa','PLAY NOW'=>'REPRODUCIR','You may also like'=>'Recomendaciones','Previous episode'=>'Episodio anterior','Next episode'=>'Episodio siguiente','Close menu'=>'Cerrar menú','Please enter a keyword...'=>'Escribe una palabra clave...','Language'=>'Idioma']
        ];
    }
}
