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

class Qcloud {

    public $name = 'Tencent Cloud SMS';
    public $ver = '2.0';
    private $url;
    private $appid;
    private $appkey;

    public function __construct()
    {
        $this->url = "https://yun.tim.qq.com/v5/tlssmssvr/sendsms";
        $this->appid =  $GLOBALS['config']['sms']['qcloud']['appid'] ?? '';
        $this->appkey = $GLOBALS['config']['sms']['qcloud']['appkey'] ?? '';
    }

    /* *
 * Generate a six-digit random integer.
 *
 * @return int Random integer from 100000 to 999999 */
    public function getRandom()
    {
        return rand(100000, 999999);
    }

    /* *
 * Generate a signature for the supplied phone numbers.
 *
 * @param string $appkey Application key associated with SDKappid
 * @param string $random Random positive integer
 * @param string $curTime Current Unix timestamp
 * @param array $phoneNumbers Array of phone numbers
 * @return string SHA-256 signature */
    public function calculateSig($appkey, $random, $curTime, $phoneNumbers)
    {
        $phoneNumbersString = $phoneNumbers[0];
        for ($i = 1; $i < count($phoneNumbers); $i++) {
            $phoneNumbersString .= ("," . $phoneNumbers[$i]);
        }

        return hash("sha256", "appkey=".$appkey."&random=".$random
            ."&time=".$curTime."&mobile=".$phoneNumbersString);
    }

    /* *
 * Generate a signature for the supplied phone numbers.
 *
 * @param string $appkey Application key associated with SDKappid
 * @param string $random Random positive integer
 * @param string $curTime Current Unix timestamp
 * @param array $phoneNumbers Array of phone numbers
 * @return string SHA-256 signature */
    public function calculateSigForTemplAndPhoneNumbers($appkey, $random,
                                                        $curTime, $phoneNumbers)
    {
        $phoneNumbersString = $phoneNumbers[0];
        for ($i = 1; $i < count($phoneNumbers); $i++) {
            $phoneNumbersString .= ("," . $phoneNumbers[$i]);
        }

        return hash("sha256", "appkey=".$appkey."&random=".$random
            ."&time=".$curTime."&mobile=".$phoneNumbersString);
    }

    public function phoneNumbersToArray($nationCode, $phoneNumbers)
    {
        $i = 0;
        $tel = array();
        do {
            $telElement = new \stdClass();
            $telElement->nationcode = $nationCode;
            $telElement->mobile = $phoneNumbers[$i];
            array_push($tel, $telElement);
        } while (++$i < count($phoneNumbers));

        return $tel;
    }

    /* *
 * Generate a signature for one phone number.
 *
 * @param string $appkey Application key associated with SDKappid
 * @param string $random Random positive integer
 * @param string $curTime Current Unix timestamp
 * @param string $phoneNumber Phone number
 * @return string SHA-256 signature */
    public function calculateSigForTempl($appkey, $random, $curTime, $phoneNumber)
    {
        $phoneNumbers = array($phoneNumber);

        return $this->calculateSigForTemplAndPhoneNumbers($appkey, $random,
            $curTime, $phoneNumbers);
    }

    /* *
 * Generate a signature from the application key, random value and timestamp.
 *
 * @param string $appkey Application key associated with SDKappid
 * @param string $random Random positive integer
 * @param string $curTime Current Unix timestamp
 * @return string SHA-256 signature */
    public function calculateSigForPuller($appkey, $random, $curTime)
    {
        return hash("sha256", "appkey=".$appkey."&random=".$random
            ."&time=".$curTime);
    }

    /* *
 * Generate an authorization signature for content.
 *
 * @param string $appkey Application key associated with SDKappid
 * @param string $random Random positive integer
 * @param string $curTime Current Unix timestamp
 * @param string $fileSha1sum SHA-1 hash of the content
 * @return string SHA-256 authorization signature */
    public function calculateAuth($appkey, $random, $curTime, $fileSha1Sum)
    {
        return hash("sha256", "appkey=".$appkey."&random=".$random
            ."&time=".$curTime."&content-sha1=".$fileSha1Sum);
    }

    /* *
 * Calculate the SHA-1 hash of the content.
 *
 * @param string $content Content to hash
 * @return string SHA-1 hash */
    public function sha1sum($content)
    {
        return hash("sha1", $content);
    }

    /* *
 * Send a JSON POST request.
 *
 * @param string $url Request URL
 * @param array|object $dataObj Data to encode as JSON
 * @return string JSON response string */
    public function sendCurlPost($url, $dataObj)
    {
        try {
            $body = json_encode($dataObj, JSON_UNESCAPED_UNICODE);
            if ($body === false) { throw new \RuntimeException(); }
            return \app\common\util\SmsSettings::http($url, 'POST', $body, true);
        } catch (\Throwable $error) {
            return json_encode(['result' => -2, 'errmsg' => lang('admin/smssetup/transport_failed')]);
        }
    }

    public function sendWithParam($nationCode, $phoneNumber, $templId = 0, $params,
                                  $sign = "", $extend = "", $ext = "")
    {
        $random = $this->getRandom();
        $curTime = time();
        $wholeUrl = $this->url . "?sdkappid=" . $this->appid . "&random=" . $random;
        // Build the POST payload in the API format.
        $data = new \stdClass();
        $tel = new \stdClass();
        $tel->nationcode = "".$nationCode;
        $tel->mobile = "".$phoneNumber;

        $data->tel = $tel;
        $data->sig = $this->calculateSigForTempl($this->appkey, $random,
            $curTime, $phoneNumber);
        $data->tpl_id = $templId;
        $data->params = $params;
        $data->sign = $sign;
        $data->time = $curTime;
        $data->extend = $extend;
        $data->ext = $ext;

        return $this->sendCurlPost($wholeUrl, $data);
    }

    public function submit($phone,$code,$type_flag,$type_des,$text)
    {
        if(!is_scalar($phone) || !is_scalar($code) || empty($phone) || empty($code) || !in_array($type_flag, ['reg','bind','findpass'], true)){
            return ['code'=>101,'msg'=>lang('admin/smssetup/invalid_parameters')];
        }

        if (!\app\common\util\SmsSettings::sendReady('qcloud', $type_flag)) { return ['code'=>101,'msg'=>lang('admin/smssetup/credentials_required')]; }

        $sign = $GLOBALS['config']['sms']['sign'];
        $tpl = $GLOBALS['config']['sms']['tpl_code_'.$type_flag] ?? '';
        if (!is_scalar($tpl) || (string)$tpl === '') { return ['code'=>101,'msg'=>lang('admin/smssetup/template_required')]; }
        $params = [
            $code
        ];

        try {
            $result = $this->sendWithParam("86", $phone, $tpl, $params, $sign, "", "");
            return \app\common\util\SmsSettings::providerResult($result, 'qcloud');
        }
        catch(\Throwable $error) {
            return ['code'=>102,'msg'=>lang('admin/smssetup/send_failed')];
        }
    }
}
