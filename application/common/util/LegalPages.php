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
 * Legal pages (SEO-ADMIN-BRIEF F.1, CF03, CF05, CF07): /label/<slug>/ for terms, privacy, dmca, 2257, cookies, rendered in
 * the active theme's art/detail template from a synthetic article, or from the core fallback view
 * application/index/view/legal/page.html when the theme render fails or the theme is in FALLBACK_THEMES.
 *
 * Text: the owner's application/data/site/legal/<slug>.json ({"title": "...", "body": "..."}, stored DECODED, CF07) or the
 * shipped template application/data/site/legal-templates/<slug>.txt. Plain-text format: "## " heading, "- " list item,
 * blank line = new paragraph; placeholders {site_name} {site_url} {email} {operator} {dmca_agent} {dmca_address}
 * {custodian} {custodian_address} {date}; a placeholder with an empty value drops the sentence it sits in.
 * format() escapes everything once, then adds only p / br / h2 / ul / li / a. No owner HTML.
 * The "this is our example text" warning lives in the admin only, never on the public page (CF05).
 */
class LegalPages
{
    const SLUGS = ['terms', 'privacy', 'dmca', '2257', 'cookies'];
    /** Themes whose art/detail render passes but looks wrong (review R-5): they always get the core fallback view. */
    const FALLBACK_THEMES = ['ember-modern', 'jav', 'jav-modern'];
    const TITLE_MAX = 120;
    const BODY_MAX = 30000;
    const PLACEHOLDERS = ['site_name', 'site_url', 'email', 'operator', 'dmca_agent', 'dmca_address', 'custodian', 'custodian_address', 'date'];

    /** Slug being rendered right now ('' otherwise): SiteHead skips the age gate on legal pages (F.2). */
    private static $current = '';
    /** Last render facts for tools: ['slug', 'mode' => theme|fallback|bare, 'error']. */
    public static $last = [];

    public static function current() { return self::$current; }

    public static function isSlug($slug)
    {
        return is_string($slug) && in_array($slug, self::SLUGS, true);
    }

    public static function enabled($slug)
    {
        return self::isSlug($slug) && in_array($slug, SeoSettings::legalPages(), true);
    }

    /** Page URL through mac_url() (rewrite on / off, sub-folder installs, CF04). */
    public static function url($slug)
    {
        static $memo = [];
        $key = (string)$slug;
        if (isset($memo[$key])) { return $memo[$key]; }
        if (function_exists('mac_url')) {
            try { return $memo[$key] = (string)mac_url('label/' . $slug); } catch (\Throwable $error) {}
        }
        return $memo[$key] = SiteIcons::base() . 'label/' . $slug . '/';
    }

    private static function appPath()
    {
        return defined('APP_PATH') ? APP_PATH : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    public static function templateFile($slug)
    {
        return self::appPath() . 'data' . DIRECTORY_SEPARATOR . 'site' . DIRECTORY_SEPARATOR . 'legal-templates' . DIRECTORY_SEPARATOR . $slug . '.txt';
    }

    public static function ownFile($slug)
    {
        return SeoSettings::legalDir() . $slug . '.json';
    }

    /** Built-in page name in the visitor's language (catalog key legal.<slug>, English fallback). */
    public static function defaultTitle($slug)
    {
        return SiteHead::t('legal.' . $slug);
    }

    /** The shipped template text (decoded plain text, LF). */
    public static function templateText($slug)
    {
        if (!self::isSlug($slug) || !is_file(self::templateFile($slug))) { return ''; }
        $text = (string)file_get_contents(self::templateFile($slug));
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) { $text = substr($text, 3); }
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Text of a page for the site and the admin editor: ['title' (owner title or ''), 'body', 'own' => bool (owner text),
     * 'updated' => unix time].
     */
    public static function source($slug)
    {
        if (!self::isSlug($slug)) { return ['title' => '', 'body' => '', 'own' => false, 'updated' => 0]; }
        $file = self::ownFile($slug);
        if (is_file($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data) && isset($data['body']) && is_string($data['body']) && trim($data['body']) !== '') {
                return ['title' => self::clip(self::line($data['title'] ?? ''), self::TITLE_MAX), 'body' => self::clip(self::text($data['body']), self::BODY_MAX),
                    'own' => true, 'updated' => (int)filemtime($file)];
            }
        }
        $template = self::templateFile($slug);
        return ['title' => '', 'body' => self::templateText($slug), 'own' => false, 'updated' => is_file($template) ? (int)filemtime($template) : 0];
    }

    /**
     * Save the owner's text (admin, DECODED input: html_entity_decode once of the posted value, CF07). Atomic (temp +
     * rename). Returns ['ok', 'error' => '' | 'slug' | 'title_long' | 'body_long' | 'body_empty' | 'write'].
     */
    public static function save($slug, $title, $body)
    {
        if (!self::isSlug($slug)) { return ['ok' => false, 'error' => 'slug']; }
        $title = self::line($title);
        $body = self::text($body);
        if (self::length($title) > self::TITLE_MAX) { return ['ok' => false, 'error' => 'title_long']; }
        if (self::length($body) > self::BODY_MAX) { return ['ok' => false, 'error' => 'body_long']; }
        if (trim($body) === '') { return ['ok' => false, 'error' => 'body_empty']; }
        $dir = SeoSettings::legalDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return ['ok' => false, 'error' => 'write']; }
        $json = json_encode(['title' => $title, 'body' => $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        $file = self::ownFile($slug);
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        if (!is_string($json) || file_put_contents($tmp, $json . "\n") === false) { @unlink($tmp); return ['ok' => false, 'error' => 'write']; }
        if (!@rename($tmp, $file)) {
            $ok = @copy($tmp, $file);
            @unlink($tmp);
            if (!$ok) { return ['ok' => false, 'error' => 'write']; }
        }
        return ['ok' => true, 'error' => ''];
    }

    /** "Restore our template": remove the owner's file. */
    public static function restore($slug)
    {
        $file = self::isSlug($slug) ? self::ownFile($slug) : '';
        return $file === '' || !is_file($file) || @unlink($file);
    }

    private static function line($value)
    {
        $value = (string)$value;
        if (!preg_match('//u', $value)) { $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8'); }
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)));
    }

    private static function text($value)
    {
        $value = (string)$value;
        if (!preg_match('//u', $value)) { $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8'); }
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value));
    }

    private static function length($value)
    {
        return function_exists('mb_strlen') ? mb_strlen((string)$value, 'UTF-8') : strlen((string)$value);
    }

    private static function clip($value, $max)
    {
        return self::length($value) <= $max ? $value : (function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max));
    }

    /** Page title (plain text): the owner's, else the catalog page name. */
    public static function title($slug)
    {
        $source = self::source($slug);
        return $source['title'] !== '' ? $source['title'] : self::defaultTitle($slug);
    }

    // ------------------------------------------------------------------ placeholders and formatter

    /** Placeholder values (plain text) for one page. */
    public static function values($updated = 0)
    {
        $site = $GLOBALS['config']['site'] ?? [];
        $decode = function ($value) { return trim(html_entity_decode(is_scalar($value) ? (string)$value : '', ENT_QUOTES | ENT_HTML5, 'UTF-8')); };
        $siteName = $decode($site['site_name'] ?? '');
        $email = $decode($site['site_email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $email = ''; }
        $siteUrl = '';
        if (class_exists('app\\common\\util\\Sitemap') && class_exists('app\\common\\util\\UrlSettings')) {
            try { $siteUrl = (string)Sitemap::absolute(SiteIcons::base()); } catch (\Throwable $error) { $siteUrl = ''; }
        }
        if ($siteUrl === '' && !empty($site['site_url'])) { $siteUrl = 'https://' . trim($decode($site['site_url']), '/') . '/'; }
        $one = function ($path) { return trim(preg_replace('/\s*\n\s*/u', ', ', (string)SeoSettings::get($path))); };
        $operator = $one('site.legal_operator');
        return [
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'email' => $email,
            'operator' => $operator !== '' ? $operator : $siteName,
            'dmca_agent' => $one('site.legal_dmca_agent'),
            'dmca_address' => $one('site.legal_dmca_address'),
            'custodian' => $one('site.legal_custodian'),
            'custodian_address' => $one('site.legal_custodian_address'),
            'date' => gmdate('Y-m-d', $updated > 0 ? (int)$updated : time()),
        ];
    }

    /** Drop every sentence that holds a placeholder with an empty value (raw text in, raw text out). */
    public static function dropEmpty($text, array $values)
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string)$text));
        $out = [];
        foreach ($lines as $line) {
            if (strpos($line, '{') === false) { $out[] = $line; continue; }
            $prefix = '';
            if (preg_match('/^(## |- )/', $line, $m)) { $prefix = $m[1]; $line = substr($line, strlen($prefix)); }
            $sentences = preg_split('/(?<=[.!?])\s+(?=\S)/u', $line);
            $keep = [];
            foreach ($sentences as $sentence) {
                $empty = false;
                if (preg_match_all('/\{([a-z_]+)\}/', $sentence, $m)) {
                    foreach ($m[1] as $name) {
                        if (in_array($name, self::PLACEHOLDERS, true) && trim((string)($values[$name] ?? '')) === '') { $empty = true; break; }
                    }
                }
                if (!$empty) { $keep[] = $sentence; }
            }
            if ($keep) { $out[] = $prefix . implode(' ', $keep); }
        }
        // A heading left without any text under it (all its sentences dropped) goes too.
        $count = count($out);
        for ($i = 0; $i < $count; $i++) {
            if (strncmp($out[$i], '## ', 3) !== 0) { continue; }
            $next = '';
            for ($j = $i + 1; $j < $count; $j++) { if (trim($out[$j]) !== '') { $next = $out[$j]; break; } }
            if ($next === '' || strncmp($next, '## ', 3) === 0) { $out[$i] = ''; }
        }
        return implode("\n", $out);
    }

    private static function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escaped line → links for http(s) URLs and e-mail addresses (rel nofollow). */
    private static function linkify($escaped)
    {
        return preg_replace_callback('~\bhttps?://(?:(?!&quot;|&#039;|&lt;|&gt;)[^\s<>"\'])+|\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b~i', function ($m) {
            $text = $m[0];
            $tail = '';
            while ($text !== '' && strpos('.,;:!?)', substr($text, -1)) !== false) { $tail = substr($text, -1) . $tail; $text = substr($text, 0, -1); }
            if ($text === '') { return $m[0]; }
            $href = stripos($text, 'http') === 0 ? $text : 'mailto:' . $text;
            return '<a href="' . $href . '" rel="nofollow">' . $text . '</a>' . $tail;
        }, $escaped);
    }

    /**
     * Plain text → safe HTML (F.1 "Formatter"): escape first, placeholders replaced after escaping with escaped values,
     * then "## " → h2, "- " → ul/li, blank line → new paragraph, URLs / e-mails → links.
     * $attributes: fixed attribute text for every block (p / h2 / ul), e.g. ' lang="en" dir="ltr"' (never visitor input).
     */
    public static function format($text, array $values, $attributes = '')
    {
        $a = in_array($attributes, ['', ' lang="en" dir="ltr"', ' dir="auto"'], true) ? $attributes : '';
        $text = self::dropEmpty($text, $values);
        $map = [];
        foreach (self::PLACEHOLDERS as $name) { $map['{' . $name . '}'] = self::e($values[$name] ?? ''); }
        $html = '';
        $para = [];
        $list = [];
        $flush = function () use (&$html, &$para, &$list, $a) {
            if ($para) { $html .= '<p' . $a . '>' . implode('<br>', $para) . '</p>'; $para = []; }
            if ($list) { $html .= '<ul' . $a . '><li>' . implode('</li><li>', $list) . '</li></ul>'; $list = []; }
        };
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') { $flush(); continue; }
            $kind = 'p';
            if (strncmp($line, '## ', 3) === 0) { $kind = 'h2'; $line = trim(substr($line, 3)); }
            elseif (strncmp($line, '- ', 2) === 0) { $kind = 'li'; $line = trim(substr($line, 2)); }
            $line = self::linkify(strtr(self::e($line), $map));
            if ($kind === 'h2') { $flush(); $html .= '<h2' . $a . '>' . $line . '</h2>'; continue; }
            if ($kind === 'li') { if ($para) { $html .= '<p' . $a . '>' . implode('<br>', $para) . '</p>'; $para = []; } $list[] = $line; continue; }
            if ($list) { $html .= '<ul' . $a . '><li>' . implode('</li><li>', $list) . '</li></ul>'; $list = []; }
            $para[] = $line;
        }
        $flush();
        return $html;
    }

    /** Plain body text (placeholders filled, markup prefixes removed) for the meta description. */
    public static function plain($text, array $values)
    {
        $text = strtr(self::dropEmpty($text, $values), array_combine(array_map(function ($n) { return '{' . $n . '}'; }, self::PLACEHOLDERS),
            array_map(function ($n) use ($values) { return (string)($values[$n] ?? ''); }, self::PLACEHOLDERS)));
        $parts = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/^(## |- )/', '', trim($line)));
            if ($line !== '') { $parts[] = $line; }
        }
        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
    }

    /**
     * TemplateSeo's legal branch (C.2, CF03): ['title' => plain, 'body' => plain] (decoded), or null when the slug is
     * not an enabled legal page.
     */
    public static function seo($slug)
    {
        if (!self::enabled($slug)) { return null; }
        $source = self::source($slug);
        return ['title' => $source['title'] !== '' ? $source['title'] : self::defaultTitle($slug), 'body' => self::plain($source['body'], self::values($source['updated']))];
    }

    // ------------------------------------------------------------------ rendering (F.1, CF03)

    /** [field => default] of a table (every column, '' or 0 by type), read once per request. */
    private static function columns($table, array $fallback)
    {
        static $memo = [];
        if (isset($memo[$table])) { return $memo[$table]; }
        $row = [];
        try {
            $types = \think\Db::name($table)->getTableInfo('', 'type');
            foreach (is_array($types) ? $types : [] as $field => $type) {
                $row[$field] = preg_match('/int|decimal|float|double/i', (string)$type) ? 0 : '';
            }
        } catch (\Throwable $error) {
            $row = [];
        }
        if (!$row) { foreach ($fallback as $field) { $row[$field] = ''; } }
        return $memo[$table] = $row;
    }

    /** The synthetic article the 18 art/detail templates read (CF03). */
    public static function article($slug)
    {
        $source = self::source($slug);
        $title = $source['title'] !== '' ? $source['title'] : self::defaultTitle($slug);
        // The shipped template text is English on pages of every language (F4 decision): mark each block lang="en"
        // dir="ltr" so an Arabic page does not mirror it (QA g3 C1). The owner's own text: dir="auto" per block.
        $html = self::format($source['body'], self::values($source['updated']), $source['own'] ? ' dir="auto"' : ' lang="en" dir="ltr"');
        $type = self::columns('type', ['type_id', 'type_name', 'type_en', 'type_pid', 'type_mid', 'type_status', 'type_tpl', 'type_tpl_list', 'type_tpl_detail']);
        $type = array_merge($type, ['type_id' => 0, 'type_pid' => 0, 'type_mid' => 2, 'type_status' => 1, 'type_name' => self::defaultTitleLabel(), 'type_en' => 'legal']);
        $obj = self::columns('art', ['art_id', 'type_id', 'type_id_1', 'art_name', 'art_en', 'art_pic', 'art_from', 'art_author', 'art_blurb', 'art_content', 'art_time', 'art_time_add', 'art_hits', 'art_tag', 'art_class']);
        return array_merge($obj, [
            'art_id' => 0, 'type_id' => 0, 'type_id_1' => 0, 'art_name' => $title, 'art_en' => $slug, 'art_status' => 1, 'art_pic' => '',
            'art_from' => '', 'art_hits' => 0, 'art_time' => $source['updated'], 'art_time_add' => $source['updated'], 'art_content' => $html,
            'art_page_list' => [1 => ['title' => $title, 'note' => '', 'content' => $html]], 'art_page_total' => 1,
            'type' => $type, 'type_1' => $type,
        ]);
    }

    private static function defaultTitleLabel()
    {
        return SiteHead::t('legal.footer_label');
    }

    /** The legal links row of the fallback view (same markup as SiteHead's footer row). */
    private static function links()
    {
        return SiteHead::legalRow(SeoSettings::legalPages());
    }

    private static function call($controller, \Closure $fn)
    {
        return \Closure::bind($fn, $controller, get_class($controller))();
    }

    /**
     * Render an enabled legal page with the controller (Label.php): the theme's art/detail through label_fetch($tpl, 0)
     * (not page-cached), comments off for this render, $param['page'] = 1. On any exception the output-buffer level is
     * restored and the core fallback view renders. Returns the HTML, or null when $slug is not an enabled legal page.
     */
    public static function render($controller, $slug)
    {
        if (!self::enabled($slug)) { return null; }
        $obj = self::article($slug);
        $param = function_exists('mac_param_url') ? (array)mac_param_url() : [];
        $param['page'] = 1;
        $theme = defined('MAC_TEMPLATE_DIR') ? (string)MAC_TEMPLATE_DIR : '';
        $comment = $GLOBALS['config']['comment']['status'] ?? 0;
        $GLOBALS['config']['comment']['status'] = 0;
        $level = ob_get_level();
        self::$current = $slug;
        self::$last = ['slug' => $slug, 'mode' => 'theme', 'error' => ''];
        try {
            if (in_array($theme, self::FALLBACK_THEMES, true)) { throw new \RuntimeException('fallback theme'); }
            $html = self::call($controller, function () use ($obj, $param) {
                $this->assign('obj', $obj);
                $this->assign('param', $param);
                $this->assign('__PAGING__', mac_page_param(1, 1, 1, ''));
                return $this->label_fetch('art/detail', 0);
            });
            if (!is_string($html) || stripos($html, '</head>') === false) { throw new \RuntimeException('empty render'); }
        } catch (\Throwable $error) {
            while (ob_get_level() > $level) { ob_end_clean(); }
            self::$last['error'] = $error->getMessage();
            $html = self::fallback($controller, $slug, $obj, $param);
        } finally {
            $GLOBALS['config']['comment']['status'] = $comment;
            self::$current = '';
        }
        return $html;
    }

    /** Core fallback view application/index/view/legal/page.html (fetched by absolute path). */
    private static function fallback($controller, $slug, array $obj, array $param)
    {
        self::$current = $slug;
        self::$last['mode'] = 'fallback';
        $site = $GLOBALS['config']['site'] ?? [];
        $siteName = trim(html_entity_decode((string)($site['site_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $vars = [
            'legal_lang' => self::e(class_exists('app\\common\\util\\TemplateText') ? TemplateText::locale() : 'en'),
            'legal_dir' => self::e(class_exists('app\\common\\util\\TemplateText') ? TemplateText::direction() : 'ltr'),
            'legal_home' => self::e(SiteIcons::base()),
            'legal_site' => self::e($siteName),
            'legal_title' => self::e($obj['art_name']),
            'legal_html' => $obj['art_page_list'][1]['content'],
            'legal_nav' => self::links(),
        ];
        $level = ob_get_level();
        try {
            return self::call($controller, function () use ($obj, $param, $vars) {
                $this->assign('obj', $obj);
                $this->assign('param', $param);
                foreach ($vars as $key => $value) { $this->assign($key, $value); }
                return $this->fetch(APP_PATH . 'index' . DS . 'view' . DS . 'legal' . DS . 'page.html');
            });
        } catch (\Throwable $error) {
            while (ob_get_level() > $level) { ob_end_clean(); }
            self::$last['mode'] = 'bare';
            self::$last['error'] .= ' | ' . $error->getMessage();
            return '<!DOCTYPE html><html lang="' . $vars['legal_lang'] . '" dir="' . $vars['legal_dir'] . '"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $vars['legal_title'] . ' - ' . $vars['legal_site'] . '</title></head>'
                . '<body><header><a href="' . $vars['legal_home'] . '">' . $vars['legal_site'] . '</a></header><main><h1>' . $vars['legal_title'] . '</h1>'
                . $vars['legal_html'] . '</main><footer>' . $vars['legal_nav'] . '</footer></body></html>';
        }
    }
}
