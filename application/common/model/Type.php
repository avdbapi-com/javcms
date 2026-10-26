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
namespace app\common\model;
use think\Db;
use think\Cache;
use app\common\util\Pinyin;

class Type extends Base {
    // Table name without the configured prefix
    protected $name = 'type';

    // Define timestamp field names
    protected $createTime = '';
    protected $updateTime = '';

    // Fields to populate automatically
    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];


    // Custom initialization
    protected function initialize()
    {
        // Call the parent model's `initialize` method.
        parent::initialize();
        // TODO: custom initialization
    }

    public function countData($where)
    {
        $total = $this->where($where)->count();
        return $total;
    }

    public function listData($where,$order,$format='def',$mid=0,$limit=999,$start=0,$totalshow=1)
    {
        if(!is_array($where)){
            $where = json_decode($where,true);
        }
        $limit_str = ($limit * (1-1) + $start) .",".$limit;
        if($totalshow==1) {
            $total = $this->where($where)->count();
        }
        else{

        }
        $tmp = Db::name('Type')->where($where)->order($order)->limit($limit_str)->select();

        $list = [];
        $childs=[];
        foreach($tmp as $k=>$v){
            $v['type_extend'] = json_decode($v['type_extend'],true);
            $list[$v['type_id']] = $v;
            $childs[$v['type_pid']][] = $v['type_id'];
        }

        $rc=false;
        foreach($list as $k=>$v){
            if($v['type_pid']==0){
                if(!empty($where)){
                    if(!$rc){
                        $type_list = model('Type')->getCache('type_list');
                        $rc=true;
                    }
                    $list[$k]['childids'] = $type_list[$v['type_id']]['childids'];
                }
                else {
                    $list[$k]['childids'] = join(',', (array)$childs[$v['type_id']]);
                }
            }
            else {
                $list[$k]['type_1'] = $list[$v['type_pid']];
            }
        }
        if($mid>0){
            foreach($list as $k=>$v){
                if($v['type_mid'] !=$mid) {
                    unset($list[$k]);
                }
            }
        }

        if($format=='tree'){
            $list = mac_list_to_tree($list,'type_id','type_pid');
        }

        return ['code'=>1,'msg'=>lang('data_list'),'total'=>$total,'list'=>$list];
    }

    public function listCacheData($lp)
    {
        if (!is_array($lp)) {
            $lp = json_decode($lp, true);
        }

        $order = $lp['order'];
        $by = $lp['by'];
        $mid = $lp['mid'];
        $ids = $lp['ids'];
        $parent = $lp['parent'];
        $format = $lp['format'];
        $flag = $lp['flag'];
        $start = mac_legacy_abs_int($lp['start']);
        $num = mac_legacy_abs_int($lp['num']);
        $cachetime = $lp['cachetime'];
        $not = $lp['not'];
        $page=1;
        $where = [];
        // xvid scale build (2.6, 2.11 rule 5/5b): hide_empty="1" drops categories without visible videos (ScaleCounts,
        // C4; unknown counts hide nothing) and applies the single-parent menu rule to ids="parent"
        $hideEmpty = !empty($lp['hide_empty']) && (string)$lp['hide_empty'] !== '0';


        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }
        if (!in_array($order, ['asc', 'desc'])) {
            $order = 'desc';
        }
        if (!in_array($by, ['id', 'sort'])) {
            $by = 'id';
        }
        if (!in_array($format, ['def', 'tree'])) {
            $format = 'def';
        }
        if (in_array($mid, ['1', '2','8','11'])) {
            $where['type_mid'] = ['eq',$mid];
        }
        if(!empty($flag)){
            if($flag=='vod'){
                $where['type_mid'] = ['eq',1];
            }
            elseif($flag=='art'){
                $where['type_mid'] = ['eq',2];
            }
        }

        $param = mac_param_url();

        if (!empty($ids)) {
            if($ids=='parent'){
                $where['type_pid'] = ['eq',0];
            }
            elseif($ids=='child'){
                $where['type_pid'] = ['gt',0];
            }
            elseif($ids=='current'){
                $type_info = $this->getCacheInfo($param['id']);
                $doid = $param['id'];
                $childs = $type_info['childids'];
                if($type_info['type_pid']>0){// Use the parent category for a child category.
                    $doid = $type_info['type_pid'];
                    $type_info1 = $this->getCacheInfo($doid);
                    $childs = $type_info1['childids'];
                }

                $where['type_id'] = ['in',$childs];
            }
            else{
                $where['type_id'] = ['in',$ids];
            }
        }
        if(!empty($parent)){
            if($parent=='current'){
                $type_info = $this->getCacheInfo($param['id']);
                $parent = intval($type_info['type_id']);
                if($type_info['type_pid'] !=0){
                    // $parent = $type_info[\'type_pid\'];
                }
            }
            $where['type_pid'] = ['in',$parent];
        }
        if(!empty($not)){
            $where['type_id'] = ['not in',$not];
        }

        if(defined('ENTRANCE') && ENTRANCE == 'index' && $GLOBALS['config']['app']['popedom_filter'] ==1){
            $type_ids = mac_get_popedom_filter($GLOBALS['user']['group']['group_type']);
            if(!empty($type_ids)){
                if(!empty($where['type_id'])){
                    $where['type_id'] = [ $where['type_id'],['not in', explode(',',$type_ids)] ];
                }
                else{
                    $where['type_id'] = ['not in', explode(',',$type_ids)];
                }
            }
        }

        $where['type_status'] = ['eq',1];

        $by = 'type_'.$by;
        $order = 'type_pid asc,'. $by . ' ' . $order;

        // r5 repair (beginner r5 P2-1): the category revision is part of the name, so a category change or Clear cache
        // shows at once (the md5 name cannot be removed one by one).
        // hide_empty: the cached list is the whole unfiltered list (counts change without a category revision); the
        // filter and then start/num run on every call, so a hidden category never takes a menu slot
        $qNum = $hideEmpty ? 999 : $num;
        $qStart = $hideEmpty ? 0 : $start;
        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' .md5('type_listcache_'.self::listRevision().'_'.http_build_query($where).'_'.$order.'_'.$qNum.'_'.$qStart);
        $res = Cache::get($cach_name);
        if(empty($cachetime)){
            $cachetime = $GLOBALS['config']['app']['cache_time'];
        }
        if($GLOBALS['config']['app']['cache_core']==0 || empty($res)) {
            $res = $this->listData($where,$order,$format,$mid,$qNum,$qStart,0);
            $res['list'] = array_values($res['list']);
            if($GLOBALS['config']['app']['cache_core']==1) {
                Cache::set($cach_name, $res, $cachetime);
            }
        }

        if ($hideEmpty) {
            $res['list'] = self::visibleOnly($res['list']);
            if ($ids === 'parent' && empty($lp['single_parent_off'])) {
                $only = self::singleParent($res['list']);
                if ($only !== null) {
                    // single-parent rule (2.6) [pr-R1]: the one visible parent's visible children take its place
                    $child = $lp;
                    $child['ids'] = '';
                    $child['parent'] = (string)$only;
                    $child['by'] = 'sort';
                    $child['order'] = 'asc';
                    $child['hide_empty'] = '1';
                    $kids = $this->listCacheData($child);
                    if (!empty($kids['list'])) {
                        $kids['single_parent'] = $only;
                        return $kids;
                    }
                }
            }
            $res['list'] = array_slice($res['list'], $start, $num);
        }

        return $res;
    }

    /**
     * Published-video count of a category for hide_empty: ScaleCounts::type() (C4, lane H; a parent counts its children),
     * null = unknown. Without the class (merge not done) everything is unknown.
     */
    public static function visibleCount($typeId)
    {
        $typeId = (int)$typeId;
        if ($typeId < 1 || !class_exists('app\\common\\util\\ScaleCounts')) {
            return null;
        }
        $n = \app\common\util\ScaleCounts::type($typeId);
        return $n === null ? null : (int)$n;
    }

    /**
     * true = the category shows: it has videos, or one of its children has (a parent counts its children), or its count
     * is unknown (C4: unknown never reads as 0, so a site without counts keeps today's menus).
     */
    public static function isVisible(array $type)
    {
        // video counts only: article (and other module) categories always show
        if (isset($type['type_mid']) && (int)$type['type_mid'] !== 1) {
            return true;
        }
        $n = self::visibleCount($type['type_id'] ?? 0);
        if ($n !== null && $n > 0) {
            return true;
        }
        $unknown = $n === null;
        if ((int)($type['type_pid'] ?? 0) === 0) {
            foreach (self::childIds($type) as $kid) {
                $k = self::visibleCount($kid);
                if ($k === null) {
                    $unknown = true;
                } elseif ($k > 0) {
                    return true;
                }
            }
        }
        return $unknown;
    }

    /**
     * true when the single-parent rule applies right now (exactly one visible video parent with filled children, 2.6):
     * themes that cap their per-category home sections (crimson-modern, ember-modern: 2) show more genre sections then.
     * One cached list read per request.
     */
    public static function singleParentActive()
    {
        static $memo = null;
        if ($memo === null) {
            $lp = ['order' => 'asc', 'by' => 'sort', 'mid' => '', 'ids' => 'parent', 'parent' => '', 'format' => 'def', 'flag' => 'vod',
                'start' => 0, 'num' => 1, 'cachetime' => '', 'not' => '', 'hide_empty' => '1'];
            try {
                $res = model('Type')->listCacheData($lp);
                $memo = is_array($res) && !empty($res['single_parent']);
            } catch (\Throwable $e) {
                $memo = false;
            }
        }
        return $memo;
    }

    /** true only when a count is known and above 0 for the category or (parent) one of its children. */
    public static function hasVideos(array $type)
    {
        $n = self::visibleCount($type['type_id'] ?? 0);
        if ($n !== null && $n > 0) {
            return true;
        }
        if ((int)($type['type_pid'] ?? 0) === 0) {
            foreach (self::childIds($type) as $kid) {
                $k = self::visibleCount($kid);
                if ($k !== null && $k > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Child ids of a list row ('childids' of the category cache), as ints. */
    private static function childIds(array $type)
    {
        $list = [];
        foreach (explode(',', (string)($type['childids'] ?? '')) as $id) {
            $id = (int)trim($id);
            if ($id > 0 && $id !== (int)($type['type_id'] ?? 0)) {
                $list[] = $id;
            }
        }
        return $list;
    }

    /** hide_empty filter, order kept; format="tree" rows are filtered in their 'child' lists too. */
    public static function visibleOnly(array $list)
    {
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row) || !self::isVisible($row)) {
                continue;
            }
            if (!empty($row['child']) && is_array($row['child'])) {
                $row['child'] = self::visibleOnly($row['child']);
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Single-parent rule: the id of the only visible parent when exactly one is left and at least one of its children
     * has a known count above 0; else null (two or more parents with videos -> menus show parents again).
     */
    public static function singleParent(array $visibleParents)
    {
        if (count($visibleParents) !== 1) {
            return null;
        }
        $row = reset($visibleParents);
        if (!is_array($row) || (int)($row['type_pid'] ?? 0) !== 0) {
            return null;
        }
        foreach (self::childIds($row) as $kid) {
            $k = self::visibleCount($kid);
            if ($k !== null && $k > 0) {
                return (int)$row['type_id'];
            }
        }
        return null;
    }

    public function infoData($where,$field='*')
    {
        if(empty($where) || !is_array($where)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $info = $this->field($field)->where($where)->find();

        if(empty($info)){
            return ['code'=>1002,'msg'=>lang('obtain_err')];
        }
        $info = $info->toArray();

        if(!empty($info['type_extend'])){
            $info['type_extend'] = json_decode($info['type_extend'],true);
        }
        else{
            $info['type_extend'] = json_decode('{"type":"","area":"","lang":"","year":"","star":"","director":"","state":"","version":""}',true);
        }


        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    public function saveData($data)
    {
        $validate = \think\Loader::validate('Type');
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').': '.$validate->getError() ];
        }

        if(!empty($data['type_extend'])){
            $data['type_extend'] = json_encode($data['type_extend']);
        }
        if(empty($data['type_en'])){
            $data['type_en'] = Pinyin::get($data['type_name']);
        }

        // Apply the XSS filter to category fields.
        $filter_fields = [
            'type_name',
            'type_en',
            'type_tpl',
            'type_tpl_list',
            'type_tpl_detail',
            'type_tpl_play',
            'type_tpl_down',
            'type_key',
            'type_des',
            'type_title',
            'type_union',
            'type_logo',
            'type_pic',
            'type_jumpurl',
        ];
        foreach ($filter_fields as $filter_field) {
            if (!isset($data[$filter_field])) {
                continue;
            }
            $data[$filter_field] = mac_filter_xss($data[$filter_field]);
        }

        if(!empty($data['type_id'])){
            $where=[];
            $where['type_id'] = ['eq',$data['type_id']];
            $res = $this->allowField(true)->where($where)->update($data);
        }
        else{
            // xvid scale build (C6): the new id is needed to open the category to the user groups
            $res = $this->allowField(true)->insert($data, false, true);
        }
        if(false === $res){
            return ['code'=>1002,'msg'=>lang('save_err').': '.$this->getError() ];
        }
        if(empty($data['type_id']) && (int)$res > 0){
            // guests could not open videos of a category added after install (THM 5.7); a child copies the parent's rights
            \app\common\util\TypeAccess::grantNew((int)$res, (int)($data['type_pid'] ?? 0));
        }

        $this->setCache();
        self::bumpListRevision(true);
        self::edgeBump();
        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    public function delData($where)
    {
        $list = $this->where($where)->select();
        foreach($list as $k=>$v){
            $where2=[];
            $where2['type_id|type_id_1'] = ['eq',$v['type_id']];
            $flag = $v['type_mid'] == 1 ? 'Vod' : 'Art';
            $cc = model($flag)->where($where2)->count();
            if($cc > 0){
                return ['code' => 1021, 'msg' => lang('del_err') . ': ' . $v['type_name'] . ' is not empty (remaining entries: ' . $cc . '). Delete or move the content first.'];
            }
        }

        $res = $this->where($where)->delete();
        if($res===false){
            return ['code'=>1001,'msg'=>lang('del_err').': '.$this->getError() ];
        }

        $this->setCache();
        self::bumpListRevision(true);
        self::edgeBump();
        return ['code'=>1,'msg'=>lang('del_ok')];
    }

    public function fieldData($where,$col,$val)
    {
        if(!isset($col) || !isset($val)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $data = [];
        $data[$col] = $val;

        $res = $this->allowField(true)->where($where)->update($data);

        if($res===false){
            return ['code'=>1002,'msg'=>lang('set_err').': '.$this->getError() ];
        }

        $this->setCache();
        self::bumpListRevision(true);
        self::edgeBump();
        return ['code'=>1,'msg'=>lang('set_ok')];
    }

    public function moveData($where,$val)
    {
        $list = $this->where($where)->select();
        $type_info = $this->getCacheInfo($val);
        if(empty($type_info)){
            return ['code'=>1011,'msg'=>lang('model/type/to_info_err')];
        }
        foreach($list as $k=>$v){
            $where2=[];
            $where2['type_id|type_id_1'] = ['eq',$v['type_id']];
            $update=[];
            $update['type_id'] = $val;
            $update['type_id_1'] = $type_info['type_pid'];
            $flag = $v['type_mid'] == 1 ? 'Vod' : 'Art';
            $cc = model($flag)->where($where2)->update($update);
            if($cc ===false){
                return ['code'=>1012,'msg'=>lang('model/type/move_err').': '. $v['type_name'].': '.$this->getError()  ];
            }
        }
        self::bumpListRevision(true);
        self::edgeBump();
        return ['code'=>1,'msg'=>lang('model/type/move_ok')];
    }

    /** Contract C5 (EdgeCache): a category added, changed, hidden, shown, moved or deleted in the admin starts a new
     *  page-cache generation, so menus and category pages change at once (only admin paths call these methods; imports
     *  never bump the generation). */
    private static function edgeBump()
    {
        if (class_exists('\app\common\util\EdgeCache')) {
            try { \app\common\util\EdgeCache::bumpGeneration(true); } catch (\Throwable $e) { }
        }
    }

    /** r5 repair (beginner r5 P2-1): revision of the public category lists (Type::listCacheData). */
    public static function listRevision()
    {
        return (int)Cache::get($GLOBALS['config']['app']['cache_flag'] . '_type_rev');
    }

    /**
     * Revision of the public pages that print category lists (home "Browse categories", menus). It changes only when a
     * category is added, changed, hidden, deleted or moved - never on Clear cache - so the public page cache key
     * (All::page_cache_key) refreshes those pages at once without dropping the whole page cache on every Clear cache.
     */
    public static function pageRevision()
    {
        return (int)Cache::get($GLOBALS['config']['app']['cache_flag'] . '_type_page_rev');
    }

    /** $pages true: a category was added, changed, hidden, deleted or moved. false: Clear cache (list names only). */
    public static function bumpListRevision($pages = false)
    {
        $keys = [$GLOBALS['config']['app']['cache_flag'] . '_type_rev'];
        if ($pages) {
            $keys[] = $GLOBALS['config']['app']['cache_flag'] . '_type_page_rev';
        }
        foreach ($keys as $key) {
            Cache::set($key, max(time(), (int)Cache::get($key) + 1));
        }
    }

    public function setCache()
    {
        $res = $this->listData([],'type_id asc');
        $list = $res['list'];
        $key = $GLOBALS['config']['app']['cache_flag']. '_'.'type_list';
        Cache::set($key,$list);

        $type_tree = mac_list_to_tree($list,'type_id','type_pid');
        $key = $GLOBALS['config']['app']['cache_flag']. '_'.'type_tree';
        Cache::set($key,$type_tree);
    }

    public function getCache($flag='type_list')
    {
        $key = $GLOBALS['config']['app']['cache_flag']. '_'.$flag;
        $cache = Cache::get($key);
        if(empty($cache)){
            $this->setCache();
            $cache = Cache::get($key);
        }
        return $cache;
    }

    public function getCacheInfo($id)
    {
        $type_list = $this->getCache('type_list');
        if(is_numeric($id)) {
            return $type_list[$id];
        }
        else{

            foreach($type_list as $k=>$v){
                if($v['type_en'] == $id){
                    return $type_list[$k];
                }
            }
        }
    }



}