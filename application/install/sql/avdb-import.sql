CREATE TABLE IF NOT EXISTS `javcms_avdb_import_job` (
 `job_id` char(32) NOT NULL, `admin_id` int unsigned NOT NULL,
 `options` longtext NOT NULL, `state` varchar(20) NOT NULL, `phase` varchar(20) NOT NULL,
 `page` int unsigned NOT NULL DEFAULT 1, `cursor` int unsigned NOT NULL DEFAULT 0,
 `discovery_done` tinyint unsigned NOT NULL DEFAULT 0, `lock_token` char(32) NOT NULL DEFAULT '',
 `lock_until` int unsigned NOT NULL DEFAULT 0, `error` text NOT NULL,
 `retry_at` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL,
 `provider` varchar(20) NOT NULL DEFAULT 'avdb',
 PRIMARY KEY (`job_id`), KEY `owner_jobs` (`admin_id`,`created_at`), KEY `provider_state` (`provider`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `javcms_avdb_import_item` (
 `item_id` bigint unsigned NOT NULL AUTO_INCREMENT, `job_id` char(32) NOT NULL,
 `remote_id` int unsigned NOT NULL, `vod_id` int unsigned NOT NULL DEFAULT 0,
 `state` varchar(20) NOT NULL, `payload` longtext NOT NULL, `translations` longtext NOT NULL,
 `locale_cursor` int unsigned NOT NULL DEFAULT 1, `before_image` longtext NOT NULL,
 `after_image` longtext NOT NULL, `message` text NOT NULL,
 PRIMARY KEY (`item_id`), UNIQUE KEY `job_remote` (`job_id`,`remote_id`), KEY `work` (`job_id`,`state`,`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `javcms_avdb_import_map` (
 `provider` varchar(20) NOT NULL, `remote_id` int unsigned NOT NULL, `vod_id` int unsigned NOT NULL DEFAULT 0,
 `state` tinyint unsigned NOT NULL DEFAULT 0,
 `row_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 `seen_at` int unsigned NOT NULL DEFAULT 0, `missed_at` int unsigned NOT NULL DEFAULT 0, `row_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`provider`,`remote_id`), KEY `local_video` (`vod_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `javcms_avdb_translation` (
 `vod_id` int unsigned NOT NULL, `locale` varchar(10) NOT NULL, `payload` longtext NOT NULL,
 `updated_at` int unsigned NOT NULL, PRIMARY KEY (`vod_id`,`locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `javcms_avdb_provider_type` (
 `provider` varchar(20) NOT NULL, `remote_type_id` int unsigned NOT NULL,
 `local_type_id` int unsigned NOT NULL DEFAULT 0, `remote_name` varchar(64) NOT NULL DEFAULT '',
 `import` tinyint unsigned NOT NULL DEFAULT 1, `remote_total` int unsigned NOT NULL DEFAULT 0,
 `total_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`provider`,`remote_type_id`), KEY `local_type` (`local_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `javcms_avdb_provider_state` (
 `provider` varchar(20) NOT NULL, `next_request_at` double NOT NULL DEFAULT 0,
 `interval_ms` int unsigned NOT NULL DEFAULT 5000, `failures` int unsigned NOT NULL DEFAULT 0,
 `fast_count` smallint unsigned NOT NULL DEFAULT 0, `retry_at` int unsigned NOT NULL DEFAULT 0,
 `last_status` smallint unsigned NOT NULL DEFAULT 0, `last_ms` int unsigned NOT NULL DEFAULT 0,
 `last_error` varchar(255) NOT NULL DEFAULT '', `cli_seen_at` int unsigned NOT NULL DEFAULT 0,
 `newest_remote_time` int unsigned NOT NULL DEFAULT 0, `rate_items_s` decimal(10,1) NOT NULL DEFAULT 0.0,
 `updated_at` int unsigned NOT NULL DEFAULT 0,
 `export_manifest` mediumtext NULL, `export_checked_at` int unsigned NOT NULL DEFAULT 0,
 `feed_cursor` varchar(40) NOT NULL DEFAULT '', `removed_since` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `javcms_avdb_import_ftkey` (
 `key_name` varchar(64) NOT NULL, `col` varchar(64) NOT NULL DEFAULT '',
 `job_id` varchar(32) NOT NULL DEFAULT '', `state` varchar(16) NOT NULL DEFAULT '',
 `tries` int unsigned NOT NULL DEFAULT 0, `message` varchar(255) NOT NULL DEFAULT '',
 `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
