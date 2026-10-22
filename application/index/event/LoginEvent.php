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
namespace app\index\event;
use login\ThinkOauth;

class LoginEvent
{
    // Fetch Tencent QQ user information using the login token.
    public function qq($token)
    {
        $qq = ThinkOauth::getInstance('qq', $token);
        $data = $qq->call('user/get_user_info');
        if (is_array($data) && isset($data['ret']) && in_array($data['ret'], [0, '0'], true)) {
            $userInfo['type'] = 'QQ';
            $userInfo['name'] = ($data['nickname'] ?? '');
            $userInfo['nick'] = ($data['nickname'] ?? '');
            $userInfo['head'] = ($data['figureurl_2'] ?? '');
            $userInfo['openid'] = $qq->openid();
            return ['code'=>1,'msg'=>'ok','info'=>$userInfo];
        } else {
            return ['code'=>0,'msg'=>lang('index/logincallback2')];
        }
    }

    // Fetch WeChat user information using the login token.
    public function weixin($token)
    {
        $weixin = ThinkOauth::getInstance('weixin', $token);
        $data = $weixin->call('sns/userinfo');
        if (is_array($data) && !empty($data) && (!isset($data['errcode']) || in_array($data['errcode'], [0, '0'], true))) {
            $userInfo['type'] = 'WEIXIN';
            $userInfo['name'] = ($data['nickname'] ?? '');
            $userInfo['nick'] = ($data['nickname'] ?? '');
            $userInfo['head'] = ($data['headimgurl'] ?? '');
            $userInfo['openid'] = $weixin->openid();
            return ['code'=>1,'msg'=>'ok','info'=>$userInfo];
        } else {
            return ['code'=>0,'msg'=>lang('index/logincallback2')];
        }
    }

}