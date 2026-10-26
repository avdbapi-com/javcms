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
