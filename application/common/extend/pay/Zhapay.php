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
namespace app\common\extend\pay;

class Zhapay {

    public $name = 'Fantasy payment';
    public $ver = '1.0';

    public function submit($user,$order,$param)
    {
        $callback = \app\common\util\PaySettings::callbackPreview($_SERVER['HTTP_HOST'] ?? '', $GLOBALS['http_type'] ?? '', 'zhapay');
        if ($callback === '') { return false; }
        $pay_type = 1;
        if(!empty($param['paytype'])){
            $pay_type = intval($param['paytype']);
        }

        // Build the request parameters.
        $data = array(
            "mch_uid" => trim( $GLOBALS['config']['pay']['zhapay']['appid'] ),// Merchant ID.
            "pay_type_id" => $pay_type,// Payment method: 1 for WeChat Pay, 2 for Alipay.
            "total_fee" => $order['order_price'],// Payment amount in yuan.
            "out_trade_no" => $order['order_code'], // Merchant order reference returned after payment.
            "notify_url" => $callback,// Payment notification URL.
            "return_url" => $callback,// Return URL after payment.
            "debug" => 1,// Debug flag included in the provider request.
            "mepay_type" => trim( $GLOBALS['config']['pay']['zhapay']['act'] ),// Payment mode: 1 for collection (application required), 2 for immediate settlement.
            "return_type" =>1,
            "param" => "",//Custom parameter
        );

        ksort($data); // Sort the request parameters by key.
        reset($data); // Reset the array pointer to the first parameter.

        $sign = ''; // Start with an empty signature input.
        $urls = ''; // Start with an empty query string.

        foreach ($data as $key => $val) { // Build the signature input and query string.
            if ($val == ''||$key == 'sign') continue; // Skip empty values and the signature field.
            if ($sign != '') { // Separate parameter pairs with &.
                $sign .= "&";
                $urls .= "&";
            }
            $sign .= "$key=$val"; // Append an unencoded key=value pair to the signature input.
            $urls .= "$key=" . urlencode($val); // Append a key=value pair with the value URL-encoded.
        }

        $query = $urls . '&sign='.md5($sign.trim( $GLOBALS['config']['pay']['zhapay']['appkey'] )); // Append the MD5 signature to the order request parameters.
        $url = "https://www.zhapay.com/mapay.html?{$query}"; // Payment page

        mac_redirect($url);
    }

    public function notify()
    {
        $param = $_POST;
        // Read the merchant order reference from out_trade_no.
        // Require a nonempty transaction_id before accepting the notification.
        // money: payment amount.
        // param: custom parameter.


        // unset($param[\'/payment/notify/pay_type/zhapay\']);
        // unset($param[\'pay_type\']);

        ksort($param); // Sort the notification parameters by key.
        reset($param); // Reset the array pointer to the first parameter.
        $sign = '';
        foreach ($param as $key => $val) {
            if ($val == '' || $key == 'sign') continue;  // Skip empty values and the signature field.
            if ($sign) $sign .= '&';
            $sign .= "$key=$val"; // Append an unencoded key=value pair to the signature input.
        }

        $GLOBALS['config']['pay'] = config('maccms.pay');

        if (!$param['transaction_id'] || md5($sign. $GLOBALS['config']['pay']['zhapay']['appkey']) != $param['sign']) {
            echo 'fail';
        }
        else{
            $res = model('Order')->notify($param['out_trade_no'],'zhapay');
            if($res['code'] >1){
                echo 'fail2';
            }
            else {
                echo 'success';
            }
        }
    }
}
