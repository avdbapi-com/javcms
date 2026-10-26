<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\controller;
use think\Controller;
use think\Cache;
use think\Request;

class All extends Controller
{
    var $_ref;
    var $_cl;
    var $_ac;
    var $_tsp;
    var $_url;

    public function __construct()
    {
        parent::__construct();
        $this->_ref = mac_get_refer();
        $this->_cl = request()->controller();
        $this->_ac = request()->action();
        $this->_tsp = date('Ymd');
        // Paged lists rendered for this request (mac_page_param), see page_out_of_range().
        $GLOBALS['mac_page_paging'] = [];
    }

    /**
     * Every public template render passes here (label_fetch and the direct $this->fetch() calls of the
     * user centre / confirm pages), before the page cache stores the HTML. The visitor theme switcher
     * appends its loader (cache-stable payload only) unless the template is excluded (player iframe,
     * feeds, AJAX fragments, gate pages): see app\common\util\ThemeSwitcher::decorate().
     */
    protected function fetch($template = '', $vars = [], $replace = [], $config = [])
    {
        $html = parent::fetch($template, $vars, $replace, $config);
        if (defined('ENTRANCE') && ENTRANCE === 'index' && is_string($html)) {
            $html = \app\common\util\ThemeSwitcher::decorate($html, $template, defined('MAC_TEMPLATE_DIR') ? MAC_TEMPLATE_DIR : '');
            $html = \app\common\util\SiteHead::decorate($html, $template);
            // [icon-merge] insertion point: other whole-page decorations go here (same exclusions apply).
            if (!\app\common\util\ThemeSwitcher::excluded($template)) { $html = \app\common\util\JcxPlacer::decorate($html, $template, defined('MAC_TEMPLATE_DIR') ? MAC_TEMPLATE_DIR : ''); }
        }
        return $html;
    }

    protected function load_page_cache($tpl,$type='html')
    {
        if(defined('ENTRANCE') && ENTRANCE == 'index' && $GLOBALS['config']['app']['cache_page'] ==1  && $GLOBALS['config']['app']['cache_time_page'] ) {
            $cach_name = $this->page_cache_key($tpl);
            $res = Cache::get($cach_name);
            if ($res) {
                // Encode cached responses as JSON when the request expects JSON.
                // https://github.com/magicblack/maccms10/issues/965
                if($type=='json' || str_contains((string)request()->header('accept'), 'application/json')){
                    $res = json_encode($res);
                } elseif ($this->feed_template($tpl) && !headers_sent()) {
                    // A cached feed keeps the content type of the first (uncached) response.
                    foreach ($this->feed_headers() as $name => $value) {
                        header($name . ': ' . $value);
                    }
                } elseif (is_string($res)) {
                    // Visitor theme switcher: renew the jv_theme cookie only on an HTML page that carries the widget.
                    \app\common\util\ThemeSwitcher::renewNow($tpl, $res);
                }
                // 1.2.0 (L1): a page-cache hit also refreshes the pre-framework copy (canonical URIs only).
                if (is_string($res) && $type !== 'json' && class_exists('\app\common\util\EdgeCache')) {
                    \app\common\util\EdgeCache::finish($res, ['tpl' => $tpl, 'feed' => $this->feed_template($tpl)]);
                }
                echo $res;
                die;
            }
        }
    }

    protected function label_fetch($tpl,$loadcache=1,$type='html')
    {
        // Keep browser/CDN HTML fresh while retaining the server-side page cache.
        $publicHtml = defined('ENTRANCE') && ENTRANCE === 'index' && $type === 'html'
            && strpos((string)request()->header('accept'), 'application/json') === false;
        if ($publicHtml && function_exists('mac_no_cahche')) {
            mac_no_cahche();
        }
        if (defined('ENTRANCE') && ENTRANCE === 'index') {
            if (!defined('MAC_TYPE_ID')) { define('MAC_TYPE_ID', (int)($GLOBALS['type_id'] ?? 0)); }
            if (!defined('MAC_TYPE_PID')) { define('MAC_TYPE_PID', (int)($GLOBALS['type_pid'] ?? 0)); }
        }
        // Video detail and playback pages contain current source URLs. Do not serve a stale
        // rendered page after a template/data update; keep cache behavior for
        // listings, search, APIs, and all other public routes unchanged.
        $tplPath = str_replace('\\', '/', trim((string)$tpl));
        $detailNoCache = (bool)preg_match('~(?:^|/)vod/(?:detail|play|down|player|downer)(?:\.html)?$~i', $tplPath) || (bool)preg_match('~(?:^|/)(?:vod|art|actor|topic)/(?:rss|ajax_detail)(?:\.html)?$~i', $tplPath);
        if($detailNoCache && !$publicHtml && function_exists('mac_no_cahche')){
            mac_no_cahche();
        }
        $frontPage = defined('ENTRANCE') && ENTRANCE === 'index';
        // A routed page whose template the active theme does not ship is "not found", not a server error (e.g. /role/).
        if ($frontPage && !$this->page_template_exists($tplPath)) {
            $this->page_error(lang('page_not_found'));
        }
        if($loadcache==1 && !$detailNoCache){
            $this->load_page_cache($tpl,$type);
        }

        // 1.2.0 (L6): every uncached public render takes a gate slot of its class (search / deep / light) and counts against
        // the visitor's budget; a shed request gets the stale copy or the busy page. Error pages (public/*) are never gated.
        $gateClass = '';
        if ($frontPage && class_exists('\app\common\util\RequestGate')) {
            $gateClass = \app\common\util\RequestGate::classify($tplPath, mac_param_url());
            if ($gateClass !== '' && !\app\common\util\RequestGate::enter($gateClass)) {
                \app\common\util\EdgeCache::shed();
            }
        }
        try {
            $html = $this->fetch($tpl);
        } catch (\Throwable $renderError) {
            // 1.2.0 (L6, D-S5): a query stopped by the statement time limit answers with the stale copy or the busy page
            // (503 + Retry-After), never a 500 page and never a retry.
            if ($frontPage && class_exists('\app\common\util\DbTimeout') && \app\common\util\DbTimeout::is($renderError)) {
                \app\common\util\EdgeCache::shed();
            }
            throw $renderError;
        }
        // A page number past the last page of the rendered list (or on a home/label page without one) is a soft 404.
        // AJAX calls (infinite scroll) still get the empty list with 200, but that copy never enters the page cache:
        // the cache key ignores X-Requested-With, so a cached copy would answer the plain URL with 200 as well.
        $pastLast = $frontPage && $this->page_out_of_range($tplPath, true);
        if ($pastLast && !Request::instance()->isAjax()) {
            $this->page_error(lang('page_not_found'));
        }
        if($GLOBALS['config']['app']['compress'] == 1){
            $html = mac_compress_html($html);
        }
        // 1.2.0 (flood-r9 R9-1a, HP-H-9): a render whose landing facts were cut (the database deadline, or a late lower bound that
        // does not make the page indexable: EdgeCache::renderCut()) says noindex only for lack of time; it never enters the page
        // cache (EdgeCache::finish() below does not store it either), so no later request, a verified crawler included, gets it.
        $renderCut = class_exists('\app\common\util\EdgeCache') && method_exists('\app\common\util\EdgeCache', 'renderCut') && \app\common\util\EdgeCache::renderCut();
        if($loadcache==1 && !$detailNoCache && !$pastLast && !$renderCut && defined('ENTRANCE') && ENTRANCE == 'index' && $GLOBALS['config']['app']['cache_page'] ==1  && $GLOBALS['config']['app']['cache_time_page'] ){
            $cach_name = $this->page_cache_key($tpl);
            if (\app\common\util\ThemeSwitcher::visitorTheme()) {
                // A page in a visitor-chosen theme: no 'vod' tag (the tag index is rewritten on every tagged write and
                // would grow with every theme) and a capped lifetime. Settings saves change the key; "clear cache" empties it.
                $res = Cache::set($cach_name, $html, min((int)$GLOBALS['config']['app']['cache_time_page'], \app\common\util\ThemeSwitcher::VISITOR_CACHE_TTL));
            } else {
                try { $res = @Cache::set($cach_name,$html,$GLOBALS['config']['app']['cache_time_page']); } catch (\Throwable $_e) { $res = false; }
            }
        }
        // 1.2.0 (L1/L2): store the pre-framework copy (canonical URI, 200, anonymous) and send CDN headers for it.
        if ($frontPage && $loadcache == 1 && !$pastLast && strpos($tplPath, 'public/') !== 0 && class_exists('\app\common\util\EdgeCache')) {
            $edgeVod = isset($this->view->obj) && is_array($this->view->obj) ? $this->view->obj : [];
            $edgeSelf = '';
            if ($detailNoCache && !empty($edgeVod['vod_id'])) {
                $edgeSelf = preg_match('~(?:^|/)vod/(?:play|player)(?:\.html)?$~i', $tplPath)
                    ? (string)mac_url_vod_play($edgeVod, ['sid' => (int)request()->param('sid', 1), 'nid' => (int)request()->param('nid', 1)])
                    : (string)mac_url_vod_detail($edgeVod);
            }
            $edgePaging = isset($GLOBALS['mac_page_paging']) && is_array($GLOBALS['mac_page_paging']) ? $GLOBALS['mac_page_paging'] : [];
            \app\common\util\EdgeCache::finish($html, ['tpl' => $tplPath, 'detail' => $detailNoCache, 'vod_id' => (int)($edgeVod['vod_id'] ?? 0),
                'self_url' => $edgeSelf, 'feed' => $this->feed_template($tplPath),
                'empty' => $gateClass === 'search' && $edgePaging && (int)$edgePaging[0]['pages'] === 0]);
        }
        if ($this->feed_template($tplPath)) {
            return response($html, 200, $this->feed_headers());
        }
        return $html;
    }

    /** RSS templates: the site feed (rss/index) and the per-item feeds (vod/rss, art/rss, actor/rss, topic/rss). */
    protected function feed_template($tpl)
    {
        $tplPath = str_replace('\\', '/', trim((string)$tpl));
        return (bool)preg_match('~(?:^|/)(?:(?:vod|art|actor|topic)/rss|rss/index)(?:\.html)?$~i', $tplPath);
    }

    protected function feed_headers()
    {
        return ['Content-Type'=>'application/rss+xml; charset=UTF-8', 'X-Content-Type-Options'=>'nosniff', 'X-Robots-Tag'=>'noindex, follow'];
    }

    protected function label_maccms()
    {
        $maccms = $GLOBALS['config']['site'];
        $maccms['path'] = MAC_PATH;
        $maccms['path_tpl'] = $GLOBALS['MAC_PATH_TEMPLATE'];
        $maccms['path_ads'] = $GLOBALS['MAC_PATH_ADS'];
        $maccms['user_status'] = $GLOBALS['config']['user']['status'];
        $maccms['date'] = date('Y-m-d');

        $maccms['search_hot'] = $GLOBALS['config']['app']['search_hot'];
        $maccms['art_extend_class'] = $GLOBALS['config']['app']['art_extend_class'];
        $maccms['vod_extend_class'] = $GLOBALS['config']['app']['vod_extend_class'];
        $maccms['vod_extend_state'] = $GLOBALS['config']['app']['vod_extend_state'];
        $maccms['vod_extend_version'] = $GLOBALS['config']['app']['vod_extend_version'];
        $maccms['vod_extend_area'] = $GLOBALS['config']['app']['vod_extend_area'];
        $maccms['vod_extend_lang'] = $GLOBALS['config']['app']['vod_extend_lang'];
        $maccms['vod_extend_year'] = $GLOBALS['config']['app']['vod_extend_year'];
        $maccms['vod_extend_weekday'] = $GLOBALS['config']['app']['vod_extend_weekday'];
        $maccms['actor_extend_area'] = $GLOBALS['config']['app']['actor_extend_area'];

        $maccms['http_type'] = $GLOBALS['http_type'];
        $maccms['http_url'] = $GLOBALS['http_type']. ''.$_SERVER['SERVER_NAME'].($_SERVER["SERVER_PORT"]==80 ? '' : ':'.$_SERVER["SERVER_PORT"]).$_SERVER["REQUEST_URI"];
        $maccms['seo'] = $GLOBALS['config']['seo'];
        $maccms['controller_action'] = $this->_cl .'/'.$this->_ac;

        if(!empty($GLOBALS['mid'])) {
            $maccms['mid'] = $GLOBALS['mid'];
        }
        else{
            $maccms['mid'] = mac_get_mid($this->_cl);
        }
        if(!empty($GLOBALS['aid'])) {
            $maccms['aid'] = $GLOBALS['aid'];
        }
        else{
            $maccms['aid'] = mac_get_aid($this->_cl,$this->_ac);
        }
        $maccms['ui_lang'] = \app\common\util\AvdbLanguage::current();
        $maccms['ui_languages'] = \app\common\util\AvdbLanguage::available();
        $this->assign( ['maccms'=>$maccms] );
    }

    protected function page_error($msg='')
    {
        $this->not_found_response($msg)->send();
        exit;
    }

    /** HTTP 404 with the active theme's public/404.html (public/jump.html fallback); JSON for AJAX/JSON clients. */
    protected function not_found_response($msg='')
    {
        if (\app\common\util\ErrorHandle::wantsJson()) {
            return \app\common\util\ErrorHandle::json(404);
        }
        try {
            $html = $this->not_found_html($msg);
        } catch (\think\exception\HttpResponseException $redirect) {
            throw $redirect;
        } catch (\Throwable $error) {
            if (\think\App::$debug) {
                throw $error;
            }
            \think\Log::record('404 template failed: ' . get_class($error) . ': ' . $error->getMessage(), 'error');
            return \app\common\util\ErrorHandle::failure(404);
        }
        return \think\Response::create($html, 'html', 404)->header(['Cache-Control' => 'no-store, max-age=0']);
    }

    private function not_found_html($msg)
    {
        if(empty($msg)){
            $msg=lang('controller/an_error_occurred');
        }
        $url = Request::instance()->isAjax() ? '' : 'javascript:history.back(-1);';
        $this->assign('url',$url);
        $this->assign('wait',3);
        $this->assign('msg',$msg);
        $tpl = 'jump';
        $configured = (string)($GLOBALS['config']['app']['page_404'] ?? '');
        if($configured !== '' && preg_match('/^[A-Za-z0-9_-]+$/D', $configured)){
            $tpl = $configured;
        }
        $suffix = ltrim((string)config('template.view_suffix'), '.');
        if (!defined('MAC_ROOT_TEMPLATE') || !is_file(MAC_ROOT_TEMPLATE . 'public/' . $tpl . '.' . ($suffix ?: 'html'))) {
            $tpl = 'jump';
        }
        return (string)$this->label_fetch('public/'.$tpl, 0);
    }

    /** Page templates (not shared public/* fragments) must exist in the active theme. */
    protected function page_template_exists($tplPath)
    {
        $tplPath = ltrim(str_replace('\\', '/', (string)$tplPath), '/');
        if ($tplPath === '' || strpos($tplPath, 'public/') === 0 || strpos($tplPath, '@') !== false
            || strpos($tplPath, '..') !== false || !defined('MAC_ROOT_TEMPLATE')) {
            return true;
        }
        $suffix = ltrim((string)config('template.view_suffix'), '.');
        $suffix = $suffix === '' ? 'html' : $suffix;
        if (strtolower(substr($tplPath, -strlen($suffix) - 1)) !== '.' . strtolower($suffix)) {
            $tplPath .= '.' . $suffix;
        }
        return is_file(MAC_ROOT_TEMPLATE . $tplPath);
    }

    /** True when ?page=N (N>1) points past every paged list of the rendered page ($withAjax: for AJAX requests too). */
    protected function page_out_of_range($tplPath, $withAjax = false)
    {
        $tplPath = ltrim(str_replace('\\', '/', (string)$tplPath), '/');
        if (strpos($tplPath, 'public/') === 0 || preg_match('~(?:^|/)ajax_[^/]*$~i', $tplPath) || (!$withAjax && Request::instance()->isAjax())) {
            return false; // fragments for infinite scroll keep answering 200 with an empty list
        }
        $param = mac_param_url();
        if ((int)($param['page'] ?? 1) <= 1) {
            return false;
        }
        $lists = isset($GLOBALS['mac_page_paging']) && is_array($GLOBALS['mac_page_paging']) ? $GLOBALS['mac_page_paging'] : [];
        if (!$lists) {
            // index-<n> and label/<file>/page/<n> only exist when that page renders a paged list.
            return in_array(strtolower((string)$this->_cl), ['index', 'label'], true);
        }
        foreach ($lists as $list) {
            if ((int)$list['current'] <= (int)$list['pages']) {
                return false;
            }
        }
        return true;
    }

    protected function label_user()
    {
        if(ENTRANCE != 'index'){
            return;
        }
        $user_id = intval(cookie('user_id'));
        $user_name = cookie('user_name');
        $user_check = cookie('user_check');

        $user = ['user_id'=>0,'user_name'=>lang('controller/visitor'),'user_portrait'=>'static/images/touxiang.png','group_id'=>1,'points'=>0];
        $group_list = model('Group')->getCache();

        if(!empty($user_id) && !empty($user_name) && !empty($user_check)){
            $res = model('User')->checkLogin();
            if($res['code'] == 1){
                $user = $res['info'];
            }
            else{
                cookie('user_id','0');
                cookie('user_name',lang('controller/visitor'));
                cookie('user_check','');
                $user['group'] = $group_list[1];
            }
        }
        else{
            $user['group'] = $group_list[1];
        }
        $GLOBALS['user'] = $user;
        $this->assign('user',$user);
    }

    protected function label_comment()
    {
        $comment = config('maccms.comment');
        $this->assign('comment',$comment);
    }

    protected function label_search($param)
    {
        $param = mac_filter_words($param);
        // 1.2.0 (search-r4 F-r4-1, lane S HP-S-R4-1): the list filters actor / tag / class / director keep the requested value in
        // the page's $param (title, canonical, language, sort and filter links); the list cuts them itself with 1.1's
        // search_len rule (Vod::searchLenCheck()), so every link of the page leads to a page that lists the same rows. Links
        // built from the cut value did not: a cut that ends on a space loses it through mac_param_url()'s trim on the next
        // request (the canonical 301 of such a page listed other rows). Every other field is cut as in 1.1.
        $param = method_exists(\app\common\model\Vod::class, 'searchLenParam') ? \app\common\model\Vod::searchLenParam($param) : mac_search_len_check($param);
        // Apply the configured filter to search parameters before rendering.
        if(!empty($GLOBALS['config']['app']['wall_filter'])){
            $param = mac_escape_param($param);
        }
        $this->assign('param',$param);
    }

    protected function label_type($view=0, $type_id_specified = 0)
    {
        $param = mac_param_url();
        $param = mac_filter_words($param);
        // 1.2.0 (search-r4 F-r4-1, lane S HP-S-R4-1): as in label_search().
        $param = method_exists(\app\common\model\Vod::class, 'searchLenParam') ? \app\common\model\Vod::searchLenParam($param) : mac_search_len_check($param);
        $info = mac_label_type($param, $type_id_specified);
        if(!empty($GLOBALS['config']['app']['wall_filter'])){
            $param['wd'] = mac_escape_param($param['wd']);
        }
        $this->assign('param',$param);
        $this->assign('obj',$info);
        // mac_label_type() returns ['parent'=>...] for an unknown slug: answer 404, not a 200 "membership" message.
        if(empty($info) || empty($info['type_id'])){
            // A renamed category address answers 301 with its current one (config category_slug_redirects).
            $this->type_slug_redirect();
            $this->page_error(lang('controller/get_type_err'));
        }
        // A hidden category (type_status 0) is gone for visitors and search engines, like an unknown one (admin r5 beginner P1-1).
        if (isset($info['type_status']) && (int)$info['type_status'] !== 1) {
            $this->page_error(lang('controller/get_type_err'));
        }
        if($view<2) {
            $res = $this->check_user_popedom($info['type_id'], 1);
            if($res['code']>1){
                echo $this->error($res['msg'], mac_url('user/index') );
                exit;
            }
        }
        return $info;
    }

    /**
     * HTTP 301 for a renamed category address (config category_slug_redirects: old type_en => current type_en) on
     * /category/<old>/, /category/<old>-<page>/ and /show/<old>/...: the old segment of the requested path becomes the
     * current address; page, filters and query string stay. Only GET/HEAD on vod/type and vod/show, only after the
     * lookup failed (no live category uses the old address) and only when the current address is a live category.
     */
    protected function type_slug_redirect()
    {
        $map = config('category_slug_redirects');
        $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : '');
        if (!is_array($map) || !$map || !in_array($method, ['GET', 'HEAD'], true) || strtolower((string)$this->_cl) !== 'vod'
            || !in_array(strtolower((string)$this->_ac), ['type', 'show'], true)) {
            return;
        }
        $request = Request::instance();
        $route = $request->route(false);
        $id = isset($route['id']) && is_scalar($route['id']) ? (string)$route['id'] : '';
        if ($id === '') {
            return;
        }
        // /category/<slug>-<digits>/ also matches the <id>-<page> rule with the digits as the page: the joined form is
        // tried first, as mac_resolve_type_route() does for live addresses.
        $candidates = [];
        $info = $request->routeInfo();
        $rule = isset($info['rule']) ? (is_array($info['rule']) ? implode('/', $info['rule']) : (string)$info['rule']) : '';
        if ((strpos($rule, '<id>-<page?>') !== false || strpos($rule, ':id-[:page]') !== false)
            && isset($route['page']) && is_scalar($route['page']) && preg_match('/\A[0-9]+\z/', (string)$route['page'])) {
            $candidates[] = $id . '-' . $route['page'];
        }
        $candidates[] = $id;
        $live = [];
        foreach ((array)model('Type')->getCache('type_list') as $type) {
            if (is_array($type) && (int)$type['type_mid'] === 1 && !empty($type['type_status'])) {
                $live[(string)$type['type_en']] = true;
            }
        }
        // The Location is built from the request line only (REQUEST_URI), never from proxy/rewrite headers.
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $cut = strcspn($uri, '?#');
        $path = (string)substr($uri, 0, $cut);
        $query = (string)substr($uri, $cut);
        $root = rtrim((string)$request->root(), '/');
        $prefix = ($root !== '' && strpos($path, $root . '/') === 0) ? $root : '';
        $rest = (string)substr($path, strlen($prefix));
        if ($rest === '' || $rest[0] !== '/' || strpos($path, '//') === 0 || strpos($path, '\\') !== false) {
            return;
        }
        foreach ($candidates as $old) {
            $new = isset($map[$old]) && is_string($map[$old]) ? $map[$old] : '';
            if ($new === '' || $new === $old || isset($live[$old]) || !isset($live[$new])
                || !preg_match('/\A[A-Za-z0-9_-]+\z/', $old) || !preg_match('/\A[A-Za-z0-9_-]+\z/', $new)) {
                continue;
            }
            // The old address as one whole path segment, optionally followed by -<page> (/category/avdb-6-2/).
            $target = preg_replace('~/' . preg_quote($old, '~') . '(?=(?:-[0-9]+)?(?:[/.]|\z))~', '/' . $new, $rest, 1, $count);
            if ($count === 1 && is_string($target)) {
                throw new \think\exception\HttpResponseException(redirect($prefix . $target . $query, 301));
            }
        }
    }

    protected function label_actor($total='')
    {
        $param = mac_param_url();
        $this->assign('param',$param);
    }

    protected function label_actor_detail($info=[],$view=0)
    {
        $param = mac_param_url();
        $this->assign('param',$param);
        if(empty($info)) {
            $res = mac_label_actor_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);;
            }
            $info = $res['info'];
        }

        if(empty($info['actor_tpl'])){
            $info['actor_tpl'] = $info['type']['type_tpl_detail'];
        }

        if($view <2) {
            $popedom = $this->check_user_popedom($info['type_id'], 2,$param,'actor',$info);
            $this->assign('popedom',$popedom);

            if($popedom['code']>1){
                $this->assign('obj',$info);

                if($popedom['confirm']==1){
                    echo $this->fetch('actor/confirm');
                    exit;
                }

                echo $this->error($popedom['msg'], mac_url('user/index') );
                exit;
            }
        }

        $this->assign('obj',$info);
        $comment = config('maccms.comment');
        $this->assign('comment',$comment);
        return $info;
    }


    protected function label_role($total='')
    {
        $param = mac_param_url();
        $param = mac_filter_words($param);
        $param = mac_search_len_check($param);
        if(!empty($GLOBALS['app']['wall_filter'])){
            $param['wd'] = mac_escape_param($param['wd']);
        }
        $this->assign('param',$param);
    }

    protected function label_role_detail($info=[])
    {
        $param = mac_param_url();
        $this->assign('param',$param);
        if(empty($info)) {
            $res = mac_label_role_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);;
            }
            $info = $res['info'];
        }
        $this->assign('obj',$info);
        $comment = config('maccms.comment');
        $this->assign('comment',$comment);

        return $info;
    }

    protected function label_website_detail($info=[],$view=0)
    {
        $param = mac_param_url();
        $this->assign('param',$param);
        if(empty($info)) {
            $res = mac_label_website_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);;
            }
            $info = $res['info'];
        }

        if(empty($info['website_tpl'])){
            $info['website_tpl'] = $info['type']['type_tpl_detail'];
        }

        if($view <2) {
            $popedom = $this->check_user_popedom($info['type_id'], 2,$param,'website',$info);
            $this->assign('popedom',$popedom);

            if($popedom['code']>1){
                $this->assign('obj',$info);

                if($popedom['confirm']==1){
                    echo $this->fetch('website/confirm');
                    exit;
                }

                echo $this->error($popedom['msg'], mac_url('user/index') );
                exit;
            }
        }

        $this->assign('obj',$info);
        $comment = config('maccms.comment');
        $this->assign('comment',$comment);

        return $info;
    }

    protected function label_topic_index($total='')
    {
        $param = mac_param_url();
        $this->assign('param',$param);

        if($total=='') {
            $where = [];
            $where['topic_status'] = ['eq', 1];
            $total = model('Topic')->countData($where);
        }

        $url = mac_url_topic_index(['page'=>'PAGELINK']);
        $__PAGING__ = mac_page_param($total,1,$param['page'],$url);
        $this->assign('__PAGING__',$__PAGING__);
    }

    protected function label_topic_detail($info=[])
    {
        $param = mac_param_url();
        $this->assign('param',$param);
        if(empty($info)) {
            $res = mac_label_topic_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);;
            }
            $info = $res['info'];
        }
        $this->assign('obj',$info);

        $comment = config('maccms.comment');
        $this->assign('comment',$comment);

        return $info;
    }

    protected function label_art_detail($info=[],$view=0)
    {
        $param = mac_param_url();
        $this->assign('param',$param);

        if(empty($info)) {
            $res = mac_label_art_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);;
            }
            $info = $res['info'];
        }
        if(empty($info['art_tpl'])){
            $info['art_tpl'] = $info['type']['type_tpl_detail'];
        }

        if($view <2) {
            $permissionParam = $param;
            $permissionParam['id'] = (int)$info['art_id'];
            $popedom = $this->check_user_popedom($info['type_id'], 2,$permissionParam,'art',$info);
            $this->assign('popedom',$popedom);

            if($popedom['code']>1){
                $this->assign('obj',$info);

                if($popedom['confirm']==1){
                    echo $this->fetch('art/confirm');
                    exit;
                }

                echo $this->error($popedom['msg'], mac_url('user/index') );
                exit;
            }
        }

        $this->assign('obj',$info);

        $url = mac_url_art_detail($info,['page'=>'PAGELINK']);

        $__PAGING__ = mac_page_param($info['art_page_total'],1,$param['page'],$url);
        $this->assign('__PAGING__',$__PAGING__);

        $this->label_comment();

        return $info;
    }

    protected function label_vod_detail($info=[],$view=0)
    {
        $param = mac_param_url();

        $this->assign('param',$param);
        if(empty($info)) {
            $res = mac_label_vod_detail($param);
            if ($res['code'] > 1){
                $this->page_error($res['msg']);
            }
            $info = $res['info'];
        }

        if(empty($info['vod_tpl'])){
            $info['vod_tpl'] = $info['type']['type_tpl_detail'];
        }
        if(empty($info['vod_tpl_play'])){
            $info['vod_tpl_play'] = $info['type']['type_tpl_play'];
        }
        if(empty($info['vod_tpl_down'])){
            $info['vod_tpl_down'] = $info['type']['type_tpl_down'];
        }

        if($view <2) {
            $res = $this->check_user_popedom($info['type']['type_id'], 2);
            if($res['code']>1){
                echo $this->error($res['msg'], mac_url('user/index') );
                exit;
            }
        }
        $this->assign('obj',\app\common\util\AvdbLanguage::localize($info));
        $this->label_comment();

        return $info;
    }

    protected function label_vod_role($info=[],$view=0)
    {
        $param = mac_param_url();
        $this->assign('param', $param);

        if (empty($info)) {
            $res = mac_label_vod_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);
            }
            $info = $res['info'];
        }
        $role = mac_label_vod_role(['rid'=>intval($info['vod_id'])]);
        if ($role['code'] > 1) {
            return $this->error($role['msg']);
        }
        $info['role'] = $role['list'];

        $this->assign('obj',\app\common\util\AvdbLanguage::localize($info));
    }

    protected function label_vod_play($flag='play',$info=[],$view=0,$pe=0)
    {
        $param = mac_param_url();
        $this->assign('param',$param);

        if(empty($info)) {
            $res = mac_label_vod_detail($param);
            if ($res['code'] > 1) {
                $this->page_error($res['msg']);
            }
            $info = $res['info'];
        }
        if(empty($info['vod_tpl'])){
            $info['vod_tpl'] = $info['type']['type_tpl_detail'];
        }
        if(empty($info['vod_tpl_play'])){
            $info['vod_tpl_play'] = $info['type']['type_tpl_play'];
        }
        if(empty($info['vod_tpl_down'])){
            $info['vod_tpl_down'] = $info['type']['type_tpl_down'];
        }

        // A source/episode that does not exist is a 404 (soft 404 before). A video without any
        // playable source keeps its default first-episode URL, which the listing cards link to.
        $episodeList = isset($info['vod_'.$flag.'_list']) && is_array($info['vod_'.$flag.'_list']) ? $info['vod_'.$flag.'_list'] : [];
        $episodeSid = (int)$param['sid'];
        $episodeNid = (int)$param['nid'];
        if (!isset($episodeList[$episodeSid]['urls'][$episodeNid]) && !($episodeList === [] && $episodeSid <= 1 && $episodeNid <= 1)) {
            $this->page_error(lang('page_not_found'));
        }

        $permissionParam = $param;
        $permissionParam['id'] = (int)$info['vod_id'];
        $trysee = 0;
        $urlfun='mac_url_vod_'.$flag;
        $listfun = 'vod_'.$flag.'_list';
        if($view <2) {
            if ($flag == 'play') {
                $trysee = $GLOBALS['config']['user']['trysee'];
                if($info['vod_trysee'] >0){
                    $trysee = $info['vod_trysee'];
                }
                $popedom = $this->check_user_popedom($info['type_id'], 3,$permissionParam,$flag,$info,$trysee);
            }
            else {
                $popedom =  $this->check_user_popedom($info['type_id'], 4,$permissionParam,$flag,$info);
            }
            $this->assign('popedom',$popedom);


            if($popedom['code']>1 && empty($popedom["trysee"])){
                $info['player_info']['flag'] = $flag;
                $this->assign('obj',\app\common\util\AvdbLanguage::localize($info));

                if($popedom['confirm']==1){
                    $this->assign('flag',$flag);
                    echo $this->fetch('vod/confirm');
                    exit;
                }
                echo $this->error($popedom['msg'], mac_url('user/index') );
                exit;
            }
        }

        $player_info=[];
        $player_info['flag'] = $flag;
        $player_info['encrypt'] = intval($GLOBALS['config']['app']['encrypt']);
        $player_info['trysee'] = intval($trysee);
        $player_info['points'] = intval($info['vod_points_'.$flag]);
        $player_info['link'] = $urlfun($info,['sid'=>'{sid}','nid'=>'{nid}']);
        $player_info['link_next'] = '';
        $player_info['link_pre'] = '';
        $player_info['vod_data'] = [
            'vod_name'     => $info['vod_name'],
            'vod_actor'    => $info['vod_actor'],
            'vod_director' => $info['vod_director'],
            'vod_class'    => $info['vod_class'],
            'vod_pic'      => $info['vod_pic'],
            'vod_poster'   => $info['vod_pic_slide'],
        ];
        if($param['nid']>1){
            $player_info['link_pre'] = $urlfun($info,['sid'=>$param['sid'],'nid'=>$param['nid']-1]);
        }
        if($param['nid'] < $info['vod_'.$flag.'_list'][$param['sid']]['url_count']){
            $player_info['link_next'] = $urlfun($info,['sid'=>$param['sid'],'nid'=>$param['nid']+1]);
        }
        $player_info['url'] = (string)$info[$listfun][$param['sid']]['urls'][$param['nid']]['url'];
        $player_info['url_next'] = (string)$info[$listfun][$param['sid']]['urls'][$param['nid']+1]['url'];

        if(substr($player_info['url'],0,6) == 'upload'){
            $player_info['url'] = MAC_PATH . $player_info['url'];
        }
        if(substr($player_info['url_next'],0,6) == 'upload'){
            $player_info['url_next'] = MAC_PATH . $player_info['url_next'];
        }

        $player_info['from'] = (string)$info[$listfun][$param['sid']]['from'];
        if((string)$info[$listfun][$param['sid']]['urls'][$param['nid']]['from'] != $player_info['from']){
            $player_info['from'] = (string)$info[$listfun][$param['sid']]['urls'][$param['nid']]['from'];
        }
        $player_info['server'] = (string)$info[$listfun][$param['sid']]['server'];
        $player_info['note'] = (string)$info[$listfun][$param['sid']]['note'];
        // Accessible name of the player frame in the visitor's language (static/js/player.js).
        $player_info['frame_title'] = \app\common\util\TemplateText::raw('ui.iframeplayer');

        if($GLOBALS['config']['app']['encrypt']=='1'){
            $player_info['url'] = mac_escape($player_info['url']);
            $player_info['url_next'] = mac_escape($player_info['url_next']);
        }
        elseif($GLOBALS['config']['app']['encrypt']=='2'){
            $player_info['url'] = base64_encode(mac_escape($player_info['url']));
            $player_info['url_next'] = base64_encode(mac_escape($player_info['url_next']));
        }
        $player_info['id'] = $param['id'];
        $player_info['sid'] = $param['sid'];
        $player_info['nid'] = $param['nid'];
        $info['player_info'] = $player_info;
        $this->assign('obj',\app\common\util\AvdbLanguage::localize($info));

        $pwd_key = '1-'.($flag=='play' ?'4':'5').'-'.$info['vod_id'];

        if( $pe==0 && $flag=='play' && ($popedom['trysee']>0 ) || ($info['vod_pwd_'.$flag]!='' && session($pwd_key)!='1') || ($info['vod_copyright']==1 && $GLOBALS['config']['app']['copyright_status']==4) ) {
            $id = $info['vod_id'];
            if ((int)$GLOBALS['config']['rewrite']['vod_id'] === 1) {
                $id = $info['vod_sub'];
            } elseif($GLOBALS['config']['rewrite']['vod_id']==2){
                $id = mac_alphaID($info['vod_id'],false,$GLOBALS['config']['rewrite']['encode_len'],$GLOBALS['config']['rewrite']['encode_key']);
            }
            $dy_play = htmlspecialchars(mac_url('index/vod/'.$flag.'er',['id'=>$id,'sid'=>$param['sid'],'nid'=>$param['nid']]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $this->assign('player_data','');
            $this->assign('player_js','<div class="MacPlayer" style="z-index:99999;width:100%;height:100%;margin:0px;padding:0px;"><iframe id="player_if" name="player_if" src="'.$dy_play.'" style="z-index:9;width:100%;height:100%;" border="0" marginWidth="0" frameSpacing="0" marginHeight="0" frameBorder="0" scrolling="no" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen="allowfullscreen" mozallowfullscreen="mozallowfullscreen" msallowfullscreen="msallowfullscreen" oallowfullscreen="oallowfullscreen" webkitallowfullscreen="webkitallowfullscreen" ></iframe></div>');
        }
        else {
            $this->assign('player_data', '<script type="text/javascript">var player_aaaa=' . json_encode($player_info, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>');
            // Use an explicit player revision so browsers do not keep the legacy
            // obfuscated bootstrap after a security update.
            $this->assign('player_js', '<script type="text/javascript" src="' . MAC_PATH . 'static/js/playerconfig.js?t='.$this->_tsp.'"></script><script type="text/javascript" src="' . MAC_PATH . 'static/js/player.js?t=' . (is_file(APP_PATH . '../static/js/player.js') ? filemtime(APP_PATH . '../static/js/player.js') : '20261009-clean8') . '"></script>');
        }
        $this->label_comment();
        return $info;
    }

    protected function settings_cache_revision()
    {
        // A settings save must not reuse HTML from the previous configuration.
        // Preserve model-cache namespaces and collection resume checkpoints.
        return defined('ENTRANCE') && ENTRANCE === 'index'
            ? '_settings_' . substr(hash('sha256', serialize($GLOBALS['config'])), 0, 16)
            : '';
    }

    /**
     * Revision of every file in the active theme's folder (templates, lang/ catalogs, assets), from a
     * directory listing (path, mtime, size). Kept for 10 seconds, so a request costs one cache read.
     */
    protected function theme_files_revision($templateRoot)
    {
        static $memo = [];
        $templateRoot = (string)$templateRoot;
        if ($templateRoot === '' || !is_dir($templateRoot)) {
            return '0';
        }
        if (isset($memo[$templateRoot])) {
            return $memo[$templateRoot];
        }
        $cacheKey = 'theme_files_rev_v2_' . md5($templateRoot);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $memo[$templateRoot] = $cached;
        }
        $entries = [];
        $themeRoot = dirname(rtrim(str_replace('\\', '/', $templateRoot), '/')) . '/';
        // The whole theme folder (html/, lang/, assets/, ksassets/, statics/, ...): pages print asset ?v= revisions,
        // so a CSS or JS edit refreshes cached HTML too. Never the shared template/ root itself.
        $directories = basename(rtrim($themeRoot, '/')) !== 'template' && is_dir($themeRoot)
            ? [$themeRoot] : [$templateRoot, $themeRoot . 'lang/'];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            try {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $entries[] = str_replace('\\', '/', $file->getPathname()) . '|' . $file->getMTime() . '|' . $file->getSize();
                    }
                }
            } catch (\Throwable $error) {
                $entries[] = $directory . '|unreadable';
            }
        }
        sort($entries, SORT_STRING);
        $revision = substr(hash('sha256', implode("\n", $entries)), 0, 16);
        Cache::set($cacheKey, $revision, 10);
        return $memo[$templateRoot] = $revision;
    }

    protected function page_cache_key($tpl)
    {
        $param = mac_param_url(); ksort($param, SORT_STRING);
        // 1.2.0 (verify r3 N19, open mode): a continuation page is its own page, never page 1's cache entry. mac_param_url()
        // carries neither after= (keyset lists) nor cursor= (searches), which Vod::listCacheData reads from the query string;
        // the same shapes it accepts are part of the key (a malformed value renders page 1, so it shares page 1's key).
        if (isset($_GET['after']) && is_string($_GET['after']) && preg_match('/^[0-9]{1,10}\.[0-9]{1,10}$/D', $_GET['after'])) {
            $param['after'] = $_GET['after'];
        }
        if (isset($_GET['cursor']) && is_string($_GET['cursor']) && preg_match('/^[1-9][0-9]{0,17}$/D', $_GET['cursor'])) {
            $param['cursor'] = $_GET['cursor'];
        }
        ksort($param, SORT_STRING);
        // A selected theme and its SEO includes must not reuse another theme's HTML.
        $templateRoot = defined('MAC_ROOT_TEMPLATE') ? MAC_ROOT_TEMPLATE : '';
        $templatePath = str_replace('\\', '/', (string)$tpl);
        $templatePath = preg_replace('~\.html$~i', '', $templatePath);
        $revision = [filemtime(__FILE__), $GLOBALS['config']['site']['template_dir'] ?? '', $GLOBALS['config']['site']['html_dir'] ?? '', $templatePath];
        // Any edited theme file (page, include, fragment) refreshes the key; core helpers that print into pages too.
        $revision[] = $this->theme_files_revision($templateRoot);
        foreach ([APP_PATH . 'common.php', __DIR__ . '/../util/TemplateSeo.php', __DIR__ . '/../util/Sitemap.php', __DIR__ . '/../util/TemplateText.php', __DIR__ . '/../util/AutoIcon.php', __DIR__ . '/../util/AutoIconRules.php'] as $coreFile) {
            $revision[] = is_file($coreFile) ? filemtime($coreFile) : 0;
        }
        // Canonical/absolute URLs depend on the scheme the page was requested with.
        $revision[] = \app\common\util\Sitemap::scheme();
        // Visitor theme switcher: offered list / position / preview flag / widget assets (never the visitor's own choice;
        // the rendered theme itself is the explicit _th_ part of the key below).
        $revision[] = \app\common\util\ThemeSwitcher::cacheKeyPart();
        foreach (\app\common\util\ThemeSwitcher::coreFiles() as $coreFile) {
            $revision[] = is_file($coreFile) ? filemtime($coreFile) : 0;
        }
        // [icon-merge] insertion point: icon-system key parts go here (ICON-CORE-PATCH all.diff edits the core-file foreach above).
        $revision[] = \app\common\util\JcxPlacer::cacheKeyPart(); foreach (\app\common\util\JcxPlacer::coreFiles() as $f) { $revision[] = is_file($f) ? filemtime($f) : 0; }
        $revision[] = \app\common\util\SiteHead::cacheKeyPart(); foreach (\app\common\util\SiteHead::coreFiles() as $f) { $revision[] = is_file($f) ? filemtime($f) : 0; }
        // r5 repair (beginner r5 P2-1): a category added / changed / hidden / deleted / moved refreshes the pages that list
        // categories (Type::pageRevision() changes only on those edits, never on Clear cache).
        $revision[] = method_exists('\app\common\model\Type', 'pageRevision') ? \app\common\model\Type::pageRevision() : 0;
        // Interface catalog edits (shared or theme-owned) must show without waiting for the page TTL.
        $catalogFiles = array_merge(\app\common\util\TemplateText::catalogFiles('en'), \app\common\util\TemplateText::catalogFiles(\app\common\util\TemplateText::locale()));
        foreach (array_unique($catalogFiles) as $catalogFile) {
            $revision[] = is_file($catalogFile) ? filemtime($catalogFile) : 0;
        }
        // 1.2.0 (D-L12): list revision instead of the 'vod' tag (imports bump it at most every 5 minutes, admin saves at once).
        $revision[] = method_exists('\app\common\model\Vod', 'listRevision') ? \app\common\model\Vod::listRevision() : 0;
        // 1.2.0 (HO-T-2): which categories hold videos (menus with hide_empty); changes with the background recount.
        $revision[] = class_exists('\app\common\util\ScaleCounts') ? \app\common\util\ScaleCounts::menuSignature() : '';
        $themeRevision = substr(hash('sha256', json_encode($revision)), 0, 16);
        // 1.2.0 (L3, se-R12): the configured host, never the raw Host header (a random Host must not create new cache entries).
        $keyHost = class_exists('\app\common\util\ScaleTier') ? \app\common\util\ScaleTier::keyHost($_SERVER) : strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        return $keyHost . '_' . MAC_MOB . '_' . $GLOBALS['config']['app']['cache_flag'] . '_community_recycle_v2_seo_v26_' . \app\common\model\Vod::catalogRevision() . '_' . $themeRevision . '_th_' . (defined('MAC_TEMPLATE_DIR') ? MAC_TEMPLATE_DIR : '') . '_' . \app\common\util\AvdbLanguage::cacheSuffix() . $this->settings_cache_revision() . '_' . $tpl . '_' . http_build_query($param);
    }
}

