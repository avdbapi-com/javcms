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
namespace app\index\controller;


class Ajax extends Base
{
    var $_param;
    
    public function __construct()
    {
        // round-2 verify fix R2-5 (N13): a video list page past its last page answers before the controller chain boots
        $this->data_past_last();
        parent::__construct();

        $this->_param = mac_param_url();
    }

    /**
     * ajax/data?mid=1 (the open video list API) above its last page: the empty list that listDataScaled() returns there
     * (no rows, the same total / pagecount), sent before the controller chain boots (user, site settings, gate): the last
     * page is ceil(T / limit), T = the precomputed total (ScaleCounts snapshot: every video, or the category / parent
     * family of tid), capped like the list (Vod::pageCap). 0 video statements. Anything unknown (no snapshot, unknown tid,
     * a hidden-category visitor setting, not GET) -> the normal chain answers.
     */
    private function data_past_last()
    {
        try {
            if (strtolower((string)request()->action()) !== 'data' || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET'
                || !class_exists('\app\common\util\ScaleCounts') || (int)($GLOBALS['config']['app']['popedom_filter'] ?? 0) === 1) {
                return;
            }
            $param = mac_param_url();
            $page = $param['page'] ?? null;
            if ((string)($param['mid'] ?? '') !== '1' || !is_scalar($page) || !ctype_digit((string)$page) || strlen((string)$page) > 9 || (int)$page < 2) {
                return;
            }
            $page = (int)$page;
            $limit = in_array((string)($param['limit'] ?? ''), ['10', '20', '30'], true) ? (int)$param['limit'] : 10;
            $tid = $param['tid'] ?? '';
            if (!empty($tid)) {
                $types = model('Type')->getCache('type_list');
                if (!is_scalar($tid) || !ctype_digit((string)$tid) || !is_array($types) || !isset($types[(int)$tid])) {
                    return;
                }
                $total = \app\common\util\ScaleCounts::type((int)$tid);
            } else {
                $total = \app\common\util\ScaleCounts::all();
            }
            if ($total === null) {
                return;
            }
            $cap = \app\common\model\Vod::pageCap('list');
            $shown = $cap > 0 ? min((int)$total, $cap * $limit) : (int)$total;
            if (($page - 1) * $limit < $shown && !($cap > 0 && $page > $cap)) {
                return; // a real page: the list code answers
            }
            json(['code' => 1, 'msg' => lang('data_list'), 'page' => $page, 'pagecount' => (int)ceil($shown / $limit), 'limit' => $limit,
                'total' => $shown, 'list' => [], 'total_all' => (int)$total, 'total_more' => false])->send();
            exit;
        } catch (\Throwable $e) {
            // the normal chain answers
        }
    }

    public function index()
    {

    }

    // Load up to 20 pages of data to prevent illegal collection. The number of entries per page can be 10,20,30
    public function data()
    {
        $mid = $this->_param['mid'];
        $limit = $this->_param['limit'];
        $page = $this->_param['page'];
        $type_id = $this->_param['tid'];
        if( !in_array($mid,['1','2','3','8','9','11']) ) {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }
        if( !in_array($limit,['10','20','30']) ) {
            $limit =10;
        }
        // XVID-NOLIMIT-BRIEF L27: video pages have no page cap any more (deep pages come from the page map, past the last
        // page the list is empty); the other modules keep their 20 pages
        $page = is_scalar($page) && ctype_digit((string)$page) && strlen((string)$page) <= 9 ? (int)$page : 1;
        if($page < 1 || ($mid != '1' && $page > 20)){
            $page =1;
        }

        // 1.2.0 (L6, D-L14): a light gate slot; shed -> the busy answer.
        if (!$this->gate('light')) {
            return $this->busy_json();
        }
        $pre = mac_get_mid_code($mid);
        $order= $pre.'_time desc';
        $where=[];
        $where[$pre.'_status'] = [ 'eq',1];
        $scaleType = 0;
        $scaleParent = 0;
        if(!empty($type_id)) {
            if(in_array($mid, ['1', '2'])){
                $type_list = model('Type')->getCache('type_list');
                $type_info = is_scalar($type_id) && isset($type_list[(int)$type_id]) ? $type_list[(int)$type_id] : null;
                if(!empty($type_info)) {
                    // D-L14: "type_id IN (children)" for a parent, "type_id = X" for a category; no OR across two columns
                    if ((int)$type_info['type_pid'] == 0) {
                        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $type_info['type_id'] . ',' . $type_info['childids'])))));
                        if ($mid == '1') {
                            // the parent shape of the visitor lists (type_id_1 = P + type_id = P, D-L1b) and its page-map key
                            $scaleParent = (int)$type_info['type_id'];
                        } else {
                            $where['type_id'] = ['in', $ids];
                        }
                    } else {
                        $where['type_id'] = ['eq', (int)$type_info['type_id']];
                    }
                    $scaleType = (int)$type_info['type_id'];
                } else {
                    $where['type_id'] = ['eq', 0];
                }
            }
        }
        $field='*';
        if ($mid == '1') {
            // D-L4 / D-L5: precomputed total when known, else a bounded count; at most 20 pages (the existing cap)
            $scaleTotal = null;
            if (class_exists('\app\common\util\ScaleCounts')) {
                try {
                    $scaleTotal = $scaleType > 0 ? \app\common\util\ScaleCounts::type($scaleType) : \app\common\util\ScaleCounts::all();
                } catch (\Throwable $e) {
                    $scaleTotal = null;
                }
            }
            $mapOpt = [];
            if (class_exists('\app\common\util\ListPageMap') && (empty($type_id) || $scaleType > 0)) {
                // a top-level category without children is one category range ('t'), a real parent its family ('p')
                $childless = $scaleParent > 0 && empty(array_filter(explode(',', (string)($type_info['childids'] ?? ''))));
                $mapOpt['pagemap'] = ['lk' => $childless ? \app\common\util\ListPageMap::baseKey($scaleParent, 0)
                    : \app\common\util\ListPageMap::baseKey($scaleParent > 0 ? 0 : $scaleType, $scaleParent), 'hidden' => [], 'asc' => false];
            }
            try {
                $res = model('Vod')->listDataScaled($where, $order, $page, $limit, 0, $field, 1, ['totalshow' => true, 'cap' => \app\common\model\Vod::pageCap('list'),
                    'scale_total' => $scaleTotal, 'parent' => $scaleParent] + $mapOpt);
            } catch (\Throwable $e) {
                \app\common\model\Vod::visitorQueryFailed($e);
            }
        } else {
            $res = model($pre)->listData($where,$order,$page,$limit,0,$field);
        }
        if($res['code']==1) {
            foreach ($res['list'] as $k => &$v) {
                unset($v[$pre.'_time_hits'],$v[$pre.'_time_make']);
                // Access passwords (vod_pwd, vod_pwd_play, vod_pwd_down, art_pwd, ...) and their URLs are never public data.
                foreach (array_keys($v) as $column) {
                    if (preg_match('/_pwd(?:_|$)/', (string)$column)) {
                        unset($v[$column]);
                    }
                }
                // Detail links are built from the numeric timestamps (URL rules may use the date), then the dates are formatted.
                $rawTime = $v[$pre.'_time'];
                $rawTimeAdd = $v[$pre.'_time_add'];
                if($mid=='1'){
                    unset($v['vod_play_from'],$v['vod_play_server'],$v['vod_play_note'],$v['vod_play_url']);
                    unset($v['vod_down_from'],$v['vod_down_server'],$v['vod_down_note'],$v['vod_down_url']);

                    $v['detail_link'] = mac_url_vod_detail($v);
                }
                elseif($mid=='2'){
                    $v['detail_link'] = mac_url_art_detail($v);
                }
                elseif($mid=='3'){
                    $v['detail_link'] = mac_url_topic_detail($v);
                }
                elseif($mid=='8'){
                    $v['detail_link'] = mac_url_actor_detail($v);
                }
                elseif($mid=='9'){
                    $v['detail_link'] = mac_url_role_detail($v);
                }
                elseif($mid=='11'){
                    $v['detail_link'] = mac_url_website_detail($v);
                }
                $v[$pre.'_time'] = date('Y-m-d H:i:s', (int)$rawTime);
                $v[$pre.'_time_add'] = date('Y-m-d H:i:s', (int)$rawTimeAdd);
                $v[$pre.'_pic'] = mac_url_img($v[$pre.'_pic']);
                $v[$pre.'_pic_thumb'] = mac_url_img($v[$pre.'_pic_thumb']);
                $v[$pre.'_pic_slide'] = mac_url_img($v[$pre.'_pic_slide']);
            }
        }
        return json($res);
    }

    public function suggest()
    {
        if($GLOBALS['config']['app']['search'] !='1'){
            return json(['code'=>999,'msg'=>lang('suggest_close')]);
        }

        $mid = $this->_param['mid'] ?? '';
        $wd = $this->_param['wd'] ?? '';
        $requestedLimit = $this->_param['limit'] ?? 0;
        if ((!is_string($wd) && !is_int($wd)) || (!is_string($mid) && !is_int($mid))
            || (!is_string($requestedLimit) && !is_int($requestedLimit))) {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }
        $wd = (string)$wd;
        if (strlen($wd) > 400 || !preg_match('//u', $wd)
            || (function_exists('mb_strlen') ? mb_strlen($wd, 'UTF-8') : preg_match_all('/./us', $wd)) > 100
            || trim(str_replace(['%', '_'], '', $wd)) === '') {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }
        $limit = max(0, min(30, (int)$requestedLimit));

        if( $wd=='' || !in_array($mid,['1','2','3','8','9','11']) ) {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }
        $mids = [1=>'vod',2=>'art',3=>'topic',8=>'actor',9=>'role',11=>'website'];
        $pre = $mids[$mid];
        if($limit<1){
            $limit = 20;
        }
        $url = mac_url_search(['wd'=>'mac_wd'],$pre);
        // 1.2.0 (D-S8, L5, se-R16): suggest takes a search slot; when shed it answers with an empty list, never 429.
        if (!$this->gate('search')) {
            return json(['code'=>1,'msg'=>lang('data_list'),'page'=>1,'pagecount'=>0,'limit'=>$limit,'total'=>0,'list'=>[],'url'=>$url]);
        }
        $where = [];
        $order = $pre.'_id desc';
        $field = $pre.'_id as id,'.$pre.'_name as name,'.$pre.'_en as en,'.$pre.'_pic as pic';

        if ($pre === 'vod' && \app\common\model\Vod::ftSearchMode() !== 'old') {
            // D-S8: the planner, 10 rows, no COUNT (today: a 25 s COUNT per keystroke at 7 M rows)
            try {
                $plan = \app\common\util\FtPlan::search($wd, ['page' => 1, 'per' => 10, 'hidden' => \app\common\model\Vod::hiddenTypeIds()]);
                $rows = [];
                if ($plan['ids']) {
                    $byId = [];
                    foreach (\think\Db::name('vod')->field('vod_id,vod_name,vod_en,vod_pic')->where('vod_status', 1)->where('vod_recycle_time', 0)
                        ->where('vod_id', 'in', $plan['ids'])->select() as $r) {
                        $byId[(int)$r['vod_id']] = ['id' => (int)$r['vod_id'], 'name' => $r['vod_name'], 'en' => $r['vod_en'], 'pic' => $r['vod_pic']];
                    }
                    foreach ($plan['ids'] as $id) {
                        if (isset($byId[(int)$id])) { $rows[] = $byId[(int)$id]; }
                    }
                }
            } catch (\Throwable $e) {
                \app\common\model\Vod::visitorQueryFailed($e);
            }
            $res = ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'pagecount'=>$rows ? 1 : 0,'limit'=>10,'total'=>count($rows),'list'=>$rows];
        } else {
            $where[$pre.'_name|'.$pre.'_en'] = ['like','%'.$wd.'%'];
            // no total: the COUNT of a LIKE over every row was the expensive half of a keystroke (HARD F12)
            try {
                $res = model($pre)->listData($where,$order,1,$limit,0,$field,1,0);
            } catch (\Throwable $e) {
                if ($pre !== 'vod') { throw $e; }
                \app\common\model\Vod::visitorQueryFailed($e);
            }
        }
        if($res['code']==1) {
            foreach ($res['list'] as $k => $v) {
                $res['list'][$k]['pic'] = mac_url_img($v['pic']);
            }
        }
        $res['url'] = $url;
        return json($res);
    }

    public function desktop()
    {
        // A desktop shortcut (.url file) to a page of THIS site only. The old code wrote any url/name into the file and
        // the header: a crafted link served a shortcut from this domain to another site, and a line break in url added
        // INI lines (IconFile=\\host\share\... makes Windows Explorer contact that host when it shows the file).
        $config = config('maccms.site');
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https://' : 'http://';
        $home = $scheme . ((string)($config['site_url'] ?? '') !== '' ? $config['site_url'] : (string)($_SERVER['HTTP_HOST'] ?? '')) . '/';
        $name = html_entity_decode((string)$this->_param['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = html_entity_decode((string)$this->_param['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = $url === '' ? false : @parse_url($url);
        $own = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (!is_array($parts) || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || $own === '' || strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')) !== $own) {
            $url = $home;
        }
        $name = trim(preg_replace('/[^\p{L}\p{N} ._()-]+/u', ' ', $name) ?? '');
        if ($name === '') {
            $name = trim(preg_replace('/[^\p{L}\p{N} ._()-]+/u', ' ', (string)($config['site_name'] ?? '')) ?? '') ?: 'shortcut';
        }
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 80, 'UTF-8') : substr($name, 0, 80);
        $shortcut = "[InternetShortcut]\r\nURL=" . $url . "\r\nIDList=\r\nIconIndex=1\r\n[{000214A0-0000-0000-C000-000000000046}]\r\nProp3=19,2\r\n";
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        $ascii = preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', $name);
        header('Content-Disposition: attachment; filename="' . $ascii . '.url"; filename*=UTF-8\'\'' . rawurlencode($name . '.url'));
        echo $shortcut;
    }

    public function hits()
    {
        $id = $this->_param['id'];
        $mid = $this->_param['mid'];
        $type = $this->_param['type'];
        if(empty($id) ||  !in_array($mid,['1','2','3','8','9','11']) ) {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }
        if (!$this->gate('light')) {
            return $this->busy_json();
        }
        $pre = mac_get_mid_code($mid);
        $where = [];
        $where[$pre.'_id'] = $id;
        $field = $pre.'_hits,'.$pre.'_hits_day,'.$pre.'_hits_week,'.$pre.'_hits_month,'.$pre.'_time_hits';
        if ($pre === 'vod') {
            $field .= ',vod_en,vod_sub';
        }
        $model = model($pre);

        $res = $model->infoData($where,$field);
        if($res['code']>1) {
            return json($res);
        }
        $info = $res['info'];

        if ($type == 'update' && $pre === 'vod') {
            // 1.2.0 (HO-H-2, D-L11, C8): one buffered increment; housekeeping folds it with today's day/week/month rules.
            // No UPDATE of four indexed counters per view and no list-cache wipe any more.
            \app\common\util\HitBuffer::add((int)$id, 'hits');
            $new = getdate();
            $old = getdate((int)$info['vod_time_hits']);
            $sameMonth = $new['year'] == $old['year'] && $new['mon'] == $old['mon'];
            $weekStart = mktime(0,0,0,$new["mon"],$new["mday"],$new["year"]) - ($new["wday"] * 86400);
            $data['hits'] = (int)$info['vod_hits'] + 1;
            $data['hits_day'] = ($sameMonth && $new['mday'] == $old['mday'] ? (int)$info['vod_hits_day'] : 0) + 1;
            $data['hits_week'] = ((int)$info['vod_time_hits'] >= $weekStart ? (int)$info['vod_hits_week'] : 0) + 1;
            $data['hits_month'] = ($sameMonth ? (int)$info['vod_hits_month'] : 0) + 1;
            return json(['code'=>1,'msg'=>'ok','data'=>$data]);
        }

        if($type == 'update'){
            // Initialize view counters from the stored values.
            $update[$pre.'_hits'] = $info[$pre.'_hits'];
            $update[$pre.'_hits_day'] = $info[$pre.'_hits_day'];
            $update[$pre.'_hits_week'] = $info[$pre.'_hits_week'];
            $update[$pre.'_hits_month'] = $info[$pre.'_hits_month'];
            $new = getdate();
            $old = getdate($info[$pre.'_time_hits']);
            // month
            if($new['year'] == $old['year'] && $new['mon'] == $old['mon']){
                $update[$pre.'_hits_month'] ++;
            }else{
                $update[$pre.'_hits_month'] = 1;
            }
            // weeks
            $weekStart = mktime(0,0,0,$new["mon"],$new["mday"],$new["year"]) - ($new["wday"] * 86400);
            $weekEnd = mktime(23,59,59,$new["mon"],$new["mday"],$new["year"]) + ((6 - $new["wday"]) * 86400);
            if($info[$pre.'_time_hits'] >= $weekStart && $info[$pre.'_time_hits'] <= $weekEnd){
                $update[$pre.'_hits_week'] ++;
            }else{
                $update[$pre.'_hits_week'] = 1;
            }
            // day
            if($new['year'] == $old['year'] && $new['mon'] == $old['mon'] && $new['mday'] == $old['mday']){
                $update[$pre.'_hits_day'] ++;
            }else{
                $update[$pre.'_hits_day'] = 1;
            }
            // Updating the database
            $update[$pre.'_hits'] = $update[$pre.'_hits']+1;
            $update[$pre.'_time_hits'] = time();
            $model->where($where)->update($update);

            $data['hits'] = $update[$pre.'_hits'];
            $data['hits_day'] = $update[$pre.'_hits_day'];
            $data['hits_week'] = $update[$pre.'_hits_week'];
            $data['hits_month'] = $update[$pre.'_hits_month'];
        }
        else{
            $data['hits'] = $info[$pre.'_hits'];
            $data['hits_day'] = $info[$pre.'_hits_day'];
            $data['hits_week'] = $info[$pre.'_hits_week'];
            $data['hits_month'] = $info[$pre.'_hits_month'];
        }
        return json(['code'=>1,'msg'=>'ok','data'=>$data]);
    }

    /**
     * 1.2.0 (L6, HO-H-2): every Ajax endpoint that reads the database takes a gate slot of its class (C10): 'search' for
     * suggest, 'light' for data / hits / digg / score. Released at the end of the request (or by leave()).
     */
    private function gate(string $class): bool
    {
        if (!class_exists('\app\common\util\RequestGate')) {
            return true;
        }
        return \app\common\util\RequestGate::enter($class);
    }

    /** Shed JSON request: 503 + Retry-After, a tiny JSON body (busy page semantics without HTML). */
    private function busy_json()
    {
        $retry = random_int(10, 30);
        return json(['code' => 1003, 'msg' => 'busy', 'retry' => $retry], 503)->header(['Retry-After' => (string)$retry, 'Cache-Control' => 'no-store']);
    }

    public function referer()
    {
        $url = $this->_param['url'];
        $type = $this->_param['type'];
        $domain = $this->_param['domain'];

        if(empty($url)) {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }

        if(strpos($_SERVER["HTTP_REFERER"],$_SERVER['HTTP_HOST'])===false){
            return json(['code'=>1002,'msg'=>lang('param_err')]);
        }

        if(strpos($url,$domain)===false){
            return json(['code'=>1003,'msg'=>lang('param_err')]);
        }

        $pre = 'website';
        $where=[];
        $where[$pre.'_jumpurl'] =  ['like', ['http://'.$domain.'%','https://'.$domain.'%'],'OR'];
        $model = model($pre);
        $field = $pre.'_referer,'.$pre.'_referer_day,'.$pre.'_referer_week,'.$pre.'_referer_month,'.$pre.'_time_referer';
        $res = $model->infoData($where,$field);
        if($res['code']>1){
            return json($res);
        }
        $info = $res['info'];
        $id = $info[$pre.'_id'];

        // Check the referral limit and record the visit.
        $res = model('Website')->visit($this->_param);
        if($res['code']>1){
            return json($res);
        }

        if($type == 'update'){
            // Initialize referral counters from the stored values.
            $update[$pre.'_referer'] = $info[$pre.'_referer'];
            $update[$pre.'_referer_day'] = $info[$pre.'_referer_day'];
            $update[$pre.'_referer_week'] = $info[$pre.'_referer_week'];
            $update[$pre.'_referer_month'] = $info[$pre.'_referer_month'];
            $new = getdate();
            $old = getdate($info[$pre.'_time_referer']);
            // month
            if($new['year'] == $old['year'] && $new['mon'] == $old['mon']){
                $update[$pre.'_referer_month'] ++;
            }else{
                $update[$pre.'_referer_month'] = 1;
            }
            // weeks
            $weekStart = mktime(0,0,0,$new["mon"],$new["mday"],$new["year"]) - ($new["wday"] * 86400);
            $weekEnd = mktime(23,59,59,$new["mon"],$new["mday"],$new["year"]) + ((6 - $new["wday"]) * 86400);
            if($info[$pre.'_time_referer'] >= $weekStart && $info[$pre.'_time_referer'] <= $weekEnd){
                $update[$pre.'_referer_week'] ++;
            }else{
                $update[$pre.'_referer_week'] = 1;
            }
            // day
            if($new['year'] == $old['year'] && $new['mon'] == $old['mon'] && $new['mday'] == $old['mday']){
                $update[$pre.'_referer_day'] ++;
            }else{
                $update[$pre.'_referer_day'] = 1;
            }
            // Updating the database
            $update[$pre.'_referer'] = $update[$pre.'_referer']+1;
            $update[$pre.'_time_referer'] = time();
            $model->where($where)->update($update);

            $data['referer'] = $update[$pre.'_referer'];
            $data['referer_day'] = $update[$pre.'_referer_day'];
            $data['referer_week'] = $update[$pre.'_referer_week'];
            $data['referer_month'] = $update[$pre.'_referer_month'];
        }
        else{
            $data['referer'] = $info[$pre.'_referer'];
            $data['referer_day'] = $info[$pre.'_referer_day'];
            $data['referer_week'] = $info[$pre.'_referer_week'];
            $data['referer_month'] = $info[$pre.'_referer_month'];
        }
        return json(['code'=>1,'msg'=>'ok','data'=>$data]);
    }

    public function digg()
    {
        $id = $this->_param['id'];
        $mid = $this->_param['mid'];
        $type = $this->_param['type'];

        if(empty($id) ||  !in_array($mid,['1','2','3','4','8','9','11']) ) {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }
        if (!$this->gate('light')) {
            return $this->busy_json();
        }
        $pre = mac_get_mid_code($mid);
        $where = [];
        $where[$pre.'_id'] = $id;
        if ($mid == '1') { $where['vod_recycle_time'] = 0; }
        $field = $pre.'_up,'.$pre.'_down';
        $model = model($pre);

        $buffered = '';
        if($type) {
            $cookie = $pre . '-digg-' . $id;
            if(!empty(cookie($cookie))){
                return json(['code'=>1002,'msg'=>lang('index/haved')]);
            }
            if ($mid == '1' && ($type == 'up' || $type == 'down')) {
                // 1.2.0 (HO-H-2, C8): buffered, folded by housekeeping
                \app\common\util\HitBuffer::add((int)$id, $type);
                cookie($cookie, 't', 30);
                $buffered = $type;
            } elseif ($type == 'up') {
                $model->where($where)->setInc($pre.'_up');
                cookie($cookie, 't', 30);
            } elseif ($type == 'down') {
                $model->where($where)->setInc($pre.'_down');
                cookie($cookie, 't', 30);
            }
        }

        $res = $model->infoData($where,$field);
        if($res['code']>1) {
            return json($res);
        }
        $info = $res['info'];
        if ($info) {
            $data['up'] = (int)$info[$pre.'_up'] + ($buffered === 'up' ? 1 : 0);
            $data['down'] = (int)$info[$pre.'_down'] + ($buffered === 'down' ? 1 : 0);
        }
        else{
            $data['up'] = 0;
            $data['down'] = 0;
        }
        return json(['code'=>1,'msg'=>'ok','data'=>$data]);
    }

    public function score()
    {
        $id = $this->_param['id'];
        $mid = $this->_param['mid'];
        $score = $this->_param['score'];

        if(empty($id) ||  !in_array($mid,['1','2','3','8','9','11']) ) {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }
        // One vote is a score from 1 to 10 (score=1000000 or score=-50 used to move the average at will).
        if (!empty($score) && ((int)$score < 1 || (int)$score > 10)) {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }

        if (!$this->gate('light')) {
            return $this->busy_json();
        }
        $pre = mac_get_mid_code($mid);
        $where = [];
        $where[$pre.'_id'] = $id;
        $field = $pre.'_score,'.$pre.'_score_num,'.$pre.'_score_all';
        $model = model($pre);

        $res = $model->infoData($where,$field);
        if($res['code']>1) {
            return json($res);
        }
        $info = $res['info'];

        if ($info) {
            if($score){
                $cookie = $pre.'-score-'.$id;
                if(!empty(cookie($cookie))){
                    return json(['code'=>1002,'msg'=>lang('index/haved')]);
                }
                $update=[];
                $update[$pre.'_score_num'] = $info[$pre.'_score_num']+1;
                $update[$pre.'_score_all'] = $info[$pre.'_score_all']+$score;
                $update[$pre.'_score'] = number_format( $update[$pre.'_score_all'] / $update[$pre.'_score_num'] ,1,'.','');
                if ($pre === 'vod') {
                    \app\common\util\HitBuffer::add((int)$id, 'score:' . (int)$score); // 1.2.0 (HO-H-2, C8)
                } else {
                    $model->where($where)->update($update);
                }

                $data['score'] = $update[$pre.'_score'];
                $data['score_num'] = $update[$pre.'_score_num'];
                $data['score_all'] = $update[$pre.'_score_all'];

                cookie($cookie,'t',30);
            }
            else{
                $data['score'] = $info[$pre.'_score'];
                $data['score_num'] = $info[$pre.'_score_num'];
                $data['score_all'] = $info[$pre.'_score_all'];
            }
        }else{
            $data['score'] = 0.0;
            $data['score_num'] = 0;
            $data['score_all'] = 0;
        }
        return json(['code'=>1,'msg'=>lang('score_ok'),'data'=>$data]);
    }

    public function pwd()
    {
        $mid = $this->_param['mid'];
        $id = $this->_param['id'];
        $type = $this->_param['type'];
        $pwd = input('param.pwd');

        if( empty($id) || empty($pwd) || !in_array($mid,['1','2']) || !in_array($type,['1','4','5'])){
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }

        $key = $mid.'-'.$type.'-'.$id;
        if(session($key)=='1'){
            return json(['code'=>1002,'msg'=>lang('index/pwd_repeat')]);
        }

        if ( mac_get_time_span("last_pwd") < 5){
            return json(['code'=>1003,'msg'=>lang('index/pwd_frequently')]);
        }


        if($mid=='1'){
            $where=[];
            $where['vod_id'] = ['eq',$id];
            $info = model('Vod')->infoData($where);
            if($info['code'] >1){
                return json(['code'=>1011,'msg'=>$info['msg']]);
            }
            if($type=='1'){
                if(!hash_equals((string)$info['info']['vod_pwd'], (string)$pwd)){
                    return json(['code'=>1012,'msg'=>lang('pass_err')]);
                }
            }
            elseif($type=='4'){
                if(!hash_equals((string)$info['info']['vod_pwd_play'], (string)$pwd)){
                    return json(['code'=>1013,'msg'=>lang('pass_err')]);
                }
            }
            elseif($type=='5'){
                if(!hash_equals((string)$info['info']['vod_pwd_down'], (string)$pwd)){
                    return json(['code'=>1014,'msg'=>lang('pass_err')]);
                }
            }
        }
        else{
            $where=[];
            $where['art_id'] = ['eq',$id];
            $info = model('Art')->infoData($where);
            if($info['code'] >1){
                return json(['code'=>1021,'msg'=>$info['msg']]);
            }
            if(!hash_equals((string)$info['info']['art_pwd'], (string)$pwd)){
                return json(['code'=>1022,'msg'=>lang('pass_err')]);
            }
        }

        session($key,'1');
        return json(['code'=>1,'msg'=>'ok']);
    }

    public function verify_check()
    {
        mac_no_cahche();
        $param = input();
        if(!in_array($param['type'],['search','show'])){
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        if (!captcha_check($param['verify'])){
            return json(['code' => 1002, 'msg' => lang('verify_err')]);
        }
        session($param['type'].'_verify','1');
        return json(['code'=>1,'msg'=>lang('index/ok')]);
    }
}