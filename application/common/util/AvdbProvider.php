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
 * avdbapi.com import provider: wraps today's AvdbClient without changing its requests or validation
 * (XVID-SCALE-BRIEF D-P1). Adds the interface shape, the optional ProviderPacer, the D-P7 cut of rows that were not
 * requested, and (lane P step 2) the embed and image host rules of 2.4 for AVDB rows: playback only from https +
 * exact `embed_hosts` + `embed_path`, otherwise the row is refused; images only from https + exact `image_hosts`,
 * otherwise the field is dropped and the old value kept. Every other field is passed on unchanged (the AVDB lean rule
 * is not applied in this build).
 */
class AvdbProvider implements ImportProvider
{
    const KEY = 'avdb';
    const IDS_MAX = 1000;
    const EXPORT_LIMIT = 1000;

    private $client;
    private $pacer;
    private $extraImageHosts = [];
    /** Counters of the last normalize() calls (the batch decides on the image-host pause, 2.4). */
    public $stats = ['rows' => 0, 'image_dropped' => 0, 'image_hosts' => []];

    public function __construct($client = null)
    {
        if ($client !== null && !($client instanceof AvdbClient)) { throw new \InvalidArgumentException('Invalid AVDB client.'); }
        $this->client = $client ?: new AvdbClient();
    }

    public function withPacer(?ProviderPacer $pacer = null) { $this->pacer = $pacer; return $this; }

    public function pacer() { return $this->pacer; }

    public function client() { return $this->client; }

    public function allowImageHosts(array $hosts)
    {
        foreach ($hosts as $host) {
            if (is_string($host) && preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/D', strtolower($host)) && !in_array(strtolower($host), $this->extraImageHosts, true)) {
                $this->extraImageHosts[] = strtolower($host);
            }
        }
        return $this;
    }

    public function key() { return self::KEY; }

    public function label() { return 'AVDB'; }

    public function capabilities()
    {
        $locales = array_values(array_diff(array_keys(AvdbClient::languages()), ['en']));
        return [
            'stable_cursor' => 'export',
            'ids_max' => self::IDS_MAX,
            'details_in_list' => true,
            'hours_filter' => 'h',
            'translations' => $locales,
            'category_names_i18n' => 'template_lang',
            'suggestions' => true,
            'tag_filter' => true,
            'jav_codes' => true,
            'embed_hosts' => ['upload18.org'],
            'image_hosts' => ['upload18.cc'],
            'min_interval_ms' => ['bulk' => 2000, 'interactive' => 1000],
            'category_parent' => null,
            'embed_path' => '^/play/index/[A-Za-z0-9._-]{1,64}$',
            // xvidexport-style static snapshot (XvidClient only; AVDB full imports use its keyset export)
            'snapshot' => false,
        ];
    }

    public function categories($locale = 'en')
    {
        $data = $this->call(function () use ($locale) { return $this->client->fetch(['ac' => 'list', 'pagesize' => 1], $locale); });
        $out = [];
        foreach ((array)($data['class'] ?? []) as $row) {
            if (is_array($row) && isset($row['type_id'], $row['type_name']) && XvidClient::validId(is_int($row['type_id']) ? $row['type_id'] : (string)$row['type_id']) && is_string($row['type_name'])) {
                $out[(int)$row['type_id']] = XvidClient::cleanText($row['type_name'], 0, 64);
            }
        }
        return $out;
    }

    public function count(array $filters)
    {
        $data = $this->call(function () use ($filters) { return $this->client->fetch(['ac' => 'list', 'pagesize' => 1] + $filters, 'en'); });
        return (int)$data['total'];
    }

    public function page(array $filters, $pg)
    {
        $data = $this->call(function () use ($filters, $pg) { return $this->client->fetchImport(['pg' => $pg] + $filters, 'en'); });
        return self::cut($data, isset($filters['pagesize']) ? (int)$filters['pagesize'] : self::IDS_MAX, null);
    }

    public function byIds(array $ids)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids || count($ids) > self::IDS_MAX) { throw new \InvalidArgumentException('Ask for 1 to ' . self::IDS_MAX . ' ids at once.'); }
        $data = $this->call(function () use ($ids) { return $this->client->fetchImport(['ids' => $ids], 'en'); });
        $answer = self::cut($data, count($ids), $ids);
        $found = [];
        foreach ($answer['list'] as $row) { $found[(int)$row['vod_id']] = true; }
        $answer['missing'] = array_values(array_filter($ids, function ($id) use ($found) { return !isset($found[$id]); }));
        return $answer;
    }

    public function cursorPage($cursor, $upper)
    {
        $data = $this->call(function () use ($cursor, $upper) { return $this->client->fetchExport(['cursor' => (int)$cursor, 'upper_id' => (int)$upper, 'limit' => self::EXPORT_LIMIT]); });
        $data['upper_id'] = (int)$data['upper_id'];
        $data['next_cursor'] = (int)$data['next_cursor'];
        $data['exhausted'] = !empty($data['exhausted']);
        $data['rejected'] = [];
        return $data;
    }

    public function recent($hours, $pg)
    {
        if (!in_array((int)$hours, XvidClient::HOURS, true)) { throw new \InvalidArgumentException('Use a canonical sync window of 3, 24 or 168 hours.'); }
        return $this->page(['h' => (int)$hours], $pg);
    }

    public function present(array $ids)
    {
        $out = [];
        foreach ($this->byIds($ids)['list'] as $row) { $out[] = (int)$row['vod_id']; }
        sort($out);
        return $out;
    }

    public function translations(array $ids, array $locales)
    {
        if (!$ids || !$locales) { return []; }
        return $this->call(function () use ($ids, $locales) {
            return count($ids) > 100 ? $this->client->fetchExportTranslations($ids, $locales) : $this->client->fetchTranslations($ids, $locales);
        });
    }

    /**
     * 2.4 host rules for AVDB rows (lane P step 2). Playback groups: every episode URL must be https, its host exactly
     * in embed_hosts (no suffix match), no port / userinfo / fragment, and its path must match embed_path; otherwise
     * the row is refused (\RuntimeException). Images (poster, thumb, slide, screenshots): https + exact image_hosts,
     * otherwise the field is dropped (the old value stays). All other fields are returned unchanged.
     */
    public function normalize(array $row)
    {
        $caps = $this->capabilities();
        $this->stats['rows']++;
        $from = $row['vod_play_from'] ?? '';
        $url = $row['vod_play_url'] ?? '';
        if (is_scalar($url) && trim((string)$url) !== '') {
            foreach (explode('$$$', (string)$url) as $group) {
                foreach (explode('#', $group) as $episode) {
                    if (trim($episode) === '') { continue; }
                    $parts = explode('$', $episode, 2);
                    if (count($parts) !== 2) { continue; } // sanitizeRow refuses malformed episodes with its own message
                    if (XvidClient::allowedUrl(trim($parts[1]), $caps['embed_hosts'], $caps['embed_path'], $host) === null) {
                        throw new \RuntimeException('Refused provider row: embed URL outside the allowed hosts (' . substr(preg_replace('/[^a-z0-9.-]/', '', (string)$host), 0, 64) . ').');
                    }
                }
            }
        }
        unset($from);
        $hosts = array_values(array_unique(array_merge($caps['image_hosts'], $this->extraImageHosts)));
        foreach (['vod_pic', 'vod_pic_thumb', 'vod_pic_slide'] as $key) {
            if (!isset($row[$key]) || !is_scalar($row[$key]) || trim((string)$row[$key]) === '') { continue; }
            if (XvidClient::allowedUrl(trim((string)$row[$key]), $hosts, null, $host) === null) { unset($row[$key]); $this->imageDropped($host); }
        }
        if (isset($row['vod_pic_screenshot']) && is_scalar($row['vod_pic_screenshot']) && trim((string)$row['vod_pic_screenshot']) !== '') {
            $keep = [];
            foreach (explode('#', (string)$row['vod_pic_screenshot']) as $shot) {
                if (trim($shot) === '') { continue; }
                if (XvidClient::allowedUrl(trim($shot), $hosts, null, $host) !== null) { $keep[] = trim($shot); } else { $this->imageDropped($host); }
            }
            if ($keep) { $row['vod_pic_screenshot'] = implode('#', $keep); } else { unset($row['vod_pic_screenshot']); }
        }
        return $row;
    }

    private function imageDropped($host)
    {
        $this->stats['image_dropped']++;
        $host = is_string($host) ? substr($host, 0, 253) : '';
        if ($host !== '' && count($this->stats['image_hosts']) < 20) { $this->stats['image_hosts'][$host] = ($this->stats['image_hosts'][$host] ?? 0) + 1; }
    }

    public function resetStats() { $this->stats = ['rows' => 0, 'image_dropped' => 0, 'image_hosts' => []]; }

    /** Run one provider request (a callable using client()) through the pacer: the job engine's legacy AVDB paths. */
    public function paced(callable $fn) { return $this->call($fn); }

    /** D-P7: at most $max rows, only requested ids (each once), extra rows ignored. AvdbClient already refused malformed rows. */
    private static function cut(array $data, $max, ?array $requested = null)
    {
        $want = null;
        if ($requested !== null) { $want = array_fill_keys(array_map('intval', $requested), true); }
        $rows = []; $seen = []; $ignored = 0;
        foreach ((array)$data['list'] as $row) {
            $id = (int)$row['vod_id'];
            if (count($rows) >= $max || ($want !== null && !isset($want[$id])) || isset($seen[$id])) { $ignored++; continue; }
            $seen[$id] = true; $rows[] = $row;
        }
        $data['list'] = $rows; $data['ignored'] = $ignored; $data['rejected'] = [];
        return $data;
    }

    private function call(callable $fn)
    {
        if ($this->pacer) { $this->pacer->acquire(); $this->pacer->markSent(); }
        $started = microtime(true);
        try { $out = $fn(); }
        catch (\Throwable $e) { if ($this->pacer) { $this->pacer->failure($e); } throw $e; }
        if ($this->pacer) { $this->pacer->success((int)round((microtime(true) - $started) * 1000), 200); }
        return $out;
    }
}
