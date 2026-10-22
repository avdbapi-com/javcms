<?php
namespace login\sdk;
use app\common\util\ConnectSettings;

/** Website-app snsapi_login and provider OpenID retained, never mapped to unionid. */
class WeixinSDK extends \login\ThinkOauth
{
    public function getRequestCodeURL()
    {
        $state=$this->beginState();
        return 'https://open.weixin.qq.com/connect/qrconnect?'.http_build_query(['appid'=>$this->AppKey,'redirect_uri'=>$this->Callback,
            'response_type'=>'code','scope'=>'snsapi_login','state'=>$state], '', '&', PHP_QUERY_RFC3986).'#wechat_redirect';
    }
    public function getAccessToken($code,$extend=null)
    {
        $this->exchangeState($code);
        $data=json_decode($this->http('https://api.weixin.qq.com/sns/oauth2/access_token',['appid'=>$this->AppKey,'secret'=>$this->AppSecret,
            'grant_type'=>'authorization_code','code'=>$code], 'GET'),true);
        if (!is_array($data) || isset($data['errcode'])) { throw new \RuntimeException('admin/connect/provider_failed'); }
        $this->Token=ConnectSettings::token($data,true); return $this->Token;
    }
    public function openid()
    {
        if (!ConnectSettings::validOpenid($this->Token['openid'] ?? null)) { throw new \RuntimeException('admin/connect/provider_failed'); }
        return $this->Token['openid'];
    }
    public function call($api,$param='',$method='GET',$multi=false)
    {
        if ($api!=='sns/userinfo' || $method!=='GET' || $multi) { throw new \RuntimeException('admin/connect/provider_failed'); }
        $token=ConnectSettings::token((array)$this->Token,true);
        $data=json_decode($this->http('https://api.weixin.qq.com/sns/userinfo',$this->param(['access_token'=>$token['access_token'],'openid'=>$this->openid()],$param)),true);
        if (!is_array($data) || !ConnectSettings::validOpenid($data['openid'] ?? null) || $data['openid']!==$this->openid()
            || (isset($data['errcode']) && !in_array($data['errcode'],[0,'0'],true))
            || (isset($data['nickname']) && !is_string($data['nickname']))) { throw new \RuntimeException('admin/connect/provider_failed'); }
        return $data;
    }
}
