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
namespace app\index\controller;
use think\Controller;
use app\common\controller\All;

class Base extends All
{
    var $_group;
    var $_user;

    public function __construct()
    {
        // 1.2.0 (verify r2 O-F1, owner decision 2026-10-17): this request may spend its statement limit + 1 s in the database
        // in all, not the limit once per statement: every later statement gets the time left, and once it is spent the page
        // answers with its stale copy or the lighter page (DbTimeout::hookFramework, DbDeadlineStatement).
        if (PHP_SAPI !== 'cli' && class_exists('\app\common\util\DbTimeout') && class_exists('\app\common\util\ScaleTier')
            && method_exists('\app\common\util\DbTimeout', 'hookFramework')) {
            \app\common\util\DbTimeout::hookFramework((int)\app\common\util\ScaleTier::get()['stmt_limit']);
        }
        // Resolve custom route aliases before public user/database initialization.
        \app\common\util\PublicRequestGuard::enforceRoute(
            ROOT_PATH, request()->controller(), request()->action()
        );
        parent::__construct();
        $this->check_site_status();
        $this->label_maccms();
        $this->check_browser_jump();
        // 1.2.0 depth cap (XVID-SCALE-BRIEF L4, D-L5): before any list query, a page past the tier's cap is "not found".
        $this->depth_cap();
        $this->label_user();
        // The "txt" URL suffix exists for /robots.txt only (controller Robots): pages never answer as .txt.
        // The raw path is tested because ext() ignores a trailing slash (/search/wd/readme.txt/ is a search).
        if (preg_match('/\.txt$/i', (string) request()->pathinfo()) && !($this instanceof MyError)) {
            $this->page_error(lang('page_not_found'));
        }
    }

    /**
     * Page depth guard (L4, D-L5; XVID-NOLIMIT-BRIEF H1). The tier caps are 0 = none since the owner decision of 2026-10-15
     * (deep pages use the page map, rank files and cursors of lane S instead of OFFSET), so only a page above the sanity
     * ceiling max(100,000, 2 x videos) is answered with the theme's 404 page here, before any list query; a cap set again
     * (tier value > 0) still applies.
     * The page number comes from the query string or the route (category/<slug>-<page>/, .../page/<n>/).
     */
    protected function depth_cap()
    {
        if ($this instanceof MyError || !class_exists('\app\common\util\ScaleTier')) {
            return;
        }
        $page = 0;
        $request = request();
        // whole arrays only: param('page') throws on page[]=2 ("Invalid variable type: array")
        $route = $request->route();
        $params = $request->param();
        foreach ([$_GET['page'] ?? null, is_array($route) ? ($route['page'] ?? null) : null, is_array($params) ? ($params['page'] ?? null) : null] as $candidate) {
            if (is_int($candidate) || (is_string($candidate) && $candidate !== '' && strlen($candidate) <= 12 && ctype_digit($candidate))) {
                $page = max($page, (int)$candidate);
            } elseif (is_string($candidate) && strlen($candidate) > 12 && ctype_digit($candidate)) {
                $page = PHP_INT_MAX;
            }
        }
        if ($page <= 1) {
            return;
        }
        $tier = \app\common\util\ScaleTier::get();
        $action = strtolower((string)$request->action());
        if ($action === 'search' || $action === 'ajax_search') {
            $cap = (int)$tier['search_cap'];
        } else {
            $by = $_GET['by'] ?? (is_array($params) ? ($params['by'] ?? '') : '');
            $cap = is_string($by) && $by !== '' && !in_array(strtolower($by), ['time', 'time_add'], true) ? (int)$tier['by_cap'] : (int)$tier['list_cap'];
        }
        // XVID-NOLIMIT-BRIEF H1: cap 0 = none; only the sanity ceiling (max(100,000, 2 x videos), no real page lies beyond
        // it) answers 404 here. The real last page is the list's own check (S: list_page_last(), search C2/C3).
        $cap = \app\common\util\ScaleTier::ceilingFor($cap, (int)$tier['rows']);
        if ($page > $cap) {
            $this->page_error(lang('page_not_found'));
        }
    }

    public function _empty()
    {
        // Unknown action: the active theme's 404 page with HTTP 404 (JSON for AJAX clients).
        return $this->not_found_response(lang('page_not_found'));
    }

    protected function check_show($aj=0)
    {
        if($GLOBALS['config']['app']['show'] ==0){
            echo $this->error(lang('show_close'));
            exit;
        }
        if($GLOBALS['config']['app']['show_verify'] ==1 && $aj==0){
            if(empty(session('show_verify'))){
                mac_no_cahche();
                $this->assign('type','show');
                echo $this->label_fetch('public/verify', 0);
                exit;
            }
        }
    }

    protected function check_ajax()
    {
        if($GLOBALS['config']['app']['ajax_page'] ==0){
            echo $this->error(lang('ajax_close'));
            exit;
        }
    }

    protected function check_search($param,$aj=0)
    {
        if($GLOBALS['config']['app']['search'] ==0){
            echo $this->error(lang('search_close'));
            exit;
        }
        // Tag / actress landing pages (one tag or actor, no other filter) are normal pages for visitors and crawlers:
        // no search interval and no search captcha (SEO-ADMIN-BRIEF C.4). They are page-cached like other lists.
        if (\app\common\util\TemplateSeo::landing(is_array($param) ? $param : []) !== null) {
            return;
        }
        if($GLOBALS['config']['app']['search_verify'] ==1 && $aj ==0){
            if(empty(session('search_verify'))){
                mac_no_cahche();
                $this->assign('type','search');
                echo $this->label_fetch('public/verify', 0);
                exit;
            }
        }
        // The search interval keeps its time in the session: only start one when an interval is set (0 = off), so
        // anonymous visitors and crawlers do not leave one session file per search page.
        $searchSpan = (int)$GLOBALS['config']['app']['search_timespan'];
        if($searchSpan > 0 && $param['page']==1 && mac_get_time_span("last_searchtime") < $searchSpan){
            echo $this->error(lang('search_frequently')."".$GLOBALS['config']['app']['search_timespan']."".lang('seconds'));
            exit;
        }
    }

    protected function check_site_status()
    {
        if ($GLOBALS['config']['site']['site_status'] == 0) {
            http_response_code(503);
            header('Retry-After: 300');
            header('Cache-Control: no-store');
            $this->assign('close_tip',$GLOBALS['config']['site']['site_close_tip']);
            echo $this->fetch('public/close');
            die;
        }
    }

    protected function check_browser_jump()
    {
        if (ENTRANCE=='index' && $GLOBALS['config']['app']['browser_junmp'] == 1) {
            $agent = $_SERVER['HTTP_USER_AGENT'];
            if(strpos($agent, 'QQ/')||strpos($agent, 'MicroMessenger')!==false){
                echo $this->fetch('public/browser');
                die;
            }
        }
    }
    protected function check_user_popedom($type_id,$popedom,$param=[],$flag='',$info=[],$trysee=0)
    {
        $user = $GLOBALS['user'];
        $group = $GLOBALS['user']['group'];

        $res = false;
        if(strpos(','.$group['group_type'],','.$type_id.',')!==false && !empty($group['group_popedom'][$type_id][$popedom])!==false){
            $res = true;
        }
        $pre = $flag;
        $col = 'detail';
        if($flag=='play' || $flag=='down'){
            $pre = 'vod';
            $col = $flag;
        }

        if(in_array($pre,['art','vod','actor','website'])){
            $points = $info[$pre.'_points_'.$col] ?? 0;
            if($GLOBALS['config']['user'][$pre.'_points_type']=='1'){
                $points = $info[$pre.'_points'] ?? 0;
            }
        }

        if($GLOBALS['config']['user']['status']==0){

        }
        elseif($popedom==2 && in_array($pre,['art','actor','website'])){

            if($res===false && (empty($group['group_popedom'][$type_id][2]) || $trysee==0)){
                return ['code'=>3001,'msg'=>lang('controller/no_popedom'),'trysee'=>0];
            }
            elseif($group['group_id']<3 && $points>0  ){
                $mid = mac_get_mid($pre);
                $where=[];
                $where['ulog_mid'] = $mid;
                $where['ulog_type'] = 1;
                $where['ulog_rid'] = $param['id'];
                $where['ulog_sid'] = $param['page'];
                $where['ulog_nid'] = 0;
                $where['user_id'] = $user['user_id'];
                $where['ulog_points'] = $points;
                if($GLOBALS['config']['user'][$pre.'_points_type']=='1'){
                    $where['ulog_sid'] = 0;
                }
                $res = model('Ulog')->infoData($where);

                if($res['code'] > 1) {
                    return ['code'=>3003,'msg'=>lang('controller/pay_play_points',[$points]),'points'=>$points,'confirm'=>1,'trysee'=>0];
                }
            }
        }
        elseif($popedom==3){
            if($res===false && (empty($group['group_popedom'][$type_id][5]) || $trysee==0)){
                return ['code'=>3001,'msg'=>lang('controller/no_popedom'),'trysee'=>0];
            }
            elseif($group['group_id']<3 && empty($group['group_popedom'][$type_id][3]) && !empty($group['group_popedom'][$type_id][5]) && $trysee>0){
                return ['code'=>3002,'msg'=>lang('controller/in_try_see'),'trysee'=>$trysee];
            }
            elseif($group['group_id']<3 && $points>0  ){
                $where=[];
                $where['ulog_mid'] = 1;
                $where['ulog_type'] = $flag=='play' ? 4 : 5;
                $where['ulog_rid'] = $param['id'];
                $where['ulog_sid'] = $param['sid'];
                $where['ulog_nid'] = $param['nid'];
                $where['user_id'] = $user['user_id'];
                $where['ulog_points'] = $points;
                if($GLOBALS['config']['user']['vod_points_type']=='1'){
                    $where['ulog_sid'] = 0;
                    $where['ulog_nid'] = 0;
                }
                $res = model('Ulog')->infoData($where);

                if($res['code'] > 1) {
                    return ['code'=>3003,'msg'=>lang('controller/pay_play_points',[$points]),'points'=>$points,'confirm'=>1,'trysee'=>0];
                }
            }
        }
        else{
            if($res===false){
                return ['code'=>1001,'msg'=>lang('controller/no_popedom')];
            }
            if($popedom == 4){
                if( $group['group_id'] ==1 && $points>0){
                    return ['code'=>4001,'msg'=>lang('controller/charge_data'),'trysee'=>0];
                }
                elseif($group['group_id'] ==2 && $points>0){
                    $where=[];
                    $where['ulog_mid'] = 1;
                    $where['ulog_type'] = $flag=='play' ? 4 : 5;
                    $where['ulog_rid'] = $param['id'];
                    $where['ulog_sid'] = $param['sid'];
                    $where['ulog_nid'] = $param['nid'];
                    $where['user_id'] = $user['user_id'];
                    $where['ulog_points'] = $points;
                    if($GLOBALS['config']['user']['vod_points_type']=='1'){
                        $where['ulog_sid'] = 0;
                        $where['ulog_nid'] = 0;
                    }
                    $res = model('Ulog')->infoData($where);

                    if($res['code'] > 1) {
                        return ['code'=>4003,'msg'=>lang('controller/pay_down_points',[$points]),'points'=>$points,'confirm'=>1,'trysee'=>0];
                    }
                }
            }
            elseif($popedom==5){
                if(empty($group['group_popedom'][$type_id][3]) && !empty($group['group_popedom'][$type_id][5])){
                    $where=[];
                    $where['ulog_mid'] = 1;
                    $where['ulog_type'] = $flag=='play' ? 4 : 5;
                    $where['ulog_rid'] = $param['id'];
                    $where['ulog_sid'] = $param['sid'];
                    $where['ulog_nid'] = $param['nid'];
                    $where['user_id'] = $user['user_id'];
                    $where['ulog_points'] = $points;
                    if($GLOBALS['config']['user']['vod_points_type']=='1'){
                        $where['ulog_sid'] = 0;
                        $where['ulog_nid'] = 0;
                    }
                    $res = model('Ulog')->infoData($where);

                    if(2 == 1) {

                    }
                    elseif($points>0 && $res['code'] == 1) {

                    }
                    elseif( $group['group_id'] <=2 && $points <= intval($user['user_points']) ){
                        return ['code'=>5001,'msg'=>lang('controller/try_see_end',[$points, $user['user_points']]),'trysee'=>$trysee];
                    }
                    elseif( $group['group_id'] <3 && $points > intval($user['user_points']) ){
                        return ['code'=>5002,'msg'=>lang('controller/not_enough_points',[$points,$user['user_points'] ]),'trysee'=>$trysee];
                    }
                }
            }
        }

        return ['code'=>1,'msg'=>lang('controller/popedom_ok')];
    }
}