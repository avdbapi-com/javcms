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

class Type extends Base
{
    public function __construct()
    {
        parent::__construct();
        $this->assign('title',lang('admin/type/title'));
    }

    public function index()
    {
        $order='type_sort asc';
        $where=[];
        $res = model('Type')->listData($where,$order,'tree');

        $list_count =[];
        // Number of videos
        $tmp = model('Vod')->field('type_id_1,type_id,count(vod_id) as cc')->where($where)->group('type_id_1,type_id')->select();
        foreach($tmp as $k=>$v){
            $list_count[$v['type_id_1']] += $v['cc'];
            $list_count[$v['type_id']] = $v['cc'];
        }
        // Number of articles
        $tmp = model('Art')->field('type_id_1,type_id,count(art_id) as cc')->where($where)->group('type_id_1,type_id')->select();
        foreach($tmp as $k=>$v){
            $list_count[$v['type_id_1']] += $v['cc'];
            $list_count[$v['type_id']] = $v['cc'];
        }

        // Number of actors
        $tmp = model('Actor')->field('type_id_1,type_id,count(actor_id) as cc')->where($where)->group('type_id_1,type_id')->select();
        foreach($tmp as $k=>$v){
            $list_count[$v['type_id_1']] += $v['cc'];
            $list_count[$v['type_id']] = $v['cc'];
        }
        // Number of website entries
        $tmp = model('Website')->field('type_id_1,type_id,count(website_id) as cc')->where($where)->group('type_id_1,type_id')->select();
        foreach($tmp as $k=>$v){
            $list_count[$v['type_id_1']] += $v['cc'];
            $list_count[$v['type_id']] = $v['cc'];
        }

        // Attach record counts to the category tree.
        foreach($res['list'] as $k=>$v){
            $res['list'][$k]['cc'] = intval($list_count[$v['type_id']]);
            foreach($v['child'] as $k2=>$v2){
                $res['list'][$k]['child'][$k2]['cc'] = intval($list_count[$v2['type_id']]);
            }
        }


        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        // F17: only draw the buttons this admin may use.
        $this->assign('can', ['info'=>$this->check_auth('type','info'), 'del'=>$this->check_auth('type','del'), 'field'=>$this->check_auth('type','field'), 'batch'=>$this->check_auth('type','batch'), 'move'=>$this->check_auth('type','move')]);
        $this->assign('title', lang('menu/type'));
        return $this->fetch('admin@type/index');
    }

    public function info()
    {
        if (Request()->isPost()) {
            $param = input('post.');
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }
            $res = model('Type')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            model('Type')->setCache();
            return $this->success($res['msg']);
        }

        // r5 repair (beginner r5 P2-4): GET ?preview=1 answers the Google preview of the editor's unsaved name / SEO
        // title / description (read-only, nothing is saved).
        if ((string)input('get.preview', '') === '1') {
            $sample = [];
            foreach (['type_name', 'type_title', 'type_des'] as $k) {
                $v = input('get.' . $k, '', null);
                $sample[$k] = is_string($v) ? mb_substr($v, 0, 300, 'UTF-8') : '';
            }
            $sample['type_id'] = (int)input('get.id', 0);
            $sample['type_mid'] = 1;
            return json(['code' => 1, 'msg' => '', 'data' => $this->typeSerp($sample)]);
        }

        $id = input('id');
        $pid = input('pid');
        $where=[];
        $where['type_id'] = ['eq',$id];
        $res = model('Type')->infoData($where);
        $cur = $res['info'];
        $this->assign('type_serp', $this->typeSerp([
            'type_id' => isset($cur['type_id']) ? (int)$cur['type_id'] : 0,
            'type_mid' => 1,
            'type_name' => isset($cur['type_name']) ? html_entity_decode((string)$cur['type_name'], ENT_QUOTES, 'UTF-8') : '',
            'type_title' => isset($cur['type_title']) ? html_entity_decode((string)$cur['type_title'], ENT_QUOTES, 'UTF-8') : '',
            'type_des' => isset($cur['type_des']) ? html_entity_decode((string)$cur['type_des'], ENT_QUOTES, 'UTF-8') : '',
        ]));
        try {
            $domain = (string)\app\common\util\Sitemap::absolute(\app\common\util\SiteIcons::base());
        } catch (\Throwable $e) {
            $domain = '';
        }
        $this->assign('type_domain', $domain !== '' ? rtrim(preg_replace('~^https?://~i', '', $domain), '/') : 'example.com');

        $where=[];
        $where['type_id'] = ['eq',$pid];
        $resp = model('Type')->infoData($where);

        $this->assign('info',$res['info']);
        $this->assign('infop',$resp['info']);
        $this->assign('pid',$pid);

        $where=[];
        $where['type_pid'] = ['eq','0'];
        $order='type_sort asc';
        $parent = model('Type')->listData($where,$order);
        $this->assign('parent',$parent['list']);

        return $this->fetch('admin@type/info');
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
            $where['type_id'] = ['in',$ids];
            $res = model('Type')->delData($where);
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

        if(!empty($ids) && in_array($col,['type_status']) && in_array($val,['0','1'])){
            $where=[];
            $where['type_id'] = ['in',$ids];

            $res = model('Type')->fieldData($where,$col,$val);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function batch()
    {
        // F52 "Save changes": update only the fields the list posts for each changed row
        // (the list no longer shows template files, so they must never be blanked here).
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
            $data['type_id'] = $id;
            foreach (['type_name', 'type_sort', 'type_en', 'type_tpl', 'type_tpl_list', 'type_tpl_detail'] as $field) {
                if (isset($param[$field . '_' . $id]) && is_scalar($param[$field . '_' . $id])) {
                    $data[$field] = (string)$param[$field . '_' . $id];
                }
            }
            if (!isset($data['type_name']) || trim($data['type_name']) === '') {
                $data['type_name'] = isset($data['type_name']) ? lang('unknown') : (string)Db::name('Type')->where('type_id', $id)->value('type_name');
            }

            $res = model('Type')->saveData($data);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
        }
        return $this->success($res['msg']);
    }

    public function extend()
    {
        $param = input();
        $id = isset($param['id']) && is_scalar($param['id']) ? trim((string)$param['id']) : '';
        if ($id === '' || !ctype_digit($id) || (int)$id <= 0) {
            return $this->error(lang('param_err'));
        }

        $type_list = model('Type')->getCache('type_list');
        if (!is_array($type_list) || !array_key_exists($id, $type_list)) {
            return $this->error(lang('get_info_err'));
        }
        $type_info = $type_list[$id];
        if (!is_array($type_info) || empty($type_info)) {
            return $this->error(lang('get_info_err'));
        }

        $type_mid = isset($type_info['type_mid']) ? $type_info['type_mid'] : 1;
        $type_pid = isset($type_info['type_pid']) && is_scalar($type_info['type_pid'])
            ? (string)$type_info['type_pid'] : '';
        $type_pinfo = ($type_pid !== '' && array_key_exists($type_pid, $type_list)
            && is_array($type_list[$type_pid])) ? $type_list[$type_pid] : [];
        $type_extend = isset($type_info['type_extend']) && is_array($type_info['type_extend'])
            ? $type_info['type_extend'] : [];
        $type_pextend = isset($type_pinfo['type_extend']) && is_array($type_pinfo['type_extend'])
            ? $type_pinfo['type_extend'] : [];

        $config = config('maccms.app');
        if (!is_array($config)) {
            $config = [];
        }

        if($type_mid==2) {
            if(empty($type_extend['class']) && !empty($type_pextend['class'])){
                $type_extend['class'] = $type_pextend['class'];
            }
            elseif(empty($type_extend['class']) && !empty($config['art_extend_class'])){
                $type_extend['class'] = $config['art_extend_class'];
            }
        }
        else{
            if(empty($type_extend['class']) && !empty($type_pextend['class'])){
                $type_extend['class'] = $type_pextend['class'];
            }
            elseif(empty($type_extend['class']) && !empty($config['vod_extend_class'])){
                $type_extend['class'] = $config['vod_extend_class'];
            }

            if(empty($type_extend['state']) && !empty($type_pextend['state'])){
                $type_extend['state'] = $type_pextend['state'];
            }
            elseif(empty($type_extend['state']) && !empty($config['vod_extend_state'])){
                $type_extend['state'] = $config['vod_extend_state'];
            }

            if(empty($type_extend['version']) && !empty($type_pextend['version'])){
                $type_extend['version'] = $type_pextend['version'];
            }
            elseif(empty($type_extend['version']) && !empty($config['vod_extend_version'])){
                $type_extend['version'] = $config['vod_extend_version'];
            }

            if(empty($type_extend['area']) && !empty($type_pextend['area'])){
                $type_extend['area'] = $type_pextend['area'];
            }
            elseif(empty($type_extend['area']) && !empty($config['vod_extend_area'])){
                $type_extend['area'] = $config['vod_extend_area'];
            }

            if(empty($type_extend['lang']) && !empty($type_pextend['lang'])){
                $type_extend['lang'] = $type_pextend['lang'];
            }
            elseif(empty($type_extend['lang']) && !empty($config['vod_extend_lang'])){
                $type_extend['lang'] = $config['vod_extend_lang'];
            }

            if(empty($type_extend['year']) && !empty($type_pextend['year'])){
                $type_extend['year'] = $type_pextend['year'];
            }
            elseif(empty($type_extend['year']) && !empty($config['vod_extend_year'])){
                $type_extend['year'] = $config['vod_extend_year'];
            }
        }

        $extend = [];
        if(!empty($type_extend)){
            foreach($type_extend as $key=>$value){
                if (is_array($value)) {
                    $options = [];
                    foreach ($value as $option) {
                        if (is_scalar($option)) {
                            $options[] = (string)$option;
                        }
                    }
                }
                elseif (is_scalar($value)) {
                    $options = explode(',', (string)$value);
                }
                else {
                    continue;
                }
                $extend[$key] = $options;
            }
        }

        return $this->success('ok',null,$extend);
    }

    public function move()
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
        $val = $param['val'];
        if(!empty($ids) && !empty($val)){
            $where=[];
            $where['type_id'] = ['in',$ids];
            $res = model('Type')->moveData($where,$val);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }


    /** r5 repair (beginner r5 P2-4): title + description of a category page as search engines get them. */
    private function typeSerp(array $sample)
    {
        try {
            $pv = \app\common\util\TemplateSeo::preview('category', $sample);
            return ['title' => (string)$pv['title'], 'description' => (string)$pv['description']];
        } catch (\Throwable $e) {
            $title = trim((string)($sample['type_title'] ?? '')) !== '' ? (string)$sample['type_title'] : (string)($sample['type_name'] ?? '');
            return ['title' => $title, 'description' => (string)($sample['type_des'] ?? '')];
        }
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
