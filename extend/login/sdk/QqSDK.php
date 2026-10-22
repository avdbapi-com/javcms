<?php
namespace login\sdk;
use app\common\util\ConnectSettings;

/** QQ OpenID identity preserved; pinned upstream provenance in ../OAUTH-PROVENANCE.json. */
class QqSDK extends \login\ThinkOauth
{
    public function getRequestCodeURL()
    {
        $state=$this->beginState();
        return 'https://graph.qq.com/oauth2.0/authorize?'.http_build_query(['client_id'=>$this->AppKey,'redirect_uri'=>$this->Callback,
            'response_type'=>'code','scope'=>'get_user_info','state'=>$state], '', '&', PHP_QUERY_RFC3986);
    }
    public function getAccessToken($code,$extend=null)
    {
        $this->exchangeState($code);
        $raw=$this->http('https://graph.qq.com/oauth2.0/token',['client_id'=>$this->AppKey,'client_secret'=>$this->AppSecret,
            'grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$this->Callback], 'GET');
        parse_str($raw,$data);
        if (isset($data['error'])) { throw new \RuntimeException('admin/connect/provider_failed'); }
        $this->Token=ConnectSettings::token($data);
        $this->Token['openid']=$this->openid();
        return $this->Token;
    }
    public function openid()
    {
        if (ConnectSettings::validOpenid($this->Token['openid'] ?? null)) { return $this->Token['openid']; }
        $token=ConnectSettings::token((array)$this->Token);
        $raw=$this->http('https://graph.qq.com/oauth2.0/me',['access_token'=>$token['access_token']]);
        if (!preg_match('/^\s*callback\s*\(\s*(\{.*\})\s*\)\s*;?\s*$/sD',$raw,$match)) { throw new \RuntimeException('admin/connect/provider_failed'); }
        $data=json_decode($match[1],true);
        if (!is_array($data) || !ConnectSettings::validOpenid($data['openid'] ?? null) || isset($data['error'])
            || !isset($data['client_id']) || !is_scalar($data['client_id']) || (string)$data['client_id'] !== $this->AppKey) { throw new \RuntimeException('admin/connect/provider_failed'); }
        $this->Token['openid']=$data['openid']; return $data['openid'];
    }
    public function call($api,$param='',$method='GET',$multi=false)
    {
        if ($api!=='user/get_user_info' || $method!=='GET' || $multi) { throw new \RuntimeException('admin/connect/provider_failed'); }
        $token=ConnectSettings::token((array)$this->Token);
        $params=$this->param(['oauth_consumer_key'=>$this->AppKey,'access_token'=>$token['access_token'],'openid'=>$this->openid(),'format'=>'json'],$param);
        $data=json_decode($this->http('https://graph.qq.com/user/get_user_info',$params),true);
        if (!is_array($data) || !isset($data['ret']) || !in_array($data['ret'],[0,'0'],true)
            || (isset($data['nickname']) && !is_string($data['nickname']))) { throw new \RuntimeException('admin/connect/provider_failed'); }
        return $data;
    }
}
