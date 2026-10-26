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

use app\common\util\SinaUpload as suOper;

class Weibo
{
    public $name = 'Sina image hosting';
    public $ver = '1.0';
    private $config = [];

    public function __construct($config = []) {
        $this->config = $config;
    }

    public function submit($file_path)
    {
        $weibo =  new suOper();

        $weibo->config($GLOBALS['config']['upload']['api']['weibo']);
        $res = $weibo->check();

        if($res['code']>1){
            echo $res['msg'];die;
        }
        $res = $weibo->upload($file_path,false,$weibo->_config['cookie']);
        if(!empty($res['url'])){
            return $res['url'];
        }
        return $file_path;
    }
}