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
namespace app\admin\controller;

use app\common\util\UrlSettings;

class Domain extends Base
{
    const IMPORT_LIMIT = 2097152;
    const FIELDS = ['site_url', 'site_name', 'site_keywords', 'site_description', 'template_dir', 'html_dir', 'ads_dir'];

    protected function reply($code, $key, $line = 0)
    {
        $token = $this->request->token();
        // F48: 'row' (1-based) lets the page highlight the row that failed.
        return json(['code' => $code, 'msg' => lang($key) . ($line ? ' (' . $line . ')' : ''), '__token__' => $token, 'data' => ['__token__' => $token, 'row' => (int)$line]]);
    }

    protected function mutationInput()
    {
        if (!$this->request->isPost()) { throw new \InvalidArgumentException('admin/multisite/post_required'); }
        $post = input('post.');
        $validator = \think\Loader::validate('Token');
        if (!$validator->check($post)) { throw new \InvalidArgumentException('illegal_request'); }
        return $post;
    }

    protected static function validateEntry(array $entry, $line = 0)
    {
        foreach (self::FIELDS as $field) {
            if (!isset($entry[$field]) || !is_string($entry[$field]) || strlen($entry[$field]) > 65535 || strpos($entry[$field], "\0") !== false) {
                throw new \InvalidArgumentException('admin/multisite/invalid_row', $line);
            }
        }
        $host = $entry['site_url'];
        if ($host !== trim($host) || strlen($host) > 300 || UrlSettings::canonicalUrl($host, '/', 'https://') === '') {
            throw new \InvalidArgumentException('admin/multisite/invalid_host', $line);
        }
        foreach (['template_dir', 'html_dir', 'ads_dir'] as $field) {
            if (preg_match('/[\x00-\x1f\x7f\\\\:]/', $entry[$field]) || substr($entry[$field], 0, 1) === '/' || in_array('..', explode('/', $entry[$field]), true)) {
                throw new \InvalidArgumentException('admin/multisite/invalid_directory', $line);
            }
        }
        if ($entry['template_dir'] !== 'no'
            && !in_array($entry['template_dir'], System::availableTemplates(), true)) {
            throw new \InvalidArgumentException('admin/multisite/invalid_directory', $line);
        }
        return $entry;
    }

    protected static function fromForm(array $post, array $existing)
    {
        // Empty list is valid only when every row was intentionally removed.
        if (!isset($post['domain_form']) || $post['domain_form'] !== '1') { throw new \InvalidArgumentException('admin/multisite/invalid_row'); }
        if (!isset($post['domain'])) { return []; }
        $columns = $post['domain'];
        if (!is_array($columns)) { throw new \InvalidArgumentException('admin/multisite/invalid_row'); }
        foreach (self::FIELDS as $field) {
            if (!isset($columns[$field]) || !is_array($columns[$field])) { throw new \InvalidArgumentException('admin/multisite/invalid_row'); }
            if (array_keys($columns[$field]) !== array_keys($columns['site_url'])) { throw new \InvalidArgumentException('admin/multisite/invalid_row'); }
        }
        if (isset($columns['origin']) && (!is_array($columns['origin']) || array_keys($columns['origin']) !== array_keys($columns['site_url']))) { throw new \InvalidArgumentException('admin/multisite/invalid_row'); }
        if (count($columns['site_url']) > 1000) { throw new \InvalidArgumentException('admin/multisite/too_many'); }
        $result = [];
        foreach ($columns['site_url'] as $index => $host) {
            $entry = [];
            foreach (self::FIELDS as $field) { $entry[$field] = $columns[$field][$index]; }
            $entry = self::validateEntry($entry, count($result) + 1);
            if (isset($result[$host])) { throw new \InvalidArgumentException('admin/multisite/duplicate_host', count($result) + 1); }
            $origin = isset($columns['origin'][$index]) ? $columns['origin'][$index] : $host;
            if (!is_string($origin) || ($origin !== '' && !isset($existing[$origin]))) { throw new \InvalidArgumentException('admin/multisite/stale_form', count($result) + 1); }
            // Keep unexposed per-site overrides when the host is renamed.
            $result[$host] = array_merge($origin !== '' && isset($existing[$origin]) ? $existing[$origin] : [], $entry);
        }
        return $result;
    }

    protected static function importData($bytes, $extension, array $existing)
    {
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::IMPORT_LIMIT || strpos($bytes, "\0") !== false) {
            throw new \InvalidArgumentException('admin/multisite/invalid_import');
        }
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        $result = [];
        if ($extension === 'json') {
            $entries = json_decode($bytes, true, 16);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($entries)) { throw new \InvalidArgumentException('admin/multisite/invalid_import'); }
            foreach ($entries as $entry) {
                if (!is_array($entry)) { throw new \InvalidArgumentException('admin/multisite/invalid_row', count($result) + 1); }
                $entry = self::validateEntry($entry, count($result) + 1);
                if (isset($result[$entry['site_url']])) { throw new \InvalidArgumentException('admin/multisite/duplicate_host', count($result) + 1); }
                $result[$entry['site_url']] = array_merge(isset($existing[$entry['site_url']]) ? $existing[$entry['site_url']] : [], $entry);
            }
        } elseif ($extension === 'txt') {
            foreach (preg_split('/\r\n|\r|\n/', $bytes) as $index => $line) {
                if (trim($line) === '') { continue; }
                $fields = explode('$', $line);
                if (count($fields) !== count(self::FIELDS)) { throw new \InvalidArgumentException('admin/multisite/invalid_row', $index + 1); }
                $entry = self::validateEntry(array_combine(self::FIELDS, $fields), $index + 1);
                if (isset($result[$entry['site_url']])) { throw new \InvalidArgumentException('admin/multisite/duplicate_host', count($result) + 1); }
                $result[$entry['site_url']] = array_merge(isset($existing[$entry['site_url']]) ? $existing[$entry['site_url']] : [], $entry);
            }
        } else { throw new \InvalidArgumentException('admin/multisite/invalid_import'); }
        if (!$result || count($result) > 1000) { throw new \InvalidArgumentException('admin/multisite/invalid_import'); }
        return $result;
    }

    protected function writeDomains(array $domains, $successKey, $expectedRevision)
    {
        $path = APP_PATH . 'extra/domain.php';
        $lock = @fopen(RUNTIME_PATH . 'multisite-save.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) { fclose($lock); }
            return $this->reply(0, 'admin/multisite/write_failed');
        }
        try {
            // Compare immediately before writing, under the same lock used by every mutation.
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
            $current = include $path;
            if (!is_array($current) || !hash_equals(hash('sha256', serialize($current)), $expectedRevision)) {
                return $this->reply(0, 'admin/multisite/stale_form');
            }
            $backup = UrlSettings::backupFiles([$path], RUNTIME_PATH . 'multisite-backups');
            if (!$backup) { return $this->reply(0, 'admin/multisite/backup_failed'); }
            try {
                $written = mac_arr2file($path, $domains);
                if ($written === false || !UrlSettings::matchesConfigFile($path, $domains)) { throw new \RuntimeException('write'); }
            } catch (\Throwable $error) {
                $restored = UrlSettings::restoreBackup($backup);
                foreach ($backup['files'] as $originalPath => $bytes) { $restored = @file_get_contents($originalPath) === $bytes && $restored; }
                return $this->reply(0, $restored ? 'admin/multisite/write_failed' : 'admin/multisite/restore_failed');
            }
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
            return $this->reply(1, $successKey);
        } catch (\Throwable $error) {
            return $this->reply(0, 'admin/multisite/write_failed');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function index()
    {
        if ($this->request->isPost()) {
            try {
                $post = $this->mutationInput();
                $existing = (array)config('domain');
                if (!isset($post['domain_revision']) || !is_string($post['domain_revision']) || !hash_equals(hash('sha256', serialize($existing)), $post['domain_revision'])) { throw new \InvalidArgumentException('admin/multisite/stale_form'); }
                $domains = self::fromForm($post, $existing);
            }
            catch (\InvalidArgumentException $error) { return $this->reply(0, $error->getMessage(), $error->getCode()); }
            return $this->writeDomains($domains, 'save_ok', hash('sha256', serialize($existing)));
        }
        if (!$this->request->isGet()) { return $this->reply(0, 'admin/multisite/post_required'); }
        $templates = System::availableTemplates();
        $templateNames = [];
        foreach ($templates as $template) {
            $templateNames[$template] = \app\admin\util\ThemeGate::displayName($template);
        }
        $this->assign('templates', $templates);
        $this->assign('template_names', $templateNames);
        $this->assign('domain_list', (array)config('domain'));
        $this->assign('domain_revision', hash('sha256', serialize((array)config('domain'))));
        $this->assign('multisite_token', $this->request->token());
        $title = lang('admin/ux/menu/domains');
        $this->assign('title', $title === 'admin/ux/menu/domains' ? lang('admin/domain/title') : $title);
        return $this->fetch('admin@domain/index');
    }

    public function del()
    {
        try {
            $post = $this->mutationInput();
            $domains = (array)config('domain');
            if (!isset($post['ids']) || !is_string($post['ids']) || !isset($domains[$post['ids']])) { throw new \InvalidArgumentException('admin/multisite/stale_form'); }
            $revision = hash('sha256', serialize($domains));
            unset($domains[$post['ids']]);
        } catch (\InvalidArgumentException $error) { return $this->reply(0, $error->getMessage(), $error->getCode()); }
        return $this->writeDomains($domains, 'del_ok', $revision);
    }

    public function export()
    {
        if (!$this->request->isGet()) { return $this->reply(0, 'illegal_request'); }
        $domains = (array)config('domain');
        $query = input('get.', [], '');
        $format = $query['format'] ?? '';
        if (!is_string($format)) { return $this->reply(0, 'illegal_request'); }
        if ($format === 'json') {
            // JSON also preserves custom overrides and text containing TXT separators.
            return response(json_encode($domains, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
                'Content-Type'=>'application/json; charset=utf-8',
                'Content-Disposition'=>'attachment; filename=mac_domains.json'
            ]);
        }
        // Keep the existing default seven-column TXT download route and bytes.
        $html = '';
        foreach ($domains as $entry) {
            $fields = [];
            foreach (self::FIELDS as $field) { $fields[] = $entry[$field]; }
            $html .= implode('$', $fields) . "\n";
        }
        return response($html, 200, [
            'Content-Type'=>'application/octet-stream',
            'Content-Disposition'=>'attachment; filename=mac_domains.txt'
        ]);
    }

    public function import()
    {
        try {
            $this->mutationInput();
            $file = $this->request->file('file');
            if (!($file instanceof \think\File) || !$file->isValid() || !$file->check(['size' => self::IMPORT_LIMIT, 'ext' => 'txt,json'])) {
                throw new \InvalidArgumentException('admin/multisite/invalid_upload');
            }
            $extension = strtolower(pathinfo($file->getInfo('name'), PATHINFO_EXTENSION));
            $bytes = @file_get_contents($file->getPathname(), false, null, 0, self::IMPORT_LIMIT + 1);
            $existing = (array)config('domain');
            $revision = hash('sha256', serialize($existing));
            $domains = self::importData($bytes, $extension, $existing);
        } catch (\InvalidArgumentException $error) { return $this->reply(0, $error->getMessage(), $error->getCode()); }
        // PHP owns the temporary upload lifetime; do not unlink arbitrary paths.
        return $this->writeDomains($domains, 'import_ok', $revision);
    }
}
