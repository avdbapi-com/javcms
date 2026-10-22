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
namespace app\install\controller;
use think\Controller;
use think\Db;
use think\Lang;
use think\Request;
use app\install\service\Installer;

class Index extends Controller
{
    public function __construct(?Request $request = null)
    {
        if (!defined('BIND_MODULE') || BIND_MODULE !== 'install') {
            header('HTTP/1.1 403 Forbidden');
            exit;
        }
        parent::__construct($request);
    }

    public function index($step = 0)
    {
        $langs = array_map('basename', glob(APP_PATH . 'lang/*.php'));
        $langs = array_map(function ($name) { return substr($name, 0, -4); }, $langs);
        $selected = input('get.lang', session('lang') ?: 'en-us');
        if (!is_string($selected) || !in_array($selected, $langs, true)) {
            $selected = 'en-us';
        }
        Lang::range($selected);
        Lang::load(APP_PATH . 'lang/' . $selected . '.php', $selected);
        // Installer-only strings (admin/ux/install/*): the module's own lang file, English fallback.
        Lang::load(APP_PATH . 'install/lang/en-us.php', $selected);
        if ($selected !== 'en-us' && is_file(APP_PATH . 'install/lang/' . $selected . '.php')) {
            Lang::load(APP_PATH . 'install/lang/' . $selected . '.php', $selected);
        }
        session('lang', $selected);
        $this->assign('langs', $langs);
        $this->assign('lang', $selected);
        if ((int)$step === 2) {
            session('install_verified', null);
            session('install_error', false);
            session('install_dir_error', false);
            $data = ['env' => $this->checkNnv(), 'dir' => $this->checkDir(), 'func' => $this->checkFunc()];
            session('install_environment', !session('install_error'));
            $this->assign('data', $data);
            $this->assign('dir_error', (bool)session('install_dir_error'));
            return $this->fetch('install@index/step2');
        }
        if (in_array((int)$step, [3, 4, 5], true) && !session('install_environment')) {
            return $this->error(lang('install/environment_failed'));
        }
        if ((int)$step === 3) {
            session('install_csrf', bin2hex(random_bytes(32)));
            session('install_verified', null);
            $this->assign('install_token', session('install_csrf'));
            $this->assign('install_dir', rtrim(str_replace('\\', '/', dirname(request()->baseFile())), '/') . '/');
            $detectedHost = strtolower((string)request()->host());
            $detectedHost = preg_match('/^[a-z0-9.\-\[\]:]+$/D', $detectedHost) ? $detectedHost : '';
            $this->assign('site_url_default', $detectedHost !== '' ? (request()->isSsl() ? 'https://' : 'http://') . $detectedHost : '');
            $this->assign('site_name_default', $detectedHost !== '' ? preg_replace('/:\d+$/', '', $detectedHost) : 'JAVCMS');
            $this->assign('catalogs', Installer::CATALOGS);
            $this->assign('parent_default', Installer::PARENT_DEFAULT);
            return $this->fetch('install@index/step3');
        }
        if (in_array((int)$step, [4, 5], true)) {
            $token = input('post.install_token', '');
            if (!$this->request->isPost() || !is_string($token) || !is_string(session('install_csrf')) || !hash_equals(session('install_csrf'), $token)) {
                return $this->error(lang('install/access_denied'));
            }
            return (int)$step === 4 ? $this->step4() : $this->step5();
        }
        return $this->fetch('install@index/index');
    }

    private function step4()
    {
        session('install_verified', null);
        try {
            $data = Installer::databaseConfig(input('post.'));
            $db = Db::connect($data, true);
            $db->query('SELECT 1');
            Installer::assertEmptyPrefix($db->query('SHOW TABLES'), $data['prefix']);
            Installer::writeConfig(APP_PATH . 'database.php', $data);
            session('install_verified', hash('sha256', serialize($data)));
        } catch (\Throwable $e) {
            return $this->error('Database verification failed. Check the connection, permissions, and that the selected table prefix is empty.');
        }
        return $this->success(lang('install/database_connect_ok'), '');
    }

    private function step5()
    {
        $guard = null;
        try {
            $config = include APP_PATH . 'database.php';
            if (!is_string(session('install_verified')) || !hash_equals(session('install_verified'), hash('sha256', serialize($config)))) {
                throw new \RuntimeException('Connection not verified');
            }
            $adminName = input('post.admin_name', '');
            $adminPass = input('post.admin_password', '');
            $confirm = input('post.admin_password_confirm', '');
            Installer::validateAdministrator($adminName, $adminPass, $confirm);
            list($siteName, $siteHost, $canonicalScheme) = Installer::siteIdentity(input('post.site_name', ''), input('post.site_url', ''), request()->host(), request()->isSsl());
            list($catalog, $parentName) = Installer::catalogChoice(input('post.catalog', ''), input('post.parent_name', ''));
            $schemaPath = APP_PATH . 'install/sql/install.sql';
            if (!is_readable($schemaPath)) {
                throw new \RuntimeException('Missing installation schema');
            }
            $schema = file_get_contents($schemaPath);
            $statements = array_filter(mac_parse_sql(Installer::schemaPrefix($schema, $config['prefix']), 0));
            if (!$statements) {
                throw new \RuntimeException('Empty installation schema');
            }
            $guard = fopen(APP_PATH . 'data/install/installing.lock', 'c');
            if (!$guard || !flock($guard, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Installation in progress');
            }
            if (is_file(APP_PATH . 'data/install/install.lock')) {
                throw new \RuntimeException('Already installed');
            }
            $db = Db::connect($config, true);
            Installer::assertEmptyPrefix($db->query('SHOW TABLES'), $config['prefix']);
            foreach ($statements as $sql) {
                $db->execute($sql);
            }
            // Seeded categories for the catalog answer (only the chosen catalogs enabled) and neutral SEO fields.
            foreach (Installer::categoryStatements($config['prefix'], $catalog, $parentName) as $update) {
                $db->execute($update[0], $update[1]);
            }
            $db->table($config['prefix'] . 'admin')->insert([
                'admin_id' => 1, 'admin_name' => $adminName,
                'admin_pwd' => password_hash($adminPass, PASSWORD_BCRYPT),
                'admin_random' => bin2hex(random_bytes(16)),
                'admin_status' => 1, 'admin_auth' => '',
            ]);
            $settings = Installer::neutralSettings(config('maccms'));
            $settings['app']['lang'] = session('lang') ?: 'en-us';
            $settings['app']['cache_flag'] = bin2hex(random_bytes(8));
            $settings['interface']['pass'] = bin2hex(random_bytes(24));
            $settings['rewrite']['encode_key'] = bin2hex(random_bytes(16));
            $settings['site']['install_dir'] = rtrim(str_replace('\\', '/', dirname(request()->baseFile())), '/') . '/';
            $settings['site']['site_name'] = $siteName;
            $settings['site']['site_url'] = $siteHost;
            foreach (Installer::siteTexts($catalog) as $key => $text) {
                $settings['site'][$key] = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
            // Installing over HTTPS makes HTTPS the canonical protocol (sitemaps, canonical tags, hreflang).
            $settings['rewrite']['canonical_scheme'] = $canonicalScheme;
            Installer::writeConfig(APP_PATH . 'extra/maccms.php', $settings);
            // 1.2.0 tables (scale, search index, importer) on the new database; failures are recorded, never fatal:
            // the first admin visit and tools/upgrade.php repeat these idempotent steps.
            \think\Config::set('database', $config);
            Db::clear();
            $db->query('SELECT 1');
            $pdo = $db->getPdo();
            $schema = $pdo instanceof \PDO ? Installer::schemaSteps($pdo, $config['prefix'], $config['database']) : ['connection' => 'error: no PDO'];
            @file_put_contents(APP_PATH . 'data/install/schema.json', json_encode(['at' => date('c'), 'catalog' => $catalog, 'steps' => $schema]), LOCK_EX);
            if (file_put_contents(APP_PATH . 'data/install/install.lock', date('c'), LOCK_EX) === false) {
                throw new \RuntimeException('Cannot write installation lock');
            }
            session('install_verified', null);
            session('install_csrf', null);
        } catch (\Throwable $e) {
            return $this->error('Installation failed. Check the site URL, the administrator fields, database permissions and writable folders. Existing tables are never overwritten.');
        } finally {
            if (is_resource($guard)) {
                flock($guard, LOCK_UN);
                fclose($guard);
            }
        }
        // 1.2.0 (CONTRACT HO-H-R3-4): the sitemap files are built now, after install.lock, so a search engine meets stored
        // files from the start; then (CONTRACT HO-H-R3-1) the search engines' current IP lists replace the lists shipped in
        // the package. Neither ever fails the finished installation.
        $sitemapLine = $this->prebuildSitemap();
        $this->refreshCrawlerLists();
        return $this->success(self::sentence(lang('install/is_ok'), $sitemapLine), $settings['site']['install_dir'] . 'javcms.php');
    }

    /**
     * 1.2.0 (CONTRACT HO-H-R3-1, verify r3 OW1 (b)): Housekeeping::refreshCrawlerLists(ROOT_PATH, 10) once the site is
     * installed: the search engines' published IP lists are downloaded now (an engine whose download fails keeps the list
     * shipped in the package; housekeeping refreshes them daily), so verified crawlers are recognized by the current lists
     * from the first visit. The facts go into application/data/install/schema.json ('crawler_lists': state and ms of the
     * housekeeping run, ok, total, failed engines); the success page shows no line for it. Never throws.
     */
    private function refreshCrawlerLists()
    {
        try {
            $cls = '\\app\\common\\util\\Housekeeping';
            if (!class_exists($cls) || !method_exists($cls, 'refreshCrawlerLists')) {
                return;
            }
            $r = (array)$cls::refreshCrawlerLists(ROOT_PATH, 10);
            $run = isset($r['run']) && is_array($r['run']) ? $r['run'] : [];
            $lists = isset($run['tasks']['crawlers']) && is_array($run['tasks']['crawlers']) ? $run['tasks']['crawlers'] : [];
            $ok = 0;
            $failed = [];
            foreach ($lists as $engine => $one) {
                if (is_array($one) && !empty($one['ok'])) {
                    $ok++;
                } else {
                    $failed[] = preg_replace('/[^a-z]/', '', strtolower((string)$engine));
                }
            }
            self::schemaLog('crawler_lists', ['state' => (string)($run['state'] ?? ''), 'ok' => $ok, 'total' => count($lists),
                'failed' => implode(',', $failed), 'ms' => (int)($run['ms'] ?? 0)]);
        } catch (\Throwable $e) {
            return;
        }
    }

    /** Adds $key => $value to application/data/install/schema.json (written by step 5); never throws. */
    private static function schemaLog($key, array $value)
    {
        try {
            $file = APP_PATH . 'data/install/schema.json';
            $log = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
            if (is_array($log)) {
                $log[$key] = $value;
                $json = json_encode($log);
                if (is_string($json)) {
                    @file_put_contents($file, $json, LOCK_EX);
                }
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * 1.2.0 (CONTRACT HO-H-R3-4, site-r3 N20 (b)): Housekeeping::prebuildSitemapFiles(ROOT_PATH, 30, <scheme of this
     * request>) once the site is installed. A child process (tools/housekeeping.php --sitemap-files-only) stores the sitemap
     * index, and the video files once there are videos, within 30 s (+ the child's grace); what it leaves, housekeeping
     * builds. The scheme of this request goes along because an install over plain HTTP keeps the canonical scheme "auto",
     * which a command line cannot resolve. The result is added to application/data/install/schema.json ('sitemap_files')
     * and returned as the line for the success page ('' when the method is missing). Never throws.
     */
    private function prebuildSitemap()
    {
        try {
            $cls = '\\app\\common\\util\\Housekeeping';
            if (!class_exists($cls) || !method_exists($cls, 'prebuildSitemapFiles')) {
                return '';
            }
            $result = (array)$cls::prebuildSitemapFiles(ROOT_PATH, 30, $this->request->isSsl() ? 'https' : 'http');
            $keep = [];
            foreach (['state', 'built', 'lang_built', 'fresh', 'left', 'wanted', 'seconds', 'via', 'why'] as $key) {
                if (isset($result[$key]) && is_scalar($result[$key])) {
                    $keep[$key] = $result[$key];
                }
            }
            self::schemaLog('sitemap_files', $keep);
            return self::sitemapLine($keep);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The success-page line of a prebuildSitemapFiles() result (the facts of tools/upgrade.php step 5c, translated). */
    private static function sitemapLine(array $r)
    {
        $state = (string)($r['state'] ?? '');
        if (isset($r['built']) && in_array($state, ['done', 'error'], true)) {
            $n = [(int)$r['built'], (int)($r['lang_built'] ?? 0), (int)($r['fresh'] ?? 0), (int)($r['left'] ?? 0)];
            if ($n[0] + $n[2] + $n[3] > 0) {
                $line = str_replace(['{built}', '{lang}', '{fresh}', '{left}'], array_map('number_format', $n), lang('admin/ux/install/sitemap_built'));
                return $state === 'error' ? self::sentence($line, lang('admin/ux/install/sitemap_stopped')) : $line;
            }
            if ($state === 'done') {
                return lang('admin/ux/install/sitemap_index');
            }
        }
        // literal keys, so the language tools see every key in use
        $why = ['no_cli' => lang('admin/ux/install/sitemap_why_no_cli'), 'disabled' => lang('admin/ux/install/sitemap_why_disabled'),
            'timeout' => lang('admin/ux/install/sitemap_why_timeout'), 'busy' => lang('admin/ux/install/sitemap_why_busy'),
            'hidden' => lang('admin/ux/install/sitemap_why_hidden'), 'error' => lang('admin/ux/install/sitemap_why_error')];
        $reason = isset($why[$state]) ? $why[$state] : (preg_replace('/[^a-z_]/', '', $state) ?: '?');
        return str_replace('{why}', $reason, lang('admin/ux/install/sitemap_later'));
    }

    /** $a and $b as two sentences: a space between them, none after a CJK full stop (U+3002, U+FF01, U+FF1F). */
    private static function sentence($a, $b)
    {
        return $b === '' ? $a : $a . (preg_match('/[\x{3002}\x{FF01}\x{FF1F}]$/u', $a) ? '' : ' ') . $b;
    }

    private function checkNnv()
    {
        $ok = version_compare(PHP_VERSION, '7.4', '>=');
        if (!$ok) { session('install_error', true); }
        return [
            'os' => [lang('install/os'), lang('install/not_limited'), 'Windows/Unix', PHP_OS, 'ok'],
            'php' => [lang('install/php'), '7.4', '>=7.4', PHP_VERSION, $ok ? 'ok' : 'no'],
        ];
    }

    private function checkDir()
    {
        $items = [];
        // Folders the installer can create on its own; nothing has to be made by hand.
        foreach (['runtime', 'upload', 'application/data/backup', 'application/data/update', 'application/data/install'] as $relative) {
            Installer::ensureWritableDir(ROOT_PATH . $relative);
        }
        foreach (['application', 'application/database.php', 'application/route.php', 'application/extra/maccms.php', 'application/extra', 'application/data/backup', 'application/data/update', 'application/data/install', 'runtime', 'upload'] as $relative) {
            $path = ROOT_PATH . $relative;
            $isFile = substr($relative, -4) === '.php';
            $ok = $isFile ? Installer::fileWritable($path) : (is_dir($path) && is_writable($path));
            $items[] = [$isFile ? 'file' : 'dir', './' . $relative, lang('install/read_and_write'), $ok ? lang('install/writable_ok') : lang('install/not_writable'), $ok ? 'ok' : 'no'];
            if (!$ok) { session('install_error', true); session('install_dir_error', true); }
        }
        return $items;
    }

    private function checkFunc()
    {
        $checks = ['PDO' => 'class', 'pdo_mysql' => 'extension', 'zip' => 'extension', 'fileinfo' => 'extension', 'curl' => 'extension', 'xml' => 'extension', 'gd' => 'extension', 'file_get_contents' => 'function', 'mb_strlen' => 'function', 'random_bytes' => 'function'];
        $items = [];
        foreach ($checks as $name => $kind) {
            $ok = $kind === 'class' ? class_exists($name) : ($kind === 'extension' ? extension_loaded($name) : function_exists($name));
            $label = $kind === 'extension' ? lang('install/model') : lang('install/' . $kind);
            $items[] = [$name, $ok ? lang('install/support') : lang('install/not_support'), $ok ? 'yes' : 'no', $label];
            if (!$ok) { session('install_error', true); }
        }
        return $items;
    }
}
