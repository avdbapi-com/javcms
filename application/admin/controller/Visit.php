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

class Visit extends Base
{
    use \app\admin\common\ListCsrf;

    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $param = input();
        $param['page'] = intval($param['page']) <1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) <1 ? $this->_pagesize : min(intval($param['limit']), 1000);
        $where=[];
        if(!empty($param['uid'])){
            $where['user_id'] = ['eq',$param['uid'] ];
        }
        if(isset($param['time']) && is_scalar($param['time']) && (string)$param['time'] !== ''){
            $param['time'] = intval($param['time']);
            $t = strtotime(date('Y-m-d',strtotime('-'.$param['time'] .' day')));
            $where['visit_time'] = ['egt', intval($t) ];
        }
        if(!empty($param['wd'])){
            $a = $param['wd'];
            if(substr($a,5)==='http:'){
                $b = str_replace('http:','https:',$a);
            }
            elseif(substr($a,5)==='https'){
                $b = str_replace('https:','http:',$a);
            }
            else{
                $a = 'http://'.$param['wd'];
                $b  = 'https://'.$param['wd'];
            }
            $where['visit_ly'] = ['like', [$a.'%',$b.'%'],'OR'];
        }

        $order='visit_id desc';
        $res = model('Visit')->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);
        // close-security (security r4 P2-5): "Clear all" names how many rows it deletes.
        $this->assign('total_all', (int)Db::name('Visit')->count());

        $this->assign('title',lang('admin/visit/title'));
        return $this->fetch('admin@visit/index');
    }

    public function del()
    {
        // close-security (security r4 P2-5 / P3-7): deletes are a POST with the admin token (a GET link or a replay
        // without the token deletes nothing). "Clear all" (all=1) also needs confirm=1, sent by the typed-DELETE dialog.
        if (!$this->listPostOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param = input('post.');
        $all = isset($param['all']) && is_scalar($param['all']) && (string)$param['all'] === '1';
        $ids = isset($param['ids']) ? (is_array($param['ids']) ? $param['ids'] : explode(',', (string)$param['ids'])) : [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($all && (!isset($param['confirm']) || (string)$param['confirm'] !== '1')) {
            return $this->error(lang('param_err'));
        }
        if($all || !empty($ids)){
            $where=[];
            $where['visit_id'] = $all ? ['gt',0] : ['in',$ids];
            $res = model('Visit')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

}
