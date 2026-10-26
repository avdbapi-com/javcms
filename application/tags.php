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
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// Application behavior extension definition file
return [
    // App initialization
    'app_init'     => [
        'app\\common\\behavior\\Init',
    ],
    // App starts
    'app_begin'    => [
        'app\\common\\behavior\\Begin',
        'app\\common\\behavior\\HousekeepingTick',
    ],
    // module initialization
    'module_init'  => [

    ],
    // Plugin starts
    'addon_begin'  => [

    ],
    // Operation starts to execute
    'action_begin' => [],
    // View content filtering
    'view_filter'  => [],
    // Log writing
    'log_write'    => [],
    // End of application
    'app_end'      => [],
];
