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
use app\common\util\Pinyin;

class Role extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function data()
    {
        $param = input();
        $param['page'] = max(1, intval($param['page']));
        // B5: 1..1000 rows per page (default = the admin page size).
        $param['limit'] = intval($param['limit']) < 1 ? $this->_pagesize : min((int)$param['limit'], 1000);

        $where = [];
        if (!empty($param['level'])) {
            $where['role_level'] = ['eq', $param['level']];
        }
        if (in_array($param['status'], ['0', '1'])) {
            $where['role_status'] = ['eq', $param['status']];
        }
        if (!empty($param['rid'])) {
            $where['role_rid'] = ['eq', $param['rid']];
        }
        if(!empty($param['pic'])){
            if($param['pic'] == '1'){
                $where['role_pic'] = ['eq',''];
            }
            elseif($param['pic'] == '2'){
                $where['role_pic'] = ['like','http%'];
            }
            elseif($param['pic'] == '3'){
                $where['role_pic'] = ['like','%#err%'];
            }
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['role_name'] = ['like','%'.$param['wd'].'%'];
        }


        $order='role_time desc';
        $res = model('Role')->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list', $res['list']);
        $this->assign('total', $res['total']);
        $this->assign('page', $res['page']);
        $this->assign('limit', $res['limit']);

        // F17: only draw the buttons this admin may use.
        $this->assign('can', ['info'=>$this->check_auth('role','info'), 'del'=>$this->check_auth('role','del'), 'field'=>$this->check_auth('role','field'), 'images_opt'=>$this->check_auth('images','opt')]);
        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param', $param);

        $this->assign('title',lang('admin/role/title'));
        return $this->fetch('admin@role/index');
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
            $res = model('Role')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $tab = input('tab');
        // SEC r2 P1-2: role_rid is a video id; cast it so the request value is never printed raw.
        $rid = (int)input('rid');

        $where=[];
        $where['role_id'] = ['eq',$id];
        $res = model('Role')->infoData($where);
        $info = $res['info'];
        if(empty($info)){
            $info['role_rid'] =  $rid;
        }
        $this->assign('info',$info);

        $where=[];
        $where['vod_id'] = ['eq', $info['role_rid'] ];
        $res = model('Vod')->infoData($where);
        $data = $res['info'];
        $this->assign('data',$data);

        $this->assign('title',lang('admin/role/title'));
        return $this->fetch('admin@role/info');
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
            $where['role_id'] = ['in',$ids];
            $res = model('Role')->delData($where);
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
        $start = $param['start'];
        $end = $param['end'];


        if(!empty($ids) && in_array($col,['role_status','role_lock','role_level','role_hits'])){
            $where=[];
            $where['role_id'] = ['in',$ids];
            if(empty($start)) {
                $res = model('Role')->fieldData($where, $col, $val);
            }
            else{
                if(empty($end)){$end = 9999;}
                $ids = explode(',',$ids);
                foreach($ids as $k=>$v){
                    $val = rand($start,$end);
                    $where['role_id'] = ['eq',$v];
                    $res = model('Role')->fieldData($where, $col, $val);
                }
            }
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
