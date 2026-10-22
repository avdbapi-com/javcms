<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\behavior;

use app\common\util\Housekeeping;

/**
 * app_begin: about 1 request in Housekeeping::TICK_ODDS schedules a small, time-boxed clean-up as a shutdown function
 * (so it also runs after the page-cache hit path, which ends with die). It runs after the response when the SAPI can
 * finish the request early, otherwise in a slice of a few milliseconds. While a cache backlog is known (marker
 * runtime/housekeeping/backlog, younger than 15 min) the odds rise to about 1 in 2 inline / 1 in 5 after an early finish.
 * See app\common\util\Housekeeping.
 */
class HousekeepingTick
{
    public function run(&$params)
    {
        if (PHP_SAPI === 'cli' || !Housekeeping::shouldTick()) {
            return;
        }
        register_shutdown_function([Housekeeping::class, 'onShutdown']);
    }
}
