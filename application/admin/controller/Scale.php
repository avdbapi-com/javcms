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
namespace app\admin\controller;

use app\common\util\EdgeCache;
use app\common\util\FtIndex;
use app\common\util\PublicRequestGuard;
use app\common\util\RequestGate;
use app\common\util\ScaleCounts;
use app\common\util\ScaleTier;
use app\common\util\StorageGuard;
use think\Db;
use think\Loader;
use think\Request;

/**
 * "Speed & protection" (XVID-SCALE-BRIEF 3.8 step 2, lane E). One status line per area (search index, counts and
 * list indexes, protection, database space, cron), each with one action, and the details below.
 *
 * Reads only the published contracts (C1 FtIndex, C3 ScaleTier, C4 ScaleCounts, C5 EdgeCache, C13 StorageGuard) and,
 * through method_exists(), the optional status reads proposed in CONTRACT.md (lane E note): FtIndex::status() /
 * backfillStep(), ScaleSchema::status() / ensureIndexes(), EdgeCache::stats(), RequestGate::stats(),
 * Housekeeping::cronSeenAt(). A missing read shows "not reported yet"; nothing here guesses a number.
 *
 * Writes (POST + __token__, each answers with a fresh token): clear cache (index, op=clear), one search backfill step
 * (search: every tier; at T3 only while no cron / command-line run was seen in 24 h, else the page shows the command),
 * finish upgrade (upgrade; then the sitemap files, CONTRACT HO-H-R3-4), proxy settings (proxy: trust the current peer,
 * forget proxies, Cloudflare Tunnel switch).
 * The proxy address always comes from REMOTE_ADDR, never from the request body.
 *
 * xv-nolimit-E (XVID-NOLIMIT-BRIEF E3): line "Deep pages" from the optional state reads ListPageMap::state(),
 * ListRank::state(), FtIndex::mapState() (C14-C16): "Deep pages being prepared (N %)" until all three are ready; page
 * caps of 0 show "every page (no limit)". While the term map is not ready and housekeeping skipped its build because
 * PHP's memory limit stays below what the build needs (hk.json deep.ftmap_mem), the line and the "Term map" row name
 * that memory and show the command that builds it (php tools/search-index.php --map raises its own limit).
 *
 * xv-owner-E (owner decision 2026-10-17 08:40, E-O2): section "Protection" in plain words: turning visitors away when busy
 * (gate slots), per-visitor request limits (high defaults, ScaleTier::BUDGET_HEAVY / BUDGET_LIGHT when lane H's code has
 * them), query time limit, "Search engines are never blocked" (ScaleTier::settings()['crawlers'] and
 * PublicRequestGuard::crawlerStatus(): verified crawler list and the last refresh of the published IP lists) and "Under
 * attack mode" (app.under_attack, off by default; stricter app.attack_budget_heavy / _light, crawlers still allowed).
 * protect() saves the values (op=save | defaults | attack) into the app.* keys of application/extra/maccms.php.
 *
 * xv-fix-E-r5: "Finish upgrade" starts the sitemap child only when the sitemap blocks exist (engines-r5 R5-2: else "Sitemap
 * files: built by housekeeping after the sitemap blocks") and then refreshes the search engines' IP lists (CONTRACT
 * HO-H-R3-1, Housekeeping::refreshCrawlerLists); "Search engines are never blocked" names a list shipped in the package as
 * such (crawlerStatus() 'shipped'); a proxy notice of the User-Agent heuristic (header 'user-agents', HO-H-R4-2) has its
 * own text and no trust button.
 */
class Scale extends Base
{
    private $nextToken = null;
    private const PROXY_HEADERS = ['X-Forwarded-For' => 'HTTP_X_FORWARDED_FOR', 'X-Real-IP' => 'HTTP_X_REAL_IP', 'CF-Connecting-IP' => 'HTTP_CF_CONNECTING_IP'];

    public function index()
    {
        if ($this->request->isPost()) {
            return $this->clearCache();
        }
        $view = $this->collect();
        $title = lang('admin/ux/scale/menu_scale');
        $this->assign('title', $title);
        $this->assign('ax_head', ['title' => $title, 'help' => lang('admin/ux/scale/help_scale')]);
        $view['lines'] = self::lines($view);
        $view['details'] = self::details($view);
        $this->assign('sp', $view);
        $this->assign('sp_lines', $view['lines']);
        $this->assign('sp_details', $view['details']);
        $this->assign('sp_notices', $view['notices']);
        $this->assign('sp_cf', $view['cf']);
        $this->assign('sp_lock', $view['lock']);
        $this->assign('sp_hosting', $view['hosting']);
        $this->assign('sp_guard', $view['guard']);
        $this->assign('sp_guard_rows', self::guardRows($view['guard']));
        $this->assign('sp_js', json_encode([
            'tier' => $view['tier']['tier'],
            'search_cli' => $view['search']['cli'],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->assign('sp_lang_js', json_encode([
            'working' => lang('admin/ux/scale/sp_working'), 'copied' => lang('admin/ux/scale/es_copied'),
            'failed' => lang('admin/ux/scale/sp_failed'), 'invalid' => lang('admin/ux/scale/dbl_invalid'),
            'attack_confirm' => lang('admin/ux/scale/pr_attack_confirm'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->assign('__token__', Request::instance()->token('__token__'));
        return $this->fetch('admin@scale/index');
    }

    /**
     * One bounded backfill step of the search index. T3 with a cron / command-line run seen in the last 24 hours gets the
     * command (faster, CLI preferred); without one, T3 builds it in web steps too (S7: no catalog size is CLI-only).
     */
    public function search()
    {
        $guard = $this->postGuard();
        if ($guard !== null) { return $guard; }
        try {
            $tier = $this->tier();
            if ((int) $tier['tier'] >= 3 && self::cronFresh(self::cronSeenAt(), time())) {
                return $this->reply(0, self::t('admin/ux/scale/search_cli_only', ['command' => 'php tools/search-index.php --backfill']), ['cli' => 1]);
            }
            if (!method_exists(FtIndex::class, 'backfillStep')) {
                return $this->reply(0, lang('admin/ux/scale/sp_search_unavail'), []);
            }
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $r = (array) FtIndex::backfillStep(9);
            $out = ['done' => (int) ($r['done'] ?? 0), 'total' => (int) ($r['total'] ?? 0), 'finished' => !empty($r['finished'])];
            // Lane S answer codes (HO-E-5 / HO-S-3): '' = progress; the others are shown with their admin text.
            $code = (string) ($r['msg'] ?? '');
            if ($code === 'nothing_to_do') { $out['finished'] = true; return $this->reply(1, lang('admin/ux/scale/search_nothing'), $out); }
            $codes = ['busy' => 'search_busy', 'cli_only' => 'search_cli_only', 'space' => 'search_space', 'not_installed' => 'search_not_installed', 'lock_wait' => 'search_lock_wait'];
            if (isset($codes[$code])) {
                $vars = ['command' => (string) ($r['command'] ?? 'php tools/search-index.php --rebuild'), 'need' => number_format((int) ($r['need_mb'] ?? $r['need'] ?? 0)), 'free' => number_format((int) ($r['free_mb'] ?? $r['free'] ?? 0))];
                return $this->reply(0, self::t('admin/ux/scale/' . $codes[$code], $vars), $out + ['code' => $code]);
            }
            return $this->reply(1, $out['finished'] ? lang('admin/ux/scale/sp_search_done') : self::t('admin/ux/scale/sp_search_progress', ['done' => number_format($out['done']), 'total' => number_format($out['total'])]), $out);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * "Finish upgrade": the list indexes of brief 2.12 step 2b (ScaleSchema::ensureIndexes, lane H); once they are complete,
     * the sitemap files (prebuildSitemap()), the search index's term statistics and term map (refreshSearchStats()) and
     * the search engines' IP lists (refreshCrawlers()), each with its result line after "Upgrade finished.".
     */
    public function upgrade()
    {
        $guard = $this->postGuard();
        if ($guard !== null) { return $guard; }
        try {
            $cls = '\\app\\common\\util\\ScaleSchema';
            if (!class_exists($cls) || !method_exists($cls, 'ensureIndexes')) {
                return $this->reply(0, lang('admin/ux/scale/sp_upgrade_unavail'), []);
            }
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            ignore_user_abort(true);
            @set_time_limit(0);
            $cls::ensureIndexes();
            $status = method_exists($cls, 'status') ? (array) $cls::status() : [];
            if (!empty($status['running'])) {
                return $this->reply(1, lang('admin/ux/scale/sp_upgrade_running'), ['status' => $status]);
            }
            if (array_key_exists('indexes_ok', $status) && !$status['indexes_ok']) {
                return $this->reply(0, self::t('admin/ux/scale/sp_upgrade_needed', ['n' => count((array) ($status['missing'] ?? []))]), ['status' => $status]);
            }
            // 1.2.0 (CONTRACT HO-H-R3-4): the finished upgrade builds the sitemap files at once (30 s, child process), so a
            // search engine meets stored files instead of lite answers until housekeeping has built them
            $files = $this->prebuildSitemap();
            // 1.2.0: the term statistics and the term map of the search index are refreshed when they are due (an index that an
            // earlier 1.2.0 build made, many new videos, a week old; child process, 60 s), so the proven single-word tag pages
            // and the exact performer lists work
            $stats = $this->refreshSearchStats();
            // 1.2.0 (CONTRACT HO-H-R3-1): then the search engines' current IP lists replace the lists shipped in the package
            $crawl = $this->refreshCrawlers();
            $msg = self::sentence(self::sentence(self::sentence(lang('admin/ux/scale/sp_upgrade_done'), $files['line']), $stats['line']), $crawl['line']);
            return $this->reply(1, $msg, ['status' => $status, 'sitemap_files' => $files['result'], 'search_stats' => $stats['result'], 'crawler_lists' => $crawl['result']]);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * 1.2.0 (CONTRACT HO-H-R3-4, site-r3 N20 (b)): after a finished upgrade the sitemap files are built at once
     * (Housekeeping::prebuildSitemapFiles: the index, then the video files whose stored copy is missing or old, newest first,
     * in a child process within 30 s + its grace), so a search engine meets stored files instead of the lite answers of
     * never-built files until housekeeping has built them. The scheme of this admin request goes along for sites whose
     * canonical scheme is "auto" (a command line cannot resolve it). Never throws: ['line' => text for the reply ('' when
     * the method is missing), 'result' => the scalar result fields or null].
     * engines-r5 R5-2: the files are built from the sitemap blocks. Before the blocks exist for the current file size (a
     * 1.1.0 site: tools/upgrade.php step 5 or housekeeping builds them, on a shared host usually after this button) the child
     * would only stop with "Sitemap buckets are not built yet", so it is not started (sitemapBlocks()) and the reply says
     * that housekeeping builds the files after the blocks (state 'blocks'); the same answer when the child reports it.
     */
    private function prebuildSitemap()
    {
        $cls = '\\app\\common\\util\\Housekeeping';
        if (!class_exists($cls) || !method_exists($cls, 'prebuildSitemapFiles')) {
            return ['line' => '', 'result' => null];
        }
        $blocks = self::sitemapBlocks();
        if ($blocks !== '') {
            $keep = ['state' => 'blocks', 'why' => $blocks];
            return ['line' => self::sitemapLine($keep), 'result' => $keep];
        }
        try {
            $root = defined('ROOT_PATH') ? ROOT_PATH : ScaleTier::root();
            $raw = (array) $cls::prebuildSitemapFiles($root, 30, Request::instance()->isSsl() ? 'https' : 'http');
        } catch (\Throwable $e) {
            $raw = ['state' => 'error'];
        }
        // the child found no blocks after all (removed meanwhile, or the small-site build in the child did not finish)
        if (($raw['state'] ?? '') === 'error' && empty($raw['built']) && strpos((string) ($raw['error'] ?? ''), 'Sitemap buckets are not built yet') !== false) {
            $raw = ['state' => 'blocks', 'why' => 'child', 'seconds' => $raw['seconds'] ?? null, 'via' => $raw['via'] ?? null];
        }
        $keep = [];
        foreach (['state', 'built', 'lang_built', 'fresh', 'left', 'wanted', 'seconds', 'via', 'why'] as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) { $keep[$key] = $raw[$key]; }
        }
        return ['line' => self::sitemapLine($keep), 'result' => $keep];
    }

    /**
     * engines-r5 R5-2: '' when the sitemap blocks are built for the current file size (Sitemap::bucketState() 'built' and
     * its 'sig' = Sitemap::bucketConfig()['sig']) or when the child can build them itself (Sitemap::bucketTable()'s inline
     * build on a small site: tier 1 and MAX(vod_id) below ScaleTier::T2_ROWS, checked against the real table as there);
     * else why not: 'not_built' (no blocks yet) or 'sig' (blocks of another file size: rebuilt by housekeeping). '' also
     * when the state cannot be read (an older Sitemap class, a read error): then the child decides, as before.
     */
    private static function sitemapBlocks()
    {
        $cls = '\\app\\common\\util\\Sitemap';
        try {
            if (!class_exists($cls) || !method_exists($cls, 'bucketState') || !method_exists($cls, 'bucketConfig')) { return ''; }
            $state = (array) $cls::bucketState();
            $cfg = (array) $cls::bucketConfig();
            if (!empty($state['built']) && (string) ($state['sig'] ?? '') === (string) ($cfg['sig'] ?? '')) { return ''; }
            $t2 = self::tierConst('T2_ROWS', 200000);
            $tier = ScaleTier::get();
            if ((int) ($tier['tier'] ?? 0) === 1 && (int) ($tier['rows'] ?? 0) < $t2) {
                $r = Db::query('SELECT MAX(vod_id) AS m FROM ' . (string) config('database.prefix') . 'vod');
                if (isset($r[0]) && (int) $r[0]['m'] < $t2) { return ''; }
            }
            return empty($state['built']) ? 'not_built' : 'sig';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The reply line of a prebuildSitemapFiles() result (the facts of tools/upgrade.php step 5c, translated). */
    private static function sitemapLine(array $r)
    {
        $state = (string) ($r['state'] ?? '');
        // engines-r5 R5-2: no blocks yet ('blocks_pending': the same answer from a Sitemap::prebuild() that reports it)
        if (in_array($state, ['blocks', 'blocks_pending'], true)) {
            return lang('admin/ux/scale/sp_sitemap_blocks');
        }
        if (isset($r['built']) && in_array($state, ['done', 'error'], true)) {
            $n = [(int) $r['built'], (int) ($r['lang_built'] ?? 0), (int) ($r['fresh'] ?? 0), (int) ($r['left'] ?? 0)];
            if ($n[0] + $n[2] + $n[3] > 0) {
                $line = self::t('admin/ux/scale/sp_sitemap_built', ['built' => number_format($n[0]), 'lang' => number_format($n[1]), 'fresh' => number_format($n[2]), 'left' => number_format($n[3])]);
                return $state === 'error' ? self::sentence($line, lang('admin/ux/scale/sp_sitemap_stopped')) : $line;
            }
            if ($state === 'done') {
                return lang('admin/ux/scale/sp_sitemap_index');
            }
        }
        // literal keys, so the language tools see every key in use
        $why = ['no_cli' => lang('admin/ux/scale/sp_sitemap_why_no_cli'), 'disabled' => lang('admin/ux/scale/sp_sitemap_why_disabled'),
            'timeout' => lang('admin/ux/scale/sp_sitemap_why_timeout'), 'busy' => lang('admin/ux/scale/sp_sitemap_why_busy'),
            'hidden' => lang('admin/ux/scale/sp_sitemap_why_hidden'), 'error' => lang('admin/ux/scale/sp_sitemap_why_error')];
        $reason = isset($why[$state]) ? $why[$state] : (preg_replace('/[^a-z_]/', '', $state) ?: '?');
        return self::t('admin/ux/scale/sp_sitemap_later', ['why' => $reason]);
    }

    /**
     * 1.2.0: after a finished upgrade the search index's term statistics are refreshed when they are due (Housekeeping::refreshSearchStats(ROOT_PATH, 60): a child process
     * tools/housekeeping.php --search-stats-only; due = statistics of an earlier 1.2.0 build, more than 5 % new videos, or
     * older than a week), then the term map when it is missing or older than them and its estimate fits the 60 s (else
     * housekeeping rebuilds it: a map older than the statistics counts as missing there). Never throws: ['line' => text for
     * the reply ('' when nothing was due or the method is missing), 'result' => scalar facts or null].
     */
    private function refreshSearchStats()
    {
        $cls = '\\app\\common\\util\\Housekeeping';
        if (!class_exists($cls) || !method_exists($cls, 'refreshSearchStats')) {
            return ['line' => '', 'result' => null];
        }
        try {
            $root = defined('ROOT_PATH') ? ROOT_PATH : ScaleTier::root();
            $raw = (array) $cls::refreshSearchStats($root, 60);
        } catch (\Throwable $e) {
            $raw = ['state' => 'error'];
        }
        $keep = [];
        foreach (['state', 'terms', 'exact', 'map', 'map_mb', 'map_est_s', 'seconds', 'via', 'why'] as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) { $keep[$key] = $raw[$key]; }
        }
        return ['line' => self::statsLine($keep), 'result' => $keep];
    }

    /**
     * The reply line of a refreshSearchStats() result: "Search statistics refreshed (N terms)." plus
     * "Term map rebuilt." or "Housekeeping rebuilds the term map (or run php tools/search-index.php --map now)."; nothing
     * when the statistics were current or the search index is not built (its backfill writes fresh statistics), except
     * "Term map rebuilt." when a missing map was built; else "Search statistics were not refreshed (<why>): run
     * php tools/search-index.php --terms and then --map once."
     */
    private static function statsLine(array $r)
    {
        $state = (string) ($r['state'] ?? '');
        $map = (string) ($r['map'] ?? '');
        $mapLine = '';
        if ($map === 'built') {
            $mapLine = lang('admin/ux/scale/sp_stats_map_built');
        } elseif (in_array($map, ['left', 'busy', 'building', 'error'], true)) {
            $mapLine = lang('admin/ux/scale/sp_stats_map_later');
        }
        if ($state === 'refreshed') {
            return self::sentence(self::t('admin/ux/scale/sp_stats_done', ['n' => number_format((int) ($r['terms'] ?? 0))]), $mapLine);
        }
        if (in_array($state, ['current', 'not_ready', 'not_installed', ''], true)) {
            return $map === 'built' ? $mapLine : '';
        }
        // literal keys, so the language tools see every key in use
        $why = ['busy' => lang('admin/ux/scale/sp_stats_why_busy'), 'left' => lang('admin/ux/scale/sp_stats_why_left'),
            'timeout' => lang('admin/ux/scale/sp_stats_why_timeout'), 'no_cli' => lang('admin/ux/scale/sp_sitemap_why_no_cli'),
            'disabled' => lang('admin/ux/scale/sp_sitemap_why_disabled'), 'no_database' => lang('admin/ux/scale/sp_stats_why_no_database'),
            'error' => lang('admin/ux/scale/sp_stats_why_error')];
        $reason = isset($why[$state]) ? $why[$state] : (preg_replace('/[^a-z_]/', '', $state) ?: '?');
        return self::t('admin/ux/scale/sp_stats_failed', ['why' => $reason]);
    }

    /**
     * 1.2.0 (CONTRACT HO-H-R3-1, verify r3 OW1 (b)): after a finished upgrade the search engines' current IP lists replace
     * the lists shipped in the package (Housekeeping::refreshCrawlerLists(ROOT_PATH, 10): one run of the 'crawlers' and
     * 'crawler_dns' tasks; an engine whose download fails keeps its shipped or last good list). Never throws:
     * ['line' => text for the reply ('' when nothing was downloaded: method missing, housekeeping busy, no engine with a
     * published list), 'result' => scalar facts or null].
     */
    private function refreshCrawlers()
    {
        $cls = '\\app\\common\\util\\Housekeeping';
        if (!class_exists($cls) || !method_exists($cls, 'refreshCrawlerLists')) {
            return ['line' => '', 'result' => null];
        }
        try {
            $root = defined('ROOT_PATH') ? ROOT_PATH : ScaleTier::root();
            return self::crawlerLine((array) $cls::refreshCrawlerLists($root, 10));
        } catch (\Throwable $e) {
            return ['line' => '', 'result' => ['state' => 'error']];
        }
    }

    /**
     * The reply line of a Housekeeping::refreshCrawlerLists() result: "Search engine IP lists refreshed: N of M." (+ the
     * others keep their shipped list), or that none could be downloaded. Result: state and ms of the housekeeping run, ok,
     * total, failed (engine names), why (when no list task ran).
     */
    private static function crawlerLine(array $r)
    {
        $run = isset($r['run']) && is_array($r['run']) ? $r['run'] : [];
        $tasks = isset($run['tasks']) && is_array($run['tasks']) ? $run['tasks'] : [];
        $out = ['state' => (string) ($run['state'] ?? ''), 'ms' => (int) ($run['ms'] ?? 0)];
        $lists = isset($tasks['crawlers']) && is_array($tasks['crawlers']) ? $tasks['crawlers'] : null;
        if ($lists === null) {
            // housekeeping busy (its lock is held), skipped, or no list task: the daily refresh downloads them
            $why = isset($tasks['crawlers']) && is_scalar($tasks['crawlers']) ? (string) $tasks['crawlers'] : (string) ($run['why'] ?? '');
            return ['line' => '', 'result' => $out + ['why' => preg_replace('/[^a-z_ ]/', '', strtolower($why))]];
        }
        $ok = 0;
        $failed = [];
        foreach ($lists as $engine => $one) {
            if (is_array($one) && !empty($one['ok'])) { $ok++; } else { $failed[] = preg_replace('/[^a-z]/', '', strtolower((string) $engine)); }
        }
        $total = count($lists);
        $out += ['ok' => $ok, 'total' => $total, 'failed' => implode(',', $failed)];
        if ($total === 0) {
            return ['line' => '', 'result' => $out];
        }
        if ($ok === 0) {
            return ['line' => lang('admin/ux/scale/sp_crawl_failed'), 'result' => $out];
        }
        $line = self::t('admin/ux/scale/sp_crawl_done', ['ok' => $ok, 'total' => $total]);
        return ['line' => $ok < $total ? self::sentence($line, lang('admin/ux/scale/sp_crawl_rest')) : $line, 'result' => $out];
    }

    /** $a and $b as two sentences: a space between them, none after a CJK full stop (U+3002, U+FF01, U+FF1F). */
    private static function sentence($a, $b)
    {
        return $b === '' ? $a : $a . (preg_match('/[\x{3002}\x{FF01}\x{FF1F}]$/u', $a) ? '' : ' ') . $b;
    }

    /** Proxy settings: op=trust (the current peer + one header it sent), op=forget, op=tunnel (on=0|1). */
    public function proxy()
    {
        $guard = $this->postGuard();
        if ($guard !== null) { return $guard; }
        try {
            $op = (string) input('post.op', '');
            if ($op === 'tunnel') {
                $on = (string) input('post.on', '0') === '1' ? 1 : 0;
                self::writeApp(['cf_tunnel' => $on]);
                if ($on) { self::clearProxyNotice(''); }
                return $this->reply(1, lang($on ? 'admin/ux/scale/sp_tunnel_on' : 'admin/ux/scale/sp_tunnel_off'), ['on' => $on]);
            }
            if ($op === 'trust') {
                $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
                $header = (string) input('post.header', '');
                if (!isset(self::PROXY_HEADERS[$header]) || empty($_SERVER[self::PROXY_HEADERS[$header]])) {
                    throw new \InvalidArgumentException(lang('admin/ux/scale/sp_proxy_header_missing'));
                }
                $cidr = self::peerCidr($peer);
                if ($cidr === '') {
                    throw new \InvalidArgumentException(lang('admin/ux/scale/sp_proxy_public'));
                }
                $custom = self::guardCustom();
                $list = isset($custom['trusted_proxies']) && is_array($custom['trusted_proxies']) ? array_values($custom['trusted_proxies']) : [];
                if (!in_array($cidr, $list, true)) {
                    if (count($list) >= 64) {
                        throw new \RuntimeException(lang('admin/ux/scale/sp_proxy_full'));
                    }
                    $list[] = $cidr;
                }
                $custom['trusted_proxies'] = $list;
                $custom['proxy_header'] = $header;
                self::writeGuardCustom($custom);
                self::clearProxyNotice($peer);
                return $this->reply(1, self::t('admin/ux/scale/sp_proxy_trusted', ['ip' => $cidr, 'h' => $header]), ['trusted' => $list, 'header' => $header]);
            }
            if ($op === 'forget') {
                $custom = self::guardCustom();
                unset($custom['trusted_proxies'], $custom['proxy_header']);
                self::writeGuardCustom($custom);
                return $this->reply(1, lang('admin/ux/scale/sp_proxy_forgot'), ['trusted' => []]);
            }
            throw new \InvalidArgumentException(lang('param_err'));
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * Protection values (owner decision 2026-10-17 08:40: protections on with high values, crawlers never blocked):
     * op=save (heavy, light, stmt, attack_heavy, attack_light; '' = the default), op=defaults (all five back to the default),
     * op=attack (on=0|1: Under attack mode). Only these app.* keys are written; 0 is refused (the limits stay on).
     */
    public function protect()
    {
        $guard = $this->postGuard();
        if ($guard !== null) { return $guard; }
        try {
            $op = (string) input('post.op', '');
            $g = $this->guardView($this->tier());
            if ($op === 'attack') {
                if (!$g['attack_supported']) {
                    throw new \RuntimeException(lang('admin/ux/scale/pr_attack_unsupported'));
                }
                $on = (string) input('post.on', '0') === '1' ? 1 : 0;
                self::writeApp(['under_attack' => $on, 'under_attack_at' => $on ? time() : '']);
                return $this->reply(1, lang($on ? 'admin/ux/scale/pr_attack_saved_on' : 'admin/ux/scale/pr_attack_saved_off'), ['on' => $on]);
            }
            if ($op === 'defaults') {
                self::writeApp(['budget_heavy' => '', 'budget_light' => '', 'stmt_limit' => '', 'attack_budget_heavy' => '', 'attack_budget_light' => '']);
                return $this->reply(1, lang('admin/ux/scale/pr_reset_done'), []);
            }
            if ($op !== 'save') {
                throw new \InvalidArgumentException(lang('param_err'));
            }
            $vals = [
                'budget_heavy' => self::intField('heavy', 'admin/ux/scale/pr_f_heavy', 10, 100000),
                'budget_light' => self::intField('light', 'admin/ux/scale/pr_f_light', 10, 100000),
                'stmt_limit' => self::intField('stmt', 'admin/ux/scale/pr_f_stmt', 1, 600),
            ];
            if ($g['attack_supported']) {
                $vals['attack_budget_heavy'] = self::intField('attack_heavy', 'admin/ux/scale/pr_f_attack_heavy', 1, 100000);
                $vals['attack_budget_light'] = self::intField('attack_light', 'admin/ux/scale/pr_f_attack_light', 1, 100000);
                $heavy = $vals['budget_heavy'] === '' ? $g['default']['heavy'] : $vals['budget_heavy'];
                $light = $vals['budget_light'] === '' ? $g['default']['light'] : $vals['budget_light'];
                $aHeavy = $vals['attack_budget_heavy'] === '' ? $g['attack_default']['heavy'] : $vals['attack_budget_heavy'];
                $aLight = $vals['attack_budget_light'] === '' ? $g['attack_default']['light'] : $vals['attack_budget_light'];
                if ($aHeavy > $heavy || $aLight > $light) {
                    throw new \InvalidArgumentException(lang('admin/ux/scale/pr_bad_attack'));
                }
            }
            self::writeApp($vals);
            return $this->reply(1, lang('admin/ux/scale/pr_saved'), ['values' => $vals]);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    private function clearCache()
    {
        $guard = $this->postGuard();
        if ($guard !== null) { return $guard; }
        try {
            if ((string) input('post.op', '') !== 'clear') {
                throw new \InvalidArgumentException(lang('param_err'));
            }
            $ok = $this->_cache_clear();
            // C5: the admin "Clear cache" is one of the two callers allowed to bump the page-cache generation (never imports).
            EdgeCache::bumpGeneration(true);
            return $this->reply($ok ? 1 : 0, lang($ok ? 'admin/ux/scale/sp_cleared' : 'admin/ux/shell/cache_failed'), []);
        } catch (\Throwable $e) {
            return $this->failure($e);
        }
    }

    // ---------------------------------------------------------------- status collection (read only)

    private function tier()
    {
        try { $t = ScaleTier::get(); } catch (\Throwable $e) { $t = []; }
        $t = is_array($t) ? $t : [];
        $t += ['tier' => 1, 'rows' => 0, 'list_cap' => 0, 'by_cap' => 0, 'search_cap' => 0, 'stmt_limit' => 0, 'walk_cap' => 0,
            'slots' => ['search' => 0, 'deep' => 0, 'light' => 0], 'budget' => ['heavy' => 30, 'light' => 240], 'edge_max_mb' => 0, 'edge_max_files' => 0];
        $t['tier'] = max(1, min(3, (int) $t['tier']));
        return $t;
    }

    private static function optional($class, $method)
    {
        try {
            if (class_exists($class) && method_exists($class, $method)) {
                $r = call_user_func([$class, $method]);
                return $r;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    private function collect()
    {
        $now = time();
        $tier = $this->tier();
        $prefix = (string) config('database.prefix');
        $cronSeen = self::cronSeenAt();
        $cronFresh = self::cronFresh($cronSeen, $now);

        // Catalog size: exact count from C4, else the tier rows, else the cheap information_schema estimate.
        $rows = null;
        try { $rows = ScaleCounts::all(); } catch (\Throwable $e) { $rows = null; }
        $rowsExact = $rows !== null;
        if ($rows === null && (int) $tier['rows'] > 0) { $rows = (int) $tier['rows']; }
        if ($rows === null) {
            try {
                $r = Db::query('SELECT TABLE_ROWS AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$prefix . 'vod']);
                $rows = isset($r[0]['n']) ? (int) $r[0]['n'] : null;
            } catch (\Throwable $e) { $rows = null; }
        }

        // Search index (C1 + optional status).
        $ftReady = false;
        try { $ftReady = FtIndex::ready(); } catch (\Throwable $e) { $ftReady = false; }
        $ft = self::optional(FtIndex::class, 'status');
        $ft = is_array($ft) ? $ft : [];
        if (array_key_exists('installed', $ft) && !$ft['installed']) { $ft['mode'] = 'old'; }
        $search = [
            'ready' => $ftReady,
            'mode' => isset($ft['mode']) ? (string) $ft['mode'] : ($ftReady ? 'ft' : 'old'),
            'docs' => isset($ft['docs']) ? (int) $ft['docs'] : null,
            'pending' => isset($ft['pending']) ? (int) $ft['pending'] : null,
            'rebuild_due' => isset($ft['rebuild_due']) ? (string) $ft['rebuild_due'] : '',
            'terms_at' => isset($ft['terms_at']) ? (int) $ft['terms_at'] : 0,
            'ms_per_read' => isset($ft['ms_per_read']) ? (float) $ft['ms_per_read'] : null,
            'step' => method_exists(FtIndex::class, 'backfillStep'),
            // T3: the command line while cron runs (faster); without cron the web steps build it too (S7).
            'cli' => (int) $tier['tier'] >= 3 && $cronFresh,
            'web_t3' => (int) $tier['tier'] >= 3 && !$cronFresh,
            'cmd' => 'php tools/search-index.php --backfill',
        ];

        // Deep pages (C14-C16, optional until lanes S / H land them): page map, rank files, term map.
        $deep = [
            'page' => self::mapState(self::optional('\\app\\common\\util\\ListPageMap', 'state')),
            'rank' => self::mapState(self::optional('\\app\\common\\util\\ListRank', 'state')),
            'term' => self::mapState(self::optional(FtIndex::class, 'mapState')),
        ];
        $deepMem = $deep['term']['known'] && !$deep['term']['ready'] ? self::termMapMemory() : null;

        // Counts (C4) and list indexes (ScaleSchema, optional).
        $fresh = 0;
        try { $fresh = (int) ScaleCounts::freshness(); } catch (\Throwable $e) { $fresh = 0; }
        $schema = self::optional('\\app\\common\\util\\ScaleSchema', 'status');
        $schema = is_array($schema) ? $schema : null;
        $counts = [
            'freshness' => $fresh,
            'schema_known' => $schema !== null,
            'indexes_ok' => $schema === null ? null : !empty($schema['indexes_ok']),
            'missing' => $schema === null ? [] : array_values(array_map('strval', (array) ($schema['missing'] ?? []))),
            'est_minutes' => $schema !== null && isset($schema['est_minutes']) ? (float) $schema['est_minutes'] : null,
            'running' => $schema !== null && !empty($schema['running']),
            'can_upgrade' => class_exists('\\app\\common\\util\\ScaleSchema') && method_exists('\\app\\common\\util\\ScaleSchema', 'ensureIndexes'),
        ];

        // Protection: edge cache, gates, proxies.
        $edge = self::optional(EdgeCache::class, 'stats');
        $edge = is_array($edge) ? $edge : null;
        $gate = self::optional(RequestGate::class, 'stats');
        $gate = is_array($gate) ? $gate : null;
        $custom = self::guardCustom();
        $trusted = isset($custom['trusted_proxies']) && is_array($custom['trusted_proxies']) ? array_values($custom['trusted_proxies']) : [];
        $tunnel = !empty($GLOBALS['config']['app']['cf_tunnel']);
        $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $seenHeaders = [];
        foreach (self::PROXY_HEADERS as $name => $key) { if (!empty($_SERVER[$key])) { $seenHeaders[] = $name; } }
        $proxyNotice = null;
        if ($gate !== null && !empty($gate['proxy_notice']) && is_array($gate['proxy_notice'])) {
            // CONTRACT HO-H-R4-2: header 'user-agents' = the User-Agent heuristic (a public peer sending most uncached pages with
            // many browsers: a CDN or firewall?); its budget stays per address, so it gets its own text and no trust button
            $noticeHeaders = array_map('strval', (array) ($gate['proxy_notice']['headers'] ?? []));
            $proxyNotice = ['peer' => (string) ($gate['proxy_notice']['peer'] ?? ''), 'headers' => array_values(array_intersect(array_keys(self::PROXY_HEADERS), $noticeHeaders)),
                'kind' => in_array('user-agents', $noticeHeaders, true) ? 'user-agents' : 'header'];
        } elseif (self::peerCidr($peer) !== '' && $seenHeaders && !$trusted && !$tunnel) {
            // This admin request itself came through an unconfigured proxy (brief 2.10 L5): every visitor shares this address.
            $proxyNotice = ['peer' => $peer, 'headers' => $seenHeaders, 'kind' => 'header'];
        }
        $flockOk = $gate !== null && array_key_exists('flock_ok', $gate) && $gate['flock_ok'] !== null ? (bool) $gate['flock_ok'] : self::flockSelfTest();
        $topKeys = [];
        if ($gate !== null && !empty($gate['top_keys']) && is_array($gate['top_keys'])) {
            foreach (array_slice($gate['top_keys'], 0, 5) as $k) { if (is_array($k)) { $topKeys[] = ['key' => (string) ($k['key'] ?? ''), 'n' => (int) ($k['n'] ?? 0)]; } }
        }
        $protection = [
            'edge' => $edge === null ? null : [
                'enabled' => !empty($edge['enabled']),
                'hit_rate' => isset($edge['hit_rate']) ? (float) $edge['hit_rate'] : null,
                'mb' => isset($edge['mb']) ? (float) $edge['mb'] : null,
                'files' => isset($edge['files']) ? (int) $edge['files'] : null,
                'max_mb' => (int) ($edge['max_mb'] ?? $tier['edge_max_mb']),
                'max_files' => (int) ($edge['max_files'] ?? $tier['edge_max_files']),
            ],
            'r429_hour' => $gate !== null && isset($gate['r429_hour']) ? (int) $gate['r429_hour'] : null,
            'top_keys' => $topKeys,
            'trusted' => $trusted,
            'header' => (string) ($custom['proxy_header'] ?? 'X-Forwarded-For'),
            'tunnel' => $tunnel,
            'notice' => $proxyNotice,
            'flock_ok' => $flockOk,
            'peer' => $peer,
            'mobile_split' => !empty($GLOBALS['config']['site']['mob_status']) && (string) ($GLOBALS['config']['site']['mob_template_dir'] ?? '') !== '' && (string) ($GLOBALS['config']['site']['mob_template_dir'] ?? '') !== (string) ($GLOBALS['config']['site']['template_dir'] ?? ''),
        ];

        // Database space (C13), with the schema size measured here when the guard does not report it.
        try { $sg = StorageGuard::status(); } catch (\Throwable $e) { $sg = []; }
        $sg = is_array($sg) ? $sg : [];
        $dbMb = isset($sg['db_mb']) ? (int) $sg['db_mb'] : null;
        if ($dbMb === null) {
            try {
                $r = Db::query('SELECT SUM(DATA_LENGTH + INDEX_LENGTH) AS b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
                $dbMb = isset($r[0]['b']) ? (int) round(((float) $r[0]['b']) / 1048576) : null;
            } catch (\Throwable $e) { $dbMb = null; }
        }
        $limitMb = isset($sg['limit_mb']) ? (int) $sg['limit_mb'] : null;
        if ($limitMb === null) {
            $gb = (string) ($GLOBALS['config']['app']['db_limit_gb'] ?? '');
            if ($gb !== '' && is_numeric($gb) && (float) $gb > 0) { $limitMb = (int) round((float) $gb * 1024); }
        }
        $full = false;
        try { $full = StorageGuard::isFull(); } catch (\Throwable $e) { $full = false; }
        // HO-P-F3: limitMb() is null for '' (never asked) and 'auto'; '' shows "Database limit: not set".
        $limitSetting = null;
        try { if (method_exists(StorageGuard::class, 'limitSetting')) { $limitSetting = (string) StorageGuard::limitSetting(); } } catch (\Throwable $e) { $limitSetting = null; }
        $storage = ['db_mb' => $dbMb, 'limit_mb' => $limitMb, 'setting' => $limitSetting, 'full' => $full, 'free_disk_mb' => isset($sg['free_disk_mb']) ? (int) $sg['free_disk_mb'] : null, 'reason' => (string) ($sg['reason'] ?? '')];

        // Cron: housekeeping heartbeat (H, optional) and the importer's command-line heartbeat (P's table).
        $cron = ['seen_at' => $cronSeen, 'fresh' => $cronFresh,
            'line' => '*/5 * * * * ' . self::phpBinary() . ' ' . self::siteRoot() . 'tools/housekeeping.php >/dev/null 2>&1',
            'import_line' => '*/5 * * * * ' . self::phpBinary() . ' ' . self::siteRoot() . 'tools/import.php --run --max-minutes=4 >/dev/null 2>&1'];

        // Protection in plain words (owner decision 2026-10-17 08:40).
        $guard = $this->guardView($tier);

        // Notices (brief 3.8 step 2, red).
        $notices = [];
        if ($guard['attack_on']) { $notices[] = ['warn', lang('admin/ux/scale/nt_attack')]; }
        if (!$search['ready']) { $notices[] = ['danger', lang('admin/ux/scale/nt_build')]; }
        if ($search['rebuild_due'] !== '') { $notices[] = ['warn', lang('admin/ux/scale/nt_approx')]; }
        if ((int) $tier['tier'] >= 3 && !$cron['fresh']) { $notices[] = ['danger', lang('admin/ux/scale/nt_cron')]; }
        if ($protection['notice'] !== null) { $notices[] = self::proxyNoticeText($protection['notice']); }
        if ($storage['full']) { $notices[] = ['danger', self::t('admin/ux/scale/nt_full', ['now' => self::mb($dbMb), 'limit' => self::mb($limitMb)])]; }
        if ($flockOk === false) { $notices[] = ['danger', lang('admin/ux/scale/nt_flock')]; }
        if ($protection['mobile_split']) { $notices[] = ['warn', lang('admin/ux/scale/nt_mobile')]; }

        $entry = basename((string) Request::instance()->baseFile());
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}\.php$/', $entry)) { $entry = 'admin.php'; }
        $dir = rtrim((string) ($GLOBALS['config']['site']['install_dir'] ?? '/'), '/');
        if ($dir !== '' && !preg_match('~^(?:/[A-Za-z0-9_-]+)+$~D', $dir)) { $dir = ''; }

        return [
            'tier' => $tier, 'rows' => $rows, 'rows_exact' => $rowsExact, 'search' => $search, 'counts' => $counts, 'deep' => $deep,
            'protection' => $protection, 'storage' => $storage, 'cron' => $cron, 'notices' => $notices, 'guard' => $guard,
            'cf' => self::cloudflareRules($dir, $entry), 'lock' => self::originLock(), 'hosting' => self::hostingRow($rows),
            'entry' => $entry, 'now' => $now, 'deep_mem' => $deepMem,
        ];
    }

    // ---------------------------------------------------------------- status lines and details (all text built here, escaped in the view)

    private static function ago($ts, $now)
    {
        $ts = (int) $ts;
        if ($ts <= 0) { return lang('admin/ux/scale/sp_never'); }
        $s = max(0, $now - $ts);
        if ($s < 3600) { return self::t('admin/ux/scale/ago_min', ['n' => max(1, (int) round($s / 60))]); }
        if ($s < 172800) { return self::t('admin/ux/scale/ago_h', ['n' => (int) round($s / 3600)]); }
        return self::t('admin/ux/scale/ago_d', ['n' => (int) round($s / 86400)]);
    }

    private static function minutes($min)
    {
        $min = (float) $min;
        return $min < 60 ? self::t('admin/ux/scale/es_min', ['n' => max(1, (int) ceil($min))]) : self::t('admin/ux/scale/es_h', ['n' => number_format($min / 60, 1)]);
    }

    /** One line per area: state (ok | warn | danger | off), title, text, action (type + data). */
    private static function lines(array $v)
    {
        $now = $v['now'];
        $L = [];
        // 1. Search index.
        $s = $v['search'];
        $line = ['area' => 'search', 'title' => lang('admin/ux/scale/sp_search'), 'state' => 'ok', 'text' => '', 'action' => 'none', 'label' => '', 'cmd' => ''];
        if (!$s['ready']) {
            $line['state'] = 'danger';
            $line['text'] = lang('admin/ux/scale/sp_search_missing');
        } else {
            $line['text'] = self::t('admin/ux/scale/sp_search_ready', ['docs' => $s['docs'] !== null ? number_format($s['docs']) : lang('admin/ux/scale/sp_not_reported')]);
            if ($s['pending']) { $line['text'] .= ' ' . self::t('admin/ux/scale/sp_search_pending', ['n' => number_format($s['pending'])]); $line['state'] = 'warn'; }
            if ($s['rebuild_due'] !== '') { $line['text'] .= ' ' . lang('admin/ux/scale/nt_approx'); $line['state'] = 'warn'; }
        }
        if ($s['cli']) { $line['action'] = 'cmd'; $line['cmd'] = $s['cmd']; $line['label'] = self::t('admin/ux/scale/sp_search_cli', ['cmd' => '']); }
        elseif ($s['step']) {
            $line['action'] = 'search';
            $line['label'] = lang($s['ready'] ? 'admin/ux/scale/sp_search_rebuild' : 'admin/ux/scale/sp_search_build');
            if ($s['web_t3']) { $line['hint'] = self::t('admin/ux/scale/sp_search_web', ['cmd' => $s['cmd']]); }
        }
        else { $line['action'] = 'note'; $line['label'] = lang('admin/ux/scale/sp_search_unavail'); }
        $L[] = $line;
        // 2. Counts and list indexes.
        $c = $v['counts'];
        $line = ['area' => 'counts', 'title' => lang('admin/ux/scale/sp_counts'), 'state' => 'ok', 'text' => '', 'action' => 'none', 'label' => '', 'cmd' => ''];
        if ($c['schema_known'] && !$c['indexes_ok']) {
            $line['state'] = 'danger';
            $line['text'] = self::t('admin/ux/scale/sp_upgrade_needed', ['n' => count($c['missing'])]);
        } elseif ($c['freshness'] > 0) {
            $line['text'] = self::t('admin/ux/scale/sp_counts_ok', ['t' => self::ago($c['freshness'], $now)]);
        } else {
            $line['state'] = 'warn';
            $line['text'] = lang('admin/ux/scale/sp_counts_never');
        }
        if ($c['running']) { $line['text'] .= ' ' . lang('admin/ux/scale/sp_upgrade_running'); }
        if ($c['can_upgrade'] && !($c['schema_known'] && $c['indexes_ok'])) {
            $line['action'] = 'upgrade';
            $line['label'] = $c['est_minutes'] !== null ? self::t('admin/ux/scale/sp_upgrade_btn', ['t' => self::minutes($c['est_minutes'])]) : lang('admin/ux/scale/sp_upgrade_btn_plain');
        }
        $L[] = $line;
        // 2b. Deep pages: every list, sort and search page reachable (page map, rank files, term map).
        $L[] = self::deepLine($v['deep'], $v['deep_mem'] ?? null);
        // 3. Protection.
        $p = $v['protection'];
        $line = ['area' => 'protection', 'title' => lang('admin/ux/scale/sp_protection'), 'state' => 'ok', 'text' => lang('admin/ux/scale/sp_prot_on'), 'action' => 'clear', 'label' => lang('admin/ux/scale/sp_clear'), 'cmd' => ''];
        if ($p['edge'] !== null && $p['edge']['hit_rate'] !== null) { $line['text'] .= ' ' . self::t('admin/ux/scale/sp_prot_hit', ['p' => (int) round($p['edge']['hit_rate'] * 100)]); }
        if ($p['edge'] !== null && !$p['edge']['enabled']) { $line['state'] = 'warn'; $line['text'] = lang('admin/ux/scale/sp_prot_edge_off'); }
        if (!empty($v['guard']['attack_on'])) { $line['state'] = 'warn'; $line['text'] = lang('admin/ux/scale/sp_prot_attack'); }
        if ($p['notice'] !== null) { list($line['state'], $line['text']) = self::proxyNoticeText($p['notice']); }
        if ($p['flock_ok'] === false) { $line['state'] = 'danger'; $line['text'] .= ' ' . lang('admin/ux/scale/nt_flock'); }
        $line['proxy_headers'] = $p['notice'] !== null ? $p['notice']['headers'] : [];
        $L[] = $line;
        // 4. Database space.
        $st = $v['storage'];
        $line = ['area' => 'storage', 'title' => lang('admin/ux/scale/sp_storage'), 'state' => 'ok', 'text' => '', 'action' => 'dblimit', 'label' => lang('admin/ux/scale/sp_storage_set'), 'cmd' => ''];
        if ($st['db_mb'] === null) { $line['state'] = 'off'; $line['text'] = lang('admin/ux/scale/sp_storage_unknown'); }
        elseif ($st['limit_mb'] !== null) {
            $line['text'] = self::t('admin/ux/scale/sp_storage_v', ['now' => self::mb($st['db_mb']), 'limit' => self::mb($st['limit_mb'])]);
            if ($st['db_mb'] >= 0.8 * $st['limit_mb']) { $line['state'] = 'warn'; }
        } elseif ($st['setting'] === '') { $line['state'] = 'warn'; $line['text'] = self::t('admin/ux/scale/sp_storage_notset', ['now' => self::mb($st['db_mb'])]); }
        else { $line['text'] = self::t('admin/ux/scale/sp_storage_nolimit', ['now' => self::mb($st['db_mb'])]); }
        if ($st['full']) { $line['state'] = 'danger'; $line['text'] = self::t('admin/ux/scale/nt_full', ['now' => self::mb($st['db_mb']), 'limit' => self::mb($st['limit_mb'])]); }
        $L[] = $line;
        // 5. Cron.
        $cr = $v['cron'];
        $line = ['area' => 'cron', 'title' => lang('admin/ux/scale/sp_cron'), 'state' => $cr['fresh'] ? 'ok' : ((int) $v['tier']['tier'] >= 3 ? 'danger' : 'warn'), 'action' => 'cmd', 'label' => lang('admin/ux/scale/sp_cron_line'), 'cmd' => $cr['line'] . "\n" . $cr['import_line']];
        $line['text'] = $cr['fresh'] ? self::t('admin/ux/scale/sp_cron_ok', ['t' => self::ago($cr['seen_at'], $now)]) : ($cr['seen_at'] > 0 ? self::t('admin/ux/scale/sp_cron_old', ['t' => self::ago($cr['seen_at'], $now)]) : lang('admin/ux/scale/sp_cron_never'));
        $L[] = $line;
        foreach ($L as &$l) { $l['state_label'] = lang('admin/ux/scale/state_' . $l['state']); $l += ['proxy_headers' => [], 'hint' => '']; }
        unset($l);
        return $L;
    }

    /** Label / value rows under "Details". */
    private static function details(array $v)
    {
        $nr = lang('admin/ux/scale/sp_not_reported');
        $t = $v['tier'];
        $rows = [];
        $rows[] = [lang('admin/ux/scale/sp_tier'), self::t('admin/ux/scale/sp_tier_v', ['n' => $t['tier'], 'name' => lang('admin/ux/scale/sp_tier_' . $t['tier']), 'rows' => $v['rows'] !== null ? number_format($v['rows']) : $nr])];
        // Page caps: 0 = none (C3 note, XVID-NOLIMIT-BRIEF H1).
        foreach (['list_cap', 'by_cap', 'search_cap'] as $capKey) {
            $cap = (int) $t[$capKey];
            $rows[] = [lang('admin/ux/scale/sp_' . $capKey), $cap > 0 ? self::t('admin/ux/scale/sp_pages_v', ['n' => number_format($cap)]) : lang('admin/ux/scale/sp_pages_all')];
        }
        foreach (['page', 'rank', 'term'] as $mapKey) {
            $mapRow = self::mapText($v['deep'][$mapKey], $v['now']);
            if ($mapKey === 'term' && !empty($v['deep_mem'])) { $mapRow = self::sentence($mapRow, self::termMapMemoryText($v['deep_mem'])); }
            $rows[] = [lang('admin/ux/scale/sp_map_' . $mapKey), $mapRow];
        }
        $rows[] = [lang('admin/ux/scale/sp_stmt'), self::t('admin/ux/scale/sp_stmt_v', ['n' => (int) $t['stmt_limit']])];
        $sl = (array) $t['slots'];
        $rows[] = [lang('admin/ux/scale/sp_slots'), (int) ($sl['search'] ?? 0) . ' / ' . (int) ($sl['deep'] ?? 0) . ' / ' . (int) ($sl['light'] ?? 0)];
        $bu = (array) $t['budget'];
        $rows[] = [lang('admin/ux/scale/sp_budget'), self::t('admin/ux/scale/sp_budget_v', ['heavy' => (int) ($bu['heavy'] ?? 0), 'light' => (int) ($bu['light'] ?? 0)])];
        $e = $v['protection']['edge'];
        $rows[] = [lang('admin/ux/scale/sp_edge'), $e === null || $e['mb'] === null ? self::t('admin/ux/scale/sp_edge_caps', ['max_mb' => number_format((int) $t['edge_max_mb']), 'max_files' => number_format((int) $t['edge_max_files'])]) : self::t('admin/ux/scale/sp_edge_v', ['mb' => number_format($e['mb'], 1), 'max_mb' => number_format($e['max_mb']), 'files' => number_format((int) $e['files']), 'max_files' => number_format($e['max_files'])])];
        $rows[] = [lang('admin/ux/scale/sp_edge_hit'), $e !== null && $e['hit_rate'] !== null ? ((int) round($e['hit_rate'] * 100)) . ' %' : $nr];
        $p = $v['protection'];
        $rows[] = [lang('admin/ux/scale/sp_429'), $p['r429_hour'] !== null ? number_format($p['r429_hour']) : $nr];
        $keys = [];
        foreach ($p['top_keys'] as $k) { $keys[] = $k['key'] . ' (' . number_format($k['n']) . ')'; }
        $rows[] = [lang('admin/ux/scale/sp_top_keys'), $keys ? implode(', ', $keys) : $nr];
        $rows[] = [lang('admin/ux/scale/sp_trusted'), $p['trusted'] ? implode(', ', $p['trusted']) . ' · ' . $p['header'] : lang('admin/ux/scale/sp_trusted_none')];
        $rows[] = [lang('admin/ux/scale/sp_counts_fresh'), self::ago($v['counts']['freshness'], $v['now'])];
        $s = $v['search'];
        $rows[] = [lang('admin/ux/scale/sp_search_mode'), lang('admin/ux/scale/sp_search_mode_' . (in_array($s['mode'], ['ft', 'walk', 'old', 'building'], true) ? $s['mode'] : 'old'))];
        $rows[] = [lang('admin/ux/scale/sp_search_docs'), $s['docs'] !== null ? number_format($s['docs']) : $nr];
        $rows[] = [lang('admin/ux/scale/sp_terms_at'), self::ago($s['terms_at'], $v['now'])];
        $rows[] = [lang('admin/ux/scale/sp_ms_per_read'), $s['ms_per_read'] !== null ? self::t('admin/ux/scale/sp_ms_v', ['n' => number_format($s['ms_per_read'], 2)]) : $nr];
        $rows[] = [lang('admin/ux/scale/sp_flock'), $p['flock_ok'] === null ? $nr : lang($p['flock_ok'] ? 'admin/ux/scale/sp_flock_ok' : 'admin/ux/scale/sp_flock_bad')];
        return $rows;
    }

    // ---------------------------------------------------------------- protection (owner decision 2026-10-17 08:40)

    /** Display names of the built-in search engines of PublicRequestGuard::CRAWLERS (lane H). */
    private const CRAWLER_NAMES = ['google' => 'Googlebot', 'bing' => 'Bingbot', 'yandex' => 'YandexBot', 'duckduckgo' => 'DuckDuckBot', 'apple' => 'Applebot'];

    /** Constant of ScaleTier (lane H) when this version has it, else $fallback (what today's ScaleTier code uses). */
    private static function tierConst($name, $fallback)
    {
        $full = ScaleTier::class . '::' . $name;
        return defined($full) ? (int) constant($full) : (int) $fallback;
    }

    /**
     * Everything the "Protection" section shows, from the effective tier values (ScaleTier::get(): already lowered while
     * Under attack mode is on), the raw settings (ScaleTier::settings()) and the stored app.* values (form prefill).
     */
    private function guardView(array $tier)
    {
        $now = time();
        try { $set = ScaleTier::settings(); } catch (\Throwable $e) { $set = []; }
        $set = is_array($set) ? $set : [];
        $app = isset($GLOBALS['config']['app']) && is_array($GLOBALS['config']['app']) ? $GLOBALS['config']['app'] : [];
        $raw = function ($k) use ($app) { return isset($app[$k]) && is_numeric($app[$k]) && (int) $app[$k] > 0 ? (string) (int) $app[$k] : ''; };
        $default = ['heavy' => self::tierConst('BUDGET_HEAVY', 30), 'light' => self::tierConst('BUDGET_LIGHT', 240)];
        $attackSupported = defined(ScaleTier::class . '::ATTACK_BUDGET_HEAVY') || array_key_exists('under_attack', $set);
        $attackDefault = ['heavy' => self::tierConst('ATTACK_BUDGET_HEAVY', 30), 'light' => self::tierConst('ATTACK_BUDGET_LIGHT', 240)];
        $attackOn = $attackSupported && !empty($set['under_attack']);
        $budget = (array) $tier['budget'];
        $effective = ['heavy' => (int) ($budget['heavy'] ?? $default['heavy']), 'light' => (int) ($budget['light'] ?? $default['light'])];
        // the stored normal values, and the attack values ScaleTier uses while the mode is on (never above the normal ones)
        $normal = ['heavy' => $raw('budget_heavy') !== '' ? (int) $raw('budget_heavy') : $default['heavy'], 'light' => $raw('budget_light') !== '' ? (int) $raw('budget_light') : $default['light']];
        $attack = ['heavy' => min($normal['heavy'], $raw('attack_budget_heavy') !== '' ? (int) $raw('attack_budget_heavy') : $attackDefault['heavy']),
            'light' => min($normal['light'], $raw('attack_budget_light') !== '' ? (int) $raw('attack_budget_light') : $attackDefault['light'])];
        if ($attackOn) { $attack = $effective; }
        // search engines (lane H: settings 'crawlers' = configured engines, crawlerStatus() = published IP lists)
        $engines = isset($set['crawlers']) && is_array($set['crawlers']) ? array_keys($set['crawlers']) : null;
        $status = null;
        try {
            if (class_exists(PublicRequestGuard::class) && method_exists(PublicRequestGuard::class, 'crawlerStatus')) {
                $status = PublicRequestGuard::crawlerStatus(ROOT_PATH);
            }
        } catch (\Throwable $e) { $status = null; }
        $status = is_array($status) && isset($status['engines']) && is_array($status['engines']) ? $status : null;
        $bots = [];
        $lastRefresh = 0;
        $shipped = 0;
        if ($engines !== null) {
            foreach ($engines as $key) {
                $key = (string) $key;
                $name = self::CRAWLER_NAMES[$key] ?? $key;
                $st = $status !== null && isset($status['engines'][$key]) && is_array($status['engines'][$key]) ? $status['engines'][$key] : null;
                if ($st !== null && !empty($st['list']) && $st['count'] !== null && (int) $st['at'] > 0 && !empty($st['shipped'])) {
                    // CONTRACT HO-H-R3-1: the list shipped in the package ('at' = the day it was fetched for the release) is in
                    // use until housekeeping downloads the current one; it never counts as "refreshed" on this site
                    $shipped++;
                    $text = self::t('admin/ux/scale/pr_bots_shipped_one', ['name' => $name, 'n' => number_format((int) $st['count']), 'date' => date('Y-m-d', (int) $st['at'])]);
                } elseif ($st !== null && !empty($st['list']) && $st['count'] !== null && (int) $st['at'] > 0) {
                    $lastRefresh = max($lastRefresh, (int) $st['at']);
                    $text = self::t('admin/ux/scale/pr_bots_list_one', ['name' => $name, 'n' => number_format((int) $st['count']), 't' => self::ago((int) $st['at'], $now)]);
                } elseif ($st !== null && !empty($st['list'])) {
                    $text = self::t(!empty($st['rdns']) ? 'admin/ux/scale/pr_bots_pending' : 'admin/ux/scale/pr_bots_pending_list', ['name' => $name]);
                } else {
                    $text = self::t('admin/ux/scale/pr_bots_dns_only', ['name' => $name]);
                }
                $bots[] = ['key' => $key, 'name' => $name, 'text' => $text];
            }
        }
        $slots = (array) $tier['slots'];
        return [
            'default' => $default, 'effective' => $effective, 'normal' => $normal, 'custom' => $normal !== $default,
            'attack_supported' => $attackSupported, 'attack_on' => $attackOn, 'attack' => $attack, 'attack_default' => $attackDefault,
            'attack_since' => $attackOn && isset($app['under_attack_at']) && is_numeric($app['under_attack_at']) ? self::ago((int) $app['under_attack_at'], $now) : '',
            'stmt' => (int) $tier['stmt_limit'], 'stmt_placeholder' => $attackOn ? lang('admin/ux/scale/pr_f_auto') : (string) (int) $tier['stmt_limit'], 'slots' => ['search' => (int) ($slots['search'] ?? 0), 'deep' => (int) ($slots['deep'] ?? 0), 'light' => (int) ($slots['light'] ?? 0)],
            'bots_known' => $engines !== null, 'bots' => $bots, 'bot_names' => implode(', ', array_column($bots, 'name')),
            'lists_at' => $lastRefresh, 'lists_ago' => $lastRefresh > 0 ? self::ago($lastRefresh, $now) : '', 'lists_shipped' => $shipped,
            'form' => ['heavy' => $raw('budget_heavy'), 'light' => $raw('budget_light'), 'stmt' => $raw('stmt_limit'),
                'attack_heavy' => $raw('attack_budget_heavy'), 'attack_light' => $raw('attack_budget_light')],
        ];
    }

    /** The rows of the "Protection" section: state (ok | warn | off), chip text, title, text, sub lines (all text built here). */
    private static function guardRows(array $g)
    {
        $on = lang('admin/ux/scale/pr_on');
        $rows = [];
        $rows[] = ['id' => 'shed', 'state' => 'ok', 'chip' => $on, 'title' => lang('admin/ux/scale/pr_shed'),
            'text' => self::t('admin/ux/scale/pr_shed_v', ['search' => $g['slots']['search'], 'deep' => $g['slots']['deep'], 'light' => $g['slots']['light']]), 'sub' => []];
        $limits = self::t('admin/ux/scale/pr_limits_v', ['heavy' => number_format($g['effective']['heavy']), 'light' => number_format($g['effective']['light'])]);
        if ($g['attack_on']) {
            $limits .= ' ' . self::t('admin/ux/scale/pr_limits_attack', ['heavy' => number_format($g['normal']['heavy']), 'light' => number_format($g['normal']['light'])]);
        } else {
            $limits .= ' ' . self::t($g['custom'] ? 'admin/ux/scale/pr_limits_custom' : 'admin/ux/scale/pr_limits_default',
                ['heavy' => number_format($g['default']['heavy']), 'light' => number_format($g['default']['light'])]);
        }
        $rows[] = ['id' => 'limits', 'state' => $g['attack_on'] ? 'warn' : 'ok', 'chip' => $on, 'title' => lang('admin/ux/scale/pr_limits'), 'text' => $limits, 'sub' => []];
        $rows[] = ['id' => 'time', 'state' => 'ok', 'chip' => $on, 'title' => lang('admin/ux/scale/pr_time'),
            'text' => self::t('admin/ux/scale/pr_time_v', ['n' => $g['stmt']]), 'sub' => []];
        if ($g['bots_known']) {
            $sub = array_column($g['bots'], 'text');
            $sub[] = $g['lists_at'] > 0 ? self::t('admin/ux/scale/pr_bots_lists', ['t' => $g['lists_ago']])
                : lang(!empty($g['lists_shipped']) ? 'admin/ux/scale/pr_bots_lists_shipped' : 'admin/ux/scale/pr_bots_lists_never');
            $rows[] = ['id' => 'bots', 'state' => 'ok', 'chip' => $on, 'title' => lang('admin/ux/scale/pr_bots'),
                'text' => self::t('admin/ux/scale/pr_bots_v', ['names' => $g['bot_names']]) . ' ' . lang('admin/ux/scale/pr_bots_refresh_note'), 'sub' => $sub];
        } else {
            $rows[] = ['id' => 'bots', 'state' => 'off', 'chip' => lang('admin/ux/scale/state_off'), 'title' => lang('admin/ux/scale/pr_bots'),
                'text' => lang('admin/ux/scale/pr_bots_unknown'), 'sub' => []];
        }
        $vars = ['heavy' => number_format($g['attack']['heavy']), 'light' => number_format($g['attack']['light']), 't' => $g['attack_since']];
        if (!$g['attack_supported']) {
            $rows[] = ['id' => 'attack', 'state' => 'off', 'chip' => lang('admin/ux/scale/state_off'), 'title' => lang('admin/ux/scale/pr_attack'),
                'text' => lang('admin/ux/scale/pr_attack_unsupported'), 'sub' => []];
        } else {
            $rows[] = ['id' => 'attack', 'state' => $g['attack_on'] ? 'warn' : 'off', 'chip' => lang($g['attack_on'] ? 'admin/ux/scale/pr_on' : 'admin/ux/scale/pr_off'),
                'title' => lang('admin/ux/scale/pr_attack'),
                'text' => self::t($g['attack_on'] ? ($g['attack_since'] !== '' ? 'admin/ux/scale/pr_attack_on' : 'admin/ux/scale/pr_attack_on_plain') : 'admin/ux/scale/pr_attack_off', $vars), 'sub' => []];
        }
        return $rows;
    }

    /** One whole number from the request ($min..$max) or '' (empty = the default). */
    private static function intField($name, $labelKey, $min, $max)
    {
        $v = trim((string) input('post.' . $name, ''));
        if ($v === '') { return ''; }
        if (!preg_match('/^\d{1,7}$/D', $v) || (int) $v < $min || (int) $v > $max) {
            throw new \InvalidArgumentException(self::t('admin/ux/scale/pr_bad_range', ['field' => lang($labelKey), 'min' => number_format($min), 'max' => number_format($max)]));
        }
        return (int) $v;
    }

    /**
     * Writes app.* keys into application/extra/maccms.php (the Tunnel switch and the protection values). ScaleTier keys its
     * settings cache on the file's mtime + size, so a second save within the same second with the same size would keep the
     * old values: the mtime is moved on by one second in that case.
     */
    private static function writeApp(array $values)
    {
        $file = APP_PATH . 'extra/maccms.php';
        $maccms = config('maccms');
        if (!is_array($maccms) || !isset($maccms['app']) || !is_array($maccms['app'])) {
            throw new \RuntimeException(lang('save_err'));
        }
        clearstatcache(true, $file);
        $before = is_file($file) ? (int) filemtime($file) : 0;
        foreach ($values as $k => $v) { $maccms['app'][$k] = $v; }
        if (mac_arr2file($file, $maccms) === false) {
            throw new \RuntimeException(lang('save_err'));
        }
        clearstatcache(true, $file);
        if ($before > 0 && (int) @filemtime($file) <= $before) { @touch($file, $before + 1); clearstatcache(true, $file); }
        foreach ($values as $k => $v) { $GLOBALS['config']['app'][$k] = $v; }
        ScaleTier::reset();
    }

    // ---------------------------------------------------------------- helpers

    /** Newest cron / command-line heartbeat: housekeeping (H, optional) and the importer's CLI runs (P's table). */
    private static function cronSeenAt()
    {
        $seen = (int) self::optional('\\app\\common\\util\\Housekeeping', 'cronSeenAt');
        try {
            $r = Db::query('SELECT MAX(cli_seen_at) AS t FROM ' . (string) config('database.prefix') . 'avdb_provider_state');
            $seen = max($seen, isset($r[0]['t']) ? (int) $r[0]['t'] : 0);
        } catch (\Throwable $e) { /* table arrives with lane P's schema */ }
        return $seen;
    }

    private static function cronFresh($seen, $now)
    {
        return (int) $seen > (int) $now - 86400;
    }

    /**
     * One state() answer of ListPageMap / ListRank / FtIndex::mapState() (C14-C16) as
     * ['known' => bool, 'ready' => bool, 'pct' => 0-100|null, 'built_at' => int, 'mb' => float|null]. Reads 'ready', 'pct'
     * (or 'progress' 0-1), 'built_at', 'mb' (or 'bytes') at the top level, else averages a 'keys' / 'sorts' list of such
     * rows. Anything missing stays "not reported".
     */
    private static function mapState($st)
    {
        $out = ['known' => false, 'ready' => false, 'pct' => null, 'built_at' => 0, 'mb' => null];
        if (!is_array($st) || !$st) { return $out; }
        $out['known'] = true;
        $one = function (array $r) {
            $pct = null;
            if (isset($r['pct']) && is_numeric($r['pct'])) { $pct = (float) $r['pct']; }
            elseif (isset($r['progress']) && is_numeric($r['progress'])) { $pct = (float) $r['progress'] * 100; }
            $ready = array_key_exists('ready', $r) ? (bool) $r['ready'] : ($pct !== null && $pct >= 100);
            if ($ready && $pct === null) { $pct = 100.0; }
            return [$ready, $pct === null ? null : max(0.0, min(100.0, $pct))];
        };
        $rows = [];
        foreach (['keys', 'sorts', 'parts'] as $list) {
            if (isset($st[$list]) && is_array($st[$list])) { foreach ($st[$list] as $r) { if (is_array($r)) { $rows[] = $r; } } }
        }
        if (array_key_exists('ready', $st) || isset($st['pct']) || isset($st['progress']) || !$rows) {
            list($out['ready'], $out['pct']) = $one($st);
        } else {
            $ready = true; $sum = 0.0; $n = 0;
            foreach ($rows as $r) { list($rd, $pc) = $one($r); $ready = $ready && $rd; if ($pc !== null) { $sum += $pc; $n++; } }
            $out['ready'] = $ready;
            $out['pct'] = $n ? $sum / $n : null;
        }
        $out['built_at'] = isset($st['built_at']) ? (int) $st['built_at'] : 0;
        if (isset($st['mb']) && is_numeric($st['mb'])) { $out['mb'] = (float) $st['mb']; }
        elseif (isset($st['bytes']) && is_numeric($st['bytes'])) { $out['mb'] = (float) $st['bytes'] / 1048576; }
        return $out;
    }

    /**
     * Status line "Deep pages": ok when all three are ready, warn with the overall percentage while any is being prepared;
     * $mem (termMapMemory()): the PHP memory the term-map build needs and the command that builds it.
     */
    private static function deepLine(array $d, $mem = null)
    {
        $line = ['area' => 'deep', 'title' => lang('admin/ux/scale/sp_deep'), 'state' => 'off', 'text' => lang('admin/ux/scale/sp_deep_unknown'), 'action' => 'note', 'label' => lang('admin/ux/scale/sp_deep_hint'), 'cmd' => ''];
        $known = array_filter($d, function ($x) { return $x['known']; });
        if (!$known) { return $line; }
        $ready = count($known) === count($d) && !array_filter($d, function ($x) { return !$x['ready']; });
        if ($ready) { $line['state'] = 'ok'; $line['text'] = lang('admin/ux/scale/sp_deep_ready'); return $line; }
        $sum = 0.0;
        foreach ($d as $x) { $sum += $x['known'] ? ($x['ready'] ? 100.0 : (float) ($x['pct'] ?? 0)) : 0.0; }
        $line['state'] = 'warn';
        $line['text'] = self::t('admin/ux/scale/sp_deep_maps', ['p' => (int) floor($sum / count($d))]);
        if (is_array($mem)) {
            $line['text'] = self::sentence($line['text'], self::termMapMemoryText($mem));
            $line['action'] = 'cmd';
            $line['label'] = lang('admin/ux/scale/sp_deep_term_cmd');
            $line['cmd'] = 'php tools/search-index.php --map';
        }
        return $line;
    }

    /**
     * Housekeeping skipped the term-map build because PHP's memory limit stays below what the build needs (runtime/scale/
     * hk.json deep.ftmap_mem, removed when a build starts): ['need_mb' => int, 'limit_mb' => int], or null. Read only.
     */
    private static function termMapMemory()
    {
        try {
            $hk = ScaleTier::readJson(ScaleTier::runtime('scale') . 'hk.json');
            $m = is_array($hk) && isset($hk['deep']['ftmap_mem']) && is_array($hk['deep']['ftmap_mem']) ? $hk['deep']['ftmap_mem'] : null;
            if ($m === null || (int) ($m['need_mb'] ?? 0) <= 0 || (int) ($m['limit_mb'] ?? 0) <= 0) { return null; }
            return ['need_mb' => (int) $m['need_mb'], 'limit_mb' => (int) $m['limit_mb']];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** "The search term map needs about N MB of PHP memory; this PHP allows M MB. Run ... --map ... or allow more memory ..." */
    private static function termMapMemoryText(array $mem)
    {
        return self::t('admin/ux/scale/sp_deep_term_mem', ['need' => number_format((int) $mem['need_mb']), 'limit' => number_format((int) $mem['limit_mb'])]);
    }

    private static function mapText(array $x, $now)
    {
        if (!$x['known']) { return lang('admin/ux/scale/sp_not_reported'); }
        if ($x['ready']) { return self::t('admin/ux/scale/sp_map_ready', ['mb' => $x['mb'] !== null ? self::mb($x['mb']) : lang('admin/ux/scale/sp_not_reported'), 't' => self::ago($x['built_at'], $now)]); }
        return self::t('admin/ux/scale/sp_map_building', ['p' => (int) floor((float) ($x['pct'] ?? 0))]);
    }

    /** lang() with {name} placeholders (the admin/ux/scale strings are shared with the import page script). */
    public static function t($key, array $vars = [])
    {
        $text = lang($key);
        foreach ($vars as $k => $v) { $text = str_replace('{' . $k . '}', (string) $v, $text); }
        return $text;
    }

    public static function mb($mb)
    {
        if ($mb === null) { return lang('admin/ux/scale/sp_not_reported'); }
        $mb = (float) $mb;
        return $mb < 1024 ? number_format($mb) . ' MB' : number_format($mb / 1024, 1) . ' GB';
    }

    /**
     * Drops the gate's proxy notice (runtime/gate/proxy-notice.json, kept 24 h) once the admin fixed it, so the red notice
     * and the trust button go away at once: after "Trust this proxy" when the notice names that peer ($peer), after the
     * Cloudflare Tunnel switch ($peer '') when it is a pooled-proxy notice. A User-Agent notice about another peer stays.
     */
    private static function clearProxyNotice($peer)
    {
        $file = ScaleTier::runtime('gate') . 'proxy-notice.json';
        if (!is_file($file)) { return; }
        $notice = ScaleTier::readJson($file);
        $samePeer = is_array($notice) && $peer !== '' && (string) ($notice['peer'] ?? '') === $peer;
        $pooled = is_array($notice) && $peer === '' && (string) ($notice['header'] ?? '') !== 'user-agents';
        if (!is_array($notice) || $samePeer || $pooled) { @unlink($file); }
    }

    /**
     * [state, text] of a proxy notice: a pooled proxy (danger: the per-visitor limits are off for that address until the
     * proxy is trusted) or, CONTRACT HO-H-R4-2, a peer the User-Agent heuristic flagged ('user-agents': warn; its limits stay
     * per address; a public peer can only be trusted in application/extra/public_request_guard.php by hand).
     */
    private static function proxyNoticeText(array $notice)
    {
        if (($notice['kind'] ?? '') === 'user-agents') {
            return ['warn', self::t('admin/ux/scale/nt_proxy_ua', ['ip' => (string) ($notice['peer'] ?? '')])];
        }
        return ['danger', self::t('admin/ux/scale/nt_proxy', ['ip' => (string) ($notice['peer'] ?? '')])];
    }

    /** Peer address as a trusted-proxy CIDR when it is loopback, private (RFC 1918 / ULA) or link-local; '' otherwise. */
    private static function peerCidr($ip)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) { return ''; }
        $v6 = strpos($ip, ':') !== false;
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        if ($public) { return ''; }
        return $ip . ($v6 ? '/128' : '/32');
    }

    private static function guardFile()
    {
        return APP_PATH . 'extra/public_request_guard.php';
    }

    /** The site's custom PublicRequestGuard settings (the keys that file may hold), [] when there is none or it is not readable. */
    private static function guardCustom()
    {
        $file = self::guardFile();
        if (!is_file($file) || is_link($file) || filesize($file) > 65536) { return []; }
        try {
            ob_start();
            $data = (static function ($path) { return include $path; })($file);
        } catch (\Throwable $e) {
            $data = [];
        } finally {
            ob_end_clean();
        }
        return is_array($data) ? $data : [];
    }

    private static function writeGuardCustom(array $custom)
    {
        $allowed = ['storage_dir', 'limits', 'max_body_bytes', 'trusted_proxies', 'proxy_header'];
        foreach (array_keys($custom) as $k) {
            if (!in_array($k, $allowed, true)) { throw new \RuntimeException(lang('save_err')); }
        }
        if (isset($custom['trusted_proxies'])) {
            if (!is_array($custom['trusted_proxies']) || count($custom['trusted_proxies']) > 64) { throw new \RuntimeException(lang('save_err')); }
            foreach ($custom['trusted_proxies'] as $cidr) {
                if (!is_string($cidr) || !preg_match('~^([0-9a-fA-F:.]{2,45})/(\d{1,3})$~', $cidr, $m) || filter_var($m[1], FILTER_VALIDATE_IP) === false) { throw new \RuntimeException(lang('save_err')); }
            }
        }
        if (isset($custom['proxy_header']) && !isset(self::PROXY_HEADERS[$custom['proxy_header']])) { throw new \RuntimeException(lang('save_err')); }
        $file = self::guardFile();
        if (!$custom) {
            // Nothing custom left: the guard's built-in defaults apply again.
            if (is_file($file) && !@unlink($file)) { throw new \RuntimeException(lang('save_err')); }
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
            return;
        }
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
        $code = "<?php\n// Written by the admin page \"Speed & protection\" (trusted proxies). Keys as PublicRequestGuard::config() accepts them.\nreturn " . var_export($custom, true) . ";\n";
        if (@file_put_contents($tmp, $code, LOCK_EX) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException(lang('save_err'));
        }
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
    }

    /** flock self-test (brief 2.10 L6): false only when the filesystem refuses locks (NFS, some FUSE mounts). */
    private static function flockSelfTest()
    {
        $dir = RUNTIME_PATH;
        $file = $dir . 'scale-flock-test.lock';
        $h = @fopen($file, 'c');
        if ($h === false) { return null; }
        $wouldBlock = 0;
        $ok = @flock($h, LOCK_EX | LOCK_NB, $wouldBlock);
        if ($ok) { @flock($h, LOCK_UN); }
        @fclose($h);
        return $ok || $wouldBlock ? true : false;
    }

    private static function phpBinary()
    {
        $bin = defined('PHP_BINDIR') ? PHP_BINDIR . '/php' : 'php';
        return preg_match('~^[A-Za-z0-9_./:\\\\ -]{1,200}$~', $bin) ? $bin : 'php';
    }

    private static function siteRoot()
    {
        $root = str_replace('\\', '/', (string) ROOT_PATH);
        return preg_match('~^[A-Za-z0-9_./: -]{1,300}$~', $root) ? rtrim($root, '/') . '/' : '';
    }

    private static function cloudflareRanges()
    {
        try {
            $r = new \ReflectionClass('\\app\\common\\util\\PublicRequestGuard');
            $v = $r->getConstant('CLOUDFLARE');
            if (is_array($v) && count($v) >= 10) { return array_values($v); }
        } catch (\Throwable $e) { /* shipped copy below */ }
        return ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'];
    }

    /** The three copy-paste rules of brief 2.10 (HARD 4.3), with the site's real admin entrance and install folder. */
    private static function cloudflareRules($dir, $entry)
    {
        $p = function ($path) use ($dir) { return $dir . $path; };
        $cache = '(http.request.method eq "GET" and not starts_with(http.request.uri.path, "' . $p('/' . $entry) . '")'
            . ' and not starts_with(http.request.uri.path, "' . $p('/api.php') . '")'
            . ' and not http.request.uri.path contains "/user" and not http.request.uri.path contains "/ajax" and not http.request.uri.path contains "/jcx"'
            . ' and not http.cookie contains "user_id=" and not http.cookie contains "PHPSESSID" and not http.cookie contains "avdb_ui_lang"'
            . ' and not http.cookie contains "jv_theme" and not http.cookie contains "jcxp")';
        $rate = '(starts_with(http.request.uri.path, "' . $p('/search') . '") or http.request.uri.query contains "wd=")';
        $skip = '(http.request.uri.path eq "' . $p('/api.php/avdbschedule/index') . '")';
        return ['cache' => $cache, 'rate' => $rate, 'skip' => $skip];
    }

    private static function originLock()
    {
        $ranges = self::cloudflareRanges();
        $ufw = "# " . lang('admin/ux/scale/sp_lock_comment') . "\n";
        foreach ($ranges as $c) { $ufw .= 'ufw allow proto tcp from ' . $c . " to any port 80,443\n"; }
        $ufw .= "ufw deny 80/tcp\nufw deny 443/tcp";
        $apache = "<RequireAny>\n";
        foreach ($ranges as $c) { $apache .= '    Require ip ' . $c . "\n"; }
        $apache .= "</RequireAny>";
        $nginx = '';
        foreach ($ranges as $c) { $nginx .= 'allow ' . $c . ";\n"; }
        $nginx .= 'deny all;';
        return ['ufw' => $ufw, 'apache' => $apache, 'nginx' => $nginx];
    }

    /** The row of the docs/HOSTING.md table (brief 2.13) that matches this catalog. */
    private static function hostingRow($rows)
    {
        $n = (int) $rows;
        $i = $n <= 50000 ? 1 : ($n <= 600000 ? 2 : ($n <= 2000000 ? 3 : 4));
        $out = ['row' => $i];
        foreach (['range', 'db', 'host', 'mem', 'method', 'notes'] as $f) { $out[$f] = lang('admin/ux/scale/hr' . $i . '_' . $f); }
        return $out;
    }

    // ---------------------------------------------------------------- request plumbing (same envelope as Avdbimport)

    private function postGuard()
    {
        if (!Request::instance()->isPost()) { return $this->reply(0, lang('admin/ux/scale/sp_post_only'), []); }
        $params = input('post.');
        $validator = Loader::validate('Token');
        if (!$validator->check($params)) { return $this->reply(0, lang('admin/ux/system/token_expired'), []); }
        $this->nextToken = Request::instance()->token('__token__');
        return null;
    }

    private function failure(\Throwable $e)
    {
        $expected = $e instanceof \InvalidArgumentException || get_class($e) === 'RuntimeException';
        return $this->reply(0, $expected ? $e->getMessage() : lang('admin/ux/scale/sp_failed_log'), []);
    }

    private function reply($code, $message, $data)
    {
        if (!is_array($data)) { $data = ['result' => $data]; }
        $data['__token__'] = $this->nextToken !== null ? $this->nextToken : Request::instance()->token('__token__');
        return json(['code' => $code, 'msg' => $message, 'data' => $data], 200, [], ['json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP]);
    }
}
