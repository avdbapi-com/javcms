<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\model;

/** Schema is installed separately; request handling never creates columns. */
trait VodRecycleTrait
{
    protected function mergeRecycleWhere(array $where): array
    {
        $admin = defined('ENTRANCE') && ENTRANCE === 'admin';
        $mode = $admin && isset($where['_recycle']) ? $where['_recycle'] : 'active';
        unset($where['_recycle']);
        if (!$admin) {
            unset($where['vod_recycle_time']);
        }
        if ($mode === 'all') {
            return $where;
        }
        if ($mode === 'recycle') {
            $where['vod_recycle_time'] = ['gt', 0];
        } elseif (!isset($where['vod_recycle_time'])) {
            $where['vod_recycle_time'] = 0;
        }
        return $where;
    }

    public function recycleData($where)
    {
        return $this->setRecycleState($where, false);
    }

    public function restoreData($where)
    {
        return $this->setRecycleState($where, true);
    }

    private function setRecycleState($where, bool $restore): array
    {
        try {
            return \app\common\util\VodDeletion::withMediaLock(function () use ($where,$restore) { return $this->setRecycleStateWithMediaLock($where,$restore); });
        } catch (\RuntimeException $error) {
            return ['code'=>1002,'msg'=>lang('vod/permanent_delete_busy')];
        }
    }

    private function setRecycleStateWithMediaLock($where, bool $restore): array
    {
        if (!defined('ENTRANCE') || ENTRANCE !== 'admin' || !is_array($where)) {
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $where['_recycle'] = $restore ? 'recycle' : 'active';
        $state = $restore ? ['gt', 0] : 0;
        $where['vod_recycle_time'] = $state;
        $time = $restore ? 0 : time();
        $total = 0;
        // Reuse the normal search filters and update at most 500 videos at a time.
        // Each changed row leaves this first page; no unbounded OFFSET scan is needed.
        while (true) {
            $result = $this->listData($where, 'vod_id asc', 1, 500, 0, 'vod_id,vod_en,vod_sub', 0, 0);
            $rows = $result['list'];
            if (empty($rows)) { break; }
            $ids = array_column($rows, 'vod_id');
            // fp-port-c (FINISH-PORT-REVIEW-b P2-1): each chunk's videos are queued for the search table in the transaction of
            // the chunk's update (Vod::queuedWrite), so a run cut after a chunk (browser closed, timeout, restart) leaves no
            // restored video visible without a search document or a queue entry; the run-end search step stays
            $write = function () use ($ids, $state, $time) {
                return $this->where(['vod_id'=>['in',$ids], 'vod_recycle_time'=>$state])->update(['vod_recycle_time'=>$time]);
            };
            $changed = method_exists($this, 'queuedWrite') ? self::queuedWrite($write, $ids) : $write();
            if ($changed === false) {
                return ['code'=>1002,'msg'=>lang('del_err')];
            }
            if ((int)$changed < 1) { break; }
            if (class_exists('\app\common\util\FilterVocab')) {
                // the filter vocabulary re-reads them (a recycled row's item-path hash goes; a restored one is checked again)
                \app\common\util\FilterVocab::touched(array_map('intval', $ids));
            }
            $total += (int)$changed;
            foreach ($rows as $row) { $this->invalidateVodDetail($row); }
            $this->touchVodCatalogRevision();
        }
        if ($total < 1) {
            return ['code'=>1002,'msg'=>lang('del_err')];
        }
        return ['code'=>1,'msg'=>lang($restore ? 'vod/recycle_restore_ok' : 'vod/recycle_ok')];
    }
}
