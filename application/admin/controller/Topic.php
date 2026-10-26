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
use think\Db;

class Topic extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function data()
    {
        $param = input();
        $param['page'] = intval($param['page']) <1 ? 1 : intval($param['page']);
        // B5: 1..1000 rows per page (default = the admin page size).
        $param['limit'] = intval($param['limit']) <1 ? $this->_pagesize : min((int)$param['limit'], 1000);

        $where=[];
        if(in_array($param['status'],['0','1'],true)){
            $where['topic_status'] = ['eq',$param['status']];
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['topic_name'] = ['like','%'.$param['wd'].'%'];
        }

        $order='topic_time desc';
        $res = model('Topic')->listData($where,$order,$param['page'],$param['limit']);

        foreach($res['list'] as $k=>&$v){
            $v['ismake'] = 1;
            if($GLOBALS['config']['view']['topic_detail'] >0 && $v['topic_time_make'] < $v['topic_time']){
                $v['ismake'] = 0;
            }
        }

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        // F17: only draw the buttons this admin may use.
        $this->assign('can', ['info'=>$this->check_auth('topic','info'), 'del'=>$this->check_auth('topic','del'), 'field'=>$this->check_auth('topic','field')]);
        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);
        $this->assign('title',lang('admin/topic/title'));
        return $this->fetch('admin@topic/index');
    }

    public function info()
    {
        if (Request()->isPost()) {
            $param = input('post.');
            // SEC r2 P2-1: the editor sends the admin CSRF token; refuse a save without it.
            if (!$this->axCsrfOk($param)) {
                return $this->error(lang('token_err'));
            }
            unset($param['__token__']);
            $res = model('Topic')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }


        $id = input('id');
        $where=[];
        $where['topic_id'] = ['eq',$id];
        $res = model('Topic')->infoData($where);


        $this->assign('info',$res['info']);

        $config = config('maccms.site');
        $this->assign('install_dir',$config['install_dir']);
        $this->assign('title',lang('admin/topic/title'));
        return $this->fetch('admin@topic/info');
    }

    public function del()
    {
        $param = input();
        // SEC r2 P2-1: delete needs POST + the CSRF token (the list sends both).
        if (!request()->isPost()) {
            return $this->error(lang('param_err'));
        }
        if (!$this->axCsrfOk($param)) {
            return $this->error(lang('token_err'));
        }
        $ids = $param['ids'];

        if(!empty($ids)){
            $where=[];
            $where['topic_id'] = ['in',$ids];
            $res = model('Topic')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function field()
    {
        // SEC r3 P2-1: this write needs POST + the admin token (the list and the bulk picker send __token__);
        // a plain GET link or a cross-site form without the token changes nothing.
        if (!request()->isPost()) {
            return $this->error(lang('param_err'));
        }
        if (!$this->axCsrfOk(input('post.'))) {
            return $this->error(lang('token_err'));
        }
        $param = input();
        $ids = $param['ids'];
        $col = $param['col'];
        $val = $param['val'];

        if(!empty($ids) && in_array($col,['topic_status','topic_level']) ){
            $where=[];
            $where['topic_id'] = ['in',$ids];

            $res = model('Topic')->fieldData($where,$col,$val);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    // SEC r2: admin CSRF token (__token__ field or X-CSRF-Token header), same rule as Type / Link / Comment.
    private function axCsrfOk($param)
    {
        // r5 (L-1 sweep): a write is POST-only; a token in a GET link (or a GET with the header) changes nothing.
        if (!request()->isPost()) {
            return false;
        }
        $t = isset($param['__token__']) && is_string($param['__token__']) ? $param['__token__'] : (string)request()->header('X-CSRF-Token');
        return $t !== '' && hash_equals((string)mac_admin_csrf_token(), $t);
    }

}
