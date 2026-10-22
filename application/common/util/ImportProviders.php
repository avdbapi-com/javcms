<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

/**
 * Registry of import providers (XVID-SCALE-BRIEF D-P4) and the size / time estimator (brief 2.2, contract C7).
 *
 * The registry is a static list; unknown keys are refused, so no controller ever builds a provider or a URL from
 * input. The estimator constants live here in one place (lane E prints them); estimate() is pure: the controller
 * measures the inputs (database size, limit, binlog, free disk, heartbeat, own import rate) and passes them in.
 */
final class ImportProviders
{
    const PROVIDERS = ['avdb' => AvdbProvider::class, 'xvid' => XvidClient::class];

    // ---- estimator constants (brief 2.2). Sizes in KB per video, 1 MB = 1024 KB (C7 interpretation I8).
    const KB_XVID_LEAN_SLIM = 1.0;   // lean policy, slim index set (LAB 3)
    const KB_XVID_LEAN = 1.5;        // lean policy, today's 37 indexes
    const KB_XVID_FULL = 2.0;        // full row, 37 indexes
    const KB_XVID_PK_ONLY = 0.77;    // lean row without secondary indexes (LAB 3): secondary part = row - this
    const KB_AVDB = 3.1;             // AVDB row (live)
    const KB_AVDB_TRANSLATIONS = 18.0; // per video when all 16 translations are kept
    const KB_SEARCH_EN = 0.6;        // search document, English
    const KB_SEARCH_EN_Z = 0.31;     // compressed
    const KB_SEARCH_ALL = 5.9;       // AVDB with 16 languages
    const KB_SEARCH_ALL_Z = 3.0;     // compressed
    const KB_MAP = 0.12;             // map row
    const KB_JOURNAL = 0.3;          // item row kept per video in web (non-bulk) jobs
    const GROWTH = 1.3;              // random-insert growth on secondary indexes until lane V measures G14/G15
    const KB_IMAGE_XVID = 12.7;      // local image copy (opt-in), poster = thumb, one file
    const KB_IMAGE_AVDB = 107.4;     // AVDB poster 78.6 KB + thumb 28.8 KB (XVID-API 7)
    const APPLY_S = 0.008;           // 8 ms per new item in CLI bulk mode (G16) = 125 items/s
    const SHARED_FACTOR = 4;         // x1-x4 on shared hosting: the estimate shows x4
    const OWN_RATE_AFTER = 10000;    // the site's own rolling rate replaces APPLY_S after the first 10 k items
    const BINLOG_FACTOR = 1.5;       // builds before import-r2: binlog = 1.5 x lean data (no longer used by estimate(); kept for callers)
    // Round-2 verify fix import-r2 (binlog estimate), measured (fix/P/r2/ev/binlog10k-mix.json, MariaDB 10.3, binlog_format ROW,
    // binlog_row_image FULL, row annotations on, the mock's test rows of 877 bytes of provider JSON): 7.23 KB of binary log per
    // imported video (V import-r2 N1 on MariaDB 11.4: about 7.0 KB; the estimate said 1.5 x lean data, ~2.9 GB per million):
    // statement text of the row annotations 3.09 KB (MariaDB writes it by default; MySQL 8.0 does not), row images 4.14 KB (item
    // insert 1.03, item update 1.22, table maps 0.68, video insert 0.46, job checkpoints 0.40, map 0.26, item delete 0.16).
    // Bigger provider rows grow the row images by 3 x the extra JSON (item insert, item update's before image, video insert) and
    // the statement text by 2 x (item insert, video insert): real-sized rows (2.28 KB of JSON, fix/P/r2/ev/binlogpad-mix.json)
    // measured 14.15 KB with ROW + annotations (model 14.33), 8.39 KB with ROW only (MySQL 8.0 default; model 8.40) and 6.54 KB
    // with MIXED (MariaDB default; the statement text + ~0.6 KB of query events). Real rows: xvid 2,307 bytes, AVDB 2,176 bytes of
    // JSON (20 real rows each, the JavCMS build tools).
    const KB_BINLOG = 7.23;              // ROW + annotations, test rows
    const KB_BINLOG_ANNOTATE = 3.09;     // of it: the statements' text (Annotate_rows)
    const KB_BINLOG_STATEMENT = 3.7;     // statement / mixed format, test rows (measured 6.54 KB with real-sized rows: 3.7 + 2 x 1.42)
    const KB_BINLOG_TEST_ROW = 0.86;     // provider JSON of the test rows
    const KB_ROW_JSON_XVID = 2.25;
    const KB_ROW_JSON_AVDB = 2.13;
    const BINLOG_ROW_GROWTH = 3.0;
    const BINLOG_TEXT_GROWTH = 2.0;
    const KB_RANK = 0.084;           // list rank files (B2): 7 sorts x 3 levels x 4 B per video (XVID-NOLIMIT-BRIEF)
    const BULK_MIN = 50000;          // above this many videos a job runs in bulk mode (web and CLI); cron is recommended
    const PAGE_OFFSET_MAX = 100000;  // recent scopes page while the offset stays < 100 k; above: id windows
    const FULL_KEEP_FILTERED = 0.765; // full xvid catalog after the xvid filters, when they are ticked (6,596,643 -> about 5.05 M)
    const FIT_RATIO = 0.8;           // storage target = 0.8 x the database limit (A2); fits = db_mb_after <= target (informational)
    const DISK_RESERVE_MB = 2048;    // free disk kept besides the import peak (A2 reserve)
    const CONFIRM_VIDEOS = 1000000;  // A1: the start asks confirm=1 above this many videos or above half of the space left
    const TICK_STEP_S = 8;           // A3: tab closed = one 8 s web step per 30 s while the site has visitors
    const TICK_EVERY_S = 30;
    const BINLOG_WARN_VIDEOS = 500000;
    const SCOPES = ['recent_10000' => 10000, 'recent_50000' => 50000, 'recent_100000' => 100000, 'recent_1000000' => 1000000];

    private static $instances = [];

    /** @return string[] */
    public static function keys()
    {
        return array_keys(self::PROVIDERS);
    }

    public static function isKey($key)
    {
        return is_string($key) && isset(self::PROVIDERS[$key]);
    }

    /** Provider by key; unknown keys throw (never a URL or class name from input). */
    public static function get($key)
    {
        if (!self::isKey($key)) { throw new \InvalidArgumentException('Unknown import provider.'); }
        if (!isset(self::$instances[$key])) { $class = self::PROVIDERS[$key]; self::$instances[$key] = new $class(); }
        return self::$instances[$key];
    }

    /** A fresh provider with a fixture transport / client (tests, mock provider). */
    public static function make($key, $transportOrClient = null, array $options = [])
    {
        if (!self::isKey($key)) { throw new \InvalidArgumentException('Unknown import provider.'); }
        if ($key === 'avdb') { return new AvdbProvider($transportOrClient instanceof AvdbClient ? $transportOrClient : ($transportOrClient !== null ? new AvdbClient($transportOrClient) : null)); }
        return new XvidClient($transportOrClient, $options);
    }

    /** @return ImportProvider[] keyed by provider key */
    public static function all()
    {
        $out = [];
        foreach (self::keys() as $key) { $out[$key] = self::get($key); }
        return $out;
    }

    /** C7 `providers` list: [{key,label,capabilities,newest_remote_time}]. */
    public static function describe(?array $stateRows = null)
    {
        if ($stateRows === null) { $stateRows = ProviderPacer::rows(); }
        $out = [];
        foreach (self::all() as $key => $p) {
            $out[] = ['key' => $key, 'label' => $p->label(), 'capabilities' => $p->capabilities(), 'newest_remote_time' => (int)($stateRows[$key]['newest_remote_time'] ?? 0)];
        }
        return $out;
    }

    // ------------------------------------------------------------------ estimator (C7 `estimate`)

    /**
     * Pure estimate. Inputs (all optional except provider and scope):
     *   provider 'xvid'|'avdb'; scope recent_10000|recent_50000|recent_100000|recent_1000000|recent|category|full|selected;
     *   videos (category / selected count), videos_unknown (categories whose total is measured at the first page),
     *   remote_total, upper_id, filters (bool; default: off for the whole catalog, decision O1, on for the other xvid
     *   scopes), slim (bool), translations (AVDB keeps 16 languages, bool), search_all_langs (bool), local_videos,
     *   search_mb_now, db_mb_now, db_limit_mb (null = none), binlog_on, binlog_expire_days, free_disk_mb, tmp_free_mb,
     *   cli_seen_at, rate_items_s (site's own rate), items_done (items the site imported so far), images (bool),
     *   background (bool: fastcgi_finish_request / litespeed_finish_request exist, A3), snapshot {shards, bytes,
     *   feed_pages} (xvidexport manifest), keyset (bool: the keyset export API answers), now, php_binaries [], tools_path,
     *   lang_note, db_limit_asked (bool: the database-limit question was answered).
     * Output: the C7 estimate object (XVID-NOLIMIT-BRIEF C19: no confirm_phrase, needs_cli renamed cli_recommended)
     *   + scope, remote_total, db_limit_asked, db_growth_mb, stop_at_videos (the job ends there at the storage target;
     *   = videos when everything fits), stop_reason ('' | storage_target | disk), target_db_mb, source (snapshot |
     *   keyset | windows | export | pages), shards, download_mb, confirm_needed, background, import_minutes_closed,
     *   import_minutes_cron, scaled_to_stop (false: sizes and times are for the whole scope), warnings [{code,text}].
     *   Nothing here refuses a start: a scope larger than the space stops cleanly at the storage target (A2).
     */
    public static function estimate(array $in)
    {
        $provider = (string)($in['provider'] ?? '');
        if (!self::isKey($provider)) { throw new \InvalidArgumentException('Unknown import provider.'); }
        $scope = (string)($in['scope'] ?? '');
        $now = (int)($in['now'] ?? time());
        $xvid = $provider === 'xvid';
        $caps = self::get($provider)->capabilities();
        $remoteTotal = max(0, (int)($in['remote_total'] ?? ($xvid ? 6596643 : 0)));
        // O1: the xvid filters (skip < 60 s, skip "Unknown") are off for the whole catalog unless the admin ticks them
        $filters = array_key_exists('filters', $in) ? !empty($in['filters']) : $scope !== 'full';
        $cliSeen = max(0, (int)($in['cli_seen_at'] ?? 0));
        $hasCli = $cliSeen > 0 && $now - $cliSeen <= 86400;

        // ---- videos and provider requests
        $rowsPerPage = 1000; $idsPerWindow = $caps['ids_max'] >= 1000 ? 1000 : $caps['ids_max'];
        $source = 'pages';
        if (isset(self::SCOPES[$scope]) || $scope === 'recent') {
            // 'recent' = "newest N" with N in `videos` (IMP 11 --scope=recent --limit=N, E's own number)
            $videos = $scope === 'recent' ? max(1, min(2147483647, (int)($in['videos'] ?? 0))) : self::SCOPES[$scope];
            if ($remoteTotal > 0) { $videos = min($videos, $remoteTotal); }
            if ($videos <= self::PAGE_OFFSET_MAX || !$xvid) { $requests = (int)ceil($videos / $rowsPerPage); }
            else { $requests = (int)ceil($videos / $idsPerWindow); $source = 'windows'; }
        } elseif ($scope === 'full') {
            $videos = $xvid && $filters ? (int)round($remoteTotal * self::FULL_KEEP_FILTERED) : $remoteTotal;
            $upper = max(0, (int)($in['upper_id'] ?? 0));
            if ($xvid) {
                if (!empty($in['keyset'])) { $requests = 1 + (int)ceil($remoteTotal / 1000); $source = 'keyset'; }
                else { $requests = (int)ceil(($upper > 0 ? $upper : $remoteTotal) / $idsPerWindow); $source = 'windows'; }
            } else { $requests = (int)ceil($remoteTotal / 1000); $source = 'export'; }
        } elseif ($scope === 'category') {
            // every video of the chosen categories (no web cap); a category never counted is measured at its first page
            $videos = max(0, (int)($in['videos'] ?? 0));
            $requests = (int)ceil($videos / $rowsPerPage) + max(0, (int)($in['videos_unknown'] ?? 0));
        } elseif ($scope === 'selected') {
            $videos = max(0, (int)($in['videos'] ?? 0));
            $requests = (int)ceil($videos / $caps['ids_max']);
        } else {
            throw new \InvalidArgumentException('Unknown import scope.');
        }
        $translations = !$xvid && !empty($in['translations']);
        if ($translations) { $requests += (int)ceil($videos / 500); }

        // ---- sizes (MB)
        $allLangs = !$xvid && !empty($in['search_all_langs']);
        $searchKb = $allLangs ? self::KB_SEARCH_ALL_Z : self::KB_SEARCH_EN_Z;
        if ($xvid) {
            $rowLean = !empty($in['slim']) ? self::KB_XVID_LEAN_SLIM : self::KB_XVID_LEAN;
            $rowFull = self::KB_XVID_FULL;
        } else {
            $rowLean = self::KB_AVDB + ($translations ? self::KB_AVDB_TRANSLATIONS : 0);
            $rowFull = $rowLean;
        }
        // secondary-index part of a row (the part that grows with random inserts): xvid lean row minus its PK-only size;
        // AVDB rows use the same 37-index set, so the xvid secondary part of today's indexes is used for them too
        $secondaryKb = $xvid ? max(0.0, $rowLean - self::KB_XVID_PK_ONLY) : self::KB_XVID_LEAN - self::KB_XVID_PK_ONLY;
        $leanMb = $videos * ($rowLean + $searchKb + self::KB_MAP + self::KB_RANK) / 1024;
        $fullMb = $videos * ($rowFull + $searchKb + self::KB_MAP + self::KB_RANK) / 1024;
        $growthMb = $videos * $secondaryKb * (self::GROWTH - 1) / 1024;
        // bulk jobs (web and CLI) delete their item rows after commit; smaller jobs keep a journal row per video
        $bulk = $videos > self::BULK_MIN;
        $journalMb = $bulk ? 0.0 : $videos * self::KB_JOURNAL / 1024;
        $dbNow = max(0, (int)($in['db_mb_now'] ?? 0));
        $after = $dbNow + $leanMb + $growthMb + $journalMb;
        $localVideos = max(0, (int)($in['local_videos'] ?? 0));
        $searchNow = isset($in['search_mb_now']) ? max(0.0, (float)$in['search_mb_now']) : $localVideos * self::KB_SEARCH_EN_Z / 1024;
        $searchNew = $videos * $searchKb / 1024;
        $searchAfter = $searchNow + $searchNew;
        $binlogOn = !empty($in['binlog_on']);
        $binlogFormat = isset($in['binlog_format']) && is_string($in['binlog_format']) ? strtoupper($in['binlog_format']) : null;
        $binlogAnnotate = array_key_exists('binlog_annotate', $in) && $in['binlog_annotate'] !== null ? (bool)$in['binlog_annotate'] : null;
        $binlogKb = self::binlogKbPerVideo($xvid ? self::KB_ROW_JSON_XVID : self::KB_ROW_JSON_AVDB, $binlogFormat, $binlogAnnotate, $translations ? self::KB_AVDB_TRANSLATIONS : 0.0);

        // ---- time (minutes)
        $intervalS = $caps['min_interval_ms']['bulk'] / 1000;
        $providerMin = $requests * $intervalS / 60;
        // xvidexport snapshot (contract section 4): 1 manifest + one static file per shard + changes feed pages; the
        // provider serves files (no database time), at about 5 MB/s with >= 1 s between shards
        $downloadMb = 0.0; $shards = 0;
        if ($scope === 'full' && $xvid && !empty($in['snapshot']['shards'])) {
            $shards = max(1, (int)$in['snapshot']['shards']); $bytes = max(0, (int)($in['snapshot']['bytes'] ?? 0));
            $requests = 1 + $shards + max(1, (int)($in['snapshot']['feed_pages'] ?? 1)) + 1;
            $providerMin = ($shards * 1.0 + $bytes / 5242880) / 60;
            $source = 'snapshot'; $downloadMb = $bytes / 1048576;
        }
        $ownRate = (float)($in['rate_items_s'] ?? 0);
        $rate = ($ownRate > 0 && (int)($in['items_done'] ?? self::OWN_RATE_AFTER) >= self::OWN_RATE_AFTER) ? $ownRate : 1 / self::APPLY_S;
        $hereMin = max($providerMin, $videos / $rate / 60);
        $sharedMin = max($providerMin, $videos * self::APPLY_S * self::SHARED_FACTOR / 60);
        $imagesMb = !empty($in['images']) ? $videos * ($xvid ? self::KB_IMAGE_XVID : self::KB_IMAGE_AVDB) / 1024 : 0.0;
        // A3: tab closed = one TICK_STEP_S step per TICK_EVERY_S while the site has visitors; cron = 4 of 5 minutes
        $closedMin = $hereMin * self::TICK_EVERY_S / self::TICK_STEP_S;
        $cronMin = $hereMin * 5 / 4;
        // import-r2: binary logs of the import (measured KB per video); an import longer than the expiry keeps only the logs of
        // the last expire_days days on disk
        $expireDays = max(0, (int)($in['binlog_expire_days'] ?? 0));
        $binlogMb = $binlogOn ? $videos * $binlogKb / 1024 * self::binlogShare($expireDays, $hereMin) : 0.0;
        // bulk jobs end with an append or a rebuild of the search table: worst case one copy + one sort of the whole table
        $peak = $after + $binlogMb + ($bulk ? 2 * $searchAfter : $searchNew);

        // ---- storage target (A2): the only stop; the newest rows come first, so the stop keeps the largest newest set
        $limit = array_key_exists('db_limit_mb', $in) && $in['db_limit_mb'] !== null ? max(1, (int)$in['db_limit_mb']) : null;
        $free = isset($in['free_disk_mb']) ? (int)$in['free_disk_mb'] : null;
        $target = $limit !== null ? self::FIT_RATIO * $limit : null;
        $fits = !($target !== null && $after > $target) && !($free !== null && $peak > $free);
        $stop = $videos; $stopReason = '';
        if ($videos > 0) {
            $perDb = ($after - $dbNow) / $videos;
            $perPeak = ($peak - $dbNow) / $videos;
            if ($target !== null && $perDb > 0 && $after > $target) { $n = (int)max(0, floor(($target - $dbNow) / $perDb)); if ($n < $stop) { $stop = $n; $stopReason = 'storage_target'; } }
            if ($free !== null && $perPeak > 0 && $peak - $dbNow > $free - self::DISK_RESERVE_MB) { $n = (int)max(0, floor(($free - self::DISK_RESERVE_MB) / $perPeak)); if ($n < $stop) { $stop = $n; $stopReason = 'disk'; } }
        }
        // A1 misclick guard (not a limit): confirm=1 when the part that will run is > 1 M videos or > half of the space left
        $left = $target !== null ? max(0.0, $target - $dbNow) : ($free !== null ? max(0.0, (float)$free - self::DISK_RESERVE_MB) : null);
        $growUsed = $videos > 0 ? ($after - $dbNow) * $stop / $videos : 0.0;
        $confirmNeeded = $stop > self::CONFIRM_VIDEOS || ($left !== null && $growUsed > 0.5 * $left);
        $warnings = [];
        if ($binlogOn && $videos > self::BINLOG_WARN_VIDEOS) {
            $warnings[] = ['code' => 'binlog', 'text' => self::text('est_binlog', 'Binary logs will use about {gb} GB for {days} days: set binlog_expire_logs_seconds=86400 or skip-log-bin (single server, no replication).', ['gb' => self::gb($binlogMb), 'days' => $expireDays ?: 30])];
        }
        if ($scope === 'full' && $free !== null && $peak > $free) {
            $warnings[] = ['code' => 'peak_disk', 'text' => self::text('est_peak_disk', 'The full catalog needs about {peak} GB of disk at its peak; this server has {free} GB free.', ['peak' => self::gb($peak), 'free' => self::gb($free)])];
        }
        if ($bulk && !$hasCli) {
            $warnings[] = ['code' => 'cli_recommended', 'text' => self::text('est_needs_cli', 'Large import: cron is faster, but this page imports everything too.')];
        }
        $php = array_values(array_filter((array)($in['php_binaries'] ?? self::phpBinaries()), 'is_string'));
        $tools = (string)($in['tools_path'] ?? self::toolsPath());
        return [
            'scope' => $scope,
            'remote_total' => $remoteTotal,
            'db_limit_asked' => !empty($in['db_limit_asked']),
            'videos' => (int)$videos,
            'videos_unknown' => max(0, (int)($in['videos_unknown'] ?? 0)),
            'filters' => $xvid ? $filters : false,
            'db_mb_lean' => (int)round($leanMb),
            'db_mb_full' => (int)round($fullMb),
            'db_mb_now' => $dbNow,
            'db_limit_mb' => $limit,
            'db_mb_after' => (int)round($after),
            'db_growth_mb' => (int)round($growthMb),
            'peak_mb' => (int)round($peak),
            'binlog' => ['on' => $binlogOn, 'expire_days' => $binlogOn ? $expireDays : 0, 'est_mb' => (int)round($binlogMb), 'kb_per_video' => $binlogOn ? $binlogKb : 0, 'format' => $binlogOn ? $binlogFormat : null],
            'free_disk_mb' => $free,
            'tmp_free_mb' => isset($in['tmp_free_mb']) ? (int)$in['tmp_free_mb'] : null,
            'requests' => (int)$requests,
            'source' => $source,
            'shards' => $shards,
            'download_mb' => (int)round($downloadMb),
            'provider_minutes' => round($providerMin, 1),
            'import_minutes_here' => round($hereMin, 1),
            'import_minutes_closed' => round($closedMin, 1),
            'import_minutes_cron' => round($cronMin, 1),
            'import_minutes_shared' => round($sharedMin, 1),
            'rate_items_s' => round($rate, 1),
            'images_mb' => (int)round($imagesMb),
            'fits' => $fits,
            'stop_at_videos' => (int)$stop,
            'stop_reason' => $stopReason,
            'target_db_mb' => $target !== null ? (int)floor($target) : null,
            'scaled_to_stop' => false,
            'suggested_scope' => '',
            'confirm_needed' => $confirmNeeded,
            'cli_recommended' => $bulk,
            'cli_seen_at' => $cliSeen,
            'background' => array_key_exists('background', $in) ? (bool)$in['background'] : true,
            'default_scope' => 'full',
            'cron_line' => '*/5 * * * * ' . ($php[0] ?? 'php') . ' ' . $tools . ' --run --max-minutes=4',
            'php_binaries' => $php,
            'lang_note' => $xvid ? (string)($in['lang_note'] ?? self::text('lang_note_xvid', 'Titles: original language only (mostly English). Visitors using other languages see these titles untranslated and find them with English words.')) : '',
            'slim_offer' => $xvid && $videos >= 1000000,
            'warnings' => $warnings,
        ];
    }

    /**
     * import-r2 (pure): KB of binary log per imported video for provider rows of $rowJsonKb KB of JSON. $format ROW (or unknown:
     * the largest case) = row images + the statement text when $annotate is true or unknown (MariaDB's binlog_annotate_row_events,
     * MySQL's binlog_rows_query_log_events); MIXED / STATEMENT = the statement text. $extraKb: rows the import writes besides the
     * video (AVDB translations: once as row images, once as statement text). Measured base: 7.23 KB (ROW + annotations).
     */
    public static function binlogKbPerVideo($rowJsonKb, $format = null, $annotate = null, $extraKb = 0.0)
    {
        $d = max(0.0, (float)$rowJsonKb - self::KB_BINLOG_TEST_ROW); $x = max(0.0, (float)$extraKb);
        $text = self::KB_BINLOG_ANNOTATE + self::BINLOG_TEXT_GROWTH * $d + $x;
        $f = strtoupper((string)$format);
        if ($f === 'MIXED' || $f === 'STATEMENT') { return round(self::KB_BINLOG_STATEMENT + self::BINLOG_TEXT_GROWTH * $d + $x, 3); }
        $rows = self::KB_BINLOG - self::KB_BINLOG_ANNOTATE + self::BINLOG_ROW_GROWTH * $d + $x;
        return round($rows + ($annotate === false ? 0.0 : $text), 3);
    }

    /**
     * import-r2 (pure): share of an import's binary logs on disk at its end: all of them while the import is shorter than the
     * expiry ($expireDays, 0 = unknown / never purged), else the last $expireDays days of an import of $importMinutes.
     */
    public static function binlogShare($expireDays, $importMinutes)
    {
        $keep = max(0, (int)$expireDays) * 1440;
        return ($keep > 0 && (float)$importMinutes > $keep) ? $keep / (float)$importMinutes : 1.0;
    }

    /**
     * Admin text through the lang key admin/ux/import/<name> (lane E adds the keys in 4 languages, HO-P-2); the English
     * source text is the fallback while a key is missing and in tests. Placeholders {name} are replaced after the lookup.
     */
    public static function text($name, $english, array $vars = [])
    {
        $key = 'admin/ux/import/' . $name;
        $s = $english;
        if (function_exists('lang')) {
            try { $t = lang($key); if (is_string($t) && $t !== '' && $t !== $key) { $s = $t; } } catch (\Throwable $e) { /* framework not booted */ }
        }
        foreach ($vars as $k => $v) { $s = str_replace('{' . $k . '}', (string)$v, $s); }
        return $s;
    }

    /**
     * 2.4 per-site list of extra image hosts the admin allowed ("The provider uses a new image host <host>: allow it?"):
     * maccms.php `import.image_hosts_extra` = 'xvid:img.example.com,avdb:cdn.example.org' (HP-P-1, default '').
     * Returns the hosts of $provider.
     */
    public static function extraImageHosts($provider)
    {
        $cfg = function_exists('config') ? config('maccms.import') : null;
        $raw = is_array($cfg) && is_scalar($cfg['image_hosts_extra'] ?? null) ? (string)$cfg['image_hosts_extra'] : '';
        $out = [];
        foreach (explode(',', $raw) as $pair) {
            $p = explode(':', trim($pair), 2);
            if (count($p) === 2 && $p[0] === $provider && self::validHost($p[1])) { $out[] = strtolower($p[1]); }
        }
        return array_values(array_unique($out));
    }

    /** Add $host to the per-site list of $provider (admin action through the importer); returns the provider's list. */
    public static function allowImageHost($provider, $host)
    {
        if (!self::isKey($provider) || !self::validHost($host)) { throw new \InvalidArgumentException('Invalid image host.'); }
        $config = config('maccms');
        if (!is_array($config)) { throw new \RuntimeException('Site settings are not readable.'); }
        $config['import'] = is_array($config['import'] ?? null) ? $config['import'] : [];
        $pairs = array_filter(array_map('trim', explode(',', (string)($config['import']['image_hosts_extra'] ?? ''))));
        $pairs[] = $provider . ':' . strtolower($host);
        $config['import']['image_hosts_extra'] = implode(',', array_values(array_unique($pairs)));
        if (!function_exists('mac_arr2file') || mac_arr2file(APP_PATH . 'extra/maccms.php', $config) === false) { throw new \RuntimeException(self::text('limit_not_saved', 'Could not save the setting (application/extra/maccms.php is not writable).')); }
        if (function_exists('opcache_invalidate')) { @opcache_invalidate(APP_PATH . 'extra/maccms.php', true); }
        \think\Config::set('maccms', $config);
        return self::extraImageHosts($provider);
    }

    private static function validHost($host)
    {
        return is_string($host) && preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', strtolower($host));
    }

    /** The PHP binary of this server plus the common panel paths (D-I1). */
    public static function phpBinaries()
    {
        $own = PHP_BINDIR . (DIRECTORY_SEPARATOR === '\\' ? '\\php.exe' : '/php');
        return array_values(array_unique([$own, '/usr/local/bin/ea-php74', '/opt/alt/php74/usr/bin/php']));
    }

    public static function toolsPath()
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : (defined('APP_PATH') ? dirname(APP_PATH) . DIRECTORY_SEPARATOR : '');
        return $root . 'tools' . DIRECTORY_SEPARATOR . 'import.php';
    }

    private static function gb($mb)
    {
        return number_format($mb / 1024, $mb >= 10240 ? 0 : 1, '.', ',');
    }
}
