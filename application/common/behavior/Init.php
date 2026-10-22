<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\behavior;

use think\Cache;
use think\Exception;

class Init
{
    public function run(&$params)
    {
        $config = config('maccms');
        $domain = config('domain');

        // 1.2.0 (XVID-SCALE-BRIEF L3, se-R19): one host per site. Requests for a host outside the allowlist (site_url,
        // site_wapurl, config('domain') hosts and their www. variants) are redirected to site_url (GET/HEAD) or refused with
        // 421; an empty or invalid site_url means no redirect; the admin entrance is exempt.
        if (ENTRANCE == 'index' && PHP_SAPI !== 'cli' && class_exists('\app\common\util\ScaleTier')) {
            $hostSettings = \app\common\util\ScaleTier::settings();
            $reqHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            if ($hostSettings['host'] !== '' && !in_array($reqHost, $hostSettings['hosts'], true)) {
                $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
                header('Cache-Control: no-store');
                if ($method === 'GET' || $method === 'HEAD') {
                    $toScheme = $hostSettings['scheme'] !== '' ? $hostSettings['scheme'] : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http');
                    $toUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
                    if ($toUri === '' || $toUri[0] !== '/' || strlen($toUri) > 2048) { $toUri = '/'; }
                    header('Location: ' . $toScheme . '://' . $hostSettings['host'] . $toUri, true, 301);
                } else {
                    http_response_code(421);
                }
                exit;
            }
        }
        // 1.2.0 (L6, HP-H-3): statement time limit on every database connection of this request: front end from the tier
        // (5 s, 3 s at >= 1 M videos), admin 30 s; CLI and housekeeping use their own unlimited connections. The PDO attribute
        // is chosen by PHP version (Pdo\Mysql::ATTR_INIT_COMMAND on 8.4+, no deprecation on 8.5).
        if ((ENTRANCE == 'index' || ENTRANCE == 'admin') && PHP_SAPI !== 'cli' && class_exists('\app\common\util\DbTimeout')) {
            $stmtLimit = ENTRANCE == 'admin' ? 30 : (int)\app\common\util\ScaleTier::get()['stmt_limit'];
            $dbParams = config('database.params');
            $dbParams = is_array($dbParams) ? $dbParams : [];
            $dbParams[\app\common\util\DbTimeout::initAttribute()] = \app\common\util\DbTimeout::initCommand($stmtLimit);
            config('database.params', $dbParams);
        }
        // 1.2.0 (2.12 step 2a): additive scale tables on the first admin visit after an upgrade (once per schema version).
        if (ENTRANCE == 'admin' && PHP_SAPI !== 'cli' && class_exists('\app\common\util\ScaleSchema')) {
            try { \app\common\util\ScaleSchema::firstAdminVisit(); } catch (\Throwable $e) {}
        }

        $isMobile = 0;
        $ua = strtolower($_SERVER['HTTP_USER_AGENT']);
        $uachar = "/(nokia|sony|ericsson|mot|samsung|sgh|lg|philips|panasonic|alcatel|lenovo|meizu|cldc|midp|iphone|wap|mobile|android)/i";
        if((preg_match($uachar, $ua))) {
            $isMobile = 1;
        }

        $isDomain=0;
        if( is_array($domain) && !empty($domain[$_SERVER['HTTP_HOST']])){
            $config['site'] = array_merge($config['site'],$domain[$_SERVER['HTTP_HOST']]);
            $isDomain=1;
            if(empty($config['site']['mob_template_dir']) || $config['site']['mob_template_dir'] =='no'){
                $config['site']['mob_template_dir'] = $config['site']['template_dir'];
            }
            $config['site']['site_wapurl'] = $config['site']['site_url'];
            $config['site']['mob_html_dir'] = $config['site']['html_dir'];
            $config['site']['mob_ads_dir'] = $config['site']['ads_dir'];
        }
        if (ENTRANCE == 'index') {
            // Settings as configured (before a preview override): the switcher's default and offered list.
            $switcherSite = $config['site'];
            $previewTheme = \app\common\util\ThemePreview::current();
            if ($previewTheme !== '') {
                $config['site']['template_dir'] = $config['site']['mob_template_dir'] = $previewTheme;
                $config['site']['html_dir'] = $config['site']['mob_html_dir'] = 'html';
                // Theme developers holding a valid preview signature may ask for error details.
                if (!empty($_COOKIE['jv_theme_debug'])) {
                    \think\App::$debug = true;
                    config('app_debug', true);
                }
            }
            // Visitor theme switcher (site.theme_switcher). The signed preview above wins; otherwise a
            // jv_theme cookie that names an offered, selectable theme replaces the default for this
            // browser only. Validation, cookie renewal/expiry: app\common\util\ThemeSwitcher::begin().
            // A phone view of a separate mobile template (same rule as $TMP_ISWAP below) has that template as its default.
            $switcherMobile = $isMobile && $isDomain == 0 && (($switcherSite['mob_status'] ?? 0) == 2
                || (($switcherSite['mob_status'] ?? 0) == 1 && ($_SERVER['HTTP_HOST'] ?? '') == ($switcherSite['site_wapurl'] ?? '')));
            $visitorTheme = \app\common\util\ThemeSwitcher::begin($switcherSite, $previewTheme, $switcherMobile);
            if ($visitorTheme !== '') {
                $config['site']['template_dir'] = $config['site']['mob_template_dir'] = $visitorTheme;
                $config['site']['html_dir'] = $config['site']['mob_html_dir'] = 'html';
                $config['site']['ads_dir'] = $config['site']['mob_ads_dir'] = \app\admin\util\ThemeGate::adsDir($visitorTheme);
            }
            // [icon-merge] insertion point: per-request icon-system setup (if any) goes here, after the theme is final.
        }
        $TMP_ISWAP = 0;
        $TMP_TEMPLATEDIR = $config['site']['template_dir'];
        $TMP_HTMLDIR = $config['site']['html_dir'];
        $TMP_ADSDIR = $config['site']['ads_dir'];

        if($isMobile && $isDomain==0){
            if( ($config['site']['mob_status']==2 ) || ($config['site']['mob_status']==1 && $_SERVER['HTTP_HOST']==$config['site']['site_wapurl']) || ($config['site']['mob_status']==1 && $isDomain) ) {
                $TMP_ISWAP = 1;
                $TMP_TEMPLATEDIR = $config['site']['mob_template_dir'];
                $TMP_HTMLDIR = $config['site']['mob_html_dir'];
                $TMP_ADSDIR = $config['site']['mob_ads_dir'];
            }
        }

        // Keep legacy integrations on this site origin.  The old hard-coded
        // maccms.la URL leaked a third-party host into templates and SEO links.
        $macUrl = trim((string)($config['site']['site_url'] ?? ''));
        if (!preg_match('~^https?://[^\\s]+$~i', $macUrl)) $macUrl = '';
        define('MAC_URL', $macUrl === '' ? '/' : rtrim($macUrl, '/') . '/');
        define('MAC_NAME','JAVCMS');
        define('MAC_PATH', $config['site']['install_dir'] .'');
        define('MAC_MOB', $TMP_ISWAP);
        define('MAC_TEMPLATE_DIR', $TMP_TEMPLATEDIR);
        define('MAC_ROOT_TEMPLATE', ROOT_PATH .'template/'.$TMP_TEMPLATEDIR.'/'. $TMP_HTMLDIR .'/');
        define('MAC_PATH_TEMPLATE', MAC_PATH.'template/'.$TMP_TEMPLATEDIR.'/');
        define('MAC_PATH_TPL', MAC_PATH_TEMPLATE. $TMP_HTMLDIR  .'/');
        define('MAC_PATH_ADS', MAC_PATH_TEMPLATE. $TMP_ADSDIR  .'/');
        define('MAC_PAGE_SP', $config['path']['page_sp'] .'');
        define('MAC_PLAYER_SORT', $config['app']['player_sort'] );
        define('MAC_ADDON_PATH', ROOT_PATH . 'addons' . '/');
        define('MAC_ADDON_PATH_STATIC', ROOT_PATH . 'static/addons/');

        $GLOBALS['MAC_ROOT_TEMPLATE'] = ROOT_PATH .'template/'.$TMP_TEMPLATEDIR.'/'. $TMP_HTMLDIR .'/';
        $GLOBALS['MAC_PATH_TEMPLATE'] = MAC_PATH.'template/'.$TMP_TEMPLATEDIR.'/';
        $GLOBALS['MAC_PATH_TPL'] = $GLOBALS['MAC_PATH_TEMPLATE']. $TMP_HTMLDIR  .'/';
        $GLOBALS['MAC_PATH_ADS'] = $GLOBALS['MAC_PATH_TEMPLATE']. $TMP_ADSDIR  .'/';

        // 1.2.0 (se-R12): X-Forwarded-Proto counts only from a Cloudflare peer or a configured trusted proxy.
        $trustedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'
            && class_exists('\app\common\util\PublicRequestGuard') && \app\common\util\PublicRequestGuard::trustedPeer(ROOT_PATH, $_SERVER);
        $GLOBALS['http_type'] = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || $trustedProto) ? 'https://' : 'http://';

        if(ENTRANCE=='index'){
            config('dispatch_success_tmpl','public/jump');
            config('dispatch_error_tmpl','public/jump');
        }
        if(ENTRANCE=='admin'){
            config('dispatch_success_tmpl','admin@public/jump');
            config('dispatch_error_tmpl','admin@public/jump');
        }

        config('template.view_path', 'template/' . $TMP_TEMPLATEDIR .'/' . $TMP_HTMLDIR .'/');

        if(ENTRANCE=='admin'){
            if(!file_exists('./template/' . $TMP_TEMPLATEDIR .'/' . $TMP_HTMLDIR .'/')){
                config('template.view_path','');
            }
        }
        if(intval($config['app']['search_len'])<1){
            $config['app']['search_len'] = 10;
        }
        config('url_route_on',$config['rewrite']['route_status']);
        if(empty($config['app']['pathinfo_depr'])){
            $config['app']['pathinfo_depr'] = '/';
        }
        config('pathinfo_depr',$config['app']['pathinfo_depr']);

        if(intval($config['app']['cache_time'])<1){
            $config['app']['cache_time'] = 60;
        }
        config('cache.expire', $config['app']['cache_time'] );


        if(!in_array($config['app']['cache_type'],['file','memcache','memcached','redis'])){
            $config['app']['cache_type'] = 'file';
        }
        if(!empty($config['app']['lang'])){
            config('default_lang', $config['app']['lang']);
        }
        // Per-admin language (admin only): the account menu sets the cookie javcms_admin_lang; whitelisted values only.
        // ADMIN-IA-BRIEF.md HP-2.
        if (defined('ENTRANCE') && ENTRANCE === 'admin') {
            config('admin_lang_cookie', true);
            $adminLang = isset($_COOKIE['javcms_admin_lang']) && is_string($_COOKIE['javcms_admin_lang']) ? $_COOKIE['javcms_admin_lang'] : '';
            if (in_array($adminLang, ['en-us', 'vi-vn', 'zh-cn', 'zh-tw'], true)) {
                config('default_lang', $adminLang);
            }
        }
        // Time zone chosen in Site settings (app.timezone); without it the default_timezone of application/config.php applies.
        // ADMIN-IA-BRIEF.md HP-1.
        $siteTimezone = isset($config['app']['timezone']) && is_string($config['app']['timezone']) ? $config['app']['timezone'] : '';
        if ($siteTimezone !== '' && in_array($siteTimezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($siteTimezone);
            config('default_timezone', $siteTimezone);
        }

        config('cache.type', $config['app']['cache_type']);
        config('cache.timeout',1000);
        config('cache.host',$config['app']['cache_host']);
        config('cache.port',$config['app']['cache_port']);
        config('cache.username',$config['app']['cache_username']);
        config('cache.password',$config['app']['cache_password']);
        if($config['app']['cache_type'] != 'file'){
            $opt = config('cache');
            Cache::$handler = null;
        }

        $GLOBALS['config'] = $config;
    }
}
