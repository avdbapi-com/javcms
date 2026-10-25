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
namespace app\common\util;

/**
 * One import provider (XVID-SCALE-BRIEF D-P1, interface exactly as XVID-IMPORTER 2.1).
 * The job engine branches on capabilities(), never on key(). Implementations: AvdbProvider, XvidClient.
 *
 * Every method that returns provider rows returns the validated answer (D-P7): rows are flat arrays of scalars,
 * remote ids 1..2^31-1, at most the requested ids / page size (extra rows ignored). normalize() turns one provider
 * row into the canonical vod_* row that AvdbImport::sanitizeRow() takes; it throws \RuntimeException for a row that
 * must be refused (for example an embed URL outside the allowlist).
 */
interface ImportProvider
{
    /** 'avdb' | 'xvid' (<= 20 chars; the value of avdb_import_job.provider and avdb_import_map.provider). */
    public function key();

    /** Short label for the admin ("AVDB", "xvidapi"). */
    public function label();

    /**
     * Capability data (XVID-IMPORTER 2.2 + embed_path, D-P2): stable_cursor, ids_max, details_in_list, hours_filter,
     * translations (locale list), category_names_i18n, suggestions, tag_filter, jav_codes, embed_hosts, image_hosts,
     * min_interval_ms ['bulk','interactive'], category_parent, embed_path.
     */
    public function capabilities();

    /** [remote_type_id => name] in $locale (one cheap list call). */
    public function categories($locale = 'en');

    /** Provider total for an estimate (pagesize 1). $filters: provider filter keys (t, h). */
    public function count(array $filters);

    /** One bounded discovery / preview page: ['list'=>rows,'total'=>int,'page'=>int,'pagecount'=>int,'limit'=>int]. */
    public function page(array $filters, $pg);

    /** Detail rows for <= capabilities()['ids_max'] remote ids: ['list'=>rows, 'missing'=>int[]]. */
    public function byIds(array $ids);

    /**
     * Stable bulk discovery. $cursor = the next id to read (walking down; 0 = start at $upper), $upper = the fixed upper id
     * of the run. Returns ['list'=>rows, 'next_cursor'=>int, 'exhausted'=>bool, 'upper_id'=>int].
     */
    public function cursorPage($cursor, $upper);

    /** Incremental sync page: rows changed in the last $hours (canonical 3 / 24 / 168), page $pg. */
    public function recent($hours, $pg);

    /** Remote ids (subset of $ids) that still exist at the provider (presence sweep). */
    public function present(array $ids);

    /** [remote_id => bundle]; [] when capabilities()['translations'] is empty. */
    public function translations(array $ids, array $locales);

    /** Provider row -> canonical vod_* row (sanitizeRow input). Throws \RuntimeException when the row is refused. */
    public function normalize(array $row);
}
