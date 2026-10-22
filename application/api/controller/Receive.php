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
namespace app\api\controller;
use think\Controller;

class Receive extends Base
{
    var $_param;

    public function __construct()
    {
        parent::__construct();
        $this->_param = input('','','trim,urldecode');


        if($GLOBALS['config']['interface']['status'] != 1){
            echo json_encode(['code'=>3001,'msg'=>lang('api/close_err')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Constant-time, type-strict key check (the loose != compared numeric-looking keys as numbers).
        $configuredPass = (string)($GLOBALS['config']['interface']['pass'] ?? '');
        $givenPass = isset($this->_param['pass']) && is_string($this->_param['pass']) ? $this->_param['pass'] : '';
        if($configuredPass === '' || $givenPass === '' || !hash_equals($configuredPass, $givenPass)){
            echo json_encode(['code'=>3002,'msg'=>lang('api/pass_err')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if( strlen($configuredPass) <16){
            echo json_encode(['code'=>3003,'msg'=>lang('api/pass_safe_err')],JSON_UNESCAPED_UNICODE);
            exit;
        }

    }

    public function index()
    {

    }

    public function vod()
    {
        $info = $this->_param;

        if(empty($info['vod_name'])){
            echo json_encode(['code'=>2001,'msg'=>lang('api/require_name')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if(empty($info['type_id']) && empty($info['type_name'])){
            echo json_encode(['code'=>2002,'msg'=>lang('api/require_type')],JSON_UNESCAPED_UNICODE);
            exit;
        }

        $inter = mac_interface_type();
        if(empty($info['type_id'])) {
            $info['type_id'] = $inter['vodtype'][$info['type_name']];
        }

        $data['data'][] = $info;
        $res = model('Collect')->vod_data([],$data,0);
        echo json_encode($res,JSON_UNESCAPED_UNICODE);
    }

    public function art()
    {
        $info = $this->_param;

        if(empty($info['art_name'])){
            echo json_encode(['code'=>2001,'msg'=>lang('api/require_name')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if(empty($info['type_id']) && empty($info['type_name'])){
            echo json_encode(['code'=>2002,'msg'=>lang('api/require_type')],JSON_UNESCAPED_UNICODE);
            exit;
        }

        $inter = mac_interface_type();
        if(empty($info['type_id'])) {
            $info['type_id'] = $inter['arttype'][$info['type_name']];
        }
        $data['data'][] = $info;
        $res = model('Collect')->art_data([],$data,0);
        echo json_encode($res,JSON_UNESCAPED_UNICODE);
    }

    public function actor()
    {
        $info = $this->_param;

        if(empty($info['actor_name'])){
            echo json_encode(['code'=>2001,'msg'=>lang('api/require_actor_name')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if(empty($info['actor_sex'])){
            echo json_encode(['code'=>2002,'msg'=>lang('api/require_sex')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if(empty($info['type_id']) && empty($info['type_name'])){
            echo json_encode(['code'=>2003,'msg'=>lang('api/require_type')],JSON_UNESCAPED_UNICODE);
            exit;
        }

        $inter = mac_interface_type();
        if(empty($info['type_id'])) {
            $info['type_id'] = $inter['actortype'][$info['type_name']];
        }
        $data['data'][] = $info;
        $res = model('Collect')->actor_data([],$data,0);
        echo json_encode($res,JSON_UNESCAPED_UNICODE);
    }

    public function role()
    {
        $info = $this->_param;

        if(empty($info['role_name'])){
            echo json_encode(['code'=>2001,'msg'=>lang('api/require_role_name')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if(empty($info['role_actor'])){
            echo json_encode(['code'=>2002,'msg'=>lang('api/require_actor_name')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if(empty($info['vod_name']) && empty($info['douban_id'])){
            echo json_encode(['code'=>2003,'msg'=>lang('api/require_rel_vod')],JSON_UNESCAPED_UNICODE);
            exit;
        }

        $data['data'][] = $info;
        $res = model('Collect')->role_data([],$data,0);
        echo json_encode($res,JSON_UNESCAPED_UNICODE);
    }

    public function website()
    {
        $info = $this->_param;

        if(empty($info['website_name'])){
            echo json_encode(['code'=>2001,'msg'=>lang('api/require_name')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if(empty($info['type_id']) && empty($info['type_name'])){
            echo json_encode(['code'=>2002,'msg'=>lang('api/require_type')],JSON_UNESCAPED_UNICODE);
            exit;
        }

        $inter = mac_interface_type();
        if(empty($info['type_id'])) {
            $info['type_id'] = $inter['websitetype'][$info['type_name']];
        }
        $data['data'][] = $info;
        $res = model('Collect')->website_data([],$data,0);
        echo json_encode($res,JSON_UNESCAPED_UNICODE);
    }

    public function comment()
    {
        $info = $this->_param;

        if(empty($info['comment_name'])){
            echo json_encode(['code'=>2001,'msg'=>lang('api/require_comment_name')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if(empty($info['comment_content'])){
            echo json_encode(['code'=>2002,'msg'=>lang('api/require_comment_content')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if(empty($info['comment_mid'])){
            echo json_encode(['code'=>2004,'msg'=>lang('api/require_mid')],JSON_UNESCAPED_UNICODE);
            exit;
        }
        if(empty($info['rel_name']) && empty($info['douban_id'])){
            echo json_encode(['code'=>2003,'msg'=>lang('api/require_rel_name')],JSON_UNESCAPED_UNICODE);
            exit;
        }


        $data['data'][] = $info;
        $res = model('Collect')->comment_data([],$data,0);
        echo json_encode($res,JSON_UNESCAPED_UNICODE);
    }
}
