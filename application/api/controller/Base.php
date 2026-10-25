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
namespace app\api\controller;
use think\Controller;
use app\common\controller\All;

class Base extends All
{
    public function __construct()
    {
        parent::__construct();
        $config = $GLOBALS['config']['site'];
        $this->assign($config);

        // Site is shutting down
        if($config['site_status'] == 0){

        }
    }


    /**
     * Syndication rows (ac=detail / videolist use field('*')) never carry access passwords:
     * vod_pwd, vod_pwd_url, vod_pwd_play(_url), vod_pwd_down(_url), art_pwd(_url) and any other *_pwd* column.
     * Same rule as the ajax/data fix (PS-07).
     */
    protected function stripSecretColumns(&$res)
    {
        if (!is_array($res) || empty($res['list']) || !is_array($res['list'])) {
            return;
        }
        foreach ($res['list'] as &$row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $column) {
                if (preg_match('/_pwd(?:_|$)/', (string)$column)) {
                    unset($row[$column]);
                }
            }
        }
        unset($row);
    }

    protected function roleHasTypeId(): bool
    {
        static $hasTypeId = null;
        if ($hasTypeId !== null) {
            return $hasTypeId;
        }

        try {
            $fields = model('Role')->getQuery()->getTableInfo('', 'fields');
            $hasTypeId = is_array($fields) && in_array('type_id', $fields, true);
        } catch (\Throwable $e) {
            $hasTypeId = false;
        }

        return $hasTypeId;
    }

    protected function applyRoleTypeFilter(array &$where, $paramT): void
    {
        if (empty($paramT)) {
            return;
        }

        $typeFilter = $GLOBALS['config']['api']['role']['typefilter'] ?? '';
        if (!empty($typeFilter) && strpos($typeFilter, $paramT) === false) {
            return;
        }

        if ($this->roleHasTypeId()) {
            $where['type_id'] = $paramT;
            return;
        }

        $where['role_rid'] = ['in', function ($query) use ($paramT) {
            $query->name('Vod')->field('vod_id')->where('type_id', $paramT)->where('vod_recycle_time', 0);
        }];
    }
}