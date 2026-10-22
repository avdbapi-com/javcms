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
namespace app\common\util;

class SinaUpload
{
    public $_config=[];

    public function __construct($config=array()){
        $this->config($config);
    }

    public function config($config=array()){
        $this->_config = array_merge($this->_config, $config);
    }

    function check()
    {
        if (time() - $this->_config['time'] >20*3600 || $this->_config['cookie']=='SUB;' || $this->_config['cookie']==''){
            $cookie = self::login($this->_config['user'],$this->_config['pwd']);
            if($cookie && $cookie!='SUB;'){
                $this->_config['cookie'] = $cookie;
                $this->_config['time'] = time();

                $config_old = config('maccms');

                $config_new['upload']=$config_old['upload'];
                $config_new['upload']['api']['weibo'] = $this->_config;
                $config_new = array_merge($config_old, $config_new);

                $res = mac_arr2file(APP_PATH . 'extra/maccms.php', $config_new);
                if ($res === false) {
                    return ['code'=>'202','msg'=>'Unable to save the Weibo login state.'];
                }

            }else{
                return ['code'=>'203','msg'=>'Unable to retrieve the Sina Weibo login cookie. Check the account status or sign in again.'];
            }
        }
        return ['code'=>'1','msg'=>'ok'];
    }

    function login($u,$p){
        $loginUrl = 'https://login.sina.com.cn/sso/login.php?client=ssologin.js(v1.4.15)&_=1403138799543';
        $loginData['entry'] = 'sso';
        $loginData['gateway'] = '1';
        $loginData['from'] = 'null';
        $loginData['savestate'] = '30';
        $loginData['useticket'] = '0';
        $loginData['pagerefer'] = '';
        $loginData['vsnf'] = '1';
        $loginData['su'] = base64_encode($u);
        $loginData['service'] = 'sso';
        $loginData['sp'] = $p;
        $loginData['sr'] = '1920*1080';
        $loginData['encoding'] = 'UTF-8';
        $loginData['cdult'] = '3';
        $loginData['domain'] = 'sina.com.cn';
        $loginData['prelt'] = '0';
        $loginData['returntype'] = 'TEXT';
        return self::loginPost($loginUrl,$loginData);
    }

    /* *
 * Send a Weibo login request and extract the session cookie.
 * @param string $url Login endpoint URL
 * @param array|string $data Form fields or a prepared request body
 * @return string SUB cookie string, or SUB; when no cookie is found */
    function loginPost($url,$data){
        $tmp = '';
        if(is_array($data)){
            foreach($data as $key =>$value){
                $tmp .= $key."=".$value."&";
            }
            $post = trim($tmp,"&");
        }else{
            $post = $data;
        }
        $ch = curl_init();
        curl_setopt($ch,CURLOPT_URL,$url);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch,CURLOPT_HEADER,1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch,CURLOPT_POST,1);
        curl_setopt($ch,CURLOPT_POSTFIELDS,$post);
        $return = curl_exec($ch);
        curl_close($ch);
        return 'SUB' . self::getSubstr($return,"Set-Cookie: SUB",'; ') . ';';
    }

    /* *
 * Extract the text between two delimiters. */
    function getSubstr($str,$leftStr,$rightStr){
        $left = strpos($str, $leftStr);
        // echo \'Left side: \'. $left;
        $right = strpos($str, $rightStr,$left);
        // echo \'<br>right:\'.$right;
        if($left <= 0 or $right < $left) return '';
        return substr($str, $left + strlen($leftStr), $right-$left-strlen($leftStr));
    }


    function upload($file, $multipart = true,$cookie) {
        $url = 'http://picupload.service.weibo.com/interface/pic_upload.php'.'?mime=image%2Fjpeg&data=base64&url=0&markpos=1&logo=&nick=0&marks=1&app=miniblog';
        if($multipart) {
            $url .= '&cb=http://weibo.com/aj/static/upimgback.html?_wv=5&callback=STK_ijax_'.time();
            if (class_exists('CURLFile')) {     // php 5.5
                $post['pic1'] = new \CURLFile(realpath($file));
            } else {
                $post['pic1'] = '@'.realpath($file);
            }
        } else {
            $post['b64_data'] = base64_encode(file_get_contents($file));
        }
        // Submit the upload with cURL.
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_VERBOSE => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array("Cookie: $cookie"),
            CURLOPT_POSTFIELDS => $post,
        ));
        $output = curl_exec($ch);
        curl_close($ch);
        // Extract JSON from the upload response.
        preg_match('/({.*)/i', $output, $match);
        if(!isset($match[1])) return ['code'=>'301','msg'=>'Upload failed.'];
        $a=json_decode($match[1],true);
        $width = $a['data']['pics']['pic_1']['width'];
        $size = $a['data']['pics']['pic_1']['size'];
        $height = $a['data']['pics']['pic_1']['height'];
        $pid = $a['data']['pics']['pic_1']['pid'];
        if(!$pid){return ['code'=>'202','msg'=>'Upload failed.']; }
        $size = 'large';
        if(!empty($GLOBALS['config']['upload']['api']['weibo']['size'])){
            $size = $GLOBALS['config']['upload']['api']['weibo']['size'];
        }
        return ['code'=>'200','width'=>$width,"height"=>$height,"size"=>$size,"pid"=>$pid,"url"=>"http://ws3.sinaimg.cn/".$size."/".$pid.".jpg"];
    }
}