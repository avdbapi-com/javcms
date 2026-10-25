<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\extend\urlsend;

use think\Cache;

class Baidufast {

    public $name = 'Baidu Fast Push';
    public $ver = '1.0';

    public function submit($data)
    {
        $token = $GLOBALS['config']['urlsend']['baidufast']['token'];
        $site = trim((string)($GLOBALS['config']['site']['site_url'] ?? ''));
        if (!preg_match('/^https?:\/\//i', $site)) {
            $site = ($GLOBALS['http_type'] ?? 'http://') . $site;
        }
        $site = rtrim($site, '/');
        $api = 'http://data.zz.baidu.com/urls?site='.$site.'&token='.$token.'&type=daily';

        $head = ['Content-Type: text/plain'];
        $post = implode("\n", $data['urls']);

        $r = mac_curl_post($api, $post, $head);
        $json = json_decode($r, true);
        if (!$json) {
            return ['code' => 101, 'msg' => 'Request failed. Please try again.'];
        } elseif ($json['error']) {
            return ['code' => 102, 'msg' => 'Error occurred: ' . $json['message']];
        } elseif ($json['remain'] == 0 && $json['success'] > 0) {
            $data = array_slice($data['urls'], 0, $json['success'], true);
            $keys = array_keys($data);
            $this->_lastid = end($keys);

            $data = implode("\n", $data);
            $r = mac_curl_post($api, $data, $head);
            $json = json_decode($r, true);
            if (!$json) {
                return ['code' => 103, 'msg' => 'Request failed. Please try again. (2)'];
            } elseif ($json['error']) {
                return ['code' => 104, 'msg' => 'Error occurred (2): ' . $json['message']];
            }
        }

        return ['code' => 1, 'msg' => 'URLs remaining today: ' . $json['remain'] . '; submitted URLs: ' . $json['success'] . '.'];
    }

}
