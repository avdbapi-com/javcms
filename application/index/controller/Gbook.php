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

class Gbook extends Base
{
    var $_config;
    public function __construct()
    {
        parent::__construct();
        // Stop when the guestbook is disabled.
        if($GLOBALS['config']['gbook']['status'] == 0){
            echo 'The guestbook is disabled.';
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
        $this->assign('gbook',$GLOBALS['config']['gbook']);
        return $this->label_fetch('gbook/index', 0);
    }

    public function ajax()
    {
        $param = mac_param_url();
        $this->assign('param',$param);
        $this->assign('gbook',$GLOBALS['config']['gbook']);
        return $this->label_fetch('gbook/ajax',0,'json');
    }

    public function report()
    {
        $param = mac_param_url();
        $this->assign('param',$param);
        $this->assign('gbook',$GLOBALS['config']['gbook']);
        return $this->label_fetch('gbook/report');
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
        $allowed = ['gbook_content', 'gbook_rid', 'verify'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $posted) && !is_string($posted[$field]) && !is_int($posted[$field])) {
                return ['code'=>1001,'msg'=>lang('param_err')];
            }
        }
        $param = array_intersect_key($posted, array_flip($allowed));
        $param += ['gbook_content'=>'','gbook_rid'=>0,'verify'=>''];
        // Public submissions create records. IDs, counters and admin fields never enter model updates.


        if($GLOBALS['config']['gbook']['verify'] == 1){
            if(!captcha_check($param['verify'])){
                return ['code'=>1002,'msg'=>lang('verify_err')];
            }
        }

        if ($GLOBALS['config']['gbook']['login'] == 1 && (int)($GLOBALS['user']['user_id'] ?? 0) < 1) {
            return ['code'=>1003,'msg'=>lang('index/require_login')];
        }

        if(empty($param['gbook_content'])){
            return ['code'=>1004,'msg'=>lang('index/require_content')];
        }
        $content = (string)$param['gbook_content'];
        if (strlen($content) > 4000 || !preg_match('//u', $content)
            || (function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : preg_match_all('/./us', $content)) > 1000
            || strlen((string)$param['verify']) > 128) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $cookie = 'gbook_timespan';
        if(!empty(cookie($cookie))){
            return ['code'=>1005,'msg'=>lang('frequently')];
        }

        $param['gbook_content']= htmlentities(mac_filter_words($param['gbook_content']));
        // Bound the serialized value to the native VARCHAR(255) storage column.
        if (!preg_match('//u', $param['gbook_content'])
            || (function_exists('mb_strlen') ? mb_strlen($param['gbook_content'], 'UTF-8') : preg_match_all('/./us', $param['gbook_content'])) > 255) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        // if (! preg_match (\ '/ [^\\ x00-\\ x80]/\ ', $param [\ 'gbook_content\']) {
        // return [\'code\'=>1005,\'msg\'=>lang(\'index/require_cn\')];
        // }

        if (!preg_match('/^[0-9]{1,10}$/D', (string)$param['gbook_rid']) || (float)$param['gbook_rid'] > 4294967295) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $param['gbook_rid'] = (int)$param['gbook_rid'];

        $user = $GLOBALS['user'] ?? [];
        $param['user_id'] = max(0, (int)($user['user_id'] ?? 0));
        $param['gbook_name'] = $param['user_id'] > 0
            ? (string)(!empty($user['user_nick_name']) ? $user['user_nick_name'] : ($user['user_name'] ?? ''))
            : lang('controller/visitor');
        // Reject invalid or oversized identity labels without truncating validated identities.
        if (!preg_match('//u', $param['gbook_name'])) { return ['code'=>1001,'msg'=>lang('param_err')]; }
        $param['gbook_name'] = htmlentities(trim($param['gbook_name']));
        if ((function_exists('mb_strlen') ? mb_strlen($param['gbook_name'], 'UTF-8') : preg_match_all('/./us', $param['gbook_name'])) > 60) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $param['gbook_status'] = $GLOBALS['config']['gbook']['audit'] == 1 ? 0 : 1;


        $param['gbook_ip'] = mac_get_ip_long();

        $res = model('Gbook')->saveData($param);

        if($res['code']>1){
            return $res;
        }
        else{
            cookie($cookie, 't', $GLOBALS['config']['gbook']['timespan']);
            if($GLOBALS['config']['gbook']['audit'] ==1){
                $res['msg'] =  lang('index/thanks_msg_audit');
            }
            else{
                $res['msg'] = lang('index/thanks_msg');
            }
            return $res;
        }
    }

}
