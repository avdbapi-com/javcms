<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\admin\controller;
use http\Cookie;
use think\Db;
use think\Config;
use think\Cache;
use think\View;
use app\admin\util\ThemeGate;
use app\admin\util\GlobalModules;

class System extends Base
{

    /**
     * Template selection gate (see app\admin\util\ThemeGate): a folder is selectable when it
     * is complete (info.ini + html/index/index.html, no path escape), not `status = draft`,
     * and passes the cached safety scan. Names in deployment/reviewed-themes.json are always
     * selectable (operator allow-list).
     */
    protected static function templateReady($templateDir, $htmlDir = 'html')
    {
        return ThemeGate::ready($templateDir, $htmlDir);
    }

    public static function availableTemplates()
    {
        return ThemeGate::available();
    }

    /**
     * Selector rows: every selectable theme with its info.ini display name, plus the active
     * theme even when it no longer passes the gate (so saving other settings never switches
     * the live theme silently).
     */
    protected static function templateChoices($current)
    {
        $choices = [];
        $hidden = [];
        foreach (ThemeGate::overview() as $row) {
            if ($row['ready']) {
                $choices[] = ['dir' => $row['dir'], 'name' => $row['name'], 'note' => ''];
            } elseif ($row['dir'] === $current && $row['reason'] !== 'invalid' && $row['reason'] !== 'incomplete') {
                $choices[] = ['dir' => $row['dir'], 'name' => $row['name'], 'note' => $row['reason']];
            } else {
                $hidden[] = $row;
            }
        }
        return [$choices, $hidden];
    }

    /** The configured site template (ThinkPHP 5.0 config() resolves only two key levels). */
    protected static function activeTemplate()
    {
        $maccms = config('maccms');
        return is_array($maccms) && isset($maccms['site']['template_dir']) && is_string($maccms['site']['template_dir'])
            ? $maccms['site']['template_dir'] : '';
    }

    /** The active theme may be saved unchanged even if it no longer passes the gate. */
    protected static function templateKeepsCurrent($templateDir, $htmlDir)
    {
        $current = self::activeTemplate();
        return is_string($current) && $current !== '' && $templateDir === $current
            && ThemeGate::structure($templateDir, $htmlDir) !== null;
    }

    /** Recursive merge for settings arrays: posted values win, stored keys the form does not send are kept. */
    protected static function mergeKeep(array $stored, array $posted)
    {
        foreach ($posted as $key => $value) {
            if (is_array($value) && isset($stored[$key]) && is_array($stored[$key])) {
                $stored[$key] = self::mergeKeep($stored[$key], $value);
            } else {
                $stored[$key] = $value;
            }
        }
        return $stored;
    }

    /** Page title from a menu label key of the new menu; the old title while that key is not shipped yet. */
    protected static function uxTitle($key, $fallback)
    {
        $text = lang($key);
        return ($text === $key || $text === '') ? lang($fallback) : $text;
    }

    public function test_email()
    {
        if (!Request()->isPost()) {
            return json(['code' => 1001, 'msg' => lang('illegal_request')]);
        }
        $post = input('post.', [], '');
        $validate = \think\Loader::validate('Token');
        if (!$validate->check($post)) {
            return json(['code' => 1001, 'msg' => $validate->getError(), '__token__' => Request()->token()]);
        }
        try {
            if (!isset($post['email']) || !is_array($post['email'])) {
                throw new \InvalidArgumentException('admin/system/configemail/invalid_settings');
            }
            // F21: a test needs the SMTP host (and a recipient, checked below).
            $testHost = $post['email']['phpmailer']['host'] ?? ($GLOBALS['config']['email']['phpmailer']['host'] ?? '');
            if (!is_scalar($testHost) || trim((string)$testHost) === '') {
                return json(['code' => 1001, 'msg' => lang('admin/ux/system/email_need_host'), '__token__' => Request()->token()]);
            }
            $settings = \app\common\util\EmailSettings::merge($GLOBALS['config']['email'], $post['email']);
            $to =\app\common\util\EmailSettings::recipient($settings['test'] ?? '');
            $providers = mac_extends_list('email');
            $installed = array_change_key_case($providers['ext_list'], CASE_LOWER);
            $provider = strtolower($settings['type'] ?? '');
            if ($provider === '' || !isset($installed[$provider])) {
                throw new \InvalidArgumentException('admin/system/configemail/invalid_settings');
            }
            foreach (array_keys($providers['ext_list']) as $name) {
                if (strtolower($name) === $provider) { $provider = $name; break; }
            }
            $class = '\\app\\common\\extend\\email\\' . $provider;
            $adapter = new $class();
            $conf = $settings[strtolower($settings['type'])] ?? [];
            if (!is_array($conf)) {
                throw new \InvalidArgumentException('admin/system/configemail/invalid_settings');
            }
            $conf['nick'] = $settings['nick'] ?? '';
            $this->label_maccms();
            View::instance()->assign(['code' => mac_get_rndstr(6, 'num'), 'time' => $settings['time']]);
            $title = View::instance()->display($settings['tpl']['test_title'] ?? '');
            $body = htmlspecialchars_decode(View::instance()->display($settings['tpl']['test_body'] ?? ''));
            $result = $adapter->submit($to, $title, $body, $conf);
            $ok = isset($result['code']) && $result['code'] == 1;
            return json(['code' => $ok ? 1 : 1001,
                'msg' => lang($ok ? 'test_ok' : 'admin/system/configemail/send_failed'),
                '__token__' => Request()->token()]);
        } catch (\InvalidArgumentException $error) {
            return json(['code' => 1001, 'msg' => lang('admin/system/configemail/invalid_settings'), '__token__' => Request()->token()]);
        } catch (\Throwable $error) {
            // SMTP credentials and transport diagnostics never leave the server.
            return json(['code' => 1001, 'msg' => lang('admin/system/configemail/send_failed'), '__token__' => Request()->token()]);
        }
    }

    public function test_cache()
    {
        if (!Request()->isPost()) {
            return json(['code' => 1001, 'msg' => lang('illegal_request')]);
        }
        $param = input('post.', [], '');
        $validate = \think\Loader::validate('Token');
        if (!$validate->check($param)) {
            return json(['code' => 1001, 'msg' => $validate->getError(), '__token__' => Request()->token()]);
        }
        $driver = null;
        $key = null;
        $ok = false;
        try {
            foreach (['type', 'host', 'port', 'username', 'password'] as $field) {
                if (isset($param[$field]) && !is_string($param[$field])) {
                    throw new \InvalidArgumentException();
                }
            }
            $type = strtolower($param['type'] ?? '');
            if (!in_array($type, ['file', 'memcache', 'memcached', 'redis'], true)) {
                throw new \InvalidArgumentException();
            }
            $options = ['type' => $type, 'persistent' => false, 'timeout' => $type === 'memcached' ? 2000 : 2];
            if ($type !== 'file') {
                $hosts = explode(',', $param['host'] ?? '');
                $ports = explode(',', $param['port'] ?? '');
                if (count($hosts) > 32 || count($ports) > 32 || ($type === 'redis' && (count($hosts) !== 1 || count($ports) !== 1))) {
                    throw new \InvalidArgumentException();
                }
                foreach ($hosts as $host) {
                    if (strlen($host) > 253 || (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
                        && (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $host)
                            || preg_match('/^[0-9.]+$/D', $host)))) {
                        throw new \InvalidArgumentException();
                    }
                }
                foreach ($ports as $port) {
                    if (!preg_match('/^[0-9]{1,5}$/D', $port) || (int)$port < 1 || (int)$port > 65535) {
                        throw new \InvalidArgumentException();
                    }
                }
                $options['host'] = implode(',', $hosts);
                $options['port'] = $type === 'redis' ? (int)$ports[0] : implode(',', $ports);
                $options['username'] = $param['username'] ?? '';
                $options['password'] = $param['password'] ?? '';
            }
            // A unique, short-lived key cannot overwrite application data.
            $key = 'admin-cache-test-' . bin2hex(random_bytes(16));
            $value = bin2hex(random_bytes(16));
            $driver = Cache::connect($options, true);
            if ($type === 'redis') {
                $driver->handler()->setOption(\Redis::OPT_READ_TIMEOUT, 2);
            }
            $ok = $driver->set($key, $value, 30) && $driver->get($key) === $value;
        } catch (\Throwable $error) {
            $ok = false;
        } finally {
            if ($driver !== null && $key !== null) {
                try { $ok = $driver->rm($key) && $ok; }
                catch (\Throwable $error) { $ok = false; }
            }
        }
        return json(['code' => $ok ? 1 : 1001, 'msg' => lang($ok ? 'test_ok' : 'admin/system/config/test_err'),
            '__token__' => Request()->token()]);
    }

    public function config()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($config)){
                return $this->ajaxErrorWithFreshToken($validate->getError(), ['retry' => 1]);
            }
            unset($config['__token__']);

            // O24 theme gallery: "Use this theme" changes only the theme (after the token check, returns early).
            if ((string)($config['theme_only'] ?? '') === '1') {
                return $this->themeOnlySave($config['template_dir'] ?? '');
            }
            // 1.2.1 Themes page card "Visitor theme switcher": changes only the switcher settings (returns early).
            if ((string)($config['switcher_only'] ?? '') === '1') {
                return $this->switcherOnlySave($config['site'] ?? null, $config['switcher_rev'] ?? null);
            }

            // Validate the selected public and mobile templates before reading
            // info.ini or writing configuration. A directory name is user input
            // at this boundary and must never be allowed to escape template/.
            if (!isset($config['site']) || !is_array($config['site'])) {
                return $this->error(lang('save_err'));
            }
            $homeTitlePosted = array_key_exists('site_home_title', $config['site']);
            $templateDir = $config['site']['template_dir'] ?? '';
            $htmlDir = $config['site']['html_dir'] ?? '';
            if (!self::templateReady($templateDir, $htmlDir) && !self::templateKeepsCurrent($templateDir, $htmlDir)) {
                return $this->error(lang('admin/system/config/template_not_ready'));
            }
            $mobileTemplate = $config['site']['mob_template_dir'] ?? '';
            if ($mobileTemplate !== '' && $mobileTemplate !== 'no'
                && !self::templateReady($mobileTemplate, $config['site']['mob_html_dir'] ?? $htmlDir)) {
                return $this->error(lang('admin/system/config/template_not_ready'));
            }

            // adsdir comes from the theme's info.ini; ThemeGate validates it as a plain folder name.
            $config['site']['ads_dir'] = ThemeGate::adsDir($templateDir);
            $config['site']['mob_ads_dir'] = ($mobileTemplate !== '' && $mobileTemplate !== 'no') ? ThemeGate::adsDir($mobileTemplate) : 'ads';

            // Visitor theme switcher: toggle, display mode, corner, optional subset, and the offered-list snapshot that
            // the public side reads (app\common\util\ThemeSwitcher). Since 1.2.1 these settings live on the Themes page
            // (switcherOnlySave()), their single source of truth: keepStored() replaces whatever switcher field this
            // post still carries (a Site identity tab opened before the update) with the stored values, then they are
            // normalised with the snapshot, which depends on the template chosen here. A stored theme that is no longer
            // selectable is dropped and reported instead of blocking the whole form; only a malformed name is an error.
            $config['site'] = \app\common\util\ThemeSwitcher::keepStored($config['site'], (array)(config('maccms')['site'] ?? []));
            $switcherDropped = [];
            $switcher = \app\common\util\ThemeSwitcher::adminSave($config['site'], function ($slug) {
                return self::templateReady($slug, 'html');
            });
            if ($switcher === null) {
                return $this->error(lang('admin/system/config/theme_switcher_invalid'));
            }
            list($config['site'], $switcherDropped) = $switcher;
            // [icon-merge] insertion point: icon-system site settings are normalised here.
            // SEO-ADMIN-BRIEF A.1 / A.6 / A.7 / B.1: validate the new site keys, keep every stored site key the form does
            // not post (CF06), apply the code right, write the manifest and the root favicon.ico.
            $seoSite = $this->seoSiteSave($config['site'], $switcherDropped);
            if (is_string($seoSite)) {
                return $this->error($seoSite);
            }
            $config['site'] = $seoSite['site'];
            // A.9: hidden app fields (suffix, input_type) keep their stored values.
            $config['app'] = (isset($config['app']) && is_array($config['app']) ? $config['app'] : [])
                + (array)(config('maccms')['app'] ?? []);

            if(empty($config['app']['cache_flag'])){
                $config['app']['cache_flag'] = substr(md5(time()),0,10);
            }
            // O22: keep a valid time zone, else UTC (Init.php applies it on every request).
            $config['app']['timezone'] = (isset($config['app']['timezone']) && is_string($config['app']['timezone'])
                && in_array($config['app']['timezone'], timezone_identifiers_list(), true)) ? $config['app']['timezone'] : 'UTC';

            $config['app']['search_vod_rule'] = join('|', !empty($config['app']['search_vod_rule']) ? (array)$config['app']['search_vod_rule'] : []);
            $config['app']['search_art_rule'] = join('|', !empty($config['app']['search_art_rule']) ? (array)$config['app']['search_art_rule'] : []);
            $config['app']['vod_search_optimise'] = join('|', !empty($config['app']['vod_search_optimise']) ? (array)$config['app']['vod_search_optimise'] : []);
            $config['app']['vod_search_optimise_cache_minutes'] = (int)$config['app']['vod_search_optimise_cache_minutes'];

            $config['extra'] = [];
            if(!empty($config['app']['extra_var'])){
                $extra_var = str_replace(array(chr(10),chr(13)), array('','#'),$config['app']['extra_var']);
                $tmp = explode('#',$extra_var);
                foreach($tmp as $a){
                    if(strpos($a,'$$$')!==false){
                        $tmp2 = explode('$$$',$a);
                        $config['extra'][$tmp2[0]] = $tmp2[1];
                    }
                }
                unset($tmp,$tmp2);
            }

            // site_tj was decoded once (or kept as stored) by seoSiteSave().
            $config_new['site'] = $config['site'];
            $config_new['app'] = $config['app'];
            $config_new['extra'] = $config['extra'];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);
            // r5 repair (function r5 P2-1): the old SEO page title (seo.vod.name, read by no theme) only pre-fills an empty
            // Home page title once. When this form has posted the Home page title, it is cleared, so a later rename of the
            // site is not undone by the old name coming back into the field and being saved.
            if ($homeTitlePosted && isset($config_new['seo']['vod']) && is_array($config_new['seo']['vod'])
                && (string)($config_new['seo']['vod']['name'] ?? '') !== '') {
                $config_new['seo']['vod']['name'] = '';
            }

            // A.7 / A.9: static/js/tj.js is loaded by nobody, so it is no longer written (the old file is left).

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            $this->seoCheckForget();
            $savedMsg = lang('save_ok') . ($seoSite['notice'] !== '' ? ' ' . lang('admin/ux/seo/' . $seoSite['notice']) : '');
            if (!empty($switcherDropped)) {
                return $this->success($savedMsg . ' ' . lang('admin/system/config/theme_switcher_dropped') . ': '
                    . htmlspecialchars(implode(', ', $switcherDropped), ENT_QUOTES, 'UTF-8'));
            }
            return $this->success($savedMsg);
        }


        if ((string)input('get.section', '') === 'theme') {
            return $this->themeGallery();
        }

        list($templates, $hiddenTemplates) = self::templateChoices(self::activeTemplate());
        $this->assign('templates', $templates);
        $this->assign('hidden_templates', $hiddenTemplates);
        // 1.2.1: the visitor theme switcher is set on the Themes page; this page shows its state and links there.
        $this->assign('switcher_state', self::switcherState((array)(config('maccms')['site'] ?? [])));

        $langs = glob('./application/lang/*.php');
        foreach ($langs as $k => &$v) {
            $v = str_replace(['./application/lang/','.php'],['',''],$v);
        }
        $this->assign('langs', $langs);

        $usergroup = Db::name('group')->select();
        $this->assign('usergroup', $usergroup);

        $editors = mac_extends_list('editor');
        $this->assign('editors',$editors);

        $config = config('maccms');
        // Accept both GET and POST input by default.
        if (!isset($config['app']['input_type'])) {
            $config['app']['input_type'] = 1;
        }
        $config['app']['vod_search_optimise_cache_minutes'] = model('VodSearch')->getResultCacheMinutes($config);
        // O22: time zone setting (applied by Init.php); an unknown or empty value shows as UTC.
        $zones = timezone_identifiers_list();
        if (!isset($config['app']['timezone']) || !in_array($config['app']['timezone'], $zones, true)) {
            $config['app']['timezone'] = 'UTC';
        }
        $this->assign('timezones', $zones);
        $this->assign('config', $config);
        $this->assign('seo', $this->seoSiteView($config));
        $this->assign('seo_js', self::seoJs());
        $this->assign('title', self::uxTitle('admin/ux/menu/site_identity', 'admin/system/config/title'));
        return $this->fetch('admin@system/config');
    }


    /**
     * O24 "Use this theme": writes only site.template_dir + site.ads_dir (and the switcher snapshot that depends on the
     * default theme). Every other stored value stays as it is; static/js/tj.js is not rewritten.
     */
    protected function themeOnlySave($slug)
    {
        $slug = is_string($slug) ? $slug : '';
        $maccms = config('maccms');
        $site = (array)($maccms['site'] ?? []);
        $htmlDir = (string)($site['html_dir'] ?? 'html');
        if (!ThemeGate::nameIsSafe($slug) || !self::templateReady($slug, $htmlDir)) {
            return $this->ajaxErrorWithFreshToken(lang('admin/system/config/template_not_ready'));
        }
        $site['template_dir'] = $slug;
        $site['ads_dir'] = ThemeGate::adsDir($slug);
        $switcher = \app\common\util\ThemeSwitcher::adminSave($site, function ($name) {
            return self::templateReady($name, 'html');
        });
        if ($switcher === null) {
            return $this->ajaxErrorWithFreshToken(lang('admin/system/config/theme_switcher_invalid'));
        }
        $maccms['site'] = $switcher[0];
        if (mac_arr2file(APP_PATH . 'extra/maccms.php', $maccms) === false) {
            return $this->ajaxErrorWithFreshToken(lang('save_err'));
        }
        if (class_exists('\app\admin\common\Onboarding')) {
            \app\admin\common\Onboarding::flag((int)($this->_admin['admin_id'] ?? 0), 'theme_chosen');
        }
        return $this->ajaxSuccessWithFreshToken(lang('admin/ux/system/theme_used_ok', [ThemeGate::displayName($slug)]));
    }

    /** O24 theme gallery (GET system/config?section=theme), with the per-browser preview cookie. */
    protected function themeGallery()
    {
        $current = self::activeTemplate();
        $preview = input('get.preview', '');
        $preview = is_string($preview) ? $preview : '';
        if ($preview !== '') {
            $secure = \app\common\util\Sitemap::requestIsHttps();
            if ($preview === 'off') {
                setcookie(\app\common\util\ThemePreview::COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
                return $this->redirect(url('system/config') . '?section=theme');
            }
            if (ThemeGate::nameIsSafe($preview) && self::templateReady($preview, 'html')) {
                setcookie(\app\common\util\ThemePreview::COOKIE, \app\common\util\ThemePreview::sign($preview),
                    ['expires' => time() + 3600, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
                return $this->redirect(MAC_PATH);
            }
            return $this->error(lang('admin/system/config/template_not_ready'));
        }
        list($choices) = self::templateChoices($current);
        $cards = [];
        foreach ($choices as $row) {
            if ($row['note'] !== '' && $row['dir'] !== $current) {
                continue;
            }
            $base = ROOT_PATH . 'template' . DIRECTORY_SEPARATOR . $row['dir'] . DIRECTORY_SEPARATOR;
            $img = function ($file) use ($row, $base) {
                return is_file($base . $file) ? MAC_PATH . 'template/' . rawurlencode($row['dir']) . '/' . $file . '?v=' . (int)filemtime($base . $file) : '';
            };
            $cards[] = ['dir' => $row['dir'], 'name' => $row['name'], 'current' => $row['dir'] === $current,
                'dark' => $img('preview-dark.webp'), 'light' => $img('preview-light.webp')];
        }
        // The theme in use first, the rest in the gate's order.
        usort($cards, function ($a, $b) { return (int)$b['current'] - (int)$a['current']; });
        $this->assign('cards', $cards);
        $this->assign('preview_theme', \app\common\util\ThemePreview::current());
        // 1.2.1 card "Visitor theme switcher" (saved by switcherOnlySave()).
        $site = (array)(config('maccms')['site'] ?? []);
        $subset = \app\common\util\ThemeSwitcher::subset($site);
        $offer = [];
        foreach ($choices as $row) {
            if ($row['note'] !== '') {
                continue;
            }
            // The default is listed like every theme: ticking it keeps it in the visitor list after a later change of
            // the default (as the subset always worked); it is offered anyway while it is the default.
            $item = ['dir' => $row['dir'], 'name' => $row['name'], 'default' => $row['dir'] === $current,
                'checked' => in_array($row['dir'], $subset, true)];
            if ($item['default']) { array_unshift($offer, $item); } else { $offer[] = $item; }
        }
        $state = self::switcherState($site);
        $this->assign('switcher', [
            'on' => \app\common\util\ThemeSwitcher::enabled($site),
            'show' => \app\common\util\ThemeSwitcher::show($site),
            'pos' => \app\common\util\ThemeSwitcher::position($site),
            'state' => $state,
            'status' => self::switcherStatusText($site),
            'static' => self::switcherStatic(),
            // the revision this page shows: its save is refused once newer settings are stored (switcherOnlySave())
            'rev' => \app\common\util\ThemeSwitcher::revision($site),
        ]);
        $this->assign('switcher_themes', $offer);
        $this->assign('switcher_modes', \app\common\util\ThemeSwitcher::SHOW_MODES);
        $this->assign('switcher_js', json_encode([
            'leave' => lang('admin/ux/system/leave_unsaved'),
            'saving' => lang('admin/ux/system/switcher_saving'),
            'stale_title' => lang('admin/ux/system/switcher_stale_title'),
            'reload' => lang('admin/ux/system/switcher_reload'),
            'url' => url('system/config'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->assign('title', self::uxTitle('admin/ux/menu/themes', 'admin/ux/system/themes_title'));
        return $this->fetch('admin@system/theme');
    }

    /**
     * 1.2.1 Themes page card (POST system/config switcher_only=1 with site[theme_switcher], site[theme_switcher_show],
     * site[theme_switcher_pos], site[theme_switcher_themes], switcher_rev and __token__): merges ONLY these settings into
     * the stored site settings (ThemeSwitcher::saveOnly(): strict values, the offered-list snapshot) and writes
     * maccms.php. Every other setting stays as it is. Every answer carries a fresh form token (the page keeps one token
     * for both of its forms, like "Use this theme"). The pre-framework page copies are renewed by
     * Base::edgeWatchSettings().
     * switcher_rev is the settings revision the page was loaded with (ThemeSwitcher::revision()); when the stored
     * settings have changed since (another tab or another admin saved them), or the post carries none (a page from
     * before this check), nothing is written and the answer asks to reload (data.stale, the stored revision): an older
     * page never reverts newer settings, not even through its automatic resend after a renewed token. An outdated
     * revision whose save would change nothing (ThemeSwitcher::unchangedBy(): a lost answer, two tabs with the same
     * change) is answered like a save, without writing, and the page takes the stored revision; only the offered-list
     * snapshot is written then when the theme folders changed since the last save (fix round 4, SVVA4-P3-1).
     */
    protected function switcherOnlySave($posted, $rev = null)
    {
        $maccms = config('maccms');
        if (!is_array($maccms)) {
            return $this->ajaxErrorWithFreshToken(lang('save_err'));
        }
        $stored = (array)($maccms['site'] ?? []);
        $ready = function ($slug) {
            return self::templateReady($slug, 'html');
        };
        if (!\app\common\util\ThemeSwitcher::revisionMatches($stored, $rev)) {
            // Settings were saved after this page was opened. Refused, unless the save would change nothing (fix round 3,
            // SVVA3-P3-1): the answer to this page's own last save was lost after it had been written, or another tab
            // saved the same settings. Then nothing is written and the page takes the stored revision.
            $same = \app\common\util\ThemeSwitcher::unchangedBy($stored, $posted, $rev, $ready);
            if ($same === null) {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/system/switcher_stale'), [
                    'stale' => 1,
                    'rev' => \app\common\util\ThemeSwitcher::revision($stored),
                ]);
            }
            // The four settings are the stored ones; the offered-list snapshot is rebuilt from the theme folders like
            // every save (fix round 4, SVVA4-P3-1): a folder that became selectable since the last save is offered.
            if (!empty($same['write'])) {
                $maccms['site'] = $same['site'];
                if (mac_arr2file(APP_PATH . 'extra/maccms.php', $maccms) === false) {
                    return $this->ajaxErrorWithFreshToken(lang('save_err'));
                }
            }
            return $this->switcherSaved($same['site'], $same['dropped']);
        }
        $result = \app\common\util\ThemeSwitcher::saveOnly($stored, $posted, $ready);
        if (empty($result['ok'])) {
            return $this->ajaxErrorWithFreshToken(lang(($result['error'] ?? '') === 'name'
                ? 'admin/system/config/theme_switcher_invalid' : 'admin/ux/system/switcher_invalid'));
        }
        $site = $result['site'];
        $maccms['site'] = $site;
        if (mac_arr2file(APP_PATH . 'extra/maccms.php', $maccms) === false) {
            return $this->ajaxErrorWithFreshToken(lang('save_err'));
        }
        return $this->switcherSaved($site, (array)($result['dropped'] ?? []));
    }

    /** Answer of a card save: the stored values (the card shows them), the page's revision from now on, the status. */
    protected function switcherSaved(array $site, array $dropped)
    {
        $dropped = array_values($dropped);
        $msg = lang('admin/ux/system/switcher_saved');
        if ($dropped) {
            $msg .= ' ' . lang('admin/system/config/theme_switcher_dropped') . ': ' . htmlspecialchars(implode(', ', $dropped), ENT_QUOTES, 'UTF-8');
        }
        return $this->ajaxSuccessWithFreshToken($msg, [
            'switcher' => [
                'on' => \app\common\util\ThemeSwitcher::enabled($site) ? '1' : '0',
                'show' => \app\common\util\ThemeSwitcher::show($site),
                'pos' => \app\common\util\ThemeSwitcher::position($site),
                'themes' => \app\common\util\ThemeSwitcher::subset($site),
            ],
            // the page's revision from now on (its next save is checked against it)
            'rev' => \app\common\util\ThemeSwitcher::revision($site),
            'dropped' => $dropped,
            'state' => self::switcherState($site),
            'label' => lang('admin/ux/system/switcher_status_' . self::switcherState($site)),
            'status' => self::switcherStatusText($site),
        ]);
    }

    /** 'off' or the display mode ('always' | 'header' | 'end'). */
    protected static function switcherState(array $site)
    {
        return \app\common\util\ThemeSwitcher::enabled($site) ? \app\common\util\ThemeSwitcher::show($site) : 'off';
    }

    /** "Visitor theme switcher: On, always visible" (Site identity status line, Themes page card). */
    protected static function switcherStatusText(array $site)
    {
        return lang('admin/ux/system/switcher_status', [lang('admin/ux/system/switcher_status_' . self::switcherState($site))]);
    }

    /** Static HTML pages are rendered once for everybody and cannot follow a visitor's choice. */
    protected static function switcherStatic()
    {
        foreach ((array)(config('maccms')['view'] ?? []) as $viewMode) {
            if ((string)$viewMode === '2') {
                return true;
            }
        }
        return false;
    }

    public function configurl()
    {
        if (Request()->isPost()) {
            $config = input();

            $validate = \think\Loader::validate('Token');
            if(!$validate->check($config)){
                return $this->ajaxErrorWithFreshToken($validate->getError());
            }
            unset($config['__token__']);

            foreach (['view', 'path', 'rewrite'] as $section) {
                if (!isset($config[$section]) || !is_array($config[$section])) {
                    return $this->ajaxErrorWithFreshToken(lang('admin/urlsetup/invalid_form'));
                }
                foreach ($config[$section] as $value) {
                    if (!is_scalar($value)) { return $this->ajaxErrorWithFreshToken(lang('admin/urlsetup/invalid_form')); }
                }
            }
            if (array_key_exists('canonical_scheme', $config['rewrite'])
                && (!is_string($config['rewrite']['canonical_scheme'])
                    || !in_array($config['rewrite']['canonical_scheme'], ['auto', 'http', 'https'], true))) {
                return $this->ajaxErrorWithFreshToken(lang('admin/urlsetup/canonical_scheme_invalid'));
            }
            try {
                $parsedRoutes = \app\common\util\UrlSettings::parseRoutes($config['rewrite']['route'] ?? null);
            } catch (\InvalidArgumentException $error) {
                return $this->ajaxErrorWithFreshToken(lang($error->getMessage()) . ($error->getCode() ? ' (' . $error->getCode() . ')' : ''));
            }


            $config_old = config('maccms');
            foreach (['view', 'path', 'rewrite'] as $section) {
                $config_new[$section] = array_merge($config_old[$section], $config[$section]);
            }

            // Write the routing rules file.
            $route = [];
            $route['__pattern__'] = [

                'id'=>'[\s\S]*?',
                'ids'=>'[\s\S]*?',
                'wd' => '[\s\S]*',
                'en'=>'[\s\S]*?',
                'state' => '[\s\S]*?',
                'area' => '[\s\S]*',
                'year'=>'[\s\S]*?',
                'lang' => '[\s\S]*?',
                'letter'=>'[\s\S]*?',
                'actor' => '[\s\S]*?',
                'director' => '[\s\S]*?',
                'tag' => '[\s\S]*?',
                'class' => '[\s\S]*?',
                'order'=>'[\s\S]*?',
                'by'=>'[\s\S]*?',
                'file'=>'[\s\S]*?',
                'name'=>'[\s\S]*?',
                'url'=>'[\s\S]*?',
                'type'=>'[\s\S]*?',
                'sex' => '[\s\S]*?',
                'version' => '[\s\S]*?',
                'blood' => '[\s\S]*?',
                'starsign' => '[\s\S]*?',
                'page'=>'\d+',
                'ajax'=>'\d+',
                'tid'=>'\d+',
                'mid'=>'\d+',
                'rid'=>'\d+',
                'pid'=>'\d+',
                'sid'=>'\d+',
                'nid'=>'\d+',
                'uid'=>'\d+',
                'level'=>'\d+',
                'score'=>'\d+',
                'limit'=>'\d+',
            ];
            $route = array_merge($route, $parsedRoutes);

            $backup = \app\common\util\UrlSettings::backupFiles(
                [APP_PATH . 'route.php', APP_PATH . 'extra/maccms.php'],
                RUNTIME_PATH . 'url-settings-backups/'
            );
            if ($backup === false) {
                return $this->ajaxErrorWithFreshToken(lang('admin/urlsetup/backup_failed'));
            }
            $res = mac_arr2file(APP_PATH . 'route.php', $route);
            if ($res === false || !\app\common\util\UrlSettings::matchesConfigFile(APP_PATH . 'route.php', $route)) {
                if (!\app\common\util\UrlSettings::restoreBackup($backup)) {
                    return $this->ajaxErrorWithFreshToken(lang('admin/urlsetup/rollback_failed'));
                }
                return $this->ajaxErrorWithFreshToken(lang('write_err_route'));
            }

            // Save the extended application configuration.
            $config_new = array_merge($config_old, $config_new);
            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false || !\app\common\util\UrlSettings::matchesConfigFile(APP_PATH . 'extra/maccms.php', $config_new)) {
                if (!\app\common\util\UrlSettings::restoreBackup($backup)) {
                    return $this->ajaxErrorWithFreshToken(lang('admin/urlsetup/rollback_failed'));
                }
                return $this->ajaxErrorWithFreshToken(lang('write_err_config'));
            }
            if ($config_new['view'] != $config_old['view'] || $config_new['path'] != $config_old['path'] || $config_new['rewrite'] != $config_old['rewrite']) {
                // Rendered listings and sitemaps must use the newly preferred URLs.
                Cache::tag('vod')->clear();
            }
            return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
        }

        $this->assign('config', config('maccms'));
        // E.4: an indexed site (older than 7 days) gets a warning above the presets and a confirm before applying.
        $this->assign('seo_url_warn', self::seoSiteAgeDays() > 7);
        $this->assign('seo_js', self::seoJs());
        $this->assign('title', lang('admin/system/configurl/title'));
        return $this->fetch('admin@system/configurl');
    }

    public function configuser()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = \think\Loader::validate('Token');
            if(!$validate->check($config)){
                return $this->error($validate->getError());
            }
            unset($config['__token__']);

            $config_new['user'] = $config['user'];
            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $this->assign('config', config('maccms'));
        $this->assign('title', lang('admin/system/configuser/title'));
        return $this->fetch('admin@system/configuser');
    }

    /**
     * The stored form of an upload storage mode: 'local', 'remote', or the key of an upload extension that exists and
     * is not blocked (the class name, e.g. 'Ftp', as the settings form offers it). '' for anything else.
     */
    private static function uploadModeKey($mode)
    {
        if (!is_scalar($mode)) {
            return '';
        }
        $mode = trim((string)$mode);
        $lower = strtolower($mode);
        if ($lower === 'local' || $lower === 'remote') {
            return $lower;
        }
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,31}\z/', $mode) || GlobalModules::blocked('upload', $lower)) {
            return '';
        }
        foreach (glob(APP_PATH . 'common' . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $class = basename($file, '.php');
            if (strtolower($class) === $lower && preg_match('/\A[A-Za-z0-9_]+\z/', $class)
                && !GlobalModules::blocked('upload', $class) && class_exists('app\\common\\extend\\upload\\' . $class)) {
                return $class;
            }
        }
        return '';
    }

    public function configupload()
    {
        if (Request()->isPost()){
            $config = input('','','htmlentities');

            $validate = \think\Loader::validate('Token');
            if(!$validate->check($config)){
                return $this->error($validate->getError());
            }
            unset($config['__token__']);

            if (!isset($config['upload']) || !is_array($config['upload'])
                || (isset($config['upload']['mode']) && GlobalModules::blocked('upload', $config['upload']['mode']))) {
                return $this->error(lang('admin/module_removed'));
            }
            // Wave 4 note: the storage mode is one plain value the form offers: local, remote, or the key of an
            // upload extension that exists and is not blocked (e.g. Ftp). Anything else changes nothing.
            if (array_key_exists('mode', $config['upload'])) {
                $mode = self::uploadModeKey($config['upload']['mode']);
                if ($mode === '') {
                    return $this->error(lang('param_err'));
                }
                $config['upload']['mode'] = $mode;
            }
            $config_old = config('maccms');
            // F22: merge the posted keys into the stored array; keys the form does not show (api.ftp, ...) are kept.
            $config_new['upload'] = self::mergeKeep((array)($config_old['upload'] ?? []), $config['upload']);
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $this->assign('config', config('maccms'));

        $extends = GlobalModules::extendsList('upload');
        $this->assign('extends',$extends);

        $this->assign('title', lang('admin/system/configupload/title'));
        return $this->fetch('admin@system/configupload');
    }

    public function configcomment()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = \think\Loader::validate('Token');
            if(!$validate->check($config)){
                return $this->error($validate->getError());
            }
            unset($config['__token__']);

            $config_new['gbook'] = $config['gbook'];
            $config_new['comment'] = $config['comment'];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $this->assign('config', config('maccms'));
        $this->assign('title', lang('admin/system/configcomment/title'));
        return $this->fetch('admin@system/configcomment');
    }

    public function configweixin()
    {
        // WeChat official-account bot: China-only, not part of the global distribution.
        return $this->error(lang('admin/module_removed'));
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = \think\Loader::validate('Token');
            if(!$validate->check($config)){
                return $this->error($validate->getError());
            }
            unset($config['__token__']);

            $config_new['weixin'] = $config['weixin'];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $this->assign('config', config('maccms'));
        $this->assign('title', lang('admin/system/configweixin/title'));
        return $this->fetch('admin@system/configweixin');
    }

    public function configpay()
    {
        if ($this->request->isPost()) {
            $post = input('post.', [], '');
            if (!\think\Loader::validate('Token')->check($post)) { return $this->ajaxErrorWithFreshToken(lang('illegal_request')); }
            if (!isset($post['pay']) || !is_array($post['pay'])) { return $this->ajaxErrorWithFreshToken(lang('admin/paysetup/invalid')); }
            // China-only gateways cannot be configured; their stored values are kept untouched.
            $post['pay'] = GlobalModules::stripBlocked('pay', $post['pay']);
            $lock = @fopen(RUNTIME_PATH . 'pay-settings-save.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) {
                if ($lock) { fclose($lock); }
                return $this->ajaxErrorWithFreshToken(lang('admin/paysetup/backup_failed'));
            }
            try {
                $path = APP_PATH . 'extra/maccms.php';
                if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
                $current = include $path;
                if (!is_array($current)) { return $this->ajaxErrorWithFreshToken(lang('admin/paysetup/invalid')); }
                try {
                    $next = $current;
                    $next['pay'] = \app\common\util\PaySettings::merge((array)($current['pay'] ?? []), $post['pay']);
                } catch (\InvalidArgumentException $error) { return $this->ajaxErrorWithFreshToken(lang('admin/paysetup/invalid')); }
                $backup = \app\common\util\UrlSettings::backupFiles([$path], RUNTIME_PATH . 'pay-settings-backups/');
                if ($backup === false) { return $this->ajaxErrorWithFreshToken(lang('admin/paysetup/backup_failed')); }
                $written = mac_arr2file($path, $next);
                if ($written === false || !\app\common\util\UrlSettings::matchesConfigFile($path, $next)) {
                    $restored = \app\common\util\UrlSettings::restoreBackup($backup);
                    if (!$restored || @file_get_contents($path) !== $backup['files'][$path]) { return $this->ajaxErrorWithFreshToken(lang('admin/paysetup/rollback_failed')); }
                    return $this->ajaxErrorWithFreshToken(lang('save_err'));
                }
                if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
                \think\Config::set('maccms', $next);
                $GLOBALS['config'] = $next;
                return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        if (!$this->request->isGet()) { return $this->ajaxErrorWithFreshToken(lang('illegal_request')); }
        $callbacks = [];
        foreach (\app\common\util\PaySettings::PROVIDERS as $provider) {
            $callbacks[$provider] = \app\common\util\PaySettings::callbackPreview($_SERVER['HTTP_HOST'] ?? '', $GLOBALS['http_type'] ?? '', $provider);
        }
        $this->assign('pay_callbacks', $callbacks);
        $this->assign('pay_available', \app\common\util\PaySettings::availability());
        $this->assign('http_type', $GLOBALS['http_type']);
        $this->assign('config', config('maccms'));
        $extends = GlobalModules::extendsList('pay');
        $this->assign('extends', $extends);
        $this->assign('title', lang('admin/system/configpay/title'));
        return $this->fetch('admin@system/configpay');
    }
    public function configconnect()
    {
        // QQ / WeChat sign-in: China-only, not part of the global distribution.
        return $this->error(lang('admin/module_removed'));
        if ($this->request->isPost()) {
            $post = input('post.', [], '');
            $validate = \think\Loader::validate('Token');
            if (!$validate->check($post)) { return $this->ajaxErrorWithFreshToken(lang('illegal_request')); }
            if (!isset($post['connect']) || !is_array($post['connect'])) { return $this->ajaxErrorWithFreshToken(lang('admin/connect/invalid_config')); }
            $lock = @fopen(RUNTIME_PATH . 'connect-settings-save.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) {
                if ($lock) { fclose($lock); }
                return $this->ajaxErrorWithFreshToken(lang('admin/connect/backup_failed'));
            }
            try {
                $path=APP_PATH.'extra/maccms.php';
                if (function_exists('opcache_invalidate')) { @opcache_invalidate($path,true); }
                $current=include $path;
                if (!is_array($current)) { return $this->ajaxErrorWithFreshToken(lang('admin/connect/invalid_config')); }
                try { $next=$current; $next['connect']=\app\common\util\ConnectSettings::merge((array)($current['connect'] ?? []), $post['connect']); }
                catch (\InvalidArgumentException $error) { return $this->ajaxErrorWithFreshToken(lang('admin/connect/invalid_config')); }
                catch (\RuntimeException $error) { return $this->ajaxErrorWithFreshToken(lang(\app\common\util\ConnectSettings::errorKey($error))); }
                $backup=\app\common\util\UrlSettings::backupFiles([$path],RUNTIME_PATH.'connect-settings-backups/');
                if ($backup===false) { return $this->ajaxErrorWithFreshToken(lang('admin/connect/backup_failed')); }
                $written=mac_arr2file($path,$next);
                if ($written===false || !\app\common\util\UrlSettings::matchesConfigFile($path,$next)) {
                    $restored=\app\common\util\UrlSettings::restoreBackup($backup);
                    if (!$restored || @file_get_contents($path)!==$backup['files'][$path]) { return $this->ajaxErrorWithFreshToken(lang('admin/connect/rollback_failed')); }
                    return $this->ajaxErrorWithFreshToken(lang('save_err'));
                }
                if (function_exists('opcache_invalidate')) { @opcache_invalidate($path,true); }
                return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
            } finally { flock($lock,LOCK_UN); fclose($lock); }
        }
        if (!$this->request->isGet()) { return $this->ajaxErrorWithFreshToken(lang('illegal_request')); }
        $callbacks=['qq'=>'','weixin'=>'']; $callbackError='';
        foreach (array_keys($callbacks) as $provider) {
            try { $callbacks[$provider]=\app\common\util\ConnectSettings::callback($provider); }
            catch (\RuntimeException $error) { $callbackError=lang('admin/connect/invalid_callback'); }
        }
        $this->assign('config',config('maccms'));
        $this->assign('connect_callbacks',$callbacks);
        $this->assign('connect_callback_error',$callbackError);
        $this->assign('connect_available',\app\common\util\ConnectSettings::available());
        $this->assign('title',lang('admin/system/configconnect/title'));
        return $this->fetch('admin@system/configconnect');
    }
    public function configemail()
    {
        if (Request()->isPost()) {
            // Keep credentials and editable HTML intact; templates escape them on output.
            $post = input('post.', [], '');
            $validate = \think\Loader::validate('Token');
            if (!$validate->check($post)) {
                return $this->ajaxErrorWithFreshToken($validate->getError());
            }
            if (!isset($post['email']) || !is_array($post['email'])) {
                return $this->ajaxErrorWithFreshToken(lang('admin/system/configemail/invalid_settings'));
            }
            $lock = @fopen(RUNTIME_PATH . 'email-settings-save.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) {
                if ($lock) { fclose($lock); }
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            $path = APP_PATH . 'extra/maccms.php';
            $message = 'save_err';
            $ok = false;
            try {
                // Read under the lock so unrelated settings and custom providers survive.
                $next = include $path;
                if (!is_array($next) || !isset($next['email']) || !is_array($next['email'])) {
                    throw new \RuntimeException();
                }
                // F21: partial settings save. While no SMTP host is set, the SMTP block is not required
                // (only the fields that are filled in are checked), so the sender name alone can be saved.
                $postedEmail = $post['email'];
                $hostAfter = array_key_exists('host', (array)($postedEmail['phpmailer'] ?? []))
                    ? $postedEmail['phpmailer']['host'] : ($next['email']['phpmailer']['host'] ?? '');
                $partialType = null;
                if (is_scalar($hostAfter) && trim((string)$hostAfter) === '' && isset($postedEmail['type']) && is_string($postedEmail['type'])) {
                    $partialType = trim($postedEmail['type']);
                    if ($partialType !== '' && !preg_match('/^[a-z][a-z0-9_]{0,63}$/iD', $partialType)) {
                        throw new \InvalidArgumentException();
                    }
                    $postedEmail['type'] = '';
                }
                $next['email'] = \app\common\util\EmailSettings::merge($next['email'], $postedEmail);
                if ($partialType !== null) {
                    $next['email']['type'] = $partialType;
                }
                $providers = mac_extends_list('email');
                $installed = array_change_key_case($providers['ext_list'], CASE_LOWER);
                if ($next['email']['type'] !== '' && !isset($installed[strtolower($next['email']['type'])])) {
                    throw new \InvalidArgumentException();
                }
                foreach (array_keys($providers['ext_list']) as $name) {
                    if (strcasecmp($name, $next['email']['type']) === 0) { $next['email']['type'] = $name; break; }
                }
                $backup = \app\common\util\UrlSettings::backupFiles([$path], RUNTIME_PATH . 'email-settings-backups/');
                if ($backup === false) {
                    $message = 'admin/urlsetup/backup_failed';
                } else {
                    try { $written = mac_arr2file($path, $next); }
                    catch (\Throwable $error) { $written = false; }
                    $ok = $written !== false && \app\common\util\UrlSettings::matchesConfigFile($path, $next);
                    if (!$ok && !\app\common\util\UrlSettings::restoreBackup($backup)) {
                        $message = 'admin/urlsetup/rollback_failed';
                    }
                    if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
                }
            } catch (\InvalidArgumentException $error) {
                $message = 'admin/system/configemail/invalid_settings';
            } catch (\Throwable $error) {
                $message = 'save_err';
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            return $ok ? $this->ajaxSuccessWithFreshToken(lang('save_ok')) : $this->ajaxErrorWithFreshToken(lang($message));
        }
        $settings = config('maccms');
        // F21: the shipped default test address (a China webmail box) is shown as empty.
        if (isset($settings['email']['test']) && strtolower(trim((string)$settings['email']['test'])) === 'test@qq.com') {
            $settings['email']['test'] = '';
        }
        // Extension panels render immediately and need their assigned values first.
        $this->assign('config', $settings);
        $extends = mac_extends_list('email');
        foreach (array_keys($extends['ext_list']) as $name) {
            if (strcasecmp($name, $settings['email']['type']) === 0) { $settings['email']['type'] = $name; break; }
        }
        $this->assign('config', $settings);
        $this->assign('extends', $extends);
        $this->assign('title', self::uxTitle('admin/ux/menu/email', 'admin/system/configemail/title'));
        return $this->fetch('admin@system/configemail');
    }

    public function configsms()
    {
        // Every bundled SMS provider (Aliyun, Tencent Cloud) is China-only; e-mail verification remains.
        return $this->error(lang('admin/module_removed'));
        if (Request()->isPost()) {
            $post = input('post.', [], '');
            $validate = \think\Loader::validate('Token');
            if (!$validate->check($post)) { return $this->ajaxErrorWithFreshToken($validate->getError()); }
            if (!isset($post['sms']) || !is_array($post['sms'])) { return $this->ajaxErrorWithFreshToken(lang('admin/smssetup/invalid_settings')); }
            $lock = @fopen(RUNTIME_PATH . 'sms-settings-save.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) {
                if ($lock) { fclose($lock); }
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            $path = APP_PATH . 'extra/maccms.php';
            $ok = false;
            $message = 'save_err';
            try {
                if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
                $next = include $path;
                if (!is_array($next) || !isset($next['sms']) || !is_array($next['sms'])) { throw new \RuntimeException(); }
                $next['sms'] = \app\common\util\SmsSettings::merge($next['sms'], $post['sms']);
                $this->assign('config', $next);
                $providers = mac_extends_list('sms');
                $installed = array_change_key_case($providers['ext_list'], CASE_LOWER);
                if ($next['sms']['type'] !== '' && !isset($installed[strtolower($next['sms']['type'])])) { throw new \InvalidArgumentException('admin/smssetup/invalid_settings'); }
                foreach (array_keys($providers['ext_list']) as $provider) {
                    if (strcasecmp($provider, $next['sms']['type']) === 0) { $next['sms']['type'] = $provider; break; }
                }
                $backup = \app\common\util\UrlSettings::backupFiles([$path], RUNTIME_PATH . 'sms-settings-backups/');
                if ($backup === false) { $message = 'admin/urlsetup/backup_failed'; }
                else {
                    try { $written = mac_arr2file($path, $next); }
                    catch (\Throwable $error) { $written = false; }
                    $ok = $written !== false && \app\common\util\UrlSettings::matchesConfigFile($path, $next);
                    if (!$ok) {
                        $restored = \app\common\util\UrlSettings::restoreBackup($backup);
                        foreach ($backup['files'] as $original => $bytes) { $restored = @file_get_contents($original) === $bytes && $restored; }
                        if (!$restored) { $message = 'admin/urlsetup/rollback_failed'; }
                    }
                    if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
                }
            } catch (\InvalidArgumentException $error) { $message = $error->getMessage(); }
            catch (\Throwable $error) { $message = 'save_err'; }
            finally { flock($lock, LOCK_UN); fclose($lock); }
            return $ok ? $this->ajaxSuccessWithFreshToken(lang('save_ok')) : $this->ajaxErrorWithFreshToken(lang($message));
        }
        if (!Request()->isGet()) { return $this->ajaxErrorWithFreshToken(lang('illegal_request')); }
        $settings = config('maccms');
        $this->assign('config', $settings);
        $extends = mac_extends_list('sms');
        foreach (array_keys($extends['ext_list']) as $provider) {
            if (strcasecmp($provider, $settings['sms']['type']) === 0) { $settings['sms']['type'] = $provider; break; }
        }
        $this->assign('config', $settings);
        $this->assign('extends', $extends);
        $this->assign('sms_available', \app\common\util\SmsSettings::available());
        $this->assign('title', lang('admin/system/configsms/title'));
        return $this->fetch('admin@system/configsms');
    }

    public function configapi()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = \think\Loader::validate('Token');
            if(!$validate->check($config)){
                return $this->error($validate->getError());
            }
            unset($config['__token__']);

            $config_new['api'] = $config['api'];

            $config_new['api']['vod']['auth'] = mac_replace_text($config_new['api']['vod']['auth'], 2);
            $config_new['api']['art']['auth'] = mac_replace_text($config_new['api']['art']['auth'], 2);
            $config_new['api']['actor']['auth'] = mac_replace_text($config_new['api']['actor']['auth'], 2);

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $this->assign('config', config('maccms'));
        $this->assign('title', lang('admin/system/configapi/title'));
        return $this->fetch('admin@system/configapi');
    }

    public function configinterface()
    {
        if ($this->request->isPost()) {
            $post = input('post.', [], '');
            if (!\think\Loader::validate('Token')->check($post)) { return $this->ajaxErrorWithFreshToken(lang('illegal_request')); }
            if (!isset($post['interface']) || !is_array($post['interface'])) { return $this->ajaxErrorWithFreshToken(lang('admin/interface/error_invalid')); }
            $lock = @fopen(RUNTIME_PATH . 'interface-settings-save.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) {
                if ($lock) { fclose($lock); }
                return $this->ajaxErrorWithFreshToken(lang('admin/interface/backup_failed'));
            }
            try {
                $path = APP_PATH . 'extra/maccms.php';
                if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
                $current = include $path;
                if (!is_array($current)) { return $this->ajaxErrorWithFreshToken(lang('admin/interface/error_invalid')); }
                $types = (array)model('Type')->getCache('type_list');
                try {
                    $next = $current;
                    $next['interface'] = \app\common\util\InterfaceSettings::merge((array)($current['interface'] ?? []), $post['interface'], $types);
                } catch (\InvalidArgumentException $error) {
                    return $this->ajaxErrorWithFreshToken($this->interfaceIssueText($error, $post['interface']));
                }
                $backup = \app\common\util\UrlSettings::backupFiles([$path], RUNTIME_PATH . 'interface-settings-backups/');
                if ($backup === false) { return $this->ajaxErrorWithFreshToken(lang('admin/interface/backup_failed')); }
                $written = mac_arr2file($path, $next);
                if ($written === false || !\app\common\util\UrlSettings::matchesConfigFile($path, $next)) {
                    $restored = \app\common\util\UrlSettings::restoreBackup($backup);
                    if (!$restored || @file_get_contents($path) !== $backup['files'][$path]) { return $this->ajaxErrorWithFreshToken(lang('admin/interface/rollback_failed')); }
                    return $this->ajaxErrorWithFreshToken(lang('save_err'));
                }
                if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
                \think\Config::set('maccms', $next);
                $GLOBALS['config'] = $next;
                \think\Cache::rm(($current['app']['cache_flag'] ?? '') . '_interface_type');
                mac_interface_type();
                return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        if (!$this->request->isGet()) { return $this->ajaxErrorWithFreshToken(lang('illegal_request')); }
        $config = config('maccms');
        $types = (array)model('Type')->getCache('type_list');
        $maps = []; $categories = []; $warnings = [];
        foreach (\app\common\util\InterfaceSettings::MODULES as $field => $mid) {
            $maps[$field] = \app\common\util\InterfaceSettings::display($config['interface'][$field] ?? '');
            $categories[$field] = [];
            foreach ($types as $row) {
                if ((int)($row['type_mid'] ?? 0) === $mid) { $categories[$field][] = $row; }
            }
            try {
                $parsed = \app\common\util\InterfaceSettings::parse($config['interface'][$field] ?? '', $field, $types, true, false);
                foreach ($parsed['issues'] as $issue) { $warnings[] = $this->interfaceIssueText(new \InvalidArgumentException($issue), (array)($config['interface'] ?? [])); }
            } catch (\InvalidArgumentException $error) { $warnings[] = $this->interfaceIssueText($error, (array)($config['interface'] ?? [])); }
        }
        $this->assign('config', $config);
        $this->assign('interface_maps', $maps);
        $this->assign('interface_categories', $categories);
        $this->assign('interface_warnings', $warnings);
        $this->assign('title', lang('admin/system/configinterface/title'));
        return $this->fetch('admin@system/configinterface');
    }
    /**
     * FUNC r3 P3-N1: one plain sentence per mapping problem, e.g. 'Line 1 of "Video category mappings": there is no local
     * category "Action Movie". Write each line as: local category = remote name.' instead of "... [vodtype:1]".
     * $texts = the mapping texts the problem refers to (saved config on GET, posted form on save).
     */
    private function interfaceIssueText(\InvalidArgumentException $error, array $texts)
    {
        $parts = explode('|', $error->getMessage());
        $reason = in_array($parts[0], ['invalid', 'separator', 'destination', 'ambiguous', 'duplicate'], true) ? $parts[0] : 'invalid';
        $field = isset($parts[1]) ? (string)$parts[1] : '';
        $line = isset($parts[2]) ? (int)$parts[2] : 0;
        if ($field === 'pass') { return lang('admin/ux/system/iface_pass'); }
        $labels = ['vodtype' => 'vod_type', 'arttype' => 'art_type', 'actortype' => 'actor_type', 'websitetype' => 'website_type'];
        if (!isset($labels[$field])) { return lang('admin/interface/error_' . $reason); }
        $label = lang('admin/system/configinterface/' . $labels[$field]);
        $detail = lang('admin/interface/error_' . $reason);
        if ($reason === 'destination' && $line > 0) {
            $local = '';
            try {
                $rows = \app\common\util\InterfaceSettings::lines(isset($texts[$field]) && is_string($texts[$field]) ? $texts[$field] : '');
                if (isset($rows[$line - 1])) { $local = trim(explode('=', (string)$rows[$line - 1], 2)[0]); }
            } catch (\InvalidArgumentException $e) { $local = ''; }
            if ($local !== '') {
                $detail = lang('admin/ux/system/iface_no_category', [mb_substr(html_entity_decode($local, ENT_QUOTES, 'UTF-8'), 0, 80)]);
            }
            $detail .= ' ' . lang('admin/ux/system/iface_format');
        }
        if ($line > 0) { return lang('admin/ux/system/iface_line', [$line, $label, $detail]); }
        return lang('admin/ux/system/iface_field', [$label, $detail]);
    }

    public function configcollect()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($config)){
                return $this->error($validate->getError());
            }
            unset($config['__token__']);

            if (!isset($config['collect']) || !is_array($config['collect'])) {
                return $this->error(lang('save_err'));
            }
            $config_old = config('maccms');
            $posted = $config['collect'];
            // N27: Role and Website tabs are not shown, so only the modules present in the form are rebuilt;
            // every other module keeps its stored values.
            foreach (['vod', 'art', 'actor', 'role', 'website', 'comment'] as $module) {
                if (!isset($posted[$module]) || !is_array($posted[$module])) {
                    continue;
                }
                // Rule checkboxes are only on the form for these modules (an unticked box sends nothing).
                foreach (in_array($module, ['vod', 'art', 'actor', 'comment'], true) ? ['inrule' => ['a'], 'uprule' => []] : [] as $rule => $default) {
                    $value = !empty($posted[$module][$rule]) ? (array)$posted[$module][$rule] : $default;
                    $posted[$module][$rule] = ',' . join(',', $value);
                }
                foreach (['namewords', 'thesaurus', 'playerwords', 'areawords', 'langwords', 'words'] as $field) {
                    if (isset($posted[$module][$field])) {
                        $posted[$module][$field] = mac_replace_text($posted[$module][$field], 2);
                    }
                }
            }
            $config_new['collect'] = self::mergeKeep((array)($config_old['collect'] ?? []), $posted);
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }


        $this->assign('config', config('maccms'));
        $this->assign('title', self::uxTitle('admin/ux/menu/import_options', 'admin/system/configcollect/title'));
        return $this->fetch('admin@system/configcollect');
    }

    /**
     * Read the parameter block of static/js/playerconfig.js (F20). Both marker spellings are accepted
     * ("//Parameter start" from MacCMS and "// Start of parameters" from the translated file).
     * Returns null when the file or the block cannot be read, else
     * ['file' => path, 'text' => whole file, 'start' / 'end' => offsets of the block, 'play' => decoded array].
     */
    protected static function playerConfigBlock()
    {
        $path = './static/js/playerconfig.js';
        if (!is_file($path)) {
            $path .= '.bak';
        }
        $fc = is_file($path) ? @file_get_contents($path) : false;
        if (!is_string($fc) || $fc === '') {
            return null;
        }
        foreach ([['//Parameter start', '//Parameter end'], ['// Start of parameters', '// End of parameters']] as $marks) {
            $start = strpos($fc, $marks[0]);
            if ($start === false) {
                continue;
            }
            $start += strlen($marks[0]);
            $end = strpos($fc, $marks[1], $start);
            if ($end === false) {
                continue;
            }
            $body = substr($fc, $start, $end - $start);
            $json = trim($body);
            if (strncmp($json, 'MacPlayerConfig=', 16) !== 0) {
                continue;
            }
            $json = rtrim(substr($json, 16), "; \t\r\n");
            $play = json_decode($json, true);
            if (!is_array($play)) {
                continue;
            }
            return ['file' => $path, 'text' => $fc, 'start' => $start, 'end' => $end, 'play' => $play];
        }
        return null;
    }

    public function configplay()
    {
        if (Request()->isPost()) {
            $config = input('','','htmlentities');

            $validate = \think\Loader::validate('Token');
            if(!$validate->check($config)){
                return $this->error($validate->getError());
            }
            unset($config['__token__']);

            // F20: never write anything when the player file cannot be read (the old code blanked every value).
            $block = self::playerConfigBlock();
            if ($block === null) {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/system/player_file_missing'));
            }
            $posted = isset($config['play']) && is_array($config['play']) ? $config['play'] : [];
            $config_old = config('maccms');
            // Merge: stored play keys, then what the player file holds, then the posted keys (a missing key keeps its old value).
            $play = array_merge((array)($config_old['play'] ?? []), $block['play']);
            foreach ($posted as $key => $value) {
                if (is_string($key) && preg_match('/^[a-z_]{1,32}$/', $key) && is_scalar($value)) {
                    $play[$key] = (string)$value;
                }
            }
            $config_new = $config_old;
            $config_new['play'] = $play;

            $content = "\r\n" . 'MacPlayerConfig=' . json_encode($play) . ';' . "\r\n";
            $fc = substr($block['text'], 0, $block['start']) . $content . substr($block['text'], $block['end']);
            $res = @file_put_contents('./static/js/playerconfig.js', $fc, LOCK_EX);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $block = self::playerConfigBlock();
        $play = (array)(config('maccms')['play'] ?? []);
        if ($block !== null) {
            $play = array_merge($play, $block['play']);
        }
        foreach (['width', 'height', 'widthmob', 'heightmob', 'widthpop', 'heightpop', 'second', 'prestrain', 'buffer', 'parse',
                  'autofull', 'showtop', 'showlist', 'flag', 'colors'] as $key) {
            if (!isset($play[$key]) || !is_scalar($play[$key])) {
                $play[$key] = '';
            }
        }
        $this->assign('play', $play);
        $this->assign('player_file_ok', $block !== null);
        $this->assign('title', self::uxTitle('admin/ux/menu/video_player', 'admin/system/configplay/title'));
        return $this->fetch('admin@system/configplay');
    }

    // ================================================================== SEO-ADMIN-BRIEF (item seo-admin)
    // Site identity (A.1 / A.6 / A.7, B.1), Languages (A.3), Legal & age (A.4), Search titles & descriptions (A.5).
    // Escaping contract (CONTRACT-FIELDS.md): posted values arrive HTML-encoded once (input('','','htmlentities'));
    // seoIn() decodes them once, SeoSettings::validate() normalises them and returns the value to store in maccms.php
    // (text HTML-encoded once again); data files (legal/*.json, seo/custom.php) get decoded text (CF07).

    /** Strings for static/js/admin_seo.js (JSON in a script tag; plain text, escaped by JSON_HEX_*). */
    protected static function seoJs()
    {
        $out = [];
        foreach (['counter', 'icon_ready', 'icon_removed', 'share_ready', 'copied', 'check_checking', 'hide_site_confirm',
                     'hide_site_confirm_title', 'hide_site_confirm_ok', 'legal_restore_confirm', 'legal_restore', 'legal_restored',
                     'legal_tpl_chip', 'legal_own_chip', 'robots_block_confirm', 'robots_block_title', 'indexnow_newkey_confirm',
                     'indexnow_newkey', 'url_change_confirm', 'url_change_ok', 'icon_type_err', 'chip_ok', 'chip_warn',
                     'check_progress', 'indexnow_key_ok', 'indexnow_key_missing', 'custom_remove'] as $k) {
            $out[$k] = lang('admin/ux/seo/' . $k);
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** Days since the site started: the install lock file, else the first video (primary-key lookup only). */
    protected static function seoSiteAgeDays()
    {
        $start = 0;
        foreach ([APP_PATH . 'data/install/install.lock', APP_PATH . 'install.lock'] as $lock) {
            if (is_file($lock)) { $start = (int)filemtime($lock); break; }
        }
        if ($start <= 0) {
            try { $start = (int)Db::name('vod')->order('vod_id asc')->value('vod_time_add'); } catch (\Throwable $e) { $start = 0; }
        }
        return $start > 0 ? (int)floor((time() - $start) / 86400) : 0;
    }

    /** Decode a posted value once. */
    private static function seoIn($value)
    {
        return is_scalar($value) ? html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    private static function seoLen($text)
    {
        return function_exists('mb_strlen') ? mb_strlen((string)$text, 'UTF-8') : strlen((string)$text);
    }

    /** Code fields compare without line-end / outer-space differences (a textarea posts CRLF). */
    private static function seoCodeNorm($text)
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string)$text));
    }

    /** One plain sentence for a failed SeoSettings::validate() of the field labelled $labelKey. */
    private static function seoFieldError(array $result, $labelKey, array $range = [])
    {
        $label = lang($labelKey);
        $error = (string)($result['error'] ?? '');
        if ($error === 'too_long') { return lang('admin/ux/seo/err_too_long', [$label]); }
        if (($error === 'range' || $error === 'number') && count($range) === 2) {
            return lang('admin/ux/seo/err_range', [$label, $range[0], $range[1]]);
        }
        // Fields whose format a beginner cannot guess get a sentence that says what to type.
        $hints = ['admin/ux/seo/social' => 'err_social', 'admin/ux/seo/theme_color' => 'err_color', 'admin/ux/seo/age_leave' => 'err_leave'];
        if (isset($hints[$labelKey])) { return lang('admin/ux/seo/' . $hints[$labelKey]); }
        return lang('admin/ux/seo/err_format', [$label]);
    }

    /** "2M" / "512K" / "1G" (php.ini size) -> bytes; 0 for empty or unlimited. */
    private static function seoIniBytes($value)
    {
        $value = trim((string)$value);
        if (!preg_match('/^(\d+)\s*([KMG]?)/i', $value, $m)) { return 0; }
        $n = (int)$m[1];
        $unit = strtoupper($m[2]);
        return $unit === 'G' ? $n * 1073741824 : ($unit === 'M' ? $n * 1048576 : ($unit === 'K' ? $n * 1024 : $n));
    }

    /** The smaller of upload_max_filesize and post_max_size, for people ("2 MB"). */
    private static function seoServerLimit()
    {
        $sizes = array_filter([self::seoIniBytes((string)ini_get('upload_max_filesize')), self::seoIniBytes((string)ini_get('post_max_size'))]);
        $bytes = $sizes ? min($sizes) : 0;
        if ($bytes <= 0) { return '?'; }
        if ($bytes >= 1048576) { return rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . ' MB'; }
        return max(1, (int)round($bytes / 1024)) . ' KB';
    }

    /** Forget the cached checklist after any SEO / site save (A.5.4: cached 60 s per admin). */
    protected function seoCheckForget()
    {
        Cache::rm('seo_check_' . (int)($this->_admin['admin_id'] ?? 0));
    }

    /** Write maccms.php with $next (the whole settings array); false on failure. */
    protected static function seoWriteConfig(array $next)
    {
        $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $next);
        if ($res === false) {
            return false;
        }
        if (function_exists('opcache_invalidate')) { @opcache_invalidate(APP_PATH . 'extra/maccms.php', true); }
        \think\Config::set('maccms', $next);
        $GLOBALS['config'] = $next;
        return true;
    }

    /**
     * Site identity save (A.1, A.6, A.7, A.10 "Save semantics", B.1 steps 6-7). $site = the posted site array after the
     * theme switcher save. Returns ['site' => final site array, 'notice' => '' | icon_root_foreign | icon_root_ro] or an
     * error sentence.
     */
    protected function seoSiteSave(array $site, array $switcherDropped)
    {
        $stored = (array)(config('maccms')['site'] ?? []);
        // SEO-fix (R-3 F1): keys of the Languages / Legal & age pages (own rights system/configlang, system/configlegal)
        // and the two recomputed icon keys are never taken from this form; keepSiteKeys() below puts the stored values back.
        foreach (['lang_default', 'lang_offered', 'lang_indexed', 'lang_home', 'legal_pages', 'legal_operator', 'legal_dmca_agent',
                     'legal_dmca_address', 'legal_custodian', 'legal_custodian_address', 'age_gate', 'age_gate_days', 'age_gate_leave',
                     'cookie_notice', 'site_icon_root_md5', 'site_manifest'] as $foreign) {
            unset($site[$foreign]);
        }

        // A.10 role rule: site_tj / site_head_code / site_ga4 change only with the right system/code (admin 1 always).
        $canCode = $this->check_auth('system', 'code');
        foreach (['site_tj', 'site_head_code', 'site_ga4'] as $key) {
            $has = array_key_exists($key, $site);
            $posted = $has ? self::seoIn($site[$key]) : '';
            if (!$canCode) {
                if ($has && self::seoCodeNorm($posted) !== self::seoCodeNorm($stored[$key] ?? '')) {
                    return lang('admin/ux/seo/code_right');
                }
                if (array_key_exists($key, $stored)) { $site[$key] = $stored[$key]; } else { unset($site[$key]); }
                continue;
            }
            if (!$has) { continue; }
            if ($key === 'site_tj') {
                // Decoded once and printed raw by the 18 footers (as before).
                $site[$key] = str_replace("\0", '', $posted);
                continue;
            }
            $result = \app\common\util\SeoSettings::validate('site.' . $key, $posted);
            if (!$result['ok']) {
                return $key === 'site_ga4' ? lang('admin/ux/seo/ga4_err') : self::seoFieldError($result, 'admin/ux/seo/head_code');
            }
            $site[$key] = $result['store'];
        }

        // Existing text fields: validated on the decoded value, stored as posted (encoded once by the form).
        if (array_key_exists('site_name', $site)) {
            $name = trim(preg_replace('/\s+/u', ' ', self::seoIn($site['site_name'])));
            $n = self::seoLen($name);
            if ($n < 2 || $n > 40) {
                return lang('admin/ux/seo/err_length', [lang('admin/ux/seo/site_name'), 2, 40]);
            }
        }
        if (array_key_exists('site_description', $site) && self::seoLen(trim(self::seoIn($site['site_description']))) > 300) {
            return lang('admin/ux/seo/err_too_long', [lang('admin/ux/seo/home_desc')]);
        }
        if (array_key_exists('site_email', $site)) {
            $email = trim(self::seoIn($site['site_email']));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return lang('admin/ux/seo/err_email');
            }
        }
        if (array_key_exists('site_url', $site)) {
            $url = trim(self::seoIn($site['site_url']));
            $clean = rtrim(preg_replace('~^https?://~i', '', $url), '/');
            if ($clean === '' || preg_match('~[\s<>"\'\\\\]~', $clean)) {
                return lang('admin/ux/seo/err_domain');
            }
            // SEO-fix F1: store only a host that the canonical links, sitemaps and robots.txt accept (UrlSettings).
            // "javhub.com/videos" was saved before and then silently emptied every sitemap and canonical link.
            if (\app\common\util\UrlSettings::canonicalUrl($clean, '/', 'https://') === '') {
                $cut = strcspn($clean, '/?#');
                if ($cut > 0 && $cut < strlen($clean)
                    && \app\common\util\UrlSettings::canonicalUrl(substr($clean, 0, $cut), '/', 'https://') !== '') {
                    return lang('admin/ux/seo/err_domain_path', [substr($clean, $cut)]);
                }
                return lang('admin/ux/seo/err_domain');
            }
            if ($clean !== $url) {
                $site['site_url'] = htmlentities($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        // New keys (A.1, A.6, A.7): SeoSettings rules.
        $labels = [
            'site_home_title' => 'admin/ux/seo/home_title', 'site_theme_color' => 'admin/ux/seo/theme_color',
            'site_social' => 'admin/ux/seo/social', 'site_icon_mode' => 'admin/ux/seo/icon_title', 'site_icon' => 'admin/ux/seo/icon_title',
            'site_share_image' => 'admin/ux/seo/share_image', 'site_share_w' => 'admin/ux/seo/share_image',
            'site_share_h' => 'admin/ux/seo/share_image', 'site_code_consent' => 'admin/ux/seo/code_consent',
        ];
        foreach ($labels as $key => $labelKey) {
            if (!array_key_exists($key, $site)) { continue; }
            $result = \app\common\util\SeoSettings::validate('site.' . $key, self::seoIn($site[$key]));
            if (!$result['ok']) {
                return self::seoFieldError($result, $labelKey);
            }
            $site[$key] = $result['store'];
        }
        if (($site['site_share_image'] ?? '') === '') {
            $site['site_share_w'] = 0;
            $site['site_share_h'] = 0;
        }
        $mode = (string)($site['site_icon_mode'] ?? ($stored['site_icon_mode'] ?? 'default'));
        $iconDir = (string)($site['site_icon'] ?? ($stored['site_icon'] ?? ''));
        if ($mode === 'custom' && \app\common\util\SiteIcons::active(['site_icon_mode' => 'custom', 'site_icon' => $iconDir])['set'] === 'default') {
            return lang('admin/ux/seo/icon_need_upload');
        }

        // CF06: every stored site key the form does not post survives (site_keywords, site_waplogo off Ember, lang_*, legal_* ...).
        $site = \app\common\util\SeoSettings::keepSiteKeys($site, $stored, $switcherDropped);

        // B.1 step 7: manifest of the active icon set; step 6: root /favicon.ico (only our own or the shipped file).
        $manifest = \app\common\util\SiteIcons::writeManifest($site, [(string)($stored['site_manifest'] ?? '')]);
        if ($manifest['ok']) {
            $site['site_manifest'] = $manifest['path'];
        } elseif (isset($stored['site_manifest'])) {
            $site['site_manifest'] = $stored['site_manifest'];
        }
        $root = \app\common\util\SiteIcons::syncRoot($mode, $iconDir, (string)($stored['site_icon_root_md5'] ?? ''));
        if ($root['md5'] !== '') {
            $site['site_icon_root_md5'] = $root['md5'];
        } elseif (isset($stored['site_icon_root_md5'])) {
            $site['site_icon_root_md5'] = $stored['site_icon_root_md5'];
        }
        return ['site' => $site, 'notice' => (string)$root['notice']];
    }

    /** Values for the Site identity cards (decoded plain text; the view escapes once). */
    protected function seoSiteView(array $config)
    {
        $S = '\app\common\util\SeoSettings';
        $site = (array)($config['site'] ?? []);
        $name = trim($S::decode($site['site_name'] ?? ''));
        $homeTitle = (string)$S::get('site.site_home_title');
        // A.9: the old (dead) SEO page title pre-fills an empty Home page title; it is stored only on Save.
        $prefill = false;
        if ($homeTitle === '') {
            $old = trim($S::decode($config['seo']['vod']['name'] ?? ''));
            if ($old !== '' && $old !== $name && strpos($old, '{') === false && self::seoLen($old) <= 70) {
                $homeTitle = $old;
                $prefill = true;
            }
        }
        $active = \app\common\util\SiteIcons::active();
        $base = \app\common\util\SiteIcons::base();
        $iconUrls = [];
        foreach ($active['links'] as $link) { $iconUrls[] = $base . $link[1]; }
        $customDir = (string)$S::get('site.site_icon');
        $custom = $customDir !== '' ? \app\common\util\SiteIcons::active(['site_icon_mode' => 'custom', 'site_icon' => $customDir]) : null;
        $preview = function ($set) use ($base) {
            if (!$set || $set['set'] === 'default' || $set['set'] === 'theme') {
                $d = $base . \app\common\util\SiteIcons::DEFAULT_DIR;
                return ['tab' => $d . 'icon.svg', 'phone' => $d . 'icon-180.png'];
            }
            $tab = $set['set'] === 'custom' ? $set['dir'] . 'icon-32.png' : $set['dir'] . 'icon-source.png';
            $phone = $set['set'] === 'custom' ? $set['dir'] . 'icon-180.png' : $set['dir'] . 'icon-source.png';
            return ['tab' => $base . $tab, 'phone' => $base . $phone];
        };
        $share = (string)$S::get('site.site_share_image');
        $canonical = '';
        try { $canonical = (string)\app\common\util\Sitemap::absolute($base); } catch (\Throwable $e) { $canonical = ''; }
        $themeDir = (string)($site['template_dir'] ?? '');
        $rootIco = ROOT_PATH . 'favicon.ico';
        return [
            'site_name' => $name,
            'home_title' => $homeTitle,
            'home_prefill' => $prefill,
            'site_description' => trim($S::decode($site['site_description'] ?? '')),
            'site_url' => trim($S::decode($site['site_url'] ?? '')),
            'site_email' => trim($S::decode($site['site_email'] ?? '')),
            'canonical' => $canonical !== '' ? $canonical : 'https://example.com/',
            'icon_mode' => (string)$S::get('site.site_icon_mode'),
            'icon_dir' => $customDir,
            'icon_custom' => $preview($custom),
            'icon_default' => $preview(null),
            'icon_gd' => \app\common\util\SiteIcons::available(),
            'short_name' => \app\common\util\SiteIcons::shortName($name !== '' ? $name : 'JavCMS'),
            'theme_color' => (string)$S::get('site.site_theme_color'),
            'share_image' => $share,
            'share_url' => $share !== '' ? $base . $share : '',
            'share_w' => (int)$S::get('site.site_share_w'),
            'share_h' => (int)$S::get('site.site_share_h'),
            'social' => (string)$S::get('site.site_social'),
            'ga4' => (string)$S::get('site.site_ga4'),
            'head_code' => (string)$S::get('site.site_head_code'),
            'code_consent' => (int)$S::get('site.site_code_consent'),
            'site_tj' => (string)($site['site_tj'] ?? ''),
            'can_code' => $this->check_auth('system', 'code'),
            'can_ads' => $this->check_auth('jcx', 'index'),
            'is_ember' => $themeDir === 'ember',
            'root_ico_url' => is_file($rootIco) ? $base . 'favicon.ico' : '',
            'upload_url' => url('system/siteicon'),
        ];
    }

    /**
     * B.1: icon / share picture upload (POST only, permission system/siteicon, token). The setting is live after Save
     * (one writer: System::config()); this action only checks, generates and publishes the files.
     */
    public function siteicon()
    {
        if (!$this->request->isPost()) {
            return $this->ajaxErrorWithFreshToken(lang('illegal_request'));
        }
        $post = input('post.', [], '');
        // A request above post_max_size arrives with no fields at all (no token either): say why instead of "Invalid request".
        $postMax = self::seoIniBytes((string)ini_get('post_max_size'));
        if (!$post && empty($_FILES) && $postMax > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
            return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/upload_server_limit', [self::seoServerLimit()]));
        }
        if (!\think\Loader::validate('Token')->check($post)) {
            return $this->ajaxErrorWithFreshToken(lang('illegal_request'), ['retry' => 1]);
        }
        $kind = (string)($post['kind'] ?? '') === 'share' ? 'share' : 'icon';
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'], $file['error']) || is_array($file['tmp_name'])
            || (int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $code = is_array($file) ? (int)($file['error'] ?? 0) : -1;
            if ($code === UPLOAD_ERR_INI_SIZE) {
                // Above PHP's upload_max_filesize (2M by default): the server limit, not our 2 MB / 5 MB rule.
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/upload_server_limit', [self::seoServerLimit()]));
            }
            $err = $code === UPLOAD_ERR_FORM_SIZE;
            return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/' . ($err ? ($kind === 'share' ? 'share_size_err' : 'icon_size_err') : 'icon_type_err')));
        }
        $name = is_string($file['name'] ?? null) ? (string)$file['name'] : '';
        $stored = (array)(config('maccms')['site'] ?? []);
        $base = \app\common\util\SiteIcons::base();
        if ($kind === 'share') {
            $res = \app\common\util\SiteIcons::share($file['tmp_name'], $name, [(string)($stored['site_share_image'] ?? '')]);
            if (!$res['ok']) {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/' . $res['error']));
            }
            return $this->ajaxSuccessWithFreshToken(lang('admin/ux/seo/share_ready'),
                ['kind' => 'share', 'path' => $res['path'], 'url' => $base . $res['path'], 'w' => $res['w'], 'h' => $res['h'], 'warn' => '']);
        }
        $color = self::seoIn($post['theme_color'] ?? '');
        if (!preg_match('~^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$~D', $color)) {
            $color = (string)\app\common\util\SeoSettings::get('site.site_theme_color');
        }
        $res = \app\common\util\SiteIcons::generate($file['tmp_name'], $color, $name, [(string)($stored['site_icon'] ?? '')]);
        if (!$res['ok']) {
            return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/' . $res['error']));
        }
        $previews = [];
        foreach ($res['previews'] as $size => $path) { $previews[$size] = $base . $path; }
        return $this->ajaxSuccessWithFreshToken(lang('admin/ux/seo/icon_ready'), ['kind' => 'icon', 'path' => $res['path'],
            'previews' => $previews, 'w' => $res['w'], 'h' => $res['h'], 'warn' => $res['warn'] !== '' ? lang('admin/ux/seo/' . $res['warn']) : '']);
    }

    // ------------------------------------------------------------------ checklist (A.5.4)

    /** Facts of the checklist (server side), cached 60 s per admin. */
    protected function seoCheckFacts()
    {
        $key = 'seo_check_' . (int)($this->_admin['admin_id'] ?? 0);
        $facts = Cache::get($key);
        if (is_array($facts) && isset($facts['v']) && $facts['v'] === 3) {
            return $facts;
        }
        $S = '\app\common\util\SeoSettings';
        $cfg = config('maccms');
        $site = (array)($cfg['site'] ?? []);
        $legalTpl = [];
        $legalOn = $S::legalPages();
        foreach ($legalOn as $slug) {
            $src = \app\common\util\LegalPages::source($slug);
            if (!$src['own']) { $legalTpl[] = $slug; }
        }
        $robotsBlocks = false;
        if (is_file(ROOT_PATH . 'robots.txt')) {
            $robotsBlocks = (bool)$S::robotsCustom((string)@file_get_contents(ROOT_PATH . 'robots.txt', false, null, 0, 65536))['blocks_all'];
        }
        $desc = self::seoLen(trim($S::decode($site['site_description'] ?? '')));
        $active = \app\common\util\SiteIcons::active();
        $facts = [
            'v' => 3,
            'name' => self::seoLen(trim($S::decode($site['site_name'] ?? ''))) <= 30,
            'home_title' => (string)$S::get('site.site_home_title') !== '',
            'home_desc' => $desc >= 70 && $desc <= 160,
            'icon' => in_array($active['set'], ['custom', 'nogd'], true),
            'verify' => (string)$S::get('seo.verify_google') !== '' || (string)$S::get('seo.verify_bing') !== ''
                || (string)$S::get('seo.verify_yandex') !== '' || (string)$S::get('seo.verify_naver') !== '',
            'hidden' => !$S::bool('seo.hide_site'),
            // null = no row: on a plain-http site there is nothing to check (an "https" Done row would be wrong).
            'https' => \app\common\util\Sitemap::requestIsHttps() ? (string)($cfg['rewrite']['canonical_scheme'] ?? 'auto') !== 'http' : null,
            'robots_root' => !$robotsBlocks,
            'email' => !(in_array('dmca', $legalOn, true) && trim($S::decode($site['site_email'] ?? '')) === ''),
            'legal_tpl' => $legalTpl,
            'search_on' => (string)($cfg['app']['search'] ?? '1') === '1',
            'indexnow' => \app\common\util\SeoPing::enabled(),
        ];
        Cache::set($key, $facts, 60);
        return $facts;
    }

    /** Checklist rows: id, ok (true / false / null = checked in the browser), text, fix link (shell tab id + URL). */
    protected function seoChecklist()
    {
        $f = $this->seoCheckFacts();
        $identity = ['n21', url('system/config')];
        $seoPage = url('system/configseo');
        $rows = [];
        $add = function ($id, $ok, $target, $fixKey = 'admin/ux/seo/fix', $text = null) use (&$rows) {
            $rows[] = ['id' => $id, 'ok' => $ok, 'text' => $text !== null ? $text : lang('admin/ux/seo/check_' . $id . ($ok ? '' : '_warn')),
                'tab' => $target ? $target[0] : '', 'url' => $target ? $target[1] : '', 'fix' => lang($fixKey)];
        };
        $add('name', $f['name'], $identity);
        $add('home_title', $f['home_title'], $identity);
        $add('home_desc', $f['home_desc'], $identity);
        $add('icon', $f['icon'], $identity);
        $add('verify', $f['verify'], ['', $seoPage . '#verify']);
        $add('hidden', $f['hidden'], ['', $seoPage . '#index']);
        if ($f['https'] !== null) { $add('https', $f['https'], ['n22', url('system/configurl')]); }
        $add('robots_root', $f['robots_root'], ['n81', url('make/opt') . '#robots']);
        $add('email', $f['email'], $identity);
        $names = [];
        foreach ($f['legal_tpl'] as $slug) { $names[] = lang('admin/ux/seo/legal_names_' . $slug); }
        $add('legal_tpl', !$names, ['n217', url('system/configlegal')], 'admin/ux/seo/fix',
            $names ? lang('admin/ux/seo/check_legal_tpl_warn', [implode(', ', $names)]) : null);
        $add('search_on', $f['search_on'], ['n21', url('system/config') . '?section=search']);
        $add('indexnow', $f['indexnow'], ['n81', url('make/opt') . '#indexnow'], 'admin/ux/seo/indexnow_turn_on');
        // Browser checks (same-origin fetch from the admin page; no outbound call from the server).
        $rows[] = ['id' => 'sitemap', 'ok' => null, 'text' => lang('admin/ux/seo/check_checking'), 'tab' => 'n81', 'url' => url('make/opt'), 'fix' => lang('admin/ux/seo/fix'),
            'ok_text' => lang('admin/ux/seo/check_sitemap'), 'warn_text' => lang('admin/ux/seo/check_sitemap_warn')];
        $rows[] = ['id' => 'gzip', 'ok' => null, 'text' => lang('admin/ux/seo/check_checking'), 'tab' => '', 'url' => '', 'fix' => '',
            'ok_text' => lang('admin/ux/seo/check_gzip'), 'warn_text' => lang('admin/ux/seo/check_gzip_warn')];
        return $rows;
    }

    /** A.5.4 checklist as JSON (permission system/seocheck). */
    public function seocheck()
    {
        $rows = $this->seoChecklist();
        $done = 0;
        foreach ($rows as $r) { if ($r['ok'] === true) { $done++; } }
        return json(['code' => 1, 'msg' => '', 'data' => ['rows' => $rows, 'done' => $done, 'total' => count($rows)]]);
    }

    // ------------------------------------------------------------------ Search titles & descriptions (A.5)

    const SEO_PAGE_KEYS = ['title_brand', 'title_sep', 'tpl_video', 'tpl_category', 'tpl_list', 'tpl_tag', 'tpl_actor', 'dtpl_video',
        'hide_site', 'landing', 'landing_min', 'noindex_after', 'robots_ai', 'verify_google', 'verify_bing', 'verify_yandex', 'verify_naver'];

    public function configseo()
    {
        $S = '\app\common\util\SeoSettings';
        if ((string)input('get.preview', '') === '1' && $this->request->isGet()) {
            return $this->seoPreview();
        }
        if ($this->request->isPost()) {
            $config = input('', '', 'htmlentities');
            if (!\think\Loader::validate('Token')->check($config)) {
                return $this->ajaxErrorWithFreshToken(lang('illegal_request'), ['retry' => 1]);
            }
            $posted = isset($config['seo']) && is_array($config['seo']) ? $config['seo'] : [];
            $labels = ['tpl_video' => 'pattern_video', 'tpl_category' => 'pattern_category', 'tpl_list' => 'pattern_list', 'tpl_tag' => 'pattern_tag',
                'tpl_actor' => 'pattern_actor', 'dtpl_video' => 'pattern_desc_video', 'landing_min' => 'landing_min', 'noindex_after' => 'noindex_after',
                'verify_google' => 'verify_google', 'verify_bing' => 'verify_bing', 'verify_yandex' => 'verify_yandex', 'verify_naver' => 'verify_naver',
                'title_brand' => 'title_brand', 'title_sep' => 'title_sep', 'hide_site' => 'hide_site', 'landing' => 'landing', 'robots_ai' => 'robots_ai'];
            $kinds = ['tpl_video' => 'video', 'tpl_category' => 'category', 'tpl_list' => 'list', 'tpl_tag' => 'tag', 'tpl_actor' => 'actor', 'dtpl_video' => 'video_desc'];
            $ranges = ['landing_min' => [3, 100], 'noindex_after' => [0, 500]];
            $values = [];
            foreach (self::SEO_PAGE_KEYS as $key) {
                if (!array_key_exists($key, $posted)) { continue; }
                $plain = self::seoIn($posted[$key]);
                if (isset($kinds[$key])) {
                    $check = $S::validTitlePattern($plain, $kinds[$key]);
                    if (!$check['ok'] && $check['error'] === 'unknown_token') {
                        return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/' . $labels[$key]) . ': ' . lang('admin/ux/seo/pattern_token_err', [implode(' ', $check['unknown'])]));
                    }
                }
                $result = $S::validate('seo.' . $key, $plain);
                if (!$result['ok']) {
                    if (strpos($key, 'verify_') === 0) {
                        return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/' . $labels[$key]) . ': ' . lang('admin/ux/seo/verify_err'));
                    }
                    return $this->ajaxErrorWithFreshToken(self::seoFieldError($result, 'admin/ux/seo/' . $labels[$key], $ranges[$key] ?? []));
                }
                $values[$key] = $result['store'];
            }
            // CF02 / A.5.2: switching "Hide the site" on needs the typed HIDE confirmation.
            if ((int)($values['hide_site'] ?? 0) === 1 && !$S::bool('seo.hide_site') && (string)($config['hide_confirm'] ?? '') !== 'HIDE') {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/hide_site_confirm'), ['need_confirm' => 'HIDE']);
            }
            // Own titles for tags / actresses (data file, decoded text, CF07). Written only when they changed.
            $customChanged = false;
            if ((string)($config['custom_present'] ?? '') === '1') {
                $rows = [];
                foreach ((isset($config['custom']) && is_array($config['custom']) ? $config['custom'] : []) as $row) {
                    if (!is_array($row)) { continue; }
                    $r = ['kind' => self::seoIn($row['kind'] ?? ''), 'name' => self::seoIn($row['name'] ?? ''),
                        'title' => self::seoIn($row['title'] ?? ''), 'desc' => self::seoIn($row['desc'] ?? '')];
                    if (trim($r['name']) === '' && trim($r['title']) === '' && trim($r['desc']) === '') { continue; }
                    $rows[] = $r;
                }
                if (count($rows) > 200) {
                    return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/custom_too_many'));
                }
                $clean = [];
                foreach ($rows as $i => $r) {
                    $c = $S::customRow($r);
                    if ($c === null) { return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/custom_err', [$i + 1])); }
                    $clean[] = $c;
                }
                $customChanged = $clean !== $S::customRows();
            }
            $config_old = config('maccms');
            $next = $config_old;
            $next['seo'] = self::mergeKeep((array)($config_old['seo'] ?? []), $values);
            if ($customChanged) {
                $w = $S::writeCustom($clean);
                if (!$w['ok']) {
                    return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/custom_write_err'));
                }
            }
            if ($next !== $config_old && !self::seoWriteConfig($next)) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            $this->seoCheckForget();
            return $this->ajaxSuccessWithFreshToken(lang('save_ok'), ['hidden' => $S::bool('seo.hide_site') ? 1 : 0]);
        }

        $g = function ($key) use ($S) { return $S::get('seo.' . $key); };
        $values = [];
        foreach (self::SEO_PAGE_KEYS as $key) { $values[$key] = $g($key); }
        $seps = [];
        foreach ($S::TITLE_SEPARATORS as $sep) { $seps[] = ['value' => $sep, 'label' => trim($sep)]; }
        $tokens = [];
        foreach (['video' => 'tpl_video', 'category' => 'tpl_category', 'list' => 'tpl_list', 'tag' => 'tpl_tag', 'actor' => 'tpl_actor', 'video_desc' => 'dtpl_video'] as $kind => $key) {
            $list = [];
            // N6: each placeholder chip says what it stands for (title attribute).
            foreach ($S::tokens($kind) as $t) { $list[] = ['tk' => '{' . $t . '}', 'tip' => lang('admin/ux/seo/tok_' . $t)]; }
            $tokens[$key] = $list;
        }
        $previews = [];
        foreach (['video', 'category', 'tag'] as $type) {
            try { $previews[$type] = \app\common\util\TemplateSeo::preview($type); } catch (\Throwable $e) { $previews[$type] = ['title' => '', 'description' => '']; }
        }
        $sitemapUrl = '';
        try { $sitemapUrl = \app\common\util\Sitemap::indexUrl(); } catch (\Throwable $e) { $sitemapUrl = ''; }
        $rows = $this->seoChecklist();
        $done = 0;
        foreach ($rows as $r) { if ($r['ok'] === true) { $done++; } }
        $this->assign('seo', $values);
        $this->assign('seo_seps', $seps);
        $verify = [];
        foreach (['google', 'bing', 'yandex', 'naver'] as $se) { $verify[] = ['id' => $se, 'value' => (string)$values['verify_' . $se]]; }
        $this->assign('seo_verify', $verify);
        $this->assign('seo_tokens', $tokens);
        $this->assign('seo_previews', $previews);
        // r5 repair (beginner r5 P2-3): the Titles tab shows the home page result; its title is edited on Site identity.
        $homePreview = ['title' => '', 'description' => ''];
        try { $homePreview = \app\common\util\TemplateSeo::preview('home'); } catch (\Throwable $e) { $homePreview = ['title' => '', 'description' => '']; }
        $this->assign('seo_home_preview', $homePreview);
        $this->assign('seo_home_url', $this->check_auth('system', 'config') ? url('system/config') . '?section=home' : '');
        $this->assign('seo_custom', $S::customRows());
        $this->assign('seo_domain', $this->seoDomainLine());
        $this->assign('seo_sitemap_url', $sitemapUrl);
        $this->assign('seo_lang_n', count(\app\common\util\AvdbLanguage::indexedCodes()));
        $this->assign('seo_lang_m', count(\app\common\util\AvdbLanguage::offeredCodes()));
        $this->assign('seo_check', $rows);
        $this->assign('seo_check_done', $done);
        $this->assign('seo_can_lang', $this->check_auth('system', 'configlang'));
        $this->assign('seo_can_type', $this->check_auth('type', 'index'));
        $this->assign('title', self::uxTitle('admin/ux/menu/seo_titles', 'admin/ux/seo/seo_title'));
        $this->assign('seo_js', self::seoJs());
        return $this->fetch('admin@system/configseo');
    }

    /** The URL line of the Google previews: the site's canonical host. */
    protected function seoDomainLine()
    {
        try {
            $url = (string)\app\common\util\Sitemap::absolute(\app\common\util\SiteIcons::base());
        } catch (\Throwable $e) {
            $url = '';
        }
        return $url !== '' ? rtrim(preg_replace('~^https?://~i', '', $url), '/') : 'example.com';
    }

    /** A.5.1: GET system/configseo?preview=1 → the three previews for unsaved title settings (nothing is saved). */
    protected function seoPreview()
    {
        $overrides = [];
        foreach (['title_brand', 'title_sep', 'tpl_video', 'tpl_category', 'tpl_tag', 'tpl_list', 'tpl_actor', 'dtpl_video'] as $key) {
            $v = input('get.' . $key, null, null);
            if (is_string($v)) { $overrides['seo.' . $key] = self::seoIn($v); }
        }
        $out = [];
        foreach (['video', 'category', 'tag'] as $type) {
            try { $out[$type] = \app\common\util\TemplateSeo::preview($type, [], $overrides); } catch (\Throwable $e) { $out[$type] = ['title' => '', 'description' => '']; }
        }
        return json(['code' => 1, 'msg' => '', 'data' => $out]);
    }

    // ------------------------------------------------------------------ Languages (A.3)

    public function configlang()
    {
        $S = '\app\common\util\SeoSettings';
        $names = \app\common\util\AvdbLanguage::languages();
        $all = array_keys($names);
        if ($this->request->isPost()) {
            $config = input('', '', 'htmlentities');
            if (!\think\Loader::validate('Token')->check($config)) {
                return $this->ajaxErrorWithFreshToken(lang('illegal_request'), ['retry' => 1]);
            }
            $default = self::seoIn($config['lang_default'] ?? '');
            if (!in_array($default, $all, true)) {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/err_format', [lang('admin/ux/seo/lang_default')]));
            }
            $pick = function ($field) use ($config, $all) {
                $list = [];
                foreach ((isset($config[$field]) && is_array($config[$field]) ? $config[$field] : []) as $code) {
                    $code = self::seoIn($code);
                    if (in_array($code, $all, true)) { $list[] = $code; }
                }
                return array_values(array_intersect($all, $list));
            };
            $offered = $pick('lang_offered');
            $indexed = $pick('lang_indexed');
            if (!in_array($default, $offered, true) || !in_array($default, $indexed, true)) {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/lang_need_default'));
            }
            $indexed = array_values(array_intersect($offered, $indexed));
            // '' = all 17 offered / all offered indexed (SeoSettings defaults), so a fresh install needs no key.
            $offeredCsv = count($offered) === count($all) ? '' : implode(',', $offered);
            $indexedCsv = $indexed === $offered ? '' : implode(',', $indexed);
            $storedSite = (array)(config('maccms')['site'] ?? []);
            // Home texts: posted rows for the shown languages (offered, not default); rows of other languages are kept.
            $home = $S::get('site.lang_home');
            $home = is_array($home) ? $home : [];
            $postedHome = isset($config['lang_home']) && is_array($config['lang_home']) ? $config['lang_home'] : [];
            foreach ($all as $code) {
                if ($code === $default || !in_array($code, $offered, true) || !isset($postedHome[$code]) || !is_array($postedHome[$code])) { continue; }
                $home[$code] = ['title' => self::seoIn($postedHome[$code]['title'] ?? ''), 'desc' => self::seoIn($postedHome[$code]['desc'] ?? '')];
            }
            $homeCheck = $S::validate('site.lang_home', $home);
            if (!$homeCheck['ok']) {
                return $this->ajaxErrorWithFreshToken(self::seoFieldError($homeCheck, 'admin/ux/seo/lang_home_card'));
            }
            $config_old = config('maccms');
            $next = $config_old;
            $site = self::mergeKeep($storedSite, ['lang_default' => $default, 'lang_offered' => $offeredCsv, 'lang_indexed' => $indexedCsv]);
            $site['lang_home'] = $homeCheck['store'];
            $next['site'] = $site;
            if ($next !== $config_old && !self::seoWriteConfig($next)) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            $this->seoCheckForget();
            return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
        }
        $offered = \app\common\util\AvdbLanguage::offeredCodes();
        $indexed = \app\common\util\AvdbLanguage::indexedCodes();
        $default = \app\common\util\AvdbLanguage::defaultLocale();
        $rows = [];
        foreach ($names as $code => $label) {
            $h = $S::langHome($code);
            $rows[] = ['code' => $code, 'name' => $label, 'offered' => in_array($code, $offered, true), 'indexed' => in_array($code, $indexed, true),
                'title' => $h['title'], 'desc' => $h['desc']];
        }
        $this->assign('lang_rows', $rows);
        $this->assign('lang_default', $default);
        $this->assign('lang_sitemaps', $S::bool('seo.sitemap_langs'));
        $this->assign('lang_can_sitemap', $this->check_auth('make', 'opt'));
        $this->assign('title', self::uxTitle('admin/ux/menu/languages', 'admin/ux/seo/lang_title'));
        $this->assign('seo_js', self::seoJs());
        return $this->fetch('admin@system/configlang');
    }

    // ------------------------------------------------------------------ Legal & age (A.4, F)

    public function configlegal()
    {
        $S = '\app\common\util\SeoSettings';
        $L = '\app\common\util\LegalPages';
        $slugs = $L::SLUGS;
        if ($this->request->isPost()) {
            $config = input('', '', 'htmlentities');
            if (!\think\Loader::validate('Token')->check($config)) {
                return $this->ajaxErrorWithFreshToken(lang('illegal_request'), ['retry' => 1]);
            }
            $pages = [];
            foreach ((isset($config['legal_pages']) && is_array($config['legal_pages']) ? $config['legal_pages'] : []) as $slug) {
                $slug = self::seoIn($slug);
                if (in_array($slug, $slugs, true)) { $pages[] = $slug; }
            }
            $siteValues = ['legal_pages' => implode(',', array_values(array_intersect($slugs, $pages)))];
            $postedSite = isset($config['site']) && is_array($config['site']) ? $config['site'] : [];
            $labels = ['legal_operator' => 'operator', 'legal_dmca_agent' => 'dmca_agent', 'legal_dmca_address' => 'dmca_address',
                'legal_custodian' => 'custodian', 'legal_custodian_address' => 'custodian_address', 'age_gate' => 'age_gate',
                'age_gate_days' => 'age_days', 'age_gate_leave' => 'age_leave', 'cookie_notice' => 'cookie_card'];
            foreach ($labels as $key => $label) {
                if (!array_key_exists($key, $postedSite)) { continue; }
                $result = $S::validate('site.' . $key, self::seoIn($postedSite[$key]));
                if (!$result['ok']) {
                    return $this->ajaxErrorWithFreshToken(self::seoFieldError($result, 'admin/ux/seo/' . $label, $key === 'age_gate_days' ? [1, 365] : []));
                }
                $siteValues[$key] = $result['store'];
            }
            $seoValues = [];
            if (isset($config['seo']['rta'])) {
                $result = $S::validate('seo.rta', self::seoIn($config['seo']['rta']));
                if (!$result['ok']) { return $this->ajaxErrorWithFreshToken(self::seoFieldError($result, 'admin/ux/seo/rta')); }
                $seoValues['rta'] = $result['store'];
            }
            // Page texts: check all first, then write (atomic per file). Untouched template text never becomes "your text".
            $writes = [];
            $restores = [];
            $postedLegal = isset($config['legal']) && is_array($config['legal']) ? $config['legal'] : [];
            foreach ($slugs as $slug) {
                if (!isset($postedLegal[$slug]) || !is_array($postedLegal[$slug])) { continue; }
                $row = $postedLegal[$slug];
                $name = lang('admin/ux/seo/legal_names_' . $slug);
                if ((string)($row['restore'] ?? '') === '1') { $restores[] = $slug; continue; }
                $title = trim(preg_replace('/\s+/u', ' ', self::seoIn($row['title'] ?? '')));
                $body = self::seoCodeNorm(self::seoIn($row['body'] ?? ''));
                $src = $L::source($slug);
                $same = $src['own'] ? ($body === self::seoCodeNorm($src['body']) && $title === $src['title'])
                    : ($body === self::seoCodeNorm($L::templateText($slug)) && $title === '');
                if ($same) { continue; }
                if ($body === '') { return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/legal_body_empty', [$name])); }
                if (self::seoLen($body) > $L::BODY_MAX) { return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/legal_body_long', [$name])); }
                if (self::seoLen($title) > $L::TITLE_MAX) { return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/err_too_long', [lang('admin/ux/seo/legal_page_title')])); }
                $writes[$slug] = [$title, $body];
            }
            foreach ($writes as $slug => $w) {
                $res = $L::save((string)$slug, $w[0], $w[1]);
                if (!$res['ok']) { return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/legal_write_err')); }
            }
            foreach ($restores as $slug) {
                if (!$L::restore($slug)) { return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/legal_write_err')); }
            }
            $config_old = config('maccms');
            $next = $config_old;
            $next['site'] = self::mergeKeep((array)($config_old['site'] ?? []), $siteValues);
            if ($seoValues) { $next['seo'] = self::mergeKeep((array)($config_old['seo'] ?? []), $seoValues); }
            if ($next !== $config_old && !self::seoWriteConfig($next)) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            $this->seoCheckForget();
            $own = [];
            foreach ($slugs as $slug) { $own[$slug] = $L::source($slug)['own'] ? 1 : 0; }
            return $this->ajaxSuccessWithFreshToken(lang('save_ok'), ['own' => $own]);
        }
        $enabled = $S::legalPages();
        $pages = [];
        foreach ($slugs as $slug) {
            $src = $L::source($slug);
            $pages[] = ['slug' => $slug, 'name' => lang('admin/ux/seo/legal_names_' . $slug), 'on' => in_array($slug, $enabled, true),
                'own' => $src['own'], 'title' => $src['title'], 'body' => $src['body'], 'template' => $L::templateText($slug),
                'default_title' => lang('admin/ux/seo/legal_names_' . $slug), 'url' => $L::url($slug)];
        }
        $tokens = [];
        foreach ($L::PLACEHOLDERS as $p) { $tokens[] = '{' . $p . '}'; }
        $values = [];
        foreach (['legal_operator', 'legal_dmca_agent', 'legal_dmca_address', 'legal_custodian', 'legal_custodian_address', 'age_gate',
                     'age_gate_days', 'age_gate_leave', 'cookie_notice'] as $key) {
            $values[$key] = $S::get('site.' . $key);
        }
        $values['rta'] = $S::get('seo.rta');
        $this->assign('legal_pages', $pages);
        $this->assign('legal_tokens', $tokens);
        $this->assign('legal', $values);
        $this->assign('title', self::uxTitle('admin/ux/menu/legal_age', 'admin/ux/seo/legal_title'));
        $this->assign('seo_js', self::seoJs());
        return $this->fetch('admin@system/configlegal');
    }
}
