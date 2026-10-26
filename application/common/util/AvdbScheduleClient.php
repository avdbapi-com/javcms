<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\util;

/** The managed service address is fixed; callback URLs are never fetched here. */
class AvdbScheduleClient
{
    public function register($id,$callback,$secret,$interval,$timestamp=null){return $this->send('register',$id,$callback,$secret,$interval,$timestamp);}
    public function disable($id,$callback,$secret,$interval,$timestamp=null){return $this->send('disable',$id,$callback,$secret,$interval,$timestamp);}
    private function send($action,$id,$callback,$secret,$interval,$timestamp)
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('Managed service needs PHP cURL.'); }
        $payload=AvdbScheduleProtocol::management($action,$id,$callback,$secret,$interval,$timestamp);
        $body=''; $tooLarge=false;
        $protocols=defined('CURLOPT_PROTOCOLS_STR')?[CURLOPT_PROTOCOLS_STR=>'https',CURLOPT_REDIR_PROTOCOLS_STR=>'https']:[CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS];
        $ch=curl_init('https://avdbapi.com/api.php/avdbschedulebridge/'.$action);
        $configuredCa=ini_get('curl.cainfo');
        if (is_string($configuredCa) && $configuredCa!=='') { curl_setopt($ch,CURLOPT_CAINFO,$configuredCa); }
        $curlVersion=curl_version();
        if (PHP_OS_FAMILY==='Windows' && defined('CURLOPT_SSL_OPTIONS') && ($curlVersion['version_number']??0)>=0x074700 && preg_match('/^(OpenSSL|LibreSSL|BoringSSL|AWS-LC)\//',$curlVersion['ssl_version']??'')) {
            curl_setopt($ch,CURLOPT_SSL_OPTIONS,defined('CURLSSLOPT_NATIVE_CA')?constant('CURLSSLOPT_NATIVE_CA'):16);
        }
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROXY=>'',
            CURLOPT_WRITEFUNCTION=>function($handle,$chunk)use(&$body,&$tooLarge){if(strlen($body)+strlen($chunk)>16384){$tooLarge=true;return 0;} $body.=$chunk;return strlen($chunk);}]+$protocols);
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if($ok===false || $tooLarge || $status!==200){throw new \RuntimeException('Managed service unavailable.');}
        $data=json_decode($body,true);
        if(!is_array($data) || (int)($data['code']??0)!==1){throw new \RuntimeException('Managed service could not register this site. Check its public HTTPS address.');}
        return $data;
    }
}
