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

class Link extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $param = input();
        $param['page'] = max(1, intval($param['page']));
        // B5: 1..1000 rows per page (default = the admin page size).
        $param['limit'] = intval($param['limit']) <1 ? $this->_pagesize : min((int)$param['limit'], 1000);
        $where=[];

        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['link_name'] = ['like','%'.$param['wd'].'%'];
        }

        $order='link_id desc';
        $res = model('Link')->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        // F17: only draw the buttons this admin may use.
        $this->assign('can', ['info'=>$this->check_auth('link','info'), 'del'=>$this->check_auth('link','del'), 'batch'=>$this->check_auth('link','batch')]);
        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);
        $this->assign('title',lang('menu/link'));
        return $this->fetch('admin@link/index');
    }

    public function info()
    {
        if (Request()->isPost()) {
            // close-security (security r4 P2-1): the editor posts __token__; a save replayed without it changes nothing.
            $param = input('post.');
            if (!$this->axCsrfOk($param)) {
                return $this->error(lang('token_err'));
            }
            unset($param['__token__']);
            $res = model('Link')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where=[];
        $where['link_id'] = ['eq',$id];
        $res = model('Link')->infoData($where);


        $this->assign('info',$res['info']);
        $this->assign('title',lang('admin/link/title'));
        return $this->fetch('admin@link/info');
    }

    public function del()
    {
        $param = input();
        if (!$this->axCsrfOk($param)) {
            return $this->error(lang('token_err'));
        }
        $ids = $param['ids'];

        if(!empty($ids)){
            $where=[];
            $where['link_id'] = ['in',$ids];
            $res = model('Link')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function batch()
    {
        // "Save changes" posts each changed row as <field>_<id>, so a row can never receive another row's values
        // (the old array form paired ids[k] with link_name[k] of ALL rows and overwrote the wrong link).
        $param = input();
        if (!$this->axCsrfOk($param)) {
            return $this->error(lang('token_err'));
        }
        $ids = isset($param['ids']) ? $param['ids'] : [];
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        if (!is_array($ids) || empty($ids)) {
            return $this->error(lang('admin/ux/kit/select_first'));
        }
        $res = ['code' => 1, 'msg' => lang('save_ok')];
        foreach ($ids as $k=>$id) {
            $id = intval($id);
            if ($id < 1) {
                continue;
            }
            $data = [];
            $data['link_id'] = $id;
            foreach (['link_name', 'link_sort', 'link_url', 'link_type', 'link_logo'] as $field) {
                if (isset($param[$field . '_' . $id]) && is_scalar($param[$field . '_' . $id])) {
                    $data[$field] = (string)$param[$field . '_' . $id];
                }
            }
            if (isset($data['link_type'])) {
                $data['link_type'] = intval($data['link_type']);
            }
            if (count($data) < 2) {
                continue;
            }
            if (isset($data['link_name']) && trim($data['link_name']) === '') {
                $data['link_name'] = lang('unknown');
            }
            $res = model('Link')->saveData($data);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
        }
        return $this->success($res['msg']);
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
