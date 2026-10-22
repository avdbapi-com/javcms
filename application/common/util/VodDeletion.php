<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

use think\Db;

/** Permanent deletion. Callers must authorize and scope the supplied condition. */
class VodDeletion
{
    private static $mediaHandle;
    private static $mediaDepth = 0;
    private $schema = [];
    private $primaryKeys = [];
    private $engines = [];
    private $candidates = [];
    private $selectedDiscoveryJobs = [];
    private $jobs = [];
    private $staticFiles = [];
    private $staticNames = [];
    private $staticFailed = false;
    private $staticTypeCache = [];

    /** Internal cleanup transactions retain the same media lock and may advance their own job. */
    public static function withCleanupLock(callable $operation)
    {
        return self::withMediaLock($operation, true);
    }

    private static function assertCleanupAllowsWriter()
    {
        try { \app\common\model\VodCleanup::assertWritesAllowed(); }
        catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'vod/cleanup_busy') { throw $error; }
            throw new \RuntimeException('Video media is busy. Retry shortly.');
        }
    }

    /** All media-reference writers must acquire this before starting transactions. */
    public static function withMediaLock(callable $operation, $cleanup = false)
    {
        if (self::$mediaDepth > 0) {
            self::$mediaDepth++;
            try { return $operation(); } finally { self::$mediaDepth--; }
        }
        $directory = defined('RUNTIME_PATH') ? RUNTIME_PATH : ROOT_PATH . 'runtime' . DIRECTORY_SEPARATOR;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Media lock directory unavailable.');
        }
        $handle = @fopen($directory . 'vod-media-integrity.lock', 'c');
        if (!$handle) { throw new \RuntimeException('Media lock unavailable.'); }
        $deadline = microtime(true) + 5;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                self::$mediaHandle = $handle;
                self::$mediaDepth = 1;
                try { if (!$cleanup) { self::assertCleanupAllowsWriter(); } return $operation(); }
                finally { self::$mediaDepth = 0; self::$mediaHandle = null; flock($handle, LOCK_UN); fclose($handle); }
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        fclose($handle);
        throw new \RuntimeException('Video media is busy. Retry shortly.');
    }

    /**
     * Permanent deletion of the videos matching $where.
     * $options (1.2.0, lane P): 'tombstones' (default true: the provider identities of a deleted video stay as
     * tombstones vod_id 0 / state 2, so scheduled and bulk imports do not bring it back, D-I9; false for undo / remove
     * all, D-I13), 'ft' ('forget' default: FtIndex::forget of the deleted ids, 'skip' when the caller rebuilds the search
     * table), 'purge' (default true: EdgeCache::purge of the deleted ids; undo purges its range once at the end),
     * 'own_job' (the import job id of the caller, whose own step lease does not block the deletion).
     */
    public static function deleteWhere(array $where, array $options = [])
    {
        if (!$where) { throw new \InvalidArgumentException('An explicit deletion condition is required.'); }
        $result = self::withMediaLock(function () use ($where, $options) {
            $run = new self();
            $run->tombstones = !array_key_exists('tombstones', $options) || !empty($options['tombstones']);
            $run->ownJob = (string)($options['own_job'] ?? '');
            return $run->execute($where);
        });
        $ids = array_values(array_filter(array_map('intval', array_column((array)($result['deleted_rows'] ?? []), 'vod_id'))));
        if ($ids) {
            // The detail cache goes before the edge purge (HO-H-6: a purged page whose detail row is still cached would
            // render 200 again and be stored again).
            try { $vod = new \app\common\model\Vod(); foreach ((array)$result['deleted_rows'] as $row) { $vod->invalidateDetailCache($row); } }
            catch (\Throwable $e) { error_log('[VodDeletion] detail cache: ' . get_class($e)); }
            // After commit: search documents (C1), edge copies (C5, deletions only), counts (C4). Never break a deletion.
            foreach ([['FtIndex', 'forget', ($options['ft'] ?? 'forget') !== 'skip'], ['EdgeCache', 'purge', !array_key_exists('purge', $options) || !empty($options['purge'])]] as $call) {
                if (!$call[2]) { continue; }
                try { $class = __NAMESPACE__ . '\\' . $call[0]; if (class_exists($class)) { call_user_func([$class, $call[1]], $ids); } }
                catch (\Throwable $e) { error_log('[VodDeletion] ' . $call[0] . '::' . $call[1] . ': ' . get_class($e)); }
            }
            try { if (class_exists(__NAMESPACE__ . '\\ScaleCounts')) { ScaleCounts::markDirty('delete'); } } catch (\Throwable $e) { error_log('[VodDeletion] ScaleCounts: ' . get_class($e)); }
            // C14 (XVID-NOLIMIT-BRIEF B1): deleted rows shift the deep list pages from the lowest deleted vod_time
            self::pageMapDirty((array)$result['deleted_rows']);
        }
        return $result;
    }

    private $tombstones = true;
    private $ownJob = '';

    /** ListPageMap::dirty(lowest vod_time, type ids) for rows that left the lists (no-op until lane S ships the class). */
    public static function pageMapDirty(array $rows)
    {
        $class = __NAMESPACE__ . '\\ListPageMap';
        if (!$rows || !class_exists($class) || !method_exists($class, 'dirty')) { return; }
        $min = null; $types = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['vod_time'])) { continue; }
            $t = max(0, (int)$row['vod_time']); $min = $min === null ? $t : min($min, $t);
            foreach (['type_id', 'type_id_1'] as $k) { if ((int)($row[$k] ?? 0) > 0) { $types[(int)$row[$k]] = (int)$row[$k]; } }
        }
        if ($min === null) { return; }
        try { call_user_func([$class, 'dirty'], (int)$min, array_values($types)); } catch (\Throwable $e) { error_log('[VodDeletion] ListPageMap: ' . get_class($e)); }
    }

    private function fields($name)
    {
        if (!array_key_exists($name, $this->schema)) {
            // Names are internal constants; values are bound, never interpolated.
            $table = Db::name($name)->getTable();
            $rows = Db::query('SELECT c.COLUMN_NAME,c.COLUMN_KEY,t.ENGINE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=:table_name ORDER BY c.ORDINAL_POSITION', ['table_name' => $table], true);
            $this->schema[$name] = array_column($rows, 'COLUMN_NAME');
            $this->engines[$name] = $rows ? $rows[0]['ENGINE'] : null;
            $this->primaryKeys[$name] = [];
            foreach ($rows as $row) { if ($row['COLUMN_KEY'] === 'PRI') { $this->primaryKeys[$name][] = $row['COLUMN_NAME']; } }
        }
        return $this->schema[$name];
    }

    private function requireFields($table, array $required)
    {
        $fields = $this->fields($table);
        if ($fields && array_diff($required, $fields)) {
            throw new \RuntimeException('Unsupported video relation schema: ' . $table);
        }
        if ($fields && strcasecmp((string)$this->engines[$table], 'InnoDB') !== 0) {
            throw new \RuntimeException('Video deletion requires a transactional relation: ' . $table);
        }
        return (bool)$fields;
    }

    private function execute(array $where)
    {
        $deleted = [];
        if (!$this->requireFields('vod', ['vod_id'])) { throw new \RuntimeException('Video table unavailable.'); }
        Db::startTrans();
        try {
            $cursor = 0;
            while (true) {
                // Discovery stays unlocked until import jobs/items/maps are fenced.
                $scope = function ($query) use ($where) { $query->where($where); };
                $ids = Db::name('vod')->where($scope)->where('vod_id', '>', $cursor)->order('vod_id')->limit(250)->column('vod_id');
                if (!$ids) { break; }
                $ids = array_map('intval', $ids);
                if ($cursor === 0) { $this->fenceDiscoveryJobs(); }
                $cursor = max($ids);
                $this->removeImportRelations($ids);
                $rows = Db::name('vod')->where($scope)->where('vod_id', 'in', $ids)->order('vod_id')->lock(true)->select();
                if (count($rows) !== count($ids)) { throw new \RuntimeException('Video selection changed. Retry deletion.'); }
                $locked = [];
                foreach ($rows as $row) {
                    $locked[] = (int)$row['vod_id'];
                    $this->collect($row);
                    $this->collectStaticDetail($row);
                    $deleted[] = array_intersect_key($row, array_flip(['vod_id','vod_en','vod_sub','vod_time','type_id','type_id_1']));
                }
                if (!$locked) { continue; }
                foreach (['avdb_translation','translate','translate_lang','vod_translation'] as $table) {
                    if ($this->requireFields($table, ['vod_id'])) {
                        foreach (Db::name($table)->where('vod_id', 'in', $locked)->select() as $row) { $this->collect($row); }
                        Db::name($table)->where('vod_id', 'in', $locked)->delete();
                    }
                }
                foreach (['comment' => ['comment_mid','comment_rid'], 'ulog' => ['ulog_mid','ulog_rid']] as $table => $columns) {
                    if ($this->requireFields($table, $columns)) {
                        $this->collectRelatedRows($table, [$columns[0]=>1, $columns[1]=>['in',$locked]]);
                        Db::name($table)->where($columns[0], 1)->where($columns[1], 'in', $locked)->delete();
                    }
                }
                if ($this->requireFields('role', ['role_rid'])) {
                    $this->collectRelatedRows('role', ['role_rid'=>['in',$locked]]);
                    Db::name('role')->where('role_rid', 'in', $locked)->delete();
                }
                $this->removeTopicLinks($locked);
                $this->removeSearchResults($locked);
                Db::name('vod')->where('vod_id', 'in', $locked)->delete();
            }
            Db::commit();
        } catch (\Throwable $error) { Db::rollback(); throw $error; }

        $result = ['code' => 1, 'msg' => 'ok', 'deleted' => count($deleted), 'deleted_rows' => $deleted,
            'files_deleted' => 0, 'files_preserved' => 0, 'html_deleted' => 0, 'html_preserved' => 0,
            'cleanup_failed' => $this->staticFailed];
        // Database success is final. A failed reference scan fails closed for files.
        try {
            $referenced = $this->referencedCandidates();
            foreach ($this->candidates as $relative => $unused) {
                $path = self::localUploadPath($relative);
                if (isset($referenced[$relative]) || !$path || !is_file($path)) { $result['files_preserved']++; continue; }
                if (@unlink($path)) { $result['files_deleted']++; }
                else { $result['files_preserved']++; $result['cleanup_failed'] = true; }
            }
        } catch (\Throwable $error) { $result['cleanup_failed'] = true; $result['files_preserved'] = count($this->candidates); }
        try {
            $sharedHtml = $this->sharedStaticDetails();
            foreach ($this->staticFiles as $relative => $unused) {
                if (isset($sharedHtml[$relative])) { $result['html_preserved']++; continue; }
                $path = self::localStaticHtmlPath($relative);
                if ($path === null) {
                    if (file_exists(ROOT_PATH . $relative) || is_link(ROOT_PATH . $relative)) { $result['cleanup_failed'] = true; $result['html_preserved']++; }
                    continue;
                }
                if (@unlink($path)) { $result['html_deleted']++; }
                else { $result['cleanup_failed'] = true; $result['html_preserved']++; }
            }
        } catch (\Throwable $error) { $result['cleanup_failed'] = true; $result['html_preserved'] = count($this->staticFiles); }
        return $result;
    }

    private function collectRelatedRows($table, array $where)
    {
        $primary = count($this->primaryKeys[$table]) === 1 ? $this->primaryKeys[$table][0] : null;
        $cursor = null;
        $offset = 0;
        do {
            $query = Db::name($table)->where($where)->lock(true);
            if ($primary) {
                $query->order($primary)->limit(250);
                if ($cursor !== null) { $query->where($primary, '>', $cursor); }
            } else {
                if ($this->primaryKeys[$table]) { $query->order(implode(',', $this->primaryKeys[$table])); }
                $query->limit($offset, 250);
            }
            $rows = $query->select();
            foreach ($rows as $row) { $this->collect($row); if ($primary) { $cursor = $row[$primary]; } }
            $offset += 250;
        } while (count($rows) === 250);
    }

    private function collectStaticDetail(array $row)
    {
        if ((int)($GLOBALS['config']['view']['vod_detail'] ?? 0) !== 2) { return; }
        try {
            $relative = $this->staticDetailName($row);
            if ($relative === null) { $this->staticFailed = true; return; }
            $this->staticFiles[$relative] = true;
            $this->staticNames[(string)($row['vod_sub'] ?? '')] = true;
        } catch (\Throwable $error) { $this->staticFailed = true; }
    }

    private function staticDetailName(array $row)
    {
        $pattern = (string)($GLOBALS['config']['path']['vod_detail'] ?? '');
        if ($pattern === '' || preg_match('~[\\\\\x00%?#:]~', $pattern)) { return null; }
        $row['type'] = ['type_en'=>'','type_pid'=>0];
        $row['type_1'] = ['type_id'=>0,'type_en'=>''];
        if (preg_match('/\{type_(?:en|pid|pen)\}/', $pattern)) {
            if (array_diff(['type_id','type_pid','type_en'], $this->fields('type'))) { throw new \RuntimeException('Static detail category context unavailable.'); }
            $typeId = (int)$row['type_id'];
            if (!array_key_exists($typeId, $this->staticTypeCache)) { $this->staticTypeCache[$typeId] = Db::name('type')->where('type_id', $typeId)->field('type_id,type_pid,type_en')->find(); }
            $type = $this->staticTypeCache[$typeId];
            if ($type) {
                $row['type'] = $type;
                if ((int)$type['type_pid']) {
                    $parentId = (int)$type['type_pid'];
                    if (!array_key_exists($parentId, $this->staticTypeCache)) { $this->staticTypeCache[$parentId] = Db::name('type')->where('type_id', $parentId)->field('type_id,type_pid,type_en')->find(); }
                    $row['type_1'] = $this->staticTypeCache[$parentId] ?: $row['type_1'];
                }
            }
        }
        $hadLanguage = array_key_exists('translang', $_GET);
        $language = $_GET['translang'] ?? null;
        unset($_GET['translang']);
        try { $name = reset_html_filename(mac_url_vod_detail($row)); }
        finally { if ($hadLanguage) { $_GET['translang'] = $language; } }
        return self::staticHtmlReference($name);
    }

    private static function staticHtmlReference($name)
    {
        $name = (string)$name;
        if ($name === '' || preg_match('~[\\\\\x00%?#:]~', $name) || strpos($name, '//') === 0) { return null; }
        if (strpos($name, './') === 0) { $name = substr($name, 2); }
        if (strpos($name, '/') === 0) { $name = substr($name, 1); }
        $parts = explode('/', $name);
        foreach ($parts as $part) { if ($part === '' || $part === '.' || $part === '..' || $part[0] === '.') { return null; } }
        $reserved = ['application','thinkphp','runtime','vendor','extend','template','upload','static','assets','addons','install'];
        if (in_array(strtolower($parts[0]), $reserved, true) || !preg_match('/\.(?:html|htm|shtml)$/iD', $name)) { return null; }
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($name) : $name;
    }

    /** Static output is authorized only as an exact regular HTML file under ROOT_PATH. */
    public static function localStaticHtmlPath($name)
    {
        $relative = self::staticHtmlReference($name);
        if ($relative === null) { return null; }
        $site = realpath(ROOT_PATH);
        $path = realpath(ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($site === false || $path === false || !is_file($path)) { return null; }
        if (self::pathIdentity($path) !== rtrim(self::pathIdentity($site), '/') . '/' . $relative) { return null; }
        $component = rtrim(ROOT_PATH, '/\\');
        foreach (explode('/', $relative) as $part) { $component .= DIRECTORY_SEPARATOR . $part; if (is_link($component)) { return null; } }
        return $path;
    }

    private function sharedStaticDetails()
    {
        if (!$this->staticFiles) { return []; }
        $pattern = (string)$GLOBALS['config']['path']['vod_detail'];
        if (strpos($pattern, '{id}') !== false || strpos($pattern, '{md5}') !== false) { return []; }
        $shared = [];
        $cursor = 0;
        do {
            $query = Db::name('vod')->field('vod_id,vod_sub,vod_time,type_id')->where('vod_id', '>', $cursor)->order('vod_id')->limit(250);
            if (strpos($pattern, '{en}') !== false) { $query->where('vod_sub', 'in', array_keys($this->staticNames)); }
            $rows = $query->select();
            foreach ($rows as $row) {
                $cursor = (int)$row['vod_id'];
                $relative = $this->staticDetailName($row);
                if ($relative !== null && isset($this->staticFiles[$relative])) { $shared[$relative] = true; }
            }
            if (count($shared) === count($this->staticFiles)) { break; }
        } while (count($rows) === 250);
        return $shared;
    }

    private function removeImportRelations(array $ids)
    {
        $hasMaps = $this->requireFields('avdb_import_map', ['provider','remote_id','vod_id']);
        $hasItems = $this->requireFields('avdb_import_item', ['item_id','job_id','remote_id','vod_id','payload','before_image','after_image']);
        $maps = $hasMaps ? Db::name('avdb_import_map')->where('vod_id', 'in', $ids)->select() : [];
        // Remote ids of the deleted videos per provider: a job only matches identities of its own provider.
        $remoteBy = [];
        foreach ($maps as $map) { $remoteBy[(string)$map['provider']][] = (int)$map['remote_id']; }
        $jobRemotes = [];
        $zeroMapRemotes = [];
        foreach ($this->jobs as $jobId => $job) {
            $options = json_decode($job['options'], true);
            $options = is_array($options) ? $options : [];
            $jobProvider = isset($job['provider']) && (string)$job['provider'] !== '' ? (string)$job['provider'] : 'avdb';
            if ($this->ownJob !== '' && $jobId === $this->ownJob) { continue; }
            $matching = array_fill_keys($remoteBy[$jobProvider] ?? [], true);
            $links = (array)($options['existing_links'] ?? []);
            $changed = false;
            foreach ($links as $remoteId => $localId) {
                if (!is_scalar($localId) || !preg_match('/^[1-9][0-9]*$/D', (string)$remoteId) || !preg_match('/^[1-9][0-9]*$/D', (string)$localId)) {
                    throw new \RuntimeException('Invalid import association options.');
                }
                if (in_array((int)$localId, $ids, true)) {
                    $matching[(int)$remoteId] = true;
                    $zeroMapRemotes[(int)$remoteId] = true;
                    unset($links[$remoteId]);
                    $changed = true;
                } else { unset($matching[(int)$remoteId]); }
            }
            if (($changed || $matching) && $job['lock_token'] !== '' && (int)$job['lock_until'] > time()) {
                throw new \RuntimeException('A related import is running. Pause it and retry deletion.');
            }
            if ($changed) {
                $options['existing_links'] = $links;
                $encoded = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) { throw new \RuntimeException('Invalid import association options.'); }
                Db::name('avdb_import_job')->where('job_id', $jobId)->update(['options'=>$encoded]);
                $this->jobs[$jobId]['options'] = $encoded;
                if (!(int)$job['discovery_done']) { $this->sealDiscovery($job); }
            }
            if ($matching) { $jobRemotes[$jobId] = array_keys($matching); }
        }
        foreach ($this->selectedDiscoveryJobs as $job) {
            $options = json_decode($job['options'], true);
            if (array_intersect($jobRemotes[$job['job_id']] ?? [], array_map('intval', (array)($options['ids'] ?? [])))) { $this->sealDiscovery($job); }
        }
        $condition = function ($query) use ($ids, $jobRemotes) {
            $query->where('vod_id', 'in', $ids);
            foreach ($jobRemotes as $jobId => $matches) {
                $query->whereOr(function ($nested) use ($jobId, $matches) {
                    $nested->where('job_id', $jobId)->where('remote_id', 'in', $matches);
                });
            }
        };
        if ($hasItems) {
            $jobs = Db::name('avdb_import_item')->where($condition)->distinct(true)->column('job_id');
            sort($jobs, SORT_STRING);
            if ($jobs && !$this->requireFields('avdb_import_job', ['job_id','lock_token','lock_until'])) {
                throw new \RuntimeException('Import job fence unavailable.');
            }
            foreach ($jobs as $id) {
                $job = Db::name('avdb_import_job')->where('job_id', $id)->lock(true)->find();
                if ($job && $job['lock_token'] !== '' && (int)$job['lock_until'] > time() && $id !== $this->ownJob) {
                    throw new \RuntimeException('A related import is running. Pause it and retry deletion.');
                }
                if ($job && isset($job['discovery_done']) && !(int)$job['discovery_done']) {
                    $this->sealDiscovery($job);
                }
            }
            $cursor = 0;
            do {
                $items = Db::name('avdb_import_item')->where($condition)->where('item_id', '>', $cursor)->order('item_id')->limit(250)->lock(true)->select();
                $itemIds = [];
                foreach ($items as $item) { $cursor = (int)$item['item_id']; $itemIds[] = $cursor; $this->collect($item); }
                if ($itemIds) { Db::name('avdb_import_item')->where('item_id', 'in', $itemIds)->delete(); }
            } while (count($items) === 250);
        }
        if ($hasMaps) {
            Db::name('avdb_import_map')->where('vod_id', 'in', $ids)->order('provider,remote_id')->lock(true)->select();
            // D-I9: an administrator's delete leaves a tombstone (vod_id 0, state 2); undo / remove all forget the identity.
            $mapFields = $this->fields('avdb_import_map');
            if ($this->tombstones && in_array('state', $mapFields, true)) {
                $set = ['vod_id' => 0, 'state' => 2];
                if (in_array('row_hash', $mapFields, true)) { $set['row_hash'] = ''; }
                Db::name('avdb_import_map')->where('vod_id', 'in', $ids)->update($set);
            } else {
                Db::name('avdb_import_map')->where('vod_id', 'in', $ids)->delete();
            }
            if ($zeroMapRemotes) {
                $zero = Db::name('avdb_import_map')->where('provider', 'avdb')->where('vod_id', 0)->where('remote_id', 'in', array_keys($zeroMapRemotes));
                if (in_array('state', $mapFields, true)) { $zero->where('state', '<>', 2); }
                $zero->delete();
            }
        }
    }

    private function sealDiscovery(array $job)
    {
        $provider = isset($job['provider']) && (string)$job['provider'] !== '' ? (string)$job['provider'] : 'avdb';
        $update = ['discovery_done'=>1, 'state'=>\app\common\model\AvdbImport::stateFor('paused', $provider), 'lock_token'=>'', 'lock_until'=>0];
        if (array_key_exists('error', $job)) { $update['error'] = 'Discovery stopped because a video was permanently deleted. Existing unrelated items may resume; start a new import to discover further videos.'; }
        Db::name('avdb_import_job')->where('job_id', $job['job_id'])->update($update);
        if (isset($this->jobs[$job['job_id']])) { $this->jobs[$job['job_id']]['discovery_done'] = 1; }
    }

    private function fenceDiscoveryJobs()
    {
        if (!$this->requireFields('avdb_import_job', ['job_id','lock_token','lock_until','discovery_done','options'])) { return; }
        $cursor = '';
        do {
            $jobs = Db::name('avdb_import_job')->where('job_id', '>', $cursor)->order('job_id')->limit(250)->lock(true)->select();
            foreach ($jobs as $job) {
                $cursor = $job['job_id'];
                $this->jobs[$job['job_id']] = $job;
                if (!(int)$job['discovery_done'] && $job['lock_token'] !== '' && (int)$job['lock_until'] > time() && $job['job_id'] !== $this->ownJob) {
                    throw new \RuntimeException('An import is still discovering videos. Pause it and retry deletion.');
                }
                if ((int)$job['discovery_done']) { continue; }
                $options = json_decode($job['options'], true);
                if (($options['mode'] ?? '') === 'selected') { $this->selectedDiscoveryJobs[] = $job; }
                else { $this->sealDiscovery($job); }
            }
        } while (count($jobs) === 250);
    }

    private function removeTopicLinks(array $ids)
    {
        if (!$this->requireFields('topic', ['topic_id','topic_rel_vod'])) { return; }
        $cursor = 0;
        $remove = array_fill_keys($ids, true);
        do {
            $rows = Db::name('topic')->where('topic_id', '>', $cursor)->order('topic_id')->limit(250)->field('topic_id,topic_rel_vod')->select();
            foreach ($rows as $row) {
                $cursor = (int)$row['topic_id'];
                $kept = [];
                foreach (explode(',', $row['topic_rel_vod']) as $id) { if (!isset($remove[(int)trim($id)]) || !ctype_digit(trim($id))) { $kept[] = $id; } }
                $value = implode(',', $kept);
                if ($value !== $row['topic_rel_vod']) { Db::name('topic')->where('topic_id', $cursor)->update(['topic_rel_vod' => $value]); }
            }
        } while (count($rows) === 250);
    }

    private function removeSearchResults(array $ids)
    {
        if (!$this->requireFields('vod_search', ['search_key','search_result_ids'])) { return; }
        $cursor = '';
        $remove = array_fill_keys($ids, true);
        do {
            $rows = Db::name('vod_search')->where('search_key', '>', $cursor)->order('search_key')->limit(250)->field('search_key,search_result_ids')->select();
            foreach ($rows as $row) {
                $cursor = $row['search_key'];
                foreach (explode(',', $row['search_result_ids']) as $id) {
                    if (isset($remove[(int)trim($id)]) && ctype_digit(trim($id))) { Db::name('vod_search')->where('search_key', $cursor)->delete(); break; }
                }
            }
        } while (count($rows) === 250);
    }

    private function collect($value)
    {
        foreach (self::imageReferences($value) as $relative) { $this->candidates[$relative] = true; }
    }

    /** Recognize local raster image references in fields, HTML and JSON snapshots. */
    public static function imageReferences($value)
    {
        $found = [];
        $walk = function ($entry) use (&$walk, &$found) {
            if (is_array($entry)) { foreach ($entry as $item) { $walk($item); } return; }
            if (!is_string($entry) || $entry === '') { return; }
            $decoded = json_decode($entry, true);
            if (is_array($decoded)) { $walk($decoded); return; }
            $text = html_entity_decode($entry, ENT_QUOTES, 'UTF-8');
            $text = preg_replace('~(?:[a-z][a-z0-9+.-]*:|//)[^\s<>"\'(){}\[\]]+~i', '', $text);
            // Capture the entire URL token so external /upload paths are never adopted.
            preg_match_all('~[^\s<>"\'\#|(){}\[\],;=]+\.(?:jpe?g|png|gif|webp|avif|bmp)(?:\?[^\s<>"\'\#|(){}\[\],;]*)?~i', $text, $matches);
            foreach ($matches[0] as $token) {
                $relative = self::uploadReference($token);
                if ($relative !== null) { $found[$relative] = true; }
            }
        };
        $walk($value);
        return array_keys($found);
    }

    private static function uploadReference($value)
    {
        $value = trim((string)$value);
        if ($value === '' || strpos($value, '\\') !== false || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $value)) { return null; }
        $value = preg_replace('~[?#].*$~', '', $value);
        if (strpos($value, '%') !== false || strpos($value, "\0") !== false) { return null; }
        if (strpos($value, './') === 0) { $value = substr($value, 2); }
        if (strpos($value, '/') === 0) { $value = substr($value, 1); }
        if (!preg_match('~^upload/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:jpe?g|png|gif|webp|avif|bmp)$~iD', $value)) { return null; }
        foreach (explode('/', $value) as $part) { if ($part === '.' || $part === '..') { return null; } }
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
    }

    /** Resolve every component; reject symlink/junction escape and root deletion. */
    public static function localUploadPath($value)
    {
        $relative = self::uploadReference($value);
        if ($relative === null) { return null; }
        $root = realpath(ROOT_PATH . 'upload');
        $site = realpath(ROOT_PATH);
        $path = realpath(ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if ($root === false || $site === false || $path === false || !is_file($path)) { return null; }
        $root = str_replace('\\', '/', $root);
        $site = rtrim(str_replace('\\', '/', $site), '/');
        $path = str_replace('\\', '/', $path);
        $compareRoot = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
        $comparePath = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        $compareSite = DIRECTORY_SEPARATOR === '\\' ? strtolower($site) : $site;
        if ($compareRoot !== $compareSite . '/upload' || $comparePath !== $compareSite . '/' . $relative) { return null; }
        if (strpos($comparePath, $compareRoot . '/') !== 0) { return null; }
        // Even an in-root symbolic alias is unsafe ownership evidence for unlink.
        $component = rtrim(ROOT_PATH, '/\\');
        foreach (explode('/', $relative) as $part) { $component .= DIRECTORY_SEPARATOR . $part; if (is_link($component)) { return null; } }
        return $path;
    }

    private function referencedCandidates()
    {
        $referenced = [];
        if (!$this->candidates) { return $referenced; }
        $canonicalCandidates = [];
        foreach ($this->candidates as $relative => $unused) {
            $path = self::localUploadPath($relative);
            if ($path !== null) { $canonicalCandidates[self::pathIdentity($path)][] = $relative; }
        }
        // Only known content-bearing modules; unrelated records are never mutated.
        $tables = ['vod','art','actor','role','topic','type','website','link','annex','comment','gbook',
            'avdb_translation','translate','translate_lang','vod_translation','avdb_import_item'];
        foreach ($tables as $table) {
            $fields = $this->fields($table);
            if (!$fields) { continue; }
            $columns = array_values(array_filter($fields, function ($field) {
                return preg_match('/(?:pic|logo|icon|file|path|url|content|text|descs|blurbs|image|payload|translations|screenshot)/i', $field);
            }));
            if (!$columns) { continue; }
            // Server-side matching avoids transferring every catalog text field.
            // Encoded HTML/JSON falls back to parsing so encoding cannot hide refs.
            foreach ($columns as $column) {
                if (!preg_match('/^[A-Za-z0-9_]+$/D', $column)) { throw new \RuntimeException('Unsupported media reference field.'); }
            }
            $text = "LOWER(CONCAT(' ',CONCAT_WS(' ',`" . implode('`,`', $columns) . '`)))';
            $encoded = '(' . $text . " LIKE :media_entity ESCAPE '=' OR " . $text . " LIKE :media_unicode ESCAPE '=' OR " . $text . " LIKE :media_named ESCAPE '=' OR " . $text . " LIKE :media_html ESCAPE '=')";
            $primary = count($this->primaryKeys[$table]) === 1 ? $this->primaryKeys[$table][0] : null;
            $selected = $columns;
            if ($primary && !in_array($primary, $selected, true)) { $selected[] = $primary; }
                $bind = ['media_entity'=>'%&#%', 'media_unicode'=>'%\\u%', 'media_named'=>'%&sol;%', 'media_html'=>'%&%;%'];
                // Do not narrow by basename: a surviving symbolic alias can have
                // a different filename from the candidate it keeps alive.
                // One bound regular expression replaces 66 LIKE predicates;
                // the private representative benchmark proves equivalent matches.
                $slash = str_repeat('\\', 2) . '/';
                $bind['media_local_pattern'] = '[ ' . "\n\r\t" . '"' . chr(39) . '(=#|>]((([.]/|/)?upload/)|(([.]' . $slash . '|' . $slash . ')?upload' . $slash . '))';
                $filter = '(' . $text . ' REGEXP :media_local_pattern OR ' . $encoded . ')';
                $offset = 0;
                $cursor = null;
                do {
                    $query = Db::name($table)->field(implode(',', $selected))->whereRaw($filter, $bind);
                    if ($primary) {
                        $query->order($primary);
                        if ($cursor !== null) { $query->where($primary, '>', $cursor); }
                        $query->limit(250);
                    } else {
                        if ($this->primaryKeys[$table]) { $query->order(implode(',', $this->primaryKeys[$table])); }
                        $query->limit($offset, 250);
                    }
                    $rows = $query->select();
                    foreach ($rows as $row) {
                        foreach (self::imageReferences($row) as $relative) {
                            if (isset($this->candidates[$relative])) { $referenced[$relative] = true; }
                            $target = realpath(ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, $relative));
                            if ($target !== false && is_file($target)) {
                                $identity = self::pathIdentity($target);
                                foreach ($canonicalCandidates[$identity] ?? [] as $candidate) { $referenced[$candidate] = true; }
                            }
                        }
                        if ($primary) { $cursor = $row[$primary]; }
                    }
                    $offset += 250;
                } while (count($rows) === 250);
        }
        return $referenced;
    }

    private static function pathIdentity($path)
    {
        $path = str_replace('\\', '/', $path);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
