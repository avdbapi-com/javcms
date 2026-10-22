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
namespace app\common\validate;
use think\Validate;

class Vod extends Validate
{
    protected $rule =   [
        'vod_name'  => 'require',
        'type_id'  => 'require',
    ];

    protected $message  =   [
        'vod_name.require' => 'validate/require_name',
        'type_id.require' => 'validate/require_type',
    ];

    protected $scene = [
        'add'  =>  ['vod_name','type_id'],
        'edit'  =>  ['vod_name','type_id'],
    ];


    // Apply the XSS filter and truncate listed fields to their length limits.
    public static function formatDataBeforeDb($data)
    {
        // Imports and edits cannot clear or supply recycle state.
        unset($data['vod_recycle_time']);
        $filter_fields = [
            'vod_name'           => 255,
            'vod_sub'            => 255,
            'vod_en'             => 255,
            'vod_color'          => 6,
            'vod_tag'            => 100,
            'vod_class'          => 255,
            'vod_pic'            => 1024,
            'vod_pic_thumb'      => 1024,
            'vod_pic_slide'      => 1024,
            'vod_pic_screenshot' => 65535,
            'vod_actor'          => 255,
            'vod_director'       => 255,
            'vod_writer'         => 100,
            'vod_behind'         => 100,
            'vod_blurb'          => 255,
            'vod_remarks'        => 100,
            'vod_pubdate'        => 100,
            'vod_serial'         => 20,
            'vod_tv'             => 30,
            'vod_weekday'        => 30,
            'vod_area'           => 255,
            'vod_lang'           => 150,
            'vod_year'           => 10,
            'vod_version'        => 30,
            'vod_state'          => 30,
            'vod_author'         => 255,
            'vod_jumpurl'        => 150,
            'vod_tpl'            => 30,
            'vod_tpl_play'       => 30,
            'vod_tpl_down'       => 30,
            'vod_duration'       => 50,
            'vod_reurl'          => 255,
            'vod_rel_vod'        => 255,
            'vod_rel_art'        => 255,
            'vod_pwd'            => 10,
            'vod_pwd_url'        => 255,
            'vod_pwd_play'       => 10,
            'vod_pwd_play_url'   => 255,
            'vod_pwd_down'       => 10,
            'vod_pwd_down_url'   => 255,
            'vod_play_from'      => 255,
            'vod_play_server'    => 255,
            'vod_play_note'      => 255,
            'vod_down_from'      => 255,
            'vod_down_server'    => 255,
            'vod_down_note'      => 255,
        ];
        foreach ($filter_fields as $field => $length) {
            if (!isset($data[$field])) {
                continue;
            }
            $data[$field] = mac_filter_xss($data[$field]);
            $data[$field] = mb_substr($data[$field], 0, $length);
        }
        return $data;
    }

}