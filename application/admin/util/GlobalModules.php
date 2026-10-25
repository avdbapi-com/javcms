<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\admin\util;

use think\View;

/**
 * China-only services that the global JAVCMS distribution does not offer (removal approved by
 * the project owner on 2026-10-08). Their adapter code under application/common/extend stays in
 * place and no database table is dropped; the admin no longer lists them and refuses to enable them.
 *
 *  - pay:     Alipay, WeChat Pay, Codepay, Zhapay
 *  - sms:     Aliyun SMS, Tencent Cloud SMS
 *  - urlsend: Baidu URL push (normal and fast)
 *  - upload:  Weibo, UOMG and Alibaba image beds, Qiniu, Upyun (also the legacy numeric modes 2 / 3 / 5)
 *
 * QQ/WeChat sign-in, the WeChat official-account bot and the Dscms/Dianying theme panels are
 * disabled in their controllers.
 */
class GlobalModules
{
    const BLOCKED = [
        'pay' => ['alipay', 'weixin', 'codepay', 'zhapay'],
        'sms' => ['aliyun', 'qcloud'],
        'urlsend' => ['baidu', 'baidufast'],
        'upload' => ['weibo', 'uomg', 'qiniu', 'upyun', 'alibaba'],
    ];

    /** Legacy numeric upload modes of old MacCMS configs (common/model/Upload.php maps them the same way). */
    const UPLOAD_LEGACY = ['2' => 'upyun', '3' => 'qiniu', '4' => 'ftp', '5' => 'weibo'];

    public static function blocked($flag, $name)
    {
        $name = is_scalar($name) ? strtolower(trim((string)$name)) : '';
        if ($flag === 'upload' && isset(self::UPLOAD_LEGACY[$name])) {
            $name = self::UPLOAD_LEGACY[$name];
        }
        return isset(self::BLOCKED[$flag]) && in_array($name, self::BLOCKED[$flag], true);
    }

    /** mac_extends_list() without the blocked providers. */
    public static function extendsList($flag)
    {
        $result = ['ext_list' => [], 'ext_html' => ''];
        if (!preg_match('/\A[a-z]+\z/', (string)$flag)) {
            return $result;
        }
        foreach (glob(APP_PATH . 'common' . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . $flag . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $class = basename($file, '.php');
            if (!preg_match('/\A[A-Za-z0-9_]+\z/', $class) || self::blocked($flag, $class)) {
                continue;
            }
            $fqcn = 'app\\common\\extend\\' . $flag . '\\' . $class;
            if (!class_exists($fqcn)) {
                continue;
            }
            $adapter = new $fqcn;
            $result['ext_list'][$class] = $adapter->name;
            $view = APP_PATH . 'admin' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . $flag . DIRECTORY_SEPARATOR . strtolower($class) . '.html';
            if (is_file($view)) {
                $result['ext_html'] .= View::instance()->fetch('admin@extend/' . $flag . '/' . strtolower($class));
            }
        }
        return $result;
    }

    /** Remove blocked provider sections from a posted settings array (e.g. $post['pay']). */
    public static function stripBlocked($flag, array $settings)
    {
        foreach (self::BLOCKED[$flag] ?? [] as $name) {
            unset($settings[$name]);
        }
        return $settings;
    }
}
