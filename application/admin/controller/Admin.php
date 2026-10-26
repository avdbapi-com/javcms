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
namespace app\admin\controller;
use think\Db;

class Admin extends Base
{
    use \app\admin\common\ListCsrf;

    /** Pages every admin may open whatever the role ticks (Base::check_auth()); never compared as a right. */
    private static $alwaysPairs = ['index/index', 'index/welcome', 'index/account', 'index/unlocked', 'index/logout'];

    /**
     * Rights that are each enough to take over the whole site (run any SQL, restore or replace any table, paste code
     * into every page, write files at the site root). The role editor marks them "Full access" (R9-2, R10).
     */
    private static $fullAccessPairs = ['database/sql', 'database/import', 'database/rep', 'system/config', 'jcx/code', 'jcx/root'];

    public function __construct()
    {
        parent::__construct();
    }

    /** The rights of a stored admin_auth value: lower-case pairs, without the always-allowed pages. */
    private static function authPairs($auth)
    {
        $pairs = [];
        foreach (explode(',', strtolower((string)$auth)) as $pair) {
            $pair = trim($pair);
            if ($pair !== '' && !in_array($pair, self::$alwaysPairs, true)) {
                $pairs[$pair] = 1;
            }
        }
        return $pairs;
    }

    private function isFirstAdmin()
    {
        return (int)$this->_admin['admin_id'] === 1;
    }

    /** The signed-in admin's own rights, read from the database, so a role changed since sign-in counts at once. */
    private function ownPairs()
    {
        $auth = Db::name('Admin')->where('admin_id', (int)$this->_admin['admin_id'])->value('admin_auth');
        return $auth === null ? [] : self::authPairs($auth);
    }

    /**
     * R9-2: may the signed-in admin edit, switch on or off, or delete admin $id? Admin 1 may manage everyone. Any
     * other admin never manages admin 1, and never an admin holding a right it does not hold itself. An id without
     * a row (a new admin) passes here; the rights ticked for it are checked by grantOk().
     */
    private function mayManage($id)
    {
        if ($this->isFirstAdmin()) {
            return true;
        }
        $id = (int)$id;
        if ($id === 1) {
            return false;
        }
        if ($id < 1) {
            return true;
        }
        $auth = Db::name('Admin')->where('admin_id', $id)->value('admin_auth');
        if ($auth === null) {
            return true;
        }
        return !array_diff_key(self::authPairs($auth), $this->ownPairs());
    }

    /** R9-2: an admin other than admin 1 may only tick rights it holds itself. */
    private function grantOk(array $pairs)
    {
        if ($this->isFirstAdmin()) {
            return true;
        }
        $own = $this->ownPairs();
        foreach ($pairs as $pair) {
            $pair = strtolower(trim((string)$pair));
            if ($pair !== '' && !in_array($pair, self::$alwaysPairs, true) && !isset($own[$pair])) {
                return false;
            }
        }
        return true;
    }

    /** The ids of a list action ("5,7" or ids[]) as whole numbers; [] when a value is not a plain number. */
    private static function idList($ids)
    {
        if (is_string($ids) || is_int($ids)) {
            $ids = explode(',', (string)$ids);
        }
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $value) {
            if (!is_scalar($value)) {
                return [];
            }
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            if (!ctype_digit($value)) {
                return [];
            }
            $out[(int)$value] = (int)$value;
        }
        return array_values($out);
    }

    public function index()
    {
        $param = input();
        $param['page'] = max(1, intval($param['page']));
        // B5: rows per page between 1 and 1000 (default = the admin page size).
        $param['limit'] = intval($param['limit'] ?? 0) < 1 ? (int)$this->_pagesize : min(max((int)$param['limit'], 1), 1000);
        $where=[];
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['admin_name'] = ['like','%'.$param['wd'].'%'];
        }

        $order='admin_id desc';
        $res = model('Admin')->listData($where,$order,$param['page'],$param['limit']);
        // R9-2: rows this account may not manage (admin 1, or an admin with more rights) show no edit, switch or delete.
        $own = $this->isFirstAdmin() ? null : $this->ownPairs();
        foreach ($res['list'] as $k => $row) {
            $res['list'][$k]['ax_manage'] = $own === null
                || ((int)$row['admin_id'] !== 1 && !array_diff_key(self::authPairs($row['admin_auth']), $own));
        }

        $this->assign('list',$res['list']);
        $this->assign('total',$res['total']);
        $this->assign('page',$res['page']);
        $this->assign('limit',$res['limit']);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';

        $this->assign('admin',$this->_admin);

        $this->assign('param',$param);
        $this->assign('title',lang('admin/admin/title'));
        return $this->fetch('admin@admin/index');
    }

    public function info()
    {
        if (Request()->isPost()) {
            $param = input('post.');
            $param['admin_auth'] = isset($param['admin_auth']) && is_array($param['admin_auth']) ? array_values(array_filter($param['admin_auth'], 'is_string')) : [];
            if(!in_array('index/welcome',$param['admin_auth'], true)){
                $param['admin_auth'][] = 'index/welcome';
            }
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->ajaxErrorWithFreshToken($validate->getError());
            }
            // R9-2: the target is a plain number (or empty for a new admin); only an admin this account may manage,
            // and only rights this account holds itself. A refused save changes nothing.
            $id = isset($param['admin_id']) ? $param['admin_id'] : '';
            if (!is_scalar($id) || (trim((string)$id) !== '' && !ctype_digit(trim((string)$id)))) {
                return $this->ajaxErrorWithFreshToken(lang('param_err'));
            }
            $id = trim((string)$id);
            $param['admin_id'] = $id === '' ? '' : (int)$id;
            if (!$this->mayManage($param['admin_id']) || !$this->grantOk($param['admin_auth'])) {
                return $this->ajaxErrorWithFreshToken(lang('permission_denied'));
            }
            $res = model('Admin')->saveData($param);
            if($res['code']>1){
                return $this->ajaxErrorWithFreshToken($res['msg']);
            }
            return $this->success($res['msg']);
        }

        $id = input('id');
        // R9-2: the editor of an admin this account may not manage does not open (admin 1, or more rights).
        // An id must be a number (an array id gave HTTP 500); no id = the Add form, which a limited admin may open (final-polish verify P2-1).
        if (is_array($id) || ($id !== null && $id !== '' && !ctype_digit(trim((string)$id)))) {
            return $this->error(lang('param_err'));
        }
        if (!$this->isFirstAdmin() && !$this->mayManage($id)) {
            return $this->error(lang('permission_denied'));
        }

        $where=[];
        $where['admin_id'] = ['eq',$id];

        $res = model('Admin')->infoData($where);
        $this->assign('info',$res['info']);

        // Role editor (ADMIN-IA-BRIEF 1.6 P4-P7): one block per menu group, one row per page with its hidden entries,
        // each controller/action pair listed once (first entry in menu order wins), ticked by exact match.
        $info = is_array($res['info'] ?? null) ? $res['info'] : ['admin_id' => 0, 'admin_name' => '', 'admin_status' => 1, 'admin_auth' => ''];
        $isNew = empty($info['admin_id']);
        if ($isNew) {
            $info['admin_status'] = 1; // F15: a new admin is Enabled by default
        }
        $this->assign('info', $info);
        $this->assign('is_new', $isNew);
        $have = array_flip(array_filter(explode(',', (string)($info['admin_auth'] ?? ''))));
        $always = ['index/welcome' => 1, 'index/account' => 1];
        // R9-2: "Full access" marks; an admin other than admin 1 sees the rights it does not hold as locked boxes.
        $firstAdmin = $this->isFirstAdmin();
        $own = $firstAdmin ? [] : $this->ownPairs();
        $full = array_flip(self::$fullAccessPairs);
        $anyLocked = false;
        $menus = @include MAC_ADMIN_COMM . 'auth.php';
        $menus = is_array($menus) ? $menus : [];
        $seen = [];
        $blocks = [];
        $advanced = ['key' => 'adv', 'label' => lang('admin/ux/roles/advanced_block'), 'rows' => [], 'advanced' => true];
        $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        foreach ($menus as $gk => $g) {
            $gk = (string)$gk;
            $block = ['key' => $gk, 'label' => $h($g['name']), 'rows' => [], 'advanced' => false];
            $pages = [];
            $hidden = [];
            foreach ($g['sub'] as $sk => $s) {
                $pair = strtolower($s['controller'] . '/' . $s['action']);
                if (isset($seen[$pair])) {
                    continue;
                }
                $seen[$pair] = 1;
                $tier = isset($s['tier']) ? $s['tier'] : 'main';
                $locked = !$firstAdmin && !isset($always[$pair]) && !isset($own[$pair]);
                $anyLocked = $anyLocked || $locked;
                $entry = ['key' => (string)$sk, 'pair' => $h($pair), 'controller' => $s['controller'], 'label' => $h($s['name']),
                    'checked' => isset($always[$pair]) || isset($have[$pair]), 'always' => isset($always[$pair]), 'tier' => $tier,
                    'full' => isset($full[$pair]), 'locked' => $locked];
                if (!empty($s['show']) && $tier !== 'retired') {
                    $pages[] = $entry + ['entries' => []];
                } else {
                    $hidden[] = $entry;
                }
            }
            // Hidden entries go to the first page of the same controller; the rest to a row "Other actions".
            $other = [];
            foreach ($hidden as $e) {
                if ($e['tier'] === 'retired') {
                    $advanced['rows'][] = ['page' => null, 'label' => $e['label'], 'entries' => [$e]];
                    continue;
                }
                $placed = false;
                foreach ($pages as $i => $p) {
                    if ($p['controller'] === $e['controller']) {
                        $pages[$i]['entries'][] = $e;
                        $placed = true;
                        break;
                    }
                }
                if (!$placed) {
                    $other[] = $e;
                }
            }
            foreach ($pages as $p) {
                $entries = $p['entries'];
                unset($p['entries']);
                $block['rows'][] = ['page' => $p, 'label' => $p['label'], 'entries' => $entries];
            }
            if ($other) {
                $block['rows'][] = ['page' => null, 'label' => $h(lang('admin/ux/roles/other')), 'entries' => $other];
            }
            if (!$block['rows']) {
                continue;
            }
            if ($gk === '14') {
                $advanced['rows'] = array_merge($block['rows'], $advanced['rows']);
                $advanced['label'] = $h(lang('admin/ux/roles/advanced_block'));
                continue;
            }
            $blocks[] = $block;
        }
        if ($advanced['rows']) {
            $advanced['label'] = $h(lang('admin/ux/roles/advanced_block'));
            $blocks[] = $advanced;
        }
        foreach ($blocks as $bi => $b) {
            $total = 0;
            $on = 0;
            foreach ($b['rows'] as $r) {
                foreach (array_merge($r['page'] ? [$r['page']] : [], $r['entries']) as $e) {
                    $total++;
                    if ($e['checked']) {
                        $on++;
                    }
                }
            }
            $blocks[$bi]['total'] = $total;
            $blocks[$bi]['count'] = $h(lang('admin/ux/roles/count', [$on, $total]));
        }
        $this->assign('role_blocks', $blocks);
        $this->assign('role_locked', $anyLocked);
        $this->assign('role_count_tpl', $h(lang('admin/ux/roles/count')));
        $this->assign('admin_icons_v', (int)@filemtime(ROOT_PATH . 'static/admin/icons/admin-icons.svg'));

        $this->assign('title', $isNew ? lang('admin/admin/editor_add') : lang('admin/admin/editor_edit'));
        return $this->fetch('admin@admin/info');
    }

    public function del()
    {
        // close-security (security r4 P3-1): POST only, so the admin token never travels in a URL.
        if (!$this->listPostOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param = input();
        $ids = self::idList(isset($param['ids']) ? $param['ids'] : '');

        if(!empty($ids)){
            if(in_array((int)$this->_admin['admin_id'],$ids,true)){
                return $this->error(lang('admin/admin/del_cur_err'));
            }
            // R9-2: one id this account may not manage refuses the whole request; nothing is deleted.
            foreach ($ids as $id) {
                if (!$this->mayManage($id)) {
                    return $this->error(lang('permission_denied'));
                }
            }
            $where=[];
            $where['admin_id'] = ['in',$ids];
            $res = model('Admin')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    public function field()
    {
        // close-security (security r4 P3-1): POST only, so the admin token never travels in a URL.
        if (!$this->listPostOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $param = input();
        $ids = self::idList(isset($param['ids']) ? $param['ids'] : '');
        $col = isset($param['col']) ? $param['col'] : '';
        $val = isset($param['val']) ? $param['val'] : '';

        if(!empty($ids) && is_scalar($col) && (string)$col === 'admin_status' && is_scalar($val) && in_array((string)$val,['0','1'],true)){
            // R9-2: one id this account may not manage refuses the whole request; nothing changes.
            foreach ($ids as $id) {
                if (!$this->mayManage($id)) {
                    return $this->error(lang('permission_denied'));
                }
            }
            $where=[];
            $where['admin_id'] = ['in',$ids];

            $res = model('Admin')->fieldData($where,$col,$val);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

}
