<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
/*
'软件名称：苹果CMS 源码库：https://github.com/magicblack
'--------------------------------------------------------
'Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
'遵循Apache2开源协议发布，并提供免费使用。
'--------------------------------------------------------
*/
header('Content-Type:text/html;charset=utf-8');
require_once __DIR__ . '/application/common/util/SecurityHeaders.php';
JavcmsSecurityHeaders::send();
// Detecting the PHP environment
if(version_compare(PHP_VERSION,'7.4','<')) die('PHP 7.4 or newer is required. Please upgrade PHP.');
// Overtime
@ini_set('max_execution_time', '0');
// Memory Limits Remove memory limits
@ini_set("memory_limit",'-1');
// REVIEW-admin-security-r4: the admin session cookie is not sent on cross-site POSTs (all browsers, not only
// Chrome) and page scripts cannot read it.
@ini_set('session.cookie_samesite', 'Lax');
@ini_set('session.cookie_httponly', '1');
// Defining an app catalog
define('ROOT_PATH', __DIR__ . '/');
define('APP_PATH', __DIR__ . '/application/');
define('MAC_COMM', __DIR__.'/application/common/common/');
define('MAC_HOME_COMM', __DIR__.'/application/index/common/');
define('MAC_ADMIN_COMM', __DIR__.'/application/admin/common/');
define('MAC_START_TIME', microtime(true) );
// define(\'BIND_MODULE\',\'admin\');
define('ENTRANCE', 'admin');
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
if(strpos($_SERVER["SCRIPT_NAME"],'/admin.php')!==false){
    echo 'Please change the name of admin.php to another name to avoid being hit by hackers!';
    exit;
}
if (isset($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])
    && function_exists('mb_check_encoding') && function_exists('mb_convert_encoding')
    && !@mb_check_encoding($_SERVER['PATH_INFO'], 'utf-8')) {
    $_SERVER['PATH_INFO'] = @mb_convert_encoding($_SERVER['PATH_INFO'], 'UTF-8', 'GBK');
}

require __DIR__ . '/thinkphp/start.php';
