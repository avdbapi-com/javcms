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

class Actor extends Base
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
        if (!empty($param['type'])) {
            $where['type_id|type_id_1'] = ['eq', $param['type']];
        }
        if (!empty($param['level'])) {
            $where['actor_level'] = ['eq', $param['level']];
        }
        if (in_array($param['status'], ['0', '1'])) {
            $where['actor_status'] = ['eq', $param['status']];
        }
        if(!empty($param['pic'])){
            if($param['pic'] == '1'){
                $where['actor_pic'] = ['eq',''];
            }
            elseif($param['pic'] == '2'){
                $where['actor_pic'] = ['like','http%'];
            }
            elseif($param['pic'] == '3'){
                $where['actor_pic'] = ['like','%#err%'];
            }
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['actor_name'] = ['like','%'.$param['wd'].'%'];
        }

        $order='actor_time desc';
        $res = model('Actor')->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list', $res['list']);
        $this->assign('total', $res['total']);
        $this->assign('page', $res['page']);
        $this->assign('limit', $res['limit']);

        // F17: only draw the buttons this admin may use.
        $this->assign('can', ['info'=>$this->check_auth('actor','info'), 'del'=>$this->check_auth('actor','del'), 'field'=>$this->check_auth('actor','field'), 'images_opt'=>$this->check_auth('images','opt')]);
        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param', $param);

        $type_tree = model('Type')->getCache('type_tree');
        $this->assign('type_tree', $type_tree);

        $this->assign('title', lang('admin/actor/title'));
        return $this->fetch('admin@actor/index');
    }

    /**
     * F13 / F45: a fresh install has no category of this model, so "Add" could never save.
     * When none exists, create "Actors" (model 8) with the category editor's defaults and return its id.
     */
    private function ensureModelCategory()
    {
        // A second save in the same editor (the page still has no category list): use the category when there is exactly one.
        $existing = Db::name('Type')->where('type_mid', 8)->column('type_id');
        if (!empty($existing)) {
            return count($existing) === 1 ? (int)reset($existing) : 0;
        }
        $res = model('Type')->saveData([
            'type_name' => lang('admin/ux/content/actor_cat'), 'type_en' => 'actors', 'type_mid' => 8, 'type_pid' => 0,
            'type_status' => 1, 'type_sort' => 1, 'type_extend' => '',
            'type_tpl' => 'type.html', 'type_tpl_list' => 'show.html', 'type_tpl_detail' => 'detail.html',
            'type_tpl_play' => '', 'type_tpl_down' => '',
        ]);
        if ($res['code'] > 1) {
            return 0;
        }
        return (int)Db::name('Type')->where('type_mid', 8)->order('type_id desc')->value('type_id');
    }

    public function info()
    {
        if (Request()->isPost()) {
            $param = input('post.');
            // SEC r2 P1-4: the editor sends the admin CSRF token; refuse a save without it.
            if (!$this->axCsrfOk($param)) {
                return $this->error(lang('token_err'));
            }
            unset($param['__token__']);
            if (empty($param['type_id'])) {
                $autoType = $this->ensureModelCategory();
                if ($autoType > 0) {
                    $param['type_id'] = $autoType;
                }
            }
            $res = model('Actor')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where=[];
        $where['actor_id'] = ['eq',$id];
        $res = model('Actor')->infoData($where);
        $info = $res['info'];
        $this->assign('info',$info);

        $type_tree = model('Type')->getCache('type_tree');
        $this->assign('type_tree', $type_tree);
        $this->assign('has_model_type', Db::name('Type')->where('type_mid', 8)->value('type_id') ? 1 : 0);

        $this->assign('title',lang('admin/actor/title'));
        return $this->fetch('admin@actor/info');
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
            $where['actor_id'] = ['in',$ids];
            $res = model('Actor')->delData($where);
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


        if(!empty($ids) && in_array($col,['actor_status','actor_lock','actor_level','type_id','actor_hits'])){
            $where=[];
            $update = [];
            $where['actor_id'] = ['in',$ids];
            if(empty($start)){
                $update[$col] = $val;
                if($col == 'type_id'){
                    $type_list = model('Type')->getCache();
                    $id1 = intval($type_list[$val]['type_pid']);
                    $update['type_id_1'] = $id1;
                }
                $res = model('Actor')->fieldData($where, $update);
            }
            else{
                if(empty($end)){$end = 9999;}
                $ids = explode(',',$ids);
                foreach($ids as $k=>$v){
                    $val = rand($start,$end);
                    $where['actor_id'] = ['eq',$v];
                    $update[$col] = $val;
                    $res = model('Actor')->fieldData($where, $update);
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
