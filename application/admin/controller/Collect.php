<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, with changes from the MacCMS editions
 * RamCMS (Apache-2.0) and/or ApiiCMS (MIT, Copyright (c) 2024 apiiphim), changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS, RamCMS and ApiiCMS parts keep their own licenses.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\admin\controller;
use think\Db;
use think\Cache;
use app\common\validate\Vod as VodValidate;
use think\Exception;
use think\Request;

class Collect extends Base
{
    use \app\admin\common\RunKey;

    /** r5 repair: the settings an import job may carry (what the Collect model reads). */
    private static $collectFields = ['ac', 'cjflag', 'cjurl', 'h', 't', 'ids', 'wd', 'type', 'mid', 'opt', 'sync_pic_opt',
        'filter', 'filter_from', 'param', 'rday', 'page'];

    public function __construct()
    {
        parent::__construct();
        // header(\'X-Accel-Buffering: no\');
    }

    public function index()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 1 ? (int)$this->_pagesize : min(max(intval($param['limit']), 1), 1000);
        $where = [];

        $order = 'collect_id desc';
        $res = model('Collect')->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('list', $res['list']);
        $this->assign('total', $res['total']);
        $this->assign('page', $res['page']);
        $this->assign('limit', $res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param', $param);

        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_vod';
        $collect_break_vod = Cache::get($key);
        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_art';
        $collect_break_art = Cache::get($key);
        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_actor';
        $collect_break_actor = Cache::get($key);
        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_role';
        $collect_break_role = Cache::get($key);
        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_website';
        $collect_break_website = Cache::get($key);


        $this->assign('collect_break_vod', $collect_break_vod);
        $this->assign('collect_break_art', $collect_break_art);
        $this->assign('collect_break_actor', $collect_break_actor);
        $this->assign('collect_break_role', $collect_break_role);
        $this->assign('collect_break_website', $collect_break_website);

        $title = lang('admin/ux/menu/api_sources');
        $title = ($title === 'admin/ux/menu/api_sources') ? lang('admin/collect/title') : $title;
        $help = lang('admin/ux/help/api_sources');
        $this->assign('title', $title);
        $this->assign('ax_head', ['title' => $title, 'help' => $help === 'admin/ux/help/api_sources' ? '' : $help]);
        return $this->fetch('admin@collect/index');
    }

    public function test()
    {
        // r5 repair (security r5 P1-1): "Test" fetches the typed address on the server: POST + the admin token, and the
        // answer carries only the result code and type, not the fetched data.
        if (!$this->axPostCsrfOk()) {
            return json(['code' => 1001, 'msg' => lang('admin/ux/system/token_expired')]);
        }
        $param = input('post.');
        $url = isset($param['cjurl']) && is_string($param['cjurl']) ? trim($param['cjurl']) : '';
        if ($url === '') {
            return json(['code' => 1001, 'msg' => lang('url_empty')]);
        }
        // final-fix-admin (security r9 R9-1): the answer is fixed text only ('json' / 'xml' or a known error), never the
        // address or anything the remote server sent.
        if (!self::cjUrlLooksOk($url)) {
            return json(['code' => 1001, 'msg' => lang('model/collect/cjurl_err')]);
        }
        $res = model('Collect')->vod(['cjurl' => $url, 'cjflag' => md5($url), 'ac' => 'list', 'type' => '', 'param' => '',
            't' => '', 'page' => '', 'h' => '', 'ids' => '', 'wd' => '']);
        $code = isset($res['code']) ? (int)$res['code'] : 1001;
        $msg = isset($res['msg']) ? (string)$res['msg'] : '';
        if ($code === 1) {
            return json(['code' => 1, 'msg' => $msg === 'xml' ? 'xml' : 'json']);
        }
        foreach (['model/collect/cjurl_err', 'model/collect/json_err', 'model/collect/xml_err'] as $key) {
            $text = (string)lang($key);
            if ($text !== '' && $text !== $key && strpos($msg, $text) === 0) {
                return json(['code' => $code, 'msg' => $text]);
            }
        }
        return json(['code' => $code, 'msg' => lang('model/collect/get_html_err')]);
    }

    /** final-fix-admin (security r9 R9-1): an import address is http(s):// with no spaces, quotes or angle brackets. */
    private static function cjUrlLooksOk($url)
    {
        return is_string($url) && (bool)preg_match('~^https?://[^\s"\'<>]+$~i', $url);
    }

    public function info()
    {
        if (Request()->isPost()) {
            $param = input('post.');
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }
            // final-fix-admin (security r9 R9-1): refuse an address with spaces, quotes or angle brackets (saves nothing).
            $cjurl = isset($param['collect_url']) && is_string($param['collect_url']) ? trim($param['collect_url']) : '';
            if ($cjurl !== '' && !self::cjUrlLooksOk($cjurl)) {
                return $this->error(lang('model/collect/cjurl_err'));
            }
            if (isset($param['collect_url']) && is_string($param['collect_url'])) {
                $param['collect_url'] = $cjurl;
            }
            $res = model('Collect')->saveData($param);
            if ($res['code'] > 1) {
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where = [];
        $where['collect_id'] = ['eq', $id];
        $res = model('Collect')->infoData($where);
        $this->assign('info', $res['info']);
        $this->assign('title', lang('admin/collect/title'));
        return $this->fetch('admin@collect/info');
    }

    /** Hardening: delete / clear bindings = POST + the session admin token (__token__ or X-CSRF-Token), as axCsrfOk. */
    private function axPostCsrfOk()
    {
        if (!$this->request->isPost()) {
            return false;
        }
        $post = $this->request->post();
        $sent = isset($post['__token__']) && is_string($post['__token__']) ? $post['__token__'] : (string)$this->request->header('X-CSRF-Token');
        return $sent !== '' && hash_equals((string)mac_admin_csrf_token(), $sent);
    }

    public function del()
    {
        if (!$this->axPostCsrfOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $ids = array_values(array_filter(array_map('intval', (array)input('post.ids/a', []))));

        if (!empty($ids)) {
            $where = [];
            $where['collect_id'] = ['in', $ids];

            $res = model('Collect')->delData($where);
            if ($res['code'] > 1) {
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function union()
    {
        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_vod';
        $collect_break_vod = Cache::get($key);
        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_art';
        $collect_break_art = Cache::get($key);
        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_actor';
        $collect_break_actor = Cache::get($key);
        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_role';
        $collect_break_role = Cache::get($key);
        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_website';
        $collect_break_website = Cache::get($key);

        $this->assign('collect_break_vod', $collect_break_vod);
        $this->assign('collect_break_art', $collect_break_art);
        $this->assign('collect_break_actor', $collect_break_actor);
        $this->assign('collect_break_role', $collect_break_role);
        $this->assign('collect_break_website', $collect_break_website);

        $this->assign('title', lang('admin/collect/title'));
        return $this->fetch('admin@collect/union');
    }

    public function load()
    {
        // r5 repair (security r5 P1-1): "Continue the last import" restarts a write job: POST + the admin token (the
        // button on Collect > API sources posts it). It starts a new run with the settings of the stopped job and goes on
        // at the page where it stopped. A GET link changes nothing.
        if (!$this->axPostCsrfOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $flag = input('post.flag', '');
        if (!is_string($flag) || !in_array($flag, ['vod', 'art', 'actor', 'role', 'website'], true)) {
            return $this->error(lang('param_err'));
        }
        $key = $GLOBALS['config']['app']['cache_flag']. '_'. 'collect_break_' . $flag;
        $collect_break = Cache::get($key);
        if (empty($collect_break) || !is_string($collect_break)) {
            return $this->error(lang('admin/ux/tools/collect_nothing_to_resume'), url('collect/index'));
        }
        $query = [];
        parse_str((string)parse_url($collect_break, PHP_URL_QUERY), $query);
        $bind = $this->collectBind($query);
        if ($bind['ac'] === 'list' || $bind['ac'] === '') {
            $bind['ac'] = 'cj';
        }
        if (!$this->collectSourceOk($bind)) {
            return $this->error(lang('admin/ux/tools/collect_source_unknown'), url('collect/index'));
        }
        $page = max(1, (int)$bind['page']);
        $run = $this->runKeyStart('collect_api', $bind);
        if ($run === false) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        echo lang('admin/collect/load_break');
        mac_jump(url('collect/api') . '?' . http_build_query(['run' => $run, 'page' => $page]));
    }

    /** r5 repair: the job settings the import buttons of a source list post (no page, limit, run key or ids). */
    private function collectRunStr($param)
    {
        $q = [];
        foreach ((array)$param as $k => $v) {
            if (is_scalar($v) && !in_array($k, ['ac', 'page', 'limit', 'run', 'ids', '__token__'], true)) {
                $q[$k] = (string)$v;
            }
        }
        return http_build_query($q);
    }

    /** r5 repair: keep only the import settings (scalars) of a request. */
    private function collectBind($in)
    {
        $out = [];
        foreach (self::$collectFields as $k) {
            $out[$k] = is_array($in) && isset($in[$k]) && is_scalar($in[$k]) ? trim((string)$in[$k]) : '';
        }
        if ($out['ac'] === 'cjday') {
            $out['h'] = '24';
        }
        return $out;
    }

    /**
     * r5 repair (security r5 P1-1): the source address must be one the admin saved under API sources, and the flag must
     * be its md5 (Collect::check_flag). So a link from another site cannot make this server fetch a host of its choice.
     */
    private function collectSourceOk($param)
    {
        $url = isset($param['cjurl']) && is_string($param['cjurl']) ? trim($param['cjurl']) : '';
        $flag = isset($param['cjflag']) && is_string($param['cjflag']) ? $param['cjflag'] : '';
        if ($url === '' || $flag === '') {
            return false;
        }
        $chk = model('Collect')->check_flag(['cjflag' => $flag, 'cjurl' => $url]);
        if (!isset($chk['code']) || (int)$chk['code'] !== 1) {
            return false;
        }
        return (bool)Db::name('collect')->where('collect_url', $url)->value('collect_id');
    }

    /**
     * r5 repair (security r5 P1-1): browsing a source (ac=list) is read-only and stays a GET, but only for a saved source.
     * Every other ac imports rows: step 1 must be the POST of an import button with the admin token (it starts a run, a
     * key in the session with the job settings); the next pages are the GET jump links of the old engine, which work only
     * with that run key and use the stored settings. Returns the request settings, or an error response.
     */
    private function collectGate($param)
    {
        $ac = isset($param['ac']) && is_string($param['ac']) ? $param['ac'] : '';
        if (request()->isPost()) {
            if (!$this->runKeyTokenOk()) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            $bind = $this->collectBind(input('post.'));
            if ($bind['ac'] === 'list' || $bind['ac'] === '') {
                return $this->error(lang('param_err'));
            }
            if (!$this->collectSourceOk($bind)) {
                return $this->error(lang('admin/ux/tools/collect_source_unknown'));
            }
            $page = max(1, (int)$bind['page']);
            $run = $this->runKeyStart('collect_api', $bind);
            if ($run === false) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            $bind['run'] = $run;
            $bind['page'] = $page;
            return $bind;
        }
        if ($ac !== 'list') {
            $resumed = $this->runKeyResume('collect_api', is_array($param) ? $param : []);
            if ($resumed === false) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            return $resumed;
        }
        if (!$this->collectSourceOk($param)) {
            return $this->error(lang('admin/ux/tools/collect_source_unknown'));
        }
        return $param;
    }

    public function api($pp = [])
    {
        // r5 repair (security r5 P1-1): $pp is only for the scheduled task (api.php Timming calls api($output) in the same
        // process). From the admin entry it is never trusted (the framework could bind it from the URL).
        $internal = defined('ENTRANCE') && ENTRANCE === 'api' && is_array($pp) && !empty($pp);
        $param = $internal ? $pp : input();
        if (!$internal) {
            $param = $this->collectGate($param);
            if (!is_array($param)) {
                return $param;
            }
        }

        // Load the category list.
        $type_list = model('Type')->getCache('type_list');
        $this->assign('type_list', $type_list);

        if (!empty($param['pg'])) {
            $param['page'] = $param['pg'];
            unset($param['pg']);
        }
        // r7 repair (function r6 note): make sure the admin token exists before the session is closed, so the import
        // buttons of the list page carry a token the session knows (a fresh session made it only after the close).
        if (!$internal) {
            mac_admin_csrf_token();
        }
        @session_write_close();
        
        if ($param['mid'] == '' || $param['mid'] == '1') {
            return $this->vod($param);
        } elseif ($param['mid'] == '2') {
            return $this->art($param);
        } elseif ($param['mid'] == '8') {
            return $this->actor($param);
        }
        elseif ($param['mid'] == '9') {
            return $this->role($param);
        }
        elseif ($param['mid'] == '11') {
            return $this->website($param);
        }
    }

    public function timing()
    {
        // Category IDs for videos updated today.
        $res = model('Vod')->updateToday('type');
        $this->assign('vod_type_ids_today', $res['data']);

        return $this->fetch('admin@collect/timing');
    }

    public function clearbind()
    {
        if (!$this->axPostCsrfOk()) {
            return json(['code'=>0,'msg'=>lang('admin/ux/system/token_expired')]);
        }
        $param = input();
        $config = [];
        if(!empty($param['cjflag'])){
            $bind_list = config('bind');
            foreach($bind_list as $k=>$v){
                if(strpos($k,$param['cjflag'])===false){
                    $config[$k] = $v;
                }
            }
        }

        $res = mac_arr2file( APP_PATH .'extra/bind.php', $config);
        if($res===false){
            return json(['code'=>0,'msg'=>lang('clear_err')]);
        }
        return json(['code'=>1,'msg'=>lang('clear_ok')]);
    }

    public function bind()
    {
        // close-security (security r4 P2-6): a category binding rewrites application/extra/bind.php: POST + the admin token
        // (the bind dialog public/select_type.html sends __token__). A replay without it changes nothing.
        if (!$this->axPostCsrfOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param = input('post.');
        $col = isset($param['col']) && is_string($param['col']) ? $param['col'] : '';
        $val = isset($param['val']) && is_scalar($param['val']) ? (string)$param['val'] : '';
        // The key is "<source flag>_<remote category id>" (a hash and a number).
        if ($col !== '' && !preg_match('/^[A-Za-z0-9]{1,64}_[0-9]{1,10}$/', $col)) {
            return $this->error(lang('param_err'));
        }

        if(!empty($col)){
            $config = config('bind');
            $config[$col] = intval($val);
            $data = [];
            $data['id'] = $col;
            $data['st'] = 0;
            $data['local_type_id'] = $val;
            $data['local_type_name'] = '';
            if(intval($val)>0){
                $data['st'] = 1;
                $type_list = model('Type')->getCache('type_list');
                $data['local_type_name'] = isset($type_list[$val]['type_name']) ? $type_list[$val]['type_name'] : '';
            }

            $res = mac_arr2file( APP_PATH .'extra/bind.php', $config);
            if($res===false){
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'),null, $data);
        }
        return $this->error(lang('param_err'));
    }

    /** r7 repair (security r6 P1-1): an import engine step, reached only through the gated api(); not a route. */
    protected function vod($param)
    {
        if($param['ac'] != 'list'){
            $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_vod';
            Cache::set($key, url('collect/api').'?'. http_build_query($param) );
        }
        $res = model('Collect')->vod($param);
        if($res['code']>1){
            return $this->error($res['msg']);
        }

        if($param['ac'] == 'list'){

            $bind_list = config('bind');
            $type_list = model('Type')->getCache('type_list');

            foreach($res['type'] as $k=>$v){
                $key = $param['cjflag'] . '_' . $v['type_id'];
                $res['type'][$k]['isbind'] = 0;
                $local_id = intval($bind_list[$key]);
                if( $local_id>0 ){
                    $res['type'][$k]['isbind'] = 1;
                    $res['type'][$k]['local_type_id'] = $local_id;
                    $type_name = $type_list[$local_id]['type_name'];
                    if(empty($type_name)){
                        $type_name = lang('unknown_type');
                    }
                    $res['type'][$k]['local_type_name'] = $type_name;
                }
            }

            $this->assign('page',$res['page']);
            $this->assign('type',$res['type']);
            $this->assign('list',$res['data']);

            $this->assign('total',(int)$res['page']['recordcount']);
            $this->assign('page',max(1, (int)$res['page']['page']));
            $this->assign('limit',max(1, (int)$res['page']['pagesize']));

            $param['page'] = '{page}';
            $param['limit'] = '{limit}';
            $this->assign('param',$param);

            $this->assign('param_str',http_build_query($param)) ;
            $this->assign('run_str', $this->collectRunStr($param));

            return $this->fetch('admin@collect/vod');
        }
        $page_now = isset($param['page']) && strlen($param['page']) > 0 ? (int)$param['page'] : 1;
        mac_echo('<title>' . $page_now . '/' . (int)$res['page']['pagecount'] . ' importing...</title>');
        mac_echo('<style type="text/css">body{font-size:12px;color: #333333;line-height:21px;}span{font-weight:bold;color:#FF0000}</style>');
        model('Collect')->vod_data($param,$res );

    }

    /** r7 repair (security r6 P1-1): an import engine step, reached only through the gated api(); not a route. */
    protected function art($param)
    {
        if($param['ac'] != 'list'){
            $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_art';
            Cache::set($key, url('collect/api').'?'. http_build_query($param) );
        }
        $res = model('Collect')->art($param);
        if($res['code']>1){
            return $this->error($res['msg']);
        }

        if($param['ac'] == 'list'){

            $bind_list = config('bind');
            $type_list = model('Type')->getCache('type_list');

            foreach($res['type'] as $k=>$v){
                $key = $param['cjflag'] . '_' . $v['type_id'];
                $res['type'][$k]['isbind'] = 0;
                $local_id = intval($bind_list[$key]);
                if( $local_id>0 ){
                    $res['type'][$k]['isbind'] = 1;
                    $res['type'][$k]['local_type_id'] = $local_id;
                    $type_name = $type_list[$local_id]['type_name'];
                    if(empty($type_name)){
                        $type_name = lang('unknown_type');
                    }
                    $res['type'][$k]['local_type_name'] = $type_name;
                }
            }

            $this->assign('page',$res['page']);
            $this->assign('type',$res['type']);
            $this->assign('list',$res['data']);

            $this->assign('total',(int)$res['page']['recordcount']);
            $this->assign('page',max(1, (int)$res['page']['page']));
            $this->assign('limit',max(1, (int)$res['page']['pagesize']));

            $param['page'] = '{page}';
            $param['limit'] = '{limit}';
            $this->assign('param',$param);

            $this->assign('param_str',http_build_query($param)) ;
            $this->assign('run_str', $this->collectRunStr($param));

            return $this->fetch('admin@collect/art');
        }

        mac_echo('<style type="text/css">body{font-size:12px;color: #333333;line-height:21px;}span{font-weight:bold;color:#FF0000}</style>');
        model('Collect')->art_data($param,$res );
    }

    /** r7 repair (security r6 P1-1): an import engine step, reached only through the gated api(); not a route. */
    protected function actor($param)
    {
        if($param['ac'] != 'list'){
            $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_actor';
            Cache::set($key, url('collect/api').'?'. http_build_query($param) );
        }
        $res = model('Collect')->actor($param);
        if($res['code']>1){
            return $this->error($res['msg']);
        }

        if($param['ac'] == 'list'){

            $bind_list = config('bind');
            $type_list = model('Type')->getCache('type_list');

            foreach($res['type'] as $k=>$v){
                $key = $param['cjflag'] . '_' . $v['type_id'];
                $res['type'][$k]['isbind'] = 0;
                $local_id = intval($bind_list[$key]);
                if( $local_id>0 ){
                    $res['type'][$k]['isbind'] = 1;
                    $res['type'][$k]['local_type_id'] = $local_id;
                    $type_name = $type_list[$local_id]['type_name'];
                    if(empty($type_name)){
                        $type_name = lang('unknown_type');
                    }
                    $res['type'][$k]['local_type_name'] = $type_name;
                }
            }

            $this->assign('page',$res['page']);
            $this->assign('type',$res['type']);
            $this->assign('list',$res['data']);

            $this->assign('total',(int)$res['page']['recordcount']);
            $this->assign('page',max(1, (int)$res['page']['page']));
            $this->assign('limit',max(1, (int)$res['page']['pagesize']));

            $param['page'] = '{page}';
            $param['limit'] = '{limit}';
            $this->assign('param',$param);

            $this->assign('param_str',http_build_query($param)) ;
            $this->assign('run_str', $this->collectRunStr($param));

            return $this->fetch('admin@collect/actor');
        }

        mac_echo('<style type="text/css">body{font-size:12px;color: #333333;line-height:21px;}span{font-weight:bold;color:#FF0000}</style>');
        model('Collect')->actor_data($param,$res );
    }

    /** r7 repair (security r6 P1-1): an import engine step, reached only through the gated api(); not a route. */
    protected function role($param)
    {
        if ($param['ac'] != 'list') {
            $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_role';
            Cache::set($key, url('collect/api') . '?' . http_build_query($param));
        }
        $res = model('Collect')->role($param);
        if ($res['code'] > 1) {
            return $this->error($res['msg']);
        }

        if ($param['ac'] == 'list') {

            $bind_list = config('bind');
            $type_list = model('Type')->getCache('type_list');

            foreach ($res['type'] as $k => $v) {
                $key = $param['cjflag'] . '_' . $v['type_id'];
                $res['type'][$k]['isbind'] = 0;
                $local_id = intval($bind_list[$key]);
                if ($local_id > 0) {
                    $res['type'][$k]['isbind'] = 1;
                    $res['type'][$k]['local_type_id'] = $local_id;
                    $type_name = $type_list[$local_id]['type_name'];
                    if (empty($type_name)) {
                        $type_name = lang('unknown_type');
                    }
                    $res['type'][$k]['local_type_name'] = $type_name;
                }
            }

            $this->assign('page', $res['page']);
            $this->assign('type', $res['type']);
            $this->assign('list', $res['data']);

            $this->assign('total', (int)$res['page']['recordcount']);
            $this->assign('page', max(1, (int)$res['page']['page']));
            $this->assign('limit', max(1, (int)$res['page']['pagesize']));

            $param['page'] = '{page}';
            $param['limit'] = '{limit}';
            $this->assign('param', $param);

            $this->assign('param_str', http_build_query($param));
            $this->assign('run_str', $this->collectRunStr($param));

            return $this->fetch('admin@collect/role');
        }

        mac_echo('<style type="text/css">body{font-size:12px;color: #333333;line-height:21px;}span{font-weight:bold;color:#FF0000}</style>');
        model('Collect')->role_data($param,$res );
    }

    /** r7 repair (security r6 P1-1): an import engine step, reached only through the gated api(); not a route. */
    protected function website($param)
    {
        if ($param['ac'] != 'list') {
            $key = $GLOBALS['config']['app']['cache_flag']. '_'.'collect_break_website';
            Cache::set($key, url('collect/api') . '?' . http_build_query($param));
        }
        $res = model('Collect')->website($param);
        if ($res['code'] > 1) {
            return $this->error($res['msg']);
        }

        if ($param['ac'] == 'list') {

            $bind_list = config('bind');
            $type_list = model('Type')->getCache('type_list');

            foreach ($res['type'] as $k => $v) {
                $key = $param['cjflag'] . '_' . $v['type_id'];
                $res['type'][$k]['isbind'] = 0;
                $local_id = intval($bind_list[$key]);
                if ($local_id > 0) {
                    $res['type'][$k]['isbind'] = 1;
                    $res['type'][$k]['local_type_id'] = $local_id;
                    $type_name = $type_list[$local_id]['type_name'];
                    if (empty($type_name)) {
                        $type_name = lang('unknown_type');
                    }
                    $res['type'][$k]['local_type_name'] = $type_name;
                }
            }

            $this->assign('page', $res['page']);
            $this->assign('type', $res['type']);
            $this->assign('list', $res['data']);

            $this->assign('total', (int)$res['page']['recordcount']);
            $this->assign('page', max(1, (int)$res['page']['page']));
            $this->assign('limit', max(1, (int)$res['page']['pagesize']));

            $param['page'] = '{page}';
            $param['limit'] = '{limit}';
            $this->assign('param', $param);

            $this->assign('param_str', http_build_query($param));
            $this->assign('run_str', $this->collectRunStr($param));

            return $this->fetch('admin@collect/website');
        }

        mac_echo('<style type="text/css">body{font-size:12px;color: #333333;line-height:21px;}span{font-weight:bold;color:#FF0000}</style>');
        model('Collect')->website_data($param,$res );
    }

    /* *
 * Display the JAVFilms import page.
 * url: https://javfilms.com */
    public function javfilms()
    {
        $this->assign('title', lang('admin/ux/tools/javfilms_title'));
        return $this->fetch('admin@collect/javfilms');
    }

    /** F37: JAV Films answers {"status_code":401} (or nothing) without an API token. */
    private function javfilmsOff()
    {
        return json(['code' => 1001, 'msg' => lang('admin/ux/tools/javfilms_off')]);
    }

    public function javfilms_get_pages()
    {
        if (Request()->has('page')) {
            $url = 'https://javfilms.com/API/v1.0/index.php?';
            $url .= http_build_query([
                'system' => 'videos',
                'action' => 'list',
                'contentid' => '',
                'sitecode' => 'javfilms',
                'ip' => '',
                'token' => '',
                'category' => '',
                'page' => input('page'),
            ]);
            $res = mac_curl_get($url);
            $res = json_decode((string)$res);
            if (!is_object($res) || !isset($res->videos) || !is_array($res->videos)) {
                return $this->javfilmsOff();
            }
            return json([
                'code' => 200,
                'msg' => 'success',
                'per_page' => count($res->videos),
                'data' => $res->videos,
            ]);
        }
        return json([
            'code' => 400,
            'msg' => 'The page parameter is required.',
        ]);
    }

    public function javfilms_get_info()
    {
        // r7 repair (sweep): this step writes a video and downloads its pictures: POST + the admin token, never a GET link.
        if (!$this->axPostCsrfOk()) {
            return json(['code' => 1001, 'msg' => lang('admin/ux/system/token_expired')]);
        }
        if (Request()->has('contentid')) {
            $url = 'https://javfilms.com/API/v1.0/index.php?';
            $url .= http_build_query([
                'system' => 'videos',
                'action' => 'detail',
                'contentid' => input('contentid'),
                'sitecode' => 'javfilms',
                'ip' => '',
                'token' => '',
                'category' => '',
                'page' => '',
            ]);
            $res = mac_curl_get($url);
            $res = json_decode((string)$res);
            if (!is_object($res) || !isset($res->videos) || !is_object($res->videos)) {
                return $this->javfilmsOff();
            }
            $videos = $res->videos;
            $cast = $res->casts_videos->cast;
            $categories = [];
            foreach ($res->categories as $category) {
                if ($category->title == null) { continue; }
                array_push($categories, $category->title);
            }
            $director = $videos->director != '' ? $videos->director : '';
            if ($videos->studio != '') {
                $studio = explode(',', $videos->studio);
                if (count($studio) == 2) {
                    $director = $director . '/' . $studio[1];
                }
            }

            $type_id = 1;
            if ( time() < intval($videos->releasetimestamp) ) {
                $type_id = 2;
            } else {
                $type_id = 1;
            }
            $data = array(
                'type_id' => $type_id,
                'vod_name' => mac_substring($videos->videocode . ' ' . $videos->title, 255),
                'vod_sub' => $videos->videocode,
                'vod_en' => $videos->title_jp,
                'vod_status' => 0,
                'vod_isend' => '1',
                'vod_letter' => strtoupper(substr($videos->videocode,0,1)),
                'vod_tag' => mac_substring(join(',', [$videos->videocode, $videos->contentid, $videos->year, $videos->plays, $director, join(',', $categories), $videos->title_jp]), 255),
                'vod_class' => mac_substring(join(',', array_unique($categories)), 255),
                'vod_actor' => $cast != null ? ($cast->name ?? '') : '',
                // \'vod_pic\' => $this->upload_image($videos->thumbnail, $videos->contentid, $videos->year, \'thumbnail\'),
                // \'vod_pic_slide\' => $this->upload_image($videos->thumbnail, $videos->contentid, $videos->year, \'coverimage\'),
                'vod_pic_screenshot' => preg_replace('/\s/', ',', $videos->moreimages),
                'vod_director' => $director,
                'vod_writer' => $videos->contentid,
                'vod_remarks' => $videos->videocode,
                'vod_pubdate' => date('d/m/Y', $videos->releasetimestamp),
                'vod_area' => 'Japan',
                'vod_year' => $videos->year,
                'vod_hits' => $videos->plays,
                'vod_duration' => $videos->duration . ' Minutes',
                'vod_time' => time(),
                'vod_time_add' => time(),
                'vod_content' => "<p>{$videos->title}</p><br><p>Photos</p><br>",
                'vod_play_url' => $videos->trailer != '' ? 'Trailer$' . $videos->trailer : '',
                'vod_play_from' => 'ngm3u8',
                'vod_plot_name' => '',
                'vod_plot_detail' => '',
                'vod_down_url' => '',
            );
            $vod_id = 0;
            $where = [];
            $where['vod_writer'] = $data['vod_writer'];
            $info = model('Vod')->where($where)->find();
            if ( !$info ) {
                $data['vod_pic'] = $this->upload_image($videos->thumbnail, $videos->contentid, $videos->year, 'thumbnail');
                $data['vod_pic_slide'] = $this->upload_image($videos->coverimage, $videos->contentid, $videos->year, 'coverimage');
                // $moreimages = [];
                // $screenshorts = explode(\"\\n\", $videos->moreimages);
                // foreach ($screenshorts as $key => $image) {
                // $moreimages[] = $this->upload_image($image, $videos->contentid, $key, \'screenshort-\');
                // }
                // $data[\'vod_pic_screenshot\'] = join(\',\', $moreimages);
                $vod_id = model('Vod')->insert($data, false, true);
            } else {                
                $vod_id = $info['vod_id'];
                $where = [];
                $where['vod_id'] = $info['vod_id'];
                $update = VodValidate::formatDataBeforeDb($data);
                model('Vod')->where($where)->update($update);
            }
            // 1.2.0 (D-S6): one video per request -> queued and drained inline for the search table
            if ((int)$vod_id > 0) {
                if (class_exists('\app\common\util\FilterVocab')) {
                    \app\common\util\FilterVocab::touched([(int)$vod_id]); // ft-vocab (fp-port): the next filter page adds its words
                }
                \app\common\model\Vod::searchTouch([(int)$vod_id]);
            }
            if ($vod_id != 0 && $cast !== null && $cast->name !== null) {
                $actor_id = model('Actor')->where(['actor_name' => $cast->name])->find();
                if ( ! $actor_id ) {
                    $actor = array(
                        'type_id' => $vod_id,
                        'actor_name' => $cast->name,
                        'actor_en' => $cast->name_jp,
                        'actor_alias' => $cast->shortcode,
                        'actor_pic' => $cast->photo,
                        'actor_status' => 1,
                        'actor_height' => $cast->height,
                        'actor_birthday' => $cast->birthday,
                        'actor_weight' => $cast->waist, 
                        'actor_birtharea' => $cast->hip, 
                        'actor_blood' => $cast->bust, 
                        'actor_starsign' => $cast->cup,
                        'actor_content' => '',
                    );
                    $actor_id = model('Actor')->insert($actor, false, true);
                }
            }
            return json([
                'code' => 200,
                'msg' => 'Done',
                'id' => $videos->id,
                // \'sreenshorts\' => $moreimages,
            ]);
        }
    }

    protected function upload_image($url, $name, $year, $flag)
    {
        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        $_save_path = 'upload/vod/' . date('Ymd') . '/';
        if(!file_exists($_save_path)){
            mac_mkdirss($_save_path);
        }

        $_save_name = $this->slugify($name) . "-" . $flag . $year . "." . $ext;
        file_put_contents($_save_path.$_save_name, file_get_contents($url));

        return $_save_path.$_save_name;
    }

    protected function slugify($str, $divider = '-')
    {
        $str = trim(mb_strtolower($str));
        $str = preg_replace('/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/', 'a', $str);
        $str = preg_replace('/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/', 'e', $str);
        $str = preg_replace('/(ì|í|ị|ỉ|ĩ)/', 'i', $str);
        $str = preg_replace('/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/', 'o', $str);
        $str = preg_replace('/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/', 'u', $str);
        $str = preg_replace('/(ỳ|ý|ỵ|ỷ|ỹ)/', 'y', $str);
        $str = preg_replace('/(đ)/', 'd', $str);
        $str = preg_replace('/[^a-z0-9-\s]/', '', $str);
        $str = preg_replace('/([\s]+)/', $divider, $str);
        return $str;
    }
}
