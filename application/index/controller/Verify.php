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
use think\captcha\Captcha;
use think\Config;
use think\Controller;

class Verify extends Controller
{
    public function __construct()
    {
        \app\common\util\PublicRequestGuard::enforceRoute(
            ROOT_PATH, request()->controller(), request()->action()
        );
        parent::__construct();
    }

    public function index($id='')
    {
        mac_no_cahche();
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        $captcha = new Captcha((array)Config::get('captcha'));
        return $captcha->entry($id);
    }

    public function check($verify,$id='')
    {
        mac_no_cahche();
        if(!captcha_check($verify,$id)){
            return 0;
        }else{
            return 1;
        }
    }

}
