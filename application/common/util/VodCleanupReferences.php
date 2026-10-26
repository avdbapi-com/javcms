<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\util;

use think\Db;

/** Bounded metadata-only cleanup. The caller owns transaction and checkpoint. */
class VodCleanupReferences
{
    private const PHASES = [
        'topic' => ['topic', 'topic_id', 'topic_rel_vod'],
        'art' => ['art', 'art_id', 'art_rel_vod'],
        'vod' => ['vod', 'vod_id', 'vod_rel_vod'],
        'search' => ['vod_search', 'search_key', 'search_result_ids'],
    ];

    /** Preserve malformed/non-target tokens exactly; remove only proven captured IDs. */
    public static function pruneCsv($value, $upper)
    {
        if (!is_string($value)) { throw new \InvalidArgumentException('Invalid video relation value.'); }
        $upper = self::number($upper, false);
        $kept = [];
        foreach (explode(',', $value) as $token) {
            $trimmed = trim($token);
            $digits = ltrim($trimmed, '0');
            $isId = $trimmed !== '' && ctype_digit($trimmed) && $digits !== '' &&
                (strlen($digits) < 10 || (strlen($digits) === 10 && strcmp($digits, '4294967295') <= 0));
            if ($isId && (int)$digits <= $upper) { continue; }
            $kept[] = $token;
        }
        return implode(',', $kept);
    }

    private static function number($value, $allowZero)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', (string)$value) ||
            strlen((string)$value) === 10 && strcmp((string)$value, '4294967295') > 0 || (!$allowZero && (string)$value === '0')) {
            throw new \InvalidArgumentException('Invalid cleanup boundary.');
        }
        return (int)$value;
    }

    /** No network, file deletion, cache wipe, transaction commit or global bootstrap. */
    public static function step($phase, $cursor, $upper, $limit = 100)
    {
        if (!is_string($phase) || !isset(self::PHASES[$phase])) { throw new \InvalidArgumentException('Invalid reference cleanup phase.'); }
        $upper = self::number($upper, false);
        if (!is_int($limit) || $limit < 1 || $limit > 250) { throw new \InvalidArgumentException('Invalid reference cleanup batch size.'); }
        $search = $phase === 'search';
        if ($search) {
            if (!is_string($cursor) || strlen($cursor) > 128 || preg_match('/[\x00-\x1f\x7f]/', $cursor)) {
                throw new \InvalidArgumentException('Invalid search cleanup cursor.');
            }
        } else { $cursor = self::number($cursor, true); }
        list($table, $key, $field) = self::PHASES[$phase];
        $query = Db::name($table)->field($key . ',' . $field)->where($key, '>', $cursor)->order($key)->limit($limit)->lock(true);
        // Captured video rows have already been deleted. Only survivors need link pruning.
        if ($phase === 'vod') { $query->where('vod_id', '>', $upper); }
        $rows = $query->select();
        $changed = 0;
        foreach ($rows as $row) {
            $cursor = $search ? (string)$row[$key] : (int)$row[$key];
            $old = $row[$field];
            $new = self::pruneCsv($old, $upper);
            if ($old === $new) { continue; }
            if ($search) {
                // This table stores only video result caches; invalidate for recomputation.
                $changed += (int)Db::name($table)->where($key, $cursor)->delete();
            } else {
                $changed += (int)Db::name($table)->where($key, $cursor)->update([$field => $new]);
            }
        }
        return ['next_cursor' => $cursor, 'done' => count($rows) < $limit, 'rows_changed' => $changed];
    }
}
