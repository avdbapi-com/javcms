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
namespace app\common\extend\pay;

class Codepay {

    public $name = 'CodePay';
    public $ver = '1.0';
    
    public function submit($user, $order, $param)
    {
        $callback = \app\common\util\PaySettings::callbackPreview($_SERVER['HTTP_HOST'] ?? '', $GLOBALS['http_type'] ?? '', 'codepay');
        if ($callback === '') { return false; }
        $pay_type = 1;
        if (!empty($param['paytype'])) {
            $pay_type = intval($param['paytype']);
        }
    
        // Assemble parameters
        $data = array(
            "id" => trim($GLOBALS['config']['pay']['codepay']['appid']), // Your CodePay ID
            "type" => $pay_type, // 1 for Alipay, 3 for WeChat Pay, 2 for QQ Wallet
            "price" => $order['order_price'], // Amount, e.g., 100 yuan
            "pay_id" => $order['order_code'], // Order reference returned in the payment notification.
            "notify_url" => $callback, // Notification URL
            "return_url" => $callback, // Return URL
            "debug" => 1, // Set to 1 if the software is not started
            "ac" => trim($GLOBALS['config']['pay']['codepay']['ac']), // Instant payment and collection, default is 1; collection requires application
            "param" => "", // Custom parameters
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

        $query = $urls . '&sign='.md5($sign.trim( $GLOBALS['config']['pay']['codepay']['appkey'] )); // Append the MD5 signature to the order request parameters.
        $url = "https://api.xiuxiu888.com/creat_order/?{$query}"; // Payment page

        mac_redirect($url);
    }

    public function notify()
    {
        $param = $_POST;
        // pay_id: payer identifier or order reference.
        // pay_no: payment transaction number; an empty value indicates no successful payment.
        // money: payment amount.
        // param: custom parameter.

        unset($param['/payment/notify/pay_type/codepay']);
        unset($param['paytype']);

        ksort($param); // Sort the notification parameters by key.
        reset($param); // Reset the array pointer to the first parameter.
        $sign = '';
        foreach ($param as $key => $val) {
            if ($val == '') continue; // Skip empty values
            if ($key != 'sign') { // Exclude the signature field from the signature input.
                $sign .= "$key=$val&"; // Append a key=value pair followed by &.
            }
        }

        $GLOBALS['config']['pay'] = config('maccms.pay');

        if (!$param['pay_no'] || md5(substr($sign,0,-1).trim( $GLOBALS['config']['pay']['codepay']['appkey'])) != $param['sign']) {
            echo 'fail';
        }
        else{
            $res = model('Order')->notify($param['pay_id'],'codepay');
            if($res['code'] >1){
                echo 'fail2';
            }
            else {
                echo 'success';
            }
        }
    }
}
