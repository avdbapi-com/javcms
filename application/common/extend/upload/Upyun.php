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

use Upyun\Upyun as upOper;
use Upyun\Config;

class Upyun
{
    public $name = 'Upyun storage';
    public $ver = '1.0';
    private $config = [];

    public function __construct($config = []) {
        $this->config = $config;
    }

    public function submit($file_path)
    {
        $bucket = $GLOBALS['config']['upload']['api']['upyun']['bucket'];
        $username = $GLOBALS['config']['upload']['api']['upyun']['username'];
        $pwd = $GLOBALS['config']['upload']['api']['upyun']['pwd'];

        require_once ROOT_PATH . 'extend/upyun/vendor/autoload.php';
        $bucketConfig = new Config($bucket, $username, $pwd);
        $client = new upOper($bucketConfig);
        $_file = fopen($file_path, 'r');
        $a = $client->write($file_path, $_file);
        $filePath = ROOT_PATH . $file_path;
        unset($_file);
        empty($this->config['keep_local']) && @unlink($filePath);
        return $GLOBALS['config']['upload']['api']['upyun']['url'] . '/' . $file_path;
    }
}