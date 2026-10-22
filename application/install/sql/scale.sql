-- JavCMS 1.2.0 scale tables (XVID-SCALE-BRIEF D-L3, D-L9, D-L11; XVID-NOLIMIT-BRIEF B1 page maps). Created by app\common\util\ScaleSchema::ensureTables()
-- (installer, first admin visit, tools/upgrade.php). Additive and idempotent; javcms_ is replaced by the site prefix.
-- Precomputed counts: every key Extend::dataCount() returns, per-year counts, tier pin. Written by the counts job only.
CREATE TABLE IF NOT EXISTS `javcms_scale_count` (
 `k` varchar(64) NOT NULL,
 `v` int unsigned NOT NULL DEFAULT 0,
 `at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Sitemap buckets of 5,000 video ids: newest vod_time and published count per bucket (housekeeping, per dirty bucket).
CREATE TABLE IF NOT EXISTS `javcms_sitemap_bucket` (
 `bucket` int unsigned NOT NULL,
 `max_time` int unsigned NOT NULL DEFAULT 0,
 `cnt` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Visitor counters (views, likes, dislikes, ratings) buffered between two folds (housekeeping, every 5 minutes).
CREATE TABLE IF NOT EXISTS `javcms_hit_buffer` (
 `vod_id` int unsigned NOT NULL,
 `n_hits` int unsigned NOT NULL DEFAULT 0,
 `n_up` int unsigned NOT NULL DEFAULT 0,
 `n_down` int unsigned NOT NULL DEFAULT 0,
 `n_score` int unsigned NOT NULL DEFAULT 0,
 `n_score_num` int unsigned NOT NULL DEFAULT 0,
 `at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`vod_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Page-boundary map of the unfiltered newest-first lists (XVID-NOLIMIT-BRIEF B1, written by ListPageMap::refresh of lane S
-- from housekeeping and tools/upgrade.php). One row every 1,000 list rows, counted from the OLDEST row (seq), holding the
-- (vod_time, vod_id) at that rank. Keys lk: 'a' (all), 't<id>' (category), 'p<id>' (parent), 'adm' (admin list), plus
-- ':h<crc32>' for a visitor group with hidden categories. About 21,500 rows (1 MB) at 7.1 M videos.
CREATE TABLE IF NOT EXISTS `javcms_list_pagemap` (
 `lk` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `seq` int unsigned NOT NULL,
 `sort_v` int unsigned NOT NULL,
 `vod_id` int unsigned NOT NULL,
 PRIMARY KEY (`lk`, `seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- One row per map key: rows mapped (total), the newest mapped row (top_v, top_id: new rows are appended above it), the
-- lowest vod_time changed below the top (dirty_from, 0 = clean), last full build, deletes / hides counted since then
-- (stale_ops), and the resumable rewalk cursor (walk_seq, walk_v, walk_id, all 0 = no rewalk running).
CREATE TABLE IF NOT EXISTS `javcms_list_pagemap_state` (
 `lk` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `total` int unsigned NOT NULL DEFAULT 0,
 `top_v` int unsigned NOT NULL DEFAULT 0,
 `top_id` int unsigned NOT NULL DEFAULT 0,
 `dirty_from` int unsigned NOT NULL DEFAULT 0,
 `built_at` int unsigned NOT NULL DEFAULT 0,
 `stale_ops` int unsigned NOT NULL DEFAULT 0,
 `walk_seq` int unsigned NOT NULL DEFAULT 0,
 `walk_v` int unsigned NOT NULL DEFAULT 0,
 `walk_id` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`lk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
