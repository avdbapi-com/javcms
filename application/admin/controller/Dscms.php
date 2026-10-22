<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\admin\controller;

/**
 * The "Dscms" theme panel of the original archive (Taobao affiliate, live-stream links, ad slots)
 * is China-specific and contacted api.cgzyb.com with the site's domain on every visit.
 * It is not part of the global JAVCMS distribution.
 */
class Dscms extends Base
{
    public function configweixin()
    {
        return $this->error(lang('admin/module_removed'));
    }
}
