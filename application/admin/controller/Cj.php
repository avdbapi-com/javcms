<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\admin\controller;
use think\Db;
use app\common\util\Collection as cjOper;

class Cj extends Base
{
    var $_isall=0;

    public function __construct()
    {
        parent::__construct();
    }

    // xss-fix-A-2 (security r8 P1-6): custom fields of a rule (from the editor or an imported rule file) keep only
    // well-formed rows: a display name, a short code name of letters / digits / "_", and plain-text rules.
    private function axCustomFields($raw)
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $rows = [];
        foreach ((is_array($raw) ? $raw : []) as $one) {
            if (!is_array($one)) { continue; }
            $row = [];
            foreach (['name', 'en_name', 'rule', 'html_rule'] as $f) {
                $row[$f] = isset($one[$f]) && is_scalar($one[$f]) ? trim((string)$one[$f]) : '';
            }
            if ($row['name'] === '' || !preg_match('/^[A-Za-z0-9_]{1,30}$/D', $row['en_name'])) { continue; }
            $rows[] = $row;
        }
        return $rows;
    }

    // List scraper rules.
    public function index()
    {
        $param = input();
        $param['page'] = intval($param['page']) <1 ? 1 : intval($param['page']);
        $param['limit'] = intval($param['limit']) <1 ? (int)$this->_pagesize : min(max(intval($param['limit']), 1), 1000);
        $where=[];

        $order='nodeid desc';
        $res = model('Cj')->listData('cj_node',$where,$order,$param['page'],$param['limit']);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);
        $title = lang('admin/ux/menu/scraper_rules');
        $title = $title === 'admin/ux/menu/scraper_rules' ? lang('admin/cj/title') : $title;
        $help = lang('admin/ux/help/scraper_rules');
        $this->assign('title', $title);
        $this->assign('ax_head', ['title' => $title, 'help' => $help === 'admin/ux/help/scraper_rules' ? '' : $help]);

        return $this->fetch('admin@cj/index');
    }


    public function info()
    {
        if (Request()->isPost()) {
            // close-security (function r4 P2-2): the rule editor posts __token__; a save replayed without it changes nothing.
            if (!$this->axPostCsrfOk()) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            $param = input('post.');
            unset($param['__token__']);
            $data = isset($param['data']) && is_array($param['data']) ? $param['data'] : [];
            if (empty($data)) {
                return $this->error(lang('param_err'));
            }
            $srcType = isset($data['sourcetype']) && is_scalar($data['sourcetype']) ? (string)$data['sourcetype'] : '';
            $data['urlpage'] = isset($param['urlpage' . $srcType]) && is_scalar($param['urlpage' . $srcType]) ? (string)$param['urlpage' . $srcType] : '';
            if(!empty($data['customize_config'])){
                $customize_config = is_array($data['customize_config']) ? $data['customize_config'] : [];
                unset($data['customize_config']);
                $rows = [];
                foreach ((isset($customize_config['name']) && is_array($customize_config['name']) ? $customize_config['name'] : []) as $k => $v) {
                    $one = [];
                    foreach (['name', 'en_name', 'rule', 'html_rule'] as $f) {
                        $one[$f] = isset($customize_config[$f][$k]) ? $customize_config[$f][$k] : '';
                    }
                    $rows[] = $one;
                }
                $data['customize_config'] = json_encode($this->axCustomFields($rows), JSON_FORCE_OBJECT);
            }
            $res = model('Cj')->saveData($data);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        $where=[];
        $where['nodeid'] = ['eq',$id];
        $res = model('Cj')->infoData('cj_node',$where);
        if(!empty($res['info']['customize_config'])){
            // r7 repair b: an imported rule file may hold anything here; keep only rows of plain text fields (the view escapes them).
            $custom = json_decode((string)$res['info']['customize_config'], true);
            $rows = [];
            foreach ((is_array($custom) ? $custom : []) as $one) {
                if (!is_array($one)) { continue; }
                $row = [];
                foreach (['name', 'en_name', 'rule', 'html_rule'] as $f) {
                    $row[$f] = isset($one[$f]) && is_scalar($one[$f]) ? (string)$one[$f] : '';
                }
                $rows[] = $row;
            }
            $res['info']['customize_config'] = $rows;
        }
        $this->assign('data',$res['info']);
        $this->assign('title',lang('admin/cj/title'));
        return $this->fetch('admin@cj/info');
    }

    public function program()
    {
        $param = input();
        // close-security: the rule id is a plain number (it is printed into the form and used in the query).
        $param['id'] = isset($param['id']) && is_scalar($param['id']) ? (int)$param['id'] : 0;
        if (Request()->isPost()) {
            // close-security (function r4 P2-2): the publish-plan form posts __token__; a save replayed without it changes nothing.
            if (!$this->axPostCsrfOk()) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            foreach (['model_field', 'node_field', 'funcs'] as $k) {
                $param[$k] = isset($param[$k]) && is_array($param[$k]) ? $param[$k] : [];
            }
        }
        $where=[];
        $where['nodeid'] = $param['id'];
        $res = model('Cj')->infoData('cj_node',$where);
        if($res['code']>1){
            return $this->error($res['msg']);
        }

        if (Request()->isPost()) {
            $program_config = [];
            foreach($param['model_field'] as $k=>$v){
                if(!empty($param['node_field'][$k])){
                    $program_config['map'][$v] = $param['node_field'][$k];
                    $program_config['funcs'][$v] = (isset($param['funcs'][$k]) && $param['funcs'][$k] === 'trim') ? 'trim' : '';
                }
            }
            $update=[];
            $update['nodeid'] = $param['id'];
            $update['program_config'] = json_encode($program_config);
            $res = model('Cj')->saveData($update);
            if($res['code']>1){
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $program_config = [];
        if(!empty($res['info']['program_config'])){
            $program_config = json_decode($res['info']['program_config'],true);
        }
        $this->assign('program_config',$program_config);


        $node_field = array('title'=>lang('title'),'type'=>lang('type'), 'content'=>lang('content'));
        $customize_config = $this->axCustomFields(isset($res['info']['customize_config']) ? (string)$res['info']['customize_config'] : '');
        foreach ($customize_config as $v) {
            $node_field[$v['en_name']] = html_entity_decode($v['name'], ENT_QUOTES, 'UTF-8');
        }
        $this->assign('node_field',$node_field);

        $table = 'vod';
        if($res['info']['mid'] =='2'){
            $table='art';
        }
        $column_list = Db::query('SHOW COLUMNS FROM '.config('database.prefix').$table);
        $this->assign('column_list',$column_list);
        $this->assign('param',$param);
        return $this->fetch('admin@cj/program');
    }

    public function col_all($param = [])
    {
        // close-security (security r4 P3-5): "collect everything" ends in content_into (writes videos / articles).
        // No admin page links it; a request must be a POST with the admin token.
        // r7 repair: the scheduled task (api.php Timming, ENTRANCE=api) calls col_all($output) in the same process, as
        // Collect::api() does; from the admin entry $param is never trusted (the framework could bind it from the URL).
        $internal = defined('ENTRANCE') && ENTRANCE === 'api' && is_array($param) && !empty($param);
        if (!$internal) {
            if (!$this->axPostCsrfOk()) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            $param = input('post.');
            unset($param['__token__']);
        }
        $this->_isall=1;
        return $this->colUrlRun($param);
    }


    /**
     * Scrape content URLs from the configured listing pages ("Scrape URLs" on Scraper rules).
     * r7 repair (security r6 P1-2): this writes cj_history / cj_content rows and fetches the rule's pages, so it goes
     * through cjRunGate like content_into: step 1 = POST + the admin token (the button posts it), which starts a run key
     * bound to the rule id; the next pages are GET jump links with only id, page and run. Anything else changes nothing.
     */
    public function col_url()
    {
        $param = input();
        $gate = $this->cjRunGate('url', $param);
        if ($gate !== true) {
            return $gate;
        }
        return $this->colUrlRun($param);
    }

    /** r7 repair: the "Scrape URLs" engine, called only by the gated col_url() and by col_all(). Not a route. */
    protected function colUrlRun($param)
    {
        $param['id'] = isset($param['id']) && is_scalar($param['id']) ? (int)$param['id'] : 0;
        $where=[];
        $where['nodeid'] = $param['id'];
        $res = model('Cj')->infoData('cj_node',$where);
        if($res['code']>1){
            return $this->error($res['msg']);
        }
        $data = $res['info'];
        $collection = new cjOper();
        $urls = $collection->url_list($data);

        $total_page = count($urls);
        if (empty($total_page)){
            return $this->error(lang('admin/cj/url_list_err'));
        }

        $param['page'] = isset($param['page']) ? intval($param['page']) : 1;

        $url_list = $urls[$param['page']-1];
        $url = $collection->get_url_lists($url_list, $data);

        $total = count($url);
        $re = 0;
        if (is_array($url) && !empty($url)) {
            foreach ($url as $v) {
                if (empty($v['url']) || empty($v['title'])) {
                    $re++;
                    continue;
                }
                $v['title'] = strip_tags($v['title']);
                $md5 = md5($v['url']);
                $where=[];
                $where['md5'] = $md5;
                $history = model('Cj')->infoData('cj_history',$where);
                if($history['code']>1){
                    Db::name('cj_history')->insert(array('md5' => $md5));
                    // r7 repair: "data" has no default value; strict MySQL refused the row without it (error 1364).
                    Db::name('cj_content')->insert(array('nodeid'=>$param['id'], 'status'=>1, 'url'=>$v['url'], 'title'=>$v['title'], 'data'=>''));
                }
                else {
                    $re++;
                }
            }
        }
        if ($total_page <= $param['page']) {
            $time = time();
            Db::name('cj_node')->where('nodeid',$param['id'])->update(array('lastdate' => $time));
        }
        if($this->_isall==1){
            mac_echo(lang('admin/cj/url_cj_complete'));
            $this->colContentRun($param);
            exit;
        }
        $this->assign('param',$param);
		$this->assign('url_list', $url_list);
		$this->assign('total_page', $total_page);
		$this->assign('re', $re);
		$this->assign('url', $url);
		$this->assign('page',$param['page']);
		$this->assign('total',$total);
        $this->assign('title',lang('admin/cj/url/title'));
        if($total_page > $param['page']){
            mac_echo(lang('server_rest'));
            $param['page'] ++;
            // r7 repair: the next page carries only the rule id, the page and the run key (never the admin token).
            $link = url('cj/col_url') . '?'. http_build_query(['id' => $param['id'], 'page' => $param['page'], 'run' => (string)($param['run'] ?? '')]);
            mac_jump( $link ,3);
        }
        else{
            session('admin_cj_run_url', null);
            mac_echo(lang('admin/cj/url_cj_complete'));
        }
        return $this->fetch('admin@cj/col_url');
    }

    /**
     * Scrape content from the queued URLs ("Scrape content" on Scraper rules).
     * r7 repair (security r6 P1-2): fetches each queued URL and stores its content, so it goes through cjRunGate like
     * content_into: step 1 = POST + the admin token, the next pages are GET jump links with only id, page and run.
     */
    public function col_content()
    {
        $param = input();
        $gate = $this->cjRunGate('content', $param);
        if ($gate !== true) {
            return $gate;
        }
        return $this->colContentRun($param);
    }

    /** r7 repair: the "Scrape content" engine, called only by the gated col_content() and by the col_all() chain. */
    protected function colContentRun($param)
    {
        $param['id'] = isset($param['id']) && is_scalar($param['id']) ? (int)$param['id'] : 0;
        $collection = new cjOper();
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $total = isset($_GET['total']) ? intval($_GET['total']) : 0;

        $where=[];
        $where['nodeid'] = $param['id'];
        $res = model('Cj')->infoData('cj_node',$where);
        if($res['code']>1){
            return $this->error($res['msg']);
        }
        $data = $res['info'];

        mac_echo('<style type="text/css">body{font-size:12px;color: #333333;line-height:21px;}span{font-weight:bold;color:#FF0000}</style>');

        if(empty($total)){
            $total = Db::name('cj_content')->where('nodeid',$param['id'])->where('status',1)->count();
        }
        $limit = 20;
        $total_page = ceil($total/$limit);
        mac_echo(lang('admin/cj/content/tip',[$total,$total_page,$limit,$page]));

        $list = Db::name('cj_content')->where('nodeid',$param['id'])->where('status',1)->page($total_page-1,$limit)->select();

        $i = 0;
        $ids=[];
        if(!empty($list) && is_array($list)){
            foreach($list as $v){
                $html = $collection->get_content($v['url'],$data);
                Db::name('cj_content')->where('id',$v['id'])->update(['status'=>2, 'data'=>json_encode($html)]);
                $ids[] = $v['id'];
                $i++;

                mac_echo($v['url'].'&nbsp;&nbsp;'.'ok');
            }
        }
        else{
            session('admin_cj_run_content', null);
            mac_echo(lang('admin/cj/content_cj_complete'));
            exit;
        }

        if($this->_isall==1){
            mac_echo(lang('admin/cj/content_cj_complete'));
            $param['ids'] = implode(',',$ids);
            $param['limit'] = 999;
            $this->content_into($param);
            exit;
        }

        if ($total_page > $page){
            mac_echo(lang('server_rest'));
            // r7 repair: same page counting as before (a first step without a page goes on with page=1); the link
            // carries only the rule id, the page and the run key.
            $next = (isset($param['page']) && is_scalar($param['page']) ? intval($param['page']) : 0) + 1;
            $link = url('cj/col_content') . '?'. http_build_query(['id' => $param['id'], 'page' => $next, 'run' => (string)($param['run'] ?? '')]);
            mac_jump( $link ,3);
        }
        else{
            $time = time();
            Db::name('cj_node')->where('nodeid',$param['id'])->update(array('lastdate' => $time));
            session('admin_cj_run_content', null);
            mac_echo(lang('admin/cj/cj_complete'));
            exit;
        }
    }


    public function publish()
    {
        $param = input();
        // SEC r4 P1-2: id and status are printed into publish.html links; keep them as plain numbers.
        $param['id'] = isset($param['id']) && is_scalar($param['id']) ? (int)$param['id'] : 0;
        $param['status'] = isset($param['status']) && in_array((string)$param['status'], ['1', '2', '3'], true) ? (string)$param['status'] : '';

        $param['page'] = max(1, intval($param['page']));
        $param['limit'] = intval($param['limit']) <1 ? (int)$this->_pagesize : min(max(intval($param['limit']), 1), 1000);
        $where=[];
        $where['nodeid'] = $param['id'];
        if(!empty($param['status'])){
            $where['status'] = ['eq',$param['status']];
        }

        $order='id desc';
        $res = model('Cj')->listData('cj_content',$where,$order,$param['page'],$param['limit']);

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param',$param);
        $this->assign('title',lang('admin/cj/publish/title'));

        // close-security (visual r4 R4-2): the kit page head names the rule; the confirms name what they delete / import.
        $node = Db::name('cj_node')->field('nodeid,name')->where('nodeid', $param['id'])->find();
        // Stored names may be HTML-encoded: decode once here (the confirms show it as text; page_head decodes on its own).
        $ruleName = $node ? html_entity_decode((string)$node['name'], ENT_QUOTES, 'UTF-8') : '';
        $this->assign('rule_name', $ruleName);
        $this->assign('count_rule', (int)Db::name('cj_content')->where('nodeid', $param['id'])->count());
        $this->assign('count_ready', (int)Db::name('cj_content')->where('nodeid', $param['id'])->where('status', 2)->count());
        $headTitle = lang('admin/cj/content_publish') . ($node && (string)$node['name'] !== '' ? " \xC2\xB7 " . (string)$node['name'] : '');
        $this->assign('ax_head', ['title' => $headTitle, 'help' => lang('admin/ux/sec/cj_publish_help')]);

        return $this->fetch('admin@cj/publish');
    }

    public function show()
    {
        $id = input('id');
        $where=[];
        $where['id'] = ['eq',$id];
        $info = Db::name('cj_content')->where($where)->find();
        // r7 repair b (security P1-1): scraped values are remote text. Hand the view plain strings only (a list or object
        // becomes its JSON text) and the view escapes every one of them.
        $info = is_array($info) ? $info : [];
        foreach (['url', 'title'] as $f) {
            $info[$f] = isset($info[$f]) && is_scalar($info[$f]) ? (string)$info[$f] : '';
        }
        $fields = [];
        $decoded = !empty($info['data']) && is_string($info['data']) ? @json_decode($info['data'], true) : null;
        foreach ((is_array($decoded) ? $decoded : []) as $k => $v) {
            $fields[(string)$k] = ($v === null || is_scalar($v)) ? (string)$v : (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $info['data'] = $fields;
        $this->assign('info',$info);
        $this->assign('title',lang('admin/cj/title'));
        return $this->fetch('admin@cj/show');

    }

    /** Hardening: deletes = POST + the session admin token (__token__ or X-CSRF-Token), as axCsrfOk. */
    private function axPostCsrfOk()
    {
        if (!$this->request->isPost()) {
            return false;
        }
        $post = $this->request->post();
        $sent = isset($post['__token__']) && is_string($post['__token__']) ? $post['__token__'] : (string)$this->request->header('X-CSRF-Token');
        return $sent !== '' && hash_equals((string)mac_admin_csrf_token(), $sent);
    }

    public function content_del()
    {
        if (!$this->axPostCsrfOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param = input('post.');
        $all = isset($param['all']) ? (string)$param['all'] : '';
        // close-security (function r4 P3-5): "Clear all" empties the list of THIS rule only (the page shows one rule; the
        // old code emptied the collected items of every rule). The confirm on the page names the rule and the count.
        $nodeid = isset($param['id']) && is_scalar($param['id']) ? (int)$param['id'] : 0;
        if ($all === '1' && $nodeid < 1) {
            return $this->error(lang('param_err'));
        }
        $rawIds = isset($param['ids']) ? (is_array($param['ids']) ? $param['ids'] : explode(',', (string)$param['ids'])) : [];
        $ids = $all === '1' ? [1] : array_values(array_filter(array_map('intval', $rawIds)));

        if(!empty($ids)){
            $where=[];
            $where['id'] = ['in',$ids];
            if($all=='1'){
                $where = ['nodeid' => $nodeid];
            }
            $urls = [];
            $list = Db::name('cj_content')->field('url')->where($where)->select();
            foreach ($list as $k => $v) {
                $md5 = md5($v['url']);
                $urls[] = $md5;
            }

            if ($urls) {
                $where2=[];
                $where2['md5'] = ['in',$urls];
                Db::name('cj_history')->where($where2)->delete();
            }

            $res = Db::name('cj_content')->where($where)->delete();
            if($res===false){
                return $this->error(lang('del_err').''.$this->getError());
            }
        }
        return $this->success(lang('del_ok'));
    }

    public function content_into($param=[])
    {
        if ($this->_isall != 1) {
            // close-security (security r4 P3-5): Import / Import all write videos or articles into the site. The first step
            // must be a POST with the admin token; the next steps carry a one-off run key kept in the session (never the token).
            $param = input();
            $gate = $this->cjRunGate('into', $param);
            if ($gate !== true) {
                return $gate;
            }
        }

        $param['id'] = isset($param['id']) && is_scalar($param['id']) ? (int)$param['id'] : 0;
        $param['all'] = isset($param['all']) && (string)$param['all'] === '1' ? '1' : '';
        $idList = isset($param['ids']) ? (is_array($param['ids']) ? $param['ids'] : explode(',', (string)$param['ids'])) : [];
        $idList = array_values(array_filter(array_map('intval', $idList)));
        $param['ids'] = implode(',', $idList);
        if ($param['all'] !== '1' && empty($idList)) {
            return $this->error(lang('admin/ux/kit/select_first'));
        }
        $nodeid = $param['id'];
        $ids = $idList;
        $all = $param['all'];
        $param['page'] = max(1, intval($param['page']));
        $param['limit'] = intval($param['limit']) <1 ? (int)$this->_pagesize : min(max(intval($param['limit']), 1), 1000);

        $where=[];
        $where['nodeid'] = $param['id'];
        $res = model('Cj')->infoData('cj_node',$where);
        if($res['code']>1){
            return $this->error($res['msg']);
        }
        $node = $res['info'];
        $where=[];
        $where['nodeid'] = $nodeid;
        $where['status'] =['eq',2];
        $where['id'] = ['in',$ids];
        if($all=='1'){
            $where['id'] = ['gt',0];
        }

        mac_echo('<style type="text/css">body{font-size:12px;color: #333333;line-height:21px;}span{font-weight:bold;color:#FF0000}</style>');
        if(empty($param['total'])) {
            $param['total'] = Db::name('cj_content')->where($where)->count();
        }

        $list = Db::name('cj_content')->where($where)->page($param['page'],$param['limit'])->select();

        $total_page = ceil($param['total']/$param['limit']);
        mac_echo(lang('admin/cj/content_into/tip',[$param['total'],$total_page,$param['limit'],$param['page']]));

        $program_config =[];
        if(!empty($node['program_config'])){
            $program_config = json_decode($node['program_config'],true);
        }

        $inter = mac_interface_type();
        $update_ids = [];
        foreach($list as $k=>$v){
            $data=[];
            $content_data = json_decode($v['data'],true);
            foreach ($program_config['map'] as $a=>$b) {
                // xss-fix-A-2: the only processing step offered is "trim"; a rule file must never name another PHP function
                // (it would run on scraped text). The value comes from the decoded item, like the branch below.
                if (isset($program_config['funcs'][$a]) && $program_config['funcs'][$a] === 'trim') {
                    $data['data'][$k][$a] = trim(isset($content_data[$b]) && is_scalar($content_data[$b]) ? (string)$content_data[$b] : '');
                }
                else {
                    $data['data'][$k][$a] = $content_data[$b];
                }
                if($b=='type' && !is_numeric($content_data[$b])) {

                    if($node['mid'] ==2 ) {
                        $data['data'][$k][$a] = $inter['arttype'][$content_data[$b]];
                    }
                    else{
                        $data['data'][$k][$a] = $inter['vodtype'][$content_data[$b]];
                    }
                }
            }


            if($node['mid'] == '2'){
                $res = model('Collect')->art_data([],$data,0);
            }
            else{
                $res = model('Collect')->vod_data([],$data,0);
            }
            if($res['code'] ==1){
                $update_ids[] = $v['id'];
            }
            mac_echo($res['msg']);
        }

        if(!empty($update_ids)){
            $where=[];
            $where['id'] = ['in',$update_ids];
            $res = Db::name('cj_content')->where($where)->update(['status' => 3]);
        }

        if($this->_isall==1){
            mac_echo(lang('admin/cj/content_into/complete'));
            exit;
        }


        if ($total_page > $param['page']){
            mac_echo(lang('server_rest'));
            $param['page'] ++;
            $link = url('cj/content_into') . '?'. http_build_query($param);
            mac_jump( $link ,3);
        }
        else{
            session('admin_cj_run_into', null);
            mac_echo(lang('import_ok'));
            exit;
        }
    }

    /**
     * close-security: gate for the multi-step scraper jobs (content_into; r7 repair: also col_url and col_content, jobs
     * "url" and "content"). Step 1 = POST + the admin token, which starts
     * a run: a random key in the session, bound to the rule id. Steps 2..n are the GET jump links of the old engine and must
     * carry that key (run=...). The admin token itself never goes into a URL. Returns true or the refusal response.
     */
    private function cjRunGate($job, &$param)
    {
        $key = 'admin_cj_run_' . $job;
        $nodeid = isset($param['id']) && is_scalar($param['id']) ? (int)$param['id'] : 0;
        $run = isset($param['run']) && is_string($param['run']) ? $param['run'] : '';
        unset($param['__token__']);
        if ($run !== '' && !$this->request->isPost()) {
            $saved = session($key);
            if (is_array($saved) && isset($saved['k'], $saved['id']) && (int)$saved['id'] === $nodeid && hash_equals((string)$saved['k'], $run)) {
                return true;
            }
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        if (!$this->axPostCsrfOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param['run'] = bin2hex(random_bytes(12));
        session($key, ['k' => $param['run'], 'id' => $nodeid]);
        return true;
    }


    // Preview the URLs generated by the listing-page rules.
    public function show_url()
    {
        $param = input();
        // xss-fix-A-2 (security r8 P1-2): this preview takes its settings from the link, so it keeps only plain
        // values, caps the number range, and lists only http(s):// addresses (the view escapes each one).
        $data = isset($param['data']) && is_array($param['data']) ? $param['data'] : [];
        $data = array_filter($data, 'is_scalar');
        $srcType = isset($data['sourcetype']) ? (string)$data['sourcetype'] : '';
        $data['sourcetype'] = $srcType;
        $data['urlpage'] = isset($param['urlpage' . $srcType]) && is_scalar($param['urlpage' . $srcType]) ? (string)$param['urlpage' . $srcType] : '';
        $data['pagesize_start'] = isset($data['pagesize_start']) ? intval($data['pagesize_start']) : 1;
        $data['pagesize_end'] = isset($data['pagesize_end']) ? intval($data['pagesize_end']) : $data['pagesize_start'];
        $data['par_num'] = isset($data['par_num']) ? max(1, intval($data['par_num'])) : 1;
        if ($data['pagesize_end'] > $data['pagesize_start'] + 1000 * $data['par_num']) {
            $data['pagesize_end'] = $data['pagesize_start'] + 1000 * $data['par_num'];
        }
        $collection = new cjOper();
        $urls = [];
        foreach ((array)$collection->url_list($data) as $u) {
            $u = is_scalar($u) ? trim((string)$u) : '';
            if ($u !== '' && preg_match('#^https?://#i', $u)) {
                $urls[] = $u;
            }
        }

        $this->assign('urls',$urls);

        return $this->fetch('admin@cj/show_url');
    }

    public function del()
    {
        if (!$this->axPostCsrfOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $ids = array_values(array_filter(array_map('intval', (array)input('post.ids/a', []))));

        if(!empty($ids)){
            $where=[];
            $where['nodeid'] = ['in',$ids];
            $res = model('Cj')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function export()
    {
        $param = input();

        $where=[];
        $where['nodeid'] = $param['id'];
        $res = model('Cj')->infoData('cj_node',$where);
        if($res['code']>1){
            return $this->error($res['msg']);
        }
        $node = $res['info'];

        header("Content-type: application/octet-stream");
        if(strpos($_SERVER['HTTP_USER_AGENT'], "MSIE")) {
            header("Content-Disposition: attachment; filename=mac_cj_" . urlencode($node['name']) . '.txt');
        }
        else{
            header("Content-Disposition: attachment; filename=mac_cj_" . $node['name'] . '.txt');
        }
        echo base64_encode(json_encode($node));
    }

    public function import()
    {
        // close-security (security r4 P3-5): importing a rule file is a write: POST + the admin token (the upload sends it).
        if (!$this->axPostCsrfOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $file = $this->request->file('file');
        if (!$file) {
            return $this->error(lang('admin/upload/no_input_file'));
        }
        $info = $file->rule('uniqid')->validate(['size' => 10240000, 'ext' => 'txt']);
        if ($info) {
            $data = json_decode(base64_decode(file_get_contents($info->getpathName())), true);
            @unlink($info->getpathName());
            if($data && is_array($data)){
                unset($data['nodeid']);
                // xss-fix-A-2 (security r8 P1-6): a rule file may hold anything; keep plain column values only and
                // the custom fields that pass the same check as the rule editor.
                $data = array_filter($data, function ($v, $k) { return is_string($k) && preg_match('/^[a-z0-9_]{1,40}$/D', $k) && (is_scalar($v) || $v === null); }, ARRAY_FILTER_USE_BOTH);
                if (isset($data['customize_config'])) {
                    $data['customize_config'] = json_encode($this->axCustomFields((string)$data['customize_config']), JSON_FORCE_OBJECT);
                }
                if (isset($data['program_config'])) {
                    $pc = json_decode((string)$data['program_config'], true);
                    $clean = [];
                    foreach ((isset($pc['map']) && is_array($pc['map']) ? $pc['map'] : []) as $col => $field) {
                        if (is_string($col) && preg_match('/^[a-z0-9_]{1,64}$/D', $col) && is_scalar($field) && preg_match('/^[A-Za-z0-9_]{1,30}$/D', (string)$field)) {
                            $clean['map'][$col] = (string)$field;
                            $clean['funcs'][$col] = (isset($pc['funcs'][$col]) && $pc['funcs'][$col] === 'trim') ? 'trim' : '';
                        }
                    }
                    $data['program_config'] = json_encode($clean);
                }
                $res = model('Cj')->saveData($data);
                if($res['code']>1){
                    return $this->success($res['msg']);
                }
                return $this->success($res['msg']);
            }
            return $this->success(lang('import_err'));
        }
        else{
            return $this->error($file->getError());
        }
    }
}
