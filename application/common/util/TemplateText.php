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

/** Offline interface translation. It never creates or changes catalog metadata. */
class TemplateText
{
    private static $catalogs = [];

    /** Languages visitors can choose (theme language menus): the offered subset of the 17 (site.lang_offered, D3). */
    public static function languages()
    {
        return array_intersect_key(AvdbLanguage::languages(), array_flip(AvdbLanguage::offeredCodes()));
    }

    /**
     * Category name in the visitor's language (D6): catalog key cat.<type_en> when the catalog has it, else the
     * category's own name. Plain text (decoded once); escape it when printing. $locale: null = the visitor's.
     */
    public static function category(array $type, $locale = null)
    {
        // HP-T-2 (xvid scale build): the category's own English name wins when the catalog knows it, so an owner's
        // choice ("International" or "Western" for the provider parent, D20; any renamed category with a catalog text)
        // reads the same in menus (themes print TemplateText::get(type_name)), breadcrumbs and SEO texts.
        $own = isset($type['type_name']) && is_scalar($type['type_name']) ? trim(html_entity_decode((string)$type['type_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        if ($own !== '') {
            $loc = self::validLocale($locale === null ? self::locale() : $locale);
            if ($loc === 'en') {
                if (in_array($own, self::catalog('en'), true)) { return $own; }
            } else {
                $catalog = self::catalog($loc);
                if (isset($catalog[$own]) && is_string($catalog[$own]) && $catalog[$own] !== '') { return $catalog[$own]; }
            }
        }
        $slug = isset($type['type_en']) && is_scalar($type['type_en']) ? strtolower(trim((string)$type['type_en'])) : '';
        if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $slug)) {
            $key = 'cat.' . $slug;
            $text = self::raw($key, [], $locale);
            if ($text !== '' && $text !== $key) { return $text; }
        }
        $name = isset($type['type_name']) && is_scalar($type['type_name']) ? (string)$type['type_name'] : '';
        return trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function validLocale($locale)
    {
        return is_string($locale) && isset(self::languages()[$locale]) ? $locale : 'en';
    }

    public static function locale()
    {
        return self::validLocale(AvdbLanguage::current());
    }

    public static function direction($locale = null)
    {
        return self::validLocale($locale === null ? self::locale() : $locale) === 'ar' ? 'rtl' : 'ltr';
    }

    private static function readCatalog($path)
    {
        if (!is_file($path)) {
            return [];
        }
        $text = (string)file_get_contents($path);
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
            $text = substr($text, 3);
        }
        $data = json_decode($text, true);
        return is_array($data) ? $data : [];
    }

    /** Catalog files that feed $locale: the shared catalog, then the active theme's own catalog. */
    public static function catalogFiles($locale)
    {
        $locale = self::validLocale($locale);
        $files = [dirname(__DIR__) . '/template_lang/' . $locale . '.json'];
        $theme = defined('MAC_TEMPLATE_DIR') ? (string)MAC_TEMPLATE_DIR : '';
        if ($theme !== '' && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', $theme) === 1) {
            $files[] = ROOT_PATH . 'template/' . $theme . '/lang/' . $locale . '.json';
        }
        return $files;
    }

    private static function catalog($locale)
    {
        $locale = self::validLocale($locale);
        if (!isset(self::$catalogs[$locale])) {
            // A theme ships its own strings in template/<theme>/lang/<locale>.json
            // (same format as the shared catalog); its entries take precedence.
            $data = [];
            foreach (self::catalogFiles($locale) as $path) {
                $data = array_merge($data, self::readCatalog($path));
            }
            self::$catalogs[$locale] = $data;
        }
        return self::$catalogs[$locale];
    }

    public static function raw($key, array $values = [], $locale = null)
    {
        $key = is_string($key) ? $key : '';
        $english = self::catalog('en');
        $source = isset($english[$key]) && is_string($english[$key]) ? $english[$key] : $key;
        $locale = self::validLocale($locale === null ? self::locale() : $locale);
        $catalog = $locale === 'en' ? [] : self::catalog($locale);
        $text = isset($catalog[$source]) && is_string($catalog[$source]) ? $catalog[$source] : $source;
        $replace = [];
        foreach ($values as $name => $value) {
            if (is_string($name) && preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $name) && is_scalar($value)) {
                $replace['{' . $name . '}'] = (string)$value;
            }
        }
        return strtr($text, $replace);
    }

    public static function get($key, array $values = [], $locale = null)
    {
        return htmlspecialchars(self::raw($key, $values, $locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function json(array $keys)
    {
        $messages = [];
        foreach ($keys as $key) { if (is_string($key)) { $messages[$key] = self::raw($key); } }
        return json_encode($messages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** Query keys that select page content (the keys of mac_param_url(), which the page cache key is made of). */
    const PAGE_QUERY_KEYS = ['page','ajax','tid','mid','rid','pid','sid','nid','uid','level','score','limit','id','ids','wd','en',
        'actor','area','blood','by','class','director','domain','file','lang','letter','name','order','sex','starsign','state',
        'tag','type','url','version','year'];

    /**
     * Safe, current-site link; retains search/filter/page state. HTML escaped for href.
     * Built from the page's canonical URL (TemplateSeo::languageBase(), set when the theme printed the SEO head), never
     * from the request: the page cache shares one copy between every URL with the same parameters, so links copied
     * from a crafted request (/category/x/index-2/, ?junk=1) would be served to everyone (core-polish). Without a
     * canonical (a page without the SEO head) the request path is used with only the content-selecting query keys.
     */
    public static function languageUrl($locale)
    {
        $locale = self::validLocale($locale);
        $base = class_exists('app\\common\\util\\TemplateSeo') ? TemplateSeo::languageBase() : null;
        $canonical = is_string($base) && $base !== '';
        $uri = $canonical ? $base : (string)($_SERVER['REQUEST_URI'] ?? '/');
        $parts = parse_url($uri);
        $path = is_array($parts) ? ($parts['path'] ?? '/') : '/';
        if (!is_array($parts) || isset($parts['host']) || isset($parts['scheme'])
            || !is_string($path) || substr($path,0,1) !== '/' || substr($path,0,2) === '//'
            || preg_match('/[\x00-\x20\x7f\\\\]/', $path)) { $path = '/'; }
        $query = [];
        if (is_array($parts) && isset($parts['query']) && strlen($parts['query']) <= 8192) { parse_str($parts['query'], $query); }
        unset($query['translang'], $query['ui_lang'], $query['ui_lang_set']);
        if (!$canonical) { $query = array_intersect_key($query, array_flip(self::PAGE_QUERY_KEYS)); }
        $query['ui_lang'] = $locale;
        $query['ui_lang_set'] = '1'; // HO-H-1 / HP-T-1: only the switcher stores the avdb_ui_lang cookie
        return htmlspecialchars($path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
