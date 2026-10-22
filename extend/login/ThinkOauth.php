<?php
namespace login;

use app\common\util\ConnectSettings;

/** Interface-compatible modernization of magicblack/maccms10 ThinkOauth.
 * Source commit a468b6236c1e028b1aca3f91f463345cf72f16c7, Apache 2.0.
 * Modified: strict providers, session state, fixed HTTPS transport and safe failures.
 */
abstract class ThinkOauth
{
    protected $AppKey;
    protected $AppSecret;
    protected $Callback;
    protected $Token;
    protected $Type;

    public function __construct($token = null)
    {
        $this->Type = $this instanceof \login\sdk\QqSDK ? 'qq' : 'weixin';
        $config = ConnectSettings::credentials($this->Type);
        $this->AppKey=$config['key']; $this->AppSecret=$config['secret']; $this->Token=$token;
    }

    public static function getInstance($type, $token = null)
    {
        if ($type === 'qq') { return new \login\sdk\QqSDK($token); }
        if ($type === 'weixin') { return new \login\sdk\WeixinSDK($token); }
        throw new \InvalidArgumentException('param_err');
    }

    public function config() { $this->Callback=ConnectSettings::callback($this->Type); }
    protected function beginState() { $this->config(); return ConnectSettings::issueState($this->Type,$this->Callback); }
    protected function exchangeState($code)
    {
        if (!is_string($code) || $code==='' || strlen($code)>4096 || preg_match('/[\x00-\x20\x7f]/',$code)) {
            throw new \RuntimeException('admin/connect/invalid_state');
        }
        $query=input('get.', [], '');
        $flow=ConnectSettings::consumeState($this->Type,$query['state'] ?? null);
        $this->Callback=$flow['callback'];
    }
    protected function http($url,$params,$method='GET',$header=[],$multi=false)
    {
        if ($header || $multi || !is_array($params)) { throw new \RuntimeException('admin/connect/provider_failed'); }
        return ConnectSettings::transport($url,$params,$method);
    }
    protected function param(array $params,$extra)
    {
        if (is_string($extra)) { parse_str($extra,$extra); }
        if (!is_array($extra) || $extra) { throw new \RuntimeException('admin/connect/provider_failed'); }
        return $params;
    }
    abstract public function getRequestCodeURL();
    abstract public function getAccessToken($code,$extend=null);
    abstract public function openid();
    abstract public function call($api,$param='',$method='GET',$multi=false);
}
