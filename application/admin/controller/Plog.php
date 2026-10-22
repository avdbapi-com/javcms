<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\admin\controller;
use think\Db;

class Plog extends Base
{
    use \app\admin\common\ListCsrf;

    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $param = input();
        $param['page'] = max(1, intval($param['page']));
        $param['limit'] = intval($param['limit']) <1 ? $this->_pagesize : min(max(intval($param['limit']), 1), 1000);
        $where=[];
        if(!empty($param['type'])){
            $where['plog_type'] = ['eq',$param['type']];
        }
        if(!empty($param['uid'])){
            $where['user_id'] = ['eq',$param['uid'] ];
        }

        $order='plog_id desc';
        $res = model('Plog')->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);

        $this->assign('title',lang('admin/plog/title'));
        return $this->fetch('admin@plog/index');
    }

    public function del()
    {
        if (!$this->listPostOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param = input();
        $ids = $param['ids'];
        $all = $param['all'];
        if(!empty($ids)){
            $where=[];
            $where['plog_id'] = ['in',$ids];
            if($all==1){
                $where['plog_id'] = ['gt',0];
            }
            $res = model('Plog')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

}
