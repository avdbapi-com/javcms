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
