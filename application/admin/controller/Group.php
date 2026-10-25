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

class Group extends Base
{
    use \app\admin\common\ListCsrf;

    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $param = input();
        $where=[];

        if(in_array($param['status'],['0','1'],true)){
            $where['group_status'] = ['eq',$param['status']];
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['group_name'] = ['like','%'.$param['wd'].'%'];
        }

        $order='group_id asc';
        $res = model('Group')->listData($where,$order);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);

        $this->assign('param',$param);
        $this->assign('title',lang('admin/group/title'));
        return $this->fetch('admin@group/index');
    }

    public function info()
    {
        if (Request()->isPost()) {
            // close-security (security r4 P2-3): the editor posts __token__; a save replayed without it changes nothing.
            if (!$this->listPostOk()) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            $param = input('post.');
            unset($param['__token__']);

            if($GLOBALS['config']['user']['reg_group'] == $param['group_id']){
                $param['group_status'] = 1;
            }
            $res = model('Group')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where=[];
        $where['group_id'] = ['eq',$id];
        $res = model('Group')->infoData($where);

        $this->assign('info',$res['info']);


        $type_tree = model('Type')->getCache('type_tree');
        $this->assign('type_tree',$type_tree);

        $this->assign('title',lang('admin/group/title'));
        return $this->fetch('admin@group/info');
    }

    public function del()
    {
        if (!$this->listPostOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param = input();
        $ids = $param['ids'];

        if(!empty($ids)){

            if(strpos(','.$ids.',', ','.$GLOBALS['config']['user']['reg_group'].',')!==false){
                return $this->error(lang('admin/group/reg_group_del_err'));
            }

            $where=[];
            $where['group_id'] = ['in',$ids];
            $res = model('Group')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function field()
    {
        if (!$this->listPostOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param = input();
        $ids = $param['ids'];
        $col = $param['col'];
        $val = $param['val'];

        if(!empty($ids) && in_array($col,['group_status']) && in_array($val,['0','1'])){
            $where=[];
            $where['group_id'] = ['in',$ids];

            $res = model('Group')->fieldData($where,$col,$val);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }


}
