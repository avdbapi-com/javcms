<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

/** Compatible QQ/WeChat website login configuration and one-use session state. */
class ConnectSettings
{
    const STATE_TTL = 600;
    const BODY_LIMIT = 65536;

    public static function merge(array $old, array $posted)
    {
        foreach (['qq', 'weixin'] as $provider) {
            if (!array_key_exists($provider, $posted)) { continue; }
            if (!is_array($posted[$provider])) { throw new \InvalidArgumentException('admin/connect/invalid_config'); }
            foreach (['status', 'key', 'secret'] as $field) {
                if (!array_key_exists($field, $posted[$provider])) { continue; }
                $value = $posted[$provider][$field];
                if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)
                    || ($field === 'status' && !in_array($value, ['0', '1'], true))) {
                    throw new \InvalidArgumentException('admin/connect/invalid_config');
                }
            }
        }
        $merged = array_replace_recursive($old, $posted);
        foreach (['qq', 'weixin'] as $provider) {
            if ((string)($merged[$provider]['status'] ?? '0') === '1') { self::credentials($provider, $merged); }
        }
        return $merged;
    }

    public static function credentials($provider, ?array $connect = null)
    {
        if (!in_array($provider, ['qq','weixin'], true)) { throw new \InvalidArgumentException('param_err'); }
        $connect = $connect === null ? (array)config('maccms.connect') : $connect;
        $row = $connect[$provider] ?? [];
        if (!is_array($row) || (string)($row['status'] ?? '0') !== '1') { throw new \RuntimeException('admin/connect/disabled'); }
        foreach (['key','secret'] as $field) {
            if (!isset($row[$field]) || !is_string($row[$field]) || trim($row[$field]) === '' || strlen($row[$field]) > 4096
                || preg_match('/[\x00-\x1f\x7f]/', $row[$field])) { throw new \RuntimeException('admin/connect/missing_credentials'); }
        }
        return $row;
    }

    public static function available()
    {
        return class_exists('login\\ThinkOauth') && class_exists('login\\sdk\\QqSDK') && class_exists('login\\sdk\\WeixinSDK')
            && function_exists('curl_init') && defined('CURL_VERSION_SSL') && (curl_version()['features'] & CURL_VERSION_SSL);
    }

    public static function callback($provider)
    {
        if (!in_array($provider, ['qq','weixin'], true)) { throw new \InvalidArgumentException('param_err'); }
        $root = defined('MAC_PATH') ? MAC_PATH : '/';
        $url = UrlSettings::canonicalUrl($GLOBALS['config']['site']['site_url'] ?? '', $root.'index.php/user/logincallback/type/'.$provider, $GLOBALS['http_type'] ?? 'http://');
        if ($url === '') { throw new \RuntimeException('admin/connect/invalid_callback'); }
        return $url;
    }

    public static function issueState($provider, $callback)
    {
        self::credentials($provider);
        if ($callback !== self::callback($provider)) { throw new \RuntimeException('admin/connect/invalid_callback'); }
        $state = bin2hex(random_bytes(32));
        session('oauth_flow_'.$provider, ['digest'=>hash('sha256',$state), 'created'=>time(), 'user'=>(int)($GLOBALS['user']['user_id'] ?? 0),
            'callback'=>$callback, 'app_key'=>hash('sha256', self::credentials($provider)['key'])]);
        return $state;
    }

    public static function consumeState($provider, $state)
    {
        $credentials = self::credentials($provider);
        $flow = session('oauth_flow_'.$provider);
        if (!is_string($state) || !preg_match('/^[a-f0-9]{64}$/D', $state) || !is_array($flow)
            || !isset($flow['digest'], $flow['created'], $flow['user'], $flow['callback'], $flow['app_key'])
            || !is_string($flow['digest']) || !hash_equals($flow['digest'], hash('sha256',$state))) {
            throw new \RuntimeException('admin/connect/invalid_state');
        }
        // Consume before any exchange, including a failed provider response.
        session('oauth_flow_'.$provider, null);
        if (!is_int($flow['created']) || $flow['created'] > time() || time()-$flow['created'] > self::STATE_TTL
            || $flow['user'] !== (int)($GLOBALS['user']['user_id'] ?? 0)
            || !is_string($flow['app_key']) || !hash_equals($flow['app_key'], hash('sha256',$credentials['key']))
            || $flow['callback'] !== self::callback($provider)) { throw new \RuntimeException('admin/connect/invalid_state'); }
        return $flow;
    }

    public static function transport($url, array $params, $method = 'GET')
    {
        // Endpoint paths are fixed, so credentials/tokens cannot reach a configured URL.
        $allowed = ['https://graph.qq.com/oauth2.0/token', 'https://graph.qq.com/oauth2.0/me',
            'https://graph.qq.com/user/get_user_info', 'https://api.weixin.qq.com/sns/oauth2/access_token', 'https://api.weixin.qq.com/sns/userinfo'];
        if (!in_array($url, $allowed, true) || !in_array($method, ['GET','POST'], true)) { throw new \RuntimeException('admin/connect/provider_failed'); }
        if (!function_exists('curl_init')) { throw new \RuntimeException('admin/connect/unavailable'); }
        $body=''; $encoded=http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $curl=curl_init();
        if ($curl === false) { throw new \RuntimeException('admin/connect/provider_failed'); }
        try {
            $opts=[CURLOPT_URL=>$url.($method==='GET'?'?'.$encoded:''), CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>10,
                CURLOPT_RETURNTRANSFER=>false, CURLOPT_WRITEFUNCTION=>function($handle,$chunk) use (&$body) {
                    if (strlen($body)+strlen($chunk)>self::BODY_LIMIT) { return 0; }
                    $body.=$chunk; return strlen($chunk);
                }];
            if (defined('CURLOPT_PROTOCOLS_STR')) { $opts[CURLOPT_PROTOCOLS_STR]='https'; }
            else { $opts[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS; }
            if ($method==='POST') { $opts[CURLOPT_POST]=true; $opts[CURLOPT_POSTFIELDS]=$encoded; }
            if (!curl_setopt_array($curl,$opts) || curl_exec($curl)===false || (int)curl_getinfo($curl,CURLINFO_HTTP_CODE)!==200) {
                throw new \RuntimeException('admin/connect/provider_failed');
            }
            return $body;
        } finally {
            // PHP 7.4 resources require close; PHP 8 CurlHandle objects close on destruction.
            if (is_resource($curl)) { curl_close($curl); }
            unset($curl);
        }
    }

    public static function validOpenid($value)
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$value);
    }

    public static function errorKey(\Throwable $error)
    {
        $key=$error->getMessage();
        return in_array($key,['param_err','admin/connect/disabled','admin/connect/missing_credentials','admin/connect/invalid_callback',
            'admin/connect/invalid_state','admin/connect/unavailable','admin/connect/provider_failed'],true) ? $key : 'admin/connect/provider_failed';
    }

    public static function token(array $data, $requireOpenid = false)
    {
        if (!isset($data['access_token'],$data['expires_in']) || !is_string($data['access_token']) || $data['access_token']===''
            || strlen($data['access_token'])>4096 || preg_match('/[\x00-\x20\x7f]/',$data['access_token'])
            || (!is_int($data['expires_in']) && !is_string($data['expires_in'])) || !ctype_digit((string)$data['expires_in']) || (int)$data['expires_in']<1
            || ($requireOpenid && !self::validOpenid($data['openid'] ?? null))) { throw new \RuntimeException('admin/connect/provider_failed'); }
        return $data;
    }
}
