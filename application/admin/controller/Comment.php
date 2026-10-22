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

class Comment extends Base
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
        $param['limit'] = intval($param['limit']) <1 ? $this->_pagesize : min((int)$param['limit'], 1000);

        $where=[];
        if(in_array($param['status'],['0','1'],true)){
            $where['comment_status'] = ['eq',$param['status']];
        }
        if(in_array($param['mid'],['1','2','3'])){
            $where['comment_mid'] = ['eq',$param['mid']];
        }
        if(!empty($param['uid'])){
            $where['user_id'] = ['eq',$param['uid'] ];
        }
        if(!empty($param['report'])){
            if($param['report'] == 1){
                $where['comment_report'] = ['eq',0];
            }
            else{
                $where['comment_report'] = ['gt',0];
            }
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['comment_name|comment_content'] = ['like','%'.$param['wd'].'%'];
        }

        $order='comment_id desc';
        $res = model('Comment')->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        // Chips (brief 1.4) with counts; FD7 needs the total for "Delete all {n}".
        $axCount = [
            'all' => (int)Db::name('Comment')->count(),
            'waiting' => (int)Db::name('Comment')->where('comment_status', 0)->count(),
            'visible' => (int)Db::name('Comment')->where('comment_status', 1)->count(),
        ];
        $this->assign('ax_count', $axCount);
        $chip = 'all';
        if (isset($param['status']) && (string)$param['status'] === '0') { $chip = 'waiting'; }
        elseif (isset($param['status']) && (string)$param['status'] === '1') { $chip = 'visible'; }
        $this->assign('chip', $chip);
        if ($chip !== 'all') {
            $this->assign('ax_head', ['title' => lang('menu/comment') . ' · ' . lang('admin/ux/content/chip_' . $chip)]);
        }
        // F17: only draw the buttons this admin may use.
        $this->assign('can', ['info'=>$this->check_auth('comment','info'), 'del'=>$this->check_auth('comment','del'), 'field'=>$this->check_auth('comment','field')]);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);
        $this->assign('title',lang('menu/comment'));
        return $this->fetch('admin@comment/index');
    }

    public function info()
    {
        if (Request()->isPost()) {
            // close-security (security r4 P2-2): the editor posts __token__; a save replayed without it changes nothing.
            $param = input('post.');
            if (!$this->axCsrfOk($param)) {
                return $this->error(lang('token_err'));
            }
            unset($param['__token__']);
            $res = model('Comment')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where=[];
        $where['comment_id'] = ['eq',$id];
        $res = model('Comment')->infoData($where);

        $this->assign('info',$res['info']);
        $this->assign('title',lang('admin/comment/title'));
        return $this->fetch('admin@comment/info');
    }

    public function del()
    {
        $param = input();
        if (!$this->axCsrfOk($param)) {
            return $this->error(lang('token_err'));
        }
        $ids = $param['ids'];
        $all = $param['all'];

        if(!empty($all)){
            // FD7: "Delete all" only as a POST with the typed-word confirm and the CSRF token.
            $token = isset($param['__token__']) && is_string($param['__token__']) ? $param['__token__'] : '';
            if ($all != 1 || !request()->isPost() || !isset($param['confirm']) || (string)$param['confirm'] !== '1') {
                return $this->error(lang('param_err'));
            }
            if ($token === '' || !hash_equals((string)mac_admin_csrf_token(), $token)) {
                return $this->error(lang('token_err'));
            }
        }
        if(!empty($ids) || !empty($all)){
            $where=[];
            $where['comment_id'] = ['in',$ids];
            if($all==1){
                $where['comment_id'] = ['gt',0];
            }
            $res = model('Comment')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function field()
    {
        $param = input();
        if (!$this->axCsrfOk($param)) {
            return $this->error(lang('token_err'));
        }
        $ids = $param['ids'];
        $col = $param['col'];
        $val = $param['val'];

        if(!empty($ids) && in_array($col,['comment_status']) ){
            $where=[];
            $where['comment_id'] = ['in',$ids];

            $res = model('Comment')->fieldData($where,$col,$val);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }



    // CSRF: the list posts __token__ (mac_admin_csrf_token()); a write replayed without it is refused.
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
