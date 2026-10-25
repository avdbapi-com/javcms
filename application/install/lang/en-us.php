<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
// Installer-only strings (XVID-SCALE-BRIEF 3.8 step 5). Loaded by application/install/controller/Index.php after
// application/lang/<lang>.php; English is loaded first, so a missing key in another language falls back to English.
return [
    'admin/ux/install/catalog_legend' => 'Your videos',
    'admin/ux/install/catalog_q' => 'Which catalogs will you import?',
    'admin/ux/install/catalog_avdb' => 'AVDB (Japanese)',
    'admin/ux/install/catalog_avdb_tip' => 'Japanese videos with titles in 16 languages. The Japanese categories are turned on.',
    'admin/ux/install/catalog_xvid' => 'xvidapi (international)',
    'admin/ux/install/catalog_xvid_tip' => 'International videos with titles in their original language. The Japanese categories are turned off and the genres appear in the menu.',
    'admin/ux/install/catalog_both' => 'Both',
    'admin/ux/install/catalog_both_tip' => 'The Japanese categories plus one menu that holds the international genres.',
    'admin/ux/install/catalog_later' => 'Decide later',
    'admin/ux/install/catalog_later_tip' => 'The Japanese categories are turned on, as in earlier versions. You can import from either source later.',
    'admin/ux/install/catalog_change' => 'You can change this later: Categories turns categories on and off, and Import videos imports from either source.',
    'admin/ux/install/parent_name' => 'Genre menu',
    'admin/ux/install/parent_name_tip' => 'Name of the menu that holds the xvidapi genres. You can rename it later in Categories.',
    'admin/ux/install/parent_international' => 'International',
    'admin/ux/install/parent_western' => 'Western',
    // 1.2.0 (CONTRACT HO-H-R3-4): the sitemap line of the last step
    'admin/ux/install/sitemap_built' => 'Sitemap files: {built} built ({lang} language files), {fresh} already stored, {left} left for housekeeping.',
    'admin/ux/install/sitemap_index' => 'Sitemap: the index is stored; the video files follow as you import videos.',
    'admin/ux/install/sitemap_later' => 'Sitemap files: left to housekeeping ({why}).',
    'admin/ux/install/sitemap_stopped' => 'The build stopped with an error; housekeeping builds the rest.',
    'admin/ux/install/sitemap_why_no_cli' => 'no PHP command line found',
    'admin/ux/install/sitemap_why_disabled' => 'proc_open is turned off in PHP',
    'admin/ux/install/sitemap_why_timeout' => 'the build took too long',
    'admin/ux/install/sitemap_why_busy' => 'another sitemap build is running',
    'admin/ux/install/sitemap_why_hidden' => 'the sitemap is turned off in the SEO settings',
    'admin/ux/install/sitemap_why_error' => 'the build stopped with an error',
];
