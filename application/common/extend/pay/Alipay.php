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

class Alipay {

    public $name = 'Alipay';
    public $ver = '1.0';
    
    public function submit($user, $order, $param)
    {
        $callback = \app\common\util\PaySettings::callbackPreview($_SERVER['HTTP_HOST'] ?? '', $GLOBALS['http_type'] ?? '', 'alipay');
        if ($callback === '') { return false; }
        $data = array();
        $data['service'] = 'create_direct_pay_by_user'; // Use the instant-payment API.
        $data['payment_type'] = '1'; // Payment type: product purchase (1).
        $data['quantity'] = '1'; // Quantity
        $data['_input_charset'] = 'utf-8';
        $data['partner'] = trim($GLOBALS['config']['pay']['alipay']['appid']);
        $data['seller_email'] = trim($GLOBALS['config']['pay']['alipay']['account']);
        $data['out_trade_no'] = $order['order_code'];
        $data['notify_url'] = $callback;
        $data['return_url'] = $callback;
        $data['subject'] = 'Points Recharge (UID: '.$user['user_id'].')';
        $data['total_fee'] = sprintf("%.2f", $order['order_price']);    

        // Build the signed request parameters.
        $para = $this->buildRequestPara($data);
        $sHtml = "<form id='alipaysubmit' name='alipaysubmit' action='https://mapi.alipay.com/gateway.do?_input_charset=utf-8' method='POST'>";
        foreach ($para as $key => $val) {
            $sHtml.= "<input type='hidden' name='".htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8')."' value='".htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8')."'/>";
        }
        // Leave the submit button unnamed.
        $sHtml = $sHtml."<input type='submit' value='Submit'></form>";
        $sHtml = $sHtml."<script>document.forms['alipaysubmit'].submit();</script>";
        echo $sHtml;
        die;
    }

    public function notify()
    {
        $param = input();
        $GLOBALS['config']['pay'] = config('maccms.pay');
        unset($param['/payment/notify/pay_type/alipay']);
        unset($param['pay_type']);

        $isSign = $this->getSignVeryfy($param, $param["sign"]);
        // Check whether signature verification succeeded.
        if($isSign) {
            if ($param['trade_status'] == 'TRADE_SUCCESS') {
                $res = model('Order')->notify($param['out_trade_no'],'alipay');
                if($res['code']>1){
                    echo "fail2";
                }
                else{
                    echo "success2";
                }
            }
            else {
                echo "success";
            }
        }else{
            echo "fail";
        }
    }

    public function buildRequestPara($para_temp) {
        // Filter out empty values and signature fields.
        $para_filter = $this->paraFilter($para_temp);

        // Sort the parameters before signing.
        $para_sort = $this->argSort($para_filter);

        // Generate the signature.
        $mysign = $this->buildRequestMysign($para_sort);

        // Add the signature and signing method to the request parameters.
        $para_sort['sign'] = $mysign;
        $para_sort['sign_type'] = 'MD5';

        return $para_sort;
    }

    public function paraFilter($para) {
        $para_filter = array();
        foreach ($para as $key => $val) {
            if($key == "sign" || $key == "sign_type" || $val == "")continue;
            else	$para_filter[$key] = $para[$key];
        }
        return $para_filter;
    }

    public function argSort($para) {
        ksort($para);
        reset($para);
        return $para;
    }

    public function buildRequestMysign($para_sort) {
        // Join the parameters as key=value pairs separated by &.
        $prestr = $this->createLinkstring($para_sort);

        $mysign = $this->md5Sign($prestr, $GLOBALS['config']['pay']['alipay']['appkey']);

        return $mysign;
    }

    public function createLinkstring($para) {
        $arg  = "";
        foreach ($para as $key => $val) {
            $arg.=$key."=".$val."&";
        }
        // Remove the trailing &.
        $arg = substr($arg,0,strlen($arg)-1);

        // Strip legacy magic-quotes escaping when enabled.
        if (PHP_VERSION_ID < 80000 && function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc()) { $arg = stripslashes($arg); }

        return $arg;
    }

    public function md5Sign($prestr, $key) {
        $prestr = $prestr . $key;
        return md5($prestr);
    }

    public function getSignVeryfy($para_temp, $sign) {
        // Filter out empty values and signature fields.
        $para_filter = $this->paraFilter($para_temp);

        // Sort the parameters before verification.
        $para_sort = $this->argSort($para_filter);

        // Join the parameters as key=value pairs separated by &.
        $prestr = $this->createLinkstring($para_sort);

        $isSgin = false;
        $isSgin = $this->md5Verify($prestr, $sign, $GLOBALS['config']['pay']['alipay']['appkey']);

        return $isSgin;
    }

    public function md5Verify($prestr, $sign, $key) {
        $prestr = $prestr . $key;
        $mysgin = md5($prestr);
        if($mysgin == $sign) {
            return true;
        }
        else {
            return false;
        }
    }
}
