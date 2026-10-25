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
namespace app\common\extend\upload;

class Alibaba
{
    public $name = 'Alibaba Cloud Storage';
    public $ver = '1.0';
    private $config = [];

    public function __construct($config = []) {
        $this->config = $config;
    }

    public function submit($file_path)
    {
        $filePath = ROOT_PATH . $file_path;

        $url = 'https://kfupload.alibaba.com/kupload';
        $data = [];
        $data['scene'] = 'aeMessageCenterV2ImageRule';
        $data['name'] = 'player.jpg';
        if (class_exists('CURLFile')) {
            $data['file'] = new \CURLFile(realpath($file_path));
        } else {
            $data['file'] = '@'.realpath($file_path);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        $httpheader[] = "Accept:application/json";
        $httpheader[] = "Accept-Encoding:gzip,deflate,sdch";
        $httpheader[] = "Accept-Language:en-US,en;q=0.8";
        $httpheader[] = "Connection:close";
        $ip = mt_rand(48, 140) . "." . mt_rand(10, 240) . "." . mt_rand(10, 240) . "." . mt_rand(10, 240); // Random IP address for the request headers.
        $httpheader[] = 'CLIENT-IP:' . $ip;
        $httpheader[] = 'X-FORWARDED-FOR:' . $ip;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Dalvik/2.1.0 (Linux; U; Android 10; ONEPLUS A5010 Build/QKQ1.191014.012)');
        curl_setopt($ch, CURLOPT_ENCODING, "gzip");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $html = @curl_exec($ch);
        curl_close($ch);
        $json = @json_decode($html,true);

        if($json['code']=='0'){
            $file_path = $json['url'];
            empty($this->config['keep_local']) && @unlink($filePath);
        }

        return $file_path;
    }
}
