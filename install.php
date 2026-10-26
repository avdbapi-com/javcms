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
/* JAVCMS.NET */
/*
'软件名称：苹果CMS 源码库：https://github.com/magicblack
'--------------------------------------------------------
'Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
'遵循Apache2开源协议发布，并提供免费使用。
'--------------------------------------------------------
*/
header('Content-Type:text/html;charset=utf-8');
// One-click update (1.2.1): while an update runs, this entry shows the run's own progress page and takes its steps
// ("?jcupd="). A 1.2.0 site updates with the update bridge uploaded as install.php; the update replaces it with this
// file mid-run, so a reload of the bridge page lands here and can continue the run.
if (is_file(__DIR__ . '/runtime/update/runner.php') && (isset($_GET['jcupd']) || is_file(__DIR__ . '/runtime/update/current.php'))) {
    if (!defined('JAVCMS_UPD_ENTRY')) { define('JAVCMS_UPD_ENTRY', 'install'); }
    include __DIR__ . '/runtime/update/runner.php';
}
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
	javcms_install_locked_page();
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

/**
 * The page of an installed site (install.lock exists), in the visitor's language when it is one of the admin languages.
 * Right after a one-click update through the update bridge (the bridge was uploaded as install.php and replaced by this
 * file) it says to the signed-in founder that the update is finished and where updates are from now on, instead of
 * talking about reinstalling; everyone else gets the plain page (no version number).
 */
function javcms_install_locked_page()
{
	$t = array(
		'en' => array('en', 'JavCMS', 'This site is installed', 'The installer is locked because JavCMS is already installed here. Reinstalling erases the site: only to do that on purpose, back up the site first, then remove application/data/install/install.lock.',
			'The update to JavCMS %s is finished.', 'From now on, updates are installed in the admin: Admins & backup > Updates.', 'Open the admin', 'Open the site'),
		'vi' => array('vi', 'JavCMS', 'Trang web này đã được cài đặt', 'Trình cài đặt bị khóa vì JavCMS đã được cài ở đây. Cài lại sẽ xóa trang web: chỉ khi thật sự muốn làm vậy, hãy sao lưu trang trước rồi xóa application/data/install/install.lock.',
			'Đã cập nhật xong lên JavCMS %s.', 'Từ nay, việc cập nhật được thực hiện trong quản trị: Quản trị & sao lưu > Cập nhật.', 'Mở trang quản trị', 'Mở trang web'),
		'zh-cn' => array('zh-Hans', 'JavCMS', '本站已安装', '由于 JavCMS 已安装在此，安装程序已锁定。重新安装会清除网站：只有确实要这样做时，请先备份网站，再删除 application/data/install/install.lock。',
			'已完成更新到 JavCMS %s。', '今后请在后台安装更新：管理员与备份 > 更新。', '打开后台', '打开网站'),
		'zh-tw' => array('zh-Hant', 'JavCMS', '本站已安裝', '由於 JavCMS 已安裝在此，安裝程式已鎖定。重新安裝會清除網站：只有確實要這樣做時，請先備份網站，再刪除 application/data/install/install.lock。',
			'已完成更新到 JavCMS %s。', '今後請在後台安裝更新：管理員與備份 > 更新。', '開啟後台', '開啟網站'),
	);
	$lang = 'en';
	foreach (explode(',', strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
		$tag = trim((string)explode(';', $part)[0]);
		if (strpos($tag, 'vi') === 0) { $lang = 'vi'; break; }
		if (in_array($tag, array('zh-tw', 'zh-hk', 'zh-mo'), true) || strpos($tag, 'zh-hant') === 0) { $lang = 'zh-tw'; break; }
		if (strpos($tag, 'zh') === 0) { $lang = 'zh-cn'; break; }
		if (strpos($tag, 'en') === 0) { break; }
	}
	$s = $t[$lang];
	$h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); };
	// the newest update run, when it was the bridge's and ended well within the last day (runtime/update/ data files) - told
	// only to the signed-in founder admin: the version tells a stranger which known problems the site has
	$done = '';
	$admin = 'javcms.php';
	$founder = false;
	$lib = __DIR__ . '/application/common/util/updater/';
	if (is_file($lib . 'Util.php') && is_file($lib . 'Runner.php')) {
		try {
			require_once $lib . 'Util.php';
			require_once $lib . 'Runner.php';
			\app\common\util\updater\Util::setRoot(__DIR__);
			// read only: the session is never written, and a made-up session id never creates a file
			$founder = \app\common\util\updater\Runner::sessionAdmin() === 1;
		} catch (\Throwable $e) {
			$founder = false;
		}
	}
	$raw = $founder ? @file_get_contents(__DIR__ . '/runtime/update/history.php') : false;
	if (is_string($raw) && ($p = strpos($raw, "\n")) !== false) {
		$hist = json_decode(substr($raw, $p + 1), true);
		$row = is_array($hist) && isset($hist['runs'][0]) && is_array($hist['runs'][0]) ? $hist['runs'][0] : null;
		if ($row && !empty($row['bridge']) && ($row['status'] ?? '') === 'done' && time() - (int)($row['ended'] ?? 0) < 86400
			&& preg_match('/^[0-9A-Za-z.-]{1,40}$/', (string)($row['to'] ?? ''))) {
			$done = (string)$row['to'];
			$st = @file_get_contents(__DIR__ . '/runtime/update/runs/' . preg_replace('/[^0-9a-f]/', '', (string)($row['id'] ?? '')) . '/state.php');
			if (is_string($st) && ($q = strpos($st, "\n")) !== false) {
				$state = json_decode(substr($st, $q + 1), true);
				if (is_array($state) && preg_match('/^[A-Za-z0-9_.-]{1,60}\.php$/', (string)($state['admin'] ?? ''))) {
					$admin = (string)$state['admin'];
				}
			}
		}
	}
	$dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/install.php'))), '/.');
	header('Cache-Control: no-store');
	header('X-Robots-Tag: noindex');
	echo '<!doctype html><html lang="' . $s[0] . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<meta name="robots" content="noindex"><title>' . $h($s[1]) . '</title><style>body{margin:0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;'
		. 'background:#f6f7f9;color:#1d2230}main{max-width:640px;margin:48px auto;padding:0 16px}h1{font-size:22px}a{color:#2457d6}p.small{color:#4b5263;font-size:14px}'
		. '@media(prefers-color-scheme:dark){body{background:#0f1115;color:#e6e8ee}a{color:#7ea2ff}p.small{color:#b4bac6}}</style></head><body><main>';
	if ($done !== '') {
		echo '<h1>' . $h(sprintf($s[4], $done)) . '</h1><p>' . $h($s[5]) . '</p><p><a href="' . $h($dir . '/' . $admin) . '">' . $h($s[6]) . '</a> · <a href="' . $h($dir . '/') . '">' . $h($s[7]) . '</a></p>';
	} else {
		echo '<h1>' . $h($s[2]) . '</h1><p><a href="' . $h($dir . '/') . '">' . $h($s[7]) . '</a></p><p class="small">' . $h($s[3]) . '</p>';
	}
	echo '</main></body></html>';
}
