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
 * xvidapi.com import provider (XVID-SCALE-BRIEF D-P1..D-P7, D-I2, D-I3, D-I7, D-I8, field rules 2.4).
 *
 * Calls only fixed URLs on https://xvidapi.com: provide1/vod (flat vod_* rows) for rows and totals, provide/vod
 * `ac=list` for the category names per locale. Nothing here takes a URL, host or path from admin input.
 * Every request goes through the optional ProviderPacer (one politeness clock per provider shared by web, callback
 * and CLI). normalize() applies the 2.4 field rules before AvdbImport::sanitizeRow().
 */
class XvidClient implements ImportProvider
{
    const KEY = 'xvid';
    const ROWS_URL = 'https://xvidapi.com/api.php/provide1/vod/';
    const LIST_URL = 'https://xvidapi.com/api.php/provide/vod/';
    const IDS_MAX = 500;
    const PAGE_ROWS = 1000;
    /** Galloping probe width (ids per probe) and the bound rule of D-I2: max(2 x reported total, 20,000,000). */
    const PROBE_IDS = 100;
    const PROBE_FLOOR = 20000000;
    const MAX_REMOTE_ID = 2147483647;
    /** Canonical incremental windows (D-I7): every install asks identical URLs. */
    const HOURS = [3, 24, 168];
    const LOCALES = ['en', 'vi', 'th', 'id', 'ko', 'zh', 'ja', 'fr', 'de', 'es', 'pt', 'ru', 'ar', 'tr', 'hi', 'ms', 'tl'];
    const PLACEHOLDERS = ['updating', 'unknown', 'n/a', '-', 'na', 'none', 'null'];
    const CLASS_NEVER = ['xvidapi', 'unknown'];
    const QUALITY = ['4K', '1080P', '720P', 'SD'];
    /** ISO 639-1 -> the language name stored in vod_lang (only for detector status high/medium). */
    const LANG_NAMES = [
        'en' => 'English', 'pt' => 'Portuguese', 'es' => 'Spanish', 'ja' => 'Japanese', 'ru' => 'Russian', 'fr' => 'French',
        'de' => 'German', 'it' => 'Italian', 'hi' => 'Hindi', 'zh' => 'Chinese', 'th' => 'Thai', 'vi' => 'Vietnamese',
        'ar' => 'Arabic', 'tl' => 'Filipino', 'ko' => 'Korean', 'id' => 'Indonesian', 'ms' => 'Malay', 'tr' => 'Turkish',
        'nl' => 'Dutch', 'pl' => 'Polish', 'cs' => 'Czech', 'ro' => 'Romanian', 'hu' => 'Hungarian', 'el' => 'Greek',
        'sv' => 'Swedish', 'uk' => 'Ukrainian', 'fa' => 'Persian', 'bn' => 'Bengali', 'ur' => 'Urdu', 'he' => 'Hebrew',
    ];
    /** Column limits of javcms_vod (install.sql) used for the cuts in normalize(). */
    const LIMITS = ['vod_name' => 255, 'vod_en' => 255, 'vod_sub' => 255, 'vod_tag' => 500, 'vod_class' => 255, 'vod_actor' => 255,
        'vod_director' => 255, 'vod_writer' => 255, 'vod_area' => 255, 'vod_blurb' => 255, 'vod_remarks' => 100, 'vod_state' => 30,
        'vod_version' => 30, 'vod_lang' => 150, 'vod_year' => 30, 'vod_pubdate' => 100, 'vod_duration' => 255, 'vod_pic' => 1024];
    const MAX_TAGS = 12;
    const TIME_MIN = 1104537600; // 2005-01-01 00:00:00 UTC (D-I12)
    /** xvidexport v1 (the JavCMS build tools, binding): fixed URLs, never taken from the manifest. */
    const EXPORT_BASE = 'https://xvidapi.com/export/v1/';
    const EXPORT_MANIFEST = 'https://xvidapi.com/export/v1/manifest.json';
    const EXPORT_API = ['cursor' => '/api.php/xvidexport/vod', 'changes' => '/api.php/xvidexport/changes', 'removed' => '/api.php/xvidexport/removed'];
    const EXPORT_CHANGES_URL = 'https://xvidapi.com/api.php/xvidexport/changes';
    const EXPORT_CURSOR_URL = 'https://xvidapi.com/api.php/xvidexport/vod';
    const EXPORT_REMOVED_URL = 'https://xvidapi.com/api.php/xvidexport/removed';
    /** Keyset page size (contract section 3) and removed feed page size. */
    const EXPORT_PAGE_ROWS = 1000;
    const EXPORT_REMOVED_ROWS = 10000;
    /** Detection once a day; the snapshot path is used while the last good check is <= 26 h old; errors retry after 1 h. */
    const EXPORT_CHECK_SECONDS = 86400;
    const EXPORT_FRESH_SECONDS = 93600;
    const EXPORT_RETRY_SECONDS = 3600;
    /** Contract section 4 / brief A4 bounds. */
    const EXPORT_MAX_SHARDS = 2000;
    const EXPORT_MAX_SHARD_ROWS = 1000000;
    const EXPORT_MAX_SHARD_BYTES = 536870912;
    const EXPORT_MAX_LINE = 1048576;
    const EXPORT_ROW_DECODED_MAX = 65536;
    /** Exception code of readShard() for a gzip bomb (the importer reads that shard's range through the keyset API). */
    const SHARD_BOMB = 413;

    private $http;
    private $pacer;
    private $extraImageHosts = [];
    /** Counters of the last normalize() calls (the batch decides on the image-host pause, 2.4). */
    public $stats = ['rows' => 0, 'image_dropped' => 0, 'image_hosts' => []];
    /** Catalog total reported by the last categories() answer (null = not reported). */
    public $lastTotal;

    /**
     * @param callable|null $transport fixture transport (tests / mock provider)
     * @param array         $options   image_hosts_extra: per-site extra image hosts the admin allowed (2.4);
     *                                 http: a ProviderHttp instance (tests / mock provider only)
     */
    public function __construct($transport = null, array $options = [])
    {
        // A ProviderHttp object may be passed by code (the mock provider of the tests); never from input.
        $this->http = isset($options['http']) && $options['http'] instanceof ProviderHttp ? $options['http'] : new ProviderHttp('xvidapi', $transport, ['timeout' => 30, 'connect_timeout' => 5]);
        foreach ((array)($options['image_hosts_extra'] ?? []) as $host) {
            if (is_string($host) && preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', strtolower($host))) {
                $this->extraImageHosts[] = strtolower($host);
            }
        }
    }

    public function withPacer(?ProviderPacer $pacer = null) { $this->pacer = $pacer; return $this; }

    public function pacer() { return $this->pacer; }

    /** Per-job extra image hosts the admin allowed (2.4 "allow it?"), added to the per-site list. */
    public function allowImageHosts(array $hosts)
    {
        foreach ($hosts as $host) {
            if (is_string($host) && preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', strtolower($host)) && !in_array(strtolower($host), $this->extraImageHosts, true)) {
                $this->extraImageHosts[] = strtolower($host);
            }
        }
        return $this;
    }

    /** Pass a provider row through without a request (generic paced wrapper kept for symmetry with AvdbProvider). */
    public function paced(callable $fn)
    {
        if ($this->pacer) { $this->pacer->acquire(); $this->pacer->markSent(); }
        $started = microtime(true);
        try { $out = $fn(); }
        catch (\Throwable $e) { if ($this->pacer) { $this->pacer->failure($e); } throw $e; }
        if ($this->pacer) { $this->pacer->success((int)round((microtime(true) - $started) * 1000), 200); }
        return $out;
    }

    public function key() { return self::KEY; }

    public function label() { return 'xvidapi'; }

    public function capabilities()
    {
        return [
            'stable_cursor' => 'id_windows',
            'ids_max' => self::IDS_MAX,
            'details_in_list' => true,
            'hours_filter' => 'h',
            'translations' => [],
            'category_names_i18n' => 'locale_list',
            'suggestions' => false,
            'tag_filter' => false,
            'jav_codes' => false,
            'embed_hosts' => ['upload18.net'],
            'image_hosts' => ['upload18.cc'],
            'min_interval_ms' => ['bulk' => 5000, 'interactive' => 2000],
            'category_parent' => 'International',
            'embed_path' => '^/play/index/xvidapi-[0-9]{1,12}$',
            // xvidexport v1 detected in the last 26 h (no network here): full imports read the static snapshot
            'snapshot' => $this->cachedManifest() !== null,
        ];
    }

    // ------------------------------------------------------------------ xvidexport v1 (contract section 4)

    private static $exportDir = null;
    private $manifestMemo = false;
    private $shardHandle = null;

    /** Tests: folder for the manifest cache and the shard files (default runtime/import/xvidexport/). */
    public static function setExportDir($dir) { self::$exportDir = $dir === null ? null : rtrim((string)$dir, '/\\') . DIRECTORY_SEPARATOR; }

    /** runtime/import/xvidexport/ (created), '' when there is no runtime folder. */
    public static function exportDir()
    {
        $dir = self::$exportDir !== null ? self::$exportDir : (defined('RUNTIME_PATH') ? rtrim(RUNTIME_PATH, '/\\') . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . 'xvidexport' . DIRECTORY_SEPARATOR : '');
        if ($dir === '') { return ''; }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { return ''; }
        return $dir;
    }

    private static function manifestCache()
    {
        $dir = self::exportDir(); if ($dir === '' || !is_file($dir . 'manifest.json')) { return null; }
        $d = json_decode((string)@file_get_contents($dir . 'manifest.json'), true, 16);
        return is_array($d) && isset($d['checked_at']) && is_int($d['checked_at']) ? $d : null;
    }

    private static function storeManifestCache(array $data)
    {
        $dir = self::exportDir(); if ($dir === '') { return; }
        $tmp = $dir . 'manifest.json.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES)) !== false && !@rename($tmp, $dir . 'manifest.json')) { @file_put_contents($dir . 'manifest.json', json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX); @unlink($tmp); }
    }

    /**
     * Keep the last good manifest in avdb_provider_state (export_manifest, export_checked_at; XVID-NOLIMIT-BRIEF A4) next
     * to the runtime file (the file answers capabilities() without a query). Best effort: before the 1.2.0 columns exist
     * nothing is written.
     */
    private static function mirrorManifest(array $m)
    {
        if (!class_exists('think\Db') || !function_exists('config')) { return; }
        try {
            $table = (string)config('database.prefix') . 'avdb_provider_state';
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { return; }
            \think\Db::execute('INSERT IGNORE INTO `' . $table . '` (`provider`,`updated_at`) VALUES (?,?)', [self::KEY, time()]);
            \think\Db::name('avdb_provider_state')->where('provider', self::KEY)->update(['export_manifest' => json_encode($m, JSON_UNESCAPED_SLASHES), 'export_checked_at' => time()]);
        } catch (\Throwable $e) { /* columns not there yet (upgrade pending) */ }
    }

    /** The validated manifest of the last good check when it is <= 26 h old, else null. Never makes a request. */
    public function cachedManifest()
    {
        if ($this->manifestMemo !== false) { return $this->manifestMemo; }
        $c = self::manifestCache();
        $m = null;
        if ($c && !empty($c['ok']) && is_array($c['manifest'] ?? null) && time() - (int)($c['ok_at'] ?? $c['checked_at']) <= self::EXPORT_FRESH_SECONDS) {
            try { $m = self::validateManifest($c['manifest']); } catch (\Throwable $e) { $m = null; }
        }
        return $this->manifestMemo = $m;
    }

    /**
     * Detection (contract section 4): GET the fixed manifest URL once a day (through the pacer), validate it and keep
     * it in runtime/import/xvidexport/manifest.json. Returns the manifest, or null when the provider has none (404,
     * invalid) or cannot be asked now (errors: retried after 1 h; a manifest checked in the last 26 h stays in use).
     */
    public function manifest($refresh = false)
    {
        $c = self::manifestCache();
        $age = $c ? time() - (int)$c['checked_at'] : PHP_INT_MAX;
        if (!$refresh && $c && $age < (!empty($c['ok']) ? self::EXPORT_CHECK_SECONDS : self::EXPORT_RETRY_SECONDS)) { $this->manifestMemo = false; return $this->cachedManifest(); }
        $keep = $c && !empty($c['ok']) ? $c : null;
        try {
            if ($this->pacer) { $this->pacer->acquire(); $this->pacer->markSent(); }
            $meta = null; $started = microtime(true);
            try { $data = $this->http->getJson(self::EXPORT_MANIFEST, null, $meta); }
            catch (\Throwable $e) { if ($this->pacer) { $this->pacer->failure($e); } throw $e; }
            if ($this->pacer) { $this->pacer->success((int)round((microtime(true) - $started) * 1000), 200); }
            $m = self::validateManifest($data);
            self::storeManifestCache(['checked_at' => time(), 'ok' => 1, 'ok_at' => time(), 'manifest' => $m]);
            self::mirrorManifest($m);
        } catch (ProviderWaitException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $gone = $e instanceof ProviderHttpException && in_array((int)$e->status, [403, 404, 410], true) || !($e instanceof ProviderHttpException);
            self::storeManifestCache(['checked_at' => time(), 'ok' => $gone ? 0 : (int)!empty($keep), 'ok_at' => $keep ? (int)($keep['ok_at'] ?? $keep['checked_at']) : 0, 'manifest' => $gone ? null : ($keep['manifest'] ?? null), 'error' => substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $e->getMessage()), 0, 200)]);
        }
        $this->manifestMemo = false;
        return $this->cachedManifest();
    }

    /**
     * Manifest rules (brief A4): format / row format / locale, ids 1..2^31-1, 1..2,000 shards with fixed file names,
     * rows 1..1,000,000, bytes 1..512 MiB, sha256 hex, ranges non-overlapping with min <= max, api paths exactly the
     * contract paths. Returns the manifest reduced to the known fields; throws \RuntimeException with the reason.
     */
    public static function validateManifest($data)
    {
        $bad = function ($why) { throw new \RuntimeException('Invalid xvidexport manifest: ' . $why . '.'); };
        if (!is_array($data)) { $bad('not an object'); }
        foreach (['format' => 'xvidexport-v1', 'row_format' => 'provide1-detail-v1', 'locale' => 'en'] as $k => $v) { if (($data[$k] ?? null) !== $v) { $bad($k); } }
        $int = function ($v, $min, $max) { return (is_int($v) || (is_string($v) && preg_match('/^[0-9]{1,10}$/D', $v))) && (float)$v >= $min && (float)$v <= $max; };
        if (!$int($data['generated_at'] ?? null, 1, time() + 86400)) { $bad('generated_at'); }
        if (!$int($data['catalog_total'] ?? null, 0, self::MAX_REMOTE_ID)) { $bad('catalog_total'); }
        if (!$int($data['max_id'] ?? null, 1, self::MAX_REMOTE_ID)) { $bad('max_id'); }
        $shards = $data['shards'] ?? null;
        if (!is_array($shards) || !$shards || count($shards) > self::EXPORT_MAX_SHARDS || array_keys($shards) !== range(0, count($shards) - 1)) { $bad('shards'); }
        $out = []; $files = [];
        foreach ($shards as $sh) {
            if (!is_array($sh) || !is_string($sh['file'] ?? null) || !preg_match('/^vod-[0-9]{6}\.ndjson\.gz$/D', $sh['file'])) { $bad('shard file name'); }
            if (isset($files[$sh['file']])) { $bad('duplicate shard'); }
            if (!$int($sh['rows'] ?? null, 1, self::EXPORT_MAX_SHARD_ROWS)) { $bad('shard rows'); }
            if (!$int($sh['bytes'] ?? null, 1, self::EXPORT_MAX_SHARD_BYTES)) { $bad('shard bytes'); }
            if (!$int($sh['min_id'] ?? null, 1, self::MAX_REMOTE_ID) || !$int($sh['max_id'] ?? null, 1, self::MAX_REMOTE_ID) || (int)$sh['min_id'] > (int)$sh['max_id'] || (int)$sh['max_id'] > (int)$data['max_id']) { $bad('shard id range'); }
            if (!is_string($sh['sha256'] ?? null) || !preg_match('/^[0-9a-f]{64}$/D', $sh['sha256'])) { $bad('shard sha256'); }
            $files[$sh['file']] = true;
            $out[] = ['file' => $sh['file'], 'rows' => (int)$sh['rows'], 'min_id' => (int)$sh['min_id'], 'max_id' => (int)$sh['max_id'], 'bytes' => (int)$sh['bytes'], 'sha256' => $sh['sha256']];
        }
        $sorted = $out; usort($sorted, function ($a, $b) { return $a['min_id'] <=> $b['min_id']; });
        for ($i = 1; $i < count($sorted); $i++) { if ($sorted[$i]['min_id'] <= $sorted[$i - 1]['max_id']) { $bad('overlapping shards'); } }
        if (isset($data['api'])) {
            if (!is_array($data['api'])) { $bad('api'); }
            foreach ($data['api'] as $k => $v) { if (!isset(self::EXPORT_API[$k]) || $v !== self::EXPORT_API[$k]) { $bad('api path'); } }
        }
        // the reduced manifest passes this check again (it is stored and re-read by cachedManifest())
        $kept = ['format' => 'xvidexport-v1', 'row_format' => 'provide1-detail-v1', 'locale' => 'en', 'generated_at' => (int)$data['generated_at'], 'catalog_total' => (int)$data['catalog_total'], 'max_id' => (int)$data['max_id'],
            'shards' => $out, 'changes' => !isset($data['api']) || isset($data['api']['changes']), 'removed' => !isset($data['api']) || isset($data['api']['removed']),
            'cursor' => !isset($data['api']) || isset($data['api']['cursor']), 'download_bytes' => array_sum(array_column($out, 'bytes'))];
        if (isset($data['api'])) { $kept['api'] = array_intersect_key(self::EXPORT_API, $data['api']); }
        return $kept;
    }

    /** Fixed shard URL for a validated file name. */
    public static function shardUrl($file)
    {
        if (!is_string($file) || !preg_match('/^vod-[0-9]{6}\.ndjson\.gz$/D', $file)) { throw new \InvalidArgumentException('Invalid shard file.'); }
        return self::EXPORT_BASE . $file;
    }

    /**
     * Resumable shard download (ProviderHttp::download, hard cap = the manifest size). Returns bytes on disk; $meta = download()'s
     * meta ('short': the file ended below the manifest size). Round-2 verify fix import-r2 N2: every download call takes the
     * provider's pacer slot first (the command line sleeps until it, a web step that finds the clock busy ends with retry_at),
     * like every API request; a failed download counts as a provider failure (back-off). A short file changes neither the
     * failure count nor the adaptive interval: the importer counts it as a failed try of that shard (two, then the keyset range).
     */
    public function downloadShard(array $shard, $path, $offset, $deadline, &$meta = null)
    {
        $this->closeShard();
        $meta = null;
        if ($this->pacer) { $this->pacer->acquire(); $this->pacer->markSent(); }
        try {
            $size = $this->http->download(self::shardUrl($shard['file'] ?? ''), $path, (int)$offset, (int)$shard['bytes'], (float)$deadline, $meta);
        } catch (\Throwable $e) {
            if ($this->pacer) { $this->pacer->markSent(); $this->pacer->failure($e); }
            throw $e;
        }
        if ($this->pacer && is_array($meta) && !empty($meta['requested'])) {
            // the next slot counts from the end of the transfer (the provider served us until now); the answer time is the time
            // to the first byte (a 23 MB file is not a slow answer)
            $this->pacer->markSent();
            if (empty($meta['short'])) { $this->pacer->success((int)($meta['ttfb_ms'] ?? 0), (int)$meta['status']); }
        }
        return $size;
    }

    /** Close a kept shard handle (before the file is replaced or deleted). */
    public function closeShard()
    {
        if ($this->shardHandle) { @gzclose($this->shardHandle['h']); $this->shardHandle = null; }
    }

    /**
     * Read up to $maxRows rows of a downloaded shard from uncompressed offset $uoff (constant memory: gzgets with a
     * 1 MiB line cap). Rows must be flat provide1-detail-v1 rows (validateAnswer rules), inside the shard's id range and
     * strictly ascending after $lastId; other lines are refused and skipped. Refusals are keyed '#line N' (never by the
     * remote id: a refused line may carry the id of a good row before or after it, which must stay importable).
     * Decoded bytes above rows x 64 KB stop the shard (gzip bomb). The handle stays open between calls of one process
     * when the next call continues where the last ended (no re-inflation from the start).
     * $shard may carry 'lo' / 'hi': after a manifest switch only that part of the new shard is still to do; valid rows
     * outside it are skipped without a refusal (counted in 'skipped').
     * Returns ['rows','rejected','uoff','lines','eof','last_id','skipped'].
     */
    public function readShard($path, $uoff, $maxRows, array $shard, $lastId, $lineNo = 0)
    {
        $uoff = max(0, (int)$uoff); $lastId = (int)$lastId; $lineNo = (int)$lineNo; $skipped = 0;
        $lo = isset($shard['lo']) ? (int)$shard['lo'] : (int)$shard['min_id']; $hi = isset($shard['hi']) ? (int)$shard['hi'] : (int)$shard['max_id'];
        $h = null;
        if ($this->shardHandle && $this->shardHandle['path'] === $path && $this->shardHandle['pos'] === $uoff) { $h = $this->shardHandle['h']; }
        else {
            $this->closeShard();
            $h = @gzopen($path, 'rb');
            if (!$h) { throw new \RuntimeException('Cannot open the downloaded shard ' . basename((string)$path) . '.'); }
            if ($uoff > 0 && gzseek($h, $uoff) !== 0) { gzclose($h); throw new \RuntimeException('Cannot resume inside the shard ' . basename((string)$path) . '.'); }
        }
        $cap = max(1, (int)$shard['rows']) * self::EXPORT_ROW_DECODED_MAX;
        $min = (int)$shard['min_id']; $max = (int)$shard['max_id'];
        $rows = []; $rejected = []; $lines = 0; $eof = false;
        while (count($rows) < $maxRows) {
            $line = gzgets($h, self::EXPORT_MAX_LINE + 2);
            if ($line === false || $line === '') { $eof = true; break; }
            $lines++; $n = $lineNo + $lines;
            if (substr($line, -1) !== "\n" && strlen($line) > self::EXPORT_MAX_LINE) {
                while ($line !== false && $line !== '' && substr($line, -1) !== "\n") { $line = gzgets($h, self::EXPORT_MAX_LINE + 2); if (gztell($h) > $cap) { break; } }
                $rejected['#line ' . $n] = 'line longer than 1 MiB';
            } else {
                $t = trim($line);
                if ($t !== '') {
                    $row = json_decode($t, true, 8);
                    if (!is_array($row) || array_keys($row) === range(0, count($row) - 1)) { $rejected['#line ' . $n] = 'invalid JSON row'; }
                    else {
                        $a = self::validateAnswer(['list' => [$row]], 1);
                        if (!$a['list']) { $rejected['#line ' . $n] = ($a['rejected'] ? 'id ' . substr((string)array_key_first($a['rejected']), 0, 24) . ': ' . (string)reset($a['rejected']) : 'invalid row'); }
                        else {
                            $id = (int)$row['vod_id'];
                            if ($id < $min || $id > $max) { $rejected['#line ' . $n] = 'id ' . $id . ': outside the shard range'; }
                            elseif ($id <= $lastId) { $rejected['#line ' . $n] = 'id ' . $id . ': not ascending or duplicate'; }
                            elseif ($id < $lo || $id > $hi) { $lastId = $id; $skipped++; }
                            else { $lastId = $id; $rows[] = $row; }
                        }
                    }
                }
            }
            if (gztell($h) > $cap) { gzclose($h); $this->shardHandle = null; throw new \RuntimeException('The shard ' . basename((string)$path) . ' inflates beyond its announced rows (gzip bomb); the download is refused.', self::SHARD_BOMB); }
        }
        $pos = (int)gztell($h);
        if ($eof || gzeof($h)) {
            // gzeof can be true after the last line before a read returns false
            $eof = $eof || gzeof($h);
        }
        if ($eof) { gzclose($h); $this->shardHandle = null; }
        else { $this->shardHandle = ['path' => $path, 'pos' => $pos, 'h' => $h]; }
        return ['rows' => $rows, 'rejected' => $rejected, 'uoff' => $pos, 'lines' => $lines, 'eof' => $eof, 'last_id' => $lastId, 'skipped' => $skipped];
    }

    /**
     * Keyset changes feed (contract section 3): rows with vod_time > $since, ascending by (vod_time, vod_id);
     * $cursor '' = first page, else "<vod_time>:<vod_id>". Returns validateAnswer() + next_cursor ('' = done).
     */
    public function changes($since, $cursor = '', $limit = 1000)
    {
        $since = self::positive($since, self::MAX_REMOTE_ID, 'since');
        $limit = self::positive($limit, 1000, 'limit');
        $cursor = (string)$cursor;
        if ($cursor !== '' && !preg_match('/^[0-9]{1,10}:[0-9]{1,10}$/D', $cursor)) { throw new \InvalidArgumentException('Invalid changes cursor.'); }
        $data = $this->request(self::EXPORT_CHANGES_URL . '?' . http_build_query(['since' => $since, 'cursor' => $cursor, 'limit' => $limit], '', '&', PHP_QUERY_RFC3986));
        $answer = self::validateAnswer($data, $limit);
        $next = isset($data['next_cursor']) && is_scalar($data['next_cursor']) ? (string)$data['next_cursor'] : '';
        if ($next !== '' && !preg_match('/^[0-9]{1,10}:[0-9]{1,10}$/D', $next)) { throw new ProviderHttpException('xvidapi returned an invalid changes cursor.', ProviderHttpException::MALFORMED, 200, 0); }
        if ($next !== '' && $cursor !== '') {
            list($t0, $i0) = array_map('intval', explode(':', $cursor)); list($t1, $i1) = array_map('intval', explode(':', $next));
            if ($t1 < $t0 || ($t1 === $t0 && $i1 <= $i0)) { throw new ProviderHttpException('xvidapi changes cursor did not advance.', ProviderHttpException::MALFORMED, 200, 0); }
        }
        if ($next !== '' && !$answer['list'] && !$answer['rejected']) { $next = ''; }
        $answer['next_cursor'] = $next;
        // position after the last row of this page ("<vod_time>:<vod_id>"): the stored feed cursor of the hourly plan
        $answer['last_cursor'] = $next;
        if ($answer['list']) {
            $last = end($answer['list']); $t = self::remoteTime($last['vod_time'] ?? '', time() + 86400);
            if ($t > 0) { $answer['last_cursor'] = $t . ':' . (int)$last['vod_id']; }
        }
        return $answer;
    }

    /**
     * Keyset export page (contract section 3, `export_policy` stable-vod-id-desc-v1, the rules of AvdbClient::fetchExport):
     * rows with vod_id <= upper_id and < cursor, strictly descending, unique; the first call (cursor 0, upper 0) fixes
     * upper_id and reports total; next_cursor = the lowest id of the page, 0 when done. Returns validateAnswer() +
     * upper_id, next_cursor, total (null after the first page), exhausted. Throws ProviderHttpException MALFORMED when
     * the answer breaks a rule (the job pauses with the message, nothing of that page is queued).
     */
    public function exportPage($cursor, $upper, $limit = self::EXPORT_PAGE_ROWS)
    {
        $cursor = (int)$cursor; $upper = (int)$upper;
        if ($cursor < 0 || $cursor > self::MAX_REMOTE_ID || $upper < 0 || $upper > self::MAX_REMOTE_ID) { throw new \InvalidArgumentException('Invalid export cursor.'); }
        $limit = self::positive($limit, self::EXPORT_PAGE_ROWS, 'limit');
        $data = $this->request(self::EXPORT_CURSOR_URL . '?' . http_build_query(['cursor' => $cursor, 'upper_id' => $upper, 'limit' => $limit], '', '&', PHP_QUERY_RFC3986));
        $bad = function ($why) { throw new ProviderHttpException('xvidapi export ' . $why . '.', ProviderHttpException::MALFORMED, 200, 0); };
        $answer = self::validateAnswer($data, $limit);
        $gotUpper = isset($data['upper_id']) && is_scalar($data['upper_id']) && preg_match('/^[0-9]{1,10}$/D', (string)$data['upper_id']) ? (int)$data['upper_id'] : -1;
        if ($gotUpper < 0 || $gotUpper > self::MAX_REMOTE_ID || ($upper !== 0 && $gotUpper !== $upper)) { $bad('changed its fixed upper id'); }
        $next = isset($data['next_cursor']) && is_scalar($data['next_cursor']) && preg_match('/^[0-9]{1,10}$/D', (string)$data['next_cursor']) ? (int)$data['next_cursor'] : -1;
        if ($next < 0) { $bad('returned an invalid cursor'); }
        $prev = PHP_INT_MAX;
        foreach ($answer['list'] as $row) {
            $id = (int)$row['vod_id'];
            if ($id > $gotUpper || ($cursor !== 0 && $id >= $cursor) || $id >= $prev) { $bad('returned an out-of-bound, unsorted or duplicate video id'); }
            $prev = $id;
        }
        if ($answer['list'] && $next !== 0 && $next !== $prev) { $bad('cursor does not match its rows'); }
        if (!$answer['list'] && !$answer['rejected'] && $next !== 0) { $bad('cursor did not advance'); }
        if ($cursor !== 0 && $next !== 0 && $next >= $cursor) { $bad('cursor did not advance'); }
        $answer['upper_id'] = $gotUpper;
        $answer['next_cursor'] = $next;
        $answer['total'] = $cursor === 0 && isset($data['total']) && is_numeric($data['total']) ? max(0, (int)$data['total']) : null;
        $answer['exhausted'] = $next === 0;
        return $answer;
    }

    /**
     * Removed feed (contract section 3): videos withdrawn after $since: list [{vod_id, removed_at}], next_cursor ('' or
     * 0 = done). Entries with an invalid id or a removed_at not after $since are dropped. Returns ['list' => [[id, at]],
     * 'next_cursor' => string, 'max_at' => int].
     */
    public function removed($since, $cursor = '', $limit = self::EXPORT_REMOVED_ROWS)
    {
        $since = max(0, (int)$since); $limit = self::positive($limit, self::EXPORT_REMOVED_ROWS, 'limit');
        $cursor = (string)$cursor;
        if ($cursor !== '' && !preg_match('/^[0-9]{1,20}$/D', $cursor)) { throw new \InvalidArgumentException('Invalid removed cursor.'); }
        $q = ['since' => $since, 'limit' => $limit]; if ($cursor !== '') { $q = ['since' => $since, 'cursor' => $cursor, 'limit' => $limit]; }
        $data = $this->request(self::EXPORT_REMOVED_URL . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
        if (!is_array($data) || !isset($data['list']) || !is_array($data['list']) || count($data['list']) > $limit) { throw new ProviderHttpException('xvidapi returned an invalid removed list.', ProviderHttpException::MALFORMED, 200, 0); }
        $out = []; $max = 0; $seen = [];
        foreach ($data['list'] as $e) {
            if (!is_array($e) || !isset($e['vod_id']) || !self::validId($e['vod_id'])) { continue; }
            $at = isset($e['removed_at']) && is_numeric($e['removed_at']) ? (int)$e['removed_at'] : 0;
            if ($at <= $since || $at > time() + 86400) { continue; }
            $id = (int)$e['vod_id']; if (isset($seen[$id])) { continue; }
            $seen[$id] = true; $out[] = [$id, $at]; if ($at > $max) { $max = $at; }
        }
        $next = isset($data['next_cursor']) && is_scalar($data['next_cursor']) ? (string)$data['next_cursor'] : '';
        if ($next === '0') { $next = ''; }
        if ($next !== '' && !preg_match('/^[0-9]{1,20}$/D', $next)) { throw new ProviderHttpException('xvidapi returned an invalid removed cursor.', ProviderHttpException::MALFORMED, 200, 0); }
        if ($next !== '' && $cursor !== '' && strlen($next) <= 20 && (float)$next <= (float)$cursor) { throw new ProviderHttpException('xvidapi removed cursor did not advance.', ProviderHttpException::MALFORMED, 200, 0); }
        if (!$data['list']) { $next = ''; }
        return ['list' => $out, 'next_cursor' => $next, 'max_at' => $max];
    }

    // ------------------------------------------------------------------ requests

    /** [remote_type_id => name] from provide/vod ac=list (one call per locale, setup time only). */
    public function categories($locale = 'en')
    {
        if (!is_string($locale) || !in_array($locale, self::LOCALES, true)) { throw new \InvalidArgumentException('Unsupported xvidapi locale.'); }
        $data = $this->request(self::LIST_URL . '?' . http_build_query(['ac' => 'list', 'pagesize' => 1, 'locale' => $locale], '', '&', PHP_QUERY_RFC3986));
        if (!isset($data['class']) || !is_array($data['class']) || count($data['class']) > 500) { throw new \RuntimeException('xvidapi returned invalid categories.'); }
        // The list answer carries the catalog total (pagesize 1): kept for the estimate (avdb_provider_type row 0).
        $this->lastTotal = isset($data['total']) && is_numeric($data['total']) && (float)$data['total'] >= 0 && (float)$data['total'] <= self::MAX_REMOTE_ID ? (int)$data['total'] : null;
        $out = [];
        foreach ($data['class'] as $row) {
            if (!is_array($row) || !isset($row['type_id'], $row['type_name']) || !self::validId($row['type_id']) || !is_string($row['type_name'])) { continue; }
            $name = self::cleanText($row['type_name'], 1, 64);
            if ($name !== '') { $out[(int)$row['type_id']] = $name; }
        }
        return $out;
    }

    /** Provider total (pagesize 1). Filters: t (remote type id), h (canonical hours). */
    public function count(array $filters)
    {
        $q = ['ac' => 'list', 'pagesize' => 1, 'locale' => 'en'] + $this->filterQuery($filters);
        $data = $this->request(self::ROWS_URL . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
        return self::number($data, 'total');
    }

    /** Category / recent discovery page (D-I3): 1,000 rows by default, or a preview page size <= 100. */
    public function page(array $filters, $pg)
    {
        $pg = self::positive($pg, 1000000, 'page');
        $size = isset($filters['pagesize']) ? self::positive($filters['pagesize'], 100, 'pagesize') : null;
        $q = ['ac' => 'detail', 'locale' => 'en'] + $this->filterQuery($filters) + ['pg' => $pg];
        if ($size !== null) { $q['pagesize'] = $size; }
        $data = $this->request(self::ROWS_URL . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986));
        return self::validateAnswer($data, $size ?? self::PAGE_ROWS);
    }

    /** Detail rows for <= 500 ids (D-I2 window request). Missing ids are gaps or hidden videos, never errors. */
    public function byIds(array $ids)
    {
        $ids = self::idList($ids, self::IDS_MAX);
        return $this->idsAnswer($ids, $this->request(self::idsUrl($ids)));
    }

    private static function idsUrl(array $ids)
    {
        return self::ROWS_URL . '?' . http_build_query(['ac' => 'detail', 'locale' => 'en'], '', '&', PHP_QUERY_RFC3986) . '&ids=' . implode(',', $ids);
    }

    private function idsAnswer(array $ids, $data)
    {
        $answer = self::validateAnswer($data, count($ids), $ids);
        $found = [];
        foreach ($answer['list'] as $row) { $found[(int)$row['vod_id']] = true; }
        $answer['missing'] = array_values(array_filter($ids, function ($id) use ($found) { return !isset($found[$id]); }));
        return $answer;
    }

    /** Started window request (prefetch, D-I1): ['key'=>"cursor:upper", 'ids'=>int[], 'handle'=>object]. */
    private $prefetch;

    /**
     * Start the request of the window at $cursor now, when the provider's clock has a free slot (never sleeps); the
     * next cursorPage($cursor, $upper) collects it. Returns true when a request is in flight for that window.
     */
    public function prefetchWindow($cursor, $upper)
    {
        $key = (int)$cursor . ':' . (int)$upper;
        if ($this->prefetch) { return $this->prefetch['key'] === $key; }
        $w = self::window($cursor, $upper, self::IDS_MAX);
        if ($this->pacer && !$this->pacer->tryAcquire()) { return false; }
        $ids = range($w['high'], $w['low']);
        $this->prefetch = ['key' => $key, 'ids' => $ids, 'handle' => $this->http->start(self::idsUrl($ids))];
        if ($this->pacer) { $this->pacer->markSent(); }
        return true;
    }

    /** A prefetched window request is in flight (its slot is used; the caller collects it next). */
    public function hasPrefetch() { return $this->prefetch !== null; }

    /** Move a prefetched transfer forward (between apply batches). */
    public function pump() { $this->http->pump(); }

    /** Drop a prefetched request that will not be collected (end of a run). */
    public function abandonPrefetch() { $this->prefetch = null; $this->http->abandon(); }

    /** One id window walking down from $cursor (0 = start at $upper). */
    public function cursorPage($cursor, $upper)
    {
        $w = self::window($cursor, $upper, self::IDS_MAX);
        $key = (int)$cursor . ':' . (int)$upper;
        if ($this->prefetch && $this->prefetch['key'] === $key) {
            $p = $this->prefetch; $this->prefetch = null;
            $meta = null;
            try { $data = $this->http->finishJson($p['handle'], '1', $meta); }
            catch (\Throwable $e) { if ($this->pacer) { $this->pacer->failure($e); } throw $e; }
            if ($this->pacer) { $this->pacer->markSent($meta['sent_at']); $this->pacer->success((int)$meta['ms'], (int)$meta['status']); }
            $answer = $this->idsAnswer($p['ids'], $data);
        } else {
            if ($this->prefetch) { $this->abandonPrefetch(); }
            $answer = $this->byIds(range($w['high'], $w['low']));
        }
        $answer['next_cursor'] = $w['next_cursor'];
        $answer['exhausted'] = $w['exhausted'];
        $answer['upper_id'] = (int)$upper;
        $answer['window'] = [$w['low'], $w['high']];
        return $answer;
    }

    /** Incremental sync (D-I7): h is one of 3 / 24 / 168, pg from 1 until a short page. */
    public function recent($hours, $pg)
    {
        if (!in_array((int)$hours, self::HOURS, true)) { throw new \InvalidArgumentException('Use a canonical sync window of 3, 24 or 168 hours.'); }
        return $this->page(['h' => (int)$hours], $pg);
    }

    /** Ids of $ids that still exist (presence sweep, D-I8). */
    public function present(array $ids)
    {
        $answer = $this->byIds($ids);
        $out = [];
        foreach ($answer['list'] as $row) { $out[] = (int)$row['vod_id']; }
        sort($out);
        return $out;
    }

    public function translations(array $ids, array $locales) { return []; }

    /**
     * Find the upper id once per run (D-I2): galloping `ids=` probes upward from the reported total, then a binary
     * search; never above max(2 x total, 20,000,000).
     */
    public function findUpper($reportedTotal)
    {
        $self = $this;
        return self::gallop((int)$reportedTotal, function ($from, $to) use ($self) {
            $found = $self->present(range($from, $to));
            return $found ? max($found) : 0;
        });
    }

    private function request($url)
    {
        if ($this->pacer) { $this->pacer->acquire(); $this->pacer->markSent(); }
        $meta = null;
        try {
            $data = $this->http->getJson($url, '1', $meta);
        } catch (\Throwable $e) {
            if ($this->pacer) { $this->pacer->markSent(is_array($meta) && isset($meta['sent_at']) ? $meta['sent_at'] : null); $this->pacer->failure($e); }
            throw $e;
        }
        if ($this->pacer) { $this->pacer->markSent($meta['sent_at']); $this->pacer->success((int)$meta['ms'], (int)$meta['status']); }
        return $data;
    }

    private function filterQuery(array $filters)
    {
        $q = [];
        foreach ($filters as $key => $value) {
            if ($key === 'pagesize') { continue; }
            if ($key === 't') { $q['t'] = self::positive($value, 100000, 'category'); }
            elseif ($key === 'h') {
                if (!in_array((int)$value, self::HOURS, true)) { throw new \InvalidArgumentException('Use a canonical sync window of 3, 24 or 168 hours.'); }
                $q['h'] = (int)$value;
            } else { throw new \InvalidArgumentException('Unsupported xvidapi filter: ' . (is_scalar($key) ? (string)$key : '?')); }
        }
        return $q;
    }

    // ------------------------------------------------------------------ pure planners (unit-tested)

    /** Smallest canonical window that covers the time since the last success plus 1 hour (D-I7). */
    public static function canonicalHours($secondsSinceLastSuccess)
    {
        $need = (max(0, (int)$secondsSinceLastSuccess) + 3600) / 3600;
        foreach (self::HOURS as $h) { if ($need <= $h) { return $h; } }
        return 168;
    }

    /** Number of id windows for a fixed upper id. */
    public static function windowCount($upper, $size = self::IDS_MAX)
    {
        $upper = max(0, (int)$upper); $size = max(1, (int)$size);
        return (int)ceil($upper / $size);
    }

    /**
     * The window that $cursor points at, walking down: ids [low, high], high = $cursor (0 = $upper), low = max(1, high-size+1).
     * next_cursor = low - 1 (the checkpoint stored after the window was applied); exhausted when it reaches 0.
     */
    public static function window($cursor, $upper, $size = self::IDS_MAX)
    {
        $upper = (int)$upper; $cursor = (int)$cursor; $size = max(1, min(self::IDS_MAX, (int)$size));
        if ($upper < 1 || $upper > self::MAX_REMOTE_ID) { throw new \InvalidArgumentException('Invalid upper id.'); }
        if ($cursor < 0 || $cursor > $upper) { throw new \InvalidArgumentException('Invalid id-window checkpoint.'); }
        $high = $cursor === 0 ? $upper : $cursor;
        $low = max(1, $high - $size + 1);
        return ['low' => $low, 'high' => $high, 'next_cursor' => $low - 1, 'exhausted' => $low <= 1];
    }

    /**
     * Galloping search for the highest existing id. $probe(int $from, int $to): int = the highest id found in
     * [$from, $to] or 0. Returns the highest id found (0 = none). Probes never go above the bound.
     */
    public static function gallop($reportedTotal, callable $probe, &$probes = null)
    {
        $total = max(0, (int)$reportedTotal);
        $bound = (int)min(self::MAX_REMOTE_ID, max(2 * $total, self::PROBE_FLOOR));
        $probes = 0; $w = self::PROBE_IDS - 1;
        $ask = function ($from) use ($probe, $bound, $w, &$probes) {
            $from = max(1, min($bound, (int)$from)); $to = min($bound, $from + $w); $probes++;
            $found = (int)call_user_func($probe, $from, $to);
            return ($found >= $from && $found <= $to) ? $found : 0;
        };
        $lo = 0; $hi = $bound + 1;
        $step = max(self::PROBE_IDS, (int)($total / 16));
        $p = max(1, $total - $w);
        while (true) {
            $f = $ask($p);
            if ($f > 0) {
                $lo = max($lo, $f);
                if ($lo >= $bound) { return $bound; }
                $p = min($bound, $lo + $step); $step *= 2;
                if ($p <= $lo) { return $lo; }
                continue;
            }
            $hi = $p; break;
        }
        // binary search in (lo, hi): ids above lo up to hi-1 are unknown
        while ($hi - $lo > self::PROBE_IDS) {
            $mid = (int)floor(($lo + $hi) / 2);
            $f = $ask($mid);
            if ($f > 0) { $lo = max($lo, $f); } else { $hi = $mid; }
            if ($probes > 200) { break; }
        }
        // the last gap is smaller than one probe width: one probe from lo+1 settles it
        if ($lo + 1 < $hi) { $f = $ask($lo + 1); if ($f > 0) { $lo = max($lo, $f); } }
        return $lo;
    }

    // ------------------------------------------------------------------ answer validation (D-P7)

    /**
     * Validate one provide1 answer: list is a JSON list; rows are flat scalar maps with a remote id 1..2^31-1 and a
     * string title; at most $max rows (extra rows ignored); with $requested only requested ids, each once.
     * Invalid rows are dropped and reported in 'rejected' (remote id or '#<position>' => reason).
     */
    public static function validateAnswer($data, $max, ?array $requested = null)
    {
        if (!is_array($data) || !isset($data['list']) || !is_array($data['list']) || ($data['list'] && array_keys($data['list']) !== range(0, count($data['list']) - 1))) {
            throw new ProviderHttpException('xvidapi returned an invalid list.', ProviderHttpException::MALFORMED, 200, 0);
        }
        $max = max(0, (int)$max);
        $want = null;
        if ($requested !== null) { $want = []; foreach ($requested as $id) { $want[(int)$id] = true; } }
        $rows = []; $rejected = []; $seen = []; $extra = 0;
        foreach ($data['list'] as $i => $row) {
            if (count($rows) >= $max) { $extra++; continue; }
            $reason = self::rowProblem($row);
            if ($reason !== '') { $rejected[is_array($row) && isset($row['vod_id']) && is_scalar($row['vod_id']) ? substr((string)$row['vod_id'], 0, 24) : '#' . $i] = $reason; continue; }
            $id = (int)$row['vod_id'];
            if ($want !== null && !isset($want[$id])) { $extra++; continue; }
            if (isset($seen[$id])) { $extra++; continue; }
            $seen[$id] = true; $rows[] = $row;
        }
        $out = ['list' => $rows, 'rejected' => $rejected, 'ignored' => $extra];
        foreach (['total', 'page', 'pagecount', 'limit'] as $key) { $out[$key] = isset($data[$key]) ? self::number($data, $key) : 0; }
        return $out;
    }

    private static function rowProblem($row)
    {
        if (!is_array($row)) { return 'not an object'; }
        if (!isset($row['vod_id']) || !self::validId($row['vod_id'])) { return 'invalid remote id'; }
        if (!isset($row['vod_name']) || !is_string($row['vod_name']) || trim($row['vod_name']) === '') { return 'missing title'; }
        if (isset($row['type_id']) && !self::validId($row['type_id'])) { return 'invalid category id'; }
        if (count($row) > 300) { return 'too many fields'; }
        foreach ($row as $key => $value) {
            if (!is_string($key) || strlen($key) > 64) { return 'invalid field name'; }
            if (!is_scalar($value) && $value !== null) { return 'nested metadata'; }
        }
        return '';
    }

    // ------------------------------------------------------------------ normalize (2.4)

    /**
     * xvid row -> canonical row for AvdbImport::sanitizeRow(). Throws \RuntimeException when the row is refused
     * (playback outside the allowlist, invalid id). Image fields outside the allowlist are dropped (old value kept).
     * Extra keys: vod_time (provider upload time as unix seconds, 0 when outside 2005-01-01 .. now+1 day),
     * _lang_code (ISO code of the detected source language, '' when unknown).
     */
    public function normalize(array $row)
    {
        $problem = self::rowProblem($row);
        if ($problem !== '') { throw new \RuntimeException('Refused provider row: ' . $problem . '.'); }
        $caps = $this->capabilities();
        $this->stats['rows']++;
        $r = ['vod_id' => (int)$row['vod_id'], 'type_id' => (int)($row['type_id'] ?? 0)];

        $r['vod_name'] = self::repairTitle($row['vod_name'], 1, self::LIMITS['vod_name']);
        if ($r['vod_name'] === '') { throw new \RuntimeException('Refused provider row: empty title.'); }
        $en = self::repairTitle($row['vod_en'] ?? '', 2, self::LIMITS['vod_en']);
        $r['vod_en'] = $en === $r['vod_name'] ? '' : $en;

        $r['vod_sub'] = (isset($row['vod_sub']) && is_scalar($row['vod_sub']) && preg_match('/^[0-9]{1,20}$/D', (string)$row['vod_sub'])) ? (string)$row['vod_sub'] : '';
        $r['vod_remarks'] = '';
        $version = strtoupper(trim((string)($row['vod_version'] ?? '')));
        $r['vod_version'] = in_array($version, self::QUALITY, true) ? $version : '';

        foreach (['vod_actor', 'vod_director', 'vod_area'] as $key) { $r[$key] = self::nameList($row[$key] ?? '', self::LIMITS[$key]); }
        $r['vod_writer'] = self::nameList($row['vod_author'] ?? '', self::LIMITS['vod_writer']);
        $r['vod_class'] = self::classList($row['vod_class'] ?? '');
        $r['vod_tag'] = self::tagList($row['vod_tag'] ?? '');

        $titleKeys = [mb_strtolower($r['vod_name'], 'UTF-8'), mb_strtolower($en, 'UTF-8')];
        $blurb = self::repairTitle($row['vod_blurb'] ?? '', 2, self::LIMITS['vod_blurb']);
        $r['vod_blurb'] = ($blurb === '' || in_array(mb_strtolower($blurb, 'UTF-8'), $titleKeys, true)) ? '' : $blurb;
        $content = self::repairTitle($row['vod_content'] ?? '', 2, 65535);
        $r['vod_content'] = ($content === '' || in_array(mb_strtolower($content, 'UTF-8'), $titleKeys, true)) ? '' : $content;

        $r['vod_state'] = self::cleanText($row['vod_state'] ?? '', 1, self::LIMITS['vod_state']);
        foreach (['vod_isend' => 1, 'vod_total' => 16777215] as $key => $maximum) {
            if (isset($row[$key]) && is_scalar($row[$key]) && preg_match('/^[0-9]{1,8}$/D', (string)$row[$key]) && (int)$row[$key] <= $maximum) { $r[$key] = (int)$row[$key]; }
        }
        $r['vod_duration'] = self::duration($row['vod_duration'] ?? '');

        $time = self::remoteTime($row['vod_time'] ?? '');
        $r['vod_time'] = $time;
        $r['vod_year'] = $time ? gmdate('Y', $time) : '';
        $r['vod_pubdate'] = $time ? gmdate('Y-m-d', $time) : '';

        $code = strtolower(trim((string)($row['source_language'] ?? '')));
        $status = strtolower(trim((string)($row['source_language_status'] ?? '')));
        $known = preg_match('/^[a-z]{2}$/D', $code) && isset(self::LANG_NAMES[$code]);
        $r['vod_lang'] = ($known && in_array($status, ['high', 'medium'], true)) ? self::LANG_NAMES[$code] : '';
        $r['_lang_code'] = $r['vod_lang'] !== '' ? $code : '';

        // images: https + exact host only; thumb / slide / screenshot empty when equal to the poster (lean rule)
        $hosts = array_values(array_unique(array_merge($caps['image_hosts'], $this->extraImageHosts)));
        $poster = null;
        if (isset($row['vod_pic']) && is_scalar($row['vod_pic']) && (string)$row['vod_pic'] !== '') {
            $poster = self::allowedUrl((string)$row['vod_pic'], $hosts, null, $host);
            if ($poster !== null) { $r['vod_pic'] = $poster; } else { $this->imageDropped($host); }
        }
        foreach (['vod_pic_thumb', 'vod_pic_slide'] as $key) {
            if (!isset($row[$key]) || !is_scalar($row[$key])) { continue; }
            $value = trim((string)$row[$key]);
            if ($value === '' || ($poster !== null && $value === $poster) || $value === trim((string)($row['vod_pic'] ?? ''))) { $r[$key] = ''; continue; }
            $ok = self::allowedUrl($value, $hosts, null, $host);
            if ($ok !== null) { $r[$key] = $ok; } else { $this->imageDropped($host); }
        }
        if (isset($row['vod_pic_screenshot']) && is_scalar($row['vod_pic_screenshot'])) {
            $shots = [];
            foreach (explode('#', (string)$row['vod_pic_screenshot']) as $shot) {
                $shot = trim($shot);
                if ($shot === '' || $shot === $poster || $shot === trim((string)($row['vod_pic'] ?? ''))) { continue; }
                $ok = self::allowedUrl($shot, $hosts, null, $host);
                if ($ok !== null) { $shots[$ok] = true; } else { $this->imageDropped($host); }
                if (count($shots) >= 20) { break; }
            }
            $r['vod_pic_screenshot'] = implode('#', array_keys($shots));
        }

        // playback: ngm3u8 / Full$https://upload18.net/play/index/xvidapi-<id>; anything else refuses the row
        list($r['vod_play_from'], $r['vod_play_url']) = self::playback($row['vod_play_from'] ?? '', $row['vod_play_url'] ?? '', $caps['embed_hosts'], $caps['embed_path']);
        return $r;
    }

    private function imageDropped($host)
    {
        $this->stats['image_dropped']++;
        $host = is_string($host) ? substr($host, 0, 253) : '';
        if ($host !== '' && count($this->stats['image_hosts']) < 20) { $this->stats['image_hosts'][$host] = ($this->stats['image_hosts'][$host] ?? 0) + 1; }
    }

    public function resetStats() { $this->stats = ['rows' => 0, 'image_dropped' => 0, 'image_hosts' => []]; }

    /**
     * Text repair for titles (2.4): decode entities $rounds times, repair the provider's "&name_" entities
     * (";" became "_"), bullet -> " - ", " _ " -> " - ", "She_s" -> "She's", strip tags and control characters,
     * collapse spaces, cut to $limit characters.
     */
    public static function repairTitle($value, $rounds, $limit)
    {
        if (!is_scalar($value)) { return ''; }
        $s = self::utf8((string)$value);
        if (strlen($s) > 262144) { $s = substr($s, 0, 262144); $s = self::utf8($s); }
        for ($i = 0; $i < $rounds; $i++) {
            $next = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $s) { break; }
            $s = $next;
        }
        $s = preg_replace_callback('/&(#[0-9]{1,7}|#x[0-9a-fA-F]{1,6}|[A-Za-z][A-Za-z0-9]{1,31})_/', function ($m) {
            $decoded = html_entity_decode('&' . $m[1] . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $decoded === '&' . $m[1] . ';' ? $m[0] : $decoded;
        }, $s);
        $s = str_replace(["\u{2022}", "\u{00B7}"], ' - ', (string)$s);
        $s = str_replace(' _ ', ' - ', $s);
        $s = preg_replace('/(\p{L})_(re|s|t|ll|ve|m|d)\b/u', "$1'$2", $s);
        return self::cleanText((string)$s, 0, $limit);
    }

    /** strip tags + control characters, collapse whitespace, cut to $limit characters (no entity decoding unless $rounds). */
    public static function cleanText($value, $rounds, $limit)
    {
        if (!is_scalar($value)) { return ''; }
        $s = self::utf8((string)$value);
        for ($i = 0; $i < $rounds; $i++) { $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
        $s = strip_tags($s);
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        $s = trim((string)preg_replace('/\s+/u', ' ', (string)$s));
        return mb_substr($s, 0, max(0, (int)$limit), 'UTF-8');
    }

    private static function utf8($s)
    {
        if (preg_match('//u', $s)) { return $s; }
        return function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($s, 'UTF-8', 'UTF-8') : '';
    }

    /** Comma list of names without placeholders (Updating, Unknown, N/A, -), joined with ", ", cut on a name boundary. */
    public static function nameList($value, $limit)
    {
        if (!is_scalar($value)) { return ''; }
        $out = []; $seen = []; $len = 0;
        foreach (explode(',', self::repairTitle((string)$value, 1, 4096)) as $name) {
            $name = trim($name);
            if ($name === '' || in_array(mb_strtolower($name, 'UTF-8'), self::PLACEHOLDERS, true)) { continue; }
            $k = mb_strtolower($name, 'UTF-8'); if (isset($seen[$k])) { continue; }
            $add = mb_strlen($name, 'UTF-8') + ($out ? 2 : 0);
            if ($len + $add > $limit) { break; }
            $seen[$k] = true; $out[] = $name; $len += $add;
        }
        return implode(', ', $out);
    }

    /** Remote class slugs, never "Xvidapi" / "Unknown". */
    public static function classList($value)
    {
        if (!is_scalar($value)) { return ''; }
        $out = [];
        foreach (explode(',', self::cleanText((string)$value, 1, 1024)) as $c) {
            $c = trim($c);
            if ($c === '' || in_array(mb_strtolower($c, 'UTF-8'), self::CLASS_NEVER, true) || in_array(mb_strtolower($c, 'UTF-8'), self::PLACEHOLDERS, true)) { continue; }
            $out[mb_strtolower($c, 'UTF-8')] = $c;
        }
        $s = implode(',', array_values($out));
        return mb_substr($s, 0, self::LIMITS['vod_class'], 'UTF-8');
    }

    /** Tags: drop the cut last tag when the API string is >= 99 characters; humanize (big-ass -> Big Ass); max 12. */
    public static function tagList($value)
    {
        if (!is_scalar($value)) { return ''; }
        $raw = (string)$value;
        if (strlen($raw) > 262144) { $raw = substr($raw, 0, 262144); }
        $parts = explode(',', $raw);
        if (mb_strlen($raw, 'UTF-8') >= 99 && count($parts) > 1) { array_pop($parts); }
        $out = []; $len = 0;
        foreach ($parts as $tag) {
            $tag = self::cleanText(str_replace(['-', '_'], ' ', $tag), 1, 64);
            if ($tag === '' || in_array(mb_strtolower($tag, 'UTF-8'), self::PLACEHOLDERS, true)) { continue; }
            $words = [];
            foreach (explode(' ', $tag) as $w) {
                $words[] = ($w !== '' && mb_strtolower($w, 'UTF-8') === $w) ? mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($w, 1, null, 'UTF-8') : $w;
            }
            $tag = implode(' ', $words);
            $k = mb_strtolower($tag, 'UTF-8');
            if (isset($out[$k])) { continue; }
            $add = mb_strlen($tag, 'UTF-8') + ($out ? 1 : 0);
            if ($len + $add > self::LIMITS['vod_tag']) { break; }
            $out[$k] = $tag; $len += $add;
            if (count($out) >= self::MAX_TAGS) { break; }
        }
        return implode(',', array_values($out));
    }

    /** mm:ss -> 00:mm:ss, h:mm:ss -> hh:mm:ss, plain seconds -> hh:mm:ss; anything else ''. */
    public static function duration($value)
    {
        if (!is_scalar($value)) { return ''; }
        $v = trim((string)$value);
        if (preg_match('/^([0-9]{1,3}):([0-5][0-9])$/D', $v, $m)) { $h = intdiv((int)$m[1], 60); $min = (int)$m[1] % 60; return sprintf('%02d:%02d:%02d', $h, $min, (int)$m[2]); }
        if (preg_match('/^([0-9]{1,3}):([0-5][0-9]):([0-5][0-9])$/D', $v, $m)) { return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)$m[3]); }
        if (preg_match('/^[0-9]{1,6}$/D', $v) && (int)$v > 0) { $s = (int)$v; return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60); }
        return '';
    }

    /** Provider upload time ("Y-m-d H:i:s" UTC or unix seconds) -> unix seconds, 0 outside 2005-01-01 .. now + 1 day. */
    public static function remoteTime($value, $now = null)
    {
        $now = $now === null ? time() : (int)$now;
        if (!is_scalar($value)) { return 0; }
        $v = trim((string)$value); $ts = 0;
        if (preg_match('/^[0-9]{9,10}$/D', $v)) { $ts = (int)$v; }
        elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/D', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $ts = gmmktime((int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0), (int)$m[2], (int)$m[3], (int)$m[1]);
        }
        return ($ts >= self::TIME_MIN && $ts <= $now + 86400) ? (int)$ts : 0;
    }

    /**
     * URL allowed by host list: https, host exactly in $hosts (no suffix match), no port, no userinfo, no fragment,
     * no unsafe characters, optional path regex. Returns the URL or null; $host gets the parsed host for messages.
     */
    public static function allowedUrl($url, array $hosts, $pathRegex = null, &$host = null)
    {
        $host = '';
        if (!is_string($url) || $url === '' || strlen($url) > 1024 || preg_match('/[\x00-\x20\x7f"\'<>`\\\\$#]/', $url)) { return null; }
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) { return null; }
        $host = strtolower($p['host']);
        if (strtolower($p['scheme']) !== 'https' || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) { return null; }
        if (!in_array($host, array_map('strtolower', $hosts), true)) { return null; }
        if ($pathRegex !== null && !preg_match('~' . str_replace('~', '\~', $pathRegex) . '~D', (string)($p['path'] ?? ''))) { return null; }
        return $url;
    }

    /** Validated playback groups -> [from, url]; throws when a group or an episode is outside the allowlist. */
    public static function playback($from, $url, array $hosts, $pathRegex)
    {
        if (!is_scalar($from) || !is_scalar($url)) { throw new \RuntimeException('Refused provider row: invalid playback fields.'); }
        $urls = explode('$$$', (string)$url);
        if (count($urls) > 20 || trim((string)$url) === '') { throw new \RuntimeException('Refused provider row: playback is missing.'); }
        $groups = [];
        foreach ($urls as $group) {
            $episodes = [];
            foreach (explode('#', $group) as $episode) {
                if (trim($episode) === '') { continue; }
                $parts = explode('$', $episode, 2);
                if (count($parts) !== 2) { throw new \RuntimeException('Refused provider row: invalid episode.'); }
                $ok = self::allowedUrl(trim($parts[1]), $hosts, $pathRegex, $host);
                if ($ok === null) { throw new \RuntimeException('Refused provider row: embed URL outside the allowed hosts (' . substr(preg_replace('/[^a-z0-9.-]/', '', (string)$host), 0, 64) . ').'); }
                $label = str_replace(['#', '$'], '', self::cleanText($parts[0], 1, 100));
                $episodes[] = ($label === '' ? 'Full' : $label) . '$' . $ok;
                if (count($episodes) > 500) { throw new \RuntimeException('Refused provider row: too many episodes.'); }
            }
            if (!$episodes) { throw new \RuntimeException('Refused provider row: empty playback group.'); }
            $groups[] = implode('#', $episodes);
        }
        return [implode('$$$', array_fill(0, count($groups), 'ngm3u8')), implode('$$$', $groups)];
    }

    // ------------------------------------------------------------------ small helpers

    public static function validId($value)
    {
        return (is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value))) && (int)$value >= 1 && (float)$value <= self::MAX_REMOTE_ID;
    }

    private static function idList(array $ids, $max)
    {
        $out = [];
        foreach ($ids as $id) {
            if (!self::validId(is_int($id) ? $id : (string)$id)) { throw new \InvalidArgumentException('Invalid remote id.'); }
            $out[(int)$id] = (int)$id;
        }
        if (!$out || count($out) > $max) { throw new \InvalidArgumentException('Ask for 1 to ' . $max . ' ids at once.'); }
        return array_values($out);
    }

    private static function positive($value, $max, $what)
    {
        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > $max) { throw new \InvalidArgumentException('Invalid xvidapi ' . $what . '.'); }
        return (int)$value;
    }

    private static function number(array $data, $key)
    {
        $v = $data[$key] ?? null;
        if (!is_scalar($v) || !preg_match('/^[0-9]{1,12}$/D', (string)$v)) { throw new ProviderHttpException('xvidapi returned invalid pagination.', ProviderHttpException::MALFORMED, 200, 0); }
        return (int)$v;
    }
}
