-- -----------------------------
-- Think MySQL Data Transfer 
-- 
-- Host     : 127.0.0.1
-- Port     : 3306
-- Database : javcms
-- 
-- Part : #1
-- Date : 2024-08-18 01:10:42
-- -----------------------------

SET FOREIGN_KEY_CHECKS = 0;


-- -----------------------------
-- Table structure for `javcms_actor`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_actor`;
CREATE TABLE `javcms_actor` (
  `actor_id` int unsigned NOT NULL AUTO_INCREMENT,
  `type_id` int unsigned NOT NULL DEFAULT '0',
  `type_id_1` int unsigned NOT NULL DEFAULT '0',
  `actor_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_alias` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_status` tinyint unsigned NOT NULL DEFAULT '0',
  `actor_lock` tinyint unsigned NOT NULL DEFAULT '0',
  `actor_letter` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_sex` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_color` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_pic` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_blurb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_remarks` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_area` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_height` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_weight` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_birthday` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_birtharea` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_blood` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_starsign` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_school` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_works` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_tag` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_level` tinyint unsigned NOT NULL DEFAULT '0',
  `actor_time` int unsigned NOT NULL DEFAULT '0',
  `actor_time_add` int unsigned NOT NULL DEFAULT '0',
  `actor_time_hits` int unsigned NOT NULL DEFAULT '0',
  `actor_time_make` int unsigned NOT NULL DEFAULT '0',
  `actor_hits` mediumint unsigned NOT NULL DEFAULT '0',
  `actor_hits_day` mediumint unsigned NOT NULL DEFAULT '0',
  `actor_hits_week` mediumint unsigned NOT NULL DEFAULT '0',
  `actor_hits_month` mediumint unsigned NOT NULL DEFAULT '0',
  `actor_score` decimal(3,1) unsigned NOT NULL DEFAULT '0.0',
  `actor_score_all` mediumint unsigned NOT NULL DEFAULT '0',
  `actor_score_num` mediumint unsigned NOT NULL DEFAULT '0',
  `actor_up` mediumint unsigned NOT NULL DEFAULT '0',
  `actor_down` mediumint unsigned NOT NULL DEFAULT '0',
  `actor_tpl` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_jumpurl` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `actor_content` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`actor_id`),
  KEY `type_id` (`type_id`) USING BTREE,
  KEY `type_id_1` (`type_id_1`) USING BTREE,
  KEY `actor_name` (`actor_name`(191)) USING BTREE,
  KEY `actor_en` (`actor_en`(191)) USING BTREE,
  KEY `actor_letter` (`actor_letter`) USING BTREE,
  KEY `actor_level` (`actor_level`) USING BTREE,
  KEY `actor_time` (`actor_time`) USING BTREE,
  KEY `actor_time_add` (`actor_time_add`) USING BTREE,
  KEY `actor_sex` (`actor_sex`),
  KEY `actor_area` (`actor_area`),
  KEY `actor_up` (`actor_up`),
  KEY `actor_down` (`actor_down`),
  KEY `actor_tag` (`actor_tag`(191)),
  KEY `actor_class` (`actor_class`(191)),
  KEY `actor_score` (`actor_score`),
  KEY `actor_score_all` (`actor_score_all`),
  KEY `actor_score_num` (`actor_score_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_annex`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_annex`;
CREATE TABLE `javcms_annex` (
  `annex_id` int unsigned NOT NULL AUTO_INCREMENT,
  `annex_time` int unsigned NOT NULL DEFAULT '0',
  `annex_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `annex_size` int unsigned NOT NULL DEFAULT '0',
  `annex_type` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`annex_id`),
  KEY `annex_time` (`annex_time`),
  KEY `annex_file` (`annex_file`(191)),
  KEY `annex_type` (`annex_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------
-- Records of `javcms_annex`
-- -----------------------------

-- -----------------------------
-- Table structure for `javcms_art`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_art`;
CREATE TABLE `javcms_art` (
  `art_id` int unsigned NOT NULL AUTO_INCREMENT,
  `type_id` smallint unsigned NOT NULL DEFAULT '0',
  `type_id_1` smallint unsigned NOT NULL DEFAULT '0',
  `group_id` smallint unsigned NOT NULL DEFAULT '0',
  `art_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_sub` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_status` tinyint unsigned NOT NULL DEFAULT '0',
  `art_letter` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_color` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_from` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_author` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_tag` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_pic` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_pic_thumb` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_pic_slide` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_pic_screenshot` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `art_blurb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_remarks` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_jumpurl` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_tpl` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_level` tinyint unsigned NOT NULL DEFAULT '0',
  `art_lock` tinyint unsigned NOT NULL DEFAULT '0',
  `art_points` smallint unsigned NOT NULL DEFAULT '0',
  `art_points_detail` smallint unsigned NOT NULL DEFAULT '0',
  `art_up` mediumint unsigned NOT NULL DEFAULT '0',
  `art_down` mediumint unsigned NOT NULL DEFAULT '0',
  `art_hits` mediumint unsigned NOT NULL DEFAULT '0',
  `art_hits_day` mediumint unsigned NOT NULL DEFAULT '0',
  `art_hits_week` mediumint unsigned NOT NULL DEFAULT '0',
  `art_hits_month` mediumint unsigned NOT NULL DEFAULT '0',
  `art_time` int unsigned NOT NULL DEFAULT '0',
  `art_time_add` int unsigned NOT NULL DEFAULT '0',
  `art_time_hits` int unsigned NOT NULL DEFAULT '0',
  `art_time_make` int unsigned NOT NULL DEFAULT '0',
  `art_score` decimal(3,1) unsigned NOT NULL DEFAULT '0.0',
  `art_score_all` mediumint unsigned NOT NULL DEFAULT '0',
  `art_score_num` mediumint unsigned NOT NULL DEFAULT '0',
  `art_rel_art` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_rel_vod` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_pwd` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_pwd_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `art_title` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `art_note` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `art_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`art_id`),
  KEY `type_id` (`type_id`) USING BTREE,
  KEY `type_id_1` (`type_id_1`) USING BTREE,
  KEY `art_level` (`art_level`) USING BTREE,
  KEY `art_hits` (`art_hits`) USING BTREE,
  KEY `art_time` (`art_time`) USING BTREE,
  KEY `art_letter` (`art_letter`) USING BTREE,
  KEY `art_down` (`art_down`) USING BTREE,
  KEY `art_up` (`art_up`) USING BTREE,
  KEY `art_tag` (`art_tag`) USING BTREE,
  KEY `art_name` (`art_name`(191)) USING BTREE,
  KEY `art_enn` (`art_en`(191)) USING BTREE,
  KEY `art_hits_day` (`art_hits_day`) USING BTREE,
  KEY `art_hits_week` (`art_hits_week`) USING BTREE,
  KEY `art_hits_month` (`art_hits_month`) USING BTREE,
  KEY `art_time_add` (`art_time_add`) USING BTREE,
  KEY `art_time_make` (`art_time_make`) USING BTREE,
  KEY `art_lock` (`art_lock`),
  KEY `art_score` (`art_score`),
  KEY `art_score_all` (`art_score_all`),
  KEY `art_score_num` (`art_score_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_card`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_card`;
CREATE TABLE `javcms_card` (
  `card_id` int unsigned NOT NULL AUTO_INCREMENT,
  `card_no` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `card_pwd` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `card_money` int unsigned NOT NULL DEFAULT '0',
  `card_points` int unsigned NOT NULL DEFAULT '0',
  `card_use_status` tinyint unsigned NOT NULL DEFAULT '0',
  `card_sale_status` tinyint unsigned NOT NULL DEFAULT '0',
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `card_add_time` int unsigned NOT NULL DEFAULT '0',
  `card_use_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`card_id`),
  KEY `user_id` (`user_id`) USING BTREE,
  KEY `card_add_time` (`card_add_time`) USING BTREE,
  KEY `card_use_time` (`card_use_time`) USING BTREE,
  KEY `card_no` (`card_no`),
  KEY `card_pwd` (`card_pwd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_cash`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_cash`;
CREATE TABLE `javcms_cash` (
  `cash_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `cash_status` tinyint unsigned NOT NULL DEFAULT '0',
  `cash_points` smallint unsigned NOT NULL DEFAULT '0',
  `cash_money` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `cash_bank_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `cash_bank_no` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `cash_payee_name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `cash_time` int unsigned NOT NULL DEFAULT '0',
  `cash_time_audit` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`cash_id`),
  KEY `user_id` (`user_id`),
  KEY `cash_status` (`cash_status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_cj_content`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_cj_content`;
CREATE TABLE `javcms_cj_content` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nodeid` int unsigned NOT NULL DEFAULT '0',
  `status` tinyint unsigned NOT NULL DEFAULT '1',
  `url` char(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `title` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `nodeid` (`nodeid`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_cj_history`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_cj_history`;
CREATE TABLE `javcms_cj_history` (
  `md5` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`md5`),
  KEY `md5` (`md5`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_cj_node`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_cj_node`;
CREATE TABLE `javcms_cj_node` (
  `nodeid` smallint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `lastdate` int unsigned NOT NULL DEFAULT '0',
  `sourcecharset` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `sourcetype` tinyint unsigned NOT NULL DEFAULT '0',
  `urlpage` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `pagesize_start` tinyint unsigned NOT NULL DEFAULT '0',
  `pagesize_end` mediumint unsigned NOT NULL DEFAULT '0',
  `page_base` char(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `par_num` tinyint unsigned NOT NULL DEFAULT '1',
  `url_contain` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `url_except` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `url_start` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `url_end` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `title_rule` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `title_html_rule` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_rule` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_html_rule` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content_rule` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content_html_rule` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content_page_start` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content_page_end` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content_page_rule` tinyint unsigned NOT NULL DEFAULT '0',
  `content_page` tinyint unsigned NOT NULL DEFAULT '0',
  `content_nextpage` char(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `down_attachment` tinyint unsigned NOT NULL DEFAULT '0',
  `watermark` tinyint unsigned NOT NULL DEFAULT '0',
  `coll_order` tinyint unsigned NOT NULL DEFAULT '0',
  `customize_config` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `program_config` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `mid` tinyint unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`nodeid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_collect`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_collect`;
CREATE TABLE `javcms_collect` (
  `collect_id` int unsigned NOT NULL AUTO_INCREMENT,
  `collect_name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `collect_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `collect_type` tinyint unsigned NOT NULL DEFAULT '1',
  `collect_mid` tinyint unsigned NOT NULL DEFAULT '1',
  `collect_appid` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `collect_appkey` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `collect_param` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `collect_filter` tinyint unsigned NOT NULL DEFAULT '0',
  `collect_filter_from` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `collect_opt` tinyint unsigned NOT NULL DEFAULT '0',
  `collect_sync_pic_opt` tinyint unsigned NOT NULL DEFAULT '0' COMMENT 'Synchronize picture options, 0-follow global, 1-on, 2-off',
  PRIMARY KEY (`collect_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------
-- Records of `javcms_collect`
-- -----------------------------

-- -----------------------------
-- Table structure for `javcms_comment`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_comment`;
CREATE TABLE `javcms_comment` (
  `comment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `comment_mid` tinyint unsigned NOT NULL DEFAULT '1',
  `comment_rid` int unsigned NOT NULL DEFAULT '0',
  `comment_pid` int unsigned NOT NULL DEFAULT '0',
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `comment_status` tinyint unsigned NOT NULL DEFAULT '1',
  `comment_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `comment_ip` int unsigned NOT NULL DEFAULT '0',
  `comment_time` int unsigned NOT NULL DEFAULT '0',
  `comment_content` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `comment_up` mediumint unsigned NOT NULL DEFAULT '0',
  `comment_down` mediumint unsigned NOT NULL DEFAULT '0',
  `comment_reply` mediumint unsigned NOT NULL DEFAULT '0',
  `comment_report` mediumint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`comment_id`),
  KEY `comment_mid` (`comment_mid`) USING BTREE,
  KEY `comment_rid` (`comment_rid`) USING BTREE,
  KEY `comment_time` (`comment_time`) USING BTREE,
  KEY `comment_pid` (`comment_pid`),
  KEY `user_id` (`user_id`),
  KEY `comment_reply` (`comment_reply`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_gbook`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_gbook`;
CREATE TABLE `javcms_gbook` (
  `gbook_id` int unsigned NOT NULL AUTO_INCREMENT,
  `gbook_rid` int unsigned NOT NULL DEFAULT '0',
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `gbook_status` tinyint unsigned NOT NULL DEFAULT '1',
  `gbook_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `gbook_ip` int unsigned NOT NULL DEFAULT '0',
  `gbook_time` int unsigned NOT NULL DEFAULT '0',
  `gbook_reply_time` int unsigned NOT NULL DEFAULT '0',
  `gbook_content` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `gbook_reply` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`gbook_id`),
  KEY `gbook_rid` (`gbook_rid`) USING BTREE,
  KEY `gbook_time` (`gbook_time`) USING BTREE,
  KEY `gbook_reply_time` (`gbook_reply_time`) USING BTREE,
  KEY `user_id` (`user_id`),
  KEY `gbook_reply` (`gbook_reply`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_group`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_group`;
CREATE TABLE `javcms_group` (
  `group_id` smallint NOT NULL AUTO_INCREMENT,
  `group_name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `group_status` tinyint unsigned NOT NULL DEFAULT '1',
  `group_type` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `group_popedom` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `group_points_day` smallint unsigned NOT NULL DEFAULT '0',
  `group_points_week` smallint NOT NULL DEFAULT '0',
  `group_points_month` smallint unsigned NOT NULL DEFAULT '0',
  `group_points_year` smallint unsigned NOT NULL DEFAULT '0',
  `group_points_free` tinyint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`group_id`),
  KEY `group_status` (`group_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------
-- Records of `javcms_group`
-- -----------------------------
INSERT INTO `javcms_group` VALUES ('1', 'Guest', '1', ',1,2,3,4,5,6,7,8,9,', '{\"1\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"2\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"3\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"4\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"5\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"6\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"7\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"8\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"9\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"}}', '0', '0', '0', '0', '0');
INSERT INTO `javcms_group` VALUES ('2', 'Default', '1', ',1,2,3,4,5,6,7,8,9,', '{\"1\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"2\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"3\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"4\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"5\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"6\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"7\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"8\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"9\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"}}', '0', '0', '0', '0', '0');
INSERT INTO `javcms_group` VALUES ('3', 'VIP', '1', ',1,2,3,4,5,6,7,8,9,', '{\"1\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"2\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"3\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"4\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"5\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"6\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"7\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"8\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"},\"9\":{\"1\":\"1\",\"2\":\"2\",\"3\":\"3\",\"4\":\"4\",\"5\":\"5\"}}', '10', '70', '300', '3600', '0');

-- -----------------------------
-- Table structure for `javcms_link`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_link`;
CREATE TABLE `javcms_link` (
  `link_id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `link_type` tinyint unsigned NOT NULL DEFAULT '0',
  `link_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `link_sort` smallint NOT NULL DEFAULT '0',
  `link_add_time` int unsigned NOT NULL DEFAULT '0',
  `link_time` int unsigned NOT NULL DEFAULT '0',
  `link_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `link_logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`link_id`),
  KEY `link_sort` (`link_sort`) USING BTREE,
  KEY `link_type` (`link_type`) USING BTREE,
  KEY `link_add_time` (`link_add_time`),
  KEY `link_time` (`link_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_msg`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_msg`;
CREATE TABLE `javcms_msg` (
  `msg_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `msg_type` tinyint unsigned NOT NULL DEFAULT '0',
  `msg_status` tinyint unsigned NOT NULL DEFAULT '0',
  `msg_to` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `msg_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `msg_content` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `msg_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`msg_id`),
  KEY `msg_code` (`msg_code`),
  KEY `msg_time` (`msg_time`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_order`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_order`;
CREATE TABLE `javcms_order` (
  `order_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `order_status` tinyint unsigned NOT NULL DEFAULT '0',
  `order_code` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `order_price` decimal(12,2) unsigned NOT NULL DEFAULT '0.00',
  `order_time` int unsigned NOT NULL DEFAULT '0',
  `order_points` mediumint unsigned NOT NULL DEFAULT '0',
  `order_pay_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `order_pay_time` int unsigned NOT NULL DEFAULT '0',
  `order_remarks` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`order_id`),
  KEY `order_code` (`order_code`) USING BTREE,
  KEY `user_id` (`user_id`) USING BTREE,
  KEY `order_time` (`order_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_plog`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_plog`;
CREATE TABLE `javcms_plog` (
  `plog_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `user_id_1` int NOT NULL DEFAULT '0',
  `plog_type` tinyint unsigned NOT NULL DEFAULT '1',
  `plog_points` smallint unsigned NOT NULL DEFAULT '0',
  `plog_time` int unsigned NOT NULL DEFAULT '0',
  `plog_remarks` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`plog_id`),
  KEY `user_id` (`user_id`),
  KEY `plog_type` (`plog_type`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_role`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_role`;
CREATE TABLE `javcms_role` (
  `role_id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_rid` int unsigned NOT NULL DEFAULT '0',
  `role_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `role_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `role_status` tinyint unsigned NOT NULL DEFAULT '0',
  `role_lock` tinyint unsigned NOT NULL DEFAULT '0',
  `role_letter` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `role_color` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `role_actor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `role_remarks` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `role_pic` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `role_sort` smallint unsigned NOT NULL DEFAULT '0',
  `role_level` tinyint unsigned NOT NULL DEFAULT '0',
  `role_time` int unsigned NOT NULL DEFAULT '0',
  `role_time_add` int unsigned NOT NULL DEFAULT '0',
  `role_time_hits` int unsigned NOT NULL DEFAULT '0',
  `role_time_make` int unsigned NOT NULL DEFAULT '0',
  `role_hits` mediumint unsigned NOT NULL DEFAULT '0',
  `role_hits_day` mediumint unsigned NOT NULL DEFAULT '0',
  `role_hits_week` mediumint unsigned NOT NULL DEFAULT '0',
  `role_hits_month` mediumint unsigned NOT NULL DEFAULT '0',
  `role_score` decimal(3,1) unsigned NOT NULL DEFAULT '0.0',
  `role_score_all` mediumint unsigned NOT NULL DEFAULT '0',
  `role_score_num` mediumint unsigned NOT NULL DEFAULT '0',
  `role_up` mediumint unsigned NOT NULL DEFAULT '0',
  `role_down` mediumint unsigned NOT NULL DEFAULT '0',
  `role_tpl` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `role_jumpurl` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `role_content` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`role_id`),
  KEY `role_rid` (`role_rid`),
  KEY `role_name` (`role_name`(191)),
  KEY `role_en` (`role_en`(191)),
  KEY `role_letter` (`role_letter`),
  KEY `role_actor` (`role_actor`(191)),
  KEY `role_level` (`role_level`),
  KEY `role_time` (`role_time`),
  KEY `role_time_add` (`role_time_add`),
  KEY `role_score` (`role_score`),
  KEY `role_score_all` (`role_score_all`),
  KEY `role_score_num` (`role_score_num`),
  KEY `role_up` (`role_up`),
  KEY `role_down` (`role_down`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_tmpart`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_tmpart`;
CREATE TABLE `javcms_tmpart` (
  `id1` int unsigned DEFAULT NULL,
  `name1` varchar(1024) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


-- -----------------------------
-- Table structure for `javcms_tmpvod`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_tmpvod`;
CREATE TABLE `javcms_tmpvod` (
  `id1` int unsigned DEFAULT NULL,
  `name1` varchar(1024) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


-- -----------------------------
-- Table structure for `javcms_tmpwebsite`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_tmpwebsite`;
CREATE TABLE `javcms_tmpwebsite` (
  `id1` int unsigned DEFAULT NULL,
  `name1` varchar(1024) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=utf8;


-- -----------------------------
-- Table structure for `javcms_topic`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_topic`;
CREATE TABLE `javcms_topic` (
  `topic_id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `topic_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_sub` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_status` tinyint unsigned NOT NULL DEFAULT '1',
  `topic_sort` smallint unsigned NOT NULL DEFAULT '0',
  `topic_letter` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_color` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_tpl` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_pic` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_pic_thumb` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_pic_slide` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_des` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_blurb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_remarks` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_level` tinyint unsigned NOT NULL DEFAULT '0',
  `topic_up` mediumint unsigned NOT NULL DEFAULT '0',
  `topic_down` mediumint unsigned NOT NULL DEFAULT '0',
  `topic_score` decimal(3,1) unsigned NOT NULL DEFAULT '0.0',
  `topic_score_all` mediumint unsigned NOT NULL DEFAULT '0',
  `topic_score_num` mediumint unsigned NOT NULL DEFAULT '0',
  `topic_hits` mediumint unsigned NOT NULL DEFAULT '0',
  `topic_hits_day` mediumint unsigned NOT NULL DEFAULT '0',
  `topic_hits_week` mediumint unsigned NOT NULL DEFAULT '0',
  `topic_hits_month` mediumint unsigned NOT NULL DEFAULT '0',
  `topic_time` int unsigned NOT NULL DEFAULT '0',
  `topic_time_add` int unsigned NOT NULL DEFAULT '0',
  `topic_time_hits` int unsigned NOT NULL DEFAULT '0',
  `topic_time_make` int unsigned NOT NULL DEFAULT '0',
  `topic_tag` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `topic_rel_vod` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `topic_rel_art` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `topic_content` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `topic_extend` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`topic_id`),
  KEY `topic_sort` (`topic_sort`) USING BTREE,
  KEY `topic_level` (`topic_level`) USING BTREE,
  KEY `topic_score` (`topic_score`) USING BTREE,
  KEY `topic_score_all` (`topic_score_all`) USING BTREE,
  KEY `topic_score_num` (`topic_score_num`) USING BTREE,
  KEY `topic_hits` (`topic_hits`) USING BTREE,
  KEY `topic_hits_day` (`topic_hits_day`) USING BTREE,
  KEY `topic_hits_week` (`topic_hits_week`) USING BTREE,
  KEY `topic_hits_month` (`topic_hits_month`) USING BTREE,
  KEY `topic_time_add` (`topic_time_add`) USING BTREE,
  KEY `topic_time` (`topic_time`) USING BTREE,
  KEY `topic_time_hits` (`topic_time_hits`) USING BTREE,
  KEY `topic_name` (`topic_name`(191)),
  KEY `topic_en` (`topic_en`(191)),
  KEY `topic_up` (`topic_up`),
  KEY `topic_down` (`topic_down`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_type`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_type`;
CREATE TABLE `javcms_type` (
  `type_id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `type_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_en` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_sort` smallint unsigned NOT NULL DEFAULT '0',
  `type_mid` smallint unsigned NOT NULL DEFAULT '1',
  `type_pid` smallint unsigned NOT NULL DEFAULT '0',
  `type_status` tinyint unsigned NOT NULL DEFAULT '1',
  `type_tpl` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_tpl_list` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_tpl_detail` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_tpl_play` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_tpl_down` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_des` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_union` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_extend` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type_logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_pic` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `type_jumpurl` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`type_id`),
  KEY `type_sort` (`type_sort`) USING BTREE,
  KEY `type_pid` (`type_pid`) USING BTREE,
  KEY `type_name` (`type_name`),
  KEY `type_en` (`type_en`),
  KEY `type_mid` (`type_mid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------
-- Records of `javcms_type`
-- -----------------------------
INSERT INTO `javcms_type` VALUES ('1', 'Censored', 'censored', '1', '1', '0', '1', 'type.html', 'show.html', 'detail.html', 'play.html', 'down.html', '', '', '', '', '{\"class\":\"3D, 3P, Bl, SM, OL, 4K, Hd, 69, Sf, Vr, Ol, Sm, POV, Toy, Gay, VIP, Gal, Ntr, BBW, Pee, Ass, Tan, NTR, 4HR, Drag, Teen, Back, Tall, Gulp, Post, Bath, SVIP, Kiss, 8KVR, 8Kvr, Plot, Tied, Love, Play, Geek, Plan, Coma, Fist, Slut, Slim, De M, Best, Idol, Peep, Ingo, Food, M jo, Lady, Long, Scat, Hame, Anal, Neat, Half, Mask, Butt, Anus, MILF, Maid, Orgy, Fear, Milk, Aunt, Mini, Do M, Ride, Yoga, Wife, Drug, Sexy, Pure, Puke, Eros, Vibe, Trip, Doll, Tool, Tits, Rape, Nampa, H Cup, 3P 4P, Gonzo, D Cup, Naive, Abuse, Boots, Semen, Missy, Bride, Myodo, Scold, Debut, G Cup, M Leg, M Man, Dance, I Cup, E Cup, Fifty, Other, Enema, Widow, Drama, Small, Chest, WLB B, Tutor, Model, Dildo, Vomit, Chaos, F Cup, Slave, Skirt, Sixty, Witch, Shota, Indie, Queen, Shave, Shame, Nurse, Devil, Blond, Cogal, Hotel, Apron, Kusco, Cruel, Honyo, J Cup, Yokui, Aokan, Bitch, Otaku, Harem, Junko, Rotor, Drunk, Stool, Hairy, Curls, Cusco, Beast, Fight, Nasty, visit, Kyonyu, Carsex, Series, Whites, Acting, Action, Selfie, Chijyo, People, Chikan, Sci Fi, Chubby, Juudai, Clinic, Office, Kimono, School, Peeing, Affair, Incest, Candle, Bikini, Dating, Muscle, Annual, Binyuu, Artist, Saddle, Digimo, Mother, Yukata, Indies, Epuron, Erotic, Blazer, Riding, Expose, Blonde, Facial, Insult, Masumi, Masked, Fetish, Record, Rankou, No Bra, Paipan, M Male, Couple, Friend, G Spot, Gansha, Lotion, Bronze, Lolita, Horror, How To, Kyoshi, Pranks, Shower, Sister, Sports, Suntan, Others, Sweaty, Portio, Thirty, Tickle, Travel, Uterus, Boyish, Virgin, clinic, Voyeur, Petite, Beauty, Frenzy, Clothes, Teacher, Project, Urinate, Nursing, Pissing, Decatin, Tattoos, Amateur, Student, Uniform, Bishiri, Handjob, Sunburn, Classic, Forties, Athlete, Torture, AV Idol, Neglect, Ryojoku, Bikyaku, Retreat, Pervert, Bathing, Freeter, Reprint, Bathtub, Tied Up, Blowjob, Bodycon, Bondage, Bontage, Braless, Sokkuri, Breasts, Outdoor, Tit Job, In Love, Slender, Bukkake, BUKKAKE, Miscaba, Kosupre, Car Sex, Similar, Gagging, Faction, Cumshot, Shemale, Shaving, Fantasy, Cosplay, Cowgirl, Meeting, Hotehel, Massage, Glasses, Hostess, Manguri, Customs, Fighter, Cuckold, Lesbian, Footjob, M Woman, Grandma, VR Only, Onasapo, Paizuri, Full Hd, Peeping, Quickie, Stepmom, leakage, Big Ass, Furnace, Variety, Actress, Av Open, Omnibus, Addition, V Cinema, Outdoors, Adultery, Advanced, M Female, Our Door, Anal Sex, Kunoichi, Cyoushin, Training, Six Nine, Ignoring, Hand Man, Original, Rice Man, Car Love, Classics, Eat Shit, Esthetic, Shotacon, Together, Daydream, Shokushu, Shipping, Ejection, Close Up, Tickling, Shiofuki, Tentacle, Lingerie, Princess, Pregnant, Cow Calf, Iramatio, Fat Girl, POV Shot, Deriheru, Ball Gag, Hypnosis, Creampie, Bareback, Hot Girl, Spanking, Pornstar, Hatsuura, Planning, Leotards, Delusion, Swimwear, Hospital, Feminine, Mischief, Swimsuit, Sweating, Fighting, Maltreat, Eatpussy, Daughter, Swapping, Muscular, Emmaniel, Homemade, Big Butt, Nice Ass, Big Cock, Gym Suit, Big Tits, Speculum, Exposure, Futanari, Waitress, Speaking, Oral Sex, Catwoman, Youthful, Soapland, Lecturer, Sorority, Hardcore, Nekomimi, Solowork, Solitary, Off Sale, Bloomers, TSUNDERE, Sex Toys, Big Dick, Foursome, Vomiting, Molester, Rigidity, Vibrator, Fainting, Emmanuel, Landlady, Gang Rape, Interview, Hot Water, Gyno Exam, Heaven Tv, Hi vision, Baby Face, Loli Type, Hostesses, Foreigner, Huge Tits, Sexy Legs, Nice Butt, Shout Out, Sixtynine, Nice Tits, Conceived, Companion, Soft Body, Solo Work, Squirting, Stockings, Exclusive, Nose Hook, Miniskirt, Sweet Ass, Tall Girl, Tall Lady, Tentacles, Time Stop, Tokusatsu, Expensive, Underwear, Urination, Childhood, Cheongsam, Secretary, Adventure, Scatology, Whitening, Animation, Ass Lover, Celebrity, Restraint, Reproduce, Proboscis, Porn Star, Big Asses, Pantyhose, Black Man, Boys Love, Blow Mono, Call Girl, Face Ride, Movement , Face Shot, Labyrinth, Fair Skin, Kokyoplay, Threesome, Miss Miss, Mousozoku, Fingering, For Women, Dedo Work, Long Hair, Lucky Bag, Gang bang, Housewife, Small Tits, Instructor, Short Hair, Stepfamily, Girl Guide, Brown Hair, Domination, Mini Skirt, Big Pennis, Stepmother, Debut Work, Lotion Oil, Piledriver, Pink Rotor, Look alike, Nyotaimori, Prostitute, Dark Style, Exhibition, Time Stops, Brush Down, Titty Fuck, Reluctance, High Heels, Restraints, Atmosphere, Ass Fetish, Oil Lotion, Emmanuelle, Schoolgirl, 50 Minutes, Cherry Boy, Virgin Man, Sex Friend, Impregnate, Defecation, Homosexual, White Skin, First Shot, Mini Small, Knee Socks, Leg Fetish, Young Wife, Mini Petit, Nasty Hard, Coprophagy, Masturbate, Hot Spring, God Of Sex, Mom Friend, Nice Booty, Bunny Girl, Panty Shot, Stewardess, Black Hair, Skirt Mono, Cum Eating, Laboratory, Collection, Race Queen, Mother Dog, Slim Waist, Sex Change, Dirty Talk, Sportswear, colleagues, Colleagues, Home Visit, Gag Comedy, Super Milk, No Panties, Staff Push, Young Lady, Shijutakin, Individual, Camouflage, Short Skirt, Documentary, Entertainer, Aphrodisiac, Transgender, Transsexual, Doggy Style, Cat Cosplay, Sailor Suit, Turn Number, China Dress, Unavoidable, Lori System, Anchorwoman, Adult Movie, Single Work, Butt Fetish, Acme Orgasm, Hit On Boys, Confinement, Cunnilingus, Set Product, Love Affair, Foot Fetish, Nipple Slip, Deep Throat, Bubble Bath, Cum on face, Compilation, Pretty Girl, Sneak Shots, Breast Milk, Cheerleader, Finger Fuck, Oral Firing, Big Breasts, Words Blame, Panty Shots, Black Actor, Paradise TV, Intercourse, Naked Apron, Prostitutes, Beauty Shop, Dark System, Female Boss, Love Comedy, Health Soap, Erotic Wear, Humiliation, Promiscuous, Promiscuity, Refreshment, Pretty Tits, Big Chicken, hard System, Grandfather, Loose Socks, Plane bread, Instant Sex, Gym Clothes, Hotel Owner, Health Care, Best Of All, Drink Urine, Tight Dress, Racing Girl, Proud Pussy, Medium Hair, Esthetician, Independent, Minamibuchi, Massage Oil, Office Lady, Dirty Words, Mini Series, Facesitting, Drink Saliva, Egg Vibrator, Elder Sister, Emulsion Oil, Erotic Photo, Face Sitting, Fair skinned, Farting Love, Female Ninja, Femdom Slave, Glabrousness, Glasses Girl, Hit On Girls, Hysteroscope, Imprisonment, Independents, Subjectivity, Kakuiro Role, Kounaihassha, Transsexuals, 40 Years Old, 50 Years Old, Age Old Wife, Best Omnibus, Big Vibrator, Shaved Bread, Shaved Pussy, Bubble Socks, Busty Fetish, Campus Story, Liquor Store, Love And Sex, Subordinates, Rigid System, Magical Girl, Many Stories, Martial Arts, feminization, Masturbation, Mature Woman, Incontinence, incontinence, Lesbian Kiss, Model System, Multi person, Multi Person, Limited Time, No Underwear, Leg Crossing, Daihatsu Hat, Nymphomaniac, Older Sister, Virgin Thing, Other Fetish, Other Finger, Over 4 Hours, Receptionist, VR Exclusive, Risky Mosaic, Ropes & Ties, School Girls, Western Pins, New Daughter, Surface Area, Mature Women, Surplus Work, Feminization, Transvestite, Cheerleaders, College Girl, Proprietress, Common Sense, Contribution, Cum In Mouth, Middleweight, Cum in mouth, Asian Actress, Chest Chiller, Western Goods, Other Student, Swallow Sperm, Fanza Limited, Stewardess Ca, Personal Work, Golden Shower, Over 16 Hours, Sex Education, Photo Gallery, Swimming Wear, Cheating Wife, Beautiful Cat, Baseball Fist, Large Breasts, Vibrating Egg, Female Doctor, Cross Dresser, School mizugi, Woman Warrior, Private Tutor, Human Monster, Naked Clothes, Old Playmates, Delivery only, Masochist Man, Shrine Maiden, Men And Women, Hermaphrodite, Delivery Only, Campaign Girl, Married Woman, Mother Friend, Small Breasts, E Cup Or More, Group Bukkake, Best Complete, Married Women, Swimming Race, Super Breasts, Man Squirting, Female Anchor, Chinese Dress, White Of Eyes, Same Sex Love, Embarrassment, Sm Department, Substance Use, Mother in law, Pregnant Woman, Various Worker, Other Fetishes, Newcomer Debut, Massage Parlor, Male Squirting, Pickup Amateur, Mizute Clothes, Drinking Urine, Administration, PICKUP Amateur, Beautiful Skin, Kireina onesan, Fishnet Tights, Drinking Party, Minimal Mosaic, Bleached Spots, Forced Blowjob, Female Soldier, Female Teacher, Sailor Uniform, Extreme Orgasm, Slim Pixelated, Cum Swallowing, Wife And Child, Neat And Clean, Shortcut Girls, Bus Tour Guide, Lewd Lewd Lady, Transformation, Big Tits Lover, Tall Glamorous, Digital Mosaic, School Teacher, Beautiful Legs, Beautiful Girl, School Uniform, Beautiful Tits, Beautiful Butt, Wearing Erotic, Female Warrior, Reverse Number, Original Works, Sexual Slavery, Beautiful Chest, Special Effects, Private Teacher, Psycho Thriller, Female Molester, Low Cost Movies, Forty Years Old, Reverse Pick Up, Fighting Action, Lesbian Kissing, Romantic Comedy, Anime Character, Foreign Imports, Lewd Nasty Lady, Foreign Actress, Academy Uniform, School Swimwear, FANZA Exclusive, 4 Hours Or More, Super VIP Movie, Thanks Offering, School Swimsuit, Science Fiction, Beautiful Pussy, Masculine Woman, Cute Little Boy, Business Attire, Beautiful Woman, High Definition, Ordinary Person, One Piece Dress, Various Workers, But Amazing Sex, High Quality Vr, Pantyhose Thing, Man is Daughter, Bimbo Gal Bitch, Resort Equipment, Husband And Wife, Flight Attendant, 3P Multiple play, Idol & Celebrity, Picking Up Girls, Childhood Friend, Cleaning Blowjob, Object Insertion, Outdoor Exposure, Exclusive Movies, Black Male Actor, Pantyhose Tights, Partner Exchange, Non nude Erotica, School Swimsuits, Female Detective, Miniskirt Police, College Students, Esthetic Massage, Game Live Action, Immediate Saddle, Electric Machine, Historical Drama, Tea Tea Ceremony, Fan Appreciation, Genital Close Up, The Magic Mirror, 16 Hours Or More, Thirty Years Old, Female Announcer, Cusco Exposition, overseas Imports, Girls Department, Drama Department, Adopted Daughter, High School Girl, Multiple Stories, Advertising Idol, Dmm Distribution, Mania Department, Consecutive Coat, Big Breast Fetish, Beautiful Breasts, Limited Time Sale, Electric Massager, Limitless Reality, Action & Fighting, Fetish Department, Super Heavyweight, Business Clothing, Brutal Expression, Female Main Angle, Yariman Gal Bitch, Intense Back Fuck, adoptive Daughter, Actress Department, Collaboration Work, Other Love Affairs, Soggy Masturbation, Ai Generated Works, Actress Collection, The Delusion Group, Collaborative Work, Works Over 4 Hours, Physical Education, Female Investigator, Various Professions, Medical Examination, Extreme Storm Surge, Planning Department, Ordinary Department, Hardcore Department, Manners And Customs, Various Occupations, Rejuvenation Massage, Transform To Heroine, Masturbation Support, High Definition (Hd), Ultra Slim Pixelated, Internal Ejaculation, Confession Experience, Miniskirt Policewoman, Club Activity Manager, Shame And Humiliation, Hospitals And Clinics, Hypnotic Brainwashing, Kiss Of Same Sex Love, Advertising Male Idol, Premature Ejaculation, Delivery Only Amateur, Female College Student, Subjective Perspective, 4 Hours Past Work Time, Beautiful Mature Woman, Exclusive Distribution, Transformation Heroine, Documentary Department, Original Collaboration, Drugs And Aphrodisiacs, Princess & Mademoiselle, Club Activities Manager, Mature Woman Department, Variety Show Department, Subordinate Or Colleague, Experimental Plain White, Av Open 2014 Heavyweight, Confession Of Experience, Foreign Object Insertion, Mistress Japanese Geisha, Shop Manager Recommended, Hostesses And Prostitutes, Getting Paid For Business, Club Hostess & Sex Worker, Foreign Object Penetration, Limited To Dmm Distribution, With Benefits (AV Baseball), Realistic Version Of The Game, More Than 16 Hours Of Footage, With Bonus Video Only For MGS, With Bonus Video Only For Mgs, Limited To Fanza Distribution, Rookie Debuts One After Another, Toys and foreign matter inserts, Administrator Recommended Works, Smartphone Recommendation Animation, Shortcut Girls Boyish But Amazing Sex, Orgy With Nori Crazy For Multiple Play, Prestige 20Th Anniversary Special Project, Vertical Video Recommended For Smartphones, Orgy With Glue Im Crazy For Multiple Plays, Comes With Bonus Footage Only Available At Mgs\",\"area\":\"Japan, China\",\"lang\":\"\",\"year\":\"2024,2023,2022,2021,2020,2019,2018,2017,2016,2015,2014,2013,2012,2011,2010,2009,2008,2007,2006,2005,2004,2003,2002,2001,2000,1999\",\"star\":\"\",\"director\":\"\",\"state\":\"Release,Trailer,Full\",\"version\":\"HD,Full HD,CAM,TV\"}', '', '', '');
INSERT INTO `javcms_type` VALUES ('2', 'Uncensored', 'uncensored', '2', '1', '0', '1', 'type.html', 'show.html', 'detail.html', 'play.html', 'down.html', '', '', '', '', '{\"class\":\"3D, 3P, Bl, SM, OL, 4K, Hd, 69, Sf, Vr, Ol, Sm, POV, Toy, Gay, VIP, Gal, Ntr, BBW, Pee, Ass, Tan, NTR, 4HR, Drag, Teen, Back, Tall, Gulp, Post, Bath, SVIP, Kiss, 8KVR, 8Kvr, Plot, Tied, Love, Play, Geek, Plan, Coma, Fist, Slut, Slim, De M, Best, Idol, Peep, Ingo, Food, M jo, Lady, Long, Scat, Hame, Anal, Neat, Half, Mask, Butt, Anus, MILF, Maid, Orgy, Fear, Milk, Aunt, Mini, Do M, Ride, Yoga, Wife, Drug, Sexy, Pure, Puke, Eros, Vibe, Trip, Doll, Tool, Tits, Rape, Nampa, H Cup, 3P 4P, Gonzo, D Cup, Naive, Abuse, Boots, Semen, Missy, Bride, Myodo, Scold, Debut, G Cup, M Leg, M Man, Dance, I Cup, E Cup, Fifty, Other, Enema, Widow, Drama, Small, Chest, WLB B, Tutor, Model, Dildo, Vomit, Chaos, F Cup, Slave, Skirt, Sixty, Witch, Shota, Indie, Queen, Shave, Shame, Nurse, Devil, Blond, Cogal, Hotel, Apron, Kusco, Cruel, Honyo, J Cup, Yokui, Aokan, Bitch, Otaku, Harem, Junko, Rotor, Drunk, Stool, Hairy, Curls, Cusco, Beast, Fight, Nasty, visit, Kyonyu, Carsex, Series, Whites, Acting, Action, Selfie, Chijyo, People, Chikan, Sci Fi, Chubby, Juudai, Clinic, Office, Kimono, School, Peeing, Affair, Incest, Candle, Bikini, Dating, Muscle, Annual, Binyuu, Artist, Saddle, Digimo, Mother, Yukata, Indies, Epuron, Erotic, Blazer, Riding, Expose, Blonde, Facial, Insult, Masumi, Masked, Fetish, Record, Rankou, No Bra, Paipan, M Male, Couple, Friend, G Spot, Gansha, Lotion, Bronze, Lolita, Horror, How To, Kyoshi, Pranks, Shower, Sister, Sports, Suntan, Others, Sweaty, Portio, Thirty, Tickle, Travel, Uterus, Boyish, Virgin, clinic, Voyeur, Petite, Beauty, Frenzy, Clothes, Teacher, Project, Urinate, Nursing, Pissing, Decatin, Tattoos, Amateur, Student, Uniform, Bishiri, Handjob, Sunburn, Classic, Forties, Athlete, Torture, AV Idol, Neglect, Ryojoku, Bikyaku, Retreat, Pervert, Bathing, Freeter, Reprint, Bathtub, Tied Up, Blowjob, Bodycon, Bondage, Bontage, Braless, Sokkuri, Breasts, Outdoor, Tit Job, In Love, Slender, Bukkake, BUKKAKE, Miscaba, Kosupre, Car Sex, Similar, Gagging, Faction, Cumshot, Shemale, Shaving, Fantasy, Cosplay, Cowgirl, Meeting, Hotehel, Massage, Glasses, Hostess, Manguri, Customs, Fighter, Cuckold, Lesbian, Footjob, M Woman, Grandma, VR Only, Onasapo, Paizuri, Full Hd, Peeping, Quickie, Stepmom, leakage, Big Ass, Furnace, Variety, Actress, Av Open, Omnibus, Addition, V Cinema, Outdoors, Adultery, Advanced, M Female, Our Door, Anal Sex, Kunoichi, Cyoushin, Training, Six Nine, Ignoring, Hand Man, Original, Rice Man, Car Love, Classics, Eat Shit, Esthetic, Shotacon, Together, Daydream, Shokushu, Shipping, Ejection, Close Up, Tickling, Shiofuki, Tentacle, Lingerie, Princess, Pregnant, Cow Calf, Iramatio, Fat Girl, POV Shot, Deriheru, Ball Gag, Hypnosis, Creampie, Bareback, Hot Girl, Spanking, Pornstar, Hatsuura, Planning, Leotards, Delusion, Swimwear, Hospital, Feminine, Mischief, Swimsuit, Sweating, Fighting, Maltreat, Eatpussy, Daughter, Swapping, Muscular, Emmaniel, Homemade, Big Butt, Nice Ass, Big Cock, Gym Suit, Big Tits, Speculum, Exposure, Futanari, Waitress, Speaking, Oral Sex, Catwoman, Youthful, Soapland, Lecturer, Sorority, Hardcore, Nekomimi, Solowork, Solitary, Off Sale, Bloomers, TSUNDERE, Sex Toys, Big Dick, Foursome, Vomiting, Molester, Rigidity, Vibrator, Fainting, Emmanuel, Landlady, Gang Rape, Interview, Hot Water, Gyno Exam, Heaven Tv, Hi vision, Baby Face, Loli Type, Hostesses, Foreigner, Huge Tits, Sexy Legs, Nice Butt, Shout Out, Sixtynine, Nice Tits, Conceived, Companion, Soft Body, Solo Work, Squirting, Stockings, Exclusive, Nose Hook, Miniskirt, Sweet Ass, Tall Girl, Tall Lady, Tentacles, Time Stop, Tokusatsu, Expensive, Underwear, Urination, Childhood, Cheongsam, Secretary, Adventure, Scatology, Whitening, Animation, Ass Lover, Celebrity, Restraint, Reproduce, Proboscis, Porn Star, Big Asses, Pantyhose, Black Man, Boys Love, Blow Mono, Call Girl, Face Ride, Movement , Face Shot, Labyrinth, Fair Skin, Kokyoplay, Threesome, Miss Miss, Mousozoku, Fingering, For Women, Dedo Work, Long Hair, Lucky Bag, Gang bang, Housewife, Small Tits, Instructor, Short Hair, Stepfamily, Girl Guide, Brown Hair, Domination, Mini Skirt, Big Pennis, Stepmother, Debut Work, Lotion Oil, Piledriver, Pink Rotor, Look alike, Nyotaimori, Prostitute, Dark Style, Exhibition, Time Stops, Brush Down, Titty Fuck, Reluctance, High Heels, Restraints, Atmosphere, Ass Fetish, Oil Lotion, Emmanuelle, Schoolgirl, 50 Minutes, Cherry Boy, Virgin Man, Sex Friend, Impregnate, Defecation, Homosexual, White Skin, First Shot, Mini Small, Knee Socks, Leg Fetish, Young Wife, Mini Petit, Nasty Hard, Coprophagy, Masturbate, Hot Spring, God Of Sex, Mom Friend, Nice Booty, Bunny Girl, Panty Shot, Stewardess, Black Hair, Skirt Mono, Cum Eating, Laboratory, Collection, Race Queen, Mother Dog, Slim Waist, Sex Change, Dirty Talk, Sportswear, colleagues, Colleagues, Home Visit, Gag Comedy, Super Milk, No Panties, Staff Push, Young Lady, Shijutakin, Individual, Camouflage, Short Skirt, Documentary, Entertainer, Aphrodisiac, Transgender, Transsexual, Doggy Style, Cat Cosplay, Sailor Suit, Turn Number, China Dress, Unavoidable, Lori System, Anchorwoman, Adult Movie, Single Work, Butt Fetish, Acme Orgasm, Hit On Boys, Confinement, Cunnilingus, Set Product, Love Affair, Foot Fetish, Nipple Slip, Deep Throat, Bubble Bath, Cum on face, Compilation, Pretty Girl, Sneak Shots, Breast Milk, Cheerleader, Finger Fuck, Oral Firing, Big Breasts, Words Blame, Panty Shots, Black Actor, Paradise TV, Intercourse, Naked Apron, Prostitutes, Beauty Shop, Dark System, Female Boss, Love Comedy, Health Soap, Erotic Wear, Humiliation, Promiscuous, Promiscuity, Refreshment, Pretty Tits, Big Chicken, hard System, Grandfather, Loose Socks, Plane bread, Instant Sex, Gym Clothes, Hotel Owner, Health Care, Best Of All, Drink Urine, Tight Dress, Racing Girl, Proud Pussy, Medium Hair, Esthetician, Independent, Minamibuchi, Massage Oil, Office Lady, Dirty Words, Mini Series, Facesitting, Drink Saliva, Egg Vibrator, Elder Sister, Emulsion Oil, Erotic Photo, Face Sitting, Fair skinned, Farting Love, Female Ninja, Femdom Slave, Glabrousness, Glasses Girl, Hit On Girls, Hysteroscope, Imprisonment, Independents, Subjectivity, Kakuiro Role, Kounaihassha, Transsexuals, 40 Years Old, 50 Years Old, Age Old Wife, Best Omnibus, Big Vibrator, Shaved Bread, Shaved Pussy, Bubble Socks, Busty Fetish, Campus Story, Liquor Store, Love And Sex, Subordinates, Rigid System, Magical Girl, Many Stories, Martial Arts, feminization, Masturbation, Mature Woman, Incontinence, incontinence, Lesbian Kiss, Model System, Multi person, Multi Person, Limited Time, No Underwear, Leg Crossing, Daihatsu Hat, Nymphomaniac, Older Sister, Virgin Thing, Other Fetish, Other Finger, Over 4 Hours, Receptionist, VR Exclusive, Risky Mosaic, Ropes & Ties, School Girls, Western Pins, New Daughter, Surface Area, Mature Women, Surplus Work, Feminization, Transvestite, Cheerleaders, College Girl, Proprietress, Common Sense, Contribution, Cum In Mouth, Middleweight, Cum in mouth, Asian Actress, Chest Chiller, Western Goods, Other Student, Swallow Sperm, Fanza Limited, Stewardess Ca, Personal Work, Golden Shower, Over 16 Hours, Sex Education, Photo Gallery, Swimming Wear, Cheating Wife, Beautiful Cat, Baseball Fist, Large Breasts, Vibrating Egg, Female Doctor, Cross Dresser, School mizugi, Woman Warrior, Private Tutor, Human Monster, Naked Clothes, Old Playmates, Delivery only, Masochist Man, Shrine Maiden, Men And Women, Hermaphrodite, Delivery Only, Campaign Girl, Married Woman, Mother Friend, Small Breasts, E Cup Or More, Group Bukkake, Best Complete, Married Women, Swimming Race, Super Breasts, Man Squirting, Female Anchor, Chinese Dress, White Of Eyes, Same Sex Love, Embarrassment, Sm Department, Substance Use, Mother in law, Pregnant Woman, Various Worker, Other Fetishes, Newcomer Debut, Massage Parlor, Male Squirting, Pickup Amateur, Mizute Clothes, Drinking Urine, Administration, PICKUP Amateur, Beautiful Skin, Kireina onesan, Fishnet Tights, Drinking Party, Minimal Mosaic, Bleached Spots, Forced Blowjob, Female Soldier, Female Teacher, Sailor Uniform, Extreme Orgasm, Slim Pixelated, Cum Swallowing, Wife And Child, Neat And Clean, Shortcut Girls, Bus Tour Guide, Lewd Lewd Lady, Transformation, Big Tits Lover, Tall Glamorous, Digital Mosaic, School Teacher, Beautiful Legs, Beautiful Girl, School Uniform, Beautiful Tits, Beautiful Butt, Wearing Erotic, Female Warrior, Reverse Number, Original Works, Sexual Slavery, Beautiful Chest, Special Effects, Private Teacher, Psycho Thriller, Female Molester, Low Cost Movies, Forty Years Old, Reverse Pick Up, Fighting Action, Lesbian Kissing, Romantic Comedy, Anime Character, Foreign Imports, Lewd Nasty Lady, Foreign Actress, Academy Uniform, School Swimwear, FANZA Exclusive, 4 Hours Or More, Super VIP Movie, Thanks Offering, School Swimsuit, Science Fiction, Beautiful Pussy, Masculine Woman, Cute Little Boy, Business Attire, Beautiful Woman, High Definition, Ordinary Person, One Piece Dress, Various Workers, But Amazing Sex, High Quality Vr, Pantyhose Thing, Man is Daughter, Bimbo Gal Bitch, Resort Equipment, Husband And Wife, Flight Attendant, 3P Multiple play, Idol & Celebrity, Picking Up Girls, Childhood Friend, Cleaning Blowjob, Object Insertion, Outdoor Exposure, Exclusive Movies, Black Male Actor, Pantyhose Tights, Partner Exchange, Non nude Erotica, School Swimsuits, Female Detective, Miniskirt Police, College Students, Esthetic Massage, Game Live Action, Immediate Saddle, Electric Machine, Historical Drama, Tea Tea Ceremony, Fan Appreciation, Genital Close Up, The Magic Mirror, 16 Hours Or More, Thirty Years Old, Female Announcer, Cusco Exposition, overseas Imports, Girls Department, Drama Department, Adopted Daughter, High School Girl, Multiple Stories, Advertising Idol, Dmm Distribution, Mania Department, Consecutive Coat, Big Breast Fetish, Beautiful Breasts, Limited Time Sale, Electric Massager, Limitless Reality, Action & Fighting, Fetish Department, Super Heavyweight, Business Clothing, Brutal Expression, Female Main Angle, Yariman Gal Bitch, Intense Back Fuck, adoptive Daughter, Actress Department, Collaboration Work, Other Love Affairs, Soggy Masturbation, Ai Generated Works, Actress Collection, The Delusion Group, Collaborative Work, Works Over 4 Hours, Physical Education, Female Investigator, Various Professions, Medical Examination, Extreme Storm Surge, Planning Department, Ordinary Department, Hardcore Department, Manners And Customs, Various Occupations, Rejuvenation Massage, Transform To Heroine, Masturbation Support, High Definition (Hd), Ultra Slim Pixelated, Internal Ejaculation, Confession Experience, Miniskirt Policewoman, Club Activity Manager, Shame And Humiliation, Hospitals And Clinics, Hypnotic Brainwashing, Kiss Of Same Sex Love, Advertising Male Idol, Premature Ejaculation, Delivery Only Amateur, Female College Student, Subjective Perspective, 4 Hours Past Work Time, Beautiful Mature Woman, Exclusive Distribution, Transformation Heroine, Documentary Department, Original Collaboration, Drugs And Aphrodisiacs, Princess & Mademoiselle, Club Activities Manager, Mature Woman Department, Variety Show Department, Subordinate Or Colleague, Experimental Plain White, Av Open 2014 Heavyweight, Confession Of Experience, Foreign Object Insertion, Mistress Japanese Geisha, Shop Manager Recommended, Hostesses And Prostitutes, Getting Paid For Business, Club Hostess & Sex Worker, Foreign Object Penetration, Limited To Dmm Distribution, With Benefits (AV Baseball), Realistic Version Of The Game, More Than 16 Hours Of Footage, With Bonus Video Only For MGS, With Bonus Video Only For Mgs, Limited To Fanza Distribution, Rookie Debuts One After Another, Toys and foreign matter inserts, Administrator Recommended Works, Smartphone Recommendation Animation, Shortcut Girls Boyish But Amazing Sex, Orgy With Nori Crazy For Multiple Play, Prestige 20Th Anniversary Special Project, Vertical Video Recommended For Smartphones, Orgy With Glue Im Crazy For Multiple Plays, Comes With Bonus Footage Only Available At Mgs\",\"area\":\"Japan, China\",\"lang\":\"\",\"year\":\"2024,2023,2022,2021,2020,2019,2018,2017,2016,2015,2014,2013,2012,2011,2010,2009,2008,2007,2006,2005,2004,2003,2002,2001,2000,1999\",\"star\":\"\",\"director\":\"\",\"state\":\"Release,Trailer,Full\",\"version\":\"HD,Full HD,CAM,TV\"}', '', '', '');
INSERT INTO `javcms_type` VALUES ('3', 'Uncensored Leaked', 'uncensored-leaked', '3', '1', '0', '1', 'type.html', 'show.html', 'detail.html', 'play.html', 'down.html', '', '', '', '', '{\"class\":\"3D, 3P, Bl, SM, OL, 4K, Hd, 69, Sf, Vr, Ol, Sm, POV, Toy, Gay, VIP, Gal, Ntr, BBW, Pee, Ass, Tan, NTR, 4HR, Drag, Teen, Back, Tall, Gulp, Post, Bath, SVIP, Kiss, 8KVR, 8Kvr, Plot, Tied, Love, Play, Geek, Plan, Coma, Fist, Slut, Slim, De M, Best, Idol, Peep, Ingo, Food, M jo, Lady, Long, Scat, Hame, Anal, Neat, Half, Mask, Butt, Anus, MILF, Maid, Orgy, Fear, Milk, Aunt, Mini, Do M, Ride, Yoga, Wife, Drug, Sexy, Pure, Puke, Eros, Vibe, Trip, Doll, Tool, Tits, Rape, Nampa, H Cup, 3P 4P, Gonzo, D Cup, Naive, Abuse, Boots, Semen, Missy, Bride, Myodo, Scold, Debut, G Cup, M Leg, M Man, Dance, I Cup, E Cup, Fifty, Other, Enema, Widow, Drama, Small, Chest, WLB B, Tutor, Model, Dildo, Vomit, Chaos, F Cup, Slave, Skirt, Sixty, Witch, Shota, Indie, Queen, Shave, Shame, Nurse, Devil, Blond, Cogal, Hotel, Apron, Kusco, Cruel, Honyo, J Cup, Yokui, Aokan, Bitch, Otaku, Harem, Junko, Rotor, Drunk, Stool, Hairy, Curls, Cusco, Beast, Fight, Nasty, visit, Kyonyu, Carsex, Series, Whites, Acting, Action, Selfie, Chijyo, People, Chikan, Sci Fi, Chubby, Juudai, Clinic, Office, Kimono, School, Peeing, Affair, Incest, Candle, Bikini, Dating, Muscle, Annual, Binyuu, Artist, Saddle, Digimo, Mother, Yukata, Indies, Epuron, Erotic, Blazer, Riding, Expose, Blonde, Facial, Insult, Masumi, Masked, Fetish, Record, Rankou, No Bra, Paipan, M Male, Couple, Friend, G Spot, Gansha, Lotion, Bronze, Lolita, Horror, How To, Kyoshi, Pranks, Shower, Sister, Sports, Suntan, Others, Sweaty, Portio, Thirty, Tickle, Travel, Uterus, Boyish, Virgin, clinic, Voyeur, Petite, Beauty, Frenzy, Clothes, Teacher, Project, Urinate, Nursing, Pissing, Decatin, Tattoos, Amateur, Student, Uniform, Bishiri, Handjob, Sunburn, Classic, Forties, Athlete, Torture, AV Idol, Neglect, Ryojoku, Bikyaku, Retreat, Pervert, Bathing, Freeter, Reprint, Bathtub, Tied Up, Blowjob, Bodycon, Bondage, Bontage, Braless, Sokkuri, Breasts, Outdoor, Tit Job, In Love, Slender, Bukkake, BUKKAKE, Miscaba, Kosupre, Car Sex, Similar, Gagging, Faction, Cumshot, Shemale, Shaving, Fantasy, Cosplay, Cowgirl, Meeting, Hotehel, Massage, Glasses, Hostess, Manguri, Customs, Fighter, Cuckold, Lesbian, Footjob, M Woman, Grandma, VR Only, Onasapo, Paizuri, Full Hd, Peeping, Quickie, Stepmom, leakage, Big Ass, Furnace, Variety, Actress, Av Open, Omnibus, Addition, V Cinema, Outdoors, Adultery, Advanced, M Female, Our Door, Anal Sex, Kunoichi, Cyoushin, Training, Six Nine, Ignoring, Hand Man, Original, Rice Man, Car Love, Classics, Eat Shit, Esthetic, Shotacon, Together, Daydream, Shokushu, Shipping, Ejection, Close Up, Tickling, Shiofuki, Tentacle, Lingerie, Princess, Pregnant, Cow Calf, Iramatio, Fat Girl, POV Shot, Deriheru, Ball Gag, Hypnosis, Creampie, Bareback, Hot Girl, Spanking, Pornstar, Hatsuura, Planning, Leotards, Delusion, Swimwear, Hospital, Feminine, Mischief, Swimsuit, Sweating, Fighting, Maltreat, Eatpussy, Daughter, Swapping, Muscular, Emmaniel, Homemade, Big Butt, Nice Ass, Big Cock, Gym Suit, Big Tits, Speculum, Exposure, Futanari, Waitress, Speaking, Oral Sex, Catwoman, Youthful, Soapland, Lecturer, Sorority, Hardcore, Nekomimi, Solowork, Solitary, Off Sale, Bloomers, TSUNDERE, Sex Toys, Big Dick, Foursome, Vomiting, Molester, Rigidity, Vibrator, Fainting, Emmanuel, Landlady, Gang Rape, Interview, Hot Water, Gyno Exam, Heaven Tv, Hi vision, Baby Face, Loli Type, Hostesses, Foreigner, Huge Tits, Sexy Legs, Nice Butt, Shout Out, Sixtynine, Nice Tits, Conceived, Companion, Soft Body, Solo Work, Squirting, Stockings, Exclusive, Nose Hook, Miniskirt, Sweet Ass, Tall Girl, Tall Lady, Tentacles, Time Stop, Tokusatsu, Expensive, Underwear, Urination, Childhood, Cheongsam, Secretary, Adventure, Scatology, Whitening, Animation, Ass Lover, Celebrity, Restraint, Reproduce, Proboscis, Porn Star, Big Asses, Pantyhose, Black Man, Boys Love, Blow Mono, Call Girl, Face Ride, Movement , Face Shot, Labyrinth, Fair Skin, Kokyoplay, Threesome, Miss Miss, Mousozoku, Fingering, For Women, Dedo Work, Long Hair, Lucky Bag, Gang bang, Housewife, Small Tits, Instructor, Short Hair, Stepfamily, Girl Guide, Brown Hair, Domination, Mini Skirt, Big Pennis, Stepmother, Debut Work, Lotion Oil, Piledriver, Pink Rotor, Look alike, Nyotaimori, Prostitute, Dark Style, Exhibition, Time Stops, Brush Down, Titty Fuck, Reluctance, High Heels, Restraints, Atmosphere, Ass Fetish, Oil Lotion, Emmanuelle, Schoolgirl, 50 Minutes, Cherry Boy, Virgin Man, Sex Friend, Impregnate, Defecation, Homosexual, White Skin, First Shot, Mini Small, Knee Socks, Leg Fetish, Young Wife, Mini Petit, Nasty Hard, Coprophagy, Masturbate, Hot Spring, God Of Sex, Mom Friend, Nice Booty, Bunny Girl, Panty Shot, Stewardess, Black Hair, Skirt Mono, Cum Eating, Laboratory, Collection, Race Queen, Mother Dog, Slim Waist, Sex Change, Dirty Talk, Sportswear, colleagues, Colleagues, Home Visit, Gag Comedy, Super Milk, No Panties, Staff Push, Young Lady, Shijutakin, Individual, Camouflage, Short Skirt, Documentary, Entertainer, Aphrodisiac, Transgender, Transsexual, Doggy Style, Cat Cosplay, Sailor Suit, Turn Number, China Dress, Unavoidable, Lori System, Anchorwoman, Adult Movie, Single Work, Butt Fetish, Acme Orgasm, Hit On Boys, Confinement, Cunnilingus, Set Product, Love Affair, Foot Fetish, Nipple Slip, Deep Throat, Bubble Bath, Cum on face, Compilation, Pretty Girl, Sneak Shots, Breast Milk, Cheerleader, Finger Fuck, Oral Firing, Big Breasts, Words Blame, Panty Shots, Black Actor, Paradise TV, Intercourse, Naked Apron, Prostitutes, Beauty Shop, Dark System, Female Boss, Love Comedy, Health Soap, Erotic Wear, Humiliation, Promiscuous, Promiscuity, Refreshment, Pretty Tits, Big Chicken, hard System, Grandfather, Loose Socks, Plane bread, Instant Sex, Gym Clothes, Hotel Owner, Health Care, Best Of All, Drink Urine, Tight Dress, Racing Girl, Proud Pussy, Medium Hair, Esthetician, Independent, Minamibuchi, Massage Oil, Office Lady, Dirty Words, Mini Series, Facesitting, Drink Saliva, Egg Vibrator, Elder Sister, Emulsion Oil, Erotic Photo, Face Sitting, Fair skinned, Farting Love, Female Ninja, Femdom Slave, Glabrousness, Glasses Girl, Hit On Girls, Hysteroscope, Imprisonment, Independents, Subjectivity, Kakuiro Role, Kounaihassha, Transsexuals, 40 Years Old, 50 Years Old, Age Old Wife, Best Omnibus, Big Vibrator, Shaved Bread, Shaved Pussy, Bubble Socks, Busty Fetish, Campus Story, Liquor Store, Love And Sex, Subordinates, Rigid System, Magical Girl, Many Stories, Martial Arts, feminization, Masturbation, Mature Woman, Incontinence, incontinence, Lesbian Kiss, Model System, Multi person, Multi Person, Limited Time, No Underwear, Leg Crossing, Daihatsu Hat, Nymphomaniac, Older Sister, Virgin Thing, Other Fetish, Other Finger, Over 4 Hours, Receptionist, VR Exclusive, Risky Mosaic, Ropes & Ties, School Girls, Western Pins, New Daughter, Surface Area, Mature Women, Surplus Work, Feminization, Transvestite, Cheerleaders, College Girl, Proprietress, Common Sense, Contribution, Cum In Mouth, Middleweight, Cum in mouth, Asian Actress, Chest Chiller, Western Goods, Other Student, Swallow Sperm, Fanza Limited, Stewardess Ca, Personal Work, Golden Shower, Over 16 Hours, Sex Education, Photo Gallery, Swimming Wear, Cheating Wife, Beautiful Cat, Baseball Fist, Large Breasts, Vibrating Egg, Female Doctor, Cross Dresser, School mizugi, Woman Warrior, Private Tutor, Human Monster, Naked Clothes, Old Playmates, Delivery only, Masochist Man, Shrine Maiden, Men And Women, Hermaphrodite, Delivery Only, Campaign Girl, Married Woman, Mother Friend, Small Breasts, E Cup Or More, Group Bukkake, Best Complete, Married Women, Swimming Race, Super Breasts, Man Squirting, Female Anchor, Chinese Dress, White Of Eyes, Same Sex Love, Embarrassment, Sm Department, Substance Use, Mother in law, Pregnant Woman, Various Worker, Other Fetishes, Newcomer Debut, Massage Parlor, Male Squirting, Pickup Amateur, Mizute Clothes, Drinking Urine, Administration, PICKUP Amateur, Beautiful Skin, Kireina onesan, Fishnet Tights, Drinking Party, Minimal Mosaic, Bleached Spots, Forced Blowjob, Female Soldier, Female Teacher, Sailor Uniform, Extreme Orgasm, Slim Pixelated, Cum Swallowing, Wife And Child, Neat And Clean, Shortcut Girls, Bus Tour Guide, Lewd Lewd Lady, Transformation, Big Tits Lover, Tall Glamorous, Digital Mosaic, School Teacher, Beautiful Legs, Beautiful Girl, School Uniform, Beautiful Tits, Beautiful Butt, Wearing Erotic, Female Warrior, Reverse Number, Original Works, Sexual Slavery, Beautiful Chest, Special Effects, Private Teacher, Psycho Thriller, Female Molester, Low Cost Movies, Forty Years Old, Reverse Pick Up, Fighting Action, Lesbian Kissing, Romantic Comedy, Anime Character, Foreign Imports, Lewd Nasty Lady, Foreign Actress, Academy Uniform, School Swimwear, FANZA Exclusive, 4 Hours Or More, Super VIP Movie, Thanks Offering, School Swimsuit, Science Fiction, Beautiful Pussy, Masculine Woman, Cute Little Boy, Business Attire, Beautiful Woman, High Definition, Ordinary Person, One Piece Dress, Various Workers, But Amazing Sex, High Quality Vr, Pantyhose Thing, Man is Daughter, Bimbo Gal Bitch, Resort Equipment, Husband And Wife, Flight Attendant, 3P Multiple play, Idol & Celebrity, Picking Up Girls, Childhood Friend, Cleaning Blowjob, Object Insertion, Outdoor Exposure, Exclusive Movies, Black Male Actor, Pantyhose Tights, Partner Exchange, Non nude Erotica, School Swimsuits, Female Detective, Miniskirt Police, College Students, Esthetic Massage, Game Live Action, Immediate Saddle, Electric Machine, Historical Drama, Tea Tea Ceremony, Fan Appreciation, Genital Close Up, The Magic Mirror, 16 Hours Or More, Thirty Years Old, Female Announcer, Cusco Exposition, overseas Imports, Girls Department, Drama Department, Adopted Daughter, High School Girl, Multiple Stories, Advertising Idol, Dmm Distribution, Mania Department, Consecutive Coat, Big Breast Fetish, Beautiful Breasts, Limited Time Sale, Electric Massager, Limitless Reality, Action & Fighting, Fetish Department, Super Heavyweight, Business Clothing, Brutal Expression, Female Main Angle, Yariman Gal Bitch, Intense Back Fuck, adoptive Daughter, Actress Department, Collaboration Work, Other Love Affairs, Soggy Masturbation, Ai Generated Works, Actress Collection, The Delusion Group, Collaborative Work, Works Over 4 Hours, Physical Education, Female Investigator, Various Professions, Medical Examination, Extreme Storm Surge, Planning Department, Ordinary Department, Hardcore Department, Manners And Customs, Various Occupations, Rejuvenation Massage, Transform To Heroine, Masturbation Support, High Definition (Hd), Ultra Slim Pixelated, Internal Ejaculation, Confession Experience, Miniskirt Policewoman, Club Activity Manager, Shame And Humiliation, Hospitals And Clinics, Hypnotic Brainwashing, Kiss Of Same Sex Love, Advertising Male Idol, Premature Ejaculation, Delivery Only Amateur, Female College Student, Subjective Perspective, 4 Hours Past Work Time, Beautiful Mature Woman, Exclusive Distribution, Transformation Heroine, Documentary Department, Original Collaboration, Drugs And Aphrodisiacs, Princess & Mademoiselle, Club Activities Manager, Mature Woman Department, Variety Show Department, Subordinate Or Colleague, Experimental Plain White, Av Open 2014 Heavyweight, Confession Of Experience, Foreign Object Insertion, Mistress Japanese Geisha, Shop Manager Recommended, Hostesses And Prostitutes, Getting Paid For Business, Club Hostess & Sex Worker, Foreign Object Penetration, Limited To Dmm Distribution, With Benefits (AV Baseball), Realistic Version Of The Game, More Than 16 Hours Of Footage, With Bonus Video Only For MGS, With Bonus Video Only For Mgs, Limited To Fanza Distribution, Rookie Debuts One After Another, Toys and foreign matter inserts, Administrator Recommended Works, Smartphone Recommendation Animation, Shortcut Girls Boyish But Amazing Sex, Orgy With Nori Crazy For Multiple Play, Prestige 20Th Anniversary Special Project, Vertical Video Recommended For Smartphones, Orgy With Glue Im Crazy For Multiple Plays, Comes With Bonus Footage Only Available At Mgs\",\"area\":\"Japan, China\",\"lang\":\"\",\"year\":\"2024,2023,2022,2021,2020,2019,2018,2017,2016,2015,2014,2013,2012,2011,2010,2009,2008,2007,2006,2005,2004,2003,2002,2001,2000,1999\",\"star\":\"\",\"director\":\"\",\"state\":\"Release,Trailer,Full\",\"version\":\"HD,Full HD,CAM,TV\"}', '', '', '');
INSERT INTO `javcms_type` VALUES ('4', 'Amateur', 'amateur', '4', '1', '0', '1', 'type.html', 'show.html', 'detail.html', 'play.html', 'down.html', '', '', '', '', '{\"class\":\"3D, 3P, Bl, SM, OL, 4K, Hd, 69, Sf, Vr, Ol, Sm, POV, Toy, Gay, VIP, Gal, Ntr, BBW, Pee, Ass, Tan, NTR, 4HR, Drag, Teen, Back, Tall, Gulp, Post, Bath, SVIP, Kiss, 8KVR, 8Kvr, Plot, Tied, Love, Play, Geek, Plan, Coma, Fist, Slut, Slim, De M, Best, Idol, Peep, Ingo, Food, M jo, Lady, Long, Scat, Hame, Anal, Neat, Half, Mask, Butt, Anus, MILF, Maid, Orgy, Fear, Milk, Aunt, Mini, Do M, Ride, Yoga, Wife, Drug, Sexy, Pure, Puke, Eros, Vibe, Trip, Doll, Tool, Tits, Rape, Nampa, H Cup, 3P 4P, Gonzo, D Cup, Naive, Abuse, Boots, Semen, Missy, Bride, Myodo, Scold, Debut, G Cup, M Leg, M Man, Dance, I Cup, E Cup, Fifty, Other, Enema, Widow, Drama, Small, Chest, WLB B, Tutor, Model, Dildo, Vomit, Chaos, F Cup, Slave, Skirt, Sixty, Witch, Shota, Indie, Queen, Shave, Shame, Nurse, Devil, Blond, Cogal, Hotel, Apron, Kusco, Cruel, Honyo, J Cup, Yokui, Aokan, Bitch, Otaku, Harem, Junko, Rotor, Drunk, Stool, Hairy, Curls, Cusco, Beast, Fight, Nasty, visit, Kyonyu, Carsex, Series, Whites, Acting, Action, Selfie, Chijyo, People, Chikan, Sci Fi, Chubby, Juudai, Clinic, Office, Kimono, School, Peeing, Affair, Incest, Candle, Bikini, Dating, Muscle, Annual, Binyuu, Artist, Saddle, Digimo, Mother, Yukata, Indies, Epuron, Erotic, Blazer, Riding, Expose, Blonde, Facial, Insult, Masumi, Masked, Fetish, Record, Rankou, No Bra, Paipan, M Male, Couple, Friend, G Spot, Gansha, Lotion, Bronze, Lolita, Horror, How To, Kyoshi, Pranks, Shower, Sister, Sports, Suntan, Others, Sweaty, Portio, Thirty, Tickle, Travel, Uterus, Boyish, Virgin, clinic, Voyeur, Petite, Beauty, Frenzy, Clothes, Teacher, Project, Urinate, Nursing, Pissing, Decatin, Tattoos, Amateur, Student, Uniform, Bishiri, Handjob, Sunburn, Classic, Forties, Athlete, Torture, AV Idol, Neglect, Ryojoku, Bikyaku, Retreat, Pervert, Bathing, Freeter, Reprint, Bathtub, Tied Up, Blowjob, Bodycon, Bondage, Bontage, Braless, Sokkuri, Breasts, Outdoor, Tit Job, In Love, Slender, Bukkake, BUKKAKE, Miscaba, Kosupre, Car Sex, Similar, Gagging, Faction, Cumshot, Shemale, Shaving, Fantasy, Cosplay, Cowgirl, Meeting, Hotehel, Massage, Glasses, Hostess, Manguri, Customs, Fighter, Cuckold, Lesbian, Footjob, M Woman, Grandma, VR Only, Onasapo, Paizuri, Full Hd, Peeping, Quickie, Stepmom, leakage, Big Ass, Furnace, Variety, Actress, Av Open, Omnibus, Addition, V Cinema, Outdoors, Adultery, Advanced, M Female, Our Door, Anal Sex, Kunoichi, Cyoushin, Training, Six Nine, Ignoring, Hand Man, Original, Rice Man, Car Love, Classics, Eat Shit, Esthetic, Shotacon, Together, Daydream, Shokushu, Shipping, Ejection, Close Up, Tickling, Shiofuki, Tentacle, Lingerie, Princess, Pregnant, Cow Calf, Iramatio, Fat Girl, POV Shot, Deriheru, Ball Gag, Hypnosis, Creampie, Bareback, Hot Girl, Spanking, Pornstar, Hatsuura, Planning, Leotards, Delusion, Swimwear, Hospital, Feminine, Mischief, Swimsuit, Sweating, Fighting, Maltreat, Eatpussy, Daughter, Swapping, Muscular, Emmaniel, Homemade, Big Butt, Nice Ass, Big Cock, Gym Suit, Big Tits, Speculum, Exposure, Futanari, Waitress, Speaking, Oral Sex, Catwoman, Youthful, Soapland, Lecturer, Sorority, Hardcore, Nekomimi, Solowork, Solitary, Off Sale, Bloomers, TSUNDERE, Sex Toys, Big Dick, Foursome, Vomiting, Molester, Rigidity, Vibrator, Fainting, Emmanuel, Landlady, Gang Rape, Interview, Hot Water, Gyno Exam, Heaven Tv, Hi vision, Baby Face, Loli Type, Hostesses, Foreigner, Huge Tits, Sexy Legs, Nice Butt, Shout Out, Sixtynine, Nice Tits, Conceived, Companion, Soft Body, Solo Work, Squirting, Stockings, Exclusive, Nose Hook, Miniskirt, Sweet Ass, Tall Girl, Tall Lady, Tentacles, Time Stop, Tokusatsu, Expensive, Underwear, Urination, Childhood, Cheongsam, Secretary, Adventure, Scatology, Whitening, Animation, Ass Lover, Celebrity, Restraint, Reproduce, Proboscis, Porn Star, Big Asses, Pantyhose, Black Man, Boys Love, Blow Mono, Call Girl, Face Ride, Movement , Face Shot, Labyrinth, Fair Skin, Kokyoplay, Threesome, Miss Miss, Mousozoku, Fingering, For Women, Dedo Work, Long Hair, Lucky Bag, Gang bang, Housewife, Small Tits, Instructor, Short Hair, Stepfamily, Girl Guide, Brown Hair, Domination, Mini Skirt, Big Pennis, Stepmother, Debut Work, Lotion Oil, Piledriver, Pink Rotor, Look alike, Nyotaimori, Prostitute, Dark Style, Exhibition, Time Stops, Brush Down, Titty Fuck, Reluctance, High Heels, Restraints, Atmosphere, Ass Fetish, Oil Lotion, Emmanuelle, Schoolgirl, 50 Minutes, Cherry Boy, Virgin Man, Sex Friend, Impregnate, Defecation, Homosexual, White Skin, First Shot, Mini Small, Knee Socks, Leg Fetish, Young Wife, Mini Petit, Nasty Hard, Coprophagy, Masturbate, Hot Spring, God Of Sex, Mom Friend, Nice Booty, Bunny Girl, Panty Shot, Stewardess, Black Hair, Skirt Mono, Cum Eating, Laboratory, Collection, Race Queen, Mother Dog, Slim Waist, Sex Change, Dirty Talk, Sportswear, colleagues, Colleagues, Home Visit, Gag Comedy, Super Milk, No Panties, Staff Push, Young Lady, Shijutakin, Individual, Camouflage, Short Skirt, Documentary, Entertainer, Aphrodisiac, Transgender, Transsexual, Doggy Style, Cat Cosplay, Sailor Suit, Turn Number, China Dress, Unavoidable, Lori System, Anchorwoman, Adult Movie, Single Work, Butt Fetish, Acme Orgasm, Hit On Boys, Confinement, Cunnilingus, Set Product, Love Affair, Foot Fetish, Nipple Slip, Deep Throat, Bubble Bath, Cum on face, Compilation, Pretty Girl, Sneak Shots, Breast Milk, Cheerleader, Finger Fuck, Oral Firing, Big Breasts, Words Blame, Panty Shots, Black Actor, Paradise TV, Intercourse, Naked Apron, Prostitutes, Beauty Shop, Dark System, Female Boss, Love Comedy, Health Soap, Erotic Wear, Humiliation, Promiscuous, Promiscuity, Refreshment, Pretty Tits, Big Chicken, hard System, Grandfather, Loose Socks, Plane bread, Instant Sex, Gym Clothes, Hotel Owner, Health Care, Best Of All, Drink Urine, Tight Dress, Racing Girl, Proud Pussy, Medium Hair, Esthetician, Independent, Minamibuchi, Massage Oil, Office Lady, Dirty Words, Mini Series, Facesitting, Drink Saliva, Egg Vibrator, Elder Sister, Emulsion Oil, Erotic Photo, Face Sitting, Fair skinned, Farting Love, Female Ninja, Femdom Slave, Glabrousness, Glasses Girl, Hit On Girls, Hysteroscope, Imprisonment, Independents, Subjectivity, Kakuiro Role, Kounaihassha, Transsexuals, 40 Years Old, 50 Years Old, Age Old Wife, Best Omnibus, Big Vibrator, Shaved Bread, Shaved Pussy, Bubble Socks, Busty Fetish, Campus Story, Liquor Store, Love And Sex, Subordinates, Rigid System, Magical Girl, Many Stories, Martial Arts, feminization, Masturbation, Mature Woman, Incontinence, incontinence, Lesbian Kiss, Model System, Multi person, Multi Person, Limited Time, No Underwear, Leg Crossing, Daihatsu Hat, Nymphomaniac, Older Sister, Virgin Thing, Other Fetish, Other Finger, Over 4 Hours, Receptionist, VR Exclusive, Risky Mosaic, Ropes & Ties, School Girls, Western Pins, New Daughter, Surface Area, Mature Women, Surplus Work, Feminization, Transvestite, Cheerleaders, College Girl, Proprietress, Common Sense, Contribution, Cum In Mouth, Middleweight, Cum in mouth, Asian Actress, Chest Chiller, Western Goods, Other Student, Swallow Sperm, Fanza Limited, Stewardess Ca, Personal Work, Golden Shower, Over 16 Hours, Sex Education, Photo Gallery, Swimming Wear, Cheating Wife, Beautiful Cat, Baseball Fist, Large Breasts, Vibrating Egg, Female Doctor, Cross Dresser, School mizugi, Woman Warrior, Private Tutor, Human Monster, Naked Clothes, Old Playmates, Delivery only, Masochist Man, Shrine Maiden, Men And Women, Hermaphrodite, Delivery Only, Campaign Girl, Married Woman, Mother Friend, Small Breasts, E Cup Or More, Group Bukkake, Best Complete, Married Women, Swimming Race, Super Breasts, Man Squirting, Female Anchor, Chinese Dress, White Of Eyes, Same Sex Love, Embarrassment, Sm Department, Substance Use, Mother in law, Pregnant Woman, Various Worker, Other Fetishes, Newcomer Debut, Massage Parlor, Male Squirting, Pickup Amateur, Mizute Clothes, Drinking Urine, Administration, PICKUP Amateur, Beautiful Skin, Kireina onesan, Fishnet Tights, Drinking Party, Minimal Mosaic, Bleached Spots, Forced Blowjob, Female Soldier, Female Teacher, Sailor Uniform, Extreme Orgasm, Slim Pixelated, Cum Swallowing, Wife And Child, Neat And Clean, Shortcut Girls, Bus Tour Guide, Lewd Lewd Lady, Transformation, Big Tits Lover, Tall Glamorous, Digital Mosaic, School Teacher, Beautiful Legs, Beautiful Girl, School Uniform, Beautiful Tits, Beautiful Butt, Wearing Erotic, Female Warrior, Reverse Number, Original Works, Sexual Slavery, Beautiful Chest, Special Effects, Private Teacher, Psycho Thriller, Female Molester, Low Cost Movies, Forty Years Old, Reverse Pick Up, Fighting Action, Lesbian Kissing, Romantic Comedy, Anime Character, Foreign Imports, Lewd Nasty Lady, Foreign Actress, Academy Uniform, School Swimwear, FANZA Exclusive, 4 Hours Or More, Super VIP Movie, Thanks Offering, School Swimsuit, Science Fiction, Beautiful Pussy, Masculine Woman, Cute Little Boy, Business Attire, Beautiful Woman, High Definition, Ordinary Person, One Piece Dress, Various Workers, But Amazing Sex, High Quality Vr, Pantyhose Thing, Man is Daughter, Bimbo Gal Bitch, Resort Equipment, Husband And Wife, Flight Attendant, 3P Multiple play, Idol & Celebrity, Picking Up Girls, Childhood Friend, Cleaning Blowjob, Object Insertion, Outdoor Exposure, Exclusive Movies, Black Male Actor, Pantyhose Tights, Partner Exchange, Non nude Erotica, School Swimsuits, Female Detective, Miniskirt Police, College Students, Esthetic Massage, Game Live Action, Immediate Saddle, Electric Machine, Historical Drama, Tea Tea Ceremony, Fan Appreciation, Genital Close Up, The Magic Mirror, 16 Hours Or More, Thirty Years Old, Female Announcer, Cusco Exposition, overseas Imports, Girls Department, Drama Department, Adopted Daughter, High School Girl, Multiple Stories, Advertising Idol, Dmm Distribution, Mania Department, Consecutive Coat, Big Breast Fetish, Beautiful Breasts, Limited Time Sale, Electric Massager, Limitless Reality, Action & Fighting, Fetish Department, Super Heavyweight, Business Clothing, Brutal Expression, Female Main Angle, Yariman Gal Bitch, Intense Back Fuck, adoptive Daughter, Actress Department, Collaboration Work, Other Love Affairs, Soggy Masturbation, Ai Generated Works, Actress Collection, The Delusion Group, Collaborative Work, Works Over 4 Hours, Physical Education, Female Investigator, Various Professions, Medical Examination, Extreme Storm Surge, Planning Department, Ordinary Department, Hardcore Department, Manners And Customs, Various Occupations, Rejuvenation Massage, Transform To Heroine, Masturbation Support, High Definition (Hd), Ultra Slim Pixelated, Internal Ejaculation, Confession Experience, Miniskirt Policewoman, Club Activity Manager, Shame And Humiliation, Hospitals And Clinics, Hypnotic Brainwashing, Kiss Of Same Sex Love, Advertising Male Idol, Premature Ejaculation, Delivery Only Amateur, Female College Student, Subjective Perspective, 4 Hours Past Work Time, Beautiful Mature Woman, Exclusive Distribution, Transformation Heroine, Documentary Department, Original Collaboration, Drugs And Aphrodisiacs, Princess & Mademoiselle, Club Activities Manager, Mature Woman Department, Variety Show Department, Subordinate Or Colleague, Experimental Plain White, Av Open 2014 Heavyweight, Confession Of Experience, Foreign Object Insertion, Mistress Japanese Geisha, Shop Manager Recommended, Hostesses And Prostitutes, Getting Paid For Business, Club Hostess & Sex Worker, Foreign Object Penetration, Limited To Dmm Distribution, With Benefits (AV Baseball), Realistic Version Of The Game, More Than 16 Hours Of Footage, With Bonus Video Only For MGS, With Bonus Video Only For Mgs, Limited To Fanza Distribution, Rookie Debuts One After Another, Toys and foreign matter inserts, Administrator Recommended Works, Smartphone Recommendation Animation, Shortcut Girls Boyish But Amazing Sex, Orgy With Nori Crazy For Multiple Play, Prestige 20Th Anniversary Special Project, Vertical Video Recommended For Smartphones, Orgy With Glue Im Crazy For Multiple Plays, Comes With Bonus Footage Only Available At Mgs\",\"area\":\"Japan, China\",\"lang\":\"\",\"year\":\"2024,2023,2022,2021,2020,2019,2018,2017,2016,2015,2014,2013,2012,2011,2010,2009,2008,2007,2006,2005,2004,2003,2002,2001,2000,1999\",\"star\":\"\",\"director\":\"\",\"state\":\"Release,Trailer,Full\",\"version\":\"HD,Full HD,CAM,TV\"}', '', '', '');
INSERT INTO `javcms_type` VALUES ('5', 'Chinese AV', 'chinese-av', '5', '1', '0', '1', 'type.html', 'show.html', 'detail.html', 'play.html', 'down.html', '', '', '', '', '{\"class\":\"3D, 3P, Bl, SM, OL, 4K, Hd, 69, Sf, Vr, Ol, Sm, POV, Toy, Gay, VIP, Gal, Ntr, BBW, Pee, Ass, Tan, NTR, 4HR, Drag, Teen, Back, Tall, Gulp, Post, Bath, SVIP, Kiss, 8KVR, 8Kvr, Plot, Tied, Love, Play, Geek, Plan, Coma, Fist, Slut, Slim, De M, Best, Idol, Peep, Ingo, Food, M jo, Lady, Long, Scat, Hame, Anal, Neat, Half, Mask, Butt, Anus, MILF, Maid, Orgy, Fear, Milk, Aunt, Mini, Do M, Ride, Yoga, Wife, Drug, Sexy, Pure, Puke, Eros, Vibe, Trip, Doll, Tool, Tits, Rape, Nampa, H Cup, 3P 4P, Gonzo, D Cup, Naive, Abuse, Boots, Semen, Missy, Bride, Myodo, Scold, Debut, G Cup, M Leg, M Man, Dance, I Cup, E Cup, Fifty, Other, Enema, Widow, Drama, Small, Chest, WLB B, Tutor, Model, Dildo, Vomit, Chaos, F Cup, Slave, Skirt, Sixty, Witch, Shota, Indie, Queen, Shave, Shame, Nurse, Devil, Blond, Cogal, Hotel, Apron, Kusco, Cruel, Honyo, J Cup, Yokui, Aokan, Bitch, Otaku, Harem, Junko, Rotor, Drunk, Stool, Hairy, Curls, Cusco, Beast, Fight, Nasty, visit, Kyonyu, Carsex, Series, Whites, Acting, Action, Selfie, Chijyo, People, Chikan, Sci Fi, Chubby, Juudai, Clinic, Office, Kimono, School, Peeing, Affair, Incest, Candle, Bikini, Dating, Muscle, Annual, Binyuu, Artist, Saddle, Digimo, Mother, Yukata, Indies, Epuron, Erotic, Blazer, Riding, Expose, Blonde, Facial, Insult, Masumi, Masked, Fetish, Record, Rankou, No Bra, Paipan, M Male, Couple, Friend, G Spot, Gansha, Lotion, Bronze, Lolita, Horror, How To, Kyoshi, Pranks, Shower, Sister, Sports, Suntan, Others, Sweaty, Portio, Thirty, Tickle, Travel, Uterus, Boyish, Virgin, clinic, Voyeur, Petite, Beauty, Frenzy, Clothes, Teacher, Project, Urinate, Nursing, Pissing, Decatin, Tattoos, Amateur, Student, Uniform, Bishiri, Handjob, Sunburn, Classic, Forties, Athlete, Torture, AV Idol, Neglect, Ryojoku, Bikyaku, Retreat, Pervert, Bathing, Freeter, Reprint, Bathtub, Tied Up, Blowjob, Bodycon, Bondage, Bontage, Braless, Sokkuri, Breasts, Outdoor, Tit Job, In Love, Slender, Bukkake, BUKKAKE, Miscaba, Kosupre, Car Sex, Similar, Gagging, Faction, Cumshot, Shemale, Shaving, Fantasy, Cosplay, Cowgirl, Meeting, Hotehel, Massage, Glasses, Hostess, Manguri, Customs, Fighter, Cuckold, Lesbian, Footjob, M Woman, Grandma, VR Only, Onasapo, Paizuri, Full Hd, Peeping, Quickie, Stepmom, leakage, Big Ass, Furnace, Variety, Actress, Av Open, Omnibus, Addition, V Cinema, Outdoors, Adultery, Advanced, M Female, Our Door, Anal Sex, Kunoichi, Cyoushin, Training, Six Nine, Ignoring, Hand Man, Original, Rice Man, Car Love, Classics, Eat Shit, Esthetic, Shotacon, Together, Daydream, Shokushu, Shipping, Ejection, Close Up, Tickling, Shiofuki, Tentacle, Lingerie, Princess, Pregnant, Cow Calf, Iramatio, Fat Girl, POV Shot, Deriheru, Ball Gag, Hypnosis, Creampie, Bareback, Hot Girl, Spanking, Pornstar, Hatsuura, Planning, Leotards, Delusion, Swimwear, Hospital, Feminine, Mischief, Swimsuit, Sweating, Fighting, Maltreat, Eatpussy, Daughter, Swapping, Muscular, Emmaniel, Homemade, Big Butt, Nice Ass, Big Cock, Gym Suit, Big Tits, Speculum, Exposure, Futanari, Waitress, Speaking, Oral Sex, Catwoman, Youthful, Soapland, Lecturer, Sorority, Hardcore, Nekomimi, Solowork, Solitary, Off Sale, Bloomers, TSUNDERE, Sex Toys, Big Dick, Foursome, Vomiting, Molester, Rigidity, Vibrator, Fainting, Emmanuel, Landlady, Gang Rape, Interview, Hot Water, Gyno Exam, Heaven Tv, Hi vision, Baby Face, Loli Type, Hostesses, Foreigner, Huge Tits, Sexy Legs, Nice Butt, Shout Out, Sixtynine, Nice Tits, Conceived, Companion, Soft Body, Solo Work, Squirting, Stockings, Exclusive, Nose Hook, Miniskirt, Sweet Ass, Tall Girl, Tall Lady, Tentacles, Time Stop, Tokusatsu, Expensive, Underwear, Urination, Childhood, Cheongsam, Secretary, Adventure, Scatology, Whitening, Animation, Ass Lover, Celebrity, Restraint, Reproduce, Proboscis, Porn Star, Big Asses, Pantyhose, Black Man, Boys Love, Blow Mono, Call Girl, Face Ride, Movement , Face Shot, Labyrinth, Fair Skin, Kokyoplay, Threesome, Miss Miss, Mousozoku, Fingering, For Women, Dedo Work, Long Hair, Lucky Bag, Gang bang, Housewife, Small Tits, Instructor, Short Hair, Stepfamily, Girl Guide, Brown Hair, Domination, Mini Skirt, Big Pennis, Stepmother, Debut Work, Lotion Oil, Piledriver, Pink Rotor, Look alike, Nyotaimori, Prostitute, Dark Style, Exhibition, Time Stops, Brush Down, Titty Fuck, Reluctance, High Heels, Restraints, Atmosphere, Ass Fetish, Oil Lotion, Emmanuelle, Schoolgirl, 50 Minutes, Cherry Boy, Virgin Man, Sex Friend, Impregnate, Defecation, Homosexual, White Skin, First Shot, Mini Small, Knee Socks, Leg Fetish, Young Wife, Mini Petit, Nasty Hard, Coprophagy, Masturbate, Hot Spring, God Of Sex, Mom Friend, Nice Booty, Bunny Girl, Panty Shot, Stewardess, Black Hair, Skirt Mono, Cum Eating, Laboratory, Collection, Race Queen, Mother Dog, Slim Waist, Sex Change, Dirty Talk, Sportswear, colleagues, Colleagues, Home Visit, Gag Comedy, Super Milk, No Panties, Staff Push, Young Lady, Shijutakin, Individual, Camouflage, Short Skirt, Documentary, Entertainer, Aphrodisiac, Transgender, Transsexual, Doggy Style, Cat Cosplay, Sailor Suit, Turn Number, China Dress, Unavoidable, Lori System, Anchorwoman, Adult Movie, Single Work, Butt Fetish, Acme Orgasm, Hit On Boys, Confinement, Cunnilingus, Set Product, Love Affair, Foot Fetish, Nipple Slip, Deep Throat, Bubble Bath, Cum on face, Compilation, Pretty Girl, Sneak Shots, Breast Milk, Cheerleader, Finger Fuck, Oral Firing, Big Breasts, Words Blame, Panty Shots, Black Actor, Paradise TV, Intercourse, Naked Apron, Prostitutes, Beauty Shop, Dark System, Female Boss, Love Comedy, Health Soap, Erotic Wear, Humiliation, Promiscuous, Promiscuity, Refreshment, Pretty Tits, Big Chicken, hard System, Grandfather, Loose Socks, Plane bread, Instant Sex, Gym Clothes, Hotel Owner, Health Care, Best Of All, Drink Urine, Tight Dress, Racing Girl, Proud Pussy, Medium Hair, Esthetician, Independent, Minamibuchi, Massage Oil, Office Lady, Dirty Words, Mini Series, Facesitting, Drink Saliva, Egg Vibrator, Elder Sister, Emulsion Oil, Erotic Photo, Face Sitting, Fair skinned, Farting Love, Female Ninja, Femdom Slave, Glabrousness, Glasses Girl, Hit On Girls, Hysteroscope, Imprisonment, Independents, Subjectivity, Kakuiro Role, Kounaihassha, Transsexuals, 40 Years Old, 50 Years Old, Age Old Wife, Best Omnibus, Big Vibrator, Shaved Bread, Shaved Pussy, Bubble Socks, Busty Fetish, Campus Story, Liquor Store, Love And Sex, Subordinates, Rigid System, Magical Girl, Many Stories, Martial Arts, feminization, Masturbation, Mature Woman, Incontinence, incontinence, Lesbian Kiss, Model System, Multi person, Multi Person, Limited Time, No Underwear, Leg Crossing, Daihatsu Hat, Nymphomaniac, Older Sister, Virgin Thing, Other Fetish, Other Finger, Over 4 Hours, Receptionist, VR Exclusive, Risky Mosaic, Ropes & Ties, School Girls, Western Pins, New Daughter, Surface Area, Mature Women, Surplus Work, Feminization, Transvestite, Cheerleaders, College Girl, Proprietress, Common Sense, Contribution, Cum In Mouth, Middleweight, Cum in mouth, Asian Actress, Chest Chiller, Western Goods, Other Student, Swallow Sperm, Fanza Limited, Stewardess Ca, Personal Work, Golden Shower, Over 16 Hours, Sex Education, Photo Gallery, Swimming Wear, Cheating Wife, Beautiful Cat, Baseball Fist, Large Breasts, Vibrating Egg, Female Doctor, Cross Dresser, School mizugi, Woman Warrior, Private Tutor, Human Monster, Naked Clothes, Old Playmates, Delivery only, Masochist Man, Shrine Maiden, Men And Women, Hermaphrodite, Delivery Only, Campaign Girl, Married Woman, Mother Friend, Small Breasts, E Cup Or More, Group Bukkake, Best Complete, Married Women, Swimming Race, Super Breasts, Man Squirting, Female Anchor, Chinese Dress, White Of Eyes, Same Sex Love, Embarrassment, Sm Department, Substance Use, Mother in law, Pregnant Woman, Various Worker, Other Fetishes, Newcomer Debut, Massage Parlor, Male Squirting, Pickup Amateur, Mizute Clothes, Drinking Urine, Administration, PICKUP Amateur, Beautiful Skin, Kireina onesan, Fishnet Tights, Drinking Party, Minimal Mosaic, Bleached Spots, Forced Blowjob, Female Soldier, Female Teacher, Sailor Uniform, Extreme Orgasm, Slim Pixelated, Cum Swallowing, Wife And Child, Neat And Clean, Shortcut Girls, Bus Tour Guide, Lewd Lewd Lady, Transformation, Big Tits Lover, Tall Glamorous, Digital Mosaic, School Teacher, Beautiful Legs, Beautiful Girl, School Uniform, Beautiful Tits, Beautiful Butt, Wearing Erotic, Female Warrior, Reverse Number, Original Works, Sexual Slavery, Beautiful Chest, Special Effects, Private Teacher, Psycho Thriller, Female Molester, Low Cost Movies, Forty Years Old, Reverse Pick Up, Fighting Action, Lesbian Kissing, Romantic Comedy, Anime Character, Foreign Imports, Lewd Nasty Lady, Foreign Actress, Academy Uniform, School Swimwear, FANZA Exclusive, 4 Hours Or More, Super VIP Movie, Thanks Offering, School Swimsuit, Science Fiction, Beautiful Pussy, Masculine Woman, Cute Little Boy, Business Attire, Beautiful Woman, High Definition, Ordinary Person, One Piece Dress, Various Workers, But Amazing Sex, High Quality Vr, Pantyhose Thing, Man is Daughter, Bimbo Gal Bitch, Resort Equipment, Husband And Wife, Flight Attendant, 3P Multiple play, Idol & Celebrity, Picking Up Girls, Childhood Friend, Cleaning Blowjob, Object Insertion, Outdoor Exposure, Exclusive Movies, Black Male Actor, Pantyhose Tights, Partner Exchange, Non nude Erotica, School Swimsuits, Female Detective, Miniskirt Police, College Students, Esthetic Massage, Game Live Action, Immediate Saddle, Electric Machine, Historical Drama, Tea Tea Ceremony, Fan Appreciation, Genital Close Up, The Magic Mirror, 16 Hours Or More, Thirty Years Old, Female Announcer, Cusco Exposition, overseas Imports, Girls Department, Drama Department, Adopted Daughter, High School Girl, Multiple Stories, Advertising Idol, Dmm Distribution, Mania Department, Consecutive Coat, Big Breast Fetish, Beautiful Breasts, Limited Time Sale, Electric Massager, Limitless Reality, Action & Fighting, Fetish Department, Super Heavyweight, Business Clothing, Brutal Expression, Female Main Angle, Yariman Gal Bitch, Intense Back Fuck, adoptive Daughter, Actress Department, Collaboration Work, Other Love Affairs, Soggy Masturbation, Ai Generated Works, Actress Collection, The Delusion Group, Collaborative Work, Works Over 4 Hours, Physical Education, Female Investigator, Various Professions, Medical Examination, Extreme Storm Surge, Planning Department, Ordinary Department, Hardcore Department, Manners And Customs, Various Occupations, Rejuvenation Massage, Transform To Heroine, Masturbation Support, High Definition (Hd), Ultra Slim Pixelated, Internal Ejaculation, Confession Experience, Miniskirt Policewoman, Club Activity Manager, Shame And Humiliation, Hospitals And Clinics, Hypnotic Brainwashing, Kiss Of Same Sex Love, Advertising Male Idol, Premature Ejaculation, Delivery Only Amateur, Female College Student, Subjective Perspective, 4 Hours Past Work Time, Beautiful Mature Woman, Exclusive Distribution, Transformation Heroine, Documentary Department, Original Collaboration, Drugs And Aphrodisiacs, Princess & Mademoiselle, Club Activities Manager, Mature Woman Department, Variety Show Department, Subordinate Or Colleague, Experimental Plain White, Av Open 2014 Heavyweight, Confession Of Experience, Foreign Object Insertion, Mistress Japanese Geisha, Shop Manager Recommended, Hostesses And Prostitutes, Getting Paid For Business, Club Hostess & Sex Worker, Foreign Object Penetration, Limited To Dmm Distribution, With Benefits (AV Baseball), Realistic Version Of The Game, More Than 16 Hours Of Footage, With Bonus Video Only For MGS, With Bonus Video Only For Mgs, Limited To Fanza Distribution, Rookie Debuts One After Another, Toys and foreign matter inserts, Administrator Recommended Works, Smartphone Recommendation Animation, Shortcut Girls Boyish But Amazing Sex, Orgy With Nori Crazy For Multiple Play, Prestige 20Th Anniversary Special Project, Vertical Video Recommended For Smartphones, Orgy With Glue Im Crazy For Multiple Plays, Comes With Bonus Footage Only Available At Mgs\",\"area\":\"Japan, China\",\"lang\":\"\",\"year\":\"\",\"star\":\"\",\"director\":\"\",\"state\":\"Release,Trailer,Full\",\"version\":\"HD,Full HD,CAM,TV\"}', '', '', '');
INSERT INTO `javcms_type` VALUES ('6', 'Western', 'western', '6', '1', '0', '0', 'type.html', 'show.html', 'detail.html', '', '', '', '', '', '', '{\"class\":\"3D, 3P, Bl, SM, OL, 4K, Hd, 69, Sf, Vr, Ol, Sm, POV, Toy, Gay, VIP, Gal, Ntr, BBW, Pee, Ass, Tan, NTR, 4HR, Drag, Teen, Back, Tall, Gulp, Post, Bath, SVIP, Kiss, 8KVR, 8Kvr, Plot, Tied, Love, Play, Geek, Plan, Coma, Fist, Slut, Slim, De M, Best, Idol, Peep, Ingo, Food, M jo, Lady, Long, Scat, Hame, Anal, Neat, Half, Mask, Butt, Anus, MILF, Maid, Orgy, Fear, Milk, Aunt, Mini, Do M, Ride, Yoga, Wife, Drug, Sexy, Pure, Puke, Eros, Vibe, Trip, Doll, Tool, Tits, Rape, Nampa, H Cup, 3P 4P, Gonzo, D Cup, Naive, Abuse, Boots, Semen, Missy, Bride, Myodo, Scold, Debut, G Cup, M Leg, M Man, Dance, I Cup, E Cup, Fifty, Other, Enema, Widow, Drama, Small, Chest, WLB B, Tutor, Model, Dildo, Vomit, Chaos, F Cup, Slave, Skirt, Sixty, Witch, Shota, Indie, Queen, Shave, Shame, Nurse, Devil, Blond, Cogal, Hotel, Apron, Kusco, Cruel, Honyo, J Cup, Yokui, Aokan, Bitch, Otaku, Harem, Junko, Rotor, Drunk, Stool, Hairy, Curls, Cusco, Beast, Fight, Nasty, visit, Kyonyu, Carsex, Series, Whites, Acting, Action, Selfie, Chijyo, People, Chikan, Sci Fi, Chubby, Juudai, Clinic, Office, Kimono, School, Peeing, Affair, Incest, Candle, Bikini, Dating, Muscle, Annual, Binyuu, Artist, Saddle, Digimo, Mother, Yukata, Indies, Epuron, Erotic, Blazer, Riding, Expose, Blonde, Facial, Insult, Masumi, Masked, Fetish, Record, Rankou, No Bra, Paipan, M Male, Couple, Friend, G Spot, Gansha, Lotion, Bronze, Lolita, Horror, How To, Kyoshi, Pranks, Shower, Sister, Sports, Suntan, Others, Sweaty, Portio, Thirty, Tickle, Travel, Uterus, Boyish, Virgin, clinic, Voyeur, Petite, Beauty, Frenzy, Clothes, Teacher, Project, Urinate, Nursing, Pissing, Decatin, Tattoos, Amateur, Student, Uniform, Bishiri, Handjob, Sunburn, Classic, Forties, Athlete, Torture, AV Idol, Neglect, Ryojoku, Bikyaku, Retreat, Pervert, Bathing, Freeter, Reprint, Bathtub, Tied Up, Blowjob, Bodycon, Bondage, Bontage, Braless, Sokkuri, Breasts, Outdoor, Tit Job, In Love, Slender, Bukkake, BUKKAKE, Miscaba, Kosupre, Car Sex, Similar, Gagging, Faction, Cumshot, Shemale, Shaving, Fantasy, Cosplay, Cowgirl, Meeting, Hotehel, Massage, Glasses, Hostess, Manguri, Customs, Fighter, Cuckold, Lesbian, Footjob, M Woman, Grandma, VR Only, Onasapo, Paizuri, Full Hd, Peeping, Quickie, Stepmom, leakage, Big Ass, Furnace, Variety, Actress, Av Open, Omnibus, Addition, V Cinema, Outdoors, Adultery, Advanced, M Female, Our Door, Anal Sex, Kunoichi, Cyoushin, Training, Six Nine, Ignoring, Hand Man, Original, Rice Man, Car Love, Classics, Eat Shit, Esthetic, Shotacon, Together, Daydream, Shokushu, Shipping, Ejection, Close Up, Tickling, Shiofuki, Tentacle, Lingerie, Princess, Pregnant, Cow Calf, Iramatio, Fat Girl, POV Shot, Deriheru, Ball Gag, Hypnosis, Creampie, Bareback, Hot Girl, Spanking, Pornstar, Hatsuura, Planning, Leotards, Delusion, Swimwear, Hospital, Feminine, Mischief, Swimsuit, Sweating, Fighting, Maltreat, Eatpussy, Daughter, Swapping, Muscular, Emmaniel, Homemade, Big Butt, Nice Ass, Big Cock, Gym Suit, Big Tits, Speculum, Exposure, Futanari, Waitress, Speaking, Oral Sex, Catwoman, Youthful, Soapland, Lecturer, Sorority, Hardcore, Nekomimi, Solowork, Solitary, Off Sale, Bloomers, TSUNDERE, Sex Toys, Big Dick, Foursome, Vomiting, Molester, Rigidity, Vibrator, Fainting, Emmanuel, Landlady, Gang Rape, Interview, Hot Water, Gyno Exam, Heaven Tv, Hi vision, Baby Face, Loli Type, Hostesses, Foreigner, Huge Tits, Sexy Legs, Nice Butt, Shout Out, Sixtynine, Nice Tits, Conceived, Companion, Soft Body, Solo Work, Squirting, Stockings, Exclusive, Nose Hook, Miniskirt, Sweet Ass, Tall Girl, Tall Lady, Tentacles, Time Stop, Tokusatsu, Expensive, Underwear, Urination, Childhood, Cheongsam, Secretary, Adventure, Scatology, Whitening, Animation, Ass Lover, Celebrity, Restraint, Reproduce, Proboscis, Porn Star, Big Asses, Pantyhose, Black Man, Boys Love, Blow Mono, Call Girl, Face Ride, Movement , Face Shot, Labyrinth, Fair Skin, Kokyoplay, Threesome, Miss Miss, Mousozoku, Fingering, For Women, Dedo Work, Long Hair, Lucky Bag, Gang bang, Housewife, Small Tits, Instructor, Short Hair, Stepfamily, Girl Guide, Brown Hair, Domination, Mini Skirt, Big Pennis, Stepmother, Debut Work, Lotion Oil, Piledriver, Pink Rotor, Look alike, Nyotaimori, Prostitute, Dark Style, Exhibition, Time Stops, Brush Down, Titty Fuck, Reluctance, High Heels, Restraints, Atmosphere, Ass Fetish, Oil Lotion, Emmanuelle, Schoolgirl, 50 Minutes, Cherry Boy, Virgin Man, Sex Friend, Impregnate, Defecation, Homosexual, White Skin, First Shot, Mini Small, Knee Socks, Leg Fetish, Young Wife, Mini Petit, Nasty Hard, Coprophagy, Masturbate, Hot Spring, God Of Sex, Mom Friend, Nice Booty, Bunny Girl, Panty Shot, Stewardess, Black Hair, Skirt Mono, Cum Eating, Laboratory, Collection, Race Queen, Mother Dog, Slim Waist, Sex Change, Dirty Talk, Sportswear, colleagues, Colleagues, Home Visit, Gag Comedy, Super Milk, No Panties, Staff Push, Young Lady, Shijutakin, Individual, Camouflage, Short Skirt, Documentary, Entertainer, Aphrodisiac, Transgender, Transsexual, Doggy Style, Cat Cosplay, Sailor Suit, Turn Number, China Dress, Unavoidable, Lori System, Anchorwoman, Adult Movie, Single Work, Butt Fetish, Acme Orgasm, Hit On Boys, Confinement, Cunnilingus, Set Product, Love Affair, Foot Fetish, Nipple Slip, Deep Throat, Bubble Bath, Cum on face, Compilation, Pretty Girl, Sneak Shots, Breast Milk, Cheerleader, Finger Fuck, Oral Firing, Big Breasts, Words Blame, Panty Shots, Black Actor, Paradise TV, Intercourse, Naked Apron, Prostitutes, Beauty Shop, Dark System, Female Boss, Love Comedy, Health Soap, Erotic Wear, Humiliation, Promiscuous, Promiscuity, Refreshment, Pretty Tits, Big Chicken, hard System, Grandfather, Loose Socks, Plane bread, Instant Sex, Gym Clothes, Hotel Owner, Health Care, Best Of All, Drink Urine, Tight Dress, Racing Girl, Proud Pussy, Medium Hair, Esthetician, Independent, Minamibuchi, Massage Oil, Office Lady, Dirty Words, Mini Series, Facesitting, Drink Saliva, Egg Vibrator, Elder Sister, Emulsion Oil, Erotic Photo, Face Sitting, Fair skinned, Farting Love, Female Ninja, Femdom Slave, Glabrousness, Glasses Girl, Hit On Girls, Hysteroscope, Imprisonment, Independents, Subjectivity, Kakuiro Role, Kounaihassha, Transsexuals, 40 Years Old, 50 Years Old, Age Old Wife, Best Omnibus, Big Vibrator, Shaved Bread, Shaved Pussy, Bubble Socks, Busty Fetish, Campus Story, Liquor Store, Love And Sex, Subordinates, Rigid System, Magical Girl, Many Stories, Martial Arts, feminization, Masturbation, Mature Woman, Incontinence, incontinence, Lesbian Kiss, Model System, Multi person, Multi Person, Limited Time, No Underwear, Leg Crossing, Daihatsu Hat, Nymphomaniac, Older Sister, Virgin Thing, Other Fetish, Other Finger, Over 4 Hours, Receptionist, VR Exclusive, Risky Mosaic, Ropes & Ties, School Girls, Western Pins, New Daughter, Surface Area, Mature Women, Surplus Work, Feminization, Transvestite, Cheerleaders, College Girl, Proprietress, Common Sense, Contribution, Cum In Mouth, Middleweight, Cum in mouth, Asian Actress, Chest Chiller, Western Goods, Other Student, Swallow Sperm, Fanza Limited, Stewardess Ca, Personal Work, Golden Shower, Over 16 Hours, Sex Education, Photo Gallery, Swimming Wear, Cheating Wife, Beautiful Cat, Baseball Fist, Large Breasts, Vibrating Egg, Female Doctor, Cross Dresser, School mizugi, Woman Warrior, Private Tutor, Human Monster, Naked Clothes, Old Playmates, Delivery only, Masochist Man, Shrine Maiden, Men And Women, Hermaphrodite, Delivery Only, Campaign Girl, Married Woman, Mother Friend, Small Breasts, E Cup Or More, Group Bukkake, Best Complete, Married Women, Swimming Race, Super Breasts, Man Squirting, Female Anchor, Chinese Dress, White Of Eyes, Same Sex Love, Embarrassment, Sm Department, Substance Use, Mother in law, Pregnant Woman, Various Worker, Other Fetishes, Newcomer Debut, Massage Parlor, Male Squirting, Pickup Amateur, Mizute Clothes, Drinking Urine, Administration, PICKUP Amateur, Beautiful Skin, Kireina onesan, Fishnet Tights, Drinking Party, Minimal Mosaic, Bleached Spots, Forced Blowjob, Female Soldier, Female Teacher, Sailor Uniform, Extreme Orgasm, Slim Pixelated, Cum Swallowing, Wife And Child, Neat And Clean, Shortcut Girls, Bus Tour Guide, Lewd Lewd Lady, Transformation, Big Tits Lover, Tall Glamorous, Digital Mosaic, School Teacher, Beautiful Legs, Beautiful Girl, School Uniform, Beautiful Tits, Beautiful Butt, Wearing Erotic, Female Warrior, Reverse Number, Original Works, Sexual Slavery, Beautiful Chest, Special Effects, Private Teacher, Psycho Thriller, Female Molester, Low Cost Movies, Forty Years Old, Reverse Pick Up, Fighting Action, Lesbian Kissing, Romantic Comedy, Anime Character, Foreign Imports, Lewd Nasty Lady, Foreign Actress, Academy Uniform, School Swimwear, FANZA Exclusive, 4 Hours Or More, Super VIP Movie, Thanks Offering, School Swimsuit, Science Fiction, Beautiful Pussy, Masculine Woman, Cute Little Boy, Business Attire, Beautiful Woman, High Definition, Ordinary Person, One Piece Dress, Various Workers, But Amazing Sex, High Quality Vr, Pantyhose Thing, Man is Daughter, Bimbo Gal Bitch, Resort Equipment, Husband And Wife, Flight Attendant, 3P Multiple play, Idol & Celebrity, Picking Up Girls, Childhood Friend, Cleaning Blowjob, Object Insertion, Outdoor Exposure, Exclusive Movies, Black Male Actor, Pantyhose Tights, Partner Exchange, Non nude Erotica, School Swimsuits, Female Detective, Miniskirt Police, College Students, Esthetic Massage, Game Live Action, Immediate Saddle, Electric Machine, Historical Drama, Tea Tea Ceremony, Fan Appreciation, Genital Close Up, The Magic Mirror, 16 Hours Or More, Thirty Years Old, Female Announcer, Cusco Exposition, overseas Imports, Girls Department, Drama Department, Adopted Daughter, High School Girl, Multiple Stories, Advertising Idol, Dmm Distribution, Mania Department, Consecutive Coat, Big Breast Fetish, Beautiful Breasts, Limited Time Sale, Electric Massager, Limitless Reality, Action & Fighting, Fetish Department, Super Heavyweight, Business Clothing, Brutal Expression, Female Main Angle, Yariman Gal Bitch, Intense Back Fuck, adoptive Daughter, Actress Department, Collaboration Work, Other Love Affairs, Soggy Masturbation, Ai Generated Works, Actress Collection, The Delusion Group, Collaborative Work, Works Over 4 Hours, Physical Education, Female Investigator, Various Professions, Medical Examination, Extreme Storm Surge, Planning Department, Ordinary Department, Hardcore Department, Manners And Customs, Various Occupations, Rejuvenation Massage, Transform To Heroine, Masturbation Support, High Definition (Hd), Ultra Slim Pixelated, Internal Ejaculation, Confession Experience, Miniskirt Policewoman, Club Activity Manager, Shame And Humiliation, Hospitals And Clinics, Hypnotic Brainwashing, Kiss Of Same Sex Love, Advertising Male Idol, Premature Ejaculation, Delivery Only Amateur, Female College Student, Subjective Perspective, 4 Hours Past Work Time, Beautiful Mature Woman, Exclusive Distribution, Transformation Heroine, Documentary Department, Original Collaboration, Drugs And Aphrodisiacs, Princess & Mademoiselle, Club Activities Manager, Mature Woman Department, Variety Show Department, Subordinate Or Colleague, Experimental Plain White, Av Open 2014 Heavyweight, Confession Of Experience, Foreign Object Insertion, Mistress Japanese Geisha, Shop Manager Recommended, Hostesses And Prostitutes, Getting Paid For Business, Club Hostess & Sex Worker, Foreign Object Penetration, Limited To Dmm Distribution, With Benefits (AV Baseball), Realistic Version Of The Game, More Than 16 Hours Of Footage, With Bonus Video Only For MGS, With Bonus Video Only For Mgs, Limited To Fanza Distribution, Rookie Debuts One After Another, Toys and foreign matter inserts, Administrator Recommended Works, Smartphone Recommendation Animation, Shortcut Girls Boyish But Amazing Sex, Orgy With Nori Crazy For Multiple Play, Prestige 20Th Anniversary Special Project, Vertical Video Recommended For Smartphones, Orgy With Glue Im Crazy For Multiple Plays, Comes With Bonus Footage Only Available At Mgs\",\"area\":\"Japan, China\",\"lang\":\"\",\"year\":\"\",\"star\":\"\",\"director\":\"\",\"state\":\"Release,Trailer,Full\",\"version\":\"HD,Full HD,CAM,TV\"}', '', '', '');
INSERT INTO `javcms_type` VALUES ('7', 'News', 'news', '7', '2', '0', '0', 'type.html', 'show.html', 'detail.html', '', '', '', '', '', '', '{\"class\":\"3D, 3P, Bl, SM, OL, 4K, Hd, 69, Sf, Vr, Ol, Sm, POV, Toy, Gay, VIP, Gal, Ntr, BBW, Pee, Ass, Tan, NTR, 4HR, Drag, Teen, Back, Tall, Gulp, Post, Bath, SVIP, Kiss, 8KVR, 8Kvr, Plot, Tied, Love, Play, Geek, Plan, Coma, Fist, Slut, Slim, De M, Best, Idol, Peep, Ingo, Food, M jo, Lady, Long, Scat, Hame, Anal, Neat, Half, Mask, Butt, Anus, MILF, Maid, Orgy, Fear, Milk, Aunt, Mini, Do M, Ride, Yoga, Wife, Drug, Sexy, Pure, Puke, Eros, Vibe, Trip, Doll, Tool, Tits, Rape, Nampa, H Cup, 3P 4P, Gonzo, D Cup, Naive, Abuse, Boots, Semen, Missy, Bride, Myodo, Scold, Debut, G Cup, M Leg, M Man, Dance, I Cup, E Cup, Fifty, Other, Enema, Widow, Drama, Small, Chest, WLB B, Tutor, Model, Dildo, Vomit, Chaos, F Cup, Slave, Skirt, Sixty, Witch, Shota, Indie, Queen, Shave, Shame, Nurse, Devil, Blond, Cogal, Hotel, Apron, Kusco, Cruel, Honyo, J Cup, Yokui, Aokan, Bitch, Otaku, Harem, Junko, Rotor, Drunk, Stool, Hairy, Curls, Cusco, Beast, Fight, Nasty, visit, Kyonyu, Carsex, Series, Whites, Acting, Action, Selfie, Chijyo, People, Chikan, Sci Fi, Chubby, Juudai, Clinic, Office, Kimono, School, Peeing, Affair, Incest, Candle, Bikini, Dating, Muscle, Annual, Binyuu, Artist, Saddle, Digimo, Mother, Yukata, Indies, Epuron, Erotic, Blazer, Riding, Expose, Blonde, Facial, Insult, Masumi, Masked, Fetish, Record, Rankou, No Bra, Paipan, M Male, Couple, Friend, G Spot, Gansha, Lotion, Bronze, Lolita, Horror, How To, Kyoshi, Pranks, Shower, Sister, Sports, Suntan, Others, Sweaty, Portio, Thirty, Tickle, Travel, Uterus, Boyish, Virgin, clinic, Voyeur, Petite, Beauty, Frenzy, Clothes, Teacher, Project, Urinate, Nursing, Pissing, Decatin, Tattoos, Amateur, Student, Uniform, Bishiri, Handjob, Sunburn, Classic, Forties, Athlete, Torture, AV Idol, Neglect, Ryojoku, Bikyaku, Retreat, Pervert, Bathing, Freeter, Reprint, Bathtub, Tied Up, Blowjob, Bodycon, Bondage, Bontage, Braless, Sokkuri, Breasts, Outdoor, Tit Job, In Love, Slender, Bukkake, BUKKAKE, Miscaba, Kosupre, Car Sex, Similar, Gagging, Faction, Cumshot, Shemale, Shaving, Fantasy, Cosplay, Cowgirl, Meeting, Hotehel, Massage, Glasses, Hostess, Manguri, Customs, Fighter, Cuckold, Lesbian, Footjob, M Woman, Grandma, VR Only, Onasapo, Paizuri, Full Hd, Peeping, Quickie, Stepmom, leakage, Big Ass, Furnace, Variety, Actress, Av Open, Omnibus, Addition, V Cinema, Outdoors, Adultery, Advanced, M Female, Our Door, Anal Sex, Kunoichi, Cyoushin, Training, Six Nine, Ignoring, Hand Man, Original, Rice Man, Car Love, Classics, Eat Shit, Esthetic, Shotacon, Together, Daydream, Shokushu, Shipping, Ejection, Close Up, Tickling, Shiofuki, Tentacle, Lingerie, Princess, Pregnant, Cow Calf, Iramatio, Fat Girl, POV Shot, Deriheru, Ball Gag, Hypnosis, Creampie, Bareback, Hot Girl, Spanking, Pornstar, Hatsuura, Planning, Leotards, Delusion, Swimwear, Hospital, Feminine, Mischief, Swimsuit, Sweating, Fighting, Maltreat, Eatpussy, Daughter, Swapping, Muscular, Emmaniel, Homemade, Big Butt, Nice Ass, Big Cock, Gym Suit, Big Tits, Speculum, Exposure, Futanari, Waitress, Speaking, Oral Sex, Catwoman, Youthful, Soapland, Lecturer, Sorority, Hardcore, Nekomimi, Solowork, Solitary, Off Sale, Bloomers, TSUNDERE, Sex Toys, Big Dick, Foursome, Vomiting, Molester, Rigidity, Vibrator, Fainting, Emmanuel, Landlady, Gang Rape, Interview, Hot Water, Gyno Exam, Heaven Tv, Hi vision, Baby Face, Loli Type, Hostesses, Foreigner, Huge Tits, Sexy Legs, Nice Butt, Shout Out, Sixtynine, Nice Tits, Conceived, Companion, Soft Body, Solo Work, Squirting, Stockings, Exclusive, Nose Hook, Miniskirt, Sweet Ass, Tall Girl, Tall Lady, Tentacles, Time Stop, Tokusatsu, Expensive, Underwear, Urination, Childhood, Cheongsam, Secretary, Adventure, Scatology, Whitening, Animation, Ass Lover, Celebrity, Restraint, Reproduce, Proboscis, Porn Star, Big Asses, Pantyhose, Black Man, Boys Love, Blow Mono, Call Girl, Face Ride, Movement , Face Shot, Labyrinth, Fair Skin, Kokyoplay, Threesome, Miss Miss, Mousozoku, Fingering, For Women, Dedo Work, Long Hair, Lucky Bag, Gang bang, Housewife, Small Tits, Instructor, Short Hair, Stepfamily, Girl Guide, Brown Hair, Domination, Mini Skirt, Big Pennis, Stepmother, Debut Work, Lotion Oil, Piledriver, Pink Rotor, Look alike, Nyotaimori, Prostitute, Dark Style, Exhibition, Time Stops, Brush Down, Titty Fuck, Reluctance, High Heels, Restraints, Atmosphere, Ass Fetish, Oil Lotion, Emmanuelle, Schoolgirl, 50 Minutes, Cherry Boy, Virgin Man, Sex Friend, Impregnate, Defecation, Homosexual, White Skin, First Shot, Mini Small, Knee Socks, Leg Fetish, Young Wife, Mini Petit, Nasty Hard, Coprophagy, Masturbate, Hot Spring, God Of Sex, Mom Friend, Nice Booty, Bunny Girl, Panty Shot, Stewardess, Black Hair, Skirt Mono, Cum Eating, Laboratory, Collection, Race Queen, Mother Dog, Slim Waist, Sex Change, Dirty Talk, Sportswear, colleagues, Colleagues, Home Visit, Gag Comedy, Super Milk, No Panties, Staff Push, Young Lady, Shijutakin, Individual, Camouflage, Short Skirt, Documentary, Entertainer, Aphrodisiac, Transgender, Transsexual, Doggy Style, Cat Cosplay, Sailor Suit, Turn Number, China Dress, Unavoidable, Lori System, Anchorwoman, Adult Movie, Single Work, Butt Fetish, Acme Orgasm, Hit On Boys, Confinement, Cunnilingus, Set Product, Love Affair, Foot Fetish, Nipple Slip, Deep Throat, Bubble Bath, Cum on face, Compilation, Pretty Girl, Sneak Shots, Breast Milk, Cheerleader, Finger Fuck, Oral Firing, Big Breasts, Words Blame, Panty Shots, Black Actor, Paradise TV, Intercourse, Naked Apron, Prostitutes, Beauty Shop, Dark System, Female Boss, Love Comedy, Health Soap, Erotic Wear, Humiliation, Promiscuous, Promiscuity, Refreshment, Pretty Tits, Big Chicken, hard System, Grandfather, Loose Socks, Plane bread, Instant Sex, Gym Clothes, Hotel Owner, Health Care, Best Of All, Drink Urine, Tight Dress, Racing Girl, Proud Pussy, Medium Hair, Esthetician, Independent, Minamibuchi, Massage Oil, Office Lady, Dirty Words, Mini Series, Facesitting, Drink Saliva, Egg Vibrator, Elder Sister, Emulsion Oil, Erotic Photo, Face Sitting, Fair skinned, Farting Love, Female Ninja, Femdom Slave, Glabrousness, Glasses Girl, Hit On Girls, Hysteroscope, Imprisonment, Independents, Subjectivity, Kakuiro Role, Kounaihassha, Transsexuals, 40 Years Old, 50 Years Old, Age Old Wife, Best Omnibus, Big Vibrator, Shaved Bread, Shaved Pussy, Bubble Socks, Busty Fetish, Campus Story, Liquor Store, Love And Sex, Subordinates, Rigid System, Magical Girl, Many Stories, Martial Arts, feminization, Masturbation, Mature Woman, Incontinence, incontinence, Lesbian Kiss, Model System, Multi person, Multi Person, Limited Time, No Underwear, Leg Crossing, Daihatsu Hat, Nymphomaniac, Older Sister, Virgin Thing, Other Fetish, Other Finger, Over 4 Hours, Receptionist, VR Exclusive, Risky Mosaic, Ropes & Ties, School Girls, Western Pins, New Daughter, Surface Area, Mature Women, Surplus Work, Feminization, Transvestite, Cheerleaders, College Girl, Proprietress, Common Sense, Contribution, Cum In Mouth, Middleweight, Cum in mouth, Asian Actress, Chest Chiller, Western Goods, Other Student, Swallow Sperm, Fanza Limited, Stewardess Ca, Personal Work, Golden Shower, Over 16 Hours, Sex Education, Photo Gallery, Swimming Wear, Cheating Wife, Beautiful Cat, Baseball Fist, Large Breasts, Vibrating Egg, Female Doctor, Cross Dresser, School mizugi, Woman Warrior, Private Tutor, Human Monster, Naked Clothes, Old Playmates, Delivery only, Masochist Man, Shrine Maiden, Men And Women, Hermaphrodite, Delivery Only, Campaign Girl, Married Woman, Mother Friend, Small Breasts, E Cup Or More, Group Bukkake, Best Complete, Married Women, Swimming Race, Super Breasts, Man Squirting, Female Anchor, Chinese Dress, White Of Eyes, Same Sex Love, Embarrassment, Sm Department, Substance Use, Mother in law, Pregnant Woman, Various Worker, Other Fetishes, Newcomer Debut, Massage Parlor, Male Squirting, Pickup Amateur, Mizute Clothes, Drinking Urine, Administration, PICKUP Amateur, Beautiful Skin, Kireina onesan, Fishnet Tights, Drinking Party, Minimal Mosaic, Bleached Spots, Forced Blowjob, Female Soldier, Female Teacher, Sailor Uniform, Extreme Orgasm, Slim Pixelated, Cum Swallowing, Wife And Child, Neat And Clean, Shortcut Girls, Bus Tour Guide, Lewd Lewd Lady, Transformation, Big Tits Lover, Tall Glamorous, Digital Mosaic, School Teacher, Beautiful Legs, Beautiful Girl, School Uniform, Beautiful Tits, Beautiful Butt, Wearing Erotic, Female Warrior, Reverse Number, Original Works, Sexual Slavery, Beautiful Chest, Special Effects, Private Teacher, Psycho Thriller, Female Molester, Low Cost Movies, Forty Years Old, Reverse Pick Up, Fighting Action, Lesbian Kissing, Romantic Comedy, Anime Character, Foreign Imports, Lewd Nasty Lady, Foreign Actress, Academy Uniform, School Swimwear, FANZA Exclusive, 4 Hours Or More, Super VIP Movie, Thanks Offering, School Swimsuit, Science Fiction, Beautiful Pussy, Masculine Woman, Cute Little Boy, Business Attire, Beautiful Woman, High Definition, Ordinary Person, One Piece Dress, Various Workers, But Amazing Sex, High Quality Vr, Pantyhose Thing, Man is Daughter, Bimbo Gal Bitch, Resort Equipment, Husband And Wife, Flight Attendant, 3P Multiple play, Idol & Celebrity, Picking Up Girls, Childhood Friend, Cleaning Blowjob, Object Insertion, Outdoor Exposure, Exclusive Movies, Black Male Actor, Pantyhose Tights, Partner Exchange, Non nude Erotica, School Swimsuits, Female Detective, Miniskirt Police, College Students, Esthetic Massage, Game Live Action, Immediate Saddle, Electric Machine, Historical Drama, Tea Tea Ceremony, Fan Appreciation, Genital Close Up, The Magic Mirror, 16 Hours Or More, Thirty Years Old, Female Announcer, Cusco Exposition, overseas Imports, Girls Department, Drama Department, Adopted Daughter, High School Girl, Multiple Stories, Advertising Idol, Dmm Distribution, Mania Department, Consecutive Coat, Big Breast Fetish, Beautiful Breasts, Limited Time Sale, Electric Massager, Limitless Reality, Action & Fighting, Fetish Department, Super Heavyweight, Business Clothing, Brutal Expression, Female Main Angle, Yariman Gal Bitch, Intense Back Fuck, adoptive Daughter, Actress Department, Collaboration Work, Other Love Affairs, Soggy Masturbation, Ai Generated Works, Actress Collection, The Delusion Group, Collaborative Work, Works Over 4 Hours, Physical Education, Female Investigator, Various Professions, Medical Examination, Extreme Storm Surge, Planning Department, Ordinary Department, Hardcore Department, Manners And Customs, Various Occupations, Rejuvenation Massage, Transform To Heroine, Masturbation Support, High Definition (Hd), Ultra Slim Pixelated, Internal Ejaculation, Confession Experience, Miniskirt Policewoman, Club Activity Manager, Shame And Humiliation, Hospitals And Clinics, Hypnotic Brainwashing, Kiss Of Same Sex Love, Advertising Male Idol, Premature Ejaculation, Delivery Only Amateur, Female College Student, Subjective Perspective, 4 Hours Past Work Time, Beautiful Mature Woman, Exclusive Distribution, Transformation Heroine, Documentary Department, Original Collaboration, Drugs And Aphrodisiacs, Princess & Mademoiselle, Club Activities Manager, Mature Woman Department, Variety Show Department, Subordinate Or Colleague, Experimental Plain White, Av Open 2014 Heavyweight, Confession Of Experience, Foreign Object Insertion, Mistress Japanese Geisha, Shop Manager Recommended, Hostesses And Prostitutes, Getting Paid For Business, Club Hostess & Sex Worker, Foreign Object Penetration, Limited To Dmm Distribution, With Benefits (AV Baseball), Realistic Version Of The Game, More Than 16 Hours Of Footage, With Bonus Video Only For MGS, With Bonus Video Only For Mgs, Limited To Fanza Distribution, Rookie Debuts One After Another, Toys and foreign matter inserts, Administrator Recommended Works, Smartphone Recommendation Animation, Shortcut Girls Boyish But Amazing Sex, Orgy With Nori Crazy For Multiple Play, Prestige 20Th Anniversary Special Project, Vertical Video Recommended For Smartphones, Orgy With Glue Im Crazy For Multiple Plays, Comes With Bonus Footage Only Available At Mgs\",\"area\":\"Japan, China\",\"lang\":\"\",\"year\":\"\",\"star\":\"\",\"director\":\"\",\"state\":\"Release,Trailer,Full\",\"version\":\"HD,Full HD,CAM,TV\"}', '', '', '');

INSERT INTO `javcms_type` (`type_id`, `type_name`, `type_en`, `type_sort`, `type_mid`, `type_pid`, `type_status`, `type_tpl`, `type_tpl_list`, `type_tpl_detail`, `type_tpl_play`, `type_tpl_down`, `type_extend`) VALUES
('8', 'Hentai', 'hentai', '8', '1', '0', '1', 'type.html', 'show.html', 'detail.html', 'play.html', 'down.html', ''),
('9', 'English subtitle', 'english-subtitle', '9', '1', '0', '1', 'type.html', 'show.html', 'detail.html', 'play.html', 'down.html', '');

-- -----------------------------
-- Table structure for `javcms_ulog`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_ulog`;
CREATE TABLE `javcms_ulog` (
  `ulog_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT '0',
  `ulog_mid` tinyint unsigned NOT NULL DEFAULT '0',
  `ulog_type` tinyint unsigned NOT NULL DEFAULT '1',
  `ulog_rid` int unsigned NOT NULL DEFAULT '0',
  `ulog_sid` tinyint unsigned NOT NULL DEFAULT '0',
  `ulog_nid` smallint unsigned NOT NULL DEFAULT '0',
  `ulog_points` smallint unsigned NOT NULL DEFAULT '0',
  `ulog_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`ulog_id`),
  KEY `user_id` (`user_id`),
  KEY `ulog_mid` (`ulog_mid`),
  KEY `ulog_type` (`ulog_type`),
  KEY `ulog_rid` (`ulog_rid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_user`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_user`;
CREATE TABLE `javcms_user` (
  `user_id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_id` smallint unsigned NOT NULL DEFAULT '0',
  `user_name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_pwd` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_nick_name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_qq` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_email` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_phone` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_status` tinyint unsigned NOT NULL DEFAULT '0',
  `user_portrait` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_portrait_thumb` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_openid_qq` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_openid_weixin` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_question` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_answer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_points` int unsigned NOT NULL DEFAULT '0',
  `user_points_froze` int unsigned NOT NULL DEFAULT '0',
  `user_reg_time` int unsigned NOT NULL DEFAULT '0',
  `user_reg_ip` int unsigned NOT NULL DEFAULT '0',
  `user_login_time` int unsigned NOT NULL DEFAULT '0',
  `user_login_ip` int unsigned NOT NULL DEFAULT '0',
  `user_last_login_time` int unsigned NOT NULL DEFAULT '0',
  `user_last_login_ip` int unsigned NOT NULL DEFAULT '0',
  `user_login_num` smallint unsigned NOT NULL DEFAULT '0',
  `user_extend` smallint unsigned NOT NULL DEFAULT '0',
  `user_random` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `user_end_time` int unsigned NOT NULL DEFAULT '0',
  `user_pid` int unsigned NOT NULL DEFAULT '0',
  `user_pid_2` int unsigned NOT NULL DEFAULT '0',
  `user_pid_3` int unsigned NOT NULL DEFAULT '0',
  `user_invite_code` varchar(20) NOT NULL DEFAULT '',
  `user_invite_count` int unsigned NOT NULL DEFAULT 0,
  `user_invite_reward_time` int unsigned NOT NULL DEFAULT 0,
  `user_invite_reward_level` int unsigned NOT NULL DEFAULT 0,
  KEY `idx_user_invite_code` (`user_invite_code`),
  PRIMARY KEY (`user_id`),
  KEY `type_id` (`group_id`) USING BTREE,
  KEY `user_name` (`user_name`),
  KEY `user_reg_time` (`user_reg_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_visit`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_visit`;
CREATE TABLE `javcms_visit` (
  `visit_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT '0',
  `visit_ip` int unsigned NOT NULL DEFAULT '0',
  `visit_ly` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `visit_time` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`visit_id`),
  KEY `user_id` (`user_id`),
  KEY `visit_time` (`visit_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_vod`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_vod`;
CREATE TABLE `javcms_vod` (
  `vod_id` int unsigned NOT NULL AUTO_INCREMENT,
  `type_id` smallint NOT NULL DEFAULT '0',
  `type_id_1` smallint unsigned NOT NULL DEFAULT '0',
  `group_id` smallint unsigned NOT NULL DEFAULT '0',
  `vod_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_sub` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_status` tinyint unsigned NOT NULL DEFAULT '0',
  `vod_letter` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_color` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_tag` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pic` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pic_thumb` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pic_slide` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pic_screenshot` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `vod_actor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_director` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_writer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_behind` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_blurb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_remarks` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pubdate` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_total` mediumint unsigned NOT NULL DEFAULT '0',
  `vod_serial` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '0',
  `vod_tv` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_weekday` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_area` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_lang` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `vod_year` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_version` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_state` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_author` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_jumpurl` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_tpl` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_tpl_play` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_tpl_down` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_isend` tinyint unsigned NOT NULL DEFAULT '0',
  `vod_lock` tinyint unsigned NOT NULL DEFAULT '0',
  `vod_level` tinyint unsigned NOT NULL DEFAULT '0',
  `vod_copyright` tinyint unsigned NOT NULL DEFAULT '0',
  `vod_points` smallint unsigned NOT NULL DEFAULT '0',
  `vod_points_play` smallint unsigned NOT NULL DEFAULT '0',
  `vod_points_down` smallint unsigned NOT NULL DEFAULT '0',
  `vod_hits` mediumint unsigned NOT NULL DEFAULT '0',
  `vod_hits_day` mediumint unsigned NOT NULL DEFAULT '0',
  `vod_hits_week` mediumint unsigned NOT NULL DEFAULT '0',
  `vod_hits_month` mediumint unsigned NOT NULL DEFAULT '0',
  `vod_duration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `vod_up` mediumint unsigned NOT NULL DEFAULT '0',
  `vod_down` mediumint unsigned NOT NULL DEFAULT '0',
  `vod_score` decimal(3,1) unsigned NOT NULL DEFAULT '0.0',
  `vod_score_all` mediumint unsigned NOT NULL DEFAULT '0',
  `vod_score_num` mediumint unsigned NOT NULL DEFAULT '0',
  `vod_time` int unsigned NOT NULL DEFAULT '0',
  `vod_time_add` int unsigned NOT NULL DEFAULT '0',
  `vod_time_hits` int unsigned NOT NULL DEFAULT '0',
  `vod_time_make` int unsigned NOT NULL DEFAULT '0',
  `vod_trysee` smallint unsigned NOT NULL DEFAULT '0',
  `vod_douban_id` int unsigned NOT NULL DEFAULT '0',
  `vod_douban_score` decimal(3,1) unsigned NOT NULL DEFAULT '0.0',
  `vod_reurl` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_rel_vod` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_rel_art` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pwd` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pwd_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pwd_play` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pwd_play_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pwd_down` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_pwd_down_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `vod_play_from` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_play_server` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_play_note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_play_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `vod_down_from` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_down_server` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_down_note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `vod_down_url` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `vod_plot` tinyint unsigned NOT NULL DEFAULT '0',
  `vod_plot_name` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `vod_plot_detail` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `vod_recycle_time` int unsigned NOT NULL DEFAULT 0,
  KEY `idx_vod_recycle_time_vod_time` (`vod_recycle_time`,`vod_time`),
  PRIMARY KEY (`vod_id`),
  KEY `type_id` (`type_id`) USING BTREE,
  KEY `type_id_1` (`type_id_1`) USING BTREE,
  KEY `vod_level` (`vod_level`) USING BTREE,
  KEY `vod_hits` (`vod_hits`) USING BTREE,
  KEY `vod_letter` (`vod_letter`) USING BTREE,
  KEY `vod_name` (`vod_name`(191)) USING BTREE,
  KEY `vod_year` (`vod_year`) USING BTREE,
  KEY `vod_area` (`vod_area`(191)) USING BTREE,
  KEY `vod_lang` (`vod_lang`) USING BTREE,
  KEY `vod_tag` (`vod_tag`(191)) USING BTREE,
  KEY `vod_class` (`vod_class`(191)) USING BTREE,
  KEY `vod_lock` (`vod_lock`) USING BTREE,
  KEY `vod_up` (`vod_up`) USING BTREE,
  KEY `vod_down` (`vod_down`) USING BTREE,
  KEY `vod_en` (`vod_en`(191)) USING BTREE,
  KEY `idx_vod_sub` (`vod_sub`(191)) USING BTREE,
  KEY `idx_vod_remarks` (`vod_remarks`) USING BTREE,
  KEY `vod_hits_day` (`vod_hits_day`) USING BTREE,
  KEY `vod_hits_week` (`vod_hits_week`) USING BTREE,
  KEY `vod_hits_month` (`vod_hits_month`) USING BTREE,
  KEY `vod_plot` (`vod_plot`) USING BTREE,
  KEY `vod_points_play` (`vod_points_play`) USING BTREE,
  KEY `vod_points_down` (`vod_points_down`) USING BTREE,
  KEY `group_id` (`group_id`) USING BTREE,
  KEY `vod_time_add` (`vod_time_add`) USING BTREE,
  KEY `vod_time` (`vod_time`) USING BTREE,
  KEY `vod_time_make` (`vod_time_make`) USING BTREE,
  KEY `vod_actor` (`vod_actor`(191)) USING BTREE,
  KEY `vod_director` (`vod_director`(191)) USING BTREE,
  KEY `vod_score_all` (`vod_score_all`) USING BTREE,
  KEY `vod_score_num` (`vod_score_num`) USING BTREE,
  KEY `vod_total` (`vod_total`) USING BTREE,
  KEY `vod_score` (`vod_score`) USING BTREE,
  KEY `vod_version` (`vod_version`),
  KEY `vod_state` (`vod_state`),
  KEY `vod_isend` (`vod_isend`),
  KEY `idx_type_list` (`type_id`,`vod_status`,`vod_recycle_time`,`vod_time`),
  KEY `idx_type1_list` (`type_id_1`,`vod_status`,`vod_recycle_time`,`vod_time`),
  KEY `idx_type_hits` (`type_id`,`vod_status`,`vod_recycle_time`,`vod_hits`),
  KEY `idx_type1_hits` (`type_id_1`,`vod_status`,`vod_recycle_time`,`vod_hits`),
  KEY `idx_type_up` (`type_id`,`vod_status`,`vod_recycle_time`,`vod_up`),
  KEY `idx_type1_up` (`type_id_1`,`vod_status`,`vod_recycle_time`,`vod_up`),
  FULLTEXT KEY `ft_vod_name` (`vod_name`),
  FULLTEXT KEY `ft_vod_actor` (`vod_actor`),
  FULLTEXT KEY `ft_vod_tag` (`vod_tag`),
  FULLTEXT KEY `ft_vod_class` (`vod_class`),
  FULLTEXT KEY `ft_vod_director` (`vod_director`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------
-- Table structure for `javcms_vod_ft_vocab` (words of the FULLTEXT filter columns, FilterMatch v2 / FilterVocab;
-- token uses the collation of the vod filter columns)
-- -----------------------------
DROP TABLE IF EXISTS `javcms_vod_ft_vocab`;
CREATE TABLE `javcms_vod_ft_vocab` (
  `col` tinyint unsigned NOT NULL,
  `token` varchar(84) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `flags` tinyint unsigned NOT NULL DEFAULT 0,
  `seen` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`col`,`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='FULLTEXT filter vocabulary (FilterVocab)';


-- -----------------------------
-- Table structure for `javcms_vod_search`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_vod_search`;
CREATE TABLE `javcms_vod_search` (
  `search_key` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Search key (keyword md5)',
  `search_word` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Search keyword',
  `search_field` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Search field name (can have multiple, separated by |)',
  `search_hit_count` bigint unsigned NOT NULL DEFAULT '0' COMMENT 'Search hit count',
  `search_last_hit_time` int unsigned NOT NULL DEFAULT '0' COMMENT 'Last hit time',
  `search_update_time` int unsigned NOT NULL DEFAULT '0' COMMENT 'Add time',
  `search_result_count` int unsigned NOT NULL DEFAULT '0' COMMENT 'Number of result IDs',
  `search_result_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Search result ID list, separated by commas',
  PRIMARY KEY (`search_key`),
  KEY `search_field` (`search_field`),
  KEY `search_update_time` (`search_update_time`),
  KEY `search_hit_count` (`search_hit_count`),
  KEY `search_last_hit_time` (`search_last_hit_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='vod search cache table';


-- -----------------------------
-- Table structure for `javcms_website`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_website`;
CREATE TABLE `javcms_website` (
  `website_id` int unsigned NOT NULL AUTO_INCREMENT,
  `type_id` smallint unsigned NOT NULL DEFAULT '0',
  `type_id_1` smallint unsigned NOT NULL DEFAULT '0',
  `website_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_sub` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_status` tinyint unsigned NOT NULL DEFAULT '0',
  `website_letter` char(1) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_color` varchar(6) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_lock` tinyint unsigned NOT NULL DEFAULT '0',
  `website_sort` int NOT NULL DEFAULT '0',
  `website_jumpurl` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_pic` varchar(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_pic_screenshot` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `website_logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_area` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_lang` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_level` tinyint unsigned NOT NULL DEFAULT '0',
  `website_time` int unsigned NOT NULL DEFAULT '0',
  `website_time_add` int unsigned NOT NULL DEFAULT '0',
  `website_time_hits` int unsigned NOT NULL DEFAULT '0',
  `website_time_make` int unsigned NOT NULL DEFAULT '0',
  `website_time_referer` int unsigned NOT NULL DEFAULT '0',
  `website_hits` mediumint unsigned NOT NULL DEFAULT '0',
  `website_hits_day` mediumint unsigned NOT NULL DEFAULT '0',
  `website_hits_week` mediumint unsigned NOT NULL DEFAULT '0',
  `website_hits_month` mediumint unsigned NOT NULL DEFAULT '0',
  `website_score` decimal(3,1) unsigned NOT NULL DEFAULT '0.0',
  `website_score_all` mediumint unsigned NOT NULL DEFAULT '0',
  `website_score_num` mediumint unsigned NOT NULL DEFAULT '0',
  `website_up` mediumint unsigned NOT NULL DEFAULT '0',
  `website_down` mediumint unsigned NOT NULL DEFAULT '0',
  `website_referer` mediumint unsigned NOT NULL DEFAULT '0',
  `website_referer_day` mediumint unsigned NOT NULL DEFAULT '0',
  `website_referer_week` mediumint unsigned NOT NULL DEFAULT '0',
  `website_referer_month` mediumint unsigned NOT NULL DEFAULT '0',
  `website_tag` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_class` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_remarks` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_tpl` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_blurb` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `website_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`website_id`),
  KEY `type_id` (`type_id`),
  KEY `type_id_1` (`type_id_1`),
  KEY `website_name` (`website_name`),
  KEY `website_en` (`website_en`(191)),
  KEY `website_letter` (`website_letter`),
  KEY `website_sort` (`website_sort`),
  KEY `website_lock` (`website_lock`),
  KEY `website_time` (`website_time`),
  KEY `website_time_add` (`website_time_add`),
  KEY `website_time_referer` (`website_time_referer`),
  KEY `website_hits` (`website_hits`),
  KEY `website_hits_day` (`website_hits_day`),
  KEY `website_hits_week` (`website_hits_week`),
  KEY `website_hits_month` (`website_hits_month`),
  KEY `website_time_make` (`website_time_make`),
  KEY `website_score` (`website_score`),
  KEY `website_score_all` (`website_score_all`),
  KEY `website_score_num` (`website_score_num`),
  KEY `website_up` (`website_up`),
  KEY `website_down` (`website_down`),
  KEY `website_level` (`website_level`),
  KEY `website_tag` (`website_tag`),
  KEY `website_class` (`website_class`(191)),
  KEY `website_referer` (`website_referer`),
  KEY `website_referer_day` (`website_referer_day`),
  KEY `website_referer_week` (`website_referer_week`),
  KEY `website_referer_month` (`website_referer_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- -----------------------------
-- Table structure for `javcms_admin`
-- -----------------------------
DROP TABLE IF EXISTS `javcms_admin`;
CREATE TABLE `javcms_admin` (
  `admin_id` smallint unsigned NOT NULL AUTO_INCREMENT,
  `admin_name` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `admin_pwd` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `admin_random` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `admin_status` tinyint unsigned NOT NULL DEFAULT '1',
  `admin_auth` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `admin_login_time` int unsigned NOT NULL DEFAULT '0',
  `admin_login_ip` int unsigned NOT NULL DEFAULT '0',
  `admin_login_num` int unsigned NOT NULL DEFAULT '0',
  `admin_last_login_time` int unsigned NOT NULL DEFAULT '0',
  `admin_last_login_ip` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`admin_id`),
  KEY `admin_name` (`admin_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------
-- Records of `javcms_admin`
-- -----------------------------

-- Optional AVDB import identity, jobs and presentation translations.
CREATE TABLE IF NOT EXISTS `javcms_avdb_import_job` (
 `job_id` char(32) NOT NULL, `admin_id` int unsigned NOT NULL,
 `options` longtext NOT NULL, `state` varchar(20) NOT NULL, `phase` varchar(20) NOT NULL,
 `page` int unsigned NOT NULL DEFAULT 1, `cursor` int unsigned NOT NULL DEFAULT 0,
 `discovery_done` tinyint unsigned NOT NULL DEFAULT 0, `lock_token` char(32) NOT NULL DEFAULT '',
 `lock_until` int unsigned NOT NULL DEFAULT 0, `error` text NOT NULL,
 `retry_at` int unsigned NOT NULL DEFAULT 0, `created_at` int unsigned NOT NULL, `updated_at` int unsigned NOT NULL,
 PRIMARY KEY (`job_id`), KEY `owner_jobs` (`admin_id`,`created_at`)
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
 PRIMARY KEY (`provider`,`remote_id`), KEY `local_video` (`vod_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `javcms_avdb_translation` (
 `vod_id` int unsigned NOT NULL, `locale` varchar(10) NOT NULL, `payload` longtext NOT NULL,
 `updated_at` int unsigned NOT NULL, PRIMARY KEY (`vod_id`,`locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- Ads manager (ADS-BRIEF 3.2): the same 5 tables as application/install/sql/jcx.sql
CREATE TABLE IF NOT EXISTS `javcms_jcx_item` (
  `item_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_name` varchar(80) NOT NULL DEFAULT '',
  `item_unit` char(3) NOT NULL DEFAULT 'U01',
  `item_kind` varchar(10) NOT NULL DEFAULT 'image',
  `item_place` varchar(6) NOT NULL DEFAULT '',
  `item_status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `item_example` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `item_ex_code` varchar(4) NOT NULL DEFAULT '',
  `item_preset` varchar(24) NOT NULL DEFAULT '',
  `item_network` varchar(24) NOT NULL DEFAULT '',
  `item_group` varchar(64) NOT NULL DEFAULT '',
  `item_weight` smallint(5) unsigned NOT NULL DEFAULT 10,
  `item_dev` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `item_pages` varchar(120) NOT NULL DEFAULT '',
  `item_cats` varchar(255) NOT NULL DEFAULT '',
  `item_langs` varchar(120) NOT NULL DEFAULT '',
  `item_geo` varchar(255) NOT NULL DEFAULT '',
  `item_who` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `item_start` int(10) unsigned NOT NULL DEFAULT 0,
  `item_end` int(10) unsigned NOT NULL DEFAULT 0,
  `item_days` tinyint(3) unsigned NOT NULL DEFAULT 127,
  `item_hours` varchar(11) NOT NULL DEFAULT '',
  `item_cap_n` smallint(5) unsigned NOT NULL DEFAULT 0,
  `item_cap_h` smallint(5) unsigned NOT NULL DEFAULT 0,
  `item_cap_gap` smallint(5) unsigned NOT NULL DEFAULT 0,
  `item_skip_first` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `item_delay` smallint(5) unsigned NOT NULL DEFAULT 0,
  `item_w` smallint(5) unsigned NOT NULL DEFAULT 0,
  `item_h` smallint(5) unsigned NOT NULL DEFAULT 0,
  `item_wm` smallint(5) unsigned NOT NULL DEFAULT 0,
  `item_hm` smallint(5) unsigned NOT NULL DEFAULT 0,
  `item_media` text NULL,
  `item_url` varchar(500) NOT NULL DEFAULT '',
  `item_alt` varchar(160) NOT NULL DEFAULT '',
  `item_title` varchar(120) NOT NULL DEFAULT '',
  `item_text` varchar(255) NOT NULL DEFAULT '',
  `item_brand` varchar(60) NOT NULL DEFAULT '',
  `item_cta` varchar(30) NOT NULL DEFAULT '',
  `item_code` mediumtext NULL,
  `item_code_m` mediumtext NULL,
  `item_code_mode` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `item_opts` text NULL,
  `item_risk_ok` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `item_note` varchar(255) NOT NULL DEFAULT '',
  `item_sort` int(10) NOT NULL DEFAULT 0,
  `item_add` int(10) unsigned NOT NULL DEFAULT 0,
  `item_edit` int(10) unsigned NOT NULL DEFAULT 0,
  `item_admin` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`item_id`),
  KEY `status_place` (`item_status`,`item_place`),
  KEY `example` (`item_example`,`item_ex_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `javcms_jcx_place` (
  `pl_code` varchar(6) NOT NULL,
  `pl_status` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `pl_every` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `pl_max` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `pl_opts` text NULL,
  `pl_edit` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`pl_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `javcms_jcx_anchor` (
  `anc_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `anc_theme` varchar(40) NOT NULL DEFAULT '',
  `anc_place` varchar(6) NOT NULL DEFAULT '',
  `anc_at` varchar(200) NOT NULL DEFAULT '',
  `anc_pos` varchar(12) NOT NULL DEFAULT 'afterend',
  `anc_nth` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `anc_every` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `anc_max` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `anc_status` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `anc_edit` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`anc_id`),
  UNIQUE KEY `theme_place` (`anc_theme`,`anc_place`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `javcms_jcx_stat` (
  `st_day` int(10) unsigned NOT NULL,
  `st_item` int(10) unsigned NOT NULL,
  `st_ev` tinyint(3) unsigned NOT NULL,
  `st_n` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`st_day`,`st_item`,`st_ev`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `javcms_jcx_root` (
  `rf_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rf_kind` varchar(8) NOT NULL DEFAULT '',
  `rf_name` varchar(64) NOT NULL DEFAULT '',
  `rf_body` mediumtext NULL,
  `rf_sha` char(64) NOT NULL DEFAULT '',
  `rf_status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `rf_edit` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`rf_id`),
  UNIQUE KEY `name` (`rf_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
