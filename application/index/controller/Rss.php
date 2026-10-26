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

class Rss extends Base
{
    public function index()
    {
        // label_fetch() answers the feed as application/rss+xml, cached copies too (All::load_page_cache()).
        return $this->label_fetch('rss/index');
    }

    // Sitemap aliases kept for URLs submitted earlier: /rss/google.xml and /rss/bing.xml. The rss/baidu, rss/sogou,
    // rss/so and rss/sm aliases were removed (SEO-ADMIN-BRIEF E.1, HO-S8): those URLs now answer 404.
    public function google()
    {
        return (new Sitemap())->index();
    }

    public function bing()
    {
        return (new Sitemap())->index();
    }

}
