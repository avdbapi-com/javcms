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
namespace app\common\model;
use think\image\Exception;

class Image extends Base {

    public function down_load($url, $config, $flag = 'vod')
    {
        if (substr($url, 0, 4) == 'http') {
            return $this->down_exec($url, $config, $flag);
        } else {
            return $url;
        }
    }

    public function down_exec($url, $config, $flag = 'vod')
    {
        $upload_image_ext = 'jpg,jpeg,png,gif,webp';
        $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        if (!in_array($ext, explode(',', $upload_image_ext))) {
            $ext = 'jpg';
        }
        $img = mac_curl_get($url);
        if (empty($img) || strlen($img) < 10) {
            return $url;
        }
        $file_name = md5(uniqid()) .'.' . $ext;
        // Filesystem directory for downloaded images.
        $_upload_path = ROOT_PATH . 'upload' . '/' . $flag . '/';
        // Relative directory for saved images.
        $_save_path = 'upload'. '/' . $flag . '/' ;
        $ymd = date('Ymd');
        $n_dir = $ymd;
        for($i=1;$i<=100;$i++){
            $n_dir = $ymd .'-'.$i;
            $path1 = $_upload_path . $n_dir. '/';
            if(file_exists($path1)){
                $farr = glob($path1.'*.*');
                if($farr){
                    $fcount = count($farr);
                    if($fcount>999){
                        continue;
                    }
                    else{
                        break;
                    }
                }
                else{
                    break;
                }
            }
            else{
                break;
            }
        }

        $_upload_path .= $n_dir . '/';
        $_save_path .= $n_dir . '/';

        // Relative path of the saved image.
        $_file_path = $_save_path.$file_name;
        // Write to a file
        $saved_img_path = $_upload_path . $file_name;
        $r = mac_write_file($saved_img_path, $img);
        if(!$r){
            return $url;
        }
        // Inspect the saved image format; return the original URL if unsupported.
        $image_info = getimagesize($saved_img_path);
        $extension_hash = [
            '1'  => 'gif',
            '2'  => 'jpg',
            '3'  => 'png',
            '18' => 'webp',
        ];
        if (!isset($image_info[2]) || !isset($extension_hash[$image_info[2]])) {
            return $url;
        }
        $file_size = filesize($_upload_path.$file_name);
        // watermarks
        if ($config['watermark'] == 1) {
            $this->watermark($_file_path,$config,$flag);
        }
        // thumbnails
        if ($config['thumb'] == 1) {
            $this->makethumb($_file_path,$config,$flag);
        }
        // Process the saved image with the upload handler.
        $_file_path = model('Upload')->api($_file_path, $config);

        $tmp = $_file_path;
        if (str_starts_with($tmp, '/upload')) {
            $tmp = substr($tmp,1);
        }
        if (str_starts_with($tmp, 'upload')) {
            $annex = [];
            $annex['annex_file'] = $tmp;
            $annex['annex_type'] = 'image';
            $annex['annex_size'] = $file_size;
            model('Annex')->saveData($annex);
        }
        return $_file_path;
    }

    public function watermark($file_path,$config,$flag='vod')
    {
        if(empty($config['watermark_font'])){
            $config['watermark_font'] = './static/font/test.ttf';
        }
        try {
            $image = \think\Image::open('./' . $file_path);
            $image->text($config['watermark_content']."", $config['watermark_font'], $config['watermark_size'], $config['watermark_color'],$config['watermark_location'])->save('./' . $file_path);
        }
        catch(\Exception $e){

        }
    }

    public function makethumb($file_path,$config,$flag='vod',$new=1)
    {
        $thumb_type = $config['thumb_type'];
        $data['thumb'] = [];
        if (!empty($config['thumb_size'])) {
            try {
                $image = \think\Image::open('./' . $file_path);
                // Supports thumbnails in multiple sizes
                $thumbs = explode(',', $config['thumb_size']);
                foreach ($thumbs as $k => $v) {
                    $t_size = explode('x', strtolower($v));
                    if (!isset($t_size[1])) {
                        $t_size[1] = $t_size[0];
                    }
                    $new_thumb = $file_path . '_' . $t_size[0] . 'x' . $t_size[1] . '.' . strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                    if($new==0){
                        $new_thumb = $file_path;
                    }
                    $image->thumb($t_size[0], $t_size[1], $thumb_type)->save('./' . $new_thumb);
                    $thumb_size = round(filesize('./' . $new_thumb) / 1024, 2);
                    $data['thumb'][$k]['type'] = 'image';
                    $data['thumb'][$k]['flag'] = $flag;
                    $data['thumb'][$k]['file'] = $new_thumb;
                    $data['thumb'][$k]['size'] = $thumb_size;
                    $data['thumb'][$k]['ctime'] = request()->time();

                    if ($config['watermark'] == 1) {// Apply the text watermark.
                        $image = \think\Image::open('./' . $new_thumb);
                        $image->text($config['watermark_content'], $config['watermark_font'], $config['watermark_size'], $config['watermark_color'])->save('./' . $new_thumb);
                    }
                }
            }
            catch(\Exception $e){

            }
        }
        return $data;
    }




}