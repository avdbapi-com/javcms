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
namespace app\common\extend\sms;

class Aliyun {

    public $name = 'Alibaba Cloud SMS';
    public $ver = '2.0';

    /* *
 * Sign the API parameters and send a request
 *
 * @param $accessKeyID string accessKeyID (https://ak-console.aliyun.com/)
 * @param $accessKeySecret string AccessKeySecret
 * @param $domain string API host name
 * @param $params array API request parameters
 * @param $security boolean Whether to use HTTPS
 * @param $method string HTTP request method (GET or POST)
 * @return mixed Decoded JSON response, or false if an exception is caught */
    public function request($accessKeyId, $accessKeySecret, $domain, $params, $security=true, $method='POST') {
        $apiParams = array_merge(array (
            "SignatureMethod" => "HMAC-SHA1",
            "SignatureNonce" => uniqid(mt_rand(0,0xffff), true),
            "SignatureVersion" => "1.0",
            "AccessKeyId" => $accessKeyId,
            "Timestamp" => gmdate("Y-m-d\TH:i:s\Z"),
            "Format" => "JSON",
        ), $params);
        ksort($apiParams);

        $sortedQueryStringTmp = "";
        foreach ($apiParams as $key => $value) {
            $sortedQueryStringTmp .= "&" . $this->encode($key) . "=" . $this->encode($value);
        }

        $stringToSign = $method . "&%2F&" . $this->encode(substr($sortedQueryStringTmp, 1));

        $sign = base64_encode(hash_hmac("sha1", $stringToSign, $accessKeySecret . "&",true));

        $signature = $this->encode($sign);

        if ($domain !== 'dysmsapi.aliyuncs.com' || !in_array($method, ['POST','GET'], true)) { return false; }
        $url = "https://dysmsapi.aliyuncs.com/";

        try {
            $content = $this->fetchContent($url, $method, "Signature={$signature}{$sortedQueryStringTmp}");
            return json_decode($content,true);
        } catch( \Throwable $e) {
            return false;
        }
    }

    private function encode($str)
    {
        $res = urlencode($str);
        $res = preg_replace("/\+/", "%20", $res);
        $res = preg_replace("/\*/", "%2A", $res);
        $res = preg_replace("/%7E/", "~", $res);
        return $res;
    }

    protected function fetchContent($url, $method, $body) {
        return \app\common\util\SmsSettings::http($url, $method, $body);
    }

    public function submit($phone,$code,$type_flag,$type_des,$text)
    {
        if(!is_scalar($phone) || !is_scalar($code) || empty($phone) || empty($code) || !in_array($type_flag, ['reg','bind','findpass'], true)){
            return ['code'=>101,'msg'=>lang('admin/smssetup/invalid_parameters')];
        }

        if (!\app\common\util\SmsSettings::sendReady('aliyun', $type_flag)) { return ['code'=>101,'msg'=>lang('admin/smssetup/credentials_required')]; }

        $appid = $GLOBALS['config']['sms']['aliyun']['appid'];
        $appkey = $GLOBALS['config']['sms']['aliyun']['appkey'];
        $sign = $GLOBALS['config']['sms']['sign'];
        $security = true;
        $tpl = $GLOBALS['config']['sms']['tpl_code_'.$type_flag] ?? '';
        if (!is_scalar($tpl) || (string)$tpl === '') { return ['code'=>101,'msg'=>lang('admin/smssetup/template_required')]; }

        $params=[];
        $params['PhoneNumbers'] = $phone;
        $params['SignName'] = $sign;
        $params['TemplateCode'] = $tpl;
        $params['TemplateParam'] = [
            'code'=>$code,
        ];

        if( is_array($params["TemplateParam"])) {
            $params["TemplateParam"] = json_encode($params["TemplateParam"], JSON_UNESCAPED_UNICODE);
        }

        try {
            $rsp = $this->request(
                $appid,
                $appkey,
                "dysmsapi.aliyuncs.com",
                array_merge($params, array(
                    "RegionId" => "cn-hangzhou",
                    "Action" => "SendSms",
                    "Version" => "2017-05-25",
                )),
                $security
            );

            return \app\common\util\SmsSettings::providerResult($rsp, 'aliyun');
        }
        catch(\Throwable $e) {
            return ['code'=>102,'msg'=>lang('admin/smssetup/send_failed')];
        }
    }
}
