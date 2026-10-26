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
use think\Controller;
use app\common\controller\All;
use think\Cache;

class Base extends All
{
    var $_admin;
    var $_pagesize;
    var $_makesize;

    public function __construct()
    {
        parent::__construct();

        // Send cache-control headers for admin responses.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        header('CDN-Cache-Control: no-store');
        header('Cloudflare-CDN-Cache-Control: no-store');
        header('Surrogate-Control: no-store');
        header('X-Accel-Expires: 0');


        // Check whether the administrator is logged in.
        if(in_array($this->_cl,['Index']) && in_array($this->_ac,['login','captcha'])) {

        }
        elseif(ENTRANCE=='api' && in_array($this->_cl,['Timming']) && in_array($this->_ac,['index'])){

        }
        else {
            $res = model('Admin')->checkLogin();
            if ($res['code'] > 1) {
                return $this->redirect('index/login');
            }
            $this->_admin = $res['info'];
            $this->_pagesize = $GLOBALS['config']['app']['pagesize'];
            $this->_makesize = $GLOBALS['config']['app']['makesize'];

            // Lock screen (ADMIN-IA-BRIEF 4.6, F1/B2): the flag lives in the server session, so every tab,
            // new window and AJAX call is locked until index/unlocked checks the password against the DB row.
            if (session('admin_locked') && !$this->lockExempt()) {
                if ($this->request->isAjax()) {
                    throw new \think\exception\HttpResponseException(json(['code' => 1001, 'msg' => lang('admin/ux/lock/title'), 'locked' => 1]));
                }
                $this->assign('cl', $this->_cl);
                $this->assign('admin', $this->_admin);
                $this->assign('title', lang('admin/ux/lock/title'));
                throw new \think\exception\HttpResponseException(\think\Response::create($this->fetch('admin@index/lock')));
            }
            // REVIEW-admin-security-r3 P3-4: the old Update controller is no longer exempt from the permission check.
            if(!$this->check_auth($this->_cl,$this->_ac)){
                return $this->error(lang('permission_denied'));
            }
        }
        $this->assign('cl',$this->_cl);
        $this->assign('MAC_VERSION',config('version')['code']);
        $this->edgeWatchSettings();
    }

    /**
     * Contract C5 (EdgeCache): a theme or settings change starts a new page-cache generation, so visitors get pages of the
     * new theme / settings at once ("Use this theme", every settings page; Clear cache already did). Any admin request
     * that rewrote application/extra/maccms.php bumps the generation once, after the response.
     */
    private function edgeWatchSettings()
    {
        if (!class_exists('\app\common\util\EdgeCache')) {
            return;
        }
        $file = APP_PATH . 'extra/maccms.php';
        $sig = function () use ($file) {
            clearstatcache(true, $file);
            return is_file($file) ? filemtime($file) . ':' . filesize($file) . ':' . md5_file($file) : '';
        };
        $before = $sig();
        register_shutdown_function(function () use ($sig, $before) {
            if ($sig() !== $before) {
                try { \app\common\util\EdgeCache::bumpGeneration(true); } catch (\Throwable $e) { }
            }
        });
    }

    // r7 repair (sweep a): an internal permission helper, not a route (it was reachable as <controller>/check_auth).
    protected function check_auth($c,$a)
    {
        $c = strtolower($c);
        $a = strtolower($a);

        // Always allowed (ADMIN-IA-BRIEF P3): the shell, the Overview and the signed-in admin's own account page.
        // Lock / unlock and sign out act only on the admin's own session (REVIEW-admin-security-r3 P2-4): every
        // admin may lock the screen and sign out, whatever the role ticks.
        $auths = $this->_admin['admin_auth'] . ',index/index,index/welcome,index/account,index/unlocked,index/logout,';
        $cur = ','.$c.'/'.$a.',';
        if($this->_admin['admin_id'] =='1'){
            return true;
        }
        elseif(strpos($auths,$cur)===false){
            return false;
        }
        else{
            return true;
        }
    }

    /** Requests that still work while the screen is locked. */
    protected function lockExempt()
    {
        $cl = strtolower((string) $this->_cl);
        $ac = strtolower((string) $this->_ac);
        return $cl === 'index' && in_array($ac, ['unlocked', 'logout', 'login', 'captcha'], true);
    }

    public function _cache_clear()
    {
        // r7 repair (sweep a): the Clear cache engine is reached through index/clear (POST + admin token), the update
        // steps and the scheduled task. As a public method it was also a route: any GET <controller>/_cache_clear.html
        // emptied the compiled templates. Called as a route it now answers 404 and changes nothing. (It stays public:
        // tests/housekeeping.php reads this method by its signature.)
        if (strtolower((string)$this->request->action()) === '_cache_clear') {
            throw new \think\exception\HttpException(404, 'Not Found');
        }
        if(ENTRANCE=='admin') {
            // Player configuration cache
            $vodplayer = config('vodplayer');
            $voddowner = config('voddowner');
            $vodserver = config('vodserver');
            $player = [];
            foreach ($vodplayer as $k => $v) {
                $player[$k] = [
                    'show' => (string)$v['show'],
                    'des' => (string)$v['des'],
                    'ps' => (string)$v['ps'],
                    'parse' => (string)$v['parse'],
                ];
            }
            $downer = [];
            foreach ($voddowner as $k => $v) {
                $downer[$k] = [
                    'show' => (string)$v['show'],
                    'des' => (string)$v['des'],
                    'ps' => (string)$v['ps'],
                    'parse' => (string)$v['parse'],
                ];
            }

            $server = [];
            foreach ($vodserver as $k => $v) {
                $server[$k] = [
                    'show' => (string)$v['show'],
                    'des' => (string)$v['des']
                ];
            }
            $content = 'MacPlayerConfig.player_list=' . json_encode($player) . ',MacPlayerConfig.downer_list=' . json_encode($downer) . ',MacPlayerConfig.server_list=' . json_encode($server) . ';';
            $path = './static/js/playerconfig.js';
            if (!file_exists($path)) {
                $path .= '.bak';
            }
            $fc = @file_get_contents($path);
            if(!empty($fc)){
	            $jsb = mac_get_body($fc, '// Caching starts', '// End of cache');
	            $fc = str_replace($jsb, "\r\n" . $content . "\r\n", $fc);
	            @fwrite(fopen('./static/js/playerconfig.js', 'wb'), $fc);
            }
        }

        // Do not drop the entire hot public cache on a busy production site.
        // Doing so sends all traffic to PHP/MySQL simultaneously and can make
        // the origin unavailable (Cloudflare 521). Refresh the small control
        // caches and compiled templates; public entries expire by their TTL.
        $prefix = $GLOBALS['config']['app']['cache_flag'];
        foreach (['type_list', 'type_tree', 'group_list', 'data_count', 'data_count_recycle_v1',
                  'interface_type', 'vod_catalog_revision'] as $key) {
            Cache::rm($prefix . '_' . $key);
        }
        try {
            model('Vod')->clearListCache();
        } catch (\Throwable $_e) {}
        Cache::rm('cache_data');
        // r5 repair (beginner r5 P2-1): new names for the public category lists (home "Browse categories" etc.).
        \app\common\model\Type::bumpListRevision();
        // Overview numbers and the top-bar to-do count (ADMIN-IA-BRIEF 4.7).
        Cache::rm('ux_overview_stats');
        // Compiled templates: runtime/temp is emptied, never through a symlink, Windows junction or hard link at any
        // depth (a recursive directory iterator follows them and deleted files outside runtime). A linked runtime/temp
        // is left alone; a link inside it is removed by its own name and never entered. The real folder itself stays.
        $temp = rtrim(RUNTIME_PATH, '/\\') . DIRECTORY_SEPARATOR . 'temp';
        $this->_temp_wipe($temp);
        // Ads (P2-04): a fresh build, and from the admin button a page-cache re-key (the scheduled task builds only).
        if (class_exists('\app\common\util\JcxBuild') && \app\common\util\JcxBuild::ready()) { try { \app\common\util\JcxBuild::refreshAll(ENTRANCE == 'admin'); } catch (\Throwable $e) {} }
        // Bounded self-maintenance pass (DB-SLIM): expired file-cache entries, orphan compiled templates, old logs,
        // old automatic settings snapshots, small DB retention. Time-boxed; never touches owner content.
        \app\common\util\Housekeeping::adminPass(3000);
        return $this->_temp_ready($temp);
    }

    /**
     * True when $dir is a real folder: not a symlink and not a Windows junction. is_link() is false for a junction, so
     * realpath() must lead back to the folder's own name in its parent's real path (the runtime folder above may itself
     * be a link: a supported setup).
     */
    private function _temp_real_dir($dir)
    {
        if (!is_dir($dir) || is_link($dir)) { return false; }
        $real = @realpath($dir);
        $parent = @realpath(dirname($dir));
        if ($real === false || $parent === false) { return false; }
        $own = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . basename($dir);
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($real, $own) === 0 : $real === $own;
    }

    /**
     * Empties the real folder $dir; false (nothing deleted) when $dir is not one. Only real sub-folders are entered; any
     * other entry is removed by its own name: unlink() removes a file, a hard link (only this name) or a file symlink,
     * rmdir() a junction or directory symlink, dangling or not; neither touches what a link points to.
     */
    private function _temp_wipe($dir)
    {
        if (!$this->_temp_real_dir($dir)) { return false; }
        foreach ((array)@scandir($dir) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..') { continue; }
            $p = $dir . DIRECTORY_SEPARATOR . $name;
            if ($this->_temp_real_dir($p)) {
                if ($this->_temp_wipe($p)) { @rmdir($p); }
            } elseif (!@unlink($p)) {
                @rmdir($p);
            }
        }
        return true;
    }

    /**
     * runtime/temp is usable: anything that resolves to a folder is left as it is; it is made only where nothing at all
     * has that name (mkdir() through a dangling junction creates its target outside runtime on PHP 7.4). The new folder
     * is made under a random name and renamed into place: rename() never follows a link planted meanwhile.
     */
    private function _temp_ready($temp)
    {
        $real = @realpath($temp);
        if ($real !== false) { return is_dir($real); }
        if (is_link($temp) || file_exists($temp)) { return false; }
        $parent = dirname($temp);
        if (!is_dir($parent)) { return false; }
        $name = basename($temp);
        foreach ((array)@scandir($parent) as $n) {
            if (is_string($n) && (DIRECTORY_SEPARATOR === '\\' ? strcasecmp($n, $name) === 0 : $n === $name)) { return false; }
        }
        $tmp = $temp . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (!@mkdir($tmp, 0755)) { return false; }
        if (@rename($tmp, $temp)) { return true; }
        @rmdir($tmp);
        return $this->_temp_real_dir($temp);
    }

    /**
     * Return a fresh form token after a validated admin POST fails.
     * Token validation consumes the session token before controller/model
     * business checks run; returning a replacement keeps the form retryable.
     */
    protected function ajaxErrorWithFreshToken($msg, $data = [])
    {
        $token = \think\Request::instance()->token('__token__');
        if (!is_array($data)) {
            $data = [];
        }
        $data['__token__'] = $token;
        return $this->error($msg, null, $data);
    }

    /**
     * Return a fresh form token after a successful admin POST.
     * Token validation consumes the submitted session token, so the next
     * save in the same editor must receive a replacement token.
     */
    protected function ajaxSuccessWithFreshToken($msg, $data = [])
    {
        $token = \think\Request::instance()->token('__token__');
        if (!is_array($data)) {
            $data = [];
        }
        $data['__token__'] = $token;
        return $this->success($msg, null, $data);
    }

}
