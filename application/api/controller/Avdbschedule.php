<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\api\controller;

/** Public endpoint: POST plus the durable per-site HMAC, never an admin session. */
class Avdbschedule extends \think\Controller
{
    public function index()
    {
        if (!$this->request->isPost()) { return json(['code'=>405,'msg'=>'POST required.','data'=>[]],405); }
        $body=$this->request->getContent();
        if (!is_string($body) || strlen($body)>2048) { return json(['code'=>400,'msg'=>'Invalid callback.','data'=>[]],400); }
        $payload=json_decode($body,true);
        if (!is_array($payload)) { return json(['code'=>400,'msg'=>'Invalid callback.','data'=>[]],400); }
        try {
            $result=(new \app\common\model\AvdbSchedule())->receive($payload);
            // Signed callback every few hours: run one bounded clean-up pass after the answer (DB-SLIM housekeeping).
            register_shutdown_function([\app\common\util\Housekeeping::class,'onCallback']);
            return json(['code'=>1,'msg'=>'OK','data'=>$result]);
        }
        catch (\Throwable $e) { return json(['code'=>403,'msg'=>'Callback refused.','data'=>[]],403); }
    }
}
