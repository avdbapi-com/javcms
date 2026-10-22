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
namespace app\index\controller;
use think\Controller;

class Vod extends Base
{
    public function __construct()
    {
        // 1.2.0 verify fix G5 / XVID-NOLIMIT-BRIEF S1: a list page past the last page answers before the controller chain
        // boots (user, site settings, template engine), from the theme's 404 page kept per theme / device / language
        // (<= 50 ms, no vod query: the total is the precomputed count). Round 2 (N9, N10, R2-5): search pages too
        $this->past_cap_shell();
        $this->search_shell();
        parent::__construct();
    }

    /**
     * Lifetime of a kept 404 shell (seconds); site settings saved later than the shell (a theme switch included) also renew
     * it. Round-3 verify R3-2: one hour (was 600 s): every first past-last request after the TTL renders the whole 404
     * through the chain again (73-88 ms measured), the kept copy answers in about half of that.
     */
    const SHELL_TTL = 3600;

    /**
     * Category list pages (type / show) past their last page (S1: ceil(precomputed total / per); a tier cap set again also
     * counts): the themed 404 of an earlier past-last request is sent at once, with this request's path put back where the
     * page printed its own path (language switcher links). Only for anonymous cacheable requests (EdgeCache::eligibleNow:
     * no session, user or theme cookie) on a path without a query string; the first such request renders the 404 through
     * the normal chain (list_page_last) and keeps its HTML.
     */
    private function past_cap_shell()
    {
        try {
            $request = request();
            $action = strtolower((string)$request->action());
            if (($action !== 'type' && $action !== 'show') || !defined('MAC_TEMPLATE_DIR') || !defined('ROOT_PATH')
                || !class_exists('\app\common\util\EdgeCache') || !class_exists('\app\common\util\ScaleTier')) {
                return;
            }
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            if (strlen($uri) > 300 || !preg_match('~^/[A-Za-z0-9/_.-]+$~D', $uri) || strpos($uri, '//') !== false
                || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
                return;
            }
            // the page number as Base::depth_cap reads it (route or query; whole arrays only)
            $page = 0;
            $route = $request->route();
            $params = $request->param();
            foreach ([is_array($route) ? ($route['page'] ?? null) : null, is_array($params) ? ($params['page'] ?? null) : null] as $c) {
                if (is_int($c) || (is_string($c) && $c !== '' && strlen($c) <= 12 && ctype_digit($c))) {
                    $page = max($page, (int)$c);
                }
            }
            $by = is_array($params) && isset($params['by']) && is_string($params['by']) ? strtolower($params['by']) : '';
            if ($page <= 1 || !\app\common\util\EdgeCache::eligibleNow()) {
                return;
            }
            $cap = \app\common\model\Vod::pageCap('list', $by === '' ? 'time' : $by);
            $last = null;
            if (!($cap > 0 && $page > $cap)) {
                $last = self::list_last_page(mac_param_url(), null, $action);
                if ($last === null || $page <= $last) {
                    return;
                }
            }
            $this->shell_404($uri);
        } catch (\Throwable $e) {
            // the normal chain answers
        }
    }

    /**
     * The kept themed 404 of this theme / device / language (runtime/s404): sent at once with $uri put back where the page
     * printed its own path, or (none yet / expired) the normal chain renders the 404 of this request and its HTML is kept
     * (status and size checked when the output ends). Returns only when the chain must render.
     */
    private function shell_404(string $uri)
    {
        $lang = self::shell_lang();
        $conf = APP_PATH . 'extra/maccms.php';
        $dir = ROOT_PATH . 'runtime/s404/';
        $file = $dir . sha1(\app\common\util\EdgeCache::key(['REQUEST_URI' => '/'] + $_SERVER, $_COOKIE) . '|' . MAC_TEMPLATE_DIR . '|' . (defined('MAC_MOB') ? MAC_MOB : '')
            . '|' . $lang) . '.html';
        $path = htmlspecialchars($uri, ENT_QUOTES, 'UTF-8');
        $mark = "\x01JVPATH\x01";
        $at = @filemtime($file);
        if ($at && $at > time() - self::SHELL_TTL && $at > (int)@filemtime($conf)) {
            $html = (string)@file_get_contents($file);
            if ($html !== '' && (strpos($html, $mark) !== false || strpos($html, "\x01JVNOPATH\x01") === 0)) {
                if (function_exists('mac_no_cahche')) {
                    mac_no_cahche();
                }
                header('Content-Type: text/html; charset=utf-8', true);
                header('Cache-Control: no-store, max-age=0', true);
                http_response_code(404);
                echo strpos($html, "\x01JVNOPATH\x01") === 0 ? substr($html, 10) : str_replace($mark, $path, $html);
                exit;
            }
        }
        // keep the 404 the chain renders for this request (status and size checked when the output ends); a page that does
        // not print its path at all (no form of it) is kept as it is
        $plain = strpos($uri, '?') === false;
        ob_start(function ($buf, $phase) use ($file, $dir, $path, $mark, $uri, $plain) {
            if (($phase & PHP_OUTPUT_HANDLER_FINAL) && http_response_code() === 404 && strlen($buf) > 200 && strlen($buf) < 2097152
                && strpos($buf, $mark) === false) {
                $keep = null;
                if (substr_count($buf, $path) > 0 && ($plain || strpos($buf, $path) !== false)) {
                    $keep = str_replace($path, $mark, $buf);
                } elseif (strpos($buf, $uri) === false && strpos($buf, rawurlencode($uri)) === false && strpos($buf, $path) === false
                    && strpos($buf, strtok($uri, '?')) === false) {
                    $keep = "\x01JVNOPATH\x01" . $buf;
                }
                if ($keep !== null) {
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    $tmp = $file . '.' . getmypid() . '.tmp';
                    if (@file_put_contents($tmp, $keep) !== false) {
                        @rename($tmp, $file);
                    }
                }
            }
            return $buf;
        });
    }

    /**
     * Language part of a kept 404 shell's key without a database read (round-3 verify R3-2: AvdbLanguage::cacheSuffix()
     * probes the translation table through the query builder, two statements and the builder boot on every past-last
     * answer): the interface language this request renders in (AvdbLanguage::resolve() over the offered languages: the
     * ui_lang parameter, the language cookie, the default), the offered set and the URL scheme settings, as cacheSuffix()
     * keys the same choice.
     */
    private static function shell_lang(): string
    {
        if (!class_exists('\app\common\util\AvdbLanguage')) {
            return '';
        }
        try {
            $offered = \app\common\util\AvdbLanguage::offeredCodes();
            $locale = \app\common\util\AvdbLanguage::resolve($_GET['ui_lang'] ?? null, $_COOKIE['avdb_ui_lang'] ?? null, $offered);
            sort($offered, SORT_STRING);
            $scheme = json_encode([$GLOBALS['config']['rewrite']['canonical_scheme'] ?? 'auto', $GLOBALS['http_type'] ?? 'http://']);
            return 'L|' . $locale . '|' . substr(sha1(implode(',', $offered) . '|' . $scheme), 0, 12);
        } catch (\Throwable $e) {
            return (string)\app\common\util\AvdbLanguage::cacheSuffix();
        }
    }

    /**
     * Round-2 verify fixes N9 / N10 / R2-5: a search page that cannot hold results answers before the controller chain
     * boots (theme, user, settings), for anonymous cacheable GET requests while the search index is ready:
     *  - a cursor= this site did not issue for this search (random, foreign, another search's: Vod::cursorDecode) or one at
     *    or above the newest doc -> 301 to the search's first page (no copy of page 1 under another URL, no 200);
     *  - page= above the last page known before rendering (search_last_page) -> the theme's kept 404 shell (shell_404).
     * Cost: the search state row and at most one term-map row; 0 video queries.
     */
    private function search_shell()
    {
        try {
            $request = request();
            $action = strtolower((string)$request->action());
            // round-3 verify N10: a keyword search inside a category / show list (/category/<slug>/wd/<word>/) gets the same
            // cursor check (its cursors are signed in its own scope: the category and the word)
            $catSearch = ($action === 'type' || $action === 'show') && array_key_exists('cursor', $_GET);
            if (($action !== 'search' && !$catSearch) || !defined('MAC_TEMPLATE_DIR') || !defined('ROOT_PATH')
                || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET' || !class_exists('\app\common\util\EdgeCache')
                || !class_exists('\app\common\util\FtIndex') || !\app\common\util\EdgeCache::eligibleNow()) {
                return;
            }
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            if ($uri === '' || strlen($uri) > 600 || $uri[0] !== '/' || strpos($uri, '//') !== false) {
                return;
            }
            $param = mac_param_url();
            $catType = null;
            if ($catSearch) {
                $catType = !empty($param['wd']) && is_scalar($param['wd']) ? \app\common\model\Vod::typeOfParam($param) : null;
                if ($catType === null) {
                    return; // a category list without a keyword: its after= / cursor rules are the list code's
                }
            }
            $page = isset($param['page']) && is_scalar($param['page']) && ctype_digit((string)$param['page']) && strlen((string)$param['page']) <= 12 ? (int)$param['page'] : 1;
            // page 1 without a cursor (most searches): nothing to decide here, so no search-state read
            if (($page <= 1 && !array_key_exists('cursor', $_GET)) || !\app\common\util\FtIndex::ready()) {
                return;
            }
            if (array_key_exists('cursor', $_GET)) {
                $raw = $_GET['cursor'];
                $dec = is_string($raw) ? \app\common\model\Vod::cursorDecode($raw, $param) : null;
                $bad = $dec === null;
                if (!$bad && $dec[0] !== 'o') {
                    $next = (int)(\app\common\util\FtIndex::state()['next_doc_id'] ?? 0);
                    $bad = $next > 0 && $dec[1] >= $next; // at or above the newest doc: the same rows as page 1
                }
                $to = !$bad ? '' : ($catType !== null ? \app\common\model\Vod::categorySearchUrl($catType, $action, array_diff_key($param, ['page' => 1]), 1)
                    : self::search_first_url($uri, $param));
                if ($bad && $to !== '') {
                    header('Cache-Control: public, max-age=3600', true);
                    header('Location: ' . $to, true, 301);
                    http_response_code(301);
                    exit;
                }
                return;
            }
            $last = self::search_last_page($param);
            if ($last !== null && $page > $last) {
                $this->shell_404($uri);
            }
        } catch (\Throwable $e) {
            // the normal chain answers
        }
    }

    /** First page of this search: the canonical URL without cursor and page (keyword searches), else the URI without them. */
    private static function search_first_url(string $uri, array $param): string
    {
        $raw = $_GET['cursor'] ?? null;
        unset($_GET['cursor'], $param['cursor'], $param['page']);
        $url = \app\common\model\Vod::searchCanonical($param, 1);
        if ($raw !== null) {
            $_GET['cursor'] = $raw;
        }
        if ($url !== '') {
            return $url;
        }
        $parts = explode('?', $uri, 2);
        $keep = [];
        foreach (isset($parts[1]) && $parts[1] !== '' ? explode('&', $parts[1]) : [] as $pair) {
            $k = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if ($k !== 'cursor' && $k !== 'page' && $k !== '') {
                $keep[] = $pair;
            }
        }
        $path = preg_replace('~/page/[0-9]+/?$~', '/', $parts[0]);
        return $path !== '' && $path[0] === '/' && strpos($path, '//') === false ? $path . ($keep ? '?' . implode('&', $keep) : '') : '';
    }

    /**
     * Last page of a search known before rendering, or null (unknown: the render decides). A keyword or a single tag / actor
     * value without other filters: one token with a term-map row -> ceil((df + appended + docs above the map) / per); any
     * other query -> max(FtPlan::NUMBERED_FIRST, ceil(FtPlan::FT_LIMIT / per)) (C3: deeper numbered pages need a cursor; the
     * FULLTEXT path numbers at most FT_LIMIT ids). per = the theme's paged list of vod/search (a low guess never 404s a
     * real page). Pages above the tier's page ceiling are always past the last.
     */
    /**
     * Live hotfix 2026-10-19: the same path with the same query pairs in another order is the canonical URL. Clients such as GPTBot
     * sort the parameters, so a 301 to the other order looped; the page's rel=canonical still names the canonical order.
     */
    private static function same_url_any_order($a, $b)
    {
        $pa = explode('?', (string)$a, 2);
        $pb = explode('?', (string)$b, 2);
        if (rawurldecode($pa[0]) !== rawurldecode($pb[0])) {
            return false;
        }
        $na = [];
        $nb = [];
        foreach (isset($pa[1]) && $pa[1] !== '' ? explode('&', $pa[1]) : [] as $pair) { $na[] = rawurldecode(str_replace('+', ' ', $pair)); }
        foreach (isset($pb[1]) && $pb[1] !== '' ? explode('&', $pb[1]) : [] as $pair) { $nb[] = rawurldecode(str_replace('+', ' ', $pair)); }
        sort($na, SORT_STRING);
        sort($nb, SORT_STRING);
        return $na === $nb;
    }

    private static function search_last_page(array $param)
    {
        foreach (['class', 'area', 'lang', 'year', 'letter', 'director', 'state', 'version', 'level', 'name', 'ids', 'isend'] as $f) {
            if (isset($param[$f]) && $param[$f] !== '' && $param[$f] !== 0 && $param[$f] !== '0') {
                return null;
            }
        }
        $per = self::list_per('vod/search');
        $ceiling = class_exists('\app\common\util\ScaleTier') ? (int)\app\common\util\ScaleTier::pageCeiling('search') : 0;
        $wd = isset($param['wd']) && is_scalar($param['wd']) ? (string)$param['wd'] : '';
        $tag = isset($param['tag']) && is_scalar($param['tag']) ? (string)$param['tag'] : '';
        $actor = isset($param['actor']) && is_scalar($param['actor']) ? (string)$param['actor'] : '';
        $q = null;
        if ($wd !== '') {
            $q = \app\common\util\FtText::query($wd);
        } elseif ($tag !== '' || $actor !== '') {
            // live hotfix 2026-10-19 (F1): since the filter port tag / actor lists are LIKE lists whose total and pager come from the
            // LIKE count, so the search-index term map is no bound for them; the render decides (All::page_out_of_range 404s past
            // the list's own last page).
            return $ceiling > 0 ? $ceiling : null;
        }
        if ($q === null || !$q['groups']) {
            return $ceiling > 0 ? $ceiling : null;
        }
        $last = max(\app\common\util\FtPlan::NUMBERED_FIRST, (int)ceil(\app\common\util\FtPlan::FT_LIMIT / $per));
        if (count($q['groups']) === 1 && count($q['groups'][0]['t']) === 1 && \app\common\util\FtIndex::mapUsable()) {
            $row = \app\common\util\FtIndex::mapRow((string)$q['groups'][0]['t'][0]);
            if ($row !== null) {
                $maxDoc = (int)(\app\common\util\FtIndex::state()['next_doc_id'] ?? 0) - 1;
                // round-3 F-r3-1: live_n = the term's matches without the documents deleted since (dead ids)
                $high = (int)($row['live_n'] ?? ((int)$row['df'] + (int)($row['tail_n'] ?? 0))) + max(0, $maxDoc - (int)($row['cover'] ?? $row['last_doc']));
                $last = max(1, (int)ceil($high / $per));
            }
        }
        return $ceiling > 0 ? min($last, $ceiling) : $last;
    }

    public function index()
    {
        return $this->label_fetch('vod/index');
    }

    public function type()
    {
        $this->list_page_last();
        $info = $this->label_type();
        return $this->label_fetch( mac_tpl_fetch('vod',$info['type_tpl'],'type') );
    }

    public function show()
    {
        $this->list_page_last();
        $this->check_show();
        $info = $this->label_type();
        return $this->label_fetch( mac_tpl_fetch('vod',$info['type_tpl_list'],'show') );
    }

    /**
     * XVID-NOLIMIT-BRIEF S1 (was list_page_cap): a category list page above its last page answers 404 before any vod query
     * (<= 50 ms): the last page is ceil(T / per), T = the precomputed count of the category (ScaleCounts, scale_count), per
     * = the theme's paged vod list of this template. Lists with filters, a visitor group with hidden categories or an
     * unknown count are decided after the render (All::page_out_of_range: a page past the last one is a 404 there too).
     * A tier cap set again (> 0) still answers 404 above it.
     */
    private function list_page_last()
    {
        $param = mac_param_url();
        $page = isset($param['page']) && is_scalar($param['page']) ? (int)$param['page'] : 1;
        if ($page <= 1) {
            return;
        }
        $by = isset($param['by']) && is_string($param['by']) && in_array($param['by'], \app\common\model\Vod::BY_URL, true) ? $param['by'] : 'time';
        $cap = \app\common\model\Vod::pageCap('list', $by);
        if ($cap > 0 && $page > $cap) {
            $this->page_error(lang('page_not_found'));
        }
        $last = self::list_last_page($param, null, strtolower((string)request()->action()));
        if ($last !== null && $page > $last) {
            $this->page_error(lang('page_not_found'));
        }
    }

    /** Filters that make a category list "filtered" (its total is a bounded count, not the precomputed one). */
    const LIST_FILTERS = ['wd', 'area', 'lang', 'year', 'letter', 'class', 'tag', 'actor', 'director', 'state', 'version', 'level', 'name', 'ids', 'isend'];

    /**
     * Last page of an unfiltered category list, or null when it is not known before the render. $info = the category row
     * (null: resolved from the URL through the category cache).
     */
    private static function list_last_page(array $param, $info, string $action)
    {
        try {
            foreach (self::LIST_FILTERS as $f) {
                if (isset($param[$f]) && $param[$f] !== '' && $param[$f] !== 0 && $param[$f] !== '0') {
                    return null;
                }
            }
            if (isset($_GET['after']) || isset($_GET['cursor']) || !class_exists('\app\common\util\ScaleCounts')
                || (int)($GLOBALS['config']['app']['popedom_filter'] ?? 0) === 1) {
                return null;
            }
            if (!is_array($info)) {
                $info = function_exists('mac_label_type') ? mac_label_type($param, 0) : null;
            }
            if (!is_array($info) || empty($info['type_id'])) {
                return null;
            }
            $total = \app\common\util\ScaleCounts::type((int)$info['type_id']);
            if ($total === null) {
                return null;
            }
            $tpl = $action === 'show' ? mac_tpl_fetch('vod', $info['type_tpl_list'] ?? '', 'show') : mac_tpl_fetch('vod', $info['type_tpl'] ?? '', 'type');
            return max(1, (int)ceil($total / self::list_per($tpl)));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Rows per page of the paged vod list of a template (the smallest num="" of a {maccms:vod ... paging="yes"} tag in the
     * file); 10 when none is found (the smallest a theme uses: a guess below the real per only moves the 404 to after the
     * render, never answers 404 for a real page).
     */
    private static function list_per(string $tpl): int
    {
        static $memo = [];
        if (isset($memo[$tpl])) {
            return $memo[$tpl];
        }
        $per = 0;
        if (defined('MAC_ROOT_TEMPLATE') && preg_match('~^[a-z0-9_/-]{1,80}$~i', $tpl)) {
            $file = MAC_ROOT_TEMPLATE . $tpl . '.html';
            $html = is_file($file) ? (string)@file_get_contents($file, false, null, 0, 262144) : '';
            if ($html !== '' && preg_match_all('~\{maccms:vod\b[^}]*\}~i', $html, $tags)) {
                foreach ($tags[0] as $tag) {
                    if (stripos($tag, 'paging="yes"') !== false && preg_match('~\bnum="([0-9]{1,4})"~', $tag, $m) && (int)$m[1] > 0) {
                        $per = $per === 0 ? (int)$m[1] : min($per, (int)$m[1]);
                    }
                }
            }
        }
        return $memo[$tpl] = $per > 0 ? $per : 10;
    }

    /**
     * 1.2.0 search front door (L3, L4, D-S4): every spelling or alias of a keyword search (other case, spaces, entities,
     * unknown parameters, path form, /search.html, ...) answers 301 with the one canonical URL, so only that URL runs the
     * search (and only it may be stored by the edge cache). XVID-NOLIMIT-BRIEF C4: no page cap here any more (the term map
     * and the continuation decide the last page; a tier cap set again still answers 404 above it); a cursor= value must be
     * a doc id (^[1-9][0-9]{0,17}$), else 404.
     */
    private function search_front_door(array $param, bool $ajax)
    {
        $page = isset($param['page']) && is_scalar($param['page']) ? (int)$param['page'] : 1;
        $cap = \app\common\model\Vod::pageCap('search');
        if ($cap > 0 && $page > $cap) {
            $this->page_error(lang('page_not_found'));
        }
        if (isset($_GET['cursor']) && (!is_string($_GET['cursor']) || !preg_match('/^[1-9][0-9]{0,17}$/D', $_GET['cursor']))) {
            $this->page_error(lang('page_not_found'));
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (($method !== 'GET' && $method !== 'HEAD') || isset($param['ui_lang_set']) || ($ajax && request()->isAjax())) {
            return; // POST forms, the language switcher's one request and infinite-scroll fragments are served as they are
        }
        $canonical = \app\common\model\Vod::searchCanonical($param);
        if ($canonical === '') {
            return;
        }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($ajax || ($uri !== $canonical && !self::same_url_any_order($uri, $canonical))) {
            header('Cache-Control: public, max-age=3600', true);
            header('Location: ' . $canonical, true, 301);
            http_response_code(301);
            exit;
        }
    }

    /**
     * Gate class 'search' (C10) around the render. Lane H's All::label_fetch (HP-H-2) gates the same render; RequestGate
     * must treat a second enter() of this request as already admitted (HO-S-4).
     */
    private function search_gate()
    {
        if (class_exists('\app\common\util\RequestGate') && !\app\common\util\RequestGate::enter('search')) {
            if (class_exists('\app\common\util\EdgeCache') && method_exists('\app\common\util\EdgeCache', 'shed')) {
                \app\common\util\EdgeCache::shed(); // stale copy or busy page; exits
            }
            \app\common\util\RequestGate::busy();
            exit;
        }
    }

    public function ajax_show()
    {
        $this->check_ajax();
        $this->check_show(1);
        $info = $this->label_type();
        return $this->label_fetch('vod/ajax_show');
    }

    public function search()
    {
        $param = mac_param_url();
        $this->search_front_door($param, false);
        $this->check_search($param);
        $this->search_gate();
        $this->label_search($param);
        return $this->label_fetch('vod/search');
    }

    public function ajax_search()
    {
        $param = mac_param_url();
        $this->search_front_door($param, true);
        $this->check_ajax();
        $this->check_search($param,1);
        $this->search_gate();
        $this->label_search($param);
        return $this->label_fetch('vod/ajax_search');
    }

    public function detail()
    {
        $info = $this->label_vod_detail();
        if($info['vod_copyright']==1 && $GLOBALS['config']['app']['copyright_status']==2){
            return $this->label_fetch('vod/copyright');
        }
        if(!empty($info['vod_pwd']) && session('1-1-'.$info['vod_id'])!='1'){
            return $this->label_fetch('vod/detail_pwd');
        }
        return $this->label_fetch( mac_tpl_fetch('vod',$info['vod_tpl'],'detail') );
    }

    public function ajax_detail()
    {
        $this->check_ajax();
        $info = $this->label_vod_detail();
        return $this->label_fetch('vod/ajax_detail');
    }

    public function copyright()
    {
        $info = $this->label_vod_detail();
        return $this->label_fetch('vod/copyright');
    }

    public function role()
    {
        $info = $this->label_vod_role();
        return $this->label_fetch('vod/role');
    }

    public function play()
    {
        $info = $this->label_vod_play('play');
        if($info['vod_copyright']==1 && $GLOBALS['config']['app']['copyright_status']==3){
            return $this->label_fetch('vod/copyright');
        }
        return $this->label_fetch( mac_tpl_fetch('vod',$info['vod_tpl_play'],'play') );
    }

    public function player()
    {
        $info = $this->label_vod_play('play',[],0,1);
        if($info['vod_copyright']==1 && $GLOBALS['config']['app']['copyright_status']==4){
            return $this->label_fetch('vod/copyright');
        }
        if(!empty($info['vod_pwd_play']) && session('1-4-'.$info['vod_id'])!='1'){
            return $this->label_fetch('vod/player_pwd');
        }
        return $this->label_fetch('vod/player');
    }

    public function down()
    {
        $info = $this->label_vod_play('down');
        if (!empty($info['vod_pwd_down']) && session('1-5-'.$info['vod_id']) != '1') {
            return $this->label_fetch('vod/downer_pwd');
        }
        return $this->label_fetch( mac_tpl_fetch('vod',$info['vod_tpl_down'],'down') );
    }

    public function downer()
    {
        $info = $this->label_vod_play('down');
        if(!empty($info['vod_pwd_down']) && session('1-5-'.$info['vod_id'])!='1'){
            return $this->label_fetch('vod/downer_pwd');
        }
        return $this->label_fetch('vod/downer');
    }

    public function rss()
    {
        $info = $this->label_vod_detail();
        return $this->label_fetch('vod/rss');
    }

    public function plot()
    {
        $info = $this->label_vod_detail();
        return $this->label_fetch('vod/plot');
    }

}
