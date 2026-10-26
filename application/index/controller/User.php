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
use think\Request;
use login\ThinkOauth;
use app\index\event\LoginEvent;

class User extends Base
{
    public function __construct()
    {
        parent::__construct();

        define('THIRD_LOGIN_CALLBACK',  $GLOBALS['http_type'] . $_SERVER['HTTP_HOST'] . '/index.php/user/logincallback/type/');

        // Require login for actions outside the public allowlist.
        $ac = request()->action();
        if (in_array($ac, ['login', 'logout', 'ajax_login', 'reg', 'findpass', 'findpass_msg', 'findpass_reset', 'reg_msg', 'oauth', 'logincallback','visit'])) {

        } else {
            if ($GLOBALS['user']['user_id'] < 1) {
                model('User')->logout();
                // Shown on the theme's jump page before the redirect: use the visitor's interface language.
                return $this->error(\app\common\util\TemplateText::raw('log_in_now'), url('user/login'));
            }
            /* $res = model(\'User\')->checkLogin();
 if($res[\'code\']>1){
 model(\'User\')->logout();
 return $this->error($res[\'msg\'], url(\'user/login\'));
 } */
            $this->assign('obj', $GLOBALS['user']);
        }
        $writesOnly = in_array($ac, ['unbind','bindmsg','ulog_del','plog_del','cash_del','ajax_buy_popedom'], true)
            || ($ac === 'ajax_ulog' && request()->param('ac') === 'set');
        $formWrite = request()->isPost() && in_array($ac, ['info','bind','buy','upgrade','cash'], true);
        if ($writesOnly || $formWrite) {
            if (!request()->isPost() || !\app\common\util\FrontCsrf::check(request()->post())) {
                throw new \think\exception\HttpResponseException(json(['code'=>1001,'msg'=>lang('illegal_request')]));
            }
        }
    }

    public function token()
    {
        return \app\common\util\FrontCsrf::response();
    }

    public function ajax_login()
    {
        return $this->fetch('user/ajax_login');
    }

    public function ajax_info()
    {
        return $this->fetch('user/ajax_info');
    }

    public function ajax_ulog()
    {
        $param = input();
        if ($param['ac'] == 'set') {
            $data = [];
            $data['ulog_mid'] = intval($param['mid']);
            $data['ulog_rid'] = intval($param['id']);
            $data['ulog_type'] = intval($param['type']);
            $data['ulog_sid'] = intval($param['sid']);
            $data['ulog_nid'] = intval($param['nid']);
            $data['user_id'] = $GLOBALS['user']['user_id'];

            // Only video play (4) and download (5) use vod_points_* pricing.
            // Initialize points so future behavior types (for example 6) do not read an undefined key.
            $data['ulog_points'] = 0;
            if ($data['ulog_mid'] == 1 && in_array($data['ulog_type'], [4, 5], true)) {
                $where2 = [];
                $where2['vod_id'] = $data['ulog_rid'];
                $res = model('Vod')->infoData($where2);
                if ($res['code'] > 1) {
                    return json($res);
                }
                $flag = $data['ulog_type'] == 4 ? 'play' : 'down';
                $data['ulog_points'] = $res['info']['vod_points_' . $flag];
            }
            $data['ulog_points'] = intval($data['ulog_points']);

            $res = model('Ulog')->infoData($data);
            if ($res['code'] == 1) {
                $r = model('Ulog')->where($data)->update(['ulog_time'=>time()]);
                return json($res);
            }
            if ($data['ulog_points'] == 0) {
                $res = model('Ulog')->saveData($data);
            } else {
                $res = ['code' => 2001, 'msg' => lang('index/ulog_fee')];
            }
        } else {
            $where = [];
            $where['user_id'] = $GLOBALS['user']['user_id'];
            $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
            $param['limit'] = intval($param['limit']) < 1 ? 10 : intval($param['limit']);
            if(intval($param['mid'])>0){
                $where['ulog_mid'] = ['eq',intval($param['mid'])];
            }
            if(intval($param['id'])>0){
                $where['ulog_rid'] = ['eq',intval($param['id'])];
            }
            if(intval($param['type'])>0){
                $where['ulog_type'] = ['eq',intval($param['type'])];
            }
            $order = 'ulog_time desc';
            $res = model('Ulog')->listData($where, $order, $param['page'], $param['limit']);
        }
        return json($res);
    }

    public function ajax_buy_popedom()
    {
        // The constructor validates the one-use form token; do not consume it twice.
        $param = request()->post();
        if (!request()->isPost() || !is_array($param)) return json(['code'=>2001,'msg'=>lang('param_err')]);
        $values=[];
        foreach (['mid','id','type','sid','nid'] as $key) {
            $value=$param[$key]??null;
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D',(string)$value) || (float)$value>2147483647) return json(['code'=>2001,'msg'=>lang('param_err')]);
            $values[$key]=(int)$value;
        }
        $mid=$values['mid']; $type=$values['type']; $userId=(int)($GLOBALS['user']['user_id']??0);
        if ($userId<1 || $values['id']<1 || $values['sid']>255 || $values['nid']>65535 || !(($mid===2 && $type===1) || ($mid===1 && in_array($type,[4,5],true)))) return json(['code'=>2001,'msg'=>lang('param_err')]);
        $operation=function() use ($values,$mid,$type,$userId) {
            $connection=null; $started=false; $failure=2003; $points=0; $balance=0;
            try {
                $connection=model('User')->getQuery()->getConnection();
                $users=(new \think\db\Query($connection))->name('user');
                $logs=(new \think\db\Query($connection))->name('plog');
                $purchases=(new \think\db\Query($connection))->name('ulog');
                $media=(new \think\db\Query($connection))->name($mid===1?'vod':'art');
                $engines=$connection->query('SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?,?)',[$users->getTable(),$logs->getTable(),$purchases->getTable(),$media->getTable()]);
                if(count($engines)!==4) throw new \RuntimeException('Nontransactional purchase storage');
                foreach($engines as $engine) if(strtolower((string)$engine['engine'])!=='innodb') throw new \RuntimeException('Nontransactional purchase storage');
                $connection->startTrans(); $started=true;
                $prefix=$mid===1?'vod':'art';
                $resource=$media->where([$prefix.'_id'=>$values['id'],$prefix.'_status'=>1])->lock(true)->find();
                if(!$resource) {$failure=2001;throw new \RuntimeException('Resource unavailable');}
                $config=$GLOBALS['config']['user']??[];
                $globalPrice=($config[$prefix.'_points_type']??0)==1;
                $sid=$values['sid']; $nid=$values['nid'];
                if($mid===2) {
                    $pages=count(explode('$$$',(string)$resource['art_content']));
                    if($nid!==0 || (!($globalPrice && $sid===0) && ($sid<1 || $sid>$pages))) {$failure=2001;throw new \RuntimeException('Invalid article page');}
                    $column=$globalPrice?'art_points':'art_points_detail';
                } else {
                    $flag=$type===4?'play':'down';
                    if(!($globalPrice && $sid===0 && $nid===0)) {
                        $sources=explode('$$$',(string)$resource['vod_'.$flag.'_from']);
                        $urls=explode('$$$',(string)$resource['vod_'.$flag.'_url']);
                        $players=(array)\think\Config::get($flag==='play'?'vodplayer':'voddowner');
                        $source=$sources[$sid-1]??''; $line=$urls[$sid-1]??'';
                        $episodes=explode('#',$line);
                        if($sid<1 || $nid<1 || $source==='' || ($players[$source]['status']??0)!=1 || !isset($episodes[$nid-1]) || $episodes[$nid-1]==='') {$failure=2001;throw new \RuntimeException('Invalid video episode');}
                    }
                    $column=$globalPrice?'vod_points':'vod_points_'.$flag;
                }
                if($globalPrice) {$sid=0;$nid=0;}
                $cost=(string)$resource[$column];
                if(!preg_match('/^[0-9]+$/D',$cost) || (float)$cost>65535) throw new \RuntimeException('Invalid resource cost');
                $points=(int)$cost;
                $account=$users->where(['user_id'=>$userId,'user_status'=>1])->lock(true)->find();
                if(!$account) throw new \RuntimeException('Account unavailable');
                $data=['user_id'=>$userId,'ulog_mid'=>$mid,'ulog_rid'=>$values['id'],'ulog_type'=>$type,'ulog_sid'=>$sid,'ulog_nid'=>$nid,'ulog_points'=>$points];
                // The payer lock serializes the absence check as well as the debit.
                if($purchases->where($data)->lock(true)->find()) {
                    $connection->commit();$started=false;
                    return ['code'=>1,'msg'=>lang('index/buy_popedom1')];
                }
                $balance=(int)$account['user_points'];
                if($balance<$points) {$failure=2002;throw new \RuntimeException('Insufficient points');}
                $rewards=[];
                if($points>0 && ($config['reward_status']??0)==1) {
                    foreach([['user_pid','reward_ratio',4],['user_pid_2','reward_ratio_2',5],['user_pid_3','reward_ratio_3',6]] as $tier) {
                        $recipient=(int)($account[$tier[0]]??0);$ratio=$config[$tier[1]]??0;
                        if($recipient<1 || empty($ratio)) continue;
                        if(!is_scalar($ratio) || !is_numeric($ratio) || !is_finite((float)$ratio) || (float)$ratio<0) throw new \RuntimeException('Invalid referral ratio');
                        $bonusValue=floor($points/100*(float)$ratio);
                        if(!is_finite($bonusValue) || $bonusValue>4294967295) throw new \RuntimeException('Referral amount overflow');
                        if($bonusValue>0) $rewards[]=['user_id'=>$recipient,'points'=>(int)$bonusValue,'type'=>$tier[2]];
                    }
                }
                $recipientIds=array_unique(array_column($rewards,'user_id'));sort($recipientIds,SORT_NUMERIC);$recipients=[];
                foreach($recipientIds as $recipient) if($users->where(['user_id'=>$recipient])->lock(true)->find()) $recipients[$recipient]=true;
                if($points>0 && $users->where(['user_id'=>$userId,'user_status'=>1])->setDec('user_points',$points)!==1) throw new \RuntimeException('Debit failed');
                if($logs->insert(['user_id'=>$userId,'plog_type'=>8,'plog_points'=>$points,'plog_time'=>time()])!==1) throw new \RuntimeException('Spend log failed');
                foreach($rewards as $reward) {
                    if(!isset($recipients[$reward['user_id']])) continue;
                    $current=$users->where(['user_id'=>$reward['user_id']])->value('user_points');
                    if((float)$current+$reward['points']>4294967295 || $users->where(['user_id'=>$reward['user_id']])->setInc('user_points',$reward['points'])!==1) throw new \RuntimeException('Referral credit failed');
                    $remarks=lang('model/user/reward_tip',[$userId,$account['user_name'],$points,$reward['points']]);
                    if($logs->insert(['user_id'=>$reward['user_id'],'plog_type'=>$reward['type'],'plog_points'=>$reward['points'],'plog_time'=>time(),'plog_remarks'=>$remarks])!==1) throw new \RuntimeException('Referral log failed');
                }
                $data['ulog_time']=time();
                if($purchases->insert($data)!==1) throw new \RuntimeException('Purchase log failed');
                $connection->commit();$started=false;
                return ['code'=>1,'msg'=>lang('save_ok')];
            } catch(\Throwable $error) {
                if($started && $connection) {try{$connection->rollback();}catch(\Throwable $ignored){}}
                $message=$failure===2001?lang('param_err'):($failure===2002?lang('index/buy_popedom3',[$points,$balance]):lang('index/buy_popedom2'));
                return ['code'=>$failure,'msg'=>$message];
            }
        };
        try {
            // Keep native deletion/cleanup exclusion outside the database transaction.
            $result=$mid===1 && class_exists('app\\common\\util\\VodDeletion')?\app\common\util\VodDeletion::withMediaLock($operation):$operation();
        } catch(\Throwable $error) {$result=['code'=>2003,'msg'=>lang('index/buy_popedom2')];}
        return json($result);
    }


    public function index()
    {
        return $this->fetch('user/index');
    }

    public function login()
    {
        if (Request()->isPost()) {
            $param = input();
            $res = model('User')->login($param);
            return json($res);
        }
        if (!empty(cookie('user_id') && !empty(cookie('user_name')))) {
            return redirect('user/index');
        }
        return $this->fetch('user/login');
    }

    public function logout()
    {
        $res = model('User')->logout();
        if (request()->isAjax()) {
            return json($res);
        } else {
            return redirect('user/login');
        }
    }

    public function oauth($type = '')
    {
        // QQ / WeChat sign-in are China-only services (admin/system/configconnect is removed): never redirect to them,
        // even when an old config still has them switched on.
        return $this->error(lang('admin/module_removed'));
        if (!in_array($type, ['qq', 'weixin'], true)) {
            return $this->error(lang('param_err'));
        }
        if (!\app\common\util\ConnectSettings::available()) {
            return $this->error(lang('admin/connect/unavailable'));
        }
        // Get the OAuth client for the selected provider.
        try {
            $sns = ThinkOauth::getInstance($type);
            $authorizationUrl = $sns->getRequestCodeURL();
        } catch (\Throwable $error) {
            return $this->error(lang(\app\common\util\ConnectSettings::errorKey($error)));
        }
        // Framework redirects remain outside the catch.
        return $this->redirect($authorizationUrl);
    }

    // Handle the OAuth authorization callback.
    public function logincallback($type = '', $code = '')
    {
        // QQ / WeChat sign-in are China-only services (admin/system/configconnect is removed): never redirect to them,
        // even when an old config still has them switched on.
        return $this->error(lang('admin/module_removed'));
        if (!in_array($type, ['qq', 'weixin'], true) || !is_string($code) || $code === '') {
            return $this->error(lang('param_err'));
        }
        if (!\app\common\util\ConnectSettings::available()) {
            return $this->error(lang('index/logincallback2'));
        }
        // Get the OAuth client for the selected provider.
        try {
            $sns = ThinkOauth::getInstance($type);
            // The SDK consumes bound state before any provider request.
            $token = $sns->getAccessToken($code, null);
            $loginEvent = new LoginEvent();
            $res = $loginEvent->$type($token);
        } catch (\Throwable $error) {
            return $this->error(lang(\app\common\util\ConnectSettings::errorKey($error)));
        }
        // Retrieve the provider profile using the access token.
        if (is_array($token)) {
            if (is_array($res) && isset($res['code']) && $res['code'] === 1) {
                $openid = $res['info']['openid'] ?? '';
                if (!is_string($openid) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $openid)) {
                    return $this->error(lang('index/logincallback2'));
                }
                $col = 'user_openid_' . $type;
                // Check whether the current user is already logged in.
                $check = model('User')->checkLogin();
                if ($check['code'] == 1) {

                    if ($check['info'][$col] == $openid) {
                        // No need to bind again
                        return json(['code' => 1001, 'msg' => lang('index/bind_haved')]);
                    } else {
                        // Remove the existing account association for this provider ID.
                        $where = [];
                        $where[$col] = $openid;
                        $update = [];
                        $update[$col] = '';
                        model('User')->where($where)->update($update);
                        // Link this provider ID to the current account.
                        $where = [];
                        $where['user_id'] = $GLOBALS['user']['user_id'];
                        $update = [];
                        $update[$col] = $openid;
                        model('User')->where($where)->update($update);
                        return json(['code' => 1, 'msg' => lang('index/bind_ok')]);
                    }
                }

                $where = [];
                $where[$col] = $openid;
                $res2 = model('User')->infoData($where);
                // Create a linked account when the lookup does not return an account.
                if ($res2['code'] > 1) {
                    $data = [];
                    $data['user_name'] = substr($openid, 0, 10);
                    $data['user_nick_name'] = htmlspecialchars(urldecode(trim($res['info']['name'])));
                    $pwd = bin2hex(random_bytes(16));
                    $data['user_pwd'] = $pwd;
                    $data['user_pwd2'] = $pwd;
                    $data[$col] = $openid;
                    $reg = model('User')->registerFromOauth($data, $type, $openid);
                    if ($reg['code'] > 1) {
                        // Registration failed
                        return $this->error(lang('index/logincallback1'));
                    }
                }
                // Sign in using the provider ID.
                $login = model('User')->loginFromOauth($type, $openid);
                if ($login['code'] > 1) {
                    return $this->error($login['msg']);
                }
                $this->redirect('user/index');
            } else {
                return $this->error(is_array($res) && isset($res['msg']) ? $res['msg'] : lang('index/logincallback2'));
            }
        } else {
            return $this->error(lang('index/logincallback2'));
        }
    }

    public function bindmsg()
    {
        $param = input();
        $res = model('User')->bindmsg($param);
        return json($res);
    }

    public function bind()
    {
        $param = input();
        if (Request()->isPost()) {
            $res = model('User')->bind($param);
            return json($res);
        }

        if (empty($param['ac'])) {
            $param['ac'] = 'email';
        }
        $this->assign('ac', $param['ac']);
        $this->assign('param',$param);
        return $this->fetch('user/bind');
    }

    public function unbind()
    {
        $param = input();
        if (Request()->isPost()) {
            $res = model('User')->unbind($param);
            return json($res);
        }
        $this->assign('param',$param);
        return $this->fetch('user/unbind');
    }

    public function info()
    {
        $param = input();
        if (Request()->isPost()) {
            $res = model('User')->info($param);
            if ($res['code'] == 1) {
                $this->success($res['msg']);
                exit;
            }
            $this->error($res['msg']);
            exit;
        }
        $this->assign('param',$param);
        return $this->fetch('user/info');
    }

    public function regcheck()
    {
        $param = input();
        $t = htmlspecialchars(urldecode(trim($param['t'])));
        $str = htmlspecialchars(urldecode(trim($param['str'])));
        $res = model('User')->regcheck($t, $str);
        if ($res['code'] > 1) {
            return $str;
        }
        return json($res);
    }

    public function reg()
    {
        $param = input();
        if (Request()->isPost()) {
            if (!empty(cookie('uid'))) {
                $param['uid'] = intval(cookie('uid'));
            }
            $res = model('User')->register($param);
            if ($res['code'] > 1) {
                return json($res);
            }

            $GLOBALS['config']['user']['login_verify'] = '0';
            $res = model('User')->login($param);
            $res['msg'] = lang('index/reg_ok').', ' . $res['msg'];
            return json($res);
        }
        if (!empty($param['uid'])) {
            cookie('uid', $param['uid']);
        }

        $user_config = $GLOBALS['config']['user'];
        if ((string) ($user_config['reg_phone_sms'] ?? '0') === '1'
            || (string) ($user_config['reg_email_sms'] ?? '0') === '1') {
            $user_config['reg_verify'] = '1';
        }
        $this->assign('user_config', $user_config);
        $this->assign('param', $param);
        return $this->fetch('user/reg');
    }

    public function reg_msg()
    {
        if (!Request()->isPost()) {
            return json(['code' => 9001, 'msg' => lang('param_err')]);
        }
        $param = input();
        $res = model('User')->reg_msg($param);
        return json($res);
    }


    public function portrait()
    {
        if (!request()->isPost()) {
            return $this->fetch('user/portrait');
        }
        $response = function ($code, $message, $file = '') {
            return json(['code' => $code, 'msg' => $message, 'file' => $file,
                '__token__' => request()->token('__token__')]);
        };
        $userId = (int) ($GLOBALS['user']['user_id'] ?? 0);
        if ($userId < 1) return $response(0, lang('index/no_login'));
        $posted = request()->post();
        $submitted = isset($posted['__token__']) ? $posted['__token__'] : null;
        if (!\app\common\util\PortraitUpload::validToken($submitted, \think\Session::get('__token__'))) {
            return $response(0, lang('verify_err'));
        }
        if ($GLOBALS['config']['user']['portrait_status'] == 0) {
            return $response(0, lang('index/portrait_tip1'));
        }
        $file = request()->file('file');
        $info = $file instanceof \think\File ? $file->getInfo() : [];
        $result = \app\common\util\PortraitUpload::save($info, $userId,
            $GLOBALS['config']['user']['portrait_size'], ROOT_PATH,
            function ($relative, $publish) use ($userId) {
                $users = model('User');
                $users->startTrans();
                try {
                    $owned = $users->where(['user_id'=>$userId, 'user_status'=>1])->lock(true)->field('user_id')->find();
                    if (!$owned) throw new \RuntimeException('index/portrait_err');
                    $changed = $users->where(['user_id'=>$userId, 'user_status'=>1])
                        ->update(['user_portrait'=>$relative]);
                    if ($changed === false) throw new \RuntimeException('index/portrait_err');
                    $publish();
                    $users->commit();
                    return true;
                } catch (\Throwable $error) {
                    $users->rollback();
                    throw $error;
                }
            });
        $path = $result['code'] === 1 ? MAC_PATH . $result['file'] . '?' . mt_rand(1000, 999999) : '';
        return $response($result['code'], lang($result['message_key']), $path);
    }

    public function findpass()
    {
        $param = input();
        if (Request()->isPost()) {
            $res = model('User')->findpass($param);
            return json($res);
        }
        $this->assign('param',$param);
        return $this->fetch('user/findpass');
    }

    public function findpass_msg()
    {
        $param = input();
        if (Request()->isPost()) {
            $res = model('User')->findpass_msg($param);
            return json($res);
        }
        // Only two recovery channels exist. Templates print ac inside attributes and a JS string,
        // so it is never echoed raw (reflected XSS through ?ac=).
        $param['ac'] = (isset($param['ac']) && $param['ac'] === 'phone') ? 'phone' : 'email';
        $param['ac_text'] = $param['ac'] == 'phone' ? lang('mobile') : lang('email');
        $this->assign('param', $param);
        return $this->fetch('user/findpass_msg');
    }

    public function findpass_reset()
    {
        if (Request()->isPost()) {
            $param = input();
            $res = model('User')->findpass_reset($param);
            return json($res);
        }
    }

    /**
     * Payment gateways this distribution offers: an installed adapter (application/common/extend/pay) that is not one
     * of the China-only providers the admin hides (application/admin/util/GlobalModules.php). Keyed by lower-case name.
     */
    private function pay_gateways()
    {
        $gateways = [];
        foreach (glob(APP_PATH . 'common/extend/pay/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $name) || \app\admin\util\GlobalModules::blocked('pay', $name)) {
                continue;
            }
            $class = 'app\\common\\extend\\pay\\' . $name;
            if (!class_exists($class)) {
                continue;
            }
            $adapter = new $class;
            $label = isset($adapter->name) && is_scalar($adapter->name) && (string)$adapter->name !== '' ? (string)$adapter->name : $name;
            $gateways[strtolower($name)] = ['file' => $name, 'class' => $class, 'name' => $label];
        }
        return $gateways;
    }

    /** Pay settings for views and checks, without the China-only gateway sections (they stay untouched in the config file). */
    private function pay_settings()
    {
        $settings = isset($GLOBALS['config']['pay']) && is_array($GLOBALS['config']['pay']) ? $GLOBALS['config']['pay'] : [];
        return \app\admin\util\GlobalModules::stripBlocked('pay', $settings);
    }

    /** Offered gateways with saved credentials: the only ones a member can pay with (empty when none is enabled). */
    private function pay_enabled()
    {
        $settings = $this->pay_settings();
        $enabled = [];
        foreach ($this->pay_gateways() as $key => $gateway) {
            if (isset($settings[$key]) && is_array($settings[$key]) && !empty($settings[$key]['appid'])) {
                $enabled[$key] = $gateway;
            }
        }
        return $enabled;
    }

    public function buy()
    {
        $param = input();
        if (Request()->isPost()) {
            $flag = input('param.flag');
            if ($flag == 'card') {
                $card_no = htmlspecialchars(urldecode(trim($param['card_no'])));
                $card_pwd = htmlspecialchars(urldecode(trim($param['card_pwd'])));

                $res = model('Card')->useData($card_no, $card_pwd, $GLOBALS['user']);
                return json($res);
            } else {
                // No gateway to pay with: say so (visitor's language) instead of creating an order nobody can settle.
                if (!$this->pay_enabled()) {
                    return json(['code' => 1003, 'msg' => \app\common\util\TemplateText::raw('ui.payment_is_not_enabled')]);
                }
                $price = input('param.price');
                if (empty($price)) {
                    return json(['code' => 1001, 'msg' => lang('param_err')]);
                }

                if ($price < $GLOBALS['config']['pay']['min']) {
                    return json(['code' => 1002, 'msg' =>lang('index/min_pay',[$GLOBALS['config']['pay']['min']])]);
                }

                $data = [];
                $data['user_id'] = $GLOBALS['user']['user_id'];
                $data['order_code'] = 'PAY' . mac_get_uniqid_code();
                $data['order_price'] = $price;
                $data['order_time'] = time();
                $data['order_points'] = intval($GLOBALS['config']['pay']['scale'] * $price);
                $res = model('Order')->saveData($data);
                $res['data'] = $data;
                return json($res);
            }
        }
        $this->assign('param',$param);
        $this->assign('config', $this->pay_settings());
        return $this->fetch('user/buy');
    }

    public function pay()
    {
        $param = input();
        $order_code = isset($param['order_code']) && is_scalar($param['order_code']) ? htmlspecialchars(urldecode(trim((string)$param['order_code']))) : '';
        $where = [];
        $where['order_code'] = $order_code;
        $where['user_id'] = $GLOBALS['user']['user_id'];
        $res = model('Order')->infoData($where);
        if ($res['code'] > 1) {
            return $this->error($res['msg']);
        }
        $this->assign('param',$param);
        $this->assign('config', $this->pay_settings());
        $this->assign('info', $res['info']);

        // Gateways a member can pay with (empty when none is enabled: every theme's pay page then shows its
        // "payments are unavailable" note); the admin settings forms are not rendered here.
        $extList = [];
        foreach ($this->pay_enabled() as $gateway) {
            $extList[$gateway['file']] = $gateway['name'];
        }
        $this->assign('extends', ['ext_list' => $extList, 'ext_html' => '']);
        $this->assign('ext_list', $extList);

        return $this->fetch('user/pay');
    }

    public function gopay()
    {
        $param = input();

        $text = function ($key) use ($param) {
            return isset($param[$key]) && is_scalar($param[$key]) ? trim((string)$param[$key]) : '';
        };
        $order_code = htmlspecialchars(urldecode($text('order_code')));
        $order_id = intval($text('order_id'));
        $payment = strtolower(urldecode($text('payment')));

        if (empty($order_code) && empty($order_id) && empty($payment)) {
            return $this->error(lang('param_err'));
        }

        // Only an offered gateway (never a China-only one) with saved credentials; anything else is "disabled".
        $gateways = $this->pay_gateways();
        $settings = $this->pay_settings();
        if (!isset($gateways[$payment]) || empty($settings[$payment]['appid'])) {
            return $this->error(lang('index/payment_status'));
        }

        // Verify the order
        $where['order_id'] = $order_id;
        $where['order_code'] = $order_code;
        $where['user_id'] = $GLOBALS['user']['user_id'];
        $res = model('Order')->infoData($where);
        if ($res['code'] > 1) {
            return $this->error(lang('index/order_not'));
        }
        if ($res['info']['order_status'] == 1) {
            return $this->error(lang('index/order_payed'));
        }

        $this->assign('order', $res['info']);
        // Assign the request parameters to the payment view.
        $this->assign('param',$param);

        // The adapter prints its own hand-off to the provider (form or redirect).
        $cp = $gateways[$payment]['class'];
        $c = new $cp;
        $c->submit($GLOBALS['user'], $res['info'], $param);
    }

    public function qrcode()
    {
        // It only drew WeChat Pay codes ("weixin://..."): that China-only gateway is not part of this distribution.
        $this->page_error(lang('page_not_found'));
    }

    public function upgrade()
    {
        $param = input();
        if (Request()->isPost()) {
            $res = model('User')->upgrade($param);
            return json($res);
        }

        $group_list = model('Group')->getCache();
        $this->assign('group_list', $group_list);
        $this->assign('param',$param);
        return $this->fetch('user/upgrade');
    }

    public function popedom()
    {
        $type_tree = model('Type')->getCache('type_tree');
        $this->assign('type_tree', $type_tree);

        $n = 1;
        $ids = [1 => lang('index/page_type'), 2 => lang('index/page_detail'), 3 => lang('index/page_play'), 4 => lang('index/page_down'), '5' => lang('index/try_see')];
        foreach ($type_tree as $k1 => $v1) {
            unset($type_tree[$k1]['type_extend']);
            foreach ($ids as $a => $b) {
                $n++;
                if ($v1['type_mid'] != 1 && $a > 2) {
                    break;
                }
                $type_tree[$k1]['popedom'][$b] = model('User')->popedom($v1['type_id'], $a, $GLOBALS['user']['group_id']);
            }
            foreach ($v1['child'] as $k2 => $v2) {
                unset($type_tree[$k1]['child'][$k2]['type_extend']);
                foreach ($ids as $a => $b) {
                    $n++;
                    if ($v2['type_mid'] != 1 && $a > 2) {
                        break;
                    }
                    $type_tree[$k1]['child'][$k2]['popedom'][$b] = model('User')->popedom($v2['type_id'], $a, $GLOBALS['user']['group_id']);
                }
            }
        }

        $this->assign('type_tree', $type_tree);

        return $this->fetch('user/popedom');
    }

    public function plays()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 20 ? 20 : intval($param['limit']);

        $where = [];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        $where['ulog_mid'] = 1;
        $where['ulog_type'] = 4;
        $order = 'ulog_time desc';
        $res = model('Ulog')->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('param',$param);
        $this->assign('list', $res['list']);
        $pages = mac_page_param($res['total'], $param['limit'], $param['page'], url('user/plays', ['page' => 'PAGELINK']));
        $this->assign('__PAGING__', $pages);
        return $this->fetch('user/plays');
    }

    public function downs()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 20 ? 20 : intval($param['limit']);

        $where = [];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        $where['ulog_mid'] = 1;
        $where['ulog_type'] = 5;
        $order = 'ulog_time desc';
        $res = model('Ulog')->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('param',$param);
        $this->assign('list', $res['list']);
        $pages = mac_page_param($res['total'], $param['limit'], $param['page'], url('user/downs', ['page' => 'PAGELINK']));
        $this->assign('__PAGING__', $pages);
        return $this->fetch('user/downs');
    }

    public function favs()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 20 ? 20 : intval($param['limit']);

        $where = [];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        if(in_array($param['mid'],['1','2','3','8'])){
            $where['ulog_mid'] = $param['mid'];
        }
        $where['ulog_type'] = 2;
        $order = 'ulog_time desc';
        $res = model('Ulog')->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('param',$param);
        $this->assign('list', $res['list']);
        $pages = mac_page_param($res['total'], $param['limit'], $param['page'], url('user/favs', ['page' => 'PAGELINK']));
        $this->assign('__PAGING__', $pages);
        return $this->fetch('user/favs');
    }

    public function ulog()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 20 ? 20 : intval($param['limit']);

        $where = [];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        if(in_array($param['mid'],['1','2','3','8'])){
            $where['ulog_mid'] = $param['mid'];
        }
        if(in_array($param['type'],['1','2','3','4','5'])){
            $where['ulog_type'] = $param['type'];
        }

        $order = 'ulog_time desc';
        $res = model('Ulog')->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('param',$param);
        $this->assign('list', $res['list']);
        $pages = mac_page_param($res['total'], $param['limit'], $param['page'], url('user/ulog', ['page' => 'PAGELINK']));
        $this->assign('__PAGING__', $pages);
        return $this->fetch('user/ulog');
    }

    public function ulog_del()
    {
        $param = input();
        $ids = htmlspecialchars(urldecode(trim($param['ids'])));
        $type = $param['type'];
        $all = $param['all'];

        if (!in_array($type, array('1', '2', '3', '4', '5'))) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        if (empty($ids) && empty($all)) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $arr = [];
        $ids = explode(',', $ids);
        foreach ($ids as $k => $v) {
            $v = intval(abs($v));
            $arr[$v] = $v;
        }

        $where = [];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        $where['ulog_type'] = $type;
        if ($all != '1') {
            $where['ulog_id'] = array('in', implode(',', $arr));
        }
        $return = model('Ulog')->delData($where);
        return json($return);
    }

    public function plog()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 20 ? 20 : intval($param['limit']);

        $where = [];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        $order = 'plog_id desc';
        $res = model('Plog')->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('param',$param);
        $this->assign('list', $res['list']);
        $pages = mac_page_param($res['total'], $param['limit'], $param['page'], url('user/plog', ['page' => 'PAGELINK']));
        $this->assign('__PAGING__', $pages);
        return $this->fetch('user/plog');
    }

    public function plog_del()
    {
        $param = input();
        $ids = htmlspecialchars(urldecode(trim($param['ids'])));
        $type = $param['type'];
        $all = $param['all'];

        if (empty($ids) && empty($all)) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $arr = [];
        $ids = explode(',', $ids);
        foreach ($ids as $k => $v) {
            $v = intval(abs($v));
            $arr[$v] = $v;
        }

        $where = [];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        if ($all != '1') {
            $where['plog_id'] = array('in', implode(',', $arr));
        }
        $return = model('Plog')->delData($where);
        return json($return);
    }

    public function cash()
    {
        $param = input();
        if (Request()->isPost()) {
            $param['user_id'] = $GLOBALS['user']['user_id'];
            $res = model('Cash')->saveData($param);
            return json($res);
        }

        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 20 ? 20 : intval($param['limit']);

        $where = [];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        $order = 'cash_id desc';
        $res = model('Cash')->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('param',$param);
        $this->assign('list', $res['list']);
        $pages = mac_page_param($res['total'], $param['limit'], $param['page'], url('user/cash', ['page' => 'PAGELINK']));
        $this->assign('__PAGING__', $pages);
        return $this->fetch('user/cash');
    }

    public function cash_del()
    {
        $param = input();
        $ids = htmlspecialchars(urldecode(trim($param['ids'])));
        $type = $param['type'];
        $all = $param['all'];

        if (empty($ids) && empty($all)) {
            return json(['code' => 1001, 'msg' => lang('param_err')]);
        }

        $arr = [];
        $ids = explode(',', $ids);
        foreach ($ids as $k => $v) {
            $v = intval(abs($v));
            $arr[$v] = $v;
        }

        $where = [];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        if ($all != '1') {
            $where['cash_id'] = array('in', implode(',', $arr));
        }
        $return = model('Cash')->delData($where);
        return json($return);
    }

    public function reward()
    {
        $param = input();
        $param['level'] = (($param['level'] ?? '') == '2') ? '2' : ((($param['level'] ?? '') == '3') ? '3' : '');

        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 20 ? 20 : intval($param['limit']);

        $where = [];
        if($param['level']=='2'){
            $where['user_pid_2'] = ['eq',$GLOBALS['user']['user_id']];
        }
        elseif($param['level']=='3'){
            $where['user_pid_3'] = ['eq',$GLOBALS['user']['user_id']];
        }
        else{
            $where['user_pid'] = ['eq',$GLOBALS['user']['user_id']];
        }

        $order = 'user_id desc';
        $res = model('User')->listData($where, $order, $param['page'], $param['limit']);

        $this->assign('param',$param);
        $this->assign('list', $res['list']);
        $pages = mac_page_param($res['total'], $param['limit'], $param['page'], url('user/reward', ['page' => 'PAGELINK', 'level' => $param['level']]));
        $this->assign('__PAGING__', $pages);
        return $this->fetch('user/reward');
    }

    public function orders()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 20 ? 20 : intval($param['limit']);

        $where = [];
        $where['o.user_id'] = $GLOBALS['user']['user_id'];

        $order = 'o.order_id desc';
        $res = model('Order')->listData($where, $order, $param['page'], $param['limit']);

        $pages = mac_page_param($res['total'], $param['limit'], $param['page'], url('user/orders', ['page' => 'PAGELINK']));
        $this->assign('__PAGING__', $pages);
        $this->assign('param',$param);
        $this->assign('list', $res['list']);
        return $this->fetch('user/orders');
    }

    public function order_info()
    {
        $param = input();
        $where = [];
        $where['order_id'] = intval($param['order_id']);
        $where['user_id'] = $GLOBALS['user']['user_id'];
        $res = model('Order')->infoData($where);
        if (request()->isAjax()) {
            return json($res);
        }
        $this->assign('param',$param);
        return $this->fetch('user/order_info');
    }


    public function cards()
    {
        $param = input();
        $param['page'] = intval($param['page']) < 1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) < 20 ? 20 : intval($param['limit']);

        $where = [];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        $where['card_use_status'] = 1;

        $order = 'card_id desc';
        $res = model('Card')->listData($where, $order, $param['page'], $param['limit']);

        $pages = mac_page_param($res['total'], $param['limit'], $param['page'], url('user/cards', ['page' => 'PAGELINK']));
        $this->assign('__PAGING__', $pages);
        $this->assign('param',$param);
        $this->assign('list', $res['list']);
        return $this->fetch('user/cards');
    }

    public function comment()
    {
        $param = input();
        $this->assign('param',$param);
        return $this->fetch('user/comment');
    }

    public function gbook()
    {
        $param = input();
        $this->assign('param',$param);
        return $this->fetch('user/gbook');
    }

    public function visit()
    {
        $param = input();
        $res = model('User')->visit($param);
        $url = '/';
        if (!empty($param['url']) && is_string($param['url'])) {
            $url = self::sameSiteUrl($param['url']);
        }
        $this->redirect($url);
    }

    /**
     * Referral links (user/visit?url=...) may only continue to this site: a local path, or an absolute http(s) URL whose
     * host is this request's host. Backslashes, control characters, user-info and protocol-relative forms are refused,
     * because browsers read "http://evil.example\@this-site/" as a link to evil.example (open redirect).
     */
    private static function sameSiteUrl($target)
    {
        $target = trim((string)$target);
        if ($target === '' || strlen($target) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $target)) {
            return '/';
        }
        if ($target[0] === '/') {
            return (isset($target[1]) && ($target[1] === '/')) ? '/' : $target;
        }
        $parts = @parse_url($target);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return '/';
        }
        $host = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        $own = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        return ($own !== '' && hash_equals($own, $host)) ? $target : '/';
    }

}
