<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\model;

use think\Db;
use think\Cache;
use app\common\util\Pinyin;

class VodSearch extends Base {
    // Table name without the configured prefix; stores cached video searches.
    protected $name = 'vod_search';
    // Result ID limit for IN queries.
    public $maxIdCount = 1000;
    // private $resultCacheDays = 14;
    private $updateTopCount  = 50000;


    /* *
 * Get the matching video IDs for a search. */
    public function getResultIdList($search_word, $search_field, $word_multiple = false)
    {
        $search_word = trim($search_word);
        $search_word = str_replace(',,', '', $search_word);
        if (strlen($search_word) == 0 || strlen($search_field) == 0) {
            return [];
        }
        // Search each comma-separated term when multiple-keyword mode is enabled.
        if ($word_multiple === true) {
            $id_list = [];
            $search_word_exploded = explode(',', $search_word);
            foreach ($search_word_exploded as $search_word_part) {
                $search_word_part = trim(mb_strtolower($search_word_part));
                if ($search_word_part === '') {
                    continue;
                }
                $id_list = array_merge($id_list, $this->getResultIdList($search_word_part, $search_field));
            }
            return $this->normalizeResultIds($id_list);
        }
        $search_key = md5($search_word . '@' . $search_field);
        $where = ['search_key' => $search_key];
        $search_row = $this->where($where)->field("search_result_ids, search_hit_count")->find();
        if (empty($search_row)) {
            $lookup = function ($persist) use ($search_word,$search_field,$search_key) {
                $where_vod = [];
                $where_vod[$search_field] = ['LIKE', '%' . $search_word . '%'];
                $ids = $this->normalizeResultIds(Db::name('Vod')->where($where_vod)->order('vod_id ASC')->column('vod_id'));
                if ($persist) {
                    // A second cache miss may have completed while this reader waited for the lock.
                    if (!$this->where('search_key', $search_key)->find()) {
                        $this->insert([
                            'search_key'=>$search_key, 'search_word'=>mb_substr($search_word,0,128),
                            'search_field'=>mb_substr($search_field,0,64), 'search_hit_count'=>1,
                            'search_last_hit_time'=>time(), 'search_update_time'=>time(),
                            'search_result_count'=>count($ids), 'search_result_ids'=>join(',', $ids),
                        ]);
                    }
                }
                return $ids;
            };
            try {
                $id_list = \app\common\util\VodDeletion::withMediaLock(function () use ($lookup) { return $lookup(true); });
            } catch (\RuntimeException $error) {
                if ($error->getMessage() !== 'Video media is busy. Retry shortly.') { throw $error; }
                $id_list = $lookup(false);
            }
        } else {
            $id_list = $this->normalizeResultIds($search_row['search_result_ids']);
            $this->where($where)->update([
                'search_hit_count'     => $search_row['search_hit_count'] + 1,
                'search_last_hit_time' => time(),
            ]);
        }
        $id_list = $this->normalizeResultIds($id_list);
        $id_list = empty($id_list) ? [0] : $id_list;
        return $id_list;
    }

    /**
     * Normalize cached IDs while preserving their first-seen order.
     */
    private function normalizeResultIds($ids)
    {
        if (!is_array($ids)) {
            $ids = explode(',', (string)$ids);
        }
        $unique = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $unique[$id] = $id;
            }
        }
        return array_values($unique);
    }

    /* *
 * Check whether search optimization is enabled on the public site. */
    public function isFrontendEnabled()
    {
        $config = config('maccms');
        // Disable search optimization when the setting is missing.
        if (!isset($config['app']['vod_search_optimise'])) {
            return false;
        }
        $list = explode('|', $config['app']['vod_search_optimise']);
        return in_array('frontend', $list);
    }

    /* *
 * Check whether search optimization is enabled during collection. */
    public function isCollectEnabled()
    {
        $config = config('maccms');
        // Disable search optimization when the setting is missing.
        if (!isset($config['app']['vod_search_optimise'])) {
            return false;
        }
        $list = explode('|', $config['app']['vod_search_optimise']);
        return in_array('collect', $list);
    }

    /* *
 * Update matching popular search results for this video. */
    public function checkAndUpdateTopResults($vod, $force = false)
    {
        try {
            return \app\common\util\VodDeletion::withMediaLock(function () use ($vod,$force) { return $this->checkAndUpdateTopResultsWithMediaLock($vod,$force); });
        } catch (\RuntimeException $error) {
            if ($error->getMessage() !== 'Video media is busy. Retry shortly.') { throw $error; }
            return;
        }
    }

    private function checkAndUpdateTopResultsWithMediaLock($vod, $force = false)
    {
        static $list;
        if (empty($vod['vod_id'])) {
            return;
        }
        if (is_null($list)) {
            $cach_name = 'vod_search_top_result_v2_' . $this->updateTopCount;
            $list = $force ? [] : Cache::get($cach_name);
            if (empty($list)) {
                $list = $this->field("search_key, search_word, search_field")->order("search_hit_count DESC, search_last_hit_time DESC")->limit("0," . $this->updateTopCount)->select();
                $force === false && Cache::set($cach_name, $list, count($list) < ($this->updateTopCount / 10) ? 3600 : 86400);
                $this->clearOldResult();
            }
        }
        $time_now = time();
        foreach ($list as $row) {
            foreach (explode('|', $row['search_field']) as $field) {
                if (!isset($vod[$field]) || strlen($vod[$field]) == 0) {
                    continue;
                }
                if (stripos($vod[$field], $row['search_word']) === false) {
                    continue;
                }
                $search_key = (string)$row['search_key'];
                if (!preg_match('/^[a-f0-9]{32}$/i', $search_key)) {
                    continue;
                }
                $vod_id = (int)$vod['vod_id'];
                $table = '`' . str_replace('`', '``', config('database.prefix') . $this->name) . '`';
                $sql = "UPDATE {$table} SET search_update_time=?, search_result_count=CASE WHEN FIND_IN_SET(?, search_result_ids) > 0 THEN search_result_count ELSE search_result_count+1 END, search_result_ids=CASE WHEN FIND_IN_SET(?, search_result_ids) > 0 THEN search_result_ids ELSE CONCAT_WS(',', NULLIF(search_result_ids, ''), ?) END WHERE search_key=?";
                Db::execute($sql, [$time_now, $vod_id, $vod_id, $vod_id, $search_key]);
            }
        }
    }

    /* *
 * Get the search cache lifetime in minutes; a positive setting overrides the default. */
    public function getResultCacheMinutes($config = []) {
        // Default 14 days
        $minutes = 20160;
        $config = $config ?: config('maccms');
        if (isset($config['app']['vod_search_optimise_cache_minutes']) && (int)$config['app']['vod_search_optimise_cache_minutes'] > 0) {
            $minutes = (int)$config['app']['vod_search_optimise_cache_minutes'];
        }
        return $minutes;
    }

    /* *
 * Delete expired cached search results. */
    public function clearOldResult($force = false) 
    {
        // Convert the configured cache lifetime from minutes to seconds.
        $clear_seconds = $this->getResultCacheMinutes() * 60;
        // Use the shorter of the cache lifetime and one day as the cleanup interval.
        $cach_name = 'interval_vs_clear_old_v1_' . $clear_seconds;
        $cache_data = Cache::get($cach_name);
        if ($force === false && !empty($cache_data)) {
            return;
        }
        Cache::set($cach_name, 1, min($clear_seconds, 86400));
        // Keep actor search results during normal cleanup to speed up collection.
        $where = [
            'search_field'       => ['neq', 'vod_actor'],
            'search_update_time' => ['lt', time() - $clear_seconds],
        ];
        // Include expired actor results when cleanup is forced.
        if ($force === true) {
            unset($where['search_field']);
        }
        $this->where($where)->delete();
    }

}
