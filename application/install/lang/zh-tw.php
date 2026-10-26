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
// Installer-only strings (XVID-SCALE-BRIEF 3.8 step 5), Traditional Chinese. English (en-us.php) is loaded first as fallback.
return [
    'admin/ux/install/catalog_legend' => '您的影片',
    'admin/ux/install/catalog_q' => '您要匯入哪些片庫？',
    'admin/ux/install/catalog_avdb' => 'AVDB（日本）',
    'admin/ux/install/catalog_avdb_tip' => '日本影片，標題有 16 種語言。將開啟日本分類。',
    'admin/ux/install/catalog_xvid' => 'xvidapi（國際）',
    'admin/ux/install/catalog_xvid_tip' => '國際影片，標題保留原始語言。將關閉日本分類，類型直接顯示在選單中。',
    'admin/ux/install/catalog_both' => '兩者都要',
    'admin/ux/install/catalog_both_tip' => '日本分類，另加一個包含國際類型的選單。',
    'admin/ux/install/catalog_later' => '以後再決定',
    'admin/ux/install/catalog_later_tip' => '與舊版本一樣開啟日本分類。以後可以從任一來源匯入。',
    'admin/ux/install/catalog_change' => '以後可以變更：在「分類」中開啟或關閉分類，在「匯入影片」中從任一來源匯入。',
    'admin/ux/install/parent_name' => '類型選單',
    'admin/ux/install/parent_name_tip' => '包含 xvidapi 類型的選單名稱。以後可以在「分類」中重新命名。',
    'admin/ux/install/parent_international' => '國際（International）',
    'admin/ux/install/parent_western' => '歐美（Western）',
    // 1.2.0 (CONTRACT HO-H-R3-4): the sitemap line of the last step
    'admin/ux/install/sitemap_built' => '網站地圖檔案：已產生 {built} 個（其中語言檔案 {lang} 個），{fresh} 個已存在，{left} 個留給維護工作。',
    'admin/ux/install/sitemap_index' => '網站地圖：索引已儲存；匯入影片後會產生影片檔案。',
    'admin/ux/install/sitemap_later' => '網站地圖檔案：留給維護工作產生（{why}）。',
    'admin/ux/install/sitemap_stopped' => '產生因錯誤中止；維護工作會產生其餘部分。',
    'admin/ux/install/sitemap_why_no_cli' => '找不到 PHP 命令列',
    'admin/ux/install/sitemap_why_disabled' => 'PHP 已停用 proc_open',
    'admin/ux/install/sitemap_why_timeout' => '產生耗時過長',
    'admin/ux/install/sitemap_why_busy' => '另一個網站地圖產生工作正在執行',
    'admin/ux/install/sitemap_why_hidden' => '網站地圖已在 SEO 設定中關閉',
    'admin/ux/install/sitemap_why_error' => '產生因錯誤中止',
];
