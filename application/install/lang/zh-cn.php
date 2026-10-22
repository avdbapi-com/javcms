<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
// Installer-only strings (XVID-SCALE-BRIEF 3.8 step 5), Simplified Chinese. English (en-us.php) is loaded first as fallback.
return [
    'admin/ux/install/catalog_legend' => '您的视频',
    'admin/ux/install/catalog_q' => '您要导入哪些片库？',
    'admin/ux/install/catalog_avdb' => 'AVDB（日本）',
    'admin/ux/install/catalog_avdb_tip' => '日本视频，标题有 16 种语言。将开启日本分类。',
    'admin/ux/install/catalog_xvid' => 'xvidapi（国际）',
    'admin/ux/install/catalog_xvid_tip' => '国际视频，标题保留原始语言。将关闭日本分类，类型直接显示在菜单中。',
    'admin/ux/install/catalog_both' => '两者都要',
    'admin/ux/install/catalog_both_tip' => '日本分类，另加一个包含国际类型的菜单。',
    'admin/ux/install/catalog_later' => '以后再决定',
    'admin/ux/install/catalog_later_tip' => '与旧版本一样开启日本分类。以后可以从任一来源导入。',
    'admin/ux/install/catalog_change' => '以后可以更改：在“分类”中开启或关闭分类，在“导入视频”中从任一来源导入。',
    'admin/ux/install/parent_name' => '类型菜单',
    'admin/ux/install/parent_name_tip' => '包含 xvidapi 类型的菜单名称。以后可以在“分类”中重命名。',
    'admin/ux/install/parent_international' => '国际（International）',
    'admin/ux/install/parent_western' => '欧美（Western）',
    // 1.2.0 (CONTRACT HO-H-R3-4): the sitemap line of the last step
    'admin/ux/install/sitemap_built' => '站点地图文件：已生成 {built} 个（其中语言文件 {lang} 个），{fresh} 个已存在，{left} 个留给维护任务。',
    'admin/ux/install/sitemap_index' => '站点地图：索引已保存；导入视频后会生成视频文件。',
    'admin/ux/install/sitemap_later' => '站点地图文件：留给维护任务生成（{why}）。',
    'admin/ux/install/sitemap_stopped' => '生成因错误中止；维护任务会生成其余部分。',
    'admin/ux/install/sitemap_why_no_cli' => '未找到 PHP 命令行',
    'admin/ux/install/sitemap_why_disabled' => 'PHP 中已禁用 proc_open',
    'admin/ux/install/sitemap_why_timeout' => '生成耗时过长',
    'admin/ux/install/sitemap_why_busy' => '另一个站点地图生成任务正在运行',
    'admin/ux/install/sitemap_why_hidden' => '站点地图已在 SEO 设置中关闭',
    'admin/ux/install/sitemap_why_error' => '生成因错误中止',
];
