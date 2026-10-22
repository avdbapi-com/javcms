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
namespace app\admin\controller;
use think\Db;

class VodDowner extends Base
{
    var $_pre;
    public function __construct()
    {
        parent::__construct();
        $this->_pre = 'voddowner';
    }

    public function index()
    {
        $list = config($this->_pre);
        $this->assign('list',$list);
        $this->assign('title',lang('admin/voddowner/title'));
        return $this->fetch('admin@voddowner/index');
    }

    public function info()
    {
        $param = input();
        $list = config($this->_pre);
        if (Request()->isPost()) {
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->ajaxErrorWithFreshToken($validate->getError());
            }
            unset($param['__token__']);
            unset($param['flag']);
            if(is_numeric($param['from'])){
                $param['from'] .='_';
            }
            if (strpos($param['from'], '.') !== false || strpos($param['from'], '/') !== false || strpos($param['from'], '\\') !== false) {
                return $this->ajaxErrorWithFreshToken(lang('param_err'));
            }
            // A code is letters, digits, _ and - only: it is a file name (static/player/<code>.js) and is shown in titles (XV2-1).
            if (!is_string($param['from']) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $param['from'])) {
                return $this->ajaxErrorWithFreshToken(lang('param_err'));
            }
            $list[$param['from']] = $param;
            $sort=[];
            foreach ($list as $k=>&$v){
                $sort[] = $v['sort'];
            }
            array_multisort($sort, SORT_DESC, SORT_FLAG_CASE , $list);
            $res = mac_arr2file( APP_PATH .'extra/'.$this->_pre.'.php', $list);
            if($res===false){
                return $this->ajaxErrorWithFreshToken(lang('save_err'));
            }
            cache('cache_data','1');
            return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
        }

        $info = $list[$param['id']];
        $this->assign('info',$info);
        $this->assign('title',lang('admin/voddowner/title'));
        return $this->fetch('admin@voddowner/info');
    }

    public function del()
    {
        // SEC r3 P2-1: this write needs POST + the admin token (the list and the bulk picker send __token__);
        // a plain GET link or a cross-site form without the token changes nothing.
        if (!request()->isPost()) {
            return $this->error(lang('param_err'));
        }
        if (!$this->axCsrfOk(input('post.'))) {
            return $this->error(lang('token_err'));
        }
        $param = input();
        $list = config($this->_pre);
        unset($list[$param['ids']]);
        $res = mac_arr2file(APP_PATH. 'extra/'.$this->_pre.'.php', $list);
        if($res===false){
            return $this->error(lang('del_err'));
        }
        cache('cache_data','1');
        return $this->success(lang('del_ok'));
    }

    public function field()
    {
        // SEC r3 P2-1: this write needs POST + the admin token (the list and the bulk picker send __token__);
        // a plain GET link or a cross-site form without the token changes nothing.
        if (!request()->isPost()) {
            return $this->error(lang('param_err'));
        }
        if (!$this->axCsrfOk(input('post.'))) {
            return $this->error(lang('token_err'));
        }
        $param = input();
        $ids = $param['ids'];
        $col = $param['col'];
        $val = $param['val'];

        if(!empty($ids) && in_array($col,['ps','status'])){
            $list = config($this->_pre);
            $ids = explode(',',$ids);
            foreach($list as $k=>&$v){
                if(in_array($k,$ids)){
                    $v[$col] = $val;
                }
            }
            $res = mac_arr2file(APP_PATH. 'extra/'.$this->_pre.'.php', $list);
            if($res===false){
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }
        return $this->error(lang('param_err'));
    }


    // SEC r3 P2-1: admin CSRF token (__token__ field or X-CSRF-Token header), same rule as Type / Link / Comment.
    private function axCsrfOk($param)
    {
        // r5 (L-1 sweep): a write is POST-only; a token in a GET link (or a GET with the header) changes nothing.
        if (!request()->isPost()) {
            return false;
        }
        $t = isset($param['__token__']) && is_string($param['__token__']) ? $param['__token__'] : (string)request()->header('X-CSRF-Token');
        return $t !== '' && hash_equals((string)mac_admin_csrf_token(), $t);
    }
}
