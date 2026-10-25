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
use think\Db;
use think\View;

class Make extends Base
{
    use \app\admin\common\RunKey;

    var $_param;

    public function __construct()
    {
        // header(\'X-Accel-Buffering: no\');
        $this->_param = input();
        $GLOBALS['ismake'] = '1';

        if(isset($this->_param['ac2']) && $this->_param['ac2']=='wap'){
            $TMP_TEMPLATEDIR = $GLOBALS['config']['site']['mob_template_dir'];
            $TMP_HTMLDIR = $GLOBALS['config']['site']['mob_html_dir'];
            $TMP_ADSDIR = $GLOBALS['config']['site']['mob_ads_dir'];
            $GLOBALS['MAC_ROOT_TEMPLATE'] = ROOT_PATH .'template/'.$TMP_TEMPLATEDIR.'/'. $TMP_HTMLDIR .'/';
            $GLOBALS['MAC_PATH_TEMPLATE'] = MAC_PATH.'template/'.$TMP_TEMPLATEDIR.'/';
            $GLOBALS['MAC_PATH_TPL'] = $GLOBALS['MAC_PATH_TEMPLATE']. $TMP_HTMLDIR  .'/';
            $GLOBALS['MAC_PATH_ADS'] = $GLOBALS['MAC_PATH_TEMPLATE']. $TMP_ADSDIR  .'/';
            config('template.view_path', 'template/' . $TMP_TEMPLATEDIR .'/' . $TMP_HTMLDIR .'/');
        }
        parent::__construct();
    }

    protected function buildHtml($htmlfile='',$htmlpath='',$templateFile='') {
        if(empty($htmlfile) || empty($htmlpath) || empty($templateFile)){
            return false;
        }
        $content    =   $this->label_fetch($templateFile);
        $htmlfile = reset_html_filename($htmlfile);
        $dir   =  dirname($htmlfile);
        if(!is_dir($dir)){
            mkdir($dir,0777,true);
        }
        if(file_put_contents($htmlfile,$content) === false) {
            return false;
        } else {
            return true;
        }
    }

    protected function echoLink($des,$url='',$color='',$wrap=1)
    {
        $this->rawHead();
        // V7: no <font> colours; an error line uses the kit danger text class.
        $open = $color === '' ? '<span>' : '<span class="ax-danger-text">';
        if(empty($url)){
            echo  $open .$des .'</span>'. ($wrap==1? '<br>':'&nbsp;');
        }
        else{
            echo  '<a target="_blank" href="'. $url .'">'. $open . $des .'</span></a>'. ($wrap==1? '<br>':'&nbsp;');
        }
        ob_flush();flush();
    }

    /** V7: generator output uses the kit raw-output layout (printed once per request, admin only). */
    protected function rawHead()
    {
        static $done = false;
        if ($done || ENTRANCE !== 'admin' || Request()->isAjax()) {
            return;
        }
        $done = true;
        $css = MAC_PATH . 'static/css/admin_ux.css?v=' . (int)@filemtime(ROOT_PATH . 'static/css/admin_ux.css');
        mac_echo('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">'
            . '<body class="admin-studio admin-dark ax-raw-output">');
    }

    /** F34: true when static pages of this kind are off (dynamic mode), so the generator must not write a file. */
    protected function staticOff($kind)
    {
        return (int)($GLOBALS['config']['view'][$kind] ?? 0) < 2;
    }

    /** F34 / F35: refuse without a success link. AJAX gets JSON, the admin a message page, a timer a text line. */
    protected function makeFail($msg)
    {
        if (Request()->isAjax()) {
            return json(['code' => 0, 'msg' => $msg]);
        }
        if (ENTRANCE === 'admin') {
            return $this->error($msg, url('make/opt'));
        }
        mac_echo(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'));
        return null;
    }

    /**
     * r7 repair (sweep: static pages). The page generators write HTML files into the site and set the *_time_make
     * columns. From the admin entry, step 1 must be a POST with the admin token (Static pages form, the "Make page"
     * buttons of the lists); it starts a run key in the session (RunKey, job "make"). The generator's own next steps are
     * GET jump links that carry run=<key> (makeJump adds it). A GET without a valid key writes nothing and says the page
     * is out of date. The scheduled task (api.php, ENTRANCE=api) is not gated here.
     */
    protected function makeGate()
    {
        static $passed = false;
        if ($passed || !defined('ENTRANCE') || ENTRANCE !== 'admin') {
            return true;
        }
        if (request()->isPost()) {
            $run = $this->runKeyStart('make', []);
            if ($run === false) {
                return $this->makeFail(lang('admin/ux/system/token_expired'));
            }
            $this->_param['run'] = $run;
            unset($this->_param['__token__']);
        } elseif ($this->runKeyResume('make', is_array($this->_param) ? $this->_param : []) === false) {
            return $this->makeFail(lang('admin/ux/system/token_expired'));
        }
        $passed = true;
        return true;
    }

    /** r7 repair: mac_jump() for the generator chain; a next step inside make/* carries the run key of this job. */
    protected function makeJump($url, $sec = 3)
    {
        $run = isset($this->_param['run']) && is_string($this->_param['run']) ? $this->_param['run'] : '';
        $url = (string)$url;
        if ($run !== '' && defined('ENTRANCE') && ENTRANCE === 'admin' && strpos($url, 'run=') === false
            && preg_match('#/make/(make|index|map|rss|type|topic_index|topic_info|info|label)\b#', $url)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'run=' . rawurlencode($run);
        }
        mac_jump($url, $sec);
    }

    public function opt()
    {
        // E.1 tab "Sitemap": the sitemap settings are saved here (POST + token).
        if ($this->request->isPost()) {
            return $this->seoSitemapSave();
        }
        $sitemapUrl = '';
        $sitemapError = '';
        try {
            $sitemapUrl = \app\common\util\Sitemap::indexUrl();
        } catch (\RuntimeException $e) {
            $sitemapError = lang('admin/make/sitemap_unavailable');
        }
        $this->assign('sitemap_url', $sitemapUrl);
        $this->assign('sitemap_error', $sitemapError);
        // List of categories
        $type_list = model('Type')->getCache('type_list');
        $this->assign('type_list',$type_list);

        $vod_type_list = [];
        $vod_type_ids = [];
        $art_type_list = [];
        $art_type_ids = [];
        foreach($type_list as $k=>$v){
            if($v['type_mid'] == 1){
                $vod_type_list[$k] = $v;
            }
            if($v['type_mid'] == 2){
                $art_type_list[$k] = $v;
            }
        }
        $vod_type_ids = array_keys($vod_type_list);
        $art_type_ids = array_keys($art_type_list);

        $this->assign('vod_type_list',$vod_type_list);
        $this->assign('art_type_list',$art_type_list);

        $this->assign('vod_type_ids',join(',',$vod_type_ids));
        $this->assign('art_type_ids',join(',',$art_type_ids));



        // Category IDs for videos updated today
        $res = model('Vod')->updateToday('type');
        $this->assign('vod_type_ids_today',$res['data']);

        // Category IDs for articles updated today
        $res = model('Art')->updateToday('type');
        $this->assign('art_type_ids_today',$res['data']);


        // List of topics
        $where = [];
        $where['topic_status'] = ['eq',1];
        $order = 'topic_id desc';
        $topic_list = model('Topic')->listData($where,$order,1,999);
        $this->assign('topic_list',$topic_list['list']);
        $topic_ids = join(',',array_keys($topic_list['list']));
        $this->assign('topic_ids',$topic_ids);

        // Custom page templates
        $label_list = [];
        $path = $GLOBALS['MAC_ROOT_TEMPLATE'] .'label';
        if(is_dir($path)){
            $farr = glob($path.'/*');
            foreach($farr as $f){
                if(is_file($f)){
                    $f = str_replace($path."/","",$f);
                    $label_list[] = $f;
                }
            }
            unset($farr);
        }
        // xss-fix-A-2: label file names are printed inside onclick strings; keep plain file names only.
        $label_list = array_values(array_filter($label_list, function ($f) { return (bool)preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $f); }));
        $this->assign('label_list',$label_list);
        $this->assign('label_ids',join(',',$label_list));


        // O16: the static generators sit in a collapsed box; it says so when every static mode is off.
        $staticAny = false;
        foreach ((array)($GLOBALS['config']['view'] ?? []) as $mode) {
            if ((int)$mode >= 2) { $staticAny = true; break; }
        }
        $this->assign('static_any', $staticAny);
        $this->assign('seo_opt', $this->seoOptView());
        $this->assign('seo_js', self::seoJs());
        $title = lang('admin/ux/menu/sitemap_robots');
        $this->assign('title', $title === 'admin/ux/menu/sitemap_robots' ? lang('admin/ux/seo/sitemap_title') : $title);
        return $this->fetch('admin@make/opt');

    }

    // ================================================================== Sitemap & robots.txt, IndexNow (SEO-ADMIN-BRIEF E.1-E.3, item seo-admin)

    /** Decode a posted value once (posted values are read with input('post.', [], '') here: raw text). */
    private static function seoIn($value)
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /** Write maccms.php with $next (the whole settings array); false on failure. */
    private static function seoWriteConfig(array $next)
    {
        if (mac_arr2file(APP_PATH . 'extra/maccms.php', $next) === false) {
            return false;
        }
        if (function_exists('opcache_invalidate')) { @opcache_invalidate(APP_PATH . 'extra/maccms.php', true); }
        \think\Config::set('maccms', $next);
        $GLOBALS['config'] = $next;
        return true;
    }

    /** Store validated seo.* values (flat keys); false on a write error. */
    private function seoStore(array $values)
    {
        $old = config('maccms');
        $next = $old;
        $next['seo'] = array_merge((array)($old['seo'] ?? []), $values);
        if ($next === $old) {
            return true;
        }
        $ok = self::seoWriteConfig($next);
        if ($ok) {
            \think\Cache::rm('seo_check_' . (int)($this->_admin['admin_id'] ?? 0));
        }
        return $ok;
    }

    /** Strings for static/js/admin_seo.js. */
    private static function seoJs()
    {
        $out = [];
        foreach (['copied', 'robots_block_confirm', 'robots_block_title', 'indexnow_newkey_confirm', 'indexnow_newkey', 'indexnow_key_ok',
                     'indexnow_key_missing', 'chip_ok', 'chip_warn'] as $k) {
            $out[$k] = lang('admin/ux/seo/' . $k);
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /** E.1 tab "Sitemap": save the three sitemap settings (POST + token; permission make/opt = the page). */
    protected function seoSitemapSave()
    {
        $post = input('post.', [], '');
        if (!\think\Loader::validate('Token')->check($post)) {
            return $this->ajaxErrorWithFreshToken(lang('illegal_request'), ['retry' => 1]);
        }
        $seo = isset($post['seo']) && is_array($post['seo']) ? $post['seo'] : [];
        $values = [];
        $labels = ['sitemap_langs' => 'sitemap_langs', 'sitemap_video' => 'sitemap_video', 'sitemap_per_file' => 'sitemap_per_file'];
        foreach ($labels as $key => $label) {
            if (!array_key_exists($key, $seo)) { continue; }
            $r = \app\common\util\SeoSettings::validate('seo.' . $key, self::seoIn($seo[$key]));
            if (!$r['ok']) {
                $msg = $key === 'sitemap_per_file' ? lang('admin/ux/seo/err_range', [lang('admin/ux/seo/sitemap_per_file'), '1,000', '50,000'])
                    : lang('admin/ux/seo/err_format', [lang('admin/ux/seo/' . $label)]);
                return $this->ajaxErrorWithFreshToken($msg);
            }
            $values[$key] = $r['store'];
        }
        if (!$this->seoStore($values)) {
            return $this->ajaxErrorWithFreshToken(lang('save_err'));
        }
        return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
    }

    /** E.2: GET ?preview=1 → the exact robots.txt for unsaved rules (nothing saved); POST + token → save the rules. */
    public function robots()
    {
        $S = '\app\common\util\SeoSettings';
        if ($this->request->isGet() && (string)input('get.preview', '') === '1') {
            $mode = (string)input('get.mode', '', null);
            $mode = in_array($mode, ['recommended', 'open', 'custom'], true) ? $mode : null;
            $custom = input('get.custom', null, null);
            $custom = is_string($custom) ? $custom : null;
            $check = $custom !== null ? $S::robotsCustom($custom) : ['blocks_all' => false];
            return json(['code' => 1, 'msg' => '', 'data' => ['text' => \app\index\controller\Robots::text($mode, $custom),
                'blocks_all' => $mode === 'custom' && !empty($check['blocks_all'])]]);
        }
        if (!$this->request->isPost()) {
            return $this->error(lang('illegal_request'));
        }
        $post = input('post.', [], '');
        if (!\think\Loader::validate('Token')->check($post)) {
            return $this->ajaxErrorWithFreshToken(lang('illegal_request'), ['retry' => 1]);
        }
        $mode = self::seoIn($post['robots_mode'] ?? '');
        if (!in_array($mode, ['recommended', 'open', 'custom'], true)) {
            return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/err_format', [lang('admin/ux/seo/robots_rules')]));
        }
        $values = ['robots_mode' => $mode];
        if (array_key_exists('robots_custom', $post)) {
            $check = $S::robotsCustom(self::seoIn($post['robots_custom']));
            if (!$check['ok']) {
                if ($mode === 'custom') {
                    return $this->ajaxErrorWithFreshToken($check['error'] === 'too_long' ? lang('admin/ux/seo/robots_too_long')
                        : lang('admin/ux/seo/robots_line_err', [$check['line']]));
                }
            } else {
                // E.2: a rule set that blocks the whole site needs the typed word BLOCK.
                if ($mode === 'custom' && $check['blocks_all'] && (string)($post['block_confirm'] ?? '') !== 'BLOCK') {
                    return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/robots_block_confirm'), ['need_confirm' => 'BLOCK']);
                }
                $values['robots_custom'] = $check['value'];
            }
        }
        if (!$this->seoStore($values)) {
            return $this->ajaxErrorWithFreshToken(lang('save_err'));
        }
        return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
    }

    /** E.3: IndexNow switch, "Send now" and "Make a new key" (POST + token; permission make/indexnow). */
    public function indexnow()
    {
        if (!$this->request->isPost()) {
            return $this->error(lang('illegal_request'));
        }
        $post = input('post.', [], '');
        if (!\think\Loader::validate('Token')->check($post)) {
            return $this->ajaxErrorWithFreshToken(lang('illegal_request'), ['retry' => 1]);
        }
        $P = '\app\common\util\SeoPing';
        $do = self::seoIn($post['do'] ?? '');
        $key = $P::key();
        if ($do === 'on' || $do === 'newkey') {
            try { $keyUrl = $P::keyUrl($key !== '' ? $key : str_repeat('0', 32)); } catch (\Throwable $e) { $keyUrl = ''; }
            if ($keyUrl === '') {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/indexnow_need_domain'));
            }
            if ($do === 'newkey' && $key !== '') {
                $P::removeKeyFile($key);
                $key = '';
            }
            $notice = '';
            for ($try = 0; $try < 2; $try++) {
                if ($key === '') { $key = $P::newKey(); }
                $file = $P::writeKeyFile($key);
                if ($file['ok']) { break; }
                if ($file['error'] === 'foreign') { $key = ''; continue; }
                $notice = lang('admin/ux/seo/indexnow_key_ro');
                break;
            }
            $values = ['indexnow_key' => $key];
            if ($do === 'on') {
                $values['indexnow'] = 1;
                $values['indexnow_since'] = time();
            }
            if (!$this->seoStore($values)) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            $msg = lang($do === 'on' ? 'admin/ux/seo/indexnow_switched_on' : 'admin/ux/seo/indexnow_newkey_ok');
            return $this->ajaxSuccessWithFreshToken(trim($msg . ' ' . $notice));
        }
        if ($do === 'off') {
            if (!$this->seoStore(['indexnow' => 0])) {
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            return $this->ajaxSuccessWithFreshToken(lang('admin/ux/seo/indexnow_switched_off'));
        }
        if ($do === 'send') {
            $res = $P::flush();
            $status = $P::status();
            if ((int)$res['sent'] > 0) {
                return $this->ajaxSuccessWithFreshToken(lang('admin/ux/seo/indexnow_sent', [number_format((int)$status['queue'])]));
            }
            $map = ['off' => 'indexnow_switched_off', 'empty' => 'indexnow_nothing', 'interval' => 'indexnow_wait', 'daily' => 'indexnow_wait',
                'busy' => 'indexnow_wait', 'backoff' => 'indexnow_rate', 'stopped' => $status['error'] === 'host_err' ? 'indexnow_host_err' : 'indexnow_key_err',
                'no_host' => 'indexnow_need_domain'];
            $skip = (string)$res['skipped'];
            if ($skip === '' ) {
                $err = (string)$status['error'];
                $text = $err === 'rate' ? 'indexnow_rate' : ($err === 'key_err' ? 'indexnow_key_err' : ($err === 'host_err' ? 'indexnow_host_err' : 'indexnow_network'));
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/' . $text));
            }
            return $this->ajaxErrorWithFreshToken(lang('admin/ux/seo/' . ($map[$skip] ?? 'indexnow_network')));
        }
        return $this->ajaxErrorWithFreshToken(lang('illegal_request'));
    }

    /** Values of the three tabs of make/opt. */
    protected function seoOptView()
    {
        $S = '\app\common\util\SeoSettings';
        $stats = ['files' => 0, 'videos' => 0, 'languages' => 0];
        try { $stats = \app\common\util\Sitemap::stats(); } catch (\Throwable $e) { /* no domain yet */ }
        $robotsText = '';
        try { $robotsText = \app\index\controller\Robots::text(); } catch (\Throwable $e) { $robotsText = ''; }
        $status = \app\common\util\SeoPing::status();
        $key = \app\common\util\SeoPing::key();
        $keyUrl = '';
        if ($key !== '') { try { $keyUrl = \app\common\util\SeoPing::keyUrl($key); } catch (\Throwable $e) { $keyUrl = ''; } }
        $last = '';
        if ((int)$status['last_post'] > 0) {
            $last = lang('admin/ux/seo/indexnow_last', [(int)$status['last_code'] ?: '-', date('Y-m-d H:i', (int)$status['last_post'])]);
        }
        $errors = ['key_err' => 'indexnow_key_err', 'host_err' => 'indexnow_host_err', 'rate' => 'indexnow_rate', 'network' => 'indexnow_network'];
        $error = isset($errors[(string)$status['error']]) ? lang('admin/ux/seo/' . $errors[(string)$status['error']]) : '';
        return [
            'langs' => (int)$S::get('seo.sitemap_langs'),
            'video' => (int)$S::get('seo.sitemap_video'),
            'per_file' => (int)$S::get('seo.sitemap_per_file'),
            'count' => lang('admin/ux/seo/sitemap_count', [number_format((int)$stats['files'] + (int)($stats['language_files'] ?? 0)),
                number_format((int)$stats['videos']), number_format((int)$stats['languages'])]),
            'hidden' => $S::bool('seo.hide_site'),
            'robots_mode' => (string)$S::get('seo.robots_mode'),
            'robots_custom' => (string)$S::get('seo.robots_custom'),
            'robots_text' => $robotsText,
            'robots_root' => \app\index\controller\Robots::rootFile(),
            'can_robots' => $this->check_auth('make', 'robots'),
            'can_indexnow' => $this->check_auth('make', 'indexnow'),
            'indexnow' => (int)$S::get('seo.indexnow') === 1,
            'key' => $key,
            'key_url' => $keyUrl,
            'key_state' => $status['key_file'],
            'last' => $last,
            'today' => lang('admin/ux/seo/indexnow_today', [number_format((int)$status['sent_today'])]),
            'queue' => lang('admin/ux/seo/indexnow_queue', [number_format((int)$status['queue'])]),
            'error' => $error,
        ];
    }

    public function make($pp=[])
    {
        // r7 repair: $pp is only for the scheduled task (api.php Timming calls make($output) in the same process). From
        // the admin entry it is never trusted (the framework could bind it from the URL as pp[...]).
        if(!empty($pp) && is_array($pp) && defined('ENTRANCE') && ENTRANCE !== 'admin'){
            $this->_param = $pp;
        }

        if(isset($this->_param['ac'], $this->_param['ac2']) && $this->_param['ac'] === 'rss' && $this->_param['ac2'] === 'google'){
            try {
                $sitemapUrl = \app\common\util\Sitemap::indexUrl();
            } catch (\RuntimeException $e) {
                return $this->error(lang('admin/make/sitemap_unavailable'));
            }
            return $this->redirect($sitemapUrl);
        }

        $gate = $this->makeGate();
        if ($gate !== true) {
            return $gate;
        }
        if($this->_param['ac'] == 'index'){
            return $this->index();
        }
        elseif($this->_param['ac'] == 'map'){
            return $this->map();
        }
        elseif($this->_param['ac'] == 'rss'){
            return $this->rss();
        }
        $this->rawHead();
        if($this->_param['ac'] == 'type'){
            $this->type();
        }
        elseif($this->_param['ac'] == 'topic_index'){
            $this->topic_index();
        }
        elseif($this->_param['ac'] == 'topic_info'){
            $this->topic_info();
        }
        elseif($this->_param['ac'] == 'rss'){
            $this->rss();
        }
        elseif($this->_param['ac'] == 'info'){
            $this->info();
        }
        elseif($this->_param['ac'] == 'label'){
            $this->label();
        }
    }

    public function index()
    {
        $gate = $this->makeGate();
        if ($gate !== true) {
            return $gate;
        }
        // F34: a root index.html written in dynamic mode would freeze the homepage.
        if ($this->staticOff('index')) {
            return $this->makeFail(lang('admin/ux/system/static_off'));
        }
        $this->rawHead();

        $GLOBALS['aid'] = mac_get_aid('index');

        $link = 'index.html';
        if($this->_param['ac2']=='wap'){
            $link = 'wap_index.html';
        }
        $this->label_maccms();

        $this->buildHtml($link,'./', 'index/index');
        $this->echoLink($link,'/'.$link);
        if(ENTRANCE=='admin'){
            $this->makeJump( url('make/opt'),3 );
        }
        exit;
    }

    public function map()
    {
        // O16: "Rebuild sitemap" on the Sitemap page (AJAX, result line in the page, no redirect).
        if (ENTRANCE === 'admin' && Request()->isAjax()) {
            // r7 repair (sweep): the rebuild drops the sitemap cache (or writes map.html): POST + the admin token.
            if (!$this->runKeyTokenOk()) {
                return json(['code' => 0, 'msg' => lang('admin/ux/system/token_expired')]);
            }
            if ($this->staticOff('map')) {
                // Dynamic mode: the sitemap is built on request and cached; drop that cache and build the index again.
                \think\Cache::tag('vod')->clear();
                try {
                    \app\common\util\Sitemap::indexFeed();
                    $url = \app\common\util\Sitemap::indexUrl();
                } catch (\Throwable $e) {
                    return json(['code' => 0, 'msg' => lang('admin/make/sitemap_unavailable')]);
                }
                return json(['code' => 1, 'msg' => lang('admin/ux/system/sitemap_rebuilt', [date('Y-m-d H:i')]), 'url' => $url]);
            }
            $GLOBALS['aid'] = mac_get_aid('map');
            $this->label_maccms();
            $ok = $this->buildHtml('map.html', './', 'map/index');
            clearstatcache(true, './map.html');
            if (!$ok || !is_file('./map.html') || (int)filesize('./map.html') === 0) {
                return json(['code' => 0, 'msg' => lang('save_err')]);
            }
            return json(['code' => 1, 'msg' => lang('admin/ux/system/sitemap_rebuilt', [date('Y-m-d H:i')]), 'url' => MAC_PATH . 'map.html']);
        }
        $gate = $this->makeGate();
        if ($gate !== true) {
            return $gate;
        }
        // F34: the static map.html is not needed (and is not written) in dynamic mode.
        if ($this->staticOff('map')) {
            return $this->makeFail(lang('admin/ux/system/static_off'));
        }
        $this->rawHead();
        $GLOBALS['aid'] = mac_get_aid('map');
        $this->label_maccms();
        $link = 'map.html';
        $this->buildHtml($link,'./','map/index');
        $this->echoLink($link,'/'.$link);
        if(ENTRANCE=='admin') {
            $this->makeJump(url('make/opt'), 3);
        }
        exit;
    }

    public function rss()
    {
        if(isset($this->_param['ac2']) && $this->_param['ac2'] === 'google'){
            try {
                $sitemapUrl = \app\common\util\Sitemap::indexUrl();
            } catch (\RuntimeException $e) {
                return $this->error(lang('admin/make/sitemap_unavailable'));
            }
            return $this->redirect($sitemapUrl);
        }

        // Baidu, 360 (so), Sogou and Shenma sitemaps are China-only and not part of the global distribution.
        if(!in_array($this->_param['ac2'] ?? '', ['index','google','bing'], true)){
            return $this->error(in_array($this->_param['ac2'] ?? '', ['baidu','so','sogou','sm'], true) ? lang('admin/module_removed') : lang('param_err'));
        }
        $gate = $this->makeGate();
        if ($gate !== true) {
            return $gate;
        }
        // F34: RSS / sitemap XML files are static files; in dynamic mode the dynamic feeds are used.
        if ($this->staticOff('rss')) {
            return $this->makeFail(lang('admin/ux/system/static_off'));
        }
        $this->rawHead();
        if(empty(intval($this->_param['ps']))){
            $this->_param['ps'] = 1;
        }

        $GLOBALS['aid'] = mac_get_aid('rss');
        $this->label_maccms();
        for($i=1;$i<=$this->_param['ps'];$i++){
            $par =[];
            if($i>=1){
                $par['page'] = $i;
                $_REQUEST['page'] = $i;
            }

            $link = 'rss/'.$this->_param['ac2'];
            if($par['page']>1){
                $link .= $GLOBALS['config']['path']['page_sp'] . $par['page'];
            }
            $link .='.xml';
            $this->buildHtml($link,'./','rss/'.$this->_param['ac2']);
            // F35: an empty file is an error, not a success link.
            clearstatcache(true, './' . $link);
            if (!is_file('./' . $link) || (int)filesize('./' . $link) === 0) {
                @unlink('./' . $link);
                $this->echoLink(lang('admin/ux/system/rss_empty'), '', 'red');
                exit;
            }
            $this->echoLink($link,'/'.$link);
        }
        if(ENTRANCE=='admin') {
            $this->makeJump(url('make/opt'), 3);
        }
        exit;
    }

    public function type()
    {
        $gate = $this->makeGate();
        if ($gate !== true) {
            return $gate;
        }
        if($this->_param['tab'] =='art'){
            $ids = $this->_param['arttype'];
            if(empty($ids) && $this->_param['ac2']=='day'){
                $res = model('Art')->updateToday('type');
                $ids = $res['data'];
            }
            $GLOBALS['mid'] = 2;
            $GLOBALS['aid'] = mac_get_aid('art','type');
        }
        else{
            $ids = $this->_param['vodtype'];
            if(empty($ids) && $this->_param['ac2']=='day'){
                $res = model('Vod')->updateToday('type');
                $ids = $res['data'];
            }
            $GLOBALS['mid'] = 1;
            $GLOBALS['aid'] = mac_get_aid('vod','type');
        }

        if($GLOBALS['config']['view'][$this->_param['tab'].'_type'] <2){
            mac_echo(lang('admin/make/view_model_static_err'));
            exit;
        }

        $num = intval($this->_param['num']);
        $start = intval($this->_param['start']);
        $page_count = intval($this->_param['page_count']);
        $page_size = intval($this->_param['page_size']);
        $data_count = intval($this->_param['data_count']);

        if(empty($ids)){
            return $this->error(lang('param_err'));
        }
        if(!is_array($ids)){
            $ids = explode(',',$ids);
        }
        if ($num>=count($ids)){
            if(empty($this->_param['jump'])){
                $this->echoLink(lang('admin/make/typepage_make_complete'));
                if(ENTRANCE=='admin') {
                    $this->makeJump(url('make/opt'), 3);
                }
                exit;
            }
            else{
                $this->echoLink(lang('admin/make/typepage_make_complete_later_make_index'));
                if(ENTRANCE=='admin') {
                    $this->makeJump(url('make/index', ['jump' => 1]), 3);
                }
                exit;
            }
        }
        if($start<1){
            $start=1;
        }

        $id = $ids[$num];
        $type_list = model('Type')->getCache('type_list');
        $type_info = $type_list[$id];

        if(empty($data_count)){
            $where = [];
            $where['type_id|type_id_1'] = ['eq',$id];

            if($this->_param['tab'] =='art') {
                $where['art_status'] = ['eq', 1];
                $data_count = model('Art')->countData($where);
                $html = mac_read_file($GLOBALS['MAC_ROOT_TEMPLATE'] . 'art/'.$type_info['type_tpl']);
                $labelRule = '{maccms:art(.*?)num="(.*?)"(.*?)paging="yes"([\s\S]*?)}([\s\S]*?){/maccms:art}';
            }
            else{
                $where['vod_status'] = ['eq', 1];
                $data_count = model('Vod')->countData($where);
                $html = mac_read_file($GLOBALS['MAC_ROOT_TEMPLATE'] . 'vod/'.$type_info['type_tpl']);
                $labelRule = '{maccms:vod(.*?)num="(.*?)"(.*?)paging="yes"([\s\S]*?)}([\s\S]*?){/maccms:vod}';
            }

            $labelRule = mac_buildregx($labelRule,"");
            preg_match_all($labelRule,$html,$arr);

            for($i=0;$i<count($arr[2]);$i++) {
                $page_size = $arr[2][$i];
                break;
            }
            if(empty($page_size)){
                $page_size = 20;
                $page_count=1;
            }
            else{
                $page_count = ceil($data_count / $page_size);
            }
            if($page_count<1){ $page_count=1; }

            $this->_param['data_count'] = $data_count;
            $this->_param['page_count'] = $page_count;
            $this->_param['page_size'] = $page_size;

            if($type_info['type_pid'] == 0){
                // $this->_param[\'page_count\'] = 1;
            }
        }

        if($start > $page_count){
            $this->_param['start'] = 1;
            $this->_param['num']++;
            $this->_param['data_count'] = 0;
            $this->_param['page_count'] = 0;
            $this->_param['page_size'] = 0;
            $url = url('make/make') .'?'. http_build_query($this->_param);

            $this->echoLink('【'.$type_info['type_name'].'】'.lang('admin/make/list_make_complate_later'));
            if(ENTRANCE=='admin') {
                $this->makeJump($url, 3);
            }
            exit;
        }

        $sec_count = ceil($page_count / $GLOBALS['config']['app']['makesize']);
        $sec = ceil($start / $GLOBALS['config']['app']['makesize']);
        $this->echoLink(lang('admin/make/type_tip',[$type_info['type_name'],$this->_param['page_count'],$sec_count,$sec]));
        $this->label_maccms();


        $n=1;
        for($i=$start;$i<=$page_count;$i++){
            $this->_param['start'] = $i;

            $_REQUEST['id'] = $id;
            $_REQUEST['page'] = $i;
            $this->label_type( $type_info['type_mid']==2 ? $GLOBALS['config']['view']['art_type'] : $GLOBALS['config']['view']['vod_type'] );
            $link = mac_url_type($type_info,['id'=>$id,'page'=>$i]);

            $this->buildHtml($link,'./', mac_tpl_fetch($this->_param['tab'],$type_info['type_tpl'],'type') );
            $this->echoLink(lang('page').' '.$i,$link);

            if($GLOBALS['config']['app']['makesize'] == $n){
                break;
            }
            $n++;
        }

        if(ENTRANCE=='api'){
            if ($num+1>=count($ids)) {
                mac_echo(lang('admin/make/type_timming_tip',[$GLOBALS['config']['app']['makesize']]));
                die;
            }
            else{
                $this->_param['start'] = 1;
                $this->_param['num']++;
                $this->_param['data_count'] = 0;
                $this->_param['page_count'] = 0;
                $this->_param['page_size'] = 0;
                $this->type();
            }
        }

        if($this->_param['start'] >= $this->_param['page_count']){
            $this->_param['start'] = 1;
            $this->_param['num']++;
            $this->_param['data_count'] = 0;
            $this->_param['page_count'] = 0;
            $this->_param['page_size'] = 0;
            $this->echoLink('【'.$type_info['type_name'].'】'.lang('admin/make/list_make_complate_later'));
        }
        elseif($this->_param['start'] < $this->_param['page_count']){
            $this->_param['start']++;

            $this->echoLink(lang('server_rest'));
        }
        $url = url('make/make') .'?'. http_build_query($this->_param);
        if(ENTRANCE=='admin') {
            $this->makeJump($url, 3);
        }
    }

    public function topic_index()
    {
        $gate = $this->makeGate();
        if ($gate !== true) {
            return $gate;
        }
        $num = intval($this->_param['num']);
        $start = intval($this->_param['start']);
        $page_count = intval($this->_param['page_count']);
        $data_count = intval($this->_param['data_count']);
        $ids = $this->_param['topic'];

        $GLOBALS['mid'] = 3;
        $GLOBALS['aid'] = mac_get_aid('topic');

        if($start<1){
            $start=1;
        }
        $GLOBALS['config']['app']['makesize'] = 1;

        if($GLOBALS['config']['view']['topic_index'] <2){
            mac_echo(lang('admin/make/view_model_static_err'));
            exit;
        }

        if(empty($data_count)){
            $where = [];
            $where['topic_status'] = ['eq', 1];
            $data_count = model('Topic')->countData($where);
            $html = mac_read_file($GLOBALS['MAC_ROOT_TEMPLATE'] . 'topic/index.html');
            $labelRule = '{maccms:topic(.*?)num="(.*?)"(.*?)paging="yes"([\s\S]*?)}([\s\S]*?){/maccms:topic}';

            $labelRule = mac_buildregx($labelRule,"");
            preg_match_all($labelRule,$html,$arr);

            for($i=0;$i<count($arr[2]);$i++) {
                $page_size = $arr[2][$i];
                break;
            }
            if(empty($page_size)){
                $page_size = 20;
            }
            $page_count = ceil($data_count / $page_size);
            if($page_count<1){ $page_count=1; }

            $this->_param['start'] = $start;
            $this->_param['data_count'] = $data_count;
            $this->_param['page_count'] = $page_count;
            $this->_param['page_size'] = $page_size;
        }

        if($start > $page_count){
            $this->echoLink(lang('admin/make/topicpage_make_complete'));
            if(ENTRANCE=='admin') {
                $this->makeJump(url('make/opt'), 3);
            }
            exit;
        }

        $sec_count = ceil($page_count / $GLOBALS['config']['app']['makesize']);
        $sec = ceil($start / $GLOBALS['config']['app']['makesize']);
        $this->echoLink(lang('admin/make/topic_index_tip',[$this->_param['page_count'],$sec_count,$sec]));

        $this->label_maccms();

        $n=1;
        for($i=$start;$i<=$page_count;$i++){
            $this->_param['start'] = $i;
            $_REQUEST['page'] = $i;

            $this->label_topic_index($data_count);
            $link = mac_url_topic_index(['page'=>$i]);
            $this->buildHtml($link,'./','topic/index');
            $this->echoLink(lang('page').' '.$i,$link);

            if($GLOBALS['config']['app']['makesize'] == $n){
                break;
            }
            $n++;
        }

        if($this->_param['start'] >= $page_count){
            $this->echoLink(lang('admin/make/topicpage_make_complete'));
            if(ENTRANCE=='admin') {
                $this->makeJump(url('make/opt'), 3);
            }
            exit;
        }
        else{
            $this->_param['start']++;
            $this->echoLink(lang('server_rest'));
        }
        $url = url('make/make') .'?'. http_build_query($this->_param);
        if(ENTRANCE=='admin') {
            $this->makeJump($url, 3);
        }
    }

    public function topic_info()
    {
        $gate = $this->makeGate();
        if ($gate !== true) {
            return $gate;
        }
        $ids = $this->_param['topic'];

        $GLOBALS['mid'] = 3;
        $GLOBALS['aid'] = mac_get_aid('topic','detail');

        if(empty($ids)){
            return $this->error(lang('param_err'));
        }
        if(!is_array($ids)){
            $ids = explode(',',$ids);
        }

        if($GLOBALS['config']['view']['topic_detail'] <2){
            mac_echo(lang('admin/make/view_model_static_err'));
            exit;
        }


        $data_count = count($ids);
        $this->echoLink(lang('admin/make/topic_tip',[$data_count]));
        $this->label_maccms();

        $n=1;
        foreach($ids as $a){
            $_REQUEST['id'] = $a;

            $where = [];
            $where['topic_id'] = ['eq',$a];
            $where['topic_status'] = ['eq',1];
            $res = model('Topic')->infoData($where);
            if($res['code'] == 1) {
                $topic_info = $res['info'];

                $this->label_topic_detail($topic_info);
                $link = mac_url_topic_detail($topic_info);
                $this->buildHtml($link,'./', mac_tpl_fetch('topic',$topic_info['topic_tpl'],'detail') );
                $this->echoLink($topic_info['topic_name'],$link);
                $n++;
            }
        }

        if(!empty($ids)){
            Db::name('topic')->where(['topic_id'=>['in',$ids]])->update(['topic_time_make'=>time()]);
        }
        if($this->_param['ref'] ==1){
            $ref = mac_same_origin_referer();
            if($ref !== ''){
                if(ENTRANCE=='admin'){
                    $this->makeJump($ref,2);
                }
                die;
            }
        }

        $this->echoLink(lang('admin/make/topic_make_complete'));
        if(ENTRANCE=='admin'){
            $this->makeJump( url('make/opt') ,3);
        }
    }


    public function info()
    {
        $gate = $this->makeGate();
        if ($gate !== true) {
            return $gate;
        }
        $where = [];

        $ids = $this->_param['ids'];
        if($this->_param['tab'] =='art'){
            $type_ids = $this->_param['arttype'];
            $order='art_time desc';
            $where['art_status'] = ['eq',1];

            if($GLOBALS['config']['view']['art_detail'] <2){
                mac_echo(lang('admin/make/view_model_static_err'));
                exit;
            }

        }
        else{
            $type_ids = $this->_param['vodtype'];
            $order='vod_time desc';
            $where['vod_status'] = ['eq',1];

            if($GLOBALS['config']['view']['vod_detail'] <2 && $GLOBALS['config']['view']['vod_play'] <2 && $GLOBALS['config']['view']['vod_down'] <2){
                mac_echo(lang('admin/make/view_model_static_err'));
                exit;
            }

        }

        $num = intval($this->_param['num']);
        $start = intval($this->_param['start']);
        $page_count = intval($this->_param['page_count']);
        $data_count = intval($this->_param['data_count']);
        if($start<1){
            $start=1;
        }
        if($page_count<1){
            $page_count=1;
        }

        $where = [];
        if(empty($ids) && empty($type_ids) && empty($this->_param['ac2'])){
            return $this->error(lang('param_err'));
        }
        $type_name ='';


        if(!empty($type_ids)){
            if(!is_array($type_ids)){
                $type_ids = explode(',',$type_ids);
            }

            if ($num>=count($type_ids)){

                if(empty($this->_param['jump'])){
                    $this->echoLink(lang('admin/make/info_make_complete').'');
                    if(ENTRANCE=='admin'){
                        $this->makeJump( url('make/opt') ,3);
                    }
                    exit;
                }
                else{
                    $this->echoLink(lang('admin/make/info_make_complete_later_make_type'));
                    if(ENTRANCE=='admin'){
                        $this->makeJump( url('make/make',['jump'=>1,'ac'=>'type','tab'=>$this->_param['tab'], $this->_param['tab'].'type'=> join(',',$type_ids) ,'ac2'=>'day']) ,3);
                    }
                    exit;
                }
            }

            $type_id = $type_ids[$num];
            $type_list = model('Type')->getCache('type_list');
            $type_info = $type_list[$type_id];

            $type_name = $type_info['type_name'];
            $where['type_id'] = ['eq',$type_id];
        }
        elseif(!empty($ids)){
            $type_name =lang('select_data');
            if($start > $page_count){
                mac_echo(lang('admin/make/info_make_complete').'');
                exit;
            }
            $where[$this->_param['tab'].'_id'] = ['in',$ids];
        }


        if($this->_param['ac2'] =='day'){
            $type_name .=lang('today_data');
            $where[$this->_param['tab'].'_time'] = ['gt', strtotime(date('Y-m-d'))];


            if ($num>=count($type_ids)){

            }
            if($start > $page_count){
                // $this->echolink (\'Content page generated 3\');
                // mac_jump( url(\'make/opt\') ,3);
                // exit;
            }
        }
        elseif($this->_param['ac2'] =='nomake'){
            $type_name =lang('no_make_data');
            $start=1;
            $data_count=0;
            $where[$this->_param['tab'].'_time_make'] = ['exp',  Db::raw(' < '. $this->_param['tab'].'_time')];
            if($start > $page_count){
                $this->echoLink(lang('admin/make/info_make_complete').'');
                if(ENTRANCE=='admin'){
                    $this->makeJump( url('make/opt') ,3);
                }
                exit;
            }
        }

        if(ENTRANCE=='api'){
            $GLOBALS['config']['app']['makesize'] = 999;
        }

        if(empty($data_count)){
            if($this->_param['tab'] =='art'){
                $data_count = model('Art')->countData($where);
            }
            else{
                $data_count = model('Vod')->countData($where);
            }


            $page_count = ceil($data_count / $GLOBALS['config']['app']['makesize']);
            $page_size = $GLOBALS['config']['app']['makesize'];

            $this->_param['data_count'] = $data_count;
            $this->_param['page_count'] = $page_count;
            $this->_param['page_size'] = $page_size;
        }

        if($start > $page_count){

            $this->echoLink('【'.$type_name.'】'.lang('admin/make/info_make_complete_later'));

            if($this->_param['ac2'] =='nomake' ){
                if(ENTRANCE=='admin'){
                    $this->makeJump( url('make/opt') ,3);
                }
                die;
            }
            else{

            }

            $this->_param['start'] = 1;
            $this->_param['num']++;
            $this->_param['data_count'] = 0;
            $this->_param['page_count'] = 0;
            $this->_param['page_size'] = 0;
            $url = url('make/make') .'?'. http_build_query($this->_param);


            if(ENTRANCE=='admin'){
                $this->makeJump( $url ,3);
            }
            exit;
        }


        $this->echoLink(lang('admin/make/info_tip',[$type_name,$this->_param['data_count'],$this->_param['page_count'],$this->_param['page_size'],$start]));

        if($this->_param['tab'] =='art') {
            $res = model('Art')->listData($where, $order, $start, $GLOBALS['config']['app']['makesize']);
        }
        else{
            $res = model('Vod')->listData($where, $order, $start, $GLOBALS['config']['app']['makesize']);
        }


        $update_ids=[];
        foreach($res['list'] as $k=>$v){

            if(!empty($v['art_id'])) {

                $GLOBALS['type_id'] =$v['type_id'];
                $GLOBALS['type_pid'] = $v['type']['type_pid'];

                $GLOBALS['mid'] = 2;
                $GLOBALS['aid'] = mac_get_aid('art','detail');

                $this->label_maccms();
                $_REQUEST['id'] = $v['art_id'];
                echo mac_substring($v['art_name'],100) .'&nbsp;';

                if(!empty($v['art_content'])) {
                    $art_page_list = mac_art_list($v['art_title'], $v['art_note'], $v['art_content']);
                    $art_page_total = count($art_page_list);
                }

                for($i=1;$i<=$art_page_total;$i++){
                    $v['art_page_list'] = mac_art_list($v['art_title'], $v['art_note'], $v['art_content']);
                    $v['art_page_total'] = count($v['art_page_list']);
                    $_REQUEST['page'] = $i;

                    $info = $this->label_art_detail($v,$GLOBALS['config']['view']['art_detail']);
                    $link = mac_url_art_detail($v, ['page' => $i]);

                    $this->buildHtml($link,'./', mac_tpl_fetch('art',$info['art_tpl'],'detail') );
                    if($i==1) {
                        $this->echoLink('Details', $link);
                    }
                }
                $update_ids[] = $v['art_id'];
            }
            else{

                $GLOBALS['type_id'] =$v['type_id'];
                $GLOBALS['type_pid'] = $v['type']['type_pid'];

                $GLOBALS['mid'] = 1;
                $GLOBALS['aid'] = mac_get_aid('vod','detail');

                $_REQUEST['id'] = $v['vod_id'];
                echo $v['vod_name'].'&nbsp;';;
                if(!empty($v['vod_play_from'])) {
                    $v['vod_play_list'] = mac_play_list($v['vod_play_from'], $v['vod_play_url'], $v['vod_play_server'], $v['vod_play_note'],'play');
                    $v['vod_play_total'] =  count($v['vod_play_list']);
                }
                if(!empty($v['vod_down_from'])) {
                    $v['vod_down_list'] = mac_play_list($v['vod_down_from'], $v['vod_down_url'], $v['vod_down_server'], $v['vod_down_note'],'down');
                    $v['vod_down_total'] =  count($v['vod_down_list']);
                }
                if(!empty($v['vod_plot_name'])) {
                    $v['vod_plot_list'] = mac_plot_list($v['vod_plot_name'], $v['vod_plot_detail']);
                    $v['vod_plot_total'] =  count($v['vod_plot_list']);
                }

                if($GLOBALS['config']['view']['vod_detail'] == 2){
                    $this->label_maccms();
                    $info = $this->label_vod_detail($v, $GLOBALS['config']['view']['vod_detail']);
                    $link = mac_url_vod_detail($v);
                    $this->buildHtml($link, './', mac_tpl_fetch('vod', $info['vod_tpl'], 'detail'));
                    $this->echoLink('Details', $link, '', 0);
                }
                $_REQUEST['id'] = $v['vod_id'];
                
                $update_ids[] = $v['vod_id'];
                $flag = ['play','down'];
                foreach($flag as $f) {
                    $GLOBALS['aid'] = mac_get_aid('vod',$f);

                    $this->label_maccms();
                    // Playback and download pages
                    if ($GLOBALS['config']['view']['vod_'.$f] < 2) {

                    }
                    else{
                        if ($GLOBALS['config']['view']['vod_'.$f] == 2) {
                        	$_REQUEST['sid'] = 1;
                        	$_REQUEST['nid'] = 1;
                            $info = $this->label_vod_play($f,$v,$GLOBALS['config']['view']['vod_'.$f]);
                            $link =  ($f=='play' ?mac_url_vod_play($v,['sid'=>1,'nid'=>1]) : mac_url_vod_down($v,['sid'=>1,'nid'=>1]) );
                            $this->buildHtml($link, './', mac_tpl_fetch('vod', $info['vod_tpl_'.$f], $f) );
                            $this->echoLink($f, $link, '', 0);
                        }
                        elseif ($GLOBALS['config']['view']['vod_'.$f] == 3) {
                            for ($i = 1; $i <= $v['vod_'.$f.'_total']; $i++) {
                                for ($j = 1; $j <= $v['vod_'.$f.'_list'][$i]['url_count']; $j++) {
                                	$_REQUEST['sid'] = $i;
                                	$_REQUEST['nid'] = $j;
                                    $info = $this->label_vod_play($f,$v,$GLOBALS['config']['view']['vod_'.$f]);
                                    $link = ($f=='play' ? mac_url_vod_play($v, ['sid' => $i, 'nid' => $j]) : mac_url_vod_down($v, ['sid' => $i, 'nid' => $j]) );
                                    $link_sp = explode('?',$link);
                                    $this->buildHtml($link_sp[0], './', mac_tpl_fetch('vod', $info['vod_tpl_'.$f], $f) );
                                    if($i==1 && $j==1) {
                                        $this->echoLink('' . $f . '-' . $i . '-' . $j, $link, '', 0);
                                    }
                                }
                            }
                        }
                        elseif ($GLOBALS['config']['view']['vod_'.$f] == 4) {
                            $tmp_play_list = $v['vod_'.$f.'_list'];
                            for ($i = 1; $i <= $v['vod_'.$f.'_total']; $i++) {
                                $v['vod_'.$f.'_list'] = [];
                                $v['vod_'.$f.'_list'][$i] = $tmp_play_list[$i];
                                $info = $this->label_vod_play($f,$v,$GLOBALS['config']['view']['vod_'.$f]);
                                $link = ($f=='play' ? mac_url_vod_play($v, ['sid' => $i]) : mac_url_vod_down($v, ['sid' => $i]) );
                                $link_sp = explode('?',$link);
                                $this->buildHtml($link_sp[0], './', mac_tpl_fetch('vod', $info['vod_tpl_'.$f], $f) );
                                if($i==1) {
                                    $this->echoLink('' . $f . '-' . $i, $link, '', 0);
                                }
                            }
                        }
                    }
                }
                echo '<br>';
            }
        }

        if(!empty($update_ids)){
            Db::name($this->_param['tab'])->where([$this->_param['tab'].'_id'=>['in',$update_ids]])->update([$this->_param['tab'].'_time_make'=>time()]);
        }
        if($this->_param['ref'] ==1){
            $ref = mac_same_origin_referer();
            if($ref !== ''){
                if(ENTRANCE=='admin'){
                    $this->makeJump($ref,2);
                }
                die;
            }
        }

        if($start > $page_count){
            $this->_param['start'] = 1;
            $this->_param['num']++;
            $this->_param['data_count'] = 0;
            $this->_param['page_count'] = 0;
            $this->_param['page_size'] = 0;
            $this->echoLink('【'.$type_name.'】'.lang('admin/make/info_make_complete_later'));


            if($this->_param['ac2'] !=''){
                // mac_jump( url(\'make/opt\') ,3);
                // the;
            }
            else{

            }
        }
        else{
            $this->_param['start'] = ++$start;
            $this->echoLink(lang('server_rest'));
        }
        $url = url('make/make') .'?'. http_build_query($this->_param);

        if(ENTRANCE=='admin'){
            $this->makeJump( $url ,3);
        }
    }


    public function label()
    {
        $gate = $this->makeGate();
        if ($gate !== true) {
            return $gate;
        }
        $ids = $this->_param['label'];
        $GLOBALS['aid'] = mac_get_aid('label');
        if(empty($ids)){
            return $this->error(lang('param_err'));
        }
        $ids = str_replace('\\','/',$ids);
        if( count( explode("./",$ids) ) > 1){
            $this->error(lang('param_err').'');
            return;
        }
        if(!is_array($ids)){
            $ids = explode(',',$ids);
        }
        $data_count = count($ids);
        $this->echoLink(lang('admin/make/label_tip',[$data_count]));
        $this->label_maccms();

        $n=1;
        foreach($ids as $a){
            $fullname = explode('.',$a)[0];
            $file = 'label/'.$a;
            $tpl = 'label/'.$fullname;

            $this->buildHtml($file ,'./', $tpl );
            $this->echoLink($file,'/'. $file);

            $n++;
        }

        $this->echoLink(lang('admin/make/label_complete'));
        if(ENTRANCE=='admin'){
            $this->makeJump( url('make/opt') ,3);
        }
    }


}
