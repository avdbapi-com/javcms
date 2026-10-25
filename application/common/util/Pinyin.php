<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, with changes from the MacCMS editions
 * RamCMS (Apache-2.0) and/or ApiiCMS (MIT, Copyright (c) 2024 apiiphim), changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS, RamCMS and ApiiCMS parts keep their own licenses.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

class Pinyin
{
    private static $pinyins = null;

    public function __construct() {

    }

    public static function get($str, $ret_format = 'all', $placeholder = '', $allow_chars = '/[a-zA-Z\d ]/') {

        if (null === self::$pinyins) {
            $data = file_get_contents('./static/data/pinyin.dat');

            $rows = explode('|', $data);

            self::$pinyins = array();
            foreach($rows as $v) {
                list($py, $vals) = explode(':', $v);
                $chars = explode(',', $vals);

                foreach ($chars as $char) {
                    self::$pinyins[$char] = $py;
                }
            }
        }

        $str = trim($str);
        $len = mb_strlen($str, 'UTF-8');
        $rs = '';
        for ($i = 0; $i < $len; $i++) {
            $chr = mb_substr($str, $i, 1, 'UTF-8');
            $asc = ord($chr);
            if ($asc < 0x80) { // ASCII byte values (0-127).
                if (preg_match($allow_chars, $chr)) { // Keep characters allowed by the supplied pattern.
                    $rs .= $chr; // Append the allowed character unchanged.
                } else { // Replace disallowed ASCII characters with the placeholder.
                    $rs .= $placeholder;
                }
            } else { // Non-ASCII characters start with a byte value of 128 or higher.
                if (isset(self::$pinyins[$chr])) {
                    $rs .= 'first' === $ret_format ? self::$pinyins[$chr][0] : (self::$pinyins[$chr] . '');
                } else {
                    $rs .= $placeholder;
                }
            }

            if ('one' === $ret_format && '' !== $rs) {
                return $rs[0];
            }
        }
        $rs = str_replace([' ','+','/','\\','|','\'','?','%','#','&','=','!','(',')',';',':','<','>'],'',$rs);
        return $rs;
    }

    public static function slugify($str, $divider = '-')
    {
        $str = trim(mb_strtolower($str));
        $str = preg_replace('/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/', 'a', $str);
        $str = preg_replace('/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/', 'e', $str);
        $str = preg_replace('/(ì|í|ị|ỉ|ĩ)/', 'i', $str);
        $str = preg_replace('/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/', 'o', $str);
        $str = preg_replace('/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/', 'u', $str);
        $str = preg_replace('/(ỳ|ý|ỵ|ỷ|ỹ)/', 'y', $str);
        $str = preg_replace('/(đ)/', 'd', $str);
        $str = preg_replace('/[^a-z0-9-\s]/', '', $str);
        $str = preg_replace('/([\s]+)/', $divider, $str);
        return $str;
    }

}