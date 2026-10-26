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

class Gbook extends Base
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
            $where['gbook_status'] = ['eq',$param['status']];
        }
        if(in_array($param['type'],['1','2'])){
            if($param['type'] == 1){
                $where['gbook_rid'] = 0;
            }
            elseif($param['type'] ==2){
                $where['gbook_rid'] = ['gt',0];
            }
        }
        // Reply status is an admin-only filter: 1 means awaiting reply, 2 means replied.
        // Keep the public Gbook model/API unchanged and use the unsigned timestamp's zero sentinel.
        $replyStatus = isset($param['reply']) ? (string)$param['reply'] : '';
        if(in_array($replyStatus, ['1','2'], true)){
            $where['gbook_reply_time'] = $replyStatus === '1' ? ['eq', 0] : ['gt', 0];
        }
        if(!empty($param['uid'])){
            $where['user_id'] = ['eq',$param['uid'] ];
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['gbook_name|gbook_content'] = ['like','%'.$param['wd'].'%'];
        }


        $order='gbook_id desc';
        $res = model('Gbook')->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        // Chips (brief 1.4) with counts; FD7 needs the total for "Delete all {n}".
        $axCount = [
            'all' => (int)Db::name('Gbook')->count(),
            'unanswered' => (int)Db::name('Gbook')->where('gbook_reply_time', 0)->count(),
            'answered' => (int)Db::name('Gbook')->where('gbook_reply_time', '>', 0)->count(),
        ];
        $this->assign('ax_count', $axCount);
        $chip = 'all';
        if ($replyStatus === '1') { $chip = 'unanswered'; }
        elseif ($replyStatus === '2') { $chip = 'answered'; }
        $this->assign('chip', $chip);
        if ($chip !== 'all') {
            $this->assign('ax_head', ['title' => lang('admin/ux/menu/visitor_messages') . ' · ' . lang('admin/ux/content/chip_' . $chip)]);
        }
        // F17: only draw the buttons this admin may use.
        $this->assign('can', ['info'=>$this->check_auth('gbook','info'), 'del'=>$this->check_auth('gbook','del'), 'field'=>$this->check_auth('gbook','field')]);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);
        $this->assign('title',lang('admin/ux/menu/visitor_messages'));
        return $this->fetch('admin@gbook/index');
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
            $res = model('Gbook')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where=[];
        $where['gbook_id'] = ['eq',$id];
        $res = model('Gbook')->infoData($where);

        $this->assign('info',$res['info']);
        $this->assign('title',lang('admin/gbook/title'));
        return $this->fetch('admin@gbook/info');
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
            $where['gbook_id'] = ['in',$ids];
            if($all==1){
                $where['gbook_id'] = ['gt',0];
            }
            $res = model('Gbook')->delData($where);
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

        if(!empty($ids) && in_array($col,['gbook_status']) ){
            $where=[];
            $where['gbook_id'] = ['in',$ids];

            $res = model('Gbook')->fieldData($where,$col,$val);
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
