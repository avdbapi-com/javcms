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
use \think\Request;

class Payment extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function notify()
    {
        if (Request()->isPost()) {
            $param = input();
            $pay_type = isset($param['pay_type']) && is_scalar($param['pay_type']) ? strtolower(trim((string)$param['pay_type'])) : '';
            // Only an offered gateway with saved credentials: the China-only adapters stay disabled, as in the admin
            // (application/admin/util/GlobalModules.php), and an unknown name never reaches the config or the autoloader.
            $settings = isset($GLOBALS['config']['pay'][$pay_type]) && is_array($GLOBALS['config']['pay'][$pay_type]) ? $GLOBALS['config']['pay'][$pay_type] : [];
            if (!preg_match('/\A[a-z][a-z0-9_]*\z/', $pay_type) || \app\admin\util\GlobalModules::blocked('pay', $pay_type) || empty($settings['appid'])) {
                echo lang('index/payment_status');
                exit;
            }

            $cp = 'app\\common\\extend\\pay\\' . ucfirst($pay_type);
            if (class_exists($cp)) {
                $c = new $cp;
                $c->notify();
            }
            else{
                echo lang('index/payment_not');
                exit;
            }
        }
        else{
            return $this->success(lang('index/payment_ok'), url('user/index') );
        }
    }
}
