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
namespace app\common\extend\upload;

use app\common\util\Ftp as ftpOper;

class Ftp
{
    public $name = 'FTP storage';
    public $ver = '1.0';
    private $config = [];

    public function __construct($config = []) {
        $this->config = $config;
    }

    public function submit($file_path)
    {
        $ftp = new ftpOper();
        $ftp_config = [
            'ftp_host'=>$GLOBALS['config']['upload']['api']['ftp']['host'],
            'ftp_port'=>$GLOBALS['config']['upload']['api']['ftp']['port'],
            'ftp_user'=>$GLOBALS['config']['upload']['api']['ftp']['user'],
            'ftp_pwd' =>$GLOBALS['config']['upload']['api']['ftp']['pwd'],
            'ftp_dir'=>$GLOBALS['config']['upload']['api']['ftp']['path'],
        ];
        $ftp->config($ftp_config);
        $ftp->connect();
        $a = $ftp->put(ROOT_PATH. $file_path, $file_path);
        $filePath = ROOT_PATH . $file_path;
        empty($this->config['keep_local']) && @unlink($filePath);
        return $GLOBALS['config']['upload']['api']['ftp']['url'] . '/' . $file_path;
    }
}