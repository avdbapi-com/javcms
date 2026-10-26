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

class Cash extends Base
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
        if($param['status']!=''){
            $where['cash_status'] = ['eq',$param['status']];
        }
        if(!empty($param['uid'])){
            $where['user_id'] = ['eq',$param['uid'] ];
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['cash_bank_no'] = ['like','%'.$param['wd'].'%' ];
        }

        $order='cash_id desc';
        $res = model('Cash')->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);

        $this->assign('title',lang('admin/cash/title'));
        return $this->fetch('admin@cash/index');
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
            $where['cash_id'] = ['in',$ids];
            if($all==1){
                $where['cash_id'] = ['gt',0];
            }
            $res = model('Cash')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function audit()
    {
        // close-security (security r4 P3-1): POST only, so the admin token never travels in a URL.
        if (!$this->listPostOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param = input();
        $ids = $param['ids'];
        if(!empty($ids)){
            $where=[];
            $where['cash_id'] = ['in',$ids];
            $res = model('Cash')->auditData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

}
