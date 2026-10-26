<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\admin\controller;
use think\Hook;
use think\captcha\Captcha;
use think\Config;
use think\Cache;
use think\Db;
use app\admin\common\Onboarding;

class Index extends Base
{
    use \app\admin\common\ListCsrf;

    /** Wrong passwords allowed on the lock screen before the session is signed out (security r3 P3-2). */
    const UNLOCK_TRIES = 5;
    /** Shell tab id of the Overview (ADMIN-IA-BRIEF R5). */
    const OVERVIEW_TAB = '111';
    /** Advanced section order (ADMIN-IA-BRIEF 1.3). */
    protected static $sections = ['content', 'paid', 'sources', 'import', 'dev', 'old'];
    /** Admin languages offered in the account menu (HP-2 whitelist). Names are shown in their own language. */
    protected static $adminLangs = ['en-us' => 'English', 'vi-vn' => 'Tiếng Việt', 'zh-cn' => '简体中文', 'zh-tw' => '繁體中文'];

    public function __construct()
    {
        parent::__construct();
    }

    public function login()
    {
        if(Request()->isPost()) {
            $data = input('post.');
            $res = model('Admin')->login($data);
            if ($res['code'] > 1) {
                return $this->error($res['msg']);
            }
            session('admin_locked', null);
            return $this->success($res['msg']);
        }
        Hook::listen("admin_login_init", $this->request);
        return $this->fetch('admin@index/login');
    }

    public function captcha($id='')
    {
        mac_no_cahche();
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        $captcha = new Captcha((array)Config::get('captcha'));
        return $captcha->entry($id);
    }

    public function logout()
    {
        session('admin_locked', null);
        $res = model('Admin')->logout();
        // r5 (R2r2-N4): signing out also ends the ad preview (cookies jcxp / jcxh / jcxal) in this browser.
        if (class_exists('\app\common\util\JcxBuild')) {
            try {
                \app\common\util\JcxBuild::clearPreviewCookies();
            } catch (\Throwable $e) {
                // the preview cookies expire on their own; signing out must never fail because of them
            }
        }
        $this->redirect('index/login');
    }

    /* ------------------------------------------------------------------ helpers */

    protected static function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    /** Stored names are HTML-encoded in the DB: decode once here, escape once on output. */
    protected static function plain($s)
    {
        return trim(html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8'));
    }

    /** English text of a lang key (ADMIN-IA-BRIEF R2): the en-us global + admin packs, loaded once into range en-us. */
    protected static function en($key)
    {
        static $loaded = false;
        if ($key === '' || $key === null) {
            return '';
        }
        if (!$loaded) {
            $loaded = true;
            $files = [];
            foreach ([APP_PATH . 'lang' . DS . 'en-us.php', APP_PATH . 'admin' . DS . 'lang' . DS . 'en-us.php'] as $f) {
                if (is_file($f)) {
                    $files[] = $f;
                }
            }
            if ($files) {
                \think\Lang::load($files, 'en-us');
            }
        }
        $v = \think\Lang::get($key, [], 'en-us');
        return is_string($v) ? $v : (string)$key;
    }

    protected function adminId()
    {
        return (int)$this->_admin['admin_id'];
    }

    protected function menus()
    {
        $menus = @include MAC_ADMIN_COMM . 'auth.php';
        return is_array($menus) ? $menus : [];
    }

    protected static function itemUrl($s)
    {
        if (strpos((string)$s['action'], 'javascript') !== false) {
            $url = $s['action'];
        } else {
            $url = url('admin/' . $s['controller'] . '/' . $s['action']);
        }
        if (!empty($s['param'])) {
            $url .= '?' . $s['param'];
        }
        return $url;
    }

    /** Full access = admin 1 or every controller/action pair of auth.php ticked (ADMIN-IA-BRIEF 4.5). */
    protected function hasFullAccess($menus)
    {
        if ($this->adminId() === 1) {
            return true;
        }
        $have = array_flip(array_filter(explode(',', strtolower((string)$this->_admin['admin_auth']))));
        foreach ($menus as $g) {
            foreach ($g['sub'] as $s) {
                $pair = strtolower($s['controller'] . '/' . $s['action']);
                if (in_array($pair, ['index/index', 'index/welcome', 'index/account'], true)) {
                    continue;
                }
                if (!isset($have[$pair])) {
                    return false;
                }
            }
        }
        return true;
    }

    /** The admin's own shortcuts ("Menu shortcuts" page), one "Name,address" per line. */
    protected function shortcuts()
    {
        $quickmenu = config('quickmenu');
        if (empty($quickmenu)) {
            $quickmenu = mac_read_file(APP_PATH . 'data/config/quickmenu.txt');
            $quickmenu = explode(chr(13), (string)$quickmenu);
        }
        $out = [];
        if (!is_array($quickmenu)) {
            return $out;
        }
        foreach ($quickmenu as $k => $v) {
            $v = trim((string)$v);
            if ($v === '' || strpos($v, ',') === false) {
                continue;
            }
            $one = explode(',', $v, 2);
            $name = trim($one[0]);
            $href = trim($one[1]);
            if ($name === '' || $href === '') {
                continue;
            }
            if (stripos($href, 'javascript:') === 0 || strpos($href, '###') !== false) {
                continue;
            }
            if (substr($href, 0, 4) == 'http' || substr($href, 0, 2) == '//' || substr($href, 0, 1) == '/') {
                $url = $href;
            } else {
                // xss-verify-1 N-1: "vod/data.html" typed by a beginner must not become vod/data.html.html (404).
                $url = url(preg_replace('/\.html?(?=$|[?#])/i', '', $href));
            }
            $out[] = ['id' => 'q' . count($out), 'name' => self::plain($name), 'url' => $url];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ shell */

    public function index()
    {
        $menus = $this->menus();
        $lang = (string)\think\Lang::range();
        $isEn = ($lang === 'en-us');
        $sprite = (int)@filemtime(ROOT_PATH . 'static/admin/icons/admin-icons.svg');

        $groups = [];
        $advGroup = null;
        $adv = [];
        $mainCount = 0;
        $advCount = 0;
        $index = [];
        $overview = null;
        foreach ($menus as $gk => $g) {
            $gk = (string)$gk;
            $gLabel = (string)$g['name'];
            $grp = [
                'key' => $gk,
                'label' => self::h($gLabel),
                'label_raw' => $gLabel,
                'help' => self::h(lang((string)$g['help'])),
                'icon' => self::h($g['icon']),
                'items' => [],
            ];
            foreach ($g['sub'] as $sk => $s) {
                $tier = isset($s['tier']) ? $s['tier'] : 'main';
                if (empty($s['show']) || $tier === 'retired') {
                    continue;
                }
                if (!$this->check_auth($s['controller'], $s['action'])) {
                    continue;
                }
                $label = (string)$s['name'];
                $help = isset($s['help']) ? lang($s['help']) : '';
                $item = [
                    'key' => (string)$sk,
                    'id' => ((string)$sk === '11') ? self::OVERVIEW_TAB : 'n' . $sk,
                    'url' => self::h(self::itemUrl($s)),
                    'url_raw' => self::itemUrl($s),
                    'label' => self::h($label),
                    'label_raw' => $label,
                    'help' => self::h($help),
                    'help_raw' => $help,
                    'icon' => self::h(isset($s['icon']) ? $s['icon'] : 'ai-circle'),
                    'kw' => isset($s['kw']) ? $s['kw'] : '',
                    'name_key' => isset($s['name_key']) ? $s['name_key'] : '',
                    'adv' => $tier === 'advanced',
                    'section' => isset($s['section']) ? $s['section'] : 'old',
                    'group_raw' => $gLabel,
                ];
                if ((string)$sk === '11') {
                    $overview = $item;
                    continue;
                }
                if ($item['adv']) {
                    $adv[$item['section']][] = $item;
                    $advCount++;
                } else {
                    $grp['items'][] = $item;
                    $mainCount++;
                }
            }
            if ($gk === '14') {
                $advGroup = $grp;
                continue;
            }
            if ($gk !== '1' && $grp['items']) {
                $groups[] = $grp;
            }
        }
        if ($advGroup === null) {
            $advGroup = ['key' => '14', 'label' => self::h(lang('admin/ux/menu/g_advanced')), 'label_raw' => lang('admin/ux/menu/g_advanced'),
                'help' => self::h(lang('admin/ux/help/g_advanced')), 'icon' => 'ai-wrench', 'items' => []];
        }
        $advSections = [];
        foreach (self::$sections as $sec) {
            if (!empty($adv[$sec])) {
                foreach ($adv[$sec] as $i => $it) {
                    $adv[$sec][$i]['group_raw'] = $advGroup['label_raw'];
                }
                $advSections[] = ['key' => $sec, 'label' => self::h(lang('admin/ux/menu/section_' . $sec)), 'items' => $adv[$sec]];
            }
        }
        foreach ($adv as $sec => $list) {
            if (!in_array($sec, self::$sections, true)) {
                $advSections[] = ['key' => $sec, 'label' => self::h(lang('admin/ux/menu/section_old')), 'items' => $list];
            }
        }

        // R8 / R9: Advanced group hidden unless the cookie says on; a role with no main page sees it always.
        $adminId = $this->adminId();
        $advCookie = isset($_COOKIE['javcms_adv_' . $adminId]) && $_COOKIE['javcms_adv_' . $adminId] === '1';
        $onlyAdvanced = ($mainCount === 0 && $advCount > 0);
        $showSwitch = ($advCount > 0 && !$onlyAdvanced);
        $advVisible = $advCount > 0 && ($onlyAdvanced || $advCookie);

        // Search index (ADMIN-IA-BRIEF 4.2).
        $shortcuts = $this->shortcuts();
        $add = function ($it, $groupLabel) use (&$index, $isEn) {
            $kw = $it['kw'] !== '' ? lang($it['kw']) : '';
            if ($kw === $it['kw']) {
                $kw = '';
            }
            $kwEn = ($isEn || $it['kw'] === '') ? '' : self::en($it['kw']);
            // Extra search words live in a "<kw>_more" key, so the English text of the original key never changes
            // (e.g. admin/ux/kw/site_identity_more: "time zone", "múi giờ" find Site identity, REVIEW-admin-beginner-r2 #3).
            if ($it['kw'] !== '') {
                $moreKey = $it['kw'] . '_more';
                $more = lang($moreKey);
                if (is_string($more) && $more !== '' && $more !== $moreKey) {
                    $kw = trim($kw . ' ' . $more);
                    $moreEn = $isEn ? '' : self::en($moreKey);
                    if ($moreEn !== '' && $moreEn !== $moreKey) {
                        $kwEn = trim($kwEn . ' ' . $moreEn);
                    }
                }
            }
            $index[] = [
                'id' => $it['id'], 'url' => $it['url_raw'], 'label' => $it['label_raw'],
                'label_en' => $isEn ? '' : self::en($it['name_key']),
                'help' => $it['help_raw'], 'kw' => $kw,
                'kw_en' => $kwEn,
                'group' => $groupLabel, 'adv' => $it['adv'] ? 1 : 0, 'icon' => $it['icon'],
            ];
        };
        if ($overview) {
            $add($overview, lang('admin/ux/menu/g_start'));
        }
        // REVIEW-admin-beginner-r3 P3-6: "Time zone" opens Site identity on the tab with the time zone field
        // (system/config.html?section=timezone). Listed before the pages, so it wins a tie with Site identity.
        foreach ($groups as $grp) {
            foreach ($grp['items'] as $it) {
                if ($it['key'] === '21') {
                    $index[] = [
                        'id' => $it['id'], 'url' => $it['url_raw'] . (strpos($it['url_raw'], '?') === false ? '?' : '&') . 'section=timezone',
                        'label' => lang('admin/ux/system/timezone'), 'label_en' => $isEn ? '' : self::en('admin/ux/system/timezone'),
                        'help' => lang('admin/ux/system/timezone_help'), 'kw' => lang('admin/ux/kw/action_timezone'),
                        'kw_en' => $isEn ? '' : self::en('admin/ux/kw/action_timezone'),
                        'group' => $grp['label_raw'], 'adv' => 0, 'icon' => 'ai-clock',
                        // The tab keeps the page's own name (Site identity), not the search label.
                        'tab' => $it['label_raw'],
                    ];
                    // r5 repair (beginner r5 P2-2 / P2-3): "google analytics", "tracking code", "mã thống kê" open the
                    // Analytics field, and "home page title" opens the Home page title field (both on Site identity).
                    $sep = strpos($it['url_raw'], '?') === false ? '?' : '&';
                    $index[] = [
                        'id' => $it['id'], 'url' => $it['url_raw'] . $sep . 'section=analytics',
                        'label' => lang('admin/ux/system/analytics_code'), 'label_en' => $isEn ? '' : self::en('admin/ux/system/analytics_code'),
                        'help' => lang('admin/ux/system/analytics_code_help'), 'kw' => lang('admin/ux/kw/action_analytics'),
                        'kw_en' => $isEn ? '' : self::en('admin/ux/kw/action_analytics'),
                        'group' => $grp['label_raw'], 'adv' => 0, 'icon' => 'ai-activity', 'tab' => $it['label_raw'],
                    ];
                    $index[] = [
                        'id' => $it['id'], 'url' => $it['url_raw'] . $sep . 'section=home',
                        'label' => lang('admin/ux/seo/home_title'), 'label_en' => $isEn ? '' : self::en('admin/ux/seo/home_title'),
                        'help' => lang('admin/ux/seo/home_title_help'), 'kw' => lang('admin/ux/kw/action_home_title'),
                        'kw_en' => $isEn ? '' : self::en('admin/ux/kw/action_home_title'),
                        'group' => $grp['label_raw'], 'adv' => 0, 'icon' => 'ai-heading', 'tab' => $it['label_raw'],
                    ];
                }
            }
        }
        foreach ($groups as $grp) {
            foreach ($grp['items'] as $it) {
                $add($it, $grp['label_raw']);
            }
        }
        foreach ($advSections as $sec) {
            foreach ($sec['items'] as $it) {
                $add($it, $advGroup['label_raw']);
            }
        }
        foreach ($shortcuts as $q) {
            $index[] = ['id' => $q['id'], 'url' => $q['url'], 'label' => $q['name'], 'label_en' => '', 'help' => '', 'kw' => '', 'kw_en' => '',
                'group' => lang('admin/ux/shell/shortcuts'), 'adv' => 0, 'icon' => 'ai-list-plus'];
        }

        $canClear = $this->check_auth('index', 'clear');
        $canLock = $this->check_auth('index', 'unlocked');
        $langCookie = config('admin_lang_cookie') === true;
        $actions = [
            ['act' => 'account', 'key' => 'admin/ux/account/my', 'kw' => 'admin/ux/kw/action_account', 'icon' => 'ai-key-round'],
        ];
        if ($canClear) {
            $actions[] = ['act' => 'clear', 'key' => 'admin/ux/shell/clear_cache', 'kw' => 'admin/ux/kw/action_clear', 'icon' => 'ai-eraser'];
        }
        if ($canLock) {
            $actions[] = ['act' => 'lock', 'key' => 'admin/ux/account/lock', 'kw' => 'admin/ux/kw/action_lock', 'icon' => 'ai-lock'];
        }
        $actions[] = ['act' => 'view', 'key' => 'admin/ux/shell/view_site', 'kw' => 'admin/ux/kw/action_view', 'icon' => 'ai-external-link'];
        $actions[] = ['act' => 'logout', 'key' => 'admin/ux/account/logout', 'kw' => 'admin/ux/kw/action_logout', 'icon' => 'ai-log-out'];
        if ($showSwitch) {
            $actions[] = ['act' => 'advanced', 'key' => 'admin/ux/shell/advanced_switch', 'kw' => 'admin/ux/kw/action_advanced', 'icon' => 'ai-toggle-right'];
        }
        if ($langCookie) {
            $actions[] = ['act' => 'language', 'key' => 'admin/ux/account/language', 'kw' => 'admin/ux/kw/action_language', 'icon' => 'ai-languages'];
        }
        foreach ($actions as $a) {
            $index[] = ['act' => $a['act'], 'label' => lang($a['key']), 'label_en' => $isEn ? '' : self::en($a['key']),
                'help' => '', 'kw' => lang($a['kw']), 'kw_en' => $isEn ? '' : self::en($a['kw']), 'group' => lang('admin/ux/account/menu'), 'adv' => 0, 'icon' => $a['icon']];
        }
        // R10: retired pages answer with their reason; a click opens the replacement page when the admin may open it.
        $retired = [
            'addons' => '', 'javfilms' => 'n96', 'ads' => 'n214', 'malware' => 'n113',
        ];
        $retiredIndex = [];
        foreach ($retired as $x => $target) {
            $retiredIndex[] = ['id' => $target, 'label' => lang('admin/ux/shell/removed', [lang('admin/ux/retired/' . $x)]),
                'kw' => lang('admin/ux/kw/retired_' . $x), 'kw_en' => $isEn ? '' : self::en('admin/ux/kw/retired_' . $x), 'retired' => 1];
        }

        // Top bar: to-do bell from the Overview numbers (60 s cache).
        $stats = $this->uxStats(false);
        // Count only what this admin may open (a limited admin must not see work it cannot reach).
        $todo = ($this->check_auth('comment', 'data') ? (int)$stats['comments_waiting'] : 0)
            + ($this->check_auth('gbook', 'data') ? (int)$stats['messages_unanswered'] : 0)
            + ($this->check_auth('avdbimport', 'index') ? (int)$stats['import_failed'] : 0);
        // 1.2.1: a newer release the updater's last check found is a to-do of the founder (only the founder installs it)
        $updateNotice = $this->updateNotice();
        if ($updateNotice !== null) {
            $todo++;
        }

        $site = config('maccms.site');
        $siteName = self::plain(is_array($site) && isset($site['site_name']) ? $site['site_name'] : '');
        $adminName = self::plain($this->_admin['admin_name']);
        $initial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($adminName, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($adminName, 0, 1));

        $langs = [];
        foreach (self::$adminLangs as $code => $name) {
            $langs[] = ['code' => $code, 'name' => $name, 'current' => $code === $lang];
        }

        $shellLang = [];
        foreach (['cache_cleared', 'cache_failed', 'search_none', 'badge_advanced', 'tab_limit', 'tab_close_others', 'unsaved', 'leave', 'stay',
                     'advanced_off', 'advanced_on', 'tab_close', 'close_menu', 'shortcuts'] as $k) {
            $shellLang[$k] = lang('admin/ux/shell/' . $k);
        }
        foreach (['language_reload', 'language'] as $k) {
            $shellLang[$k] = lang('admin/ux/account/' . $k);
        }
        $shellLang['tabs'] = lang('admin/ux/shell/tabs');
        $shellLang['confirm_title'] = lang('admin/ux/kit/confirm_title');
        foreach (['title', 'wrong', 'prompt'] as $k) {
            $shellLang['lock_' . $k] = lang('admin/ux/lock/' . $k);
        }
        $shellLang['cancel'] = lang('admin/ux/kit/cancel');
        $shellLang['request_failed'] = lang('admin/ux/kit/request_failed');
        $shellLang['admin'] = lang('admin/ux/shell/brand_admin');
        $shellLang['account_tab'] = lang('admin/ux/account/my');
        $shellLang['cache_data'] = lang('admin/index/cache_data');

        $json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        $this->assign('admin_icons_v', $sprite);
        $this->assign('nav_groups', $groups);
        $this->assign('nav_overview', $overview);
        $this->assign('nav_adv', $advGroup);
        $this->assign('nav_adv_sections', $advSections);
        $this->assign('nav_adv_count', $advCount);
        $this->assign('nav_adv_visible', $advVisible);
        $this->assign('nav_show_switch', $showSwitch);
        $this->assign('nav_adv_off', self::h(lang('admin/ux/shell/advanced_off', [$advCount])));
        $this->assign('nav_shortcuts', array_map(function ($q) {
            return ['id' => $q['id'], 'name' => self::h($q['name']), 'url' => self::h($q['url'])];
        }, $shortcuts));
        $this->assign('shell_compact', isset($_COOKIE['javcms_compact']) && $_COOKIE['javcms_compact'] === '1');
        $this->assign('shell_collapsed', isset($_COOKIE['javcms_nav_collapsed']) && $_COOKIE['javcms_nav_collapsed'] === '1');
        $this->assign('shell_admin_id', $adminId);
        $this->assign('shell_can_clear', $canClear);
        $this->assign('shell_can_lock', $canLock);
        $this->assign('shell_lang_menu', $langCookie);
        $this->assign('shell_langs', $langs);
        $this->assign('shell_todo', $todo);
        $this->assign('shell_update_counted', $updateNotice !== null);
        $this->assign('shell_site_name', self::h($siteName));
        $this->assign('shell_admin_name', self::h($adminName));
        $this->assign('shell_initial', self::h($initial));
        $this->assign('shell_full', $this->hasFullAccess($menus));
        $this->assign('shell_account_url', url('index/account'));
        $this->assign('shell_index_json', json_encode(['items' => $index, 'retired' => $retiredIndex], $json));
        $this->assign('shell_lang_json', json_encode($shellLang, $json));
        $this->assign('shell_cfg_json', json_encode([
            'adminId' => $adminId,
            'overviewUrl' => url('index/welcome'),
            'accountUrl' => url('index/account'),
            'clearUrl' => url('index/clear'),
            'unlockUrl' => url('index/unlocked'),
            'logoutUrl' => url('index/logout'),
            'checkCacheUrl' => url('index/checkcache'),
            // Session admin token for the shell's own POSTs (Clear cache, Lock screen), sent as X-CSRF-Token.
            'csrf' => function_exists('mac_admin_csrf_token') ? (string)mac_admin_csrf_token() : '',
            'tabLimit' => 12,
        ], $json));
        $this->assign('title', lang('admin/ux/shell/brand_admin'));
        return $this->fetch('admin@index/index');
    }

    /* ------------------------------------------------------------------ Overview numbers */

    /**
     * Newest database backup file time (0 = none). Backups are written to the configured db.backup_path
     * (default ./application/data/backup/, relative to the site root); the old database/ sub folder is
     * still looked at so older backups count too.
     */
    protected static function lastBackupTime()
    {
        $dirs = [];
        $cfg = isset($GLOBALS['config']['db']['backup_path']) ? trim((string)$GLOBALS['config']['db']['backup_path']) : '';
        if ($cfg !== '') {
            if (strpos($cfg, './') === 0) {
                $cfg = ROOT_PATH . substr($cfg, 2);
            } elseif (!preg_match('#^(/|[A-Za-z]:)#', $cfg)) {
                $cfg = ROOT_PATH . $cfg;
            }
            $dirs[] = rtrim($cfg, '/\\') . DS;
        }
        $dirs[] = APP_PATH . 'data' . DS . 'backup' . DS . 'database' . DS;
        $files = [];
        foreach (array_unique($dirs) as $dir) {
            $files = array_merge($files, (array)@glob($dir . '*'));
        }
        $newest = 0;
        foreach ($files as $f) {
            if (!is_string($f) || !is_file($f) || !preg_match('/\.(sql|gz|zip)$/i', $f)) {
                continue;
            }
            $t = (int)@filemtime($f);
            if ($t > $newest) {
                $newest = $t;
            }
        }
        return $newest;
    }

    /**
     * One cache key for the Overview cards and the top-bar to-do count: ux_overview_stats, 60 s
     * (deleted by Clear cache and by the Refresh button). ADMIN-IA-BRIEF 4.7.
     */
    protected function uxStats($refresh)
    {
        if (!$refresh) {
            $c = Cache::get('ux_overview_stats');
            if (is_array($c) && isset($c['time'])) {
                return $c;
            }
        }
        $now = time();
        $s = ['time' => $now, 'videos' => 0, 'videos_estimate' => 0, 'new24' => 0, 'hidden' => 0, 'recycle' => 0,
            'members' => 0, 'members_week' => 0, 'comments_waiting' => 0, 'messages_unanswered' => 0,
            'import' => null, 'import_failed' => 0, 'backup' => self::lastBackupTime()];
        try {
            $t0 = microtime(true);
            $s['videos'] = (int)Db::name('vod')->count();
            if (microtime(true) - $t0 > 0.3) {
                $prefix = config('database.prefix');
                $row = Db::query('SHOW TABLE STATUS LIKE ?', [$prefix . 'vod']);
                if (!empty($row[0]['Rows'])) {
                    $s['videos'] = (int)$row[0]['Rows'];
                    $s['videos_estimate'] = 1;
                }
            }
            $s['new24'] = (int)Db::name('vod')->where('vod_time_add', '>=', $now - 86400)->count();
            $s['hidden'] = (int)Db::name('vod')->where('vod_status', 0)->count();
            $s['recycle'] = (int)Db::name('vod')->where('vod_recycle_time', '>', 0)->count();
        } catch (\Throwable $e) {
        }
        try {
            $s['members'] = (int)Db::name('user')->count();
            $s['members_week'] = (int)Db::name('user')->where('user_reg_time', '>=', $now - 604800)->count();
        } catch (\Throwable $e) {
        }
        try {
            $s['comments_waiting'] = (int)Db::name('comment')->where('comment_time', '>=', $now - 90 * 86400)->where('comment_status', 0)->count();
        } catch (\Throwable $e) {
        }
        try {
            $s['messages_unanswered'] = (int)Db::name('gbook')->where('gbook_time', '>=', $now - 90 * 86400)->where('gbook_reply_time', 0)->count();
        } catch (\Throwable $e) {
        }
        try {
            $job = Db::name('avdb_import_job')->field('job_id,state,phase,created_at,updated_at')->order('created_at desc')->find();
            if ($job) {
                $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'pending' => 0];
                $rows = Db::name('avdb_import_item')->where('job_id', $job['job_id'])->field('state, COUNT(*) AS n')->group('state')->select();
                foreach ((array)$rows as $r) {
                    if (isset($counts[$r['state']])) {
                        $counts[$r['state']] = (int)$r['n'];
                    }
                }
                $total = array_sum($counts);
                $s['import'] = ['state' => (string)$job['state'], 'time' => (int)$job['updated_at'], 'counts' => $counts,
                    'percent' => $total > 0 ? (int)floor(100 * ($total - $counts['pending']) / $total) : 0];
                $s['import_failed'] = $counts['failed'];
            }
        } catch (\Throwable $e) {
        }
        Cache::set('ux_overview_stats', $s, 60);
        return $s;
    }

    /** The signed-in admin's automatic-import row (primary key / unique owner lookup, never cached). */
    protected function scheduleRow()
    {
        try {
            $row = Db::name('avdb_import_schedule')->where('admin_id', $this->adminId())->field('enabled,state,next_run_at,interval_minutes')->find();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected static function ago($t)
    {
        if ($t <= 0) {
            return lang('admin/ux/overview/never');
        }
        $d = time() - $t;
        if ($d < 60) {
            return lang('admin/ux/overview/just_now');
        }
        if ($d < 3600) {
            return lang('admin/ux/overview/min_ago', [(int)floor($d / 60)]);
        }
        if ($d < 86400) {
            return lang('admin/ux/overview/hours_ago', [(int)floor($d / 3600)]);
        }
        return lang('admin/ux/overview/days_ago', [(int)floor($d / 86400)]);
    }

    /**
     * 1.2.1: the newest release the one-click updater's last check found (runtime/update/check.php, no network), when it
     * is newer than this site and the admin is the founder (only the founder installs updates); else null.
     */
    protected function updateNotice()
    {
        if ((int)($this->_admin['admin_id'] ?? 0) !== 1 || !is_file(APP_PATH . 'common/util/updater/Service.php')) {
            return null;
        }
        try {
            \app\common\util\updater\Util::setRoot(ROOT_PATH);
            $n = \app\common\util\updater\Service::notice();
        } catch (\Throwable $e) {
            return null;
        }
        return !empty($n['available']) && (string)$n['latest'] !== '' ? (string)$n['latest'] : null;
    }

    protected function entryFile()
    {
        return basename((string)$this->request->baseFile());
    }

    /**
     * CSRF token of the Overview and My account forms. It lives in its own session slot (__ux_token__), so these
     * always-open pages never invalidate the __token__ of a form the admin is editing in another tab.
     */
    protected function uxTokenOk($param)
    {
        $v = \think\Validate::make(['__ux_token__' => 'require|token:__ux_token__']);
        return is_array($param) && $v->check($param);
    }

    /** JSON answer of the Overview / My account actions; always carries a fresh __ux_token__. */
    protected function uxJson($code, $msg, $data = [])
    {
        $data = is_array($data) ? $data : [];
        $data['__ux_token__'] = $this->request->token('__ux_token__');
        return json(['code' => (int)$code, 'msg' => (string)$msg, 'data' => $data]);
    }

    /* ------------------------------------------------------------------ Overview */

    public function welcome()
    {
        $adminId = $this->adminId();
        if ($this->request->isPost()) {
            $param = input('post.');
            if (!$this->uxTokenOk($param)) {
                return $this->uxJson(0, lang('token_err'), ['retry' => 1]);
            }
            $do = isset($param['do']) ? (string)$param['do'] : '';
            $step = isset($param['step']) ? (int)$param['step'] : 0;
            if ($do === 'refresh') {
                Cache::rm('ux_overview_stats');
                return $this->uxJson(1, lang('admin/ux/overview/refresh'));
            }
            if ($do === 'gs_hide' || $do === 'gs_show') {
                Onboarding::set($adminId, ['hidden' => $do === 'gs_hide']);
                return $this->uxJson(1, lang('save_ok'));
            }
            if ($do === 'gs_done' && in_array($step, [6, 9], true)) {
                Onboarding::set($adminId, ['done' => [(string)$step => 1]]);
                return $this->uxJson(1, lang('save_ok'));
            }
            return $this->uxJson(0, lang('param_err'));
        }

        $cfg = config('maccms');
        $site = isset($cfg['site']) ? $cfg['site'] : [];
        $user = isset($cfg['user']) ? $cfg['user'] : [];
        $defaults = @include MAC_ADMIN_COMM . 'seo_defaults.php';
        if (!is_array($defaults)) {
            $defaults = [];
        }
        $stats = $this->uxStats(false);
        $schedule = $this->scheduleRow();
        $onb = Onboarding::get($adminId);
        $version = config('version');
        $versionCode = is_array($version) && isset($version['code']) ? (string)$version['code'] : '';
        $updateSql = file_exists(APP_PATH . 'data/update/database.php');
        $entry = $this->entryFile();
        $host = (string)parse_url('http://' . preg_replace('#^https?://#i', '', (string)(isset($site['site_url']) ? $site['site_url'] : '')), PHP_URL_HOST);
        if ($host === '') {
            $host = (string)$this->request->host();
        }
        $siteName = self::plain(isset($site['site_name']) ? $site['site_name'] : '');
        $templateDir = isset($site['template_dir']) ? (string)$site['template_dir'] : '';
        $themeName = $templateDir;
        try {
            if ($templateDir !== '' && class_exists('\\app\\admin\\util\\ThemeGate')) {
                $themeName = \app\admin\util\ThemeGate::displayName($templateDir);
            }
        } catch (\Throwable $e) {
        }
        $siteOn = !isset($site['site_status']) || (string)$site['site_status'] === '1';
        $regOn = isset($user['reg_open']) && (string)$user['reg_open'] === '1';
        $autoOn = $schedule && (int)$schedule['enabled'] === 1;
        $backup = (int)$stats['backup'];
        $backupOld = $backup <= 0 || (time() - $backup) > 30 * 86400;
        $emailHost = isset($cfg['email']['phpmailer']['host']) ? trim((string)$cfg['email']['phpmailer']['host']) : '';

        $can = function ($c, $a) {
            return $this->check_auth($c, $a);
        };
        $tab = function ($id, $c, $a, $param = '') {
            $u = url('admin/' . $c . '/' . $a);
            return ['tab' => $id, 'url' => $u . ($param !== '' ? '?' . $param : '')];
        };

        // Status strip (block 2).
        $strip = [];
        $strip[] = ['kind' => $siteOn ? 'ok' : 'warn', 'text' => lang($siteOn ? 'admin/ux/overview/site_online' : 'admin/ux/overview/site_closed')];
        $strip[] = ['kind' => $regOn ? 'ok' : 'off', 'text' => lang($regOn ? 'admin/ux/overview/signup_on' : 'admin/ux/overview/signup_off')];
        if ($autoOn) {
            $next = (int)$schedule['next_run_at'] - time();
            $nextTxt = $next > 0 ? lang('admin/ux/overview/in_min', [max(1, (int)ceil($next / 60))]) : lang('admin/ux/overview/just_now');
            $strip[] = ['kind' => 'ok', 'text' => lang('admin/ux/overview/autoimport_on', [$nextTxt])];
        } else {
            $strip[] = ['kind' => 'off', 'text' => lang('admin/ux/overview/autoimport_off')];
        }
        $updateNotice = $this->updateNotice();
        if ($updateSql) {
            $strip[] = ['kind' => 'warn', 'text' => lang('admin/ux/overview/db_update')];
        } elseif ($updateNotice !== null) {
            // 1.2.1: the updater's last check found a newer release (Admins & backup > Updates)
            $strip[] = ['kind' => 'warn', 'text' => lang('admin/ux/updates/overview_available', [$versionCode, $updateNotice])];
        } elseif ((int)($this->_admin['admin_id'] ?? 0) !== 1 && is_file(APP_PATH . 'common/util/updater/Service.php')) {
            // only the founder learns whether a newer release is known (only the founder installs updates): the version
            // alone for every other admin, never "up to date" (ux-4 P3-9)
            $strip[] = ['kind' => 'info', 'text' => lang('admin/ux/updates/overview_version', [$versionCode])];
        } else {
            $strip[] = ['kind' => 'ok', 'text' => lang('admin/ux/overview/version_ok', [$versionCode])];
        }
        $strip[] = ['kind' => 'info', 'text' => lang('admin/ux/overview/theme_in_use', [$themeName]), 'theme' => 1];
        // One warning per topic: when "Needs your attention" already asks for a backup, this chip stays neutral.
        $strip[] = ['kind' => $backupOld ? ($can('database', 'index') ? 'off' : 'warn') : 'ok', 'text' => lang('admin/ux/overview/last_backup', [self::ago($backup)])];
        foreach ($strip as $i => $row) {
            $strip[$i]['text'] = self::h($row['text']);
        }

        // Needs your attention (block 3), in the brief's order.
        $att = [];
        $addAtt = function ($text, $btn, $target, $icon) use (&$att) {
            $att[] = ['text' => self::h($text), 'btn' => self::h(lang($btn)), 'tab' => $target ? $target['tab'] : '',
                'url' => $target ? self::h($target['url']) : '', 'icon' => $icon];
        };
        if (!$siteOn && $can('system', 'config')) {
            $addAtt(lang('admin/ux/overview/att_site_closed'), 'admin/ux/overview/btn_turn_on', $tab('n21', 'system', 'config'), 'ai-triangle-alert');
        }
        if ($updateNotice !== null) {
            $addAtt(lang('admin/ux/updates/att_available', [$updateNotice]), 'admin/ux/updates/att_btn', $tab('n116', 'update', 'index'), 'ai-refresh-cw');
        }
        // The Update controller is permission-checked now (security r3 P3-4): only an admin who may open it gets the button.
        if ($updateSql && $can('update', 'step2')) {
            $att[] = ['text' => self::h(lang('admin/ux/overview/db_update')), 'btn' => self::h(lang('admin/ux/overview/btn_update')), 'tab' => '',
                'url' => self::h(url('update/step2')), 'iframe' => 1, 'icon' => 'ai-database-zap'];
        }
        if ((int)$stats['import_failed'] > 0 && $can('avdbimport', 'index')) {
            $addAtt(lang('admin/ux/overview/att_import_failed', [(int)$stats['import_failed']]), 'admin/ux/overview/btn_retry', $tab('n96', 'avdbimport', 'index'), 'ai-circle-alert');
        }
        if ((int)$stats['comments_waiting'] > 0 && $can('comment', 'data')) {
            // REVIEW-admin-beginner-r3 P3-5: "1 comment waits…", not "1 comments wait…".
            $addAtt((int)$stats['comments_waiting'] === 1 ? lang('admin/ux/overview/att_comments_one') : lang('admin/ux/overview/att_comments', [(int)$stats['comments_waiting']]), 'admin/ux/overview/btn_review', $tab('n35', 'comment', 'data', 'status=0'), 'ai-message-circle');
        }
        if ((int)$stats['messages_unanswered'] > 0 && $can('gbook', 'data')) {
            $addAtt((int)$stats['messages_unanswered'] === 1 ? lang('admin/ux/overview/att_messages_one') : lang('admin/ux/overview/att_messages', [(int)$stats['messages_unanswered']]), 'admin/ux/overview/btn_answer', $tab('n34', 'gbook', 'data', 'reply=1'), 'ai-messages-square');
        }
        if ($backupOld && $can('database', 'index')) {
            $addAtt($backup > 0 ? lang('admin/ux/overview/att_backup_old', [(int)floor((time() - $backup) / 86400)]) : lang('admin/ux/overview/att_backup_never'),
                'admin/ux/overview/btn_backup', $tab('n101', 'database', 'index'), 'ai-database-backup');
        }
        if (!$autoOn && $can('avdbimport', 'index')) {
            $addAtt(lang('admin/ux/overview/att_autoimport'), 'admin/ux/overview/btn_turn_on', $tab('n96', 'avdbimport', 'index'), 'ai-cloud-download');
        }
        if ($regOn && $emailHost === '' && $can('system', 'configemail')) {
            $addAtt(lang('admin/ux/overview/att_smtp'), 'admin/ux/overview/btn_email', $tab('n291', 'system', 'configemail'), 'ai-mail');
        }
        if ($entry === 'javcms.php') {
            $addAtt(lang('admin/ux/overview/att_entry'), 'admin/ux/overview/btn_how', $tab('n1006', 'index', 'account'), 'ai-shield-alert');
        }

        // Getting started (block 4): 9 steps; ticks use config reads, file checks or primary-key lookups only.
        $dSite = isset($defaults['site']) ? $defaults['site'] : [];
        $dSeo = isset($defaults['seo']['vod']) ? $defaults['seo']['vod'] : [];
        $logo = isset($site['site_logo']) ? (string)$site['site_logo'] : '';
        $seoVod = isset($cfg['seo']['vod']) ? $cfg['seo']['vod'] : [];
        $hasVideo = false;
        try {
            $hasVideo = (bool)Db::name('vod')->field('vod_id')->limit(1)->find();
        } catch (\Throwable $e) {
        }
        $done = [
            1 => ($siteName !== '' && $siteName !== $host && $siteName !== (isset($dSite['site_name']) ? self::plain($dSite['site_name']) : ''))
                || ($logo !== '' && $logo !== (isset($dSite['site_logo']) ? $dSite['site_logo'] : '')),
            2 => ($templateDir !== '' && $templateDir !== 'javcms-modern') || !empty($onb['flags']['theme_chosen']),
            3 => $hasVideo,
            4 => (bool)$autoOn,
            // SEO-ADMIN-BRIEF A.9: "Write your search titles" ticks on the home page title or a site name of your own.
            5 => (isset($site['site_home_title']) && trim(self::plain($site['site_home_title'])) !== '')
                || ($siteName !== '' && $siteName !== (isset($dSite['site_name']) ? self::plain($dSite['site_name']) : '')),
            6 => !empty($onb['done']['6']),
            7 => (isset($site['site_tj']) && trim((string)$site['site_tj']) !== '') || self::jcxItemOn(),
            8 => !$backupOld,
            9 => $entry !== 'javcms.php' || !empty($onb['flags']['pwd_changed']) || !empty($onb['done']['9']),
        ];
        $targets = [
            1 => ['n21', 'system', 'config', ''], 2 => ['n215', 'system', 'config', 'section=theme'], 3 => ['n96', 'avdbimport', 'index', ''],
            4 => ['n96', 'avdbimport', 'index', ''], 5 => ['n210', 'system', 'configseo', ''], 6 => ['n211', 'system', 'configuser', ''],
            7 => ['n214', 'jcx', 'index', ''], 8 => ['n101', 'database', 'index', ''], 9 => ['n1006', 'index', 'account', ''],
        ];
        $steps = [];
        $doneCount = 0;
        foreach ($targets as $n => $t) {
            if ($done[$n]) {
                $doneCount++;
            }
            $help = lang('admin/ux/overview/gs' . $n . '_help');
            if ($n === 3 && $done[3]) {
                $help = lang('admin/ux/overview/gs3_done', [number_format((int)$stats['videos'])]);
            }
            if ($n === 6) {
                $help = lang('admin/ux/overview/gs6_help', [lang($regOn ? 'admin/ux/overview/on' : 'admin/ux/overview/off')]);
            }
            $allowed = $t[1] === 'index' ? true : $can($t[1], $t[2]);
            $target = $tab($t[0], $t[1], $t[2], $t[3]);
            $row = ['n' => $n, 'title' => self::h(lang('admin/ux/overview/gs' . $n)), 'help' => self::h($help), 'done' => $done[$n],
                'tab' => $allowed ? $target['tab'] : '', 'url' => $allowed ? self::h($target['url']) : '', 'manual' => in_array($n, [6, 9], true),
                'tab2' => '', 'url2' => ''];
            if ($n === 7 && $can('system', 'config')) {
                // r5 repair (beginner r5 P2-2): step 7 "ads or analytics" gets a second button to the Analytics field.
                $t2 = $tab('n21', 'system', 'config', 'section=analytics');
                $row['tab2'] = $t2['tab'];
                $row['url2'] = self::h($t2['url']);
            }
            $steps[] = $row;
        }

        // Your site today (block 5).
        $num = function ($n) {
            return number_format((int)$n);
        };
        $cards = [];
        if ($can('vod', 'data')) {
            $cards[] = ['icon' => 'ai-film', 'n' => $stats['videos_estimate'] ? lang('admin/ux/overview/estimate', [$num($stats['videos'])]) : $num($stats['videos']),
                'label' => lang('admin/ux/overview/card_videos'), 'tab' => 'n44', 'url' => url('admin/vod/data'), 'sub' => ''];
            $cards[] = ['icon' => 'ai-clock', 'n' => $num($stats['new24']), 'label' => lang('admin/ux/overview/card_new24'), 'tab' => 'n44', 'url' => url('admin/vod/data') . '?added=1', 'sub' => ''];
            $cards[] = ['icon' => 'ai-eye-off', 'n' => $num($stats['hidden']), 'label' => lang('admin/ux/overview/card_hidden'), 'tab' => 'n44', 'url' => url('admin/vod/data') . '?status=0', 'sub' => ''];
            if ((int)$stats['recycle'] > 0) {
                $cards[] = ['icon' => 'ai-archive', 'n' => $num($stats['recycle']), 'label' => lang('admin/ux/overview/card_recycle'), 'tab' => 'n44', 'url' => url('admin/vod/data') . '?recycle=1', 'sub' => ''];
            }
        }
        if ($can('user', 'data')) {
            $cards[] = ['icon' => 'ai-users-round', 'n' => $num($stats['members']), 'label' => lang('admin/ux/overview/card_members'), 'tab' => 'n63', 'url' => url('admin/user/data'),
                'sub' => lang('admin/ux/overview/card_members_new', [$num($stats['members_week'])])];
        }
        if ($can('comment', 'data')) {
            $cards[] = ['icon' => 'ai-message-circle', 'n' => $num($stats['comments_waiting']), 'label' => lang('admin/ux/overview/card_comments'), 'tab' => 'n35', 'url' => url('admin/comment/data') . '?status=0', 'sub' => ''];
        }
        if ($can('gbook', 'data')) {
            $cards[] = ['icon' => 'ai-messages-square', 'n' => $num($stats['messages_unanswered']), 'label' => lang('admin/ux/overview/card_messages'), 'tab' => 'n34', 'url' => url('admin/gbook/data') . '?reply=1', 'sub' => ''];
        }
        foreach ($cards as $i => $c) {
            foreach (['n', 'label', 'url', 'sub'] as $k) {
                $cards[$i][$k] = self::h($c[$k]);
            }
        }

        // Last AVDB import (block 6).
        $import = null;
        if (!empty($stats['import'])) {
            $im = $stats['import'];
            $when = date('Y-m-d H:i', (int)$im['time']);
            if (in_array($im['state'], ['running'], true)) {
                $import = self::h(lang('admin/ux/overview/import_running', [(int)$im['percent']]));
            } else {
                $import = self::h(lang('admin/ux/overview/import_result', [$when, $num($im['counts']['created']), $num($im['counts']['updated']), $num($im['counts']['failed'])]));
            }
        }

        // Quick actions (block 7): built from the items the admin may open, labels from lang.
        $quick = [];
        foreach ([['n96', 'avdbimport', 'index', '', 'admin/ux/menu/avdb_import', 'ai-download'], ['n45', 'vod', 'info', '', 'menu/vod_add', 'ai-square-plus'],
                     ['n35', 'comment', 'data', '', 'menu/comment', 'ai-message-circle'], ['n21', 'system', 'config', '', 'admin/ux/menu/site_identity', 'ai-id-card'],
                     ['n215', 'system', 'config', 'section=theme', 'admin/ux/menu/themes', 'ai-palette']] as $q) {
            if ($can($q[1], $q[2])) {
                $t = $tab($q[0], $q[1], $q[2], $q[3]);
                $quick[] = ['tab' => $t['tab'], 'url' => self::h($t['url']), 'label' => self::h(lang($q[4])), 'icon' => $q[5]];
            }
        }

        $admin = $this->_admin;
        $this->assign('ov_site_line', self::h(trim($siteName . ($siteName !== '' ? ' · ' : '') . $host)));
        $this->assign('ov_strip', $strip);
        $this->assign('ov_update_sql', $updateSql);
        $this->assign('ov_can_theme', $can('system', 'config'));
        $this->assign('ov_theme_url', self::h(url('admin/system/config') . '?section=theme'));
        $this->assign('ov_att', $att);
        $this->assign('ov_att_count', count($att));
        // SEO-ADMIN-BRIEF A.5.2: a red notice while "Hide the site from search engines" is on.
        $this->assign('ov_seo_hidden', class_exists('\\app\\common\\util\\SeoSettings') && \app\common\util\SeoSettings::bool('seo.hide_site'));
        $this->assign('ov_can_seo', $can('system', 'configseo'));
        $this->assign('ov_seo_url', self::h(url('admin/system/configseo') . '#index'));
        $this->assign('ov_steps', $steps);
        $this->assign('ov_gs_hidden', !empty($onb['hidden']));
        $this->assign('ov_gs_progress', self::h(lang('admin/ux/overview/gs_progress', [$doneCount, count($steps)])));
        $this->assign('ov_gs_all_done', $doneCount === count($steps));
        $this->assign('ov_cards', $cards);
        $this->assign('ov_updated', self::h(lang('admin/ux/overview/updated', [date('H:i', (int)$stats['time'])])));
        $this->assign('ov_import', $import);
        $this->assign('ov_can_import', $can('avdbimport', 'index'));
        $this->assign('ov_import_url', self::h(url('admin/avdbimport/index')));
        $this->assign('ov_quick', $quick);
        $this->assign('ov_last_time', $admin['admin_last_login_time'] ? self::h(date('Y-m-d H:i', (int)$admin['admin_last_login_time'])) : self::h(lang('admin/ux/overview/never')));
        $this->assign('ov_last_ip', $admin['admin_last_login_ip'] ? self::h(long2ip((int)$admin['admin_last_login_ip'])) : '-');
        $this->assign('ov_login_num', (int)$admin['admin_login_num']);
        $this->assign('version', $version);
        $this->assign('update_sql', $updateSql);
        $this->assign('admin', $admin);
        $this->assign('admin_icons_v', (int)@filemtime(ROOT_PATH . 'static/admin/icons/admin-icons.svg'));
        $this->assign('ux_token', $this->request->token('__ux_token__'));
        $this->assign('title', lang('admin/ux/overview/title'));
        return $this->fetch('admin@index/welcome');
    }

    /* ------------------------------------------------------------------ My account (ADMIN-IA-BRIEF 4.6, N13) */

    public function account()
    {
        $adminId = $this->adminId();
        if ($this->request->isPost()) {
            $param = input('post.');
            if (!$this->uxTokenOk($param)) {
                return $this->uxJson(0, lang('token_err'), ['retry' => 1]);
            }
            $current = isset($param['current_pwd']) && is_string($param['current_pwd']) ? $param['current_pwd'] : '';
            $new = isset($param['new_pwd']) && is_string($param['new_pwd']) ? $param['new_pwd'] : '';
            $repeat = isset($param['repeat_pwd']) && is_string($param['repeat_pwd']) ? $param['repeat_pwd'] : '';
            if (!model('Admin')->verifyPassword($adminId, $current)) {
                return $this->uxJson(0, lang('admin/ux/myaccount/pwd_wrong'));
            }
            if (strlen($new) < 8) {
                return $this->uxJson(0, lang('admin/ux/myaccount/pwd_short'));
            }
            if (strlen($new) > 72 || strpos($new, "\0") !== false) {
                return $this->uxJson(0, lang('admin/ux/myaccount/pwd_long'));
            }
            if (!hash_equals($new, $repeat)) {
                return $this->uxJson(0, lang('admin/ux/myaccount/pwd_mismatch'));
            }
            $hash = mac_hash_password_for_column($new, 'admin', 'admin_pwd');
            $res = Db::name('Admin')->where('admin_id', $adminId)->update(['admin_pwd' => $hash]);
            if ($res === false) {
                return $this->uxJson(0, lang('save_err'));
            }
            $info = session('admin_info');
            if (is_array($info)) {
                $info['admin_pwd'] = $hash;
                session('admin_info', $info);
            }
            Onboarding::flag($adminId, 'pwd_changed');
            return $this->uxJson(1, lang('admin/ux/myaccount/pwd_changed'));
        }

        $row = Db::name('Admin')->where('admin_id', $adminId)->field('admin_name,admin_last_login_time,admin_last_login_ip')->find();
        $last = '';
        if ($row && (int)$row['admin_last_login_time'] > 0) {
            $last = lang('admin/ux/myaccount/last_signin', [date('Y-m-d H:i', (int)$row['admin_last_login_time']), long2ip((int)$row['admin_last_login_ip'])]);
        }
        $lang = (string)\think\Lang::range();
        $langs = [];
        foreach (self::$adminLangs as $code => $name) {
            $langs[] = ['code' => $code, 'name' => $name, 'current' => $code === $lang];
        }
        $this->assign('acc_name', self::h(self::plain($row ? $row['admin_name'] : $this->_admin['admin_name'])));
        $this->assign('acc_last', self::h($last));
        $this->assign('acc_lang_menu', config('admin_lang_cookie') === true);
        $this->assign('acc_langs', $langs);
        $this->assign('acc_entry_default', $this->entryFile() === 'javcms.php');
        $this->assign('acc_lang_json', json_encode(['language_reload' => lang('admin/ux/account/language_reload'), 'language' => lang('admin/ux/account/language')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE));
        $this->assign('admin_icons_v', (int)@filemtime(ROOT_PATH . 'static/admin/icons/admin-icons.svg'));
        $this->assign('ux_token', $this->request->token('__ux_token__'));
        $this->assign('title', lang('admin/ux/myaccount/title'));
        return $this->fetch('admin@index/account');
    }

    public function quickmenu()
    {
        if(Request()->isPost()){
            $param = input();
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }
            $quickmenu = input('post.quickmenu');
            $quickmenu = str_replace(chr(10),'',$quickmenu);
            $menu_arr = explode(chr(13),$quickmenu);
            $res = mac_arr2file(APP_PATH . 'extra/quickmenu.php', $menu_arr);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }
        else{
            $config_menu = config('quickmenu');
            if(empty($config_menu)){
                $quickmenu = mac_read_file(APP_PATH.'data/config/quickmenu.txt');
            }
            else{
                $quickmenu = array_values($config_menu);
                $quickmenu = join(chr(13),$quickmenu);
            }
            $this->assign('quickmenu',$quickmenu);
            $this->assign('quickmenu_empty', trim(str_replace([chr(13), chr(10)], '', (string)$quickmenu)) === '');
            $this->assign('admin_icons_v', (int)@filemtime(ROOT_PATH . 'static/admin/icons/admin-icons.svg'));
            $this->assign('title',lang('admin/ux/menu/menu_shortcuts'));
            return $this->fetch('admin@index/quickmenu');
        }
    }

    public function checkcache()
    {
        $res = 'no';
        $r = cache('cache_data');
        if($r=='1'){
            $res = 'haved';
        }
        echo $res;
    }

    public function clear()
    {
        // REVIEW-admin-security-r3 P3-2: a POST with the session admin token (sent by the shell), never a plain link.
        if (!$this->request->isPost() || !$this->listCsrfOk()) {
            return json(['code' => 0, 'msg' => lang('admin/ux/system/token_expired'), 'url' => '']);
        }
        $res = $this->_cache_clear();
        if (!$res) {
            return json(['code' => 0, 'msg' => lang('admin/ux/shell/cache_failed'), 'url' => '']);
        }
        // HO-E-7 (C5): the shell's "Clear cache" also starts a new edge-cache generation (stale copies stay usable).
        if (class_exists('\app\common\util\EdgeCache')) { \app\common\util\EdgeCache::bumpGeneration(true); }
        // Preserve the community installation's dedicated search-result cache cleanup.
        model('VodSearch')->clearOldResult(true);
        // Ads (P2-04): Clear cache also ends the admin's own preview (jcxp / jcxh cookies), so live ads show again here.
        if (class_exists('\app\common\util\JcxBuild')) { try { \app\common\util\JcxBuild::clearPreviewCookies(); } catch (\Throwable $e) {} }
        return json(['code' => 1, 'msg' => lang('admin/ux/shell/cache_cleared'), 'url' => '']);
    }

    public function iframe()
    {
        $val = input('post.val', 0);
        if ($val != 0 && $val != 1) {
            return $this->error(lang('param_err'));
        }
        if ($val == 1) {
            cookie('is_iframe', 'yes');
        } else {
            cookie('is_iframe', null);
        }
        return $this->success(lang('admin/index/iframe'));
    }

    /**
     * Lock screen (ADMIN-IA-BRIEF 4.6, F1/B2). POST do=lock sets the server-side flag; POST password unlocks after
     * checking it against the DB row with the login rules (not md5() against the session copy).
     */
    public function unlocked()
    {
        if (!$this->request->isPost()) {
            return $this->error(lang('param_err'));
        }
        $param = input('post.');
        if (isset($param['do']) && $param['do'] === 'lock') {
            // REVIEW-admin-security-r3 P3-2: locking needs the session admin token, so another site cannot lock the admin out.
            if (!$this->listCsrfOk()) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            session('admin_lock_fails', null);
            session('admin_locked', 1);
            return $this->success(lang('admin/ux/lock/title'));
        }
        // close-security (security r4 P3-3): unlocking only means something while the screen is locked. Without a lock
        // the request is refused and NOT counted, so cross-site posts cannot sign the admin out.
        if (!session('admin_locked')) {
            return $this->error(lang('param_err'));
        }
        $password = isset($param['password']) && is_string($param['password']) ? $param['password'] : '';
        if ($password === '' || !model('Admin')->verifyPassword($this->adminId(), $password)) {
            // REVIEW-admin-security-r3 P3-2: after 5 wrong passwords on a locked screen the session is signed out.
            $fails = (int)session('admin_lock_fails') + 1;
            if ($password !== '' && $fails >= self::UNLOCK_TRIES) {
                session('admin_lock_fails', null);
                session('admin_locked', null);
                model('Admin')->logout();
                return json(['code' => 0, 'msg' => lang('admin/ux/lock/too_many'), 'url' => url('index/login'), 'logout' => 1]);
            }
            if ($password !== '') {
                session('admin_lock_fails', $fails);
            }
            return $this->error(lang('admin/ux/lock/wrong'));
        }
        session('admin_lock_fails', null);
        session('admin_locked', null);
        return $this->success(lang('admin/index/unlock_ok'));
    }

    public function check_back_link()
    {
        // r7 repair (sweep): this fetches the given address on the server. No admin page uses it any more; keep it for
        // add-ons, but only as a POST with the admin token and only for an http(s) address.
        if (!$this->request->isPost() || !$this->listCsrfOk()) {
            return json(['code' => 0, 'msg' => lang('admin/ux/system/token_expired')]);
        }
        $url = input('post.url', '');
        $url = is_string($url) ? trim($url) : '';
        if (!preg_match('#^https?://[^/\s]+#i', $url)) {
            return json(['code' => 0, 'msg' => lang('param_err')]);
        }
        $res = mac_check_back_link($url);
        return json($res);
    }

    public function select()
    {
        $param = input();
        $tpl = isset($param['tpl']) ? $param['tpl'] : '';
        $tab = isset($param['tab']) ? $param['tab'] : '';
        $col = isset($param['col']) ? $param['col'] : '';
        $ids = isset($param['ids']) ? $param['ids'] : '';
        $url = isset($param['url']) ? $param['url'] : '';
        $val = isset($param['val']) && is_scalar($param['val']) ? (string)$param['val'] : '';

        $refresh = isset($param['refresh']) ? $param['refresh'] : '';

        if(empty($tpl) || empty($tab) || empty($col) || empty($ids) || empty($url)){
            return $this->error(lang('param_err'));
        }
        // Only the bulk-value pickers in public/select_*.html may be rendered here (kit report: tpl went straight to fetch()).
        if (!is_string($tpl) || !preg_match('/^select_[a-z]+$/', $tpl) || !is_file(APP_PATH . 'admin/view/public/' . $tpl . '.html')) {
            return $this->error(lang('param_err'));
        }
        // Every value below is printed into the picker form (REVIEW-admin-security-r2 P1-1): refuse anything that is not
        // a plain controller/action, column name, model name or id list, so a crafted link cannot inject HTML.
        // col: field names such as vod_level, or collect/bind keys "<cjflag>_<type_id>" (letters, digits, underscore).
        if (!is_string($url) || !preg_match('#^[a-z]+/[a-z_]+$#D', $url)
            || !is_string($tab) || !preg_match('/^[a-z]{1,20}$/D', $tab)
            || !is_string($col) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $col)) {
            return $this->error(lang('param_err'));
        }

        if(is_array($ids)){
            foreach ($ids as $one) {
                if (!is_scalar($one)) {
                    return $this->error(lang('param_err'));
                }
            }
            $ids = join(',',$ids);
        }
        if (!is_scalar($ids) || !preg_match('/^[A-Za-z0-9_,.\-]{1,20000}$/D', (string)$ids)) {
            return $this->error(lang('param_err'));
        }
        $ids = (string)$ids;

        if (!in_array($refresh, ['yes', 'no'], true)) {
            $refresh = 'yes';
        }

        $url = url($url);
        $mid = 1;
        if($tab=='art'){
            $mid = 2;
        }
        elseif($tab=='actor'){
            $mid=8;
        }
        elseif($tab=='website'){
            $mid=11;
        }
        $this->assign('mid',$mid);

        if($tpl=='select_type'){
            $type_tree = model('Type')->getCache('type_tree');
            $this->assign('type_tree',$type_tree);
        }
        elseif($tpl =='select_level'){
            $level_list = [1,2,3,4,5,6,7,8,9];
            $this->assign('level_list',$level_list);
        }

        $this->assign('refresh',$refresh);
        $this->assign('url',$url);
        $this->assign('tab',$tab);
        $this->assign('col',$col);
        $this->assign('ids',$ids);
        $this->assign('val',$val);
        return $this->fetch( 'admin@public/'.$tpl);
    }


    /** Overview gs7 (ADS-BRIEF H7): at least one ad of the Ads manager is on (examples never count). */
    private static function jcxItemOn()
    {
        if (!is_file(APP_PATH . 'extra/jcx.php')) {
            return false;
        }
        try {
            return (bool)Db::name('jcx_item')->where('item_status', 1)->where('item_example', 0)->field('item_id')->find();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
