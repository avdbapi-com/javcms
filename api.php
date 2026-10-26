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
/*
'软件名称：苹果CMS 源码库：https://github.com/magicblack
'--------------------------------------------------------
'Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
'遵循Apache2开源协议发布，并提供免费使用。
'--------------------------------------------------------
*/
header('Content-Type:text/html;charset=utf-8');
// One-click update (1.2.1): while an update replaces files, visitors get its maintenance page (runtime/update/gate.php,
// written by the update and never part of a release) before any other file of the site is loaded.
if (is_file(__DIR__ . '/runtime/update/maintenance.php') && is_file(__DIR__ . '/runtime/update/gate.php')) { include __DIR__ . '/runtime/update/gate.php'; }
require_once __DIR__ . '/application/common/util/SecurityHeaders.php';
JavcmsSecurityHeaders::send();
// Never print PHP errors (file paths) to API clients, even where the host's php.ini has display_errors=On.
@ini_set('display_errors', '0');
// Detecting the PHP environment
if(version_compare(PHP_VERSION,'7.4','<')) die('PHP 7.4 or newer is required. Please upgrade PHP.');
// Overtime
@ini_set('max_execution_time', '0');
// Memory Limits Remove memory limits
@ini_set("memory_limit",'2048M'); // per-request cap: far above any API response, stops one runaway request from exhausting the server's RAM
// Defining an app catalog
define('ROOT_PATH', __DIR__ . '/');
define('APP_PATH', __DIR__ . '/application/');
define('MAC_COMM', __DIR__.'/application/common/common/');
define('MAC_HOME_COMM', __DIR__.'/application/index/common/');
define('MAC_ADMIN_COMM', __DIR__.'/application/admin/common/');
define('MAC_START_TIME', microtime(true) );
define('BIND_MODULE','api');
define('ENTRANCE', 'api');
$in_file = rtrim($_SERVER['SCRIPT_NAME'],'/');
if(substr($in_file,strlen($in_file)-4)!=='.php'){
    $in_file = substr($in_file,0,strpos($in_file,'.php')) .'.php';
}
define('IN_FILE',$in_file);

if(!is_file(ROOT_PATH . 'application/data/install/install.lock')) {
    $installBase = trim(str_replace('\\', '/', dirname(IN_FILE)), '/');
    header('Location: /' . ($installBase === '.' || $installBase === '' ? '' : $installBase . '/') . 'install.php');
    exit;
}
if (isset($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])
    && function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')
    && !@mb_check_encoding($_SERVER['PATH_INFO'], 'utf-8')) {
    $_SERVER['PATH_INFO'] = @mb_convert_encoding($_SERVER['PATH_INFO'], 'UTF-8', 'GBK');
}
// Load the framework bootstrap file
require __DIR__ . '/thinkphp/start.php';


