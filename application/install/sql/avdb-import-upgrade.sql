ALTER TABLE `javcms_avdb_import_job` ADD COLUMN `provider` varchar(20) NOT NULL DEFAULT 'avdb';
ALTER TABLE `javcms_avdb_import_job` ADD KEY `provider_state` (`provider`,`state`);
ALTER TABLE `javcms_avdb_import_map` ADD COLUMN `state` tinyint unsigned NOT NULL DEFAULT 0;
ALTER TABLE `javcms_avdb_import_map` ADD COLUMN `row_hash` char(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '';
ALTER TABLE `javcms_avdb_import_map` ADD COLUMN `seen_at` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `javcms_avdb_import_map` ADD COLUMN `missed_at` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `javcms_avdb_import_map` ADD COLUMN `row_time` int unsigned NOT NULL DEFAULT 0;
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
 PRIMARY KEY (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
ALTER TABLE `javcms_avdb_provider_state` ADD COLUMN `export_manifest` mediumtext NULL;
ALTER TABLE `javcms_avdb_provider_state` ADD COLUMN `export_checked_at` int unsigned NOT NULL DEFAULT 0;
ALTER TABLE `javcms_avdb_provider_state` ADD COLUMN `feed_cursor` varchar(40) NOT NULL DEFAULT '';
ALTER TABLE `javcms_avdb_provider_state` ADD COLUMN `removed_since` int unsigned NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS `javcms_avdb_import_ftkey` (
 `key_name` varchar(64) NOT NULL, `col` varchar(64) NOT NULL DEFAULT '',
 `job_id` varchar(32) NOT NULL DEFAULT '', `state` varchar(16) NOT NULL DEFAULT '',
 `tries` int unsigned NOT NULL DEFAULT 0, `message` varchar(255) NOT NULL DEFAULT '',
 `created_at` int unsigned NOT NULL DEFAULT 0, `updated_at` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
