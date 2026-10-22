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
/* JAVCMS.NET */
/*
'软件名称：苹果CMS 源码库：https://github.com/magicblack
'--------------------------------------------------------
'Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
'遵循Apache2开源协议发布，并提供免费使用。
'--------------------------------------------------------
*/
header('Content-Type:text/html;charset=utf-8');
// Same baseline headers as the other entrances (no framing of the installer, nosniff, referrer policy).
require_once __DIR__ . '/application/common/util/SecurityHeaders.php';
JavcmsSecurityHeaders::send();
if(version_compare(PHP_VERSION,'7.4','<')) die('PHP 7.4 or newer is required. Please upgrade PHP.');
@ini_set('max_execution_time', '0');
@ini_set("memory_limit",'-1');
define('ROOT_PATH', __DIR__ . '/');
define('APP_PATH', __DIR__ . '/application/');
define('MAC_COMM', __DIR__.'/application/common/common/');
define('MAC_HOME_COMM', __DIR__.'/application/index/common/');
define('MAC_ADMIN_COMM', __DIR__.'/application/admin/common/');
define('MAC_START_TIME', microtime(true) );
define('BIND_MODULE', 'install');
define('ENTRANCE', 'install');
$in_file = rtrim($_SERVER['SCRIPT_NAME'],'/');
if(substr($in_file,strlen($in_file)-4)!=='.php'){
    $in_file = substr($in_file,0,strpos($in_file,'.php')) .'.php';
}
define('IN_FILE',$in_file);
if(is_file(APP_PATH.'data/install/install.lock')) {
	echo 'Installation is locked. To reinstall intentionally, remove /application/data/install/install.lock after backing up your site.';
	exit;
}

if(!is_dir(ROOT_PATH.'runtime')) {
	@mkdir(ROOT_PATH.'runtime', 0755, true);
}
if(!is_dir(ROOT_PATH.'runtime') || !is_writable(ROOT_PATH.'runtime')) {
	header('HTTP/1.1 500 Internal Server Error');
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>JAVCMS installation</title></head><body style="font:16px/1.6 system-ui,sans-serif;max-width:680px;margin:48px auto;padding:0 16px">'
		. '<h1 style="font-size:22px">JAVCMS cannot write to the runtime folder</h1>'
		. '<p>PHP must be able to create and write the folder <code>runtime/</code> inside your JAVCMS directory.</p>'
		. '<p>Give the web server user write permission to the JAVCMS directory (or create <code>runtime/</code> and make it writable), then reload this page.</p>'
		. '<ul><li>Linux: <code>chmod -R 775 runtime upload application</code> and <code>chown -R www-data:www-data .</code> (use your PHP user: www-data, nginx, apache or your hosting account)</li>'
		. '<li>Windows / IIS: allow <em>Modify</em> for the IIS_IUSRS group on the JAVCMS folder</li></ul></body></html>';
	exit;
}

require __DIR__ . '/thinkphp/start.php';
