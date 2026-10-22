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

class Vod extends Base
{
    use \app\admin\common\RunKey;

    public function __construct()
    {
        parent::__construct();
    }

    public function data()
    {
        $param = input();
        $param['page'] = intval($param['page']) <1 ? 1 : intval($param['page']);
        // B5: 1..1000 rows per page (default = the admin page size).
        $param['limit'] = intval($param['limit']) <1 ? $this->_pagesize : min((int)$param['limit'], 1000);

        $param['recycle'] = isset($param['recycle']) && $param['recycle'] == '1' ? 1 : 0;
        $where = ['_recycle'=>$param['recycle'] ? 'recycle' : 'active'];
        if(!empty($param['type'])){
            $where['type_id|type_id_1'] = ['eq',$param['type']];
        }
        if(!empty($param['level'])){
            $where['vod_level'] = ['eq',$param['level']];
        }
        if(in_array($param['status'],['0','1'])){
            $where['vod_status'] = ['eq',$param['status']];
        }
        if(in_array($param['copyright'],['0','1'])){
            $where['vod_copyright'] = ['eq',$param['copyright']];
        }
        if(in_array($param['isend'],['0','1'])){
            $where['vod_isend'] = ['eq',$param['isend']];
        }
        if(isset($param['lock']) && $param['lock'] !== ''){
            $where['vod_lock'] = ['eq',$param['lock']];
        }
        if(!empty($param['state'])){
            $where['vod_state'] = ['eq',$param['state']];
        }
        if(!empty($param['area'])){
            // $where [\'vod_area\'] = [\'eq\', $param [\'area\']];// Previous exact-match area filter
            $where['vod_area'] = ['like','%'.$param['area'].'%'];
        }
        if(!empty($param['lang'])){
            $where['vod_lang'] = ['eq',$param['lang']];
        }
        if(in_array($param['plot'],['0','1'])){
            $where['vod_plot'] = ['eq',$param['plot']];
        }

        if(!empty($param['url'])){
            if($param['url']==1){
                $where['vod_play_url'] = '';
            }
        }
        if(!empty($param['points'])){
            $where['vod_points_play|vod_points_down'] = ['gt', 0];
        }
        // Chip "New (24 h)" (brief 1.4).
        if(!empty($param['added'])){
            $where['vod_time_add'] = ['egt', time() - 86400];
        }
        if(!empty($param['pic'])){
            if($param['pic'] == '1'){
                $where['vod_pic'] = ['eq',''];
            }
            elseif($param['pic'] == '2'){
                $where['vod_pic'] = ['like','http%'];
            }
            elseif($param['pic'] == '3'){
                $where['vod_pic'] = ['like','%#err%'];
            }
        }
        if(!empty($param['weekday'])){
            $where['vod_weekday'] = ['like','%'.$param['weekday'].'%'];
        }
        // 1.2.0 (D-L13): a number or a code is an exact vod_id / vod_sub lookup; a word goes through the search planner
        // (published videos); the LIKE over three columns (15-20 s full scan at 7 M) stays only for the hidden / recycle
        // chips and while the search table is not ready.
        $adminFt = null;
        if(!empty($param['wd'])){
            $param['wd'] = urldecode($param['wd']);
            $param['wd'] = mac_filter_xss($param['wd']);
            $wdTrim = trim((string)$param['wd']);
            if (preg_match('/^[0-9]{1,10}$/D', $wdTrim)) {
                $where['vod_id|vod_sub'] = ['eq', $wdTrim];
            } elseif (preg_match('/^[A-Za-z0-9]{1,12}(?:[-_][A-Za-z0-9]{1,12}){1,3}$/D', $wdTrim) && preg_match('/[0-9]/', $wdTrim)) {
                $where['vod_sub'] = ['eq', $wdTrim];
            } elseif (!$param['recycle'] && !(isset($param['status']) && (string)$param['status'] === '0')
                && \app\common\model\Vod::ftSearchMode() !== 'old') {
                $adminFt = $wdTrim;
            } else {
                // Preserve the community search across video names, actors and subtitles.
                $where['vod_name|vod_actor|vod_sub'] = ['like','%'.$param['wd'].'%'];
            }
        }
        if(!empty($param['player'])){
            if($param['player']=='no'){
                $where['vod_play_from'] = [['eq', ''], ['eq', 'no'], 'or'];
            }
            else {
                $where['vod_play_from'] = ['like', '%' . $param['player'] . '%'];
            }
        }
        if(!empty($param['downer'])){
            if($param['downer']=='no'){
                $where['vod_down_from'] = [['eq', ''], ['eq', 'no'], 'or'];
            }
            else {
                $where['vod_down_from'] = ['like', '%' . $param['downer'] . '%'];
            }
        }
        if(!empty($param['server'])){
            $where['vod_play_server|vod_down_server'] = ['like','%'.$param['server'].'%'];
        }
        $order='vod_time desc';
        if(in_array($param['order'],['vod_id','vod_hits','vod_hits_month','vod_hits_week','vod_hits_day'])){
            $order = $param['order'] .' desc';
        }

        // D-L13: at T3 the duplicate finder (GROUP BY over every name: minutes and GBs of temp tables at 7 M) is the CLI job
        if (!empty($param['repeat']) && \app\common\model\Vod::tierInfo()['tier'] >= 3) {
            return $this->error($this->dupesCliText());
        }
        if(!empty($param['repeat'])){
            if($param['page'] ==1){
                Db::execute('DROP TABLE IF EXISTS '.config('database.prefix').'tmpvod');
                Db::execute('CREATE TABLE `'.config('database.prefix').'tmpvod` (`id1` int unsigned DEFAULT NULL, `name1` varchar(1024) NOT NULL DEFAULT \'\') ENGINE=InnoDB COLLATE=utf8_general_ci');
                Db::execute('INSERT INTO `'.config('database.prefix').'tmpvod` (SELECT min(vod_id)as id1,vod_name as name1 FROM '.config('database.prefix').'vod WHERE vod_recycle_time = 0 GROUP BY name1 HAVING COUNT(name1)>1)');
            }
            $order='vod_name asc';
            $res = model('Vod')->listRepeatData($where,$order,$param['page'],$param['limit']);
        }
        elseif ($adminFt !== null) {
            $res = $this->adminWordSearch($adminFt, $where, $param);
        }
        else{
            // D-L13: unfiltered total from the precomputed counts (C4), filtered lists a bounded count ("10,000+").
            // XVID-NOLIMIT-BRIEF B4 / S6: no page cap; the unfiltered list (every not-recycled row, newest first) reads its deep
            // pages from the page map (key 'adm', exact total from the map); a filtered list continues past its bounded count
            // with after=<sort value>.<vod_id> (page_cursor_next of the last numbered page).
            // Category filter as D-L1b: a parent -> type_id_1 = P (+ UNION ALL type_id = P), a child -> type_id = X; the
            // type_id|type_id_1 OR form read 7 M rows and sorted them at scale (measured: step 3, acceptance 11).
            $adminParent = 0;
            if (isset($where['type_id|type_id_1'])) {
                $tid = (int)$where['type_id|type_id_1'][1];
                unset($where['type_id|type_id_1']);
                if ($this->adminTypeHasChildren($tid)) {
                    $adminParent = $tid;
                } else {
                    $where['type_id'] = ['eq', $tid];
                }
            }
            $adminAfter = isset($param['after']) && is_string($param['after']) && preg_match('/^([0-9]{1,10})\.([0-9]{1,10})$/D', $param['after'], $am)
                ? [(int)$am[1], (int)$am[2]] : null;
            $scaleTotal = $this->adminScaleTotal($param);
            $mapOpt = [];
            if ($scaleTotal !== null && empty($param['type']) && $order === 'vod_time desc' && empty($param['recycle'])
                && class_exists('\app\common\util\ListPageMap')) {
                $mapOpt['pagemap'] = ['lk' => 'adm', 'hidden' => [], 'asc' => false];
                $mapTotal = \app\common\util\ListPageMap::total('adm');
                if ($mapTotal !== null) {
                    $scaleTotal = $mapTotal; // hidden videos included, as the list shows them
                }
            }
            $res = model('Vod')->listDataScaled($where, $order, $adminAfter ? 1 : $param['page'], $param['limit'], 0, '*', 1, [
                'totalshow' => true, 'cap' => 0, 'scale_total' => $scaleTotal, 'parent' => $adminParent,
                'open_total' => $this->adminOpenTotal($param), 'after' => $adminAfter,
            ] + $mapOpt);
        }
        // B3 / C3 continuation of the list (hand-over: the list view shows an "Older" link from it)
        $this->assign('page_cursor_next', isset($res['page_cursor_next']) ? (string)$res['page_cursor_next'] : '');
        $this->assign('page_cursor_on', !empty($res['page_cursor_on']) ? 1 : 0);
        $this->assign('total_more', !empty($res['total_more']) ? 1 : 0);
        $this->assign('total_all', (int)($res['total_all'] ?? $res['total']));


        foreach($res['list'] as $k=>&$v){
            $v['ismake'] = 1;
            if($GLOBALS['config']['view']['vod_detail'] >0 && $v['vod_time_make'] < $v['vod_time']){
                $v['ismake'] = 0;
            }
        }

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        // Chips (brief 1.4): each chip is the old menu URL; the active one names the page ("All videos · Hidden").
        $chip = 'all';
        if ($param['recycle']) { $chip = 'recycle'; }
        elseif (!empty($param['repeat'])) { $chip = 'dupes'; }
        elseif (isset($param['status']) && (string)$param['status'] === '0') { $chip = 'hidden'; }
        elseif (!empty($param['added'])) { $chip = 'new24'; }
        elseif (!empty($param['url']) && $param['url'] == 1) { $chip = 'nolink'; }
        elseif (isset($param['lock']) && (string)$param['lock'] === '1') { $chip = 'protected'; }
        elseif (!empty($param['points'])) { $chip = 'paid'; }
        $this->assign('chip', $chip);
        $hasPaid = \think\Cache::get('admin_vod_has_paid');
        if (($hasPaid === false || $hasPaid === null) && \app\common\model\Vod::tierInfo()['tier'] >= 3) {
            // D-L13 at T3: no full-table scan for the "Paid" chip (step 3: 9 s at 7.1 M when no video is paid); the chip shows
            $hasPaid = 1;
        } elseif ($hasPaid === false || $hasPaid === null) {
            $hasPaid = Db::name('Vod')->where('vod_points_play|vod_points_down', 'gt', 0)->value('vod_id') ? 1 : 0;
            \think\Cache::set('admin_vod_has_paid', $hasPaid, 600);
        }
        $this->assign('has_paid', (int)$hasPaid);
        $filtered = 0;
        foreach (['type','level','status','copyright','isend','lock','state','area','lang','plot','url','points','added','pic','weekday','wd','player','downer','server','repeat'] as $f) {
            if (isset($param[$f]) && (string)$param[$f] !== '') { $filtered = 1; break; }
        }
        $this->assign('filtered', $filtered);
        if ($chip !== 'all') {
            $this->assign('ax_head', ['title' => lang('menu/vod_data') . ' · ' . lang('admin/ux/content/chip_' . $chip)]);
        }
        // F17: only draw the buttons this admin may use.
        $this->assign('can', [
            'info' => $this->check_auth('vod', 'info'), 'del' => $this->check_auth('vod', 'del'),
            'field' => $this->check_auth('vod', 'field'), 'restore' => $this->check_auth('vod', 'restore'),
            'images' => $this->check_auth('images', 'opt'), 'make' => $this->check_auth('make', 'make') && $this->axStaticOn(['vod_detail', 'vod_play', 'vod_down']),
        ]);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);

        // Load the category tree
        $type_tree = model('Type')->getCache('type_tree');
        $this->assign('type_tree',$type_tree);

        // Assign player, download-source, and server lists
        $this->assignBaseListByConfig();
        $this->assign('title',lang('admin/vod/title'));
        return $this->fetch('admin@vod/index');
    }

    /** Admin text with the English fallback while the lang key is not shipped yet (keys: lane E, HO-S-6). */
    private function uxText(string $key, string $english, array $vars = [])
    {
        $text = lang($key);
        if (!is_string($text) || $text === '' || $text === $key) {
            $text = $english;
        }
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string)$v, $text);
        }
        return $text;
    }

    /** D-L13: the duplicate finder is a CLI job at T3; the page names the command. */
    private function dupesCliText()
    {
        return $this->uxText('admin/ux/scale/dupes_cli', 'At this catalog size the duplicate finder runs from the command line: {command}',
            ['command' => 'php tools/vod-duplicates.php']);
    }

    /**
     * D-L13 at T3: a filter other than the category (status, level, lock, area ... - columns without an index in the slim set)
     * gets an open-ended pager instead of a bounded count (one extra row decides "next page").
     */
    private function adminOpenTotal(array $param)
    {
        if (\app\common\model\Vod::tierInfo()['tier'] < 3) {
            return false;
        }
        foreach (['level','status','copyright','isend','lock','state','area','lang','plot','url','points','added','pic','weekday','player','downer','server'] as $f) {
            if (isset($param[$f]) && (string)$param[$f] !== '') {
                return true;
            }
        }
        return false;
    }

    /** D-L1b for the admin list: true when another category names $typeId as its parent. */
    private function adminTypeHasChildren(int $typeId)
    {
        $list = model('Type')->getCache('type_list');
        foreach (is_array($list) ? $list : [] as $t) {
            if ((int)($t['type_pid'] ?? 0) === $typeId) {
                return true;
            }
        }
        return false;
    }

    /** D-L13: total of the unfiltered admin list from the precomputed counts (C4); null = unknown or filtered (bounded count). */
    private function adminScaleTotal(array $param)
    {
        if (!empty($param['recycle']) || !class_exists('\app\common\util\ScaleCounts')) {
            return null;
        }
        foreach (['level','status','copyright','isend','lock','state','area','lang','plot','url','points','added','pic','weekday','wd','player','downer','server','repeat'] as $f) {
            if (isset($param[$f]) && (string)$param[$f] !== '') {
                return null;
            }
        }
        try {
            return !empty($param['type']) ? \app\common\util\ScaleCounts::type((int)$param['type']) : \app\common\util\ScaleCounts::all();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * D-L13: admin word search through the planner (C2): published videos, newest first; the other filters of the list
     * apply to the rows of the found ids. XVID-NOLIMIT-BRIEF B4 / C2 / C3: no page cap: a single word pages through the term
     * map to its last page (exact total), other queries are numbered up to FtPlan::NUMBERED_FIRST pages and continue with
     * cursor=<doc id> (page_cursor_next).
     */
    private function adminWordSearch($wd, array $where, array $param)
    {
        $page = max(1, (int)$param['page']);
        $limit = max(1, min(\app\common\util\FtPlan::PER_MAX, (int)$param['limit']));
        $cursor = isset($param['cursor']) && is_string($param['cursor']) && preg_match('/^[1-9][0-9]{0,17}$/D', $param['cursor']) ? (int)$param['cursor'] : 0;
        if ($cursor > 0) {
            $page = 1;
        }
        $types = [];
        if (isset($where['type_id|type_id_1'])) {
            $types = [(int)$where['type_id|type_id_1'][1]];
            unset($where['type_id|type_id_1']);
        }
        unset($where['_recycle']);
        $plan = \app\common\util\FtPlan::search($wd, ['page' => $page, 'per' => $limit, 'hidden' => [], 'type_ids' => $types, 'cursor' => $cursor]);
        $list = [];
        if ($plan['ids']) {
            $byId = [];
            foreach (Db::name('Vod')->where($where)->where('vod_recycle_time', 0)->where('vod_id', 'in', $plan['ids'])->select() as $row) {
                $byId[(int)$row['vod_id']] = $row;
            }
            foreach ($plan['ids'] as $id) {
                if (isset($byId[(int)$id])) { $list[] = $byId[(int)$id]; }
            }
        }
        $type_list = model('Type')->getCache('type_list');
        foreach ($list as $k => $v) {
            if (!empty($v['type_id']) && isset($type_list[$v['type_id']])) {
                $list[$k]['type'] = $type_list[$v['type_id']];
                $list[$k]['type_1'] = $type_list[$list[$k]['type']['type_pid']] ?? [];
            }
        }
        $found = (int)$plan['found'];
        if ($cursor > 0) {
            $found = count($plan['ids']);
        } elseif (($plan['path'] ?? '') === 'walk') {
            $found = min($found, \app\common\util\FtPlan::NUMBERED_FIRST * $limit);
        }
        return ['code' => 1, 'msg' => lang('data_list'), 'page' => $page, 'pagecount' => (int)ceil($found / $limit), 'limit' => $limit,
            'total' => $found, 'list' => $list, 'total_all' => max($found, (int)$plan['total']), 'total_more' => empty($plan['exact']) && $plan['total'] > $found]
            + (!empty($plan['next']) ? ['page_cursor_next' => (string)(int)$plan['next']] : []) + ($cursor > 0 ? ['page_cursor_on' => 1] : []);
    }

    /** Filters shared by the bulk-edit page, its live count and its danger actions (F43). */
    private function batchWhere(&$param)
    {
        $where = [];
        if(!empty($param['type'])){
            $where['type_id'] = ['eq',$param['type']];
        }
        if(!empty($param['level'])){
            $where['vod_level'] = ['eq',$param['level']];
        }
        if(isset($param['status']) && in_array($param['status'],['0','1'],true)){
            $where['vod_status'] = ['eq',$param['status']];
        }
        if(isset($param['copyright']) && in_array($param['copyright'],['0','1'],true)){
            $where['vod_copyright'] = ['eq',$param['copyright']];
        }
        if(isset($param['isend']) && in_array($param['isend'],['0','1'],true)){
            $where['vod_isend'] = ['eq',$param['isend']];
        }
        if(isset($param['lock']) && $param['lock'] !== ''){
            $where['vod_lock'] = ['eq',$param['lock']];
        }
        if(!empty($param['state'])){
            $where['vod_state'] = ['eq',$param['state']];
        }
        if(!empty($param['area'])){ // Match area names within the stored value
            $where['vod_area'] = ['like','%'.$param['area'].'%'];
        }
        if(!empty($param['lang'])){
            $where['vod_lang'] = ['eq',$param['lang']];
        }
        if(!empty($param['url'])){
            if($param['url']==1){
                $where['vod_play_url'] = '';
            }
        }
        if(!empty($param['pic'])){
            if($param['pic'] == '1'){
                $where['vod_pic'] = ['eq',''];
            }
            elseif($param['pic'] == '2'){
                $where['vod_pic'] = ['like','http%'];
            }
            elseif($param['pic'] == '3'){
                $where['vod_pic'] = ['like','%#err%'];
            }
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            // Preserve the community batch operation's name search.
            $where['vod_name'] = ['like','%'.$param['wd'].'%'];
        }
        if(!empty($param['weekday'])){
            $where['vod_weekday'] = ['like','%'.$param['weekday'].'%'];
        }
        if(!empty($param['player'])){
            if($param['player']=='no'){
                $where['vod_play_from'] = [['eq', ''], ['eq', 'no'], 'or'];
            }
            else {
                $where['vod_play_from'] = ['like', '%' . $param['player'] . '%'];
            }
        }
        if(!empty($param['downer'])){
            if($param['downer']=='no'){
                $where['vod_down_from'] = [['eq', ''], ['eq', 'no'], 'or'];
            }
            else {
                $where['vod_down_from'] = ['like', '%' . $param['downer'] . '%'];
            }
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
            // r5 (admin-leftovers): steps 2..n ("Change videos", "Remove a source") are GET jump links with only a run key;
            // the settings come from the session (stored by the POST of step 1), never from the link.
            $resumed = false;
            if (!request()->isPost() && isset($param['run'])) {
                $resumed = true;
                $param = $this->runKeyResume('vod_batch', $param);
                if ($param === false) {
                    return $this->error(lang('admin/ux/system/token_expired'));
                }
            }
            $where = $this->batchWhere($param);

            // Live count for "Apply to {n} videos" and the danger confirm (F43).
            if (!empty($param['count'])) {
                return json(['code'=>1, 'msg'=>'ok', 'data'=>['count'=>(int)model('Vod')->countData($where), 'filtered'=>!empty($where)]]);
            }
            if (empty($where)) {
                return $this->error(lang('admin/ux/content/batch_need_filter'));
            }

            $del = isset($param['ck_del']) ? (string)$param['ck_del'] : '';
            if ($del !== '' && $del !== '0') {
                // Danger card only: a known action, the confirm flag and the CSRF token on every step.
                if (!in_array($del, ['1','2','3'], true) || !isset($param['confirm']) || (string)$param['confirm'] !== '1') {
                    return $this->error(lang('param_err'));
                }
                if (!$resumed) {
                    $csrf = $this->validateVodCsrf($param, true);
                    if ($csrf !== null) { return $csrf; }
                }
                if ($del === '1') {
                    if (!request()->isPost()) { return $this->error(lang('param_err')); }
                    $count = (int)model('Vod')->countData($where);
                    $seoPingRecycled = [];
                    try { $seoPingRecycled = Db::name('vod')->where($where)->where('vod_recycle_time', 0)->limit(50000)->column('vod_id'); } catch (\Throwable $e) { }
                    $this->seoPingQueue($seoPingRecycled);
                    $res = model('Vod')->recycleData($where);
                    if ($res['code'] > 1) { return $this->error($res['msg']); }
                    $this->seoPingFlush();
                    return $this->success(str_replace('{n}', (string)$count, lang('admin/ux/content/batch_recycled')), null, ['count'=>$count]);
                }
                if($del === '2' && empty($param['player'])){
                    return $this->error(lang('admin/vod/del_play_must_select_play'));
                }
                if($del === '3' && empty($param['downer'])){
                    return $this->error(lang('admin/vod/del_down_must_select_down'));
                }
            }
            elseif(empty($param['ck_level']) && empty($param['ck_status']) && empty($param['ck_lock']) && empty($param['ck_hits'])
                && empty($param['ck_points']) && empty($param['ck_copyright'])
            ){
                return $this->error(lang('admin/ux/content/batch_pick_change'));
            }
            else {
                // "Change videos" never deletes anything.
                unset($param['ck_del']);
                $del = '';
                // SEC r4 P1-1 + r5: this branch writes rows too (step 1 = POST + token; see the run below).
            }
            if (!$resumed) {
                // r5: step 1 = POST + the admin token; it starts a run. The token never goes into a jump link.
                $run = $this->runKeyStart('vod_batch', input('post.'));
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
                $param['total'] = model('Vod')->countData($where);
                $param['page_count'] = ceil($param['total'] / $param['limit']);
            }

            if($param['page'] > $param['page_count']) {
                $this->runKeyEnd('vod_batch');
                mac_echo(lang('multi_opt_ok'));
                mac_jump( url('vod/batch') ,3);
                exit;
            }
            mac_echo('<strong>' . lang('admin/batch_tip',[$param['total'],$param['limit'],$param['page_count'],$param['page']]) . '</strong>');

            $page = $param['page_count'] - $param['page'] + 1;
            $order='vod_id desc';
            $res = model('Vod')->listData($where,$order,$page,$param['limit']);

            $ftTouched = [];
            foreach($res['list'] as  $k=>$v){
                $where2 = [];
                $where2['vod_id'] = $v['vod_id'];

                $update = [];
                $des = $v['vod_id'].','.htmlspecialchars(html_entity_decode((string)$v['vod_name'], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');

                if ($del === '') {
                    if(!empty($param['ck_level']) && !empty($param['val_level'])){
                        $update['vod_level'] = $param['val_level'];
                        $des .= '&nbsp;'.lang('level').': '.$param['val_level'].';';
                    }
                    if(!empty($param['ck_status']) && isset($param['val_status'])){
                        $update['vod_status'] = $param['val_status'];
                        $des .= '&nbsp;'.lang('status').': '.($param['val_status'] ==1 ? lang('admin/ux/content/visible') : lang('admin/ux/content/hidden')) .';';
                    }
                    if(!empty($param['ck_copyright']) && isset($param['val_copyright'])){
                        $update['vod_copyright'] = $param['val_copyright'];
                        $des .= '&nbsp;'.lang('copyright').': '.($param['val_copyright'] ==1 ? '['.lang('open').']':'['.lang('close').']') .';';
                    }
                    if(!empty($param['ck_lock']) && isset($param['val_lock'])){
                        $update['vod_lock'] = $param['val_lock'];
                        $des .= '&nbsp;'.($param['val_lock']==1 ? lang('admin/ux/content/chip_protected') : lang('admin/ux/content/not_protected')).';';
                    }
                    if(!empty($param['ck_hits']) && $param['val_hits_min']!='' && $param['val_hits_max']!='' ){
                        $update['vod_hits'] = rand($param['val_hits_min'],$param['val_hits_max']);
                        $des .= '&nbsp;'.lang('hits').': '.$update['vod_hits'].';';
                    }
                    if(!empty($param['ck_points']) && $param['val_points_play']!=''  ){
                        $update['vod_points_play'] = $param['val_points_play'];
                        $des .= '&nbsp;'.lang('points_play').': '.$param['val_points_play'].';';
                    }
                    if(!empty($param['ck_points']) && $param['val_points_down']!='' ){
                        $update['vod_points_down'] = $param['val_points_down'];
                        $des .= '&nbsp;'.lang('points_down').': '.$param['val_points_down'].';';
                    }
                }
                elseif($del === '2' || $del === '3'){
                    if($del === '2') {
                        $pre = 'vod_play';
                        $par = 'player';
                        $des .= '&nbsp;'.lang('play_group').': ';
                    }
                    else {
                        $pre = 'vod_down';
                        $par='downer';
                        $des .= '&nbsp;'.lang('down_group').': ';
                    }

                    if($param[$par] == $v[$pre.'_from']){
                        $update[$pre.'_from'] = '';
                        $update[$pre.'_server'] = '';
                        $update[$pre.'_note'] = '';
                        $update[$pre.'_url'] = '';
                        $des .= lang('del_empty').';';
                    }
                    else{
                        $vod_from_arr = explode('$$$',$v[$pre.'_from']);
                        $vod_server_arr = explode('$$$',$v[$pre.'_server']);
                        $vod_note_arr = explode('$$$',$v[$pre.'_note']);
                        $vod_url_arr = explode('$$$',$v[$pre.'_url']);

                        $key = array_search($param[$par],$vod_from_arr);
                        if($key!==false){
                            unset($vod_from_arr[$key]);
                            unset($vod_server_arr[$key]);
                            unset($vod_note_arr[$key]);
                            unset($vod_url_arr[$key]);

                            $update[$pre.'_from'] = join('$$$',$vod_from_arr);
                            $update[$pre.'_server'] = join('$$$',$vod_server_arr);
                            $update[$pre.'_note'] = join('$$$',$vod_note_arr);
                            $update[$pre.'_url'] = join('$$$',$vod_url_arr);
                            $des .= lang('del'). ';';
                        }
                        else{
                            $des .= lang('jump_over').';';
                        }
                    }
                }

                mac_echo($des);
                if (!empty($update)) {
                    // fp-port-c (FINISH-PORT-REVIEW-b P2-1): a status change commits with the video's search-queue entry, so a page
                    // cut before its end (browser closed, timeout) leaves no video shown again without a document or queue entry
                    $res2 = \app\common\model\Vod::queuedWrite(function () use ($where2, $update) { return model('Vod')->where($where2)->update($update); },
                        array_key_exists('vod_status', $update) ? [(int)$v['vod_id']] : []);
                    if ($res2 && array_key_exists('vod_status', $update)) {
                        $ftTouched[(int)$v['vod_id']] = (int)$update['vod_status'] !== 1;
                        if (class_exists('\app\common\util\FilterVocab')) { \app\common\util\FilterVocab::touched([(int)$v['vod_id']]); }
                    }
                }

            }
            // 1.2.0 (D-S6): a status change re-queues the videos of this page for the search table; hidden ones are purged
            // from the edge cache (C5). One call per page of the run.
            if (!empty($ftTouched)) {
                $hidden = array_keys(array_filter($ftTouched));
                $shown = array_keys(array_filter($ftTouched, function ($h) { return !$h; }));
                if ($hidden) { \app\common\model\Vod::searchTouch($hidden, true); }
                if ($shown) { \app\common\model\Vod::searchTouch($shown, false); }
                \app\common\model\Vod::bumpListRevision(true);
            }
            // IndexNow: the videos of this page changed (status, protection, points, sources).
            $seoPingPage = [];
            foreach ($res['list'] as $v) { $seoPingPage[] = $v['vod_id']; }
            $this->seoPingQueue($seoPingPage);
            $this->seoPingFlush();
            $param['page']++;
            $url = $this->runKeyNextUrl(url('vod/batch'), $param);
            mac_jump( $url ,3);
            exit;
        }

        // Load the category tree
        $type_tree = model('Type')->getCache('type_tree');
        $this->assign('type_tree',$type_tree);

        // Assign player, download-source, and server lists
        $this->assignBaseListByConfig();
        $this->assign('title',lang('admin/ux/menu/bulk_videos'));
        return $this->fetch('admin@vod/batch');
    }

    /**
     * Stable CSRF validation for protected VOD actions. If configuration is absent,
     * protection stays enabled; only an explicit boolean false disables this check.
     */
    protected function validateVodCsrf(&$param, $force = false)
    {
        // r5 (L-1 sweep): every caller is a write, so it is POST-only, even where the token check is switched off.
        if (!request()->isPost()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $config = config('maccms.admin_vod_csrf');
        if (!$force && is_array($config) && array_key_exists('enabled', $config) && $config['enabled'] === false) {
            return null;
        }

        $submitted = isset($param['__token__']) && is_string($param['__token__'])
            ? trim($param['__token__']) : '';
        if ($submitted === '') {
            $submitted = trim((string)request()->header('X-CSRF-Token'));
        }
        $expected = function_exists('mac_admin_csrf_token') ? (string)mac_admin_csrf_token() : '';
        if ($submitted === '' || $expected === '' || !hash_equals($expected, $submitted)) {
            return $this->error(lang('token_err'));
        }
        unset($param['__token__']);
        return null;
    }

    /** IndexNow (SEO-ADMIN-BRIEF E.3, HO-S7): queue videos the owner saved, hid or deleted now; never breaks the action. */
    private function seoPingQueue($ids, $force = true)
    {
        try {
            if (!class_exists('\app\common\util\SeoPing')) { return; }
            $list = is_array($ids) ? $ids : explode(',', (string)$ids);
            \app\common\util\SeoPing::queueVods(array_slice(array_values(array_filter(array_map('intval', $list))), 0, 50000), $force);
        } catch (\Throwable $e) {
            error_log('[IndexNow] queue: ' . get_class($e));
        }
    }

    /** IndexNow: send the queue when due (at most one POST per 60 s; no-op while IndexNow is off). */
    private function seoPingFlush()
    {
        try {
            if (class_exists('\app\common\util\SeoPing')) { \app\common\util\SeoPing::flush(); }
        } catch (\Throwable $e) {
            error_log('[IndexNow] flush: ' . get_class($e));
        }
    }

    public function info()
    {
        if (Request()->isPost()) {
            $param = input('post.');
            $csrfError = $this->validateVodCsrf($param);
            if ($csrfError !== null) {
                return $csrfError;
            }
            $seoPingStart = time();
            $res = model('Vod')->saveData($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            // IndexNow: the saved video (a new one is the newest row added by this save).
            $seoPingSaved = (int)($param['vod_id'] ?? 0);
            if ($seoPingSaved < 1) { $seoPingSaved = (int)Db::name('vod')->where('vod_time_add', '>=', $seoPingStart)->order('vod_id desc')->value('vod_id'); }
            if ($seoPingSaved > 0) { $this->seoPingQueue([$seoPingSaved]); $this->seoPingFlush(); }
            return $this->success($res['msg']);
        }

        $query = Request()->param(false);
        $rawId = is_array($query) && array_key_exists('id', $query) ? $query['id'] : null;
        if ($rawId === null || $rawId === '') {
            // An ID-less request is the existing add-video route.
            $info = [];
        } else {
            if (!is_scalar($rawId) || !preg_match('/^[1-9][0-9]*$/D', trim((string)$rawId))) {
                return $this->error(lang('param_err'));
            }
            $id = (int)$rawId;
            $where=[];
            $where['vod_id'] = ['eq', $id];
            $res = model('Vod')->infoData($where);
            if (empty($res['info']) || (!empty($res['code']) && $res['code'] > 1)) {
                return $this->error(isset($res['msg']) ? $res['msg'] : lang('obtain_err'));
            }
            $info = $res['info'];
        }
        $this->assign('info',$info);

        // Load the category tree
        $type_tree = model('Type')->getCache('type_tree');
        $this->assign('type_tree',$type_tree);

        // Load region and language lists
        $config = config('maccms.app');
        $area_list = explode(',',$config['vod_area']);
        $lang_list = explode(',',$config['vod_lang']);
        $this->assign('area_list',$area_list);
        $this->assign('lang_list',$lang_list);

     // Load the user group list
        $group_list = model('Group')->getCache('group_list');
        $this->assign('group_list',$group_list);

        // Assign player, download-source, and server lists
        $this->assignBaseListByConfig();

        // Prepare playback and download lists. Preserve imported no/unknown playback
        // groups so editing unrelated metadata cannot silently discard direct URLs.
        $vodPlayList = isset($info['vod_play_list']) ? (array)$info['vod_play_list'] : [];
        $rawPlayList = mac_play_list_unconfigured(
            $info['vod_play_from'] ?? '',
            $info['vod_play_url'] ?? '',
            $info['vod_play_server'] ?? '',
            $info['vod_play_note'] ?? '',
            'play'
        );
        foreach ($rawPlayList as $rawPlayGroup) {
            $vodPlayList[] = $rawPlayGroup;
        }
        $this->assign('vod_play_list',$vodPlayList);
        $this->assign('vod_down_list',(array)($info['vod_down_list'] ?? []));
        $this->assign('vod_plot_list',(array)($info['vod_plot_list'] ?? []));


        $this->assign('title',lang('admin/vod/title'));
        return $this->fetch('admin@vod/info');
    }

    /**
     * Admin-only TMDB metadata proxy. The legacy button keeps its JSON field
     * mapping, while the API key and network calls stay on the server.
     */
    public function metadata()
    {
        $rawId = input('id');
        $id = is_scalar($rawId) ? trim((string)$rawId) : '';
        if (!preg_match('/^[1-9][0-9]{0,8}$/', $id)) {
            return json(['status_code' => 400, 'status_message' => lang('admin/vod/metadata_id_invalid')]);
        }

        $apiKey = $this->metadataApiKey();
        if ($apiKey === '') {
            return json(['status_code' => 503, 'status_message' => 'Metadata service is not configured.']);
        }

        $baseUrl = 'https://api.themoviedb.org/3/movie/' . rawurlencode($id);
        $query = '?api_key=' . rawurlencode($apiKey);
        $movie = $this->metadataRequest($baseUrl . $query . '&language=en');
        if (!$movie['ok']) {
            return json($this->metadataError($movie));
        }

        $movieData = $movie['data'];
        if (empty($movieData['overview']) || empty($movieData['original_title'])) {
            $original = $this->metadataRequest($baseUrl . $query);
            if ($original['ok']) {
                if (empty($movieData['overview']) && !empty($original['data']['overview'])) {
                    $movieData['overview'] = $original['data']['overview'];
                }
                if (empty($movieData['original_title']) && !empty($original['data']['original_title'])) {
                    $movieData['original_title'] = $original['data']['original_title'];
                }
            }
        }

        $credits = $this->metadataRequest($baseUrl . '/credits' . $query);
        $movieData['credits'] = [];
        if ($credits['ok']) {
            $movieData['credits'] = $credits['data'];
        } else {
            $movieData['metadata_warning'] = 'Movie data loaded, but cast and director data is unavailable.';
        }

        return json($movieData);
    }

    private function metadataApiKey()
    {
        $environmentKey = getenv('MACCMS_TMDB_API_KEY');
        if (is_string($environmentKey) && trim($environmentKey) !== '') {
            return trim($environmentKey);
        }

        $secretFile = ROOT_PATH . 'application/extra/external.php';
        if (is_file($secretFile)) {
            $secrets = include $secretFile;
            if (is_array($secrets) && !empty($secrets['tmdb_api_key'])) {
                return trim((string)$secrets['tmdb_api_key']);
            }
        }

        if (isset($GLOBALS['config']['extra']['tmdb_api_key'])) {
            return trim((string)$GLOBALS['config']['extra']['tmdb_api_key']);
        }
        return '';
    }

    private function metadataRequest($url)
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'message' => 'Metadata service is unavailable.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'JAVCMS admin metadata/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 1) {
            return ['ok' => false, 'status' => 0, 'message' => 'Metadata service is unavailable.'];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'status' => $status, 'message' => 'Metadata service returned invalid data.'];
        }
        if ($status < 200 || $status >= 300) {
            $message = !empty($data['status_message']) ? (string)$data['status_message'] : 'Metadata service returned HTTP ' . $status . '.';
            return ['ok' => false, 'status' => $status, 'message' => $message];
        }
        return ['ok' => true, 'status' => $status, 'data' => $data];
    }

    private function metadataError($result)
    {
        $status = isset($result['status']) ? (int)$result['status'] : 502;
        if ($status < 400 || $status > 599) {
            $status = 502;
        }
        return ['status_code' => $status, 'status_message' => $result['message'] ?: 'Metadata service request failed.'];
    }

    public function iplot()
    {
        if (Request()->isPost()) {
            $param = input('post.');
            $csrfError = $this->validateVodCsrf($param);
            if ($csrfError !== null) {
                return $csrfError;
            }
            $res = model('Vod')->savePlot($param);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $query = Request()->param(false);
        $rawId = is_array($query) && array_key_exists('id', $query) ? $query['id'] : null;
        if (!is_scalar($rawId) || !preg_match('/^[1-9][0-9]*$/D', trim((string)$rawId))) {
            return $this->error(lang('param_err'));
        }
        $id = (int)$rawId;
        $where=[];
        $where['vod_id'] = ['eq', $id];
        $res = model('Vod')->infoData($where);
        if (empty($res['info']) || (!empty($res['code']) && $res['code'] > 1)) {
            return $this->error(isset($res['msg']) ? $res['msg'] : lang('obtain_err'));
        }

        $info = $res['info'];
        $this->assign('info',$info);
        $this->assign('vod_plot_list',$info['vod_plot_list']);


        $this->assign('title',lang('admin/vod/plot/title'));
        return $this->fetch('admin@vod/iplot');
    }

    public function restore()
    {
        $param = input();
        $csrf = $this->validateVodCsrf($param);
        if ($csrf !== null) { return $csrf; }
        if (empty($param['ids'])) { return $this->error(lang('param_err')); }
        $res = model('Vod')->restoreData(['vod_id'=>['in',$param['ids']]]);
        return $res['code'] > 1 ? $this->error($res['msg']) : $this->success($res['msg']);
    }

    /** Permanently delete only explicitly selected videos from the displayed scope. */
    protected function permanentDeleteSelected($param)
    {
        if (!request()->isPost()) {
            return $this->error(lang('param_err'));
        }
        // Permanent deletion always requires CSRF, including installations opting out
        // of protection for older recycle actions.
        $csrf = $this->validateVodCsrf($param, true);
        if ($csrf !== null) { return $csrf; }
        $scope = $param['scope'] ?? '';
        if (!is_string($scope) || !in_array($scope, ['active', 'recycle'], true)) {
            return $this->error(lang('param_err'));
        }
        $raw = $param['ids'] ?? null;
        if (is_string($raw)) {
            if (strlen($raw) > 21000) { return $this->error(lang('param_err')); }
            $raw = explode(',', $raw);
        } elseif (is_int($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw) || count($raw) < 1 || count($raw) > 1000) {
            return $this->error(lang('param_err'));
        }
        $ids = [];
        foreach ($raw as $value) {
            if ((!is_int($value) && !is_string($value)) ||
                !preg_match('/^[1-9][0-9]*$/D', (string)$value) ||
                filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) {
                return $this->error(lang('param_err'));
            }
            $ids[] = (int)$value;
        }
        $where = ['_recycle'=>$scope, 'vod_id'=>['in', array_values(array_unique($ids))]];
        $this->seoPingQueue(array_values(array_unique($ids)));
        $res = model('Vod')->delData($where);
        if ($res['code'] != 1) { return $this->error($res['msg']); }
        $this->seoPingFlush();
        return $this->success($res['msg']);
    }

    /** Full video catalog cleanup shares the existing delete permission and stable CSRF. */
    protected function cleanupVideos(array $param)
    {
        if (!request()->isPost()) { return $this->error(lang('param_err')); }
        $post = input('post.');
        $operation = $post['operation'] ?? '';
        $allowed = ['cleanup_preview','cleanup_start','cleanup_status','cleanup_step','cleanup_pause','cleanup_resume','cleanup_cancel'];
        if (!is_string($operation) || !in_array($operation, $allowed, true) || $operation !== $param['operation']) {
            return $this->error(lang('param_err'));
        }
        $csrf = $this->validateVodCsrf($post, true);
        if ($csrf !== null) { return $csrf; }
        $owner = (int)($this->_admin['admin_id'] ?? 0);
        if ($owner < 1) { return $this->error(lang('permission_denied')); }
        $job = $post['job_id'] ?? '';
        if (!in_array($operation, ['cleanup_preview','cleanup_start'], true) &&
            (!is_string($job) || !preg_match('/^[a-f0-9]{32}$/D', $job))) {
            return $this->error(lang('param_err'));
        }
        $version = null;
        if (array_key_exists('version', $post)) {
            $raw = $post['version'];
            if ((!is_int($raw) && !is_string($raw)) || !preg_match('/^(?:0|[1-9][0-9]*)$/D', (string)$raw) ||
                filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]) === false) {
                return $this->error(lang('param_err'));
            }
            $version = (int)$raw;
        }
        try {
            $cleaner = new \app\common\model\VodCleanup();
            switch ($operation) {
                case 'cleanup_preview': $data = $cleaner->preview($owner); break;
                case 'cleanup_start':
                    $ticket = $post['ticket'] ?? null;
                    $phrase = $post['phrase'] ?? null;
                    if (!is_string($ticket) || strlen($ticket) > 4096 || !is_string($phrase) || strlen($phrase) > 64) {
                        throw new \RuntimeException('vod/cleanup_ticket');
                    }
                    $data = $cleaner->start($owner, $ticket, $phrase); break;
                case 'cleanup_status': $data = $cleaner->status($owner, $job); break;
                case 'cleanup_step': $data = $cleaner->step($owner, $job, $version); break;
                case 'cleanup_pause': $data = $cleaner->pause($owner, $job); break;
                case 'cleanup_resume': $data = $cleaner->resume($owner, $job); break;
                case 'cleanup_cancel': $data = $cleaner->cancel($owner, $job); break;
            }
        } catch (\Throwable $error) {
            $key = $error->getMessage();
            $known = ['vod/cleanup_auth','vod/cleanup_ticket','vod/cleanup_expired','vod/cleanup_changed',
                'vod/cleanup_phrase','vod/cleanup_busy','vod/cleanup_import_busy','vod/cleanup_schema',
                'vod/cleanup_job_missing','vod/cleanup_finished','vod/cleanup_empty'];
            if (!in_array($key, $known, true)) { error_log('[VodCleanup] Request failed: '.get_class($error)); $key = 'vod/cleanup_failed'; }
            return $this->error(lang($key));
        }
        if (isset($data['error']) && $data['error'] !== '') {
            $data['error'] = preg_match('~^vod/cleanup_[a-z_]+$~D', $data['error']) ? lang($data['error']) : lang('vod/cleanup_failed');
        }
        return $this->success('ok', null, $data);
    }

    public function del()
    {
        $param = input();
        if (array_key_exists('operation', $param)) {
            if (is_string($param['operation']) && strpos($param['operation'], 'cleanup_') === 0) { return $this->cleanupVideos($param); }
            if ($param['operation'] !== 'permanent') { return $this->error(lang('param_err')); }
            return $this->permanentDeleteSelected($param);
        }
        // Set PHP resource limits for large recycle operations
        set_time_limit(0);
        ini_set('memory_limit', '4096M');
        
        try {
            Db::execute("SET SESSION wait_timeout = 28800");
            Db::execute("SET SESSION interactive_timeout = 28800");
            Db::execute("SET SESSION innodb_lock_wait_timeout = 300");
            Db::execute("SET SESSION net_read_timeout = 300");
            Db::execute("SET SESSION net_write_timeout = 300");
        } catch (\Exception $e) {
            // Continue if the database session timeouts cannot be set
        }

        $param = input();
        $ids = $param['ids'] ?? '';
        $csrf = $this->validateVodCsrf($param);
        if ($csrf !== null) { return $csrf; }

        if(!empty($ids)){
            $where=[];
            $where['vod_id'] = ['in',$ids];
            $this->seoPingQueue($ids); // IndexNow: recycled videos
            $res = model('Vod')->recycleData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            $this->seoPingFlush();
            return $this->success($res['msg']);
        }
        elseif(!empty($param['repeat'])){
            // D-L13: at T3 the GROUP BY over every name is the CLI job tools/vod-duplicates.php (--recycle --keep=min|max)
            if (\app\common\model\Vod::tierInfo()['tier'] >= 3) {
                return $this->error($this->dupesCliText());
            }
            // B6: keep ONE video per duplicate name (lowest or highest id) and recycle every other copy.
            // dry=1 only counts, so the confirm can name "{n} videos in {m} groups".
            $keepMax = isset($param['retain']) && $param['retain'] === 'max';
            $groups = Db::name('Vod')->field('vod_name, MIN(vod_id) AS mn, MAX(vod_id) AS mx, COUNT(*) AS c')
                ->where('vod_recycle_time', 0)->group('vod_name')->having('COUNT(*) > 1')->select();
            $n = 0;
            foreach ($groups as $g) { $n += (int)$g['c'] - 1; }
            $m = count($groups);
            if (!empty($param['dry'])) {
                return $this->success('ok', null, ['n'=>$n, 'm'=>$m]);
            }
            if ($m < 1) {
                return $this->error(lang('admin/ux/content/batch_none'));
            }
            foreach (array_chunk($groups, 500) as $chunk) {
                $names = array_column($chunk, 'vod_name');
                $keep = array_map('intval', array_column($chunk, $keepMax ? 'mx' : 'mn'));
                $res = model('Vod')->recycleData(['vod_name'=>['in', $names], 'vod_id'=>['not in', $keep]]);
                if($res['code']>1){
                    return $this->error($res['msg']);
                }
            }
            return $this->success(str_replace('{n}', (string)$n, lang('admin/ux/content/batch_recycled')), null, ['n'=>$n, 'm'=>$m]);
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
        $csrfPost = input('post.');
        $csrf = $this->validateVodCsrf($csrfPost, true);
        if ($csrf !== null) { return $csrf; }
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
            if (!isset($type_list[$val]) || intval($type_list[$val]['type_mid']) != 1) {
                return $this->error(lang('param_err'));
            }
            $type_pid = intval($type_list[$val]['type_pid']);
        }



        if(!empty($ids) && in_array($col,['vod_status','vod_lock','vod_level','vod_hits','type_id','vod_copyright'])){
            $where=[];
            $where['vod_id'] = ['in',$ids];
            $update = [];
            if(empty($start)) {
                $update[$col] = $val;
                if($col == 'type_id'){
                    $update['type_id_1'] = $type_pid;
                }
                $res = model('Vod')->fieldData($where, $update);
            }
            else{
                if(empty($end)){$end = 9999;}
                $ids = explode(',',$ids);
                foreach($ids as $k=>$v){
                    $val = rand($start,$end);
                    $where['vod_id'] = ['eq',$v];
                    $update[$col] = $val;
                    $res = model('Vod')->fieldData($where, $update);
                }
            }
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            $this->seoPingQueue($ids); $this->seoPingFlush();
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function updateToday()
    {
        $param = input();
        $flag = $param['flag'];
        $res = model('Vod')->updateToday($flag);
        return json($res);
    }

    private function assignBaseListByConfig() {
        $player_list = config('vodplayer');
        $downer_list = config('voddowner');
        $server_list = config('vodserver');
        $player_list = mac_multisort($player_list,'sort',SORT_DESC,'status','1');
        $downer_list = mac_multisort($downer_list,'sort',SORT_DESC,'status','1');
        $server_list = mac_multisort($server_list,'sort',SORT_DESC,'status','1');
        $this->assign('player_list',$player_list);
        $this->assign('downer_list',$downer_list);
        $this->assign('server_list',$server_list);
    }

    // "Generate pages" is offered only when a static page mode (2) is on; otherwise the generator only says it is off.
    private function axStaticOn($keys)
    {
        $view = isset($GLOBALS['config']['view']) && is_array($GLOBALS['config']['view']) ? $GLOBALS['config']['view'] : [];
        foreach ($keys as $k) { if (isset($view[$k]) && (int)$view[$k] === 2) { return true; } }
        return false;
    }
}


