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

/** WeChat Pay v2 unifiedorder transport; never follows a supplied URL. */
class PayTransport
{
    const ENDPOINT = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
    const MAX_BODY = 65536;

    public static function options($xml, callable $writer)
    {
        $options = [CURLOPT_URL=>self::ENDPOINT, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$xml, CURLOPT_HTTPHEADER=>['Content-Type: text/xml; charset=UTF-8','Accept: text/xml'],
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0, CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADER=>false, CURLOPT_WRITEFUNCTION=>$writer];
        if (defined('CURLOPT_PROTOCOLS_STR')) { $options[constant('CURLOPT_PROTOCOLS_STR')] = 'https'; }
        else { $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS; }
        return $options;
    }

    public static function post($xml)
    {
        if (!is_string($xml) || strlen($xml)>1048576 || !function_exists('curl_init') || !function_exists('simplexml_load_string')) { return false; }
        $curl = curl_init();
        if ($curl === false) { return false; }
        $body = ''; $overflow = false;
        try {
            $writer = function ($handle, $chunk) use (&$body, &$overflow) {
                if (strlen($body)+strlen($chunk)>self::MAX_BODY) { $overflow=true; return 0; }
                $body.=$chunk; return strlen($chunk);
            };
            if (!curl_setopt_array($curl, self::options($xml, $writer))) { return false; }
            $result = curl_exec($curl);
            if ($result === false || $overflow || curl_errno($curl) || (int)curl_getinfo($curl,CURLINFO_HTTP_CODE)!==200) { return false; }
            return self::parseResponse($body);
        } finally {
            if (is_resource($curl)) { curl_close($curl); }
            unset($curl);
        }
    }

    public static function parseResponse($body)
    {
        // The v2 protocol is UTF-8. Refuse other byte encodings before scanning
        // declaration keywords; otherwise UTF-16/32 can bypass ASCII DTD guards.
        if (!is_string($body) || $body==='' || strlen($body)>self::MAX_BODY
            || strpos($body, "\0")!==false || preg_match('//u', $body)!==1) { return false; }
        if (preg_match('/\A(?:\xEF\xBB\xBF)?[ \t\r\n]*<\?xml\b([^?]*)\?>/i', $body, $declaration)
            && preg_match('/\bencoding\s*=\s*([\'"])(.*?)\1/is', $declaration[1], $encoding)
            && strcasecmp($encoding[2], 'UTF-8')!==0) { return false; }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $body)) { return false; }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET|LIBXML_NOCDATA);
            if ($xml === false || $xml->getName()!=='xml' || count($xml->attributes())>0) { return false; }
            $result = [];
            foreach ($xml->children() as $key=>$value) {
                if (count($value->children()) || count($value->attributes()) || array_key_exists($key,$result)) { return false; }
                $result[$key] = (string)$value;
            }
            return $result;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }
}
