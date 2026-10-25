<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\model;
use think\Db;

class Admin extends Base {
    // Table name without the prefix.
    protected $name = 'admin';

    // Define timestamp field names
    protected $createTime = '';
    protected $updateTime = '';

    // Automatic field completion.
    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function getAdminStatusTextAttr($val,$data)
    {
        $arr = [0=>lang('disable'),1=>lang('enable')];
        return $arr[$data['admin_status']];
    }

    public function listData($where,$order,$page,$limit=20)
    {
        $total = $this->where($where)->count();
        $list = Db::name('Admin')->where($where)->order($order)->page($page)->limit($limit)->select();
        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$page,'pagecount'=>ceil($total/$limit),'limit'=>$limit,'total'=>$total,'list'=>$list];
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

        $info['admin_pwd'] = '';
        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    public function saveData($data)
    {
        if(!empty($data['admin_auth'])){
            $data['admin_auth'] = ','.join(',',$data['admin_auth']).',';
        }
        else{
            $data['admin_auth'] = '';
        }
        $validate = \think\Loader::validate('Admin');
        if(!empty($data['admin_id'])){
            if(!$validate->scene('edit')->check($data)){
                return ['code'=>1001,'msg'=>lang('param_err').': '.$validate->getError() ];
            }

            if(empty($data['admin_pwd'])){
                unset($data['admin_pwd']);
            }
            else{
                $data['admin_pwd'] = mac_hash_password_for_column($data['admin_pwd'], 'admin', 'admin_pwd');
            }
            $where=[];
            $where['admin_id'] = ['eq',$data['admin_id']];
            $res = $this->where($where)->update($data);
        }
        else{
            if(!$validate->scene('edit')->check($data)){
                return ['code'=>1002,'msg'=>lang('param_err').': '.$validate->getError() ];
            }

            $data['admin_pwd'] = mac_hash_password_for_column($data['admin_pwd'], 'admin', 'admin_pwd');
            $res = $this->insert($data);
        }
        if(false === $res){
            return ['code'=>1003,'msg'=>''.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    public function delData($where)
    {
        $res = $this->where($where)->delete();
        if($res===false){
            return ['code'=>1001,'msg'=>lang('del_err').': '.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('del_ok')];
    }

    public function fieldData($where,$col,$val)
    {
        if(!isset($col) || !isset($val)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $data = [];
        $data[$col] = $val;
        $res = $this->where($where)->update($data);
        if($res===false){
            return ['code'=>1002,'msg'=>lang('set_err').': '.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('set_ok')];
    }

    public function login($data)
    {
        if (!is_array($data)) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        foreach (['admin_name', 'admin_pwd', 'verify'] as $field) {
            if (isset($data[$field]) && !is_string($data[$field])) {
                return ['code'=>1001,'msg'=>lang('param_err')];
            }
        }
        if(empty($data['admin_name']) || empty($data['admin_pwd'])  ) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        if($GLOBALS['config']['app']['admin_login_verify'] !='0'){
            if(!captcha_check($data['verify'] ?? '')){
                return ['code'=>1002,'msg'=>lang('verify_err')];
            }
        }


        $where=[];
        $where['admin_name'] = ['eq',$data['admin_name']];
        $where['admin_status'] = ['eq',1];

        $row = $this->where($where)->find();

        if(empty($row)){
            return ['code'=>1003,'msg'=>lang('access_or_pass_err')];
        }
        // Preserve the administrator's exact legacy MD5(raw password) semantics.
        $stored = (string) $row['admin_pwd'];
        $legacy = preg_match('/^[a-f0-9]{32}$/iD', $stored) === 1;
        $bcrypt = preg_match('/^\$2[aby]\$/', $stored) === 1;
        $verified = $legacy
            ? hash_equals(strtolower($stored), md5($data['admin_pwd']))
            : ($bcrypt && strlen($data['admin_pwd']) <= 72
                && strpos($data['admin_pwd'], "\0") === false
                && mac_verify_password($data['admin_pwd'], $stored));
        if (!$verified) {
            return ['code'=>1003,'msg'=>lang('access_or_pass_err')];
        }
        $random = md5(rand(10000000,99999999));
        $update['admin_login_ip'] = mac_get_ip_long();
        $update['admin_login_time'] = time();
        $update['admin_login_num'] = $row['admin_login_num'] + 1;
        $update['admin_random'] = $random;
        $update['admin_last_login_time'] = $row['admin_login_time'];
        $update['admin_last_login_ip'] = $row['admin_login_ip'];

        if (mac_password_needs_rehash($stored) && mac_pwd_column_fits_hash('admin', 'admin_pwd')) {
            $update['admin_pwd'] = mac_hash_password_for_column($data['admin_pwd'], 'admin', 'admin_pwd');
        }
        $res = $this->where(['admin_id' => (int) $row['admin_id'], 'admin_status' => 1])
            ->whereRaw('BINARY `admin_pwd` = :admin_pwd_original', ['admin_pwd_original' => $stored])
            ->update($update);
        if ($res === 0) {
            return ['code'=>1003,'msg'=>lang('access_or_pass_err')];
        }
        if($res===false){
            return ['code'=>1004,'msg'=>lang('model/admin/update_login_err')];
        }

        // REVIEW-admin-security-r4: a fresh session id at sign-in (no session fixation). PHP sends the cookie again,
        // so the entry file's cookie settings (SameSite=Lax in javcms.php) apply even when the front end made the session.
        session('admin_auth', null); // ThinkPHP starts the session on first use
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        session('admin_auth','1');
        session('admin_info',array_merge($row->toArray(), $update));

        // cookie(\'admin_id\',$row[\'admin_id\']);
        // cookie(\'admin_name\',$row[\'admin_name\']);
        // cookie(\'admin_check\',md5($random .\'-\'. $row[\'admin_name\'] .\'-\'.$row[\'admin_id\'] .\'-\'.mac_get_client_ip() ) );

        return ['code'=>1,'msg'=>lang('model/admin/login_ok')];
    }

    /**
     * Check a raw password against the stored row of one admin, with the exact rules of login():
     * legacy md5(raw) or a bcrypt hash ($2a$/$2b$/$2y$) checked by mac_verify_password().
     * Used by the lock screen and by "My account" (ADMIN-IA-BRIEF 4.6). Never reads the session copy.
     */
    public function verifyPassword($adminId, $raw)
    {
        $adminId = (int) $adminId;
        if ($adminId < 1 || !is_string($raw) || $raw === '') {
            return false;
        }
        $stored = Db::name('Admin')->where('admin_id', $adminId)->value('admin_pwd');
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        $legacy = preg_match('/^[a-f0-9]{32}$/iD', $stored) === 1;
        $bcrypt = preg_match('/^\$2[aby]\$/', $stored) === 1;
        return $legacy
            ? hash_equals(strtolower($stored), md5($raw))
            : ($bcrypt && strlen($raw) <= 72
                && strpos($raw, "\0") === false
                && mac_verify_password($raw, $stored));
    }

    public function logout()
    {
        session('admin_auth',null);
        session('admin_info',null);
        // cookie(\'admin_id\',null);
        // cookie(\'admin_name\',null);
        // cookie(\'admin_check\',null);

        return ['code'=>1,'msg'=>lang('model/admin/logout_ok')];
    }

    public function checkLogin()
    {
        if(session('admin_auth')!=='1'){
            return ['code'=>1009,'msg'=>lang('model/admin/not_login')];
        }
        $info = session('admin_info');
        if(empty($info)){
            return ['code'=>1002,'msg'=>lang('model/admin/not_login')];
        }
        return ['code'=>1,'msg'=>lang('model/admin/haved_login'),'info'=>$info];
    }

    public function checkLogin2()
    {
        $admin_id = cookie('admin_id');
        $admin_name = cookie('admin_name');
        $admin_check = cookie('admin_check');

        if(empty($admin_id) || empty($admin_name) || empty($admin_check)){
            return ['code'=>1001, 'msg'=>lang('model/admin/not_login')];
        }

        $where = [];
        $where['admin_id'] = $admin_id;
        $where['admin_name'] = $admin_name;
        $where['admin_status'] =1 ;

        $info = $this->where($where)->find();
        if(empty($info)){
            return ['code'=>1002,'msg'=>lang('model/admin/not_login')];
        }
        $info = $info->toArray();

        $login_check = md5($info['admin_random'] .'-'. $info['admin_name'] .'-'.$info['admin_id'] .'-'.mac_get_client_ip() ) ;
        if($login_check != $admin_check){
            return ['code'=>1003,'msg'=>lang('model/admin/not_login')];
        }
        return ['code'=>1,'msg'=>lang('model/admin/haved_login'),'info'=>$info];
    }

}