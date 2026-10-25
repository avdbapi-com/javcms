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
namespace app\common\util;

/** Local configuration validation and bounded transport for existing SMS protocols. */
class SmsSettings
{
    private static function scalar($value)
    {
        if (!is_scalar($value) || is_bool($value) || strlen((string)$value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', (string)$value)) {
            throw new \InvalidArgumentException('admin/smssetup/invalid_settings');
        }
        return (string)$value;
    }

    public static function merge(array $old, array $posted)
    {
        foreach (['type', 'sign', 'tpl_code_reg', 'tpl_code_bind', 'tpl_code_findpass'] as $field) {
            if (array_key_exists($field, $posted)) { $posted[$field] = self::scalar($posted[$field]); }
        }
        foreach (['aliyun', 'qcloud'] as $provider) {
            if (!array_key_exists($provider, $posted)) { continue; }
            if (!is_array($posted[$provider])) { throw new \InvalidArgumentException('admin/smssetup/invalid_settings'); }
            foreach (['appid', 'appkey'] as $field) {
                if (array_key_exists($field, $posted[$provider])) { $posted[$provider][$field] = self::scalar($posted[$provider][$field]); }
            }
        }
        $next = array_replace_recursive($old, $posted);
        $type = strtolower(self::scalar($next['type'] ?? ''));
        if (!isset($next['type'])) { $next['type'] = ''; }
        if ($type !== '' && !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $type)) { throw new \InvalidArgumentException('admin/smssetup/invalid_settings'); }
        if (in_array($type, ['aliyun', 'qcloud'], true)) {
            foreach (['appid', 'appkey'] as $field) {
                if (!isset($next[$type][$field]) || trim(self::scalar($next[$type][$field])) === '') { throw new \InvalidArgumentException('admin/smssetup/credentials_required'); }
            }
            if (trim(self::scalar($next['sign'] ?? '')) === '') { throw new \InvalidArgumentException('admin/smssetup/credentials_required'); }
            if ($type === 'qcloud' && !preg_match('/^[0-9]{1,32}$/D', $next[$type]['appid'])) { throw new \InvalidArgumentException('admin/smssetup/invalid_settings'); }
        }
        return $next;
    }

    public static function sendReady($provider, $flow)
    {
        try {
            $settings = $GLOBALS['config']['sms'] ?? [];
            if (!is_array($settings) || !in_array($provider, ['qcloud','aliyun'], true) || !in_array($flow, ['reg','bind','findpass'], true)) { return false; }
            $settings = self::merge($settings, ['type' => $provider]);
            return true;
        } catch (\InvalidArgumentException $error) { return false; }
    }

    public static function available()
    {
        if (!function_exists('curl_init') || !defined('CURL_VERSION_SSL')) { return false; }
        $version = curl_version();
        return isset($version['features']) && ($version['features'] & CURL_VERSION_SSL) !== 0;
    }

    public static function providerResult($response, $provider)
    {
        if ($provider === 'qcloud' && is_string($response)) { $response = json_decode($response, true); }
        $ok = is_array($response) && ($provider === 'qcloud'
            ? isset($response['result']) && is_int($response['result']) && $response['result'] === 0
            : isset($response['Code']) && $response['Code'] === 'OK');
        if ($ok) { return ['code' => 1, 'msg' => lang('admin/smssetup/accepted')]; }
        $message = is_array($response) ? ($provider === 'qcloud' ? ($response['errmsg'] ?? '') : ($response['Message'] ?? '')) : '';
        if (!is_string($message) || $message === '') { $message = lang('admin/smssetup/send_failed'); }
        else {
            $message = preg_replace('/[\x00-\x1f\x7f]/', ' ', strip_tags($message));
            foreach (['aliyun', 'qcloud'] as $name) {
                $secret = $GLOBALS['config']['sms'][$name]['appkey'] ?? '';
                if (is_string($secret) && $secret !== '') { $message = str_replace($secret, '[redacted]', $message); }
            }
            $message = substr($message, 0, 400);
            while ($message !== '' && !preg_match('//u', $message)) { $message = substr($message, 0, -1); }
        }
        return ['code' => 101, 'msg' => $message];
    }

    /** Only fixed provider endpoints; no redirects, retries or charged health checks. */
    public static function http($url, $method, $body, $json = false)
    {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || !in_array($method, ['POST', 'GET'], true)
            || !(($parts['host'] ?? '') === 'dysmsapi.aliyuncs.com' && ($parts['path'] ?? '') === '/'
                || ($parts['host'] ?? '') === 'yun.tim.qq.com' && ($parts['path'] ?? '') === '/v5/tlssmssvr/sendsms')) {
            throw new \RuntimeException('admin/smssetup/transport_failed');
        }
        if (!self::available()) { throw new \RuntimeException('admin/smssetup/unavailable'); }
        $handle = curl_init();
        if (!$handle) { throw new \RuntimeException('admin/smssetup/transport_failed'); }
        $response = '';
        try {
            if ($method === 'GET') { $url .= (strpos($url, '?') === false ? '?' : '&') . $body; }
            $options = [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => false, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded')],
                CURLOPT_WRITEFUNCTION => function ($unused, $chunk) use (&$response) {
                    if (strlen($response) + strlen($chunk) > 65536) { return 0; }
                    $response .= $chunk;
                    return strlen($chunk);
                }];
            $options[defined('CURLOPT_PROTOCOLS_STR') ? CURLOPT_PROTOCOLS_STR : CURLOPT_PROTOCOLS] = defined('CURLOPT_PROTOCOLS_STR') ? 'https' : CURLPROTO_HTTPS;
            if ($method === 'POST') { $options[CURLOPT_POST] = true; $options[CURLOPT_POSTFIELDS] = $body; }
            if (!curl_setopt_array($handle, $options) || curl_exec($handle) === false || (int)curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) {
                throw new \RuntimeException('admin/smssetup/transport_failed');
            }
            return $response;
        } finally {
            if (is_resource($handle)) { curl_close($handle); }
            unset($handle);
        }
    }
}
