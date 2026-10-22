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
use think\View;
use app\common\validate\User as UserValidate;

class User extends Base
{
    // Table name without the configured prefix
    protected $name = 'user';

    // Define timestamp field names
    protected $createTime = '';
    protected $updateTime = '';

    // Fields to populate automatically
    protected $auto = [];
    protected $insert = [];
    protected $update = [];

    public $_guest_group = 1;
    public $_def_group = 2;

    public function countData($where)
    {
        $total = $this->where($where)->count();
        return $total;
    }

    public function listData($where, $order, $page = 1, $limit = 20, $start = 0)
    {
        $total = $this->where($where)->count();
        $list = Db::name('User')->where($where)->order($order)->page($page)->limit($limit)->select();
        return ['code' => 1, 'msg' => lang('data_list'), 'page' => $page, 'pagecount' => ceil($total / $limit), 'limit' => $limit, 'total' => $total, 'list' => $list];
    }

    public function infoData($where, $field='*')
    {
        if (empty($where) || !is_array($where)) {
            return ['code' => 1001, 'msg'=>lang('param_err')];
        }
        $info = $this->field($field)->where($where)->find();
        if (empty($info)) {
            return ['code' => 1002, 'msg' => lang('obtain_err')];
        }
        $info = $info->toArray();

        // Attach the cached group details.
        $group_list = model('Group')->getCache('group_list');
        $info['group'] = $group_list[$info['group_id']];


        $info['user_pwd'] = '';
        return ['code' => 1, 'msg' =>lang('obtain_ok'), 'info' => $info];
    }

    public function saveData($data)
    {
        $validate = \think\Loader::validate('User');

        if (isset($data['user_start_time']) && !is_numeric($data['user_start_time'])) {
            $data['user_start_time'] = strtotime($data['user_start_time']);
        }
        if (isset($data['user_end_time']) && !is_numeric($data['user_end_time'])) {
            $data['user_end_time'] = strtotime($data['user_end_time']);
        }

        if (!empty($data['user_id'])) {
            if (!$validate->scene('edit')->check($data)) {
                return ['code' => 1001, 'msg' => lang('param_err').': ' . $validate->getError()];
            }

            if (empty($data['user_pwd'])) {
                unset($data['user_pwd']);
            } else {
                $data['user_pwd'] = mac_hash_password_for_column($data['user_pwd'], 'user', 'user_pwd');
            }
            $where = [];
            $where['user_id'] = ['eq', $data['user_id']];
            $res = $this->where($where)->update($data);
        } else {
            if (!$validate->scene('edit')->check($data)) {
                return ['code' => 1002, 'msg' => lang('param_err').': ' . $validate->getError()];
            }

            $data['user_pwd'] = mac_hash_password_for_column($data['user_pwd'], 'user', 'user_pwd');
            $res = $this->insert($data);
            if ($res !== false) {
                $this->ensureInviteCodeCompat($this->getLastInsID());
            }
        }
        if (false === $res) {
            return ['code' => 1003, 'msg' => '' . $this->getError()];
        }
        return ['code' => 1, 'msg' =>lang('save_ok')];
    }

    public function delData($where)
    {
        $res = $this->where($where)->delete();
        if ($res === false) {
            return ['code' => 1001, 'msg' => lang('del_err').': ' . $this->getError()];
        }
        return ['code' => 1, 'msg'=>lang('del_ok')];
    }

    public function fieldData($where, $col, $val)
    {
        if (!isset($col) || !isset($val)) {
            return ['code' => 1001, 'msg'=>lang('param_err')];
        }
        $data = [];
        $data[$col] = $val;
        $res = $this->where($where)->update($data);
        if ($res === false) {
            return ['code' => 1002, 'msg' => lang('set_err').': ' . $this->getError()];
        }
        return ['code' => 1, 'msg' =>lang('set_ok')];
    }

    public function register($param)
    {
        return $this->registerAccount($param, []);
    }

    // Internal callback entry: identity must come from the provider exchange.
    public function registerFromOauth($param, $provider, $openid)
    {
        $column = $this->oauthColumn($provider, $openid);
        if ($column === false) {
            return ['code' => 1002, 'msg' => lang('param_err')];
        }
        return $this->registerAccount($param, [$column => $openid]);
    }

    private function oauthColumn($provider, $openid)
    {
        if (!in_array($provider, ['qq', 'weixin'], true) || !is_string($openid)
            || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $openid)) {
            return false;
        }
        return 'user_openid_' . $provider;
    }

    private function registerAccount($param, array $identity)
    {
        if (!is_array($param)) {
            return ['code' => 1002, 'msg' => lang('model/user/input_require')];
        }
        foreach (['user_name', 'user_pwd', 'user_pwd2', 'verify'] as $field) {
            if (isset($param[$field]) && !is_string($param[$field]) && !is_int($param[$field])) {
                return ['code' => 1002, 'msg' => lang('model/user/input_require')];
            }
            $param[$field] = isset($param[$field]) ? (string) $param[$field] : '';
        }
        $param += ['user_name' => '', 'user_pwd' => '', 'user_pwd2' => '', 'verify' => '', 'uid' => 0];
        $config = config('maccms');

        $data = [];
        $password_raw = trim($param['user_pwd']);
        $data['user_name'] = htmlspecialchars(urldecode(trim($param['user_name'])));
        $data['user_pwd'] = htmlspecialchars(urldecode(trim($param['user_pwd'])));
        $data['user_pwd2'] = htmlspecialchars(urldecode(trim($param['user_pwd2'])));
        $data['verify'] = $param['verify'];
        $uid = (int) $param['uid'];
        $is_from_3rdparty = !empty($identity);
        $messageVerificationEnabled = !$is_from_3rdparty && (
            (string) ($config['user']['reg_phone_sms'] ?? '0') === '1'
            || (string) ($config['user']['reg_email_sms'] ?? '0') === '1'
        );


        if ($config['user']['status'] == 0 || $config['user']['reg_open'] == 0) {
            return ['code' => 1001, 'msg' => lang('model/user/not_open_reg')];
        }
        if (empty($data['user_name']) || empty($data['user_pwd']) || empty($data['user_pwd2'])) {
            return ['code' => 1002, 'msg' => lang('model/user/input_require')];
        }
        // SMS/email registration checks the CAPTCHA when requesting a code,
        // then verifies access to the recipient with that one-time code.
        if (!$is_from_3rdparty && !$messageVerificationEnabled
            && !captcha_check($data['verify']) && $config['user']['reg_verify'] == 1) {
            return ['code' => 1003, 'msg' => lang('verify_err')];
        }
        if ($data['user_pwd'] != $data['user_pwd2']) {
            return ['code' => 1004, 'msg' => lang('model/user/pass_not_pass2')];
        }
        $row = $this->where('user_name', $data['user_name'])->find();
        if (!empty($row)) {
            return ['code' => 1005, 'msg' => lang('model/user/haved_reg')];
        }
        if (!preg_match("/^[a-zA-Z\d]*$/i", $data['user_name'])) {
            return ['code' => 1006, 'msg' => lang('model/user/name_contain')];
        }

        $validate = \think\Loader::validate('User');
        if (!$validate->scene('add')->check($data)) {
            return ['code' => 1007, 'msg' => lang('param_err').': ' . $validate->getError()];
        }

        $filter = $GLOBALS['config']['user']['filter_words'];
        if(!empty($filter)) {
            $filter_arr = explode(',', $filter);
            $f_name = str_replace($filter_arr, '', $data['user_name']);
            if ($f_name != $data['user_name']) {
                return ['code' => 1008, 'msg' =>lang('model/user/name_filter',[$filter])];
            }
        }

        $ip = mac_get_ip_long();
        if( $GLOBALS['config']['user']['reg_num'] > 0){
            $where2=[];
            $where2['user_reg_ip'] = ['eq', $ip];
            $where2['user_reg_time'] = ['gt', strtotime('today')];
            $cc = $this->where($where2)->count();
            if($cc >= $GLOBALS['config']['user']['reg_num']){
                return ['code' => 1009, 'msg' => lang('model/user/ip_limit',[$GLOBALS['config']['user']['reg_num']])];
            }
        }

        $fields = [];
        $fields['user_name'] = $data['user_name'];
        $fields['user_pwd'] = mac_hash_password_for_column($password_raw, 'user', 'user_pwd');
        $fields['group_id'] = $this->_def_group;
        $fields['user_points'] = intval($config['user']['reg_points']);
        $fields['user_status'] = intval($config['user']['reg_status']);
        $fields['user_reg_time'] = time();
        $fields['user_reg_ip'] = $ip;
        $fields['user_openid_qq'] = $identity['user_openid_qq'] ?? '';
        $fields['user_openid_weixin'] = $identity['user_openid_weixin'] ?? '';

        $messageParam = null;
        if (!$is_from_3rdparty) {
            // https://github.com/magicblack/maccms10/issues/418
            if($config['user']['reg_phone_sms'] == '1'){
                $param['type'] = 3;
                $param['ac'] = 'phone';
                $prepared = $this->messageParameters($param);
                if ($prepared['code'] > 1) {
                    return $prepared;
                }
                $param = $prepared['param'];
                $res = $this->check_msg($param);
                if ($res['code'] > 1) {
                    return $res;
                }
                $messageParam = $param;
                $fields['user_phone'] = $param['to'];

                $update=[];
                $update['user_phone'] = '';
                $where2=[];
                $where2['user_phone'] = $param['to'];

                $row = $this->where($where2)->find();
                if (!empty($row)) {
                    return ['code' => 1011, 'msg' =>lang('model/user/phone_haved')];
                }
                // $this->where($where2)->update($update);
            }
            elseif($config['user']['reg_email_sms'] == '1'){
                $param['type'] = 3;
                $param['ac'] = 'email';
                $prepared = $this->messageParameters($param);
                if ($prepared['code'] > 1) {
                    return $prepared;
                }
                $param = $prepared['param'];
                $res = $this->check_msg($param);
                if ($res['code'] > 1) {
                    return $res;
                }
                $messageParam = $param;
                $fields['user_email'] = $param['to'];

                $update=[];
                $update['user_email'] = '';
                $where2=[];
                $where2['user_email'] = $param['to'];

                $row = $this->where($where2)->find();
                if (!empty($row)) {
                    return ['code' => 1012, 'msg' => lang('model/user/email_haved')];
                }
                // $this->where($where2)->update($update);
            }
        }

        $nid = 0;
        $failure = ['code' => 1010, 'msg' => lang('model/user/reg_err')];
        $create = function ($message) use ($fields, &$nid, $failure) {
            if ($this->insert($fields) === false) {
                return $failure;
            }
            $nid = $this->getLastInsID();
            return ['code' => 1, 'msg' => lang('model/user/reg_ok')];
        };
        $res = $messageParam === null ? $create($param) : $this->completeMessageAction($messageParam, $create, $failure);
        if ($res['code'] > 1) {
            return $res;
        }
        $uid = intval($uid);
        $inviteCodeColumn = $this->hasUserColumnCompat('user_invite_code');
        if ($inviteCodeColumn) {
            // Keep existing uid referrals when no invite code is supplied.
            // A supplied code replaces uid; unresolved codes do not create a referral.
            $this->ensureInviteCodeCompat($nid);
            $inviteCode = isset($param['invite_code']) && (is_string($param['invite_code']) || is_int($param['invite_code']))
                ? trim((string) $param['invite_code']) : '';
            if ($inviteCode === '') {
                $cookieInviteCode = cookie('invite_code');
                if (is_string($cookieInviteCode) || is_int($cookieInviteCode)) {
                    $inviteCode = trim((string) $cookieInviteCode);
                }
            }
            if ($inviteCode !== '') {
                $uid = 0;
                $resolvedUid = $this->resolveInviteIdCompat($inviteCode);
                if ($resolvedUid > 0) {
                    $uid = $resolvedUid;
                }
            }
        }
        if($uid > 0 && $uid !== intval($nid)) {
            $where2 = [];
            $where2['user_id'] = $uid;
            $invite = $this->where($where2)->find();
            if ($invite) {
                $where=[];
                $where['user_id'] = $nid;
                $update=[];
                $update['user_pid'] = $invite['user_id'];
                $update['user_pid_2'] = $invite['user_pid'];
                $update['user_pid_3'] = $invite['user_pid_2'];
                $r1 = $this->where($where)->update($update);
                $r2 = false;
                $config['user']['invite_reg_num'] = intval($config['user']['invite_reg_num']);

                if($config['user']['invite_reg_points']>0){
                    $r2 = $this->where($where2)->setInc('user_points', $config['user']['invite_reg_points']);
                }

                if($r2!==false) {
                    // Record the registration referral reward.
                    $data = [];
                    $data['user_id'] = $uid;
                    $data['plog_type'] = 2;
                    $data['plog_points'] = $config['user']['invite_reg_points'];
                    model('Plog')->saveData($data);
                }
                if ($this->hasUserColumnCompat('user_invite_count')) {
                    $this->where($where2)->setInc('user_invite_count', 1);
                }
            }
        }
        return ['code' => 1, 'msg' => lang('model/user/reg_ok')];
    }

    public function regcheck($t, $str)
    {
        $where = [];
        if ($t == 'user_name') {
            $where['user_name'] = $str;
            $row = $this->where($where)->find();
            if (!empty($row)) {
                return ['code' => 1001, 'msg' => lang('registered')];
            }
        } elseif ($t == 'user_email') {
            $where['user_email'] = $str;
            $row = $this->where($where)->find();
            if (!empty($row)) {
                return ['code' => 1001, 'msg' =>  lang('registered')];
            }
        } elseif ($t == 'verify') {
            if (!captcha_check($str)) {
                return ['code' => 1002, 'msg' => lang('verify_err')];
            }
        }
        return ['code' => 1, 'msg' => 'ok'];
    }

    public function info($param)
    {
        if (empty($param['user_pwd'])) {
            return ['code' => 1001, 'msg' => lang('model/user/input_old_pass')];
        }
        $password_raw = trim($param['user_pwd']);
        $userId = isset($GLOBALS['user']['user_id']) ? (int) $GLOBALS['user']['user_id'] : 0;
        $storedHash = '';
        if ($userId > 0) {
            $stored = $this->where(['user_id' => $userId])->value('user_pwd');
            $storedHash = $stored === null ? '' : (string) $stored;
        }
        if ($userId < 1 || !mac_verify_password($password_raw, $storedHash)) {
            return ['code' => 1002, 'msg' => lang('model/user/old_pass_err')];
        }
        if ($param['user_pwd1'] != $param['user_pwd2']) {
            return ['code' => 1003, 'msg' => lang('model/user/pass_not_same_pass2')];
        }

        $data = [];
        $data['user_id'] = $GLOBALS['user']['user_id'];
        $data['user_name'] = $GLOBALS['user']['user_name'];
        if(!empty($param['user_nick_name'])){
            $data['user_nick_name'] = htmlspecialchars(urldecode(trim($param['user_nick_name'])));
        }
        $data['user_qq'] = htmlspecialchars(urldecode(trim($param['user_qq'])));
        $data['user_question'] = htmlspecialchars(urldecode(trim($param['user_question'])));
        $data['user_answer'] = htmlspecialchars(urldecode(trim($param['user_answer'])));
        if (!empty($param['user_pwd2'])) {
            $data['user_pwd'] = trim($param['user_pwd2']);
        }
        return $this->saveData($data);
    }

    public function login($param)
    {
        if (!is_array($param)) {
            return ['code' => 1001, 'msg' => lang('model/user/input_require')];
        }
        foreach (['user_name', 'user_pwd', 'verify'] as $field) {
            if (isset($param[$field]) && !is_string($param[$field]) && !is_int($param[$field])) {
                return ['code' => 1001, 'msg' => lang('model/user/input_require')];
            }
            $param[$field] = isset($param[$field]) ? (string) $param[$field] : '';
        }
        $data = [];
        $password_raw = trim($param['user_pwd'] ?? '');
        $data['user_name'] = htmlspecialchars(urldecode(trim($param['user_name'] ?? '')));
        $data['user_pwd'] = htmlspecialchars(urldecode($password_raw));
        $data['verify'] = $param['verify'] ?? '';
        if (empty($data['user_name']) || empty($data['user_pwd'])) {
            return ['code' => 1001, 'msg' => lang('model/user/input_require')];
        }
        if ($GLOBALS['config']['user']['login_verify'] == 1 && !captcha_check($data['verify'])) {
            return ['code' => 1002, 'msg' => lang('verify_err')];
        }
        $where = [];
        $pattern = '/\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*/';
        if (!preg_match($pattern, $data['user_name'])) {
            $where['user_name'] = ['eq', $data['user_name']];
        } else {
            $where['user_email'] = ['eq', $data['user_name']];
        }
        // Fetch by account identity, then verify the password in PHP.
        // Never authenticate by putting a submitted password/hash in SQL.
        $where['user_status'] = ['eq', 1];
        $row = $this->where($where)->find();
        // Distributed password guessing against one account (by name or by e-mail, any IP): after LOGIN_FAIL_LIMIT
        // misses the account refuses sign-ins for the rest of the window.
        $lockKey = !empty($row) ? 'uid:' . (int)$row['user_id'] : 'name:' . $data['user_name'];
        if (self::loginLocked($lockKey)) {
            return ['code' => 1003, 'msg' => lang('model/user/not_found')];
        }
        if (empty($row) || !mac_verify_password($password_raw, $row['user_pwd'])) {
            self::loginFailed($lockKey);
            return ['code' => 1003, 'msg' => lang('model/user/not_found')];
        }
        \think\Cache::rm(self::loginFailKey($lockKey));
        // completeLogin() updates metadata using this stable identity.
        return $this->completeLogin(['user_id' => (int) $row['user_id']]);
    }

    // Internal callback entry: never dispatch this from ordinary form parameters.
    public function loginFromOauth($provider, $openid)
    {
        $column = $this->oauthColumn($provider, $openid);
        if ($column === false) {
            return ['code' => 1002, 'msg' => lang('param_err')];
        }
        return $this->completeLogin([$column => $openid]);
    }

    private function completeLogin($where)
    {
        $where['user_status'] = ['eq', 1];
        $row = $this->where($where)->find();

        if(empty($row)) {
            return ['code' => 1003, 'msg' => lang('model/user/not_found')];
        }

        if($row['group_id'] > 2 &&  $row['user_end_time'] < time()) {
            $row['group_id'] = 2;
            $update['group_id'] = 2;
        }

        // Login secret behind the user_check cookie: 128 random bits (was md5 of rand(): about 2^26 guessable values).
        $random = bin2hex(random_bytes(16));
        $update['user_random'] = $random;
        $update['user_login_ip'] = mac_get_ip_long();
        $update['user_login_time'] = time();
        $update['user_login_num'] = $row['user_login_num'] + 1;
        $update['user_last_login_time'] = $row['user_login_time'];
        $update['user_last_login_ip'] = $row['user_login_ip'];

        $res = $this->where($where)->update($update);
        if ($res === false) {
            return ['code' => 1004, 'msg' => lang('model/user/update_login_err')];
        }

        // Load the account group details.
        $group_list = model('Group')->getCache('group_list');
        $group = $group_list[$row['group_id']];

        cookie('user_id', $row['user_id'],['expire'=>2592000] );
        cookie('user_name', $row['user_name'],['expire'=>2592000] );
        cookie('group_id', $group['group_id'],['expire'=>2592000] );
        cookie('group_name', $group['group_name'],['expire'=>2592000] );
        self::setLoginCheckCookie(md5($random . '-' .$row['user_name'] . '-' . $row['user_id'] .'-' ), 2592000);
        cookie('user_portrait', mac_get_user_portrait($row['user_id']),['expire'=>2592000] );

        return ['code' => 1, 'msg' => lang('model/user/login_ok')];
    }

    /**
     * The user_check cookie is the member's login secret: HttpOnly (no theme script reads it; they read user_id /
     * user_name / group_* / user_portrait, which stay readable), SameSite=Lax, Secure on HTTPS. Same name, path and
     * domain as cookie() so logout (cookie('user_check', null)) still removes it.
     */
    private static function setLoginCheckCookie($value, $lifetime)
    {
        $config = (array)config('cookie');
        $name = (string)($config['prefix'] ?? '') . 'user_check';
        $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443');
        $options = [
            'expires' => time() + (int)$lifetime,
            'path' => (string)($config['path'] ?? '/') ?: '/',
            'domain' => (string)($config['domain'] ?? ''),
            'secure' => $https || !empty($config['secure']),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if (!headers_sent()) {
            setcookie($name, (string)$value, $options);
        }
        $_COOKIE[$name] = (string)$value;
    }

    /** Failed sign-ins per account (any IP): after LOGIN_FAIL_LIMIT failures the account refuses sign-ins for the rest of the window. */
    const LOGIN_FAIL_LIMIT = 10;
    const LOGIN_FAIL_WINDOW = 900;

    private static function loginFailKey($account)
    {
        return 'psec_login_fail_' . md5(strtolower(trim((string)$account)));
    }

    private static function loginLocked($account)
    {
        $state = \think\Cache::get(self::loginFailKey($account));
        return is_array($state) && (int)($state['n'] ?? 0) >= self::LOGIN_FAIL_LIMIT && (int)($state['t'] ?? 0) + self::LOGIN_FAIL_WINDOW > time();
    }

    private static function loginFailed($account)
    {
        $key = self::loginFailKey($account);
        $state = \think\Cache::get($key);
        $now = time();
        if (!is_array($state) || (int)($state['t'] ?? 0) + self::LOGIN_FAIL_WINDOW <= $now) {
            $state = ['n' => 0, 't' => $now];
        }
        $state['n'] = (int)$state['n'] + 1;
        \think\Cache::set($key, $state, max(1, (int)$state['t'] + self::LOGIN_FAIL_WINDOW - $now));
    }

    /** Wrong one-time codes per recipient: after MSG_FAIL_LIMIT misses every pending code for it is spent (new code needed). */
    const MSG_FAIL_LIMIT = 5;

    private static function messageFailKey($param)
    {
        return 'psec_msg_fail_' . md5((string)($param['type'] ?? '') . '|' . strtolower(trim((string)($param['to'] ?? ''))));
    }

    private function messageLocked($param)
    {
        return (int)\think\Cache::get(self::messageFailKey($param)) >= self::MSG_FAIL_LIMIT;
    }

    private function messageFailed($param)
    {
        $key = self::messageFailKey($param);
        $count = (int)\think\Cache::get($key) + 1;
        $minutes = ($param['ac'] ?? '') === 'email' ? (int)($GLOBALS['config']['email']['time'] ?? 0) : 0;
        \think\Cache::set($key, $count, 60 * ($minutes > 0 ? $minutes : 5) + 60);
        if ($count >= self::MSG_FAIL_LIMIT) {
            try {
                model('msg')->where(['msg_to' => ['eq', (string)$param['to']], 'msg_type' => ['eq', (int)$param['type']], 'msg_status' => ['eq', 0]])
                    ->update(['msg_status' => 1]);
            } catch (\Throwable $error) {
                // the counter alone keeps refusing codes until it expires
            }
        }
    }

    /** A new code was sent: the miss counter starts again for that recipient. */
    private function messageReset($param)
    {
        \think\Cache::rm(self::messageFailKey($param));
    }

    public function expire()
    {
        $where=[];
        $where['group_id'] = ['gt',2];
        $where['user_end_time'] = ['elt',time()];

        $update=[];
        $update['group_id'] = 2;

        $res = $this->where($where)->update($update);
        if ($res === false) {
            return ['code' => 101, 'msg' => lang('model/user/update_expire_err')];
        }
        return ['code' => 1, 'msg' => lang('model/user/update_expire_ok')];
    }

    public function logout()
    {
        cookie('user_id', null);
        cookie('user_name', null);
        cookie('group_id', null);
        cookie('group_name', null);
        cookie('user_check', null);
        cookie('user_portrait', null);
        return ['code' => 1, 'msg' =>lang('model/user/logout_ok')];
    }

    public function checkLogin()
    {
        $user_id = cookie('user_id');
        $user_name = cookie('user_name');
        $user_check = cookie('user_check');

        $user_id = htmlspecialchars(urldecode(trim($user_id)));
        $user_name = htmlspecialchars(urldecode(trim($user_name)));
        $user_check = htmlspecialchars(urldecode(trim($user_check)));

        if (empty($user_id) || empty($user_name) || empty($user_check)) {
            return ['code' => 1001, 'msg' => lang('model/user/not_login')];
        }

        $where = [];
        $where['user_id'] = $user_id;
        $where['user_name'] = $user_name;
        $where['user_status'] = 1;

        $info = $this->field('*')->where($where)->find();
        if(empty($info)) {
            return ['code' => 1002, 'msg' => lang('model/user/not_login')];
        }
        $info = $info->toArray();
        $login_check = md5($info['user_random'] . '-' . $info['user_name']. '-' . $info['user_id'] .'-' );
        if (!is_string($user_check) || !hash_equals((string)$login_check, $user_check)) {
            return ['code' => 1003, 'msg' => lang('model/user/not_login')];
        }

        $group_list = model('Group')->getCache('group_list');
        $info['group'] = $group_list[$info['group_id']];

        // Move expired memberships back to group 2.
        if ($info['group_id'] > 2 && $info['user_end_time'] < time()) {
            // Use the group 2 details.
            $info['group'] = $group_list[2];

            $update = [];
            $update['group_id'] = 2;

            $res = $this->where($where)->update($update);
            if($res === false){
                return ['code' => 1004, 'msg' => lang('model/user/update_expire_err')];
            }

            cookie('group_id', $info['group']['group_id'], ['expire'=>2592000] );
            cookie('group_name', $info['group']['group_name'],['expire'=>2592000] );
        }


        return ['code' => 1, 'msg' => lang('model/user/haved_login'), 'info' => $info];
    }

    public function resetPwd()
    {

    }

    public function findpass($param)
    {
        $data = [];
        $password_raw = trim($param['user_pwd']);
        $data['user_name'] = htmlspecialchars(urldecode(trim($param['user_name'])));
        $data['user_question'] = htmlspecialchars(urldecode(trim($param['user_question'])));
        $data['user_answer'] = htmlspecialchars(urldecode(trim($param['user_answer'])));
        $data['user_pwd'] = htmlspecialchars(urldecode(trim($param['user_pwd'])));
        $data['user_pwd2'] = htmlspecialchars(urldecode(trim($param['user_pwd2'])));
        $data['verify'] = $param['verify'];

        if (empty($data['user_name']) || empty($data['user_question']) || empty($data['user_answer']) || empty($data['user_pwd']) || empty($data['user_pwd2']) || empty($data['verify'])) {
            return ['code' => 1001, 'msg' => lang('param_err')];
        }

        if (!captcha_check($data['verify'])) {
            return ['code' => 1002, 'msg' => lang('verify_err')];
        }

        if ($data['user_pwd'] != $data['user_pwd2']) {
            return ['code' => 1003, 'msg' => lang('model/user/pass_not_same_pass2')];
        }


        $where = [];
        $where['user_name'] = $data['user_name'];
        $where['user_question'] = $data['user_question'];
        $where['user_answer'] = $data['user_answer'];

        $info = $this->where($where)->find();
        if (empty($info)) {
            return ['code' => 1004, 'msg' => lang('model/user/findpass_not_found')];
        }

        $update = [];
        $update['user_pwd'] = mac_hash_password_for_column($password_raw, 'user', 'user_pwd');

        $where = [];
        $where['user_id'] = $info['user_id'];
        $res = $this->where($where)->update($update);

        if (false === $res) {
            return ['code' => 1005, 'msg' => '' . $this->getError()];
        }
        return ['code' => 1, 'msg' => lang('model/user/findpass_ok')];

    }

    public function popedom($type_id, $popedom, $group_id = 1)
    {
        $group_list = model('Group')->getCache();
        $group_info = $group_list[$group_id];

        if (strpos(',' . $group_info['group_type'], ',' . $type_id . ',') !== false && !empty($group_info['group_popedom'][$type_id][$popedom]) !== false) {
            return true;
        }
        return false;
    }

    public function upgrade($param)
    {
        $durations=['day'=>86400,'week'=>604800,'month'=>2592000,'year'=>31536000];
        if (!is_array($param) || !isset($param['long']) || !is_string($param['long']) || !isset($durations[$param['long']])) {
            return ['code'=>1001,'msg'=>'Invalid membership duration.'];
        }
        if (!isset($param['group_id']) || (!is_string($param['group_id']) && !is_int($param['group_id'])) || !preg_match('/^[0-9]+$/D',(string)$param['group_id']) || (float)$param['group_id']>65535 || (int)$param['group_id']<3) {
            return ['code'=>1002,'msg'=>lang('model/user/select_diy_group_err')];
        }
        $groupId=(int)$param['group_id']; $duration=$param['long'];
        $userId=(int)($GLOBALS['user']['user_id']??0);
        $connection=null; $started=false; $failure=1009;
        try {
            if ($userId<1) throw new \RuntimeException('No authenticated account');
            $connection=$this->getQuery()->getConnection();
            $users=new \think\db\Query($connection); $users->name('user');
            $groups=new \think\db\Query($connection); $groups->name('group');
            $logs=new \think\db\Query($connection); $logs->name('plog');
            $engines=$connection->query('SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?)',[$users->getTable(),$groups->getTable(),$logs->getTable()]);
            if (count($engines)!==3) throw new \RuntimeException('Nontransactional upgrade storage');
            foreach($engines as $engine) if(strtolower((string)$engine['engine'])!=='innodb') throw new \RuntimeException('Nontransactional upgrade storage');
            $connection->startTrans(); $started=true;
            $group=$groups->where(['group_id'=>$groupId])->lock(true)->find();
            if (!$group) {$failure=1003;throw new \RuntimeException('Group unavailable');}
            if ((int)$group['group_status']!==1) {$failure=1004;throw new \RuntimeException('Group disabled');}
            $account=$users->where(['user_id'=>$userId,'user_status'=>1])->lock(true)->find();
            if (!$account) throw new \RuntimeException('Account unavailable');
            $cost=(string)$group['group_points_'.$duration];
            if(!preg_match('/^[0-9]+$/D',$cost) || (float)$cost>65535) throw new \RuntimeException('Invalid membership cost');
            $points=(int)$cost;
            if((int)$account['user_points']<$points) {$failure=1005;throw new \RuntimeException('Insufficient points');}
            $end=max(time(),(int)$account['user_end_time'])+$durations[$duration];
            if($end>4294967295) throw new \RuntimeException('Membership date overflow');
            $rewardConfig=$GLOBALS['config']['user']??[]; $rewards=[];
            if($points>0 && ($rewardConfig['reward_status']??0)==1) {
                foreach([['user_pid','reward_ratio',4],['user_pid_2','reward_ratio_2',5],['user_pid_3','reward_ratio_3',6]] as $tier) {
                    $recipient=(int)($account[$tier[0]]??0);$ratio=$rewardConfig[$tier[1]]??0;
                    if($recipient<1 || empty($ratio)) continue;
                    if(!is_scalar($ratio) || !is_numeric($ratio) || !is_finite((float)$ratio) || (float)$ratio<0) throw new \RuntimeException('Invalid referral ratio');
                    $bonusValue=floor($points/100*(float)$ratio);
                    if(!is_finite($bonusValue) || $bonusValue>4294967295) throw new \RuntimeException('Referral amount overflow');
                    $bonus=(int)$bonusValue;
                    if($bonus>0) $rewards[]=['user_id'=>$recipient,'points'=>$bonus,'type'=>$tier[2]];
                }
            }
            $recipientIds=array_unique(array_column($rewards,'user_id'));sort($recipientIds,SORT_NUMERIC);
            $recipients=[];
            foreach($recipientIds as $recipient) {
                $row=$users->where(['user_id'=>$recipient])->lock(true)->find();
                // Legacy reward behavior skips removed referral accounts.
                if($row) $recipients[$recipient]=true;
            }
            if($users->where(['user_id'=>$userId,'user_status'=>1])->update(['user_points'=>(int)$account['user_points']-$points,'user_end_time'=>$end,'group_id'=>$groupId])!==1) throw new \RuntimeException('Membership update failed');
            if($logs->insert(['user_id'=>$userId,'plog_type'=>7,'plog_points'=>$points,'plog_time'=>time()])!==1) throw new \RuntimeException('Spend log failed');
            foreach($rewards as $reward) {
                if(!isset($recipients[$reward['user_id']])) continue;
                $balance=$users->where(['user_id'=>$reward['user_id']])->value('user_points');
                if((float)$balance+$reward['points']>4294967295 || $users->where(['user_id'=>$reward['user_id']])->setInc('user_points',$reward['points'])!==1) throw new \RuntimeException('Referral credit failed');
                $remarks=lang('model/user/reward_tip',[$userId,$account['user_name'],$points,$reward['points']]);
                if($logs->insert(['user_id'=>$reward['user_id'],'plog_type'=>$reward['type'],'plog_points'=>$reward['points'],'plog_time'=>time(),'plog_remarks'=>$remarks])!==1) throw new \RuntimeException('Referral log failed');
            }
            $connection->commit();$started=false;
        } catch(\Throwable $error) {
            if($started && $connection) {try{$connection->rollback();}catch(\Throwable $ignored){}}
            $keys=[1003=>'model/user/group_not_found',1004=>'model/user/group_is_close',1005=>'model/user/potins_not_enough',1009=>'model/user/update_group_err'];
            return ['code'=>$failure,'msg'=>lang($keys[$failure])];
        }
        // Browser membership hints are published only after all DB writes commit.
        cookie('group_id',$group['group_id'],['expire'=>2592000]);
        cookie('group_name',$group['group_name'],['expire'=>2592000]);
        return ['code'=>1,'msg'=>lang('model/user/update_group_ok')];
    }

    private function messageParameters($param, $requireCode = true, $legacyReset = false)
    {
        if (!is_array($param) || !isset($param['ac'], $param['to'], $param['type'])
            || !in_array($param['ac'], ['email', 'phone'], true)
            || !is_string($param['to'])
            || !in_array($param['type'], [1, 2, 3, '1', '2', '3'], true)) {
            return ['code' => 9001, 'msg' => lang('param_err')];
        }
        // Request values are already URL-decoded. Preserve literal plus signs.
        $param['to'] = trim($param['to']);
        $param['type'] = (int) $param['type'];
        if ($legacyReset) {
            if (empty($param['to'])) {
                return ['code' => 9001, 'msg' => lang('param_err')];
            }
            // Reset historically checked the message before recipient format. Keep
            // that order and its 2005/2007/9002 response contract while still
            // binding the message lookup to this exact recipient.
            if ($requireCode) {
                if (!isset($param['code']) || (!is_string($param['code']) && !is_int($param['code']))) {
                    return ['code' => 9001, 'msg' => lang('param_err')];
                }
                $param['code'] = htmlspecialchars(urldecode(trim((string) $param['code'])));
                if (empty($param['code'])) {
                    return ['code' => 9001, 'msg' => lang('param_err')];
                }
            }
            return ['code' => 1, 'param' => $param];
        }
        if ($param['ac'] === 'email') {
            // Limit email recipients to 30 bytes.
            if (strlen($param['to']) > 30 || !filter_var($param['to'], FILTER_VALIDATE_EMAIL)) {
                return ['code' => 9001, 'msg' => lang('param_err')];
            }
            if (in_array($param['type'], [1, 3], true)) {
                $result = UserValidate::validateEmail($param['to']);
                if ($result['code'] > 1) {
                    return $result;
                }
            }
        } elseif (!preg_match('/^1[0-9]{10}$/D', $param['to'])) {
            return ['code' => 9001, 'msg' => lang('param_err')];
        }
        if ($param['type'] === 1 && (int) ($GLOBALS['user']['user_id'] ?? 0) < 1) {
            return ['code' => 9001, 'msg' => lang('param_err')];
        }
        if ($requireCode) {
            if (!isset($param['code']) || (!is_string($param['code']) && !is_int($param['code']))) {
                return ['code' => 9001, 'msg' => lang('param_err')];
            }
            $param['code'] = trim((string) $param['code']);
            if (!preg_match('/^[0-9]{6}$/D', $param['code'])) {
                return ['code' => 9001, 'msg' => lang('param_err')];
            }
        }
        return ['code' => 1, 'param' => $param];
    }

    private function messageWhere($param)
    {
        $minutes = $param['ac'] === 'email' ? (int) ($GLOBALS['config']['email']['time'] ?? 0) : 0;
        $stime = time() - 60 * ($minutes > 0 ? $minutes : 5);
        return [
            'user_id' => (int) ($GLOBALS['user']['user_id'] ?? 0),
            'msg_time' => ['gt', $stime],
            'msg_code' => ['eq', $param['code']],
            'msg_type' => ['eq', $param['type']],
            'msg_to' => ['eq', $param['to']],
            'msg_status' => ['eq', 0],
        ];
    }

    public function check_msg($param)
    {
        $result = $this->messageParameters($param);
        if ($result['code'] > 1) {
            return $result;
        }
        $param = $result['param'];
        if ($this->messageLocked($param)) {
            return ['code' => 9002, 'msg' => lang('model/user/msg_not_found')];
        }
        $result = model('msg')->infoData($this->messageWhere($param));
        if ($result['code'] !== 1) {
            $this->messageFailed($param);
            return ['code' => 9002, 'msg' => lang('model/user/msg_not_found')];
        }
        return ['code' => 1, 'msg' => 'ok'];
    }

    private function completeMessageAction($param, callable $action, $failure, $legacyReset = false)
    {
        $result = $this->messageParameters($param, true, $legacyReset);
        if ($result['code'] > 1) {
            return $result;
        }
        $param = $result['param'];
        // One-time codes are 6 digits: cap wrong guesses per recipient so they cannot be brute-forced.
        if ($this->messageLocked($param)) {
            return ['code' => 9002, 'msg' => lang('model/user/msg_not_found')];
        }
        $started = false;
        try {
            // Run message consumption and the account action in one transaction.
            Db::startTrans();
            $started = true;
            $where = $this->messageWhere($param);
            $message = model('msg')->infoData($where);
            if ($message['code'] !== 1) {
                Db::rollback();
                $started = false;
                $this->messageFailed($param);
                return ['code' => 9002, 'msg' => lang('model/user/msg_not_found')];
            }
            $where['msg_id'] = (int) $message['info']['msg_id'];
            // The conditional write permits only one successful consumer.
            $claimed = model('msg')->where($where)->update(['msg_status' => 1]);
            if ($claimed !== 1) {
                Db::rollback();
                return ['code' => 9002, 'msg' => lang('model/user/msg_not_found')];
            }
            $result = $action($param);
            if ($result['code'] !== 1) {
                Db::rollback();
                return $result;
            }
            Db::commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($started) {
                Db::rollback();
            }
            return $failure;
        }
    }

    public function send_msg($param)
    {
        $result = $this->messageParameters($param, false);
        if ($result['code'] > 1) {
            return $result;
        }
        $param = $result['param'];
        if (in_array($param['type'], [2, 3], true)) {
            if (!isset($param['verify'])
                || (!is_string($param['verify']) && !is_int($param['verify']))) {
                return ['code' => 1002, 'msg' => lang('verify_err')];
            }
            $verify = trim((string) $param['verify']);
            if ($verify === '' || !captcha_check($verify)) {
                return ['code' => 1002, 'msg' => lang('verify_err')];
            }
        }

        $type_arr = [
            1=>['des'=>lang('bind'),'flag'=>'bind'],
            2=>['des'=>lang('findpass'),'flag'=>'findpass'],
            3=>['des'=>lang('register'),'flag'=>'reg'],
        ];
        $type_des = $type_arr[$param['type']]['des'];
        $type_flag = $type_arr[$param['type']]['flag'];


        $to = $param['to'];
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $r=0;

        $stime = strtotime('-5 min');
        if($param['ac']=='email' && intval($GLOBALS['config']['email']['time'])>0){
            $stime = strtotime('-'.$GLOBALS['config']['email']['time'].' min');
        }
        $where=[];
        $where['user_id'] = intval($GLOBALS['user']['user_id']);
        $where['msg_time'] = ['gt',$stime];
        $where['msg_type'] = ['eq', $param['type'] ];
        $where['msg_to'] = ['eq', $param['to'] ];
        $res = model('msg')->infoData($where);
        if($res['code'] ==1){
            return ['code'=>9002,'msg'=>lang('model/user/do_not_send_frequently')];
        }
        $res_msg= ', '.lang('please_try_again');
        if($param['ac']=='email'){
            $title = $GLOBALS['config']['email']['tpl']['user_'.$type_flag.'_title'];
            $msg = $GLOBALS['config']['email']['tpl']['user_'.$type_flag.'_body'];
            View::instance()->assign(['code'=>$code,'time'=>$GLOBALS['config']['email']['time']]);
            $title =  View::instance()->display($title);
            $msg =  View::instance()->display($msg);
            $msg = htmlspecialchars_decode($msg);
            $res_send = mac_send_mail($to, $title, $msg);
            $res_code = $res_send['code'];
            $res_msg = $res_send['msg'];
        }
        else{
            $msg = $GLOBALS['config']['sms']['content'];
            $msg = str_replace(['[User]', '[Type]', '[Duration]', '[Code]'], [$GLOBALS['user']['user_name'], $type_des, '5', $code], $msg);
            $res_send = mac_send_sms($to,$code,$type_flag,$type_des,$msg);
            $res_code = $res_send['code'];
            $res_msg = $res_send['msg'];
        }
        
        if($res_code==1){
            $data=[];
            $data['user_id'] = intval($GLOBALS['user']['user_id']);
            $data['msg_type'] = $param['type'];
            $data['msg_status'] = 0;
            $data['msg_to'] = $to;
            $data['msg_code'] = $code;
            $data['msg_content'] = $msg;
            $data['msg_time'] = time();
            try {
                $res = model('msg')->saveData($data);
            } catch (\Throwable $exception) {
                return ['code' => 9009, 'msg' => lang('model/user/msg_send_err')];
            }
            if ($res['code'] !== 1) {
                return ['code' => 9009, 'msg' => lang('model/user/msg_send_err')];
            }
            $this->messageReset($param);

            return ['code'=>1,'msg'=>lang('model/user/msg_send_ok')];
        }
        else{
            return ['code'=>9009,'msg'=>lang('model/user/msg_send_err').': '.$res_msg];
        }
    }

    public function bind($param)
    {
        $param['type'] = 1;
        $failure = ['code' => 2003, 'msg' => lang('model/user/update_bind_err')];
        return $this->completeMessageAction($param, function ($message) use ($failure) {
            $userId = (int) $GLOBALS['user']['user_id'];
            if (!$this->where(['user_id' => $userId])->lock(true)->find()) {
                return $failure;
            }
            $column = $message['ac'] === 'email' ? 'user_email' : 'user_phone';
            $previous = [$column => $message['to'], 'user_id' => ['neq', $userId]];
            if ($this->where($previous)->update([$column => '']) === false
                || $this->where(['user_id' => $userId])->update([$column => $message['to']]) === false) {
                return $failure;
            }
            return ['code' => 1, 'msg' => lang('model/user/update_bind_ok')];
        }, $failure);
    }

    private function unbindPasswordMatches($passwordRaw, $storedPassword)
    {
        $passwordRaw = (string) $passwordRaw;
        $storedPassword = (string) $storedPassword;
        if ($storedPassword === '') {
            return false;
        }
        if (strncmp($storedPassword, '$2', 2) === 0) {
            return password_verify($passwordRaw, $storedPassword);
        }
        $formatted = htmlspecialchars(urldecode(trim($passwordRaw)));
        if (hash_equals($storedPassword, md5($passwordRaw))
            || hash_equals($storedPassword, md5($formatted))) {
            return true;
        }
        if (preg_match('/^[0-9a-f]{32}$/iD', $storedPassword)) {
            return false;
        }
        return hash_equals($storedPassword, $passwordRaw)
            || hash_equals($storedPassword, $formatted);
    }

    public function unbind($param)
    {
        if (!is_array($param)
            || !isset($param['ac'])
            || !is_string($param['ac'])
            || !in_array($param['ac'], ['email', 'phone'], true)) {
            return ['code'=>2001,'msg'=>lang('param_err')];
        }
        if (!array_key_exists('user_pwd', $param)) {
            return ['code'=>1001,'msg'=>lang('model/user/input_old_pass')];
        }
        if (!is_string($param['user_pwd']) && !is_int($param['user_pwd'])) {
            return ['code'=>2001,'msg'=>lang('param_err')];
        }
        $passwordRaw = trim((string) $param['user_pwd']);
        if ($passwordRaw === '') {
            return ['code'=>1001,'msg'=>lang('model/user/input_old_pass')];
        }

        $currentUser = isset($GLOBALS['user']) && is_array($GLOBALS['user']) ? $GLOBALS['user'] : [];
        $userIdValue = array_key_exists('user_id', $currentUser) ? $currentUser['user_id'] : null;
        if (is_int($userIdValue)) {
            $userId = $userIdValue;
        } elseif (is_string($userIdValue) && ctype_digit($userIdValue)) {
            $userId = (int) $userIdValue;
            $normalized = ltrim($userIdValue, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            if ((string) $userId !== $normalized) {
                return ['code'=>1002,'msg'=>lang('model/user/not_login')];
            }
        } else {
            return ['code'=>1002,'msg'=>lang('model/user/not_login')];
        }
        if ($userId < 1) {
            return ['code'=>1002,'msg'=>lang('model/user/not_login')];
        }

        $col = $param['ac'] === 'phone' ? 'user_phone' : 'user_email';
        $row = $this->where(['user_id' => $userId])->field('user_pwd,' . $col)->find();
        if (empty($row) || !isset($row['user_pwd'])) {
            return ['code'=>1002,'msg'=>lang('model/user/old_pass_err')];
        }
        $storedPassword = (string) $row['user_pwd'];
        if (!$this->unbindPasswordMatches($passwordRaw, $storedPassword)) {
            return ['code'=>1002,'msg'=>lang('model/user/old_pass_err')];
        }
        if ((string) $row[$col] === '') {
            return ['code'=>1,'msg'=>lang('model/user/update_unbind_ok')];
        }

        $where = ['user_id' => $userId, 'user_pwd' => $storedPassword];
        $res = $this->where($where)->update([$col => '']);
        if ($res === false || (int) $res < 1) {
            return ['code'=>2002,'msg'=>lang('model/user/update_bind_err')];
        }
        return ['code'=>1,'msg'=>lang('model/user/update_unbind_ok')];
    }

    public function bindmsg($param)
    {
        $param['type'] = 1;
        return $this->send_msg($param);
    }

    public function findpass_msg($param)
    {
        $param['type'] = 2;
        return $this->send_msg($param);
    }

    public function reg_msg($param)
    {
        $phoneEnabled = (string) ($GLOBALS['config']['user']['reg_phone_sms'] ?? '0') === '1';
        $emailEnabled = !$phoneEnabled
            && (string) ($GLOBALS['config']['user']['reg_email_sms'] ?? '0') === '1';
        if (!is_array($param) || !isset($param['ac'])
            || ($param['ac'] === 'phone' && !$phoneEnabled)
            || ($param['ac'] === 'email' && !$emailEnabled)) {
            return ['code' => 9001, 'msg' => lang('param_err')];
        }
        $param['type'] = 3;
        return $this->send_msg($param);
    }


    public function findpass_reset($param)
    {
        if (!is_array($param)) {
            return ['code' => 9001, 'msg' => lang('param_err')];
        }
        foreach (['to', 'user_email', 'user_pwd', 'user_pwd2'] as $field) {
            if (isset($param[$field]) && !is_string($param[$field])) {
                return ['code' => 9001, 'msg' => lang('param_err')];
            }
        }
        $to = trim($param['to'] ?? '');
        $legacyEmail = trim($param['user_email'] ?? '');
        // Keep the legacy alias, but never verify one recipient and reset another.
        if ($legacyEmail !== '' && $to !== '' && $legacyEmail !== $to) {
            return ['code' => 9001, 'msg' => lang('param_err')];
        }
        $param['to'] = $to !== '' ? $to : $legacyEmail;
        $password_raw = trim($param['user_pwd'] ?? '');
        $password = htmlspecialchars(urldecode($password_raw));
        $confirmation = htmlspecialchars(urldecode(trim($param['user_pwd2'] ?? '')));
        if (strlen($password) < 6) {
            return ['code' => 2002, 'msg' => lang('model/user/pass_length_err')];
        }
        if ($password !== $confirmation) {
            return ['code' => 2003, 'msg' => lang('model/user/pass_not_same_pass2')];
        }
        $param['type'] = 2;
        $failure = ['code' => 2009, 'msg' => lang('model/user/pass_reset_err')];
        return $this->completeMessageAction($param, function ($message) use ($password_raw, $failure) {
            $email = $message['ac'] === 'email';
            if ($email) {
                $pattern = '/\w+([-+.]\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*/';
                if (!preg_match($pattern, $message['to'])) {
                    return ['code' => 2005, 'msg' => lang('model/user/email_format_err')];
                }
            } else {
                $pattern = '/^1{1}\d{10}$/';
                if (!preg_match($pattern, $message['to'])) {
                    return ['code' => 2007, 'msg' => lang('model/user/phone_format_err')];
                }
            }
            $column = $email ? 'user_email' : 'user_phone';
            $where = [$column => $message['to']];
            $user = $this->where($where)->lock(true)->find();
            if (!$user) {
                return ['code' => $email ? 2006 : 2008, 'msg' => lang($email ? 'model/user/email_err' : 'model/user/phone_err')];
            }
            $where['user_id'] = (int) $user['user_id'];
            if ($this->where($where)->update(['user_pwd' => mac_hash_password_for_column($password_raw, 'user', 'user_pwd')]) === false) {
                return $failure;
            }
            return ['code' => 1, 'msg' => lang('model/user/pass_reset_ok')];
        }, $failure, true);
    }

    public function visit($param)
    {
        $param['uid'] = abs(intval($param['uid']));
        if ($param['uid'] == 0) {
            return ['code' => 101, 'msg' =>lang('model/user/id_err')];
        }

        $ip = mac_get_ip_long();
        $max_cc = $GLOBALS['config']['user']['invite_visit_num'];
        if(empty($max_cc)){
            $max_cc=1;
        }
        $todayunix = strtotime("today");
        $where = [];
        $where['user_id'] = $param['uid'];
        $where['visit_ip'] = $ip;
        $where['visit_time'] = ['gt', $todayunix];
        $cc = model('visit')->where($where)->count();
        if ($cc>= $max_cc){
            return ['code' => 102, 'msg' => lang('model/user/visit_tip')];
        }

        $data = [];
        $data['user_id'] = $param['uid'];
        $data['visit_ip'] = $ip;
        $data['visit_time'] = time();
        $data['visit_ly'] = htmlspecialchars(mac_get_refer());
        $res = model('visit')->saveData($data);

        if ($res['code'] > 1) {
            return ['code' => 103, 'msg' => lang('model/user/visit_err')];
        }

        $res = $this->where('user_id', $param['uid'])->setInc('user_points', intval($GLOBALS['config']['user']['invite_visit_points']));
        if($res) {
            // Record the referral visit reward.
            $data = [];
            $data['user_id'] = $param['uid'];
            $data['plog_type'] = 3;
            $data['plog_points'] = intval($GLOBALS['config']['user']['invite_visit_points']);
            model('Plog')->saveData($data);
        }

        return ['code'=>1,'msg'=>lang('model/user/visit_ok')];
    }

    public function reward($fee_points=0)
    {
        // Award points to up to three referral levels.
        if($fee_points>0 && $GLOBALS['config']['user']['reward_status'] == '1'){

            if(!empty($GLOBALS['config']['user']['reward_ratio']) && !empty($GLOBALS['user']['user_pid'])){
                $points = floor($fee_points / 100 * $GLOBALS['config']['user']['reward_ratio']);
                if($points>0){
                    $where=[];
                    $where['user_id'] = $GLOBALS['user']['user_pid'];
                    $r = model('User')->where($where)->setInc('user_points',$points);
                    if($r){
                        $data = [];
                        $data['user_id'] = $GLOBALS['user']['user_pid'];
                        $data['plog_type'] = 4;
                        $data['plog_points'] = $points;
                        $data['plog_remarks'] = lang('model/user/reward_tip',[$GLOBALS['user']['user_id'],$GLOBALS['user']['user_name'],$fee_points,$points]);
                        model('Plog')->saveData($data);
                    }
                }
            }
            if(!empty($GLOBALS['config']['user']['reward_ratio_2']) && !empty($GLOBALS['user']['user_pid_2'])){
                $points = floor($fee_points / 100 * $GLOBALS['config']['user']['reward_ratio_2']);
                if($points>0){
                    $where=[];
                    $where['user_id'] = $GLOBALS['user']['user_pid_2'];
                    $r = model('User')->where($where)->setInc('user_points',$points);
                    if($r){
                        $data = [];
                        $data['user_id'] = $GLOBALS['user']['user_pid_2'];
                        $data['plog_type'] = 5;
                        $data['plog_points'] = $points;
                        $data['plog_remarks'] =lang('model/user/reward_tip',[$GLOBALS['user']['user_id'],$GLOBALS['user']['user_name'],$fee_points,$points]);
                        model('Plog')->saveData($data);
                    }
                }
            }
            if(!empty($GLOBALS['config']['user']['reward_ratio_3']) && !empty($GLOBALS['user']['user_pid_3'])){
                $points = floor($fee_points / 100 * $GLOBALS['config']['user']['reward_ratio_3']);
                if($points>0){
                    $where=[];
                    $where['user_id'] = $GLOBALS['user']['user_pid_3'];
                    $r = model('User')->where($where)->setInc('user_points',$points);
                    if($r){
                        $data = [];
                        $data['user_id'] = $GLOBALS['user']['user_pid_3'];
                        $data['plog_type'] = 6;
                        $data['plog_points'] = $points;
                        $data['plog_remarks'] = lang('model/user/reward_tip',[$GLOBALS['user']['user_id'],$GLOBALS['user']['user_name'],$fee_points,$points]);
                        model('Plog')->saveData($data);
                    }
                }
            }
        }

        return ['code'=>1,'msg'=>lang('model/user/reward_ok')];
    }

    /**
     * Check optional account columns without assuming the account migration ran.
     * Treat missing or unreadable metadata as an unavailable column.
     * Optional invite features can then remain disabled.
     */
    private function hasUserColumnCompat($column)
    {
        static $cache = [];
        $column = (string) $column;
        $database = (string) config('database.database');
        $prefix = (string) config('database.prefix');
        $key = $database . '|' . $prefix . '|' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $cache[$key] = false;
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $column)
            || !preg_match('/^[A-Za-z0-9_]*$/D', $prefix)
            || !preg_match('/^[A-Za-z0-9_]*$/D', $database)) {
            return false;
        }
        try {
            $table = $prefix . 'user';
            $rows = Db::query(
                "SHOW COLUMNS FROM `{$table}` WHERE Field = '" . addslashes($column) . "'"
            );
            $cache[$key] = !empty($rows);
        } catch (\Throwable $exception) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }

    /**
     * Generate and persist a five-character invite code only when the optional
     * column is available. Return existing codes without changing them.
     */
    private function ensureInviteCodeCompat($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0 || !$this->hasUserColumnCompat('user_invite_code')) {
            return '';
        }
        try {
            $row = $this->field('user_invite_code')->where('user_id', $userId)->find();
            if ($row && trim((string) $row['user_invite_code']) !== '') {
                return (string) $row['user_invite_code'];
            }
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $length = strlen($chars);
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $code = '';
                for ($i = 0; $i < 5; $i++) {
                    $code .= $chars[random_int(0, $length - 1)];
                }
                if ((int) $this->where('user_invite_code', $code)->count() === 0
                    && $this->where('user_id', $userId)->update(['user_invite_code' => $code]) !== false) {
                    return $code;
                }
            }
        } catch (\Throwable $exception) {
            return '';
        }
        return '';
    }

    /**
     * Resolve a caller-provided invite code without exposing a new public API.
     */
    private function resolveInviteIdCompat($inviteCode)
    {
        $inviteCode = trim((string) $inviteCode);
        if ($inviteCode === '' || strlen($inviteCode) > 20
            || !preg_match('/^[A-Za-z0-9_-]+$/D', $inviteCode)
            || !$this->hasUserColumnCompat('user_invite_code')) {
            return 0;
        }
        try {
            $row = $this->field('user_id')->where('user_invite_code', $inviteCode)->find();
            return $row ? (int) $row['user_id'] : 0;
        } catch (\Throwable $exception) {
            return 0;
        }
    }

}
