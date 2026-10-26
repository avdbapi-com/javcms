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
// Installer-only strings (XVID-SCALE-BRIEF 3.8 step 5), Vietnamese. English (en-us.php) is loaded first as fallback.
return [
    'admin/ux/install/catalog_legend' => 'Video của bạn',
    'admin/ux/install/catalog_q' => 'Bạn sẽ nhập video từ nguồn nào?',
    'admin/ux/install/catalog_avdb' => 'AVDB (Nhật Bản)',
    'admin/ux/install/catalog_avdb_tip' => 'Video Nhật Bản, tiêu đề có 16 ngôn ngữ. Các chuyên mục Nhật Bản được bật.',
    'admin/ux/install/catalog_xvid' => 'xvidapi (quốc tế)',
    'admin/ux/install/catalog_xvid_tip' => 'Video quốc tế, tiêu đề giữ nguyên ngôn ngữ gốc. Các chuyên mục Nhật Bản được tắt và các thể loại hiện ngay trên menu.',
    'admin/ux/install/catalog_both' => 'Cả hai',
    'admin/ux/install/catalog_both_tip' => 'Các chuyên mục Nhật Bản cùng một menu chứa các thể loại quốc tế.',
    'admin/ux/install/catalog_later' => 'Quyết định sau',
    'admin/ux/install/catalog_later_tip' => 'Các chuyên mục Nhật Bản được bật như các phiên bản trước. Bạn có thể nhập từ nguồn nào cũng được về sau.',
    'admin/ux/install/catalog_change' => 'Bạn có thể đổi lại sau: trang Chuyên mục bật hoặc tắt chuyên mục, trang Nhập video nhập từ cả hai nguồn.',
    'admin/ux/install/parent_name' => 'Menu thể loại',
    'admin/ux/install/parent_name_tip' => 'Tên menu chứa các thể loại của xvidapi. Bạn có thể đổi tên sau trong trang Chuyên mục.',
    'admin/ux/install/parent_international' => 'Quốc tế (International)',
    'admin/ux/install/parent_western' => 'Phương Tây (Western)',
    // 1.2.0 (CONTRACT HO-H-R3-4): the sitemap line of the last step
    'admin/ux/install/sitemap_built' => 'Tệp sitemap: đã tạo {built} ({lang} tệp ngôn ngữ), {fresh} đã có sẵn, {left} để tác vụ bảo trì tạo tiếp.',
    'admin/ux/install/sitemap_index' => 'Sitemap: đã lưu tệp chỉ mục; các tệp video sẽ có khi bạn nhập video.',
    'admin/ux/install/sitemap_later' => 'Tệp sitemap: để tác vụ bảo trì tạo sau ({why}).',
    'admin/ux/install/sitemap_stopped' => 'Quá trình tạo dừng vì lỗi; tác vụ bảo trì sẽ tạo phần còn lại.',
    'admin/ux/install/sitemap_why_no_cli' => 'không tìm thấy PHP dòng lệnh',
    'admin/ux/install/sitemap_why_disabled' => 'proc_open đang bị tắt trong PHP',
    'admin/ux/install/sitemap_why_timeout' => 'quá trình tạo mất quá nhiều thời gian',
    'admin/ux/install/sitemap_why_busy' => 'một lần tạo sitemap khác đang chạy',
    'admin/ux/install/sitemap_why_hidden' => 'sitemap đang tắt trong phần cài đặt SEO',
    'admin/ux/install/sitemap_why_error' => 'quá trình tạo dừng vì lỗi',
];
