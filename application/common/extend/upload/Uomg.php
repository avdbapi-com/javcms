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
namespace app\common\extend\upload;

class Uomg
{
    public $name = 'Uomg image hosting';
    public $ver = '1.0';
    private $config = [];

    public function __construct($config = []) {
        $this->config = $config;
    }

    public function submit($file_path)
    {
        $type = $GLOBALS['config']['upload']['api']['uomg']['type'];
        $openid = $GLOBALS['config']['upload']['api']['uomg']['openid'];
        $key = $GLOBALS['config']['upload']['api']['uomg']['key'];
        if(empty($type)){
            $type = 'ali';
        }
        $filePath = ROOT_PATH . $file_path;

        $url = 'https://api.uomg.com/api/image.'.$type;
        $data = [];
        // $data['imgurl'] = 'https://example.com/images/sample.jpg';
        $data['file'] = 'multipart';

        if (class_exists('CURLFile')) {
            $data['Filedata'] = new \CURLFile(realpath($file_path));
        } else {
            $data['Filedata'] = '@'.realpath($file_path);
        }

        $html = mac_curl_post($url,$data);
        $json = @json_decode($html,true);
        if($json['code']=='1'){
            $file_path = $json['imgurl'];
            empty($this->config['keep_local']) && @unlink($filePath);
        }

        return $file_path;
    }
}