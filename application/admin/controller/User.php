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

class User extends Base
{
    use \app\admin\common\ListCsrf;

    public function __construct()
    {
        parent::__construct();
    }

    public function data()
    {
        $param = input();
        $param['page'] = intval($param['page']) <1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) <1 ? $this->_pagesize : min(max(intval($param['limit']), 1), 1000);

        if($param['page'] ==1){
            model('User')->expire();
        }

        $where=[];
        if(in_array($param['status'],['0','1'],true)){
            $where['user_status'] = $param['status'];
        }
        if(!empty($param['group'])){
            $where['group_id'] = $param['group'];
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['user_name'] = ['like','%'.$param['wd'].'%'];
        }

        $order='user_id desc';
        $res = model('User')->listData($where,$order,$param['page'],$param['limit']);

        $group_list = model('Group')->getCache('group_list');
        foreach($res['list'] as $k=>$v){
            $res['list'][$k]['group_name'] = $group_list[$v['group_id']]['group_name'];
        }

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);

        $this->assign('group_list',$group_list);

        $this->assign('title',lang('admin/user/title'));
        return $this->fetch('admin@user/index');
    }

    public function reward()
    {
        $param = input();
        $param['page'] = intval($param['page']) <1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) <1 ? $this->_pagesize : min(max(intval($param['limit']), 1), 1000);
        $param['uid'] = intval($param['uid']);
        $where=[];
        if(!empty($param['level'])){
            if($param['level']=='1'){
                $where['user_pid'] = ['eq', $param['uid']];
            }
            elseif($param['level']=='2'){
                $where['user_pid_2'] = ['eq', $param['uid']];
            }
            elseif($param['level']=='3'){
                $where['user_pid_3'] = ['eq', $param['uid']];
            }
        }
        else{
            $where['user_pid|user_pid_2|user_pid_3'] = ['eq', intval($param['uid']) ];
        }

        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['user_name'] = ['like','%'.$param['wd'].'%'];
        }

        $order='user_id desc';
        $res = model('User')->listData($where,$order,$param['page'],$param['limit']);
        $group_list = model('Group')->getCache('group_list');
        foreach($res['list'] as $k=>$v){
            $res['list'][$k]['group_name'] = $group_list[$v['group_id']]['group_name'];
        }

        $where2=[];
        $where2['user_pid'] = ['eq', $param['uid']];
        $level_cc_1 = Db::name('User')->where($where2)->count();
        $where3 = [];
        $where3['user_id'] = $param['uid'];
        $where3['plog_type'] = 4;
        $points_cc_1 = Db::name('Plog')->where($where3)->sum('plog_points');

        $where2=[];
        $where2['user_pid_2'] = ['eq', $param['uid']];
        $level_cc_2 = Db::name('User')->where($where2)->count();
        $where3 = [];
        $where3['user_id'] = $param['uid'];
        $where3['plog_type'] = 5;
        $points_cc_2 = Db::name('Plog')->where($where3)->sum('plog_points');

        $where2=[];
        $where2['user_pid_3'] = ['eq', $param['uid']];
        $level_cc_3 = Db::name('User')->where($where2)->count();
        $where3 = [];
        $where3['user_id'] = $param['uid'];
        $where3['plog_type'] = 6;
        $points_cc_3 = Db::name('Plog')->where($where3)->sum('plog_points');

        $data=[];
        $data['level_cc_1'] = intval($level_cc_1);
        $data['level_cc_2'] = intval($level_cc_2);
        $data['level_cc_3'] = intval($level_cc_3);
        $data['points_cc_1'] = intval($points_cc_1);
        $data['points_cc_2'] = intval($points_cc_2);
        $data['points_cc_3'] = intval($points_cc_3);

        $this->assign('data',$data);
        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);

        $this->assign('title',lang('admin/user/title'));
        return $this->fetch('admin@user/reward');
    }


    public function info()
    {
        if (Request()->isPost()) {
            // SEC r3 P2-1: the member editor save needs the admin token (user/info.html sends it).
            if (!$this->listCsrfOk()) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            $param = input('post.');
            unset($param['__token__']);
            // r7 repair b (security P1-4): a member name follows the sign-up rule (letters and numbers only). An old name that
            // is saved back unchanged is still accepted, so members created before this rule stay editable.
            if (array_key_exists('user_name', $param)) {
                $newName = is_string($param['user_name']) ? $param['user_name'] : '';
                $oldName = '';
                if (!empty($param['user_id']) && is_scalar($param['user_id'])) {
                    $oldName = (string)Db::name('user')->where('user_id', (int)$param['user_id'])->value('user_name');
                }
                $unchanged = $oldName !== '' && ($newName === $oldName || $newName === html_entity_decode($oldName, ENT_QUOTES, 'UTF-8'));
                if (!$unchanged && $newName !== '' && !preg_match('/^[a-zA-Z0-9]+$/D', $newName)) {
                    return $this->error(lang('admin/ux/r7b/user_name_chars'));
                }
                if ($unchanged) {
                    $param['user_name'] = $oldName;
                }
            }
            $res = model('User')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where=[];
        $where['user_id'] = ['eq',$id];
        $res = model('User')->infoData($where);

        $this->assign('info',$res['info']);

        $order='group_id asc';
        $where=[];
        $res = model('Group')->listData($where,$order);
        $this->assign('group_list',$res['list']);

        $this->assign('title',lang('admin/user/title'));
        return $this->fetch('admin@user/info');
    }

    public function del()
    {
        if (!$this->listPostOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param = input();
        $ids = $param['ids'];

        if(!empty($ids)){
            $where=[];
            $where['user_id'] = ['in',$ids];
            $res = model('User')->delData($where);
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

        if(!empty($ids) && in_array($col,['user_status']) && in_array($val,['0','1'])){
            $where=[];
            $where['user_id'] = ['in',$ids];

            $res = model('User')->fieldData($where,$col,$val);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }




}
