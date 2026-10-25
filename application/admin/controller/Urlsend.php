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

class Urlsend extends Base
{
    use \app\admin\common\RunKey;

    var $_lastid='';

    public function __construct()
    {
        parent::__construct();
        $this->_param = input();
    }

    public function index()
    {
        // The only bundled push services were Baidu (China-only); they are not part of the global distribution.
        if (empty(\app\admin\util\GlobalModules::extendsList('urlsend')['ext_list'])) {
            return $this->error(lang('admin/module_removed'));
        }
        if (Request()->isPost()) {
            // r7 repair (sweep): saving rewrites application/extra/maccms.php: POST + the admin token.
            if (!$this->runKeyTokenOk()) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            $config = input();
            $config_new['urlsend'] = $config['urlsend'];

            $config_old = config('maccms');
            $config_new = array_merge($config_old, $config_new);

            $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
            if ($res === false) {
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $urlsend_config = $GLOBALS['config']['urlsend'];
        $this->assign('config',$urlsend_config);

        $extends = \app\admin\util\GlobalModules::extendsList('urlsend');
        $this->assign('extends',$extends);


        $site_url = trim((string)($GLOBALS['config']['site']['site_url'] ?? ''));
        if (!preg_match('/^https?:\/\//i', $site_url)) {
            $site_url = ($GLOBALS['http_type'] ?? 'http://') . $site_url;
        }
        $this->assign('site_url', rtrim($site_url, '/'));

        $this->assign('title',lang('admin/urlsend/title'));
        return $this->fetch('admin@urlsend/index');
    }

    public function data()
    {
        // xss-fix-B: the direct data page printed ?page= and the content names raw (reflected XSS by a link). The push
        // services are removed in this distribution, so the direct page answers like index(); every printed value is
        // a number or escaped below.
        if (strtolower((string)request()->action()) === 'data' && empty(\app\admin\util\GlobalModules::extendsList('urlsend')['ext_list'])) {
            return $this->error(lang('admin/module_removed'));
        }
        mac_echo('<style type="text/css">body{font-size:12px;color: #333333;line-height:21px;}span{font-weight:bold;color:#FF0000}</style>');

        $list = [];
        $mid = intval(isset($this->_param['mid']) ? $this->_param['mid'] : 0);
        $page = intval(isset($this->_param['page']) && is_scalar($this->_param['page']) ? $this->_param['page'] : 1);
        $limit = intval(isset($this->_param['limit']) && is_scalar($this->_param['limit']) ? $this->_param['limit'] : 50);
        $this->_param['page'] = $page < 1 ? 1 : $page;
        $this->_param['limit'] = $limit < 1 ? 50 : min($limit, 1000);
        $ids = isset($this->_param['ids']) && is_scalar($this->_param['ids']) ? preg_replace('/[^0-9,]/', '', (string)$this->_param['ids']) : '';
        $ac2 = isset($this->_param['ac2']) && is_scalar($this->_param['ac2']) ? (string)$this->_param['ac2'] : '';
        $col_time = 'time';
        if($this->_param['range'] == '1'){
            $col_time = 'time_add';
        }
        $today = strtotime(date('Y-m-d'));
        $where = [];
        $col = '';
        switch($mid)
        {
            case 1:
                $where['vod_status'] = ['eq',1];

                if($ac2=='today'){
                    $where['vod_'.$col_time] = ['gt',$today];
                }
                if(!empty($ids)){
                    $where['vod_id'] = ['in',$ids];
                }
                elseif(!empty($data)){
                    $where['vod_id'] = ['gt', $data];
                }

                $col = 'vod';
                $order = 'vod_id asc';
                $fun = 'mac_url_vod_detail';
                $res = model('Vod')->listData($where,$order,$this->_param['page'],$this->_param['limit']);
                break;
            case 2:
                $where['art_status'] = ['eq',1];

                if($ac2=='today'){
                    $where['art_'.$col_time] = ['gt',$today];

                }
                if(!empty($ids)){
                    $where['art_id'] = ['in',$ids];
                }
                elseif(!empty($data)){
                    $where['art_id'] = ['gt', $data];
                }

                $col = 'art';
                $order = 'art_id asc';
                $fun = 'mac_url_art_detail';
                $res = model('Art')->listData($where,$order,$this->_param['page'],$this->_param['limit']);
                break;
            case 3:
                $where['topic_status'] = ['eq',1];

                if($ac2=='today'){
                    $where['topic_'.$col_time] = ['gt',$today];

                }
                if(!empty($ids)){
                    $where['topic_id'] = ['in',$ids];
                }
                elseif(!empty($data)){
                    $where['topic_id'] = ['gt', $data];
                }

                $col = 'topic';
                $order = 'topic_id asc';
                $fun = 'mac_url_topic_detail';
                $res = model('Topic')->listData($where,$order,$this->_param['page'],$this->_param['limit']);
                break;
            case 8:
                $where['actor_status'] = ['eq',1];

                if($ac2=='today'){
                    $where['actor_'.$col_time] = ['gt',$today];

                }
                if(!empty($ids)){
                    $where['actor_id'] = ['in',$ids];
                }
                elseif(!empty($data)){
                    $where['actor_id'] = ['gt', $data];
                }
                $col = 'actor';
                $order = 'actor_id asc';
                $fun = 'mac_url_actor_detail';
                $res = model('Actor')->listData($where,$order,$this->_param['page'],$this->_param['limit']);
                break;
            case 9:
                $where['role_status'] = ['eq',1];

                if($ac2=='today'){
                    $where['role_'.$col_time] = ['gt',$today];

                }
                if(!empty($ids)){
                    $where['role_id'] = ['in',$ids];
                }
                elseif(!empty($data)){
                    $where['role_id'] = ['gt', $data];
                }
                $col = 'role';
                $order = 'role_id asc';
                $fun = 'mac_url_role_detail';
                $res = model('Role')->listData($where,$order,$this->_param['page'],$this->_param['limit']);
                break;
            case 11:
                $where['website_status'] = ['eq',1];

                if($ac2=='today'){
                    $where['website_'.$col_time] = ['gt',$today];

                }
                if(!empty($ids)){
                    $where['website_id'] = ['in',$ids];
                }
                elseif(!empty($data)){
                    $where['website_id'] = ['gt', $data];
                }
                $col = 'website';
                $order = 'website_id asc';
                $fun = 'mac_url_website_detail';
                $res = model('Website')->listData($where,$order,$this->_param['page'],$this->_param['limit']);
                break;
        }

        if(empty($res['list'])){
            mac_echo(lang('admin/urlsend/no_data'));
            return;
        }

        mac_echo(lang('admin/urlsend/tip',[$res['total'],$res['pagecount'],$res['page']]));

        $urls = [];
        $site_url = trim((string)($GLOBALS['config']['site']['site_url'] ?? ''));
        if (!preg_match('/^https?:\/\//i', $site_url)) {
            $site_url = ($GLOBALS['http_type'] ?? 'http://') . $site_url;
        }
        $site_url = rtrim($site_url, '/');
        foreach($res['list'] as $k=>$v){
            $urls[$v[$col.'_id']] = $site_url . $fun($v);
            $this->_lastid = $v[$col.'_id'];

            $axU = htmlspecialchars((string)$urls[$v[$col.'_id']], ENT_QUOTES, 'UTF-8');
            mac_echo(intval($v[$col.'_id']) . ', ' . htmlspecialchars(html_entity_decode((string)$v[$col . '_name'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') . '&nbsp;<a href="' . $axU . '">' . $axU . '</a>');
        }

        $res['urls'] = $urls;

        // Direct data requests stream HTML with mac_echo(); keep the array for push().
        if (strtolower((string)request()->action()) === 'data') {
            return null;
        }

        return $res;
    }


    public function push($pp=[])
    {
        // r7 repair (sweep): $pp is only for the scheduled task (api.php Timming calls push($output) in the same process);
        // from the admin entry it is never trusted. There, step 1 must be the POST of the push form with the admin token
        // (it starts a run key, RunKey job "urlsend"); the next pages are GET jump links with only run and page.
        if(!empty($pp) && is_array($pp) && defined('ENTRANCE') && ENTRANCE !== 'admin'){
            $this->_param = $pp;
        }
        $ac = (string)($this->_param['ac'] ?? '');
        if (\app\admin\util\GlobalModules::blocked('urlsend', $ac)) {
            // Scheduled tasks created before the removal end quietly instead of calling Baidu.
            if (ENTRANCE == 'admin') {
                return $this->error(lang('admin/module_removed'));
            }
            mac_echo(lang('admin/module_removed'));
            return;
        }
        if (defined('ENTRANCE') && ENTRANCE === 'admin') {
            if (Request()->isPost()) {
                $bind = [];
                foreach ((array)$this->_param as $k => $v) {
                    if (is_scalar($v) && $k !== '__token__') { $bind[$k] = (string)$v; }
                }
                $run = $this->runKeyStart('urlsend', $bind);
                if ($run === false) {
                    return $this->error(lang('admin/ux/system/token_expired'));
                }
                $this->_param = $bind;
                $this->_param['run'] = $run;
            } else {
                $resumed = $this->runKeyResume('urlsend', (array)$this->_param);
                if ($resumed === false) {
                    return $this->error(lang('admin/ux/system/token_expired'));
                }
                $this->_param = $resumed;
            }
            $ac = (string)($this->_param['ac'] ?? '');
        }
        $cp = 'app\\common\\extend\\urlsend\\' . ucfirst($ac);
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $ac) && class_exists($cp)) {
            $data = $this->data();

            $c = new $cp;
            $res = $c->submit($data);

            if($res['code']!=1){
                mac_echo($res['msg']);
                die;
            }

            if ($data['page'] >= $data['pagecount']) {
                mac_echo(lang('admin/urlsend/complete'));
                if(ENTRANCE=='admin') {

                }
            }
            else {
                $this->_param['page']++;
                $url = url('urlsend/push') . '?' . http_build_query($this->_param);
                if(ENTRANCE=='admin') {
                    $url = $this->runKeyNextUrl(url('urlsend/push'), $this->_param);
                    mac_jump($url, 3);
                }
                else{
                    $this->push($this->_param);
                }
            }

        }
        else{
            $this->error(lang('param_err'));
        }
    }

}
