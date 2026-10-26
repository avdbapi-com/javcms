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

/** Fixed wire format shared by the provider and consumer. Secrets are literal keys. */
class AvdbScheduleProtocol
{
    public static function validId($id) { return is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id) === 1; }
    public static function validSecret($key) { return is_string($key) && preg_match('/^[a-f0-9]{64}$/D', $key) === 1; }
    public static function sign($action, $id, $sequence, $timestamp, $key)
    {
        return hash_hmac('sha256', implode("\n", ['avdb-schedule-v1', $action, $id, (string)$sequence, (string)$timestamp]), $key);
    }
    public static function callback($action, $id, $sequence, $key, $timestamp = null)
    {
        $timestamp = $timestamp === null ? time() : $timestamp;
        return ['action'=>$action,'client_id'=>$id,'sequence'=>$sequence,'timestamp'=>$timestamp,'signature'=>self::sign($action,$id,$sequence,$timestamp,$key)];
    }
    public static function verifyCallback(array $p, $key, $now = null)
    {
        $now = $now === null ? time() : $now;
        return self::validSecret($key) && isset($p['action'],$p['client_id'],$p['sequence'],$p['timestamp'],$p['signature'])
            && in_array($p['action'],['probe','run'],true) && self::validId($p['client_id'])
            && is_int($p['sequence']) && $p['sequence']>0 && is_int($p['timestamp']) && abs($now-$p['timestamp'])<=300
            && self::validSecret($p['signature']) && hash_equals(self::sign($p['action'],$p['client_id'],$p['sequence'],$p['timestamp'],$key),$p['signature']);
    }
    public static function proof(array $p, $key)
    {
        return ['client_id'=>$p['client_id'],'sequence'=>$p['sequence'],'timestamp'=>$p['timestamp'],
            'proof'=>self::sign('proof',$p['client_id'],$p['sequence'],$p['timestamp'],$key)];
    }
    public static function verifyProof(array $reply, array $p, $key)
    {
        return isset($reply['client_id'],$reply['sequence'],$reply['timestamp'],$reply['proof'])
            && $reply['client_id']===$p['client_id'] && $reply['sequence']===$p['sequence'] && $reply['timestamp']===$p['timestamp']
            && self::validSecret($reply['proof']) && hash_equals(self::sign('proof',$p['client_id'],$p['sequence'],$p['timestamp'],$key),$reply['proof']);
    }
    public static function managementSign($action, $id, $callback, $interval, $timestamp, $key)
    {
        return hash_hmac('sha256',implode("\n",['avdb-schedule-manage-v1',$action,$id,$callback,(string)$interval,(string)$timestamp]),$key);
    }
    public static function management($action,$id,$callback,$key,$interval,$timestamp=null)
    {
        $timestamp=$timestamp===null?time():$timestamp;
        return ['client_id'=>$id,'callback'=>$callback,'secret'=>$key,'interval_minutes'=>$interval,'timestamp'=>$timestamp,
            'signature'=>self::managementSign($action,$id,$callback,$interval,$timestamp,$key)];
    }
    public static function verifyManagement($action,array $p,$now=null)
    {
        $now=$now===null?time():$now;
        return in_array($action,['register','disable'],true) && isset($p['client_id'],$p['callback'],$p['secret'],$p['interval_minutes'],$p['timestamp'],$p['signature'])
            && self::validId($p['client_id']) && self::validSecret($p['secret']) && is_string($p['callback']) && strlen($p['callback'])<=2048
            && is_int($p['interval_minutes']) && $p['interval_minutes']>=1 && $p['interval_minutes']<=10080
            && is_int($p['timestamp']) && abs($now-$p['timestamp'])<=300 && self::validSecret($p['signature'])
            && hash_equals(self::managementSign($action,$p['client_id'],$p['callback'],$p['interval_minutes'],$p['timestamp'],$p['secret']),$p['signature']);
    }
}
