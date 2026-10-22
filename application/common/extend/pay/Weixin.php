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
namespace app\common\extend\pay;

class Weixin {

    public $name = 'WeChat Pay';
    public $ver = '1.0';
    
    public function submit($user, $order, $param)
    {
        $callback = \app\common\util\PaySettings::callbackPreview($_SERVER['HTTP_HOST'] ?? '', $GLOBALS['http_type'] ?? '', 'weixin');
        if ($callback === '') { return false; }
        $total_fee = $order['order_price'];
        $data = array();
        $data['appid'] = trim($GLOBALS['config']['pay']['weixin']['appid']);// WeChat application ID.
        $data['mch_id'] = trim($GLOBALS['config']['pay']['weixin']['mchid']);// Merchant ID
        $data['nonce_str'] = mac_get_rndstr();// Random string
        $data['body'] = 'Points Recharge (UID: ' . $user['user_id'] . ')';// Product description
        $data['fee_type'] = 'CNY';// Currency type
        $data['out_trade_no'] = $order['order_code'];// Merchant order number
        $data['total_fee'] = $total_fee * 100;// Amount in fen (1/100 of a yuan).
        $data['spbill_create_ip'] = mac_get_client_ip();// Client IP
        $data['notify_url'] = $callback;
        $data['trade_type'] = 'NATIVE';// Trade type: JSAPI, NATIVE, APP
        $data['product_id'] = '1';// Product ID
        // $data['openid'] = ''; // When the trade_type is JSAPI (i.e., public account payment), this parameter must be passed
        $data['sign'] =  $this->makeSign($data);
        // Request a payment QR-code URL.
        $data_xml = mac_array2xml($data);
        $res = \app\common\util\PayTransport::post($data_xml);

        if (is_array($res) && ($res['return_code'] ?? '') === 'SUCCESS' && ($res['result_code'] ?? '') === 'SUCCESS' && isset($res['code_url']) && is_string($res['code_url']) && strpos($res['code_url'], 'weixin://') === 0) {
            // Return the payment details.
            $res = [
                'user_id'=>$user['user_id'],
                'total_fee'=>$total_fee,
                'out_trade_no'=>$data['out_trade_no'],
                'code_url'=>$res['code_url']
            ];

            // Example QR renderer: https://example.com/qrcode.php?data=<encoded-code_url>
            return $res;
        }
        // echo \'Payment error: \'. $res [\'return_msg\'];
        return false;
    }

    public function notify()
    {
        $xml = file_get_contents('php://input', false, null, 0, \app\common\util\PayTransport::MAX_BODY + 1);
        $config = config('maccms.pay');

        // Parse the notification XML into an array.
        $data = \app\common\util\PayTransport::parseResponse($xml);
        if (!is_array($data) || !isset($data['sign'], $data['out_trade_no']) || $data['out_trade_no'] === '') {
            echo '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[Failed to sign]]></return_msg></xml>';
            return;
        }
        // Keep the signature supplied by WeChat.
        $data_sign = $data['sign'];
        // Exclude the sign field from the signature input.
        unset($data['sign']);
        // Compute the expected signature.
        $sign = $this->makeSign($data);
        // Check the signature and both payment status codes.
        if ( hash_equals($sign, $data_sign) && (($data['return_code'] ?? '')==='SUCCESS') && (($data['result_code'] ?? '')==='SUCCESS') ) {
            $res = model('Order')->notify($data['out_trade_no'],'weixin');
            echo '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        }
        else{
            echo '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[Failed to sign]]></return_msg></xml>';
        }
    }

    public function makeSign($data){
        // Get the WeChat Pay key
        $key = trim($GLOBALS['config']['pay']['weixin']['appkey']);
        // Remove values that PHP considers empty.
        $data=array_filter($data);
        // Signature step 1: sort parameters by key.
        ksort($data);
        $string_a=http_build_query($data);
        $string_a=urldecode($string_a);
        // Signature step 2: append the payment key.
        $string_sign_temp=$string_a."&key=".$key;
        // Signature step 3: compute the MD5 hash.
        $sign = md5($string_sign_temp);
        // Signature step 4: convert the hash to uppercase.
        $result=strtoupper($sign);
        return $result;
    }

}
