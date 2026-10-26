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

class Art extends Base
{
    use \app\admin\common\RunKey;

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
            $where['art_level'] = ['eq', $param['level']];
        }
        if (in_array($param['status'], ['0', '1'])) {
            $where['art_status'] = ['eq', $param['status']];
        }
        if (isset($param['lock']) && $param['lock'] !== '') {
            $where['art_lock'] = ['eq', $param['lock']];
        }
        if(!empty($param['pic'])){
            if($param['pic'] == '1'){
                $where['art_pic'] = ['eq',''];
            }
            elseif($param['pic'] == '2'){
                $where['art_pic'] = ['like','http%'];
            }
            elseif($param['pic'] == '3'){
                $where['art_pic'] = ['like','%#err%'];
            }
        }
        if(!empty($param['wd'])){
            $param['wd'] = urldecode($param['wd']);
            $param['wd'] = mac_filter_xss($param['wd']);
            $where['art_name'] = ['like','%'.$param['wd'].'%'];
        }

        if(!empty($param['repeat'])){
            if($param['page'] ==1){
                Db::execute('DROP TABLE IF EXISTS '.config('database.prefix').'tmpart');
                Db::execute('CREATE TABLE `'.config('database.prefix').'tmpart` (`id1` int unsigned DEFAULT NULL, `name1` varchar(1024) NOT NULL DEFAULT \'\') ENGINE=InnoDB COLLATE=utf8_general_ci');
                Db::execute('INSERT INTO `'.config('database.prefix').'tmpart` (SELECT min(art_id)as id1,art_name as name1 FROM '.config('database.prefix').'art GROUP BY name1 HAVING COUNT(name1)>1)');
            }
            $order='art_name asc';
            $res = model('Art')->listRepeatData($where,$order,$param['page'],$param['limit']);
        }
        else{
            $order='art_time desc';
            $res = model('Art')->listData($where,$order,$param['page'],$param['limit']);
        }

        foreach($res['list'] as $k=>&$v){
            $v['ismake'] = 1;
            if($GLOBALS['config']['view']['art_detail'] >0 && $v['art_time_make'] < $v['art_time']){
                $v['ismake'] = 0;
            }
        }

        $this->assign('list', $res['list']);
        $this->assign('total', $res['total']);
        $this->assign('page', $res['page']);
        $this->assign('limit', $res['limit']);

        // Chips (brief 1.4): plain links to the old filter URLs; the active one names the page.
        $chip = 'all';
        if (!empty($param['repeat'])) { $chip = 'dupes'; }
        elseif (isset($param['status']) && (string)$param['status'] === '0') { $chip = 'hidden'; }
        elseif (isset($param['lock']) && (string)$param['lock'] === '1') { $chip = 'protected'; }
        $this->assign('chip', $chip);
        if ($chip !== 'all') {
            $this->assign('ax_head', ['title' => lang('menu/art_data') . ' · ' . lang('admin/ux/content/chip_' . $chip)]);
        }
        $filtered = 0;
        foreach (['type','level','status','lock','pic','wd','repeat'] as $f) {
            if (isset($param[$f]) && (string)$param[$f] !== '') { $filtered = 1; break; }
        }
        $this->assign('filtered', $filtered);
        // F17: only draw the buttons this admin may use.
        $this->assign('can', ['info'=>$this->check_auth('art','info'), 'del'=>$this->check_auth('art','del'), 'field'=>$this->check_auth('art','field'), 'images_opt'=>$this->check_auth('images','opt'), 'make_make'=>$this->check_auth('make','make') && isset($GLOBALS['config']['view']['art_detail']) && (int)$GLOBALS['config']['view']['art_detail'] === 2]);
        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param', $param);

        $type_tree = model('Type')->getCache('type_tree');
        $this->assign('type_tree', $type_tree);

        $this->assign('title', lang('admin/art/title'));
        return $this->fetch('admin@art/index');
    }

    /** Filters shared by the bulk-edit page, its live count and its delete action (F44). */
    private function batchWhere(&$param)
    {
        $where = [];
        if(!empty($param['type'])){
            $where['type_id'] = ['eq',$param['type']];
        }
        if(!empty($param['level'])){
            $where['art_level'] = ['eq',$param['level']];
        }
        if(isset($param['status']) && in_array($param['status'],['0','1'],true)){
            $where['art_status'] = ['eq',$param['status']];
        }
        if(isset($param['lock']) && $param['lock'] !== ''){
            $where['art_lock'] = ['eq',$param['lock']];
        }
        if(!empty($param['pic'])){
            if($param['pic'] == '1'){
                $where['art_pic'] = ['eq',''];
            }
            elseif($param['pic'] == '2'){
                $where['art_pic'] = ['like','http%'];
            }
            elseif($param['pic'] == '3'){
                $where['art_pic'] = ['like','%#err%'];
            }
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['art_name'] = ['like','%'.$param['wd'].'%'];
        }
        return $where;
    }

    /** Raw progress output in the kit look (component 16) instead of white Times text. */
    private function rawOutputHead()
    {
        $root = rtrim(str_replace('\\', '/', dirname(request()->baseFile())), '/');
        $css = $root . '/static/css/admin_ux.css?v=' . @filemtime(ROOT_PATH . 'static/css/admin_ux.css');
        mac_echo('<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '"><body class="admin-studio ax-raw-output">');
    }

    public function batch()
    {
        $param = input();
        if (!empty($param)) {
            // r5 (admin-leftovers): steps 2..n of "Change articles" are GET jump links with only a run key; the settings
            // come from the session (stored by the POST of step 1), never from the link. A wrong or old key changes nothing.
            $resumed = false;
            if (!request()->isPost() && isset($param['run'])) {
                $resumed = true;
                $param = $this->runKeyResume('art_batch', $param);
                if ($param === false) {
                    return $this->error(lang('admin/ux/system/token_expired'));
                }
            }
            $where = $this->batchWhere($param);

            // Live count for "Apply to {n} articles" / the delete confirm (F44).
            if (!empty($param['count'])) {
                return json(['code'=>1, 'msg'=>'ok', 'data'=>['count'=>(int)model('Art')->countData($where), 'filtered'=>!empty($where)]]);
            }

            if (!empty($param['ck_del'])) {
                // Permanent delete: AJAX POST only, with a filter, the typed-word confirm and the CSRF token.
                if (!request()->isPost() || !request()->isAjax()) {
                    return $this->error(lang('param_err'));
                }
                $submitted = isset($param['__token__']) && is_string($param['__token__']) ? $param['__token__'] : '';
                if ($submitted === '' || !hash_equals((string)mac_admin_csrf_token(), $submitted)) {
                    return $this->error(lang('token_err'));
                }
                if (empty($where)) {
                    return $this->error(lang('admin/ux/content/batch_need_filter'));
                }
                if (!isset($param['confirm']) || (string)$param['confirm'] !== '1') {
                    return $this->error(lang('param_err'));
                }
                $count = (int)model('Art')->countData($where);
                $res = model('Art')->delData($where);
                if ($res['code'] > 1) {
                    return $this->error($res['msg']);
                }
                return $this->success(lang('multi_del_ok'), null, ['count'=>$count]);
            }

            if(empty($param['ck_level']) && empty($param['ck_status']) && empty($param['ck_lock']) && empty($param['ck_hits']) ){
                return $this->error(lang('param_err'));
            }
            // SEC r4 P1-1 + r5: this branch writes rows too. Step 1 = POST + the admin token (the form posts it) and starts a
            // run; the next steps carry only the run key (checked above). The token never goes into a link.
            if (!$resumed) {
                $run = $this->runKeyStart('art_batch', input('post.'));
                if ($run === false) {
                    return $this->error(lang('admin/ux/system/token_expired'));
                }
                $param['run'] = $run;
                unset($param['__token__'], $param['page'], $param['total'], $param['page_count']);
            }

            $this->rawOutputHead();

            if(empty($param['page'])){
                $param['page'] = 1;
            }
            $param['limit'] = min(max((int)$param['limit'], 1), 1000);
            if(empty($param['total'])) {
                $param['total'] = model('Art')->countData($where);
                $param['page_count'] = ceil($param['total'] / $param['limit']);
            }

            if($param['page'] > $param['page_count']) {
                $this->runKeyEnd('art_batch');
                mac_echo(lang('multi_set_ok'));
                mac_jump( url('art/batch') ,3);
                exit;
            }
            mac_echo('<strong>' . lang('admin/batch_tip',[$param['total'],$param['limit'],$param['page_count'],$param['page']]) . '</strong>');

            $page = $param['page_count'] - $param['page'] + 1;
            $order='art_id desc';
            $res = model('Art')->listData($where,$order,$page,$param['limit']);

            foreach($res['list'] as  $k=>$v){
                $where2 = [];
                $where2['art_id'] = $v['art_id'];

                $update = [];
                $des = $v['art_id'].','.htmlspecialchars(html_entity_decode((string)$v['art_name'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');

                if(!empty($param['ck_level']) && !empty($param['val_level'])){
                    $update['art_level'] = $param['val_level'];
                    $des .= '&nbsp;'.lang('level').': '.$param['val_level'].';';
                }
                if(!empty($param['ck_status']) && isset($param['val_status'])){
                    $update['art_status'] = $param['val_status'];
                    $des .= '&nbsp;'.lang('status').': '.($param['val_status'] ==1 ? lang('admin/ux/content/visible') : lang('admin/ux/content/hidden')) .';';
                }
                if(!empty($param['ck_lock']) && isset($param['val_lock'])){
                    $update['art_lock'] = $param['val_lock'];
                    $des .= '&nbsp;'.($param['val_lock']==1 ? lang('admin/ux/content/chip_protected') : lang('admin/ux/content/not_protected')).';';
                }
                if(!empty($param['ck_hits']) && !empty($param['val_hits_min']) && !empty($param['val_hits_max']) ){
                    $update['art_hits'] = rand($param['val_hits_min'],$param['val_hits_max']);
                    $des .= '&nbsp;'.lang('hits').': '.$update['art_hits'].';';
                }
                mac_echo($des);
                $res2 = model('Art')->where($where2)->update($update);

            }
            $param['page']++;
            $url = $this->runKeyNextUrl(url('art/batch'), $param);
            mac_jump( $url ,3);
            exit;
        }

        $type_tree = model('Type')->getCache('type_tree');
        $this->assign('type_tree',$type_tree);

        $this->assign('title',lang('admin/ux/menu/bulk_articles'));
        return $this->fetch('admin@art/batch');
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
            $res = model('Art')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where=[];
        $where['art_id'] = ['eq',$id];
        $res = model('Art')->infoData($where);

        $info = $res['info'];
        $this->assign('info',$info);
        $this->assign('art_page_list',(array)$info['art_page_list']);

        $type_tree = model('Type')->getCache('type_tree');
        $this->assign('type_tree',$type_tree);

        $this->assign('title',lang('admin/art/title'));
        return $this->fetch('admin@art/info');
    }

    public function del()
    {
        $param = input();
        // SEC r2 P1-4: every branch (row / bulk delete, duplicate count, duplicate delete) needs POST + the CSRF token.
        if (!request()->isPost()) {
            return $this->error(lang('param_err'));
        }
        if (!$this->axCsrfOk($param)) {
            return $this->error(lang('token_err'));
        }
        $ids = $param['ids'];

        if(!empty($ids)){
            $where=[];
            $where['art_id'] = ['in',$ids];
            $res = model('Art')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        elseif(!empty($param['repeat'])){
            // B6 pattern: keep ONE art per duplicate name (lowest or highest id), delete every other copy.
            // dry=1 only counts; the real delete needs POST, the CSRF token and confirm=1 (it cannot be undone).
            $keepMax = isset($param['retain']) && $param['retain'] === 'max';
            $groups = Db::name('Art')->field('art_name, MIN(art_id) AS mn, MAX(art_id) AS mx, COUNT(*) AS c')
                ->group('art_name')->having('COUNT(*) > 1')->select();
            $n = 0;
            foreach ($groups as $g) { $n += (int)$g['c'] - 1; }
            $m = count($groups);
            if (!empty($param['dry'])) {
                return $this->success('ok', null, ['n'=>$n, 'm'=>$m]);
            }
            $token = isset($param['__token__']) && is_string($param['__token__']) ? $param['__token__'] : '';
            if (!request()->isPost() || !isset($param['confirm']) || (string)$param['confirm'] !== '1') {
                return $this->error(lang('param_err'));
            }
            if ($token === '' || !hash_equals((string)mac_admin_csrf_token(), $token)) {
                return $this->error(lang('token_err'));
            }
            if ($m < 1) {
                return $this->error(lang('admin/ux/content/batch_none'));
            }
            foreach (array_chunk($groups, 500) as $chunk) {
                $names = array_column($chunk, 'art_name');
                $keep = array_map('intval', array_column($chunk, $keepMax ? 'mx' : 'mn'));
                $res = model('Art')->delData(['art_name'=>['in', $names], 'art_id'=>['not in', $keep]]);
                if($res['code']>1){
                    return $this->error($res['msg']);
                }
            }
            return $this->success(lang('del_ok'), null, ['n'=>$n, 'm'=>$m]);
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

        $type_pid = 0;
        if ($col == 'type_id') {
            // Category assignments must target this module and cannot use random ranges.
            if (!is_scalar($val) || !preg_match('/^[1-9][0-9]*$/D', (string)$val) || !empty($start)) {
                return $this->error(lang('param_err'));
            }
            $type_list = model('Type')->getCache();
            $val = intval($val);
            if (!isset($type_list[$val]) || intval($type_list[$val]['type_mid']) != 2) {
                return $this->error(lang('param_err'));
            }
            $type_pid = intval($type_list[$val]['type_pid']);
        }



        if(!empty($ids) && in_array($col,['art_status','art_lock','art_level','art_hits','type_id'])){
            $where=[];
            $where['art_id'] = ['in',$ids];
            $update = [];
            if(empty($start)) {
                $update[$col] = $val;
                if($col == 'type_id'){
                    $update['type_id_1'] = $type_pid;
                }
                $res = model('Art')->fieldData($where, $update);
            }
            else{
                if(empty($end)){$end = 9999;}
                $ids = explode(',',$ids);
                foreach($ids as $k=>$v){
                    $val = rand($start,$end);
                    $where['art_id'] = ['eq',$v];
                    $update[$col] = $val;
                    $res = model('Art')->fieldData($where, $update);
                }
            }
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function updateToday()
    {
        $param = input();
        $flag = $param['flag'];
        $res = model('Art')->updateToday($flag);
        return json($res);
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
