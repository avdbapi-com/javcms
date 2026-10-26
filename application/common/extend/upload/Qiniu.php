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

use Qiniu\Auth;
use Qiniu\Storage\UploadManager;

class Qiniu
{
    public $name = 'Qiniu Cloud Storage';
    public $ver = '1.0';
    private $config = [];

    public function __construct($config = []) {
        $this->config = $config;
    }

    public function submit($file_path)
    {
        $bucket = $GLOBALS['config']['upload']['api']['qiniu']['bucket'];
        $accessKey = $GLOBALS['config']['upload']['api']['qiniu']['accesskey'];
        $secretKey = $GLOBALS['config']['upload']['api']['qiniu']['secretkey'];

        require_once ROOT_PATH . 'extend/qiniu/autoload.php';
        $auth = new Auth($accessKey, $secretKey);
        $return = '{"newName":"$(key)","hash":"$(etag)","fsize":$(fsize),"bucket":"$(bucket)","oldName":"$(fname)","width":"$(imageInfo.width)","height":"$(imageInfo.height)"}';
        $return = array('returnBody' => $return);
        $expires = 3600;
        $token = $auth->uploadToken($bucket,$file_path,$expires,$return);
        $filePath = ROOT_PATH . $file_path;
        $uploadMgr = new UploadManager();
        $a = $uploadMgr->putFile($token, $file_path, $filePath);
        empty($this->config['keep_local']) && @unlink($filePath);
        return $GLOBALS['config']['upload']['api']['qiniu']['url'] . '/' . $file_path;
    }
}