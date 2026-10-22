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
namespace app\index\controller;
use think\Controller;

class Map extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $template = 'map/index';
        $suffix = ltrim((string)config('template.view_suffix'), '.') ?: 'html';
        if (!is_file(MAC_ROOT_TEMPLATE . $template . '.' . $suffix)
            && is_file(MAC_ROOT_TEMPLATE . 'public/map.' . $suffix)) {
            $template = 'public/map';
        }
        return $this->label_fetch($template);
    }

}
