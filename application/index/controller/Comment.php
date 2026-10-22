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
use think\Controller;
use \think\Request;

class Comment extends Base
{
    public function __construct()
    {
        parent::__construct();
        // Stop when comments are disabled.
        if($GLOBALS['config']['comment']['status'] == 0){
            echo 'Comments are disabled.';
            exit;
        }
    }

    public function index()
    {
        if (Request()->isPost()) {
            return $this->saveData();
        }
        $param = mac_param_url();
        $this->assign('param',$param);
        $this->assign('comment',$GLOBALS['config']['comment']);

        return $this->label_fetch('comment/index', 0);
    }
    
    public function ajax() {
        $param = mac_param_url();
        $this->assign('param',$param);
        $this->assign('comment',$GLOBALS['config']['comment']);
        return $this->label_fetch('comment/ajax',0,'json');
    }

    public function token() {
        return \app\common\util\FrontCsrf::response();
    }

    public function saveData() {
        return json($this->saveSubmission());
    }

    protected function saveSubmission() {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') { return ['code'=>1001,'msg'=>lang('illegal_request')]; }
        $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if (!in_array($contentType, ['application/x-www-form-urlencoded', 'multipart/form-data', 'application/json'], true)
            || (isset($_SERVER['CONTENT_LENGTH']) && (!ctype_digit((string)$_SERVER['CONTENT_LENGTH']) || (float)$_SERVER['CONTENT_LENGTH'] > 16384))) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $posted = Request()->post();
        if (!is_array($posted)) { return ['code'=>1001,'msg'=>lang('param_err')]; }
        if (!\app\common\util\FrontCsrf::check($posted)) { return ['code'=>1001,'msg'=>lang('illegal_request')]; }
        $allowed = ['comment_content', 'comment_mid', 'comment_rid', 'comment_pid', 'verify'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $posted) && !is_string($posted[$field]) && !is_int($posted[$field])) {
                return ['code'=>1001,'msg'=>lang('param_err')];
            }
        }
        $param = array_intersect_key($posted, array_flip($allowed));
        $param += ['comment_content'=>'','comment_mid'=>'','comment_rid'=>0,'comment_pid'=>0,'verify'=>''];
        // Public submissions create records. IDs, counters and admin fields never enter model updates.


        if($GLOBALS['config']['comment']['verify'] == 1){
            if(!captcha_check($param['verify'])){
                return ['code'=>1002,'msg'=>lang('verify_err')];
            }
        }

        if ($GLOBALS['config']['comment']['login'] == 1 && (int)($GLOBALS['user']['user_id'] ?? 0) < 1) {
            return ['code'=>1003,'msg'=>lang('index/require_login')];
        }

        if(empty($param['comment_content'])){
            return ['code'=>1004,'msg'=>lang('index/require_content')];
        }
        $content = (string)$param['comment_content'];
        if (strlen($content) > 4000 || !preg_match('//u', $content)
            || (function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : preg_match_all('/./us', $content)) > 1000
            || strlen((string)$param['verify']) > 128) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $cookie = 'comment_timespan';
        if(!empty(cookie($cookie))){
            return ['code'=>1005,'msg'=>lang('frequently')];
        }

        $param['comment_content']= htmlentities(mac_filter_words($param['comment_content']));
        // Bound the serialized value to the native VARCHAR(255) storage column.
        if (!preg_match('//u', $param['comment_content'])
            || (function_exists('mb_strlen') ? mb_strlen($param['comment_content'], 'UTF-8') : preg_match_all('/./us', $param['comment_content'])) > 255) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        // if(!preg_match(\'/[^\\x00-\\x80]/\',$param[\'comment_content\'])){
        // return [\'code\'=>1005,\'msg\'=>lang(\'index/require_cn\')];
        // }

        if(!in_array((string)$param['comment_mid'],['1','2','3','8','9','11'],true)){
            return ['code'=>1006,'msg'=>lang('index/mid_err')];
        }

        $user = $GLOBALS['user'] ?? [];
        $param['user_id'] = max(0, (int)($user['user_id'] ?? 0));
        $param['comment_name'] = $param['user_id'] > 0
            ? (string)(!empty($user['user_nick_name']) ? $user['user_nick_name'] : ($user['user_name'] ?? ''))
            : lang('controller/visitor');
        // Reject invalid or oversized identity labels without truncating validated identities.
        if (!preg_match('//u', $param['comment_name'])) { return ['code'=>1001,'msg'=>lang('param_err')]; }
        $param['comment_name'] = htmlentities(trim($param['comment_name']));
        if ((function_exists('mb_strlen') ? mb_strlen($param['comment_name'], 'UTF-8') : preg_match_all('/./us', $param['comment_name'])) > 60) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        foreach (['comment_rid','comment_pid'] as $field) {
            if (!preg_match('/^[0-9]{1,10}$/D', (string)$param[$field]) || (float)$param[$field] > 4294967295) {
                return ['code'=>1001,'msg'=>lang('param_err')];
            }
            $param[$field] = (int)$param[$field];
        }
        if ($param['comment_rid'] < 1) { return ['code'=>1001,'msg'=>lang('param_err')]; }
        $media = [1=>'vod',2=>'art',3=>'topic',8=>'actor',9=>'role',11=>'website'][(int)$param['comment_mid']];
        $mediaWhere = [$media.'_id'=>$param['comment_rid'], $media.'_status'=>1];
        if ($media === 'vod') { $mediaWhere['vod_recycle_time'] = 0; }
        if (!model($media)->where($mediaWhere)->find()) { return ['code'=>1001,'msg'=>lang('param_err')]; }
        if ($param['comment_pid'] > 0) {
            $parent = model('Comment')->where([
                'comment_id'=>$param['comment_pid'], 'comment_mid'=>$param['comment_mid'],
                'comment_rid'=>$param['comment_rid'], 'comment_status'=>1
            ])->find();
            if (!$parent) { return ['code'=>1001,'msg'=>lang('param_err')]; }
        }
        $param['comment_status'] = $GLOBALS['config']['comment']['audit'] == 1 ? 0 : 1;


        $param['comment_ip'] = mac_get_ip_long();

        $res = model('Comment')->saveData($param);
        if($res['code']>1){
            return $res;
        }
        else{
            cookie($cookie, 't', $GLOBALS['config']['comment']['timespan']);
            if($GLOBALS['config']['comment']['audit'] ==1){
                $res['msg'] = lang('index/thanks_msg_audit');
            }
            else{
                $res['msg'] = lang('index/thanks_msg');
            }
            return $res;
        }
    }

    public function report()
    {
        $param = input();
        $id = intval($param['id']);

        if(empty($id) ) {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }

        $cookie = 'comment-report-' . $id;
        if(!empty(cookie($cookie))){
            return json(['code'=>1002,'msg'=>lang('index/haved')]);
        }
        $where = [];
        $where['comment_id'] = $id;
        model('comment')->where($where)->setInc('comment_report');
        cookie($cookie, 't', $GLOBALS['config']['comment']['timespan']);

        return json(['code'=>1,'msg'=>lang('opt_ok')]);
    }

    public function digg()
    {
        $param = input();
        $id = intval($param['id']);
        $type = $param['type'];

        if(empty($id) ||  empty($type) ) {
            return json(['code'=>1001,'msg'=>lang('param_err')]);
        }

        $pre = 'comment';
        $where = [];
        $where[$pre.'_id'] = $id;
        $field = $pre.'_up,'.$pre.'_down';
        $model = model($pre);

        if($type) {
            $cookie = $pre . '-digg-' . $id;
            if(!empty(cookie($cookie))){
                return json(['code'=>1002,'msg'=>lang('index/haved')]);
            }
            if ($type == 'up') {
                $model->where($where)->setInc($pre . '_up');
            } elseif ($type == 'down') {
                $model->where($where)->setInc($pre . '_down');
            }
            cookie($cookie, 't', $GLOBALS['config']['comment']['timespan']);
        }

        $res = $model->infoData($where,$field);
        if($res['code']>1) {
            return json($res);
        }
        $info = $res['info'];
        if ($info) {
            $data = $info;
        }
        else{
            $data[$pre.'_up'] = 0;
            $data[$pre.'_down'] = 0;
        }
        return json(['code'=>1,'msg'=>lang('opt_ok'),'data'=>$data]);
    }

}
