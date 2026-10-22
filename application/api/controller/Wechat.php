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
namespace app\api\controller;

/**
 * WeChat official-account callback (a China-only service). JAVCMS ships without China services, so this public
 * endpoint is switched off whatever the old weixin setting says: it answers 404 and never parses request bodies.
 * The original handler (app\common\util\WechatPublic) stays in the code base for a later opt-in build.
 */
class Wechat extends \think\Controller
{
    public function index()
    {
        return response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
