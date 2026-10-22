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
namespace app\common\extend\editor;

class Ckeditor {

    public $name = 'Ckeditor';
    public $ver = '1.0';

    public function front($param)
    {

    }

    public function back($info='',$status=0,$data=[])
    {
        $arr=[];
        if ($status == 1) {
            $arr['uploaded'] = 1;
            $arr['fileName'] = '';
            $arr['url'] = $data['file'];
            // echo \'<script type=\"text/javascript\">window.parent.CKEDITOR.tools.callFunction(1, \"\'.$data[\'file\'].\'\", \"\");</script>\';
        } else {
            $arr['uploaded'] = 0;
            $arr['error']['msg'] = $info;
            // echo \'<script type=\"text/javascript\">window.parent.CKEDITOR.tools.callFunction(1, \"\", \"\'.$info.\'\");</script>\';
        }
        echo json_encode($arr, 1);
        exit;
    }
}
