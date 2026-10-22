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
namespace app\common\model;
use think\Db;
use think\Cache;

class Extend extends Base {


    /**
     * Counts for nav menus, footers and maps (mac_data_count). 1.2.0 (XVID-SCALE-BRIEF D-L3, L7): visitors never run the
     * whole-table GROUP BY / COUNT / MIN any more (49.6 s at 7 M videos after every cache wipe). The counts job
     * (ScaleCounts::recount, run by housekeeping and at import ends on its own connection) stores every key this method
     * returned before in the scale_count table; this method reads that table (one small query per request, no 'vod' tag).
     * Missing table or never counted: [] (numbers hidden), never computed here.
     */
    public function dataCount()
    {
        return \app\common\util\ScaleCounts::dataCount();
    }

    public function areaData($lp)
    {
        $order = $lp['order'];
        $start = mac_legacy_abs_int($lp['start']);
        $num = mac_legacy_abs_int($lp['num']);
        $tid = intval($lp['tid']);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_area'];
        if($tid>0){
            $type_list = model('Type')->getCache('tree_list');
            $type_info = $type_list[$tid];
            if(!empty($type_info)){
                $type_extend = json_decode($type_info['type_extend'],true);
                $data_str = $type_extend['area'];
            }
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$num){
                $list[] = ['area_name' => $v];
            }
        }

        $list = array_filter($list);
        $total = count($list);

        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' . md5('area_listcache_'.join('&',$lp).'_'.$order.'_'.$num.'_'.$start);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function langData($lp)
    {
        $order = $lp['order'];
        $start = mac_legacy_abs_int($lp['start']);
        $num = mac_legacy_abs_int($lp['num']);
        $tid = intval($lp['tid']);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_lang'];
        if($tid>0){
            $type_list = model('Type')->getCache('tree_list');
            $type_info = $type_list[$tid];
            if(!empty($type_info)){
                $type_extend = json_decode($type_info['type_extend'],true);
                $data_str = $type_extend['lang'];
            }
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$num){
                $list[] = ['lang_name' => $v];
            }
        }
        $list = array_filter($list);
        $total = count($list);

        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' . md5('lang_listcache_'.join('&',$lp).'_'.$order.'_'.$num.'_'.$start);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function classData($lp)
    {
        $order = $lp['order'];
        $start = mac_legacy_abs_int($lp['start']);
        $num = mac_legacy_abs_int($lp['num']);
        $tid = intval($lp['tid']);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_class'];
        if($tid>0){
            $type_list = model('Type')->getCache('tree_list');
            $type_info = $type_list[$tid];
            if(!empty($type_info)){
                $type_extend = json_decode($type_info['type_extend'],true);
                $data_str = $type_extend['class'];
            }
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$num){
                $list[] = ['class_name' => $v];
            }
        }
        $list = array_filter($list);
        $total = count($list);

        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' . md5('class_listcache_'.join('&',$lp).'_'.$order.'_'.$num.'_'.$start);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function yearData($lp)
    {
        $order = $lp['order'];
        $start = mac_legacy_abs_int($lp['start']);
        $num = mac_legacy_abs_int($lp['num']);
        $tid = intval($lp['tid']);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_year'];
        if($tid>0){
            $type_list = model('Type')->getCache('tree_list');
            $type_info = $type_list[$tid];
            if(!empty($type_info)){
                $type_extend = json_decode($type_info['type_extend'],true);
                $data_str = $type_extend['year'];
            }
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$num){
                $list[] = ['year_name' => $v];
            }
        }
        $list = array_filter($list);
        $total = count($list);

        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' . md5('year_listcache_'.join('&',$lp).'_'.$order.'_'.$num.'_'.$start);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function versionData($lp)
    {
        $order = $lp['order'];
        $start = mac_legacy_abs_int($lp['start']);
        $num = mac_legacy_abs_int($lp['num']);
        $tid = intval($lp['tid']);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_version'];
        if($tid>0){
            $type_list = model('Type')->getCache('tree_list');
            $type_info = $type_list[$tid];
            if(!empty($type_info)){
                $type_extend = json_decode($type_info['type_extend'],true);
                $data_str = $type_extend['version'];
            }
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$num){
                $list[] = ['version_name' => $v];
            }
        }

        $list = array_filter($list);
        $total = count($list);

        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' . md5('version_listcache_'.join('&',$lp).'_'.$order.'_'.$num.'_'.$start);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function stateData($lp)
    {
        $order = $lp['order'];
        $start = mac_legacy_abs_int($lp['start']);
        $num = mac_legacy_abs_int($lp['num']);
        $tid = intval($lp['tid']);

        $config = config('maccms.app');
        $data_str = $config['vod_extend_state'];
        if($tid>0){
            $type_list = model('Type')->getCache('tree_list');
            $type_info = $type_list[$tid];
            if(!empty($type_info)){
                $type_extend = json_decode($type_info['type_extend'],true);
                $data_str = $type_extend['state'];
            }
        }

        if (!in_array($order, ['asc', 'desc'])) {
            $order = 'asc';
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        $tmp = explode(',',$data_str);
        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$num){
                $list[] = ['state_name' => $v];
            }
        }
        $list = array_filter($list);
        $total = count($list);

        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' . md5('state_listcache_'.join('&',$lp).'_'.$order.'_'.$num.'_'.$start);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }

    public function letterData($lp)
    {
        $data_str = 'A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z,0-9';
        $tmp = explode(',',$data_str);

        $order = $lp['order'];
        $start = mac_legacy_abs_int($lp['start']);
        $num = mac_legacy_abs_int($lp['num']);
        $tid = intval($lp['tid']);

        if (!in_array($order, ['asc', 'desc'])) {
            $order = 'asc';
        }

        if(empty($num)){
            $num = 20;
        }
        if($start>1){
            $start--;
        }

        if($tid>0){

        }

        if($order=='desc'){
            $tmp = array_reverse($tmp);
        }
        $list = [];
        foreach($tmp as $k=>$v){
            if($k>=$start && $k<$num){
                $list[] = ['letter_name' => $v];
            }
        }

        $list = array_filter($list);
        $total = count($list);

        $cach_name = $GLOBALS['config']['app']['cache_flag']. '_' . md5('letter_listcache_'.join('&',$lp).'_'.$order.'_'.$num.'_'.$start);

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>1,'limit'=>$num,'total'=>$total,'list'=>$list];
    }



}