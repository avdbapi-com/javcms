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
namespace app\common\model;

use app\common\util\Ftp as ftpOper;

class Upload extends Base {

    /**
     * Storage mode of an upload config value (core-polish, review F-2): legacy numbers 2-5 mapped to their names, lower
     * case. A non-scalar value (upload[mode][]=x), a malformed name or a name without an adapter class is "local", so a
     * bad saved config never stops an upload (it used to give "System Error" on PHP 8 after the file was written).
     * China-only names (GlobalModules::BLOCKED) are returned as they are: the callers refuse or skip them.
     */
    public static function storageMode($mode)
    {
        if (!is_scalar($mode)) { return 'local'; }
        $mode = strtolower(trim((string)$mode));
        $mode = ['2' => 'upyun', '3' => 'qiniu', '4' => 'ftp', '5' => 'weibo'][$mode] ?? $mode;
        if ($mode === 'local' || $mode === 'remote') { return $mode; }
        if (\app\admin\util\GlobalModules::blocked('upload', $mode)) { return $mode; }
        if (!preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $mode) || !class_exists('app\\common\\extend\\upload\\' . ucfirst($mode))) { return 'local'; }
        return $mode;
    }

    public function api($file_path,$config)
    {
        if(empty($config) || !is_array($config)){
            return $file_path;
        }

        $config['mode'] = self::storageMode($config['mode'] ?? 'local');

        // China-only storage (GlobalModules::BLOCKED['upload']) never receives a file, even from an old config:
        // the file stays on this server.
        if (\app\admin\util\GlobalModules::blocked('upload', $config['mode'])) {
            return $file_path;
        }

        if(!in_array($config['mode'],['local','remote'])){
            $cp = 'app\\common\\extend\\upload\\' . ucfirst($config['mode']);
            if (class_exists($cp)) {
                $c = new $cp($config);
                $file_path = $c->submit($file_path);
            }
        }

        return str_replace(['http:','https:'],'mac:',$file_path);
    }

    public function upload($p=[])
    {
        $param = input();
        if(!empty($p)){
            $param = array_merge($param,$p);
        }

        $param['from'] = empty($param['from']) ? '' : $param['from'];
        $param['input'] = empty($param['input']) ? 'file' : $param['input'];
        $param['flag'] = empty($param['flag']) ? 'vod' : $param['flag'];
        $param['thumb'] = empty($param['thumb']) ? '0' : $param['thumb'];
        $param['thumb_class'] = empty($param['thumb_class']) ? '' : $param['thumb_class'];
        $param['user_id'] = empty($param['user_id']) ? '0' : $param['user_id'];
        $base64_img = $param['imgdata'];
        $data = [];
        $config = (array)config('maccms.site');
        $pre= $config['install_dir'];
        $upload_image_ext = 'jpg,jpeg,png,gif,webp';
        $upload_file_ext = 'doc,docx,xls,xlsx,ppt,pptx,pdf,wps,txt,rar,zip,torrent';
        $upload_media_ext = 'rm,rmvb,avi,mkv,mp4,mp3';
        $add_rnd = false;
        $config = (array)config('maccms.upload');
        $storage = self::storageMode($config['mode'] ?? 'local');
        $config['mode'] = $storage;
        if (\app\admin\util\GlobalModules::blocked('upload', $storage)) {
            // An old config still names a China-only storage service: refuse before any file is written.
            return self::upload_return(lang('admin/module_removed'), $param['from']);
        }

        if(!empty($param['from'])){
            $cp = 'app\\common\\extend\\editor\\' . ucfirst($param['from']);
            if (class_exists($cp)) {
                $c = new $cp;
                $c->front($param);
            }
            else{
                return self::upload_return(lang('admin/upload/not_find_extend'), '');
            }
        }
        else{
            $pre='';
        }

        // Filesystem directory for uploaded files.
        $_upload_path = ROOT_PATH . 'upload' . '/' . $param['flag'] . '/' ;
        // Relative directory for uploaded files.
        $_save_path = 'upload'. '/' . $param['flag'] . '/';
        if($param['flag']=='user'){
            $uniq = $param['user_id'] % 10;
            $_upload_path .= $uniq .'/';
            $_save_path .= $uniq .'/';
            $_save_name = $param['user_id'] . '.jpg';

            if(!file_exists($_save_path)){
                mac_mkdirss($_save_path);
            }
        }
        else{
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
            $_save_name = $n_dir . '/' . md5(microtime(true));
        }


        if(!empty($base64_img)){
            if(preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64_img, $result)){
                $type = $result[2];
                if(in_array($type, explode(',', $upload_image_ext))){
                    if(!file_put_contents($_save_path.$_save_name, base64_decode(str_replace($result[1], '', $base64_img)))){
                        return self::upload_return(lang('admin/upload/upload_faild'), $param['from']);
                    }
                    $file_size = round(filesize('./'.$_save_path.$_save_name)/1024, 2);
                }
                else {
                    return self::upload_return(lang('admin/upload/forbidden_ext'), $param['from']);
                }
            }
            else{
                return self::upload_return(lang('admin/upload/no_input_file'), $param['from']);
            }
        }
        else {
            $file = request()->file($param['input']);
            if (empty($file)) {
                return self::upload_return(lang('admin/upload/no_input_file'), $param['from']);
            }
            if ($file->getMime() == 'text/x-php') {
                return self::upload_return(lang('admin/upload/forbidden_ext'), $param['from']);
            }

            if ($file->checkExt($upload_image_ext)) {
                $type = 'image';
            } elseif ($file->checkExt($upload_file_ext)) {
                $type = 'file';
            } elseif ($file->checkExt($upload_media_ext)) {
                $type = 'media';
            } else {
                return self::upload_return(lang('admin/upload/forbidden_ext'), $param['from']);
            }
            $upfile = $file->move($_upload_path,$_save_name);
            if ($upfile === false) {
                // r7 repair (r5 note): ThinkPHP refuses a file whose image check fails ("illegal image files") or that it
                // cannot store; answer with a message instead of a fatal "getSaveName() on false". Nothing is saved.
                $msg = '';
                if (!$file->checkImg()) {
                    $msg = lang('admin/ux/r7/upload_not_image');
                    $msg = $msg === 'admin/ux/r7/upload_not_image' ? '' : $msg;
                }
                return self::upload_return($msg !== '' ? $msg : lang('admin/upload/upload_faild'), $param['from']);
            }
            if (!is_file($_upload_path.$upfile->getSaveName())) {
                return self::upload_return(lang('admin/upload/upload_faild'), $param['from']);
            }
            $file_size = round($upfile->getInfo('size')/1024, 2);
            $_save_name = str_replace('\\', '/', $upfile->getSaveName());
        }


        $resource = fopen($_save_path.$_save_name, 'rb');
        $fileSize = filesize($_save_path.$_save_name);
        fseek($resource, 0);
        if ($fileSize>512){
            $hexCode = bin2hex(fread($resource, 512));
            fseek($resource, $fileSize - 512);
            $hexCode .= bin2hex(fread($resource, 512));
        } else {
            $hexCode = bin2hex(fread($resource, $fileSize));
        }
        fclose($resource);
        if(preg_match("/(3c25.*?28.*?29.*?253e)|(3c3f.*?28.*?29.*?3f3e)|(3C534352495054)|(2F5343524950543E)|(3C736372697074)|(2F7363726970743E)/is", $hexCode)){
            return self::upload_return(lang('admin/upload/upload_safe'), $param['from']);
        }

        $file_count = 1;
        $data = [
            'file'  => $_save_path.$_save_name,
            'type'  => $type,
            'size'  => $file_size,
            'flag' => $param['flag'],
            'ctime' => request()->time(),
            'thumb_class'=>$param['thumb_class'],
        ];

        $data['thumb'] = [];
        if($param['flag']=='user'){
            $add_rnd=true;
            $file = $_save_path.str_replace('\\', '/', $_save_name);
            $new_thumb = $param['user_id'] .'.jpg';
            $new_file = $_save_path . $new_thumb;
            try {
                $image = \think\Image::open('./' . $file);
                $t_size = explode('x', strtolower($GLOBALS['config']['user']['portrait_size']));
                if (!isset($t_size[1])) {
                    $t_size[1] = $t_size[0];
                }
                $image->thumb($t_size[0], $t_size[1], 6)->save('./' . $new_file);
                $file_size = round(filesize('./' .$new_file)/1024, 2);
            }
            catch(\Exception $e){
                return self::upload_return(lang('admin/upload/make_thumb_faild'), $param['from']);
            }
            $update = [];
            $update['user_portrait'] = $new_file;
            $where = [];
            $where['user_id'] = $GLOBALS['user']['user_id'];
            $res = model('User')->where($where)->update($update);
            if ($res === false) {
                return self::upload_return(lang('index/portrait_err'), $param['from']);
            }
        }
        else {
            if ($type == 'image') {
                if ($config['watermark'] == 1) {
                    model('Image')->watermark($data['file'], $config, $param['flag']);
                }
                if ($param['thumb'] == 1 && $config['thumb'] == 1) {
                    $dd = model('Image')->makethumb($data['file'], $config, $param['flag']);
                    if (is_array($dd)) {
                        $data = array_merge($data, $dd);
                    }
                }
            }
        }
        unset($upfile);

        $config['mode'] = self::storageMode($config['mode'] ?? 'local');

        if(!in_array($config['mode'],['local','remote'])){
            $data['file'] = model('Upload')->api($data['file'],$config);
            if(!empty($data['thumb'])){
                $data['thumb'][0]['file'] = model('Upload')->api($data['thumb'][0]['file'],$config);
            }
        }
        if(!empty($param['from'])){
            if(substr($data['file'],0,4)!='http' && substr($data['file'],0,4)!='mac:'){
                $data['file']  =  $pre. $data['file'];
            }
            else{
                $data['file']  = mac_url_content_img($data['file']);
            }
        }

        $tmp = $data['file'];
        if((substr($tmp,0,7) == "/upload")){
            $tmp = substr($tmp,1);
        }
        if((substr($tmp,0,6) == "upload")){
            $annex = [];
            $annex['annex_file'] = $tmp;
            $r = model('Annex')->infoData($annex);
            if($r['code']!==1){
                $annex['annex_type'] = $type;
                $annex['annex_size'] = $file_size;
                model('Annex')->saveData($annex);
                $tmp = $data['thumb'][0]['file'];
                if(!empty($tmp)){
                    $file_size = filesize($tmp);
                    $annex = [];
                    $annex['annex_file'] = $tmp;
                    $r = model('Annex')->infoData($annex);
                    if($r['code']!==1){
                        $annex['annex_type'] = $type;
                        $annex['annex_size'] = $file_size;
                        model('Annex')->saveData($annex);
                    }
                }
            }
        }
        return self::upload_return(lang('admin/upload/upload_success'), $param['from'], 1, $data);
    }


    private function upload_return($info='',$from='',$status=0,$data=[])
    {
        $arr = [];
        if(!empty($from)){
            $cp = 'app\\common\\extend\\editor\\' . ucfirst($from);
            if (class_exists($cp)) {
                $c = new $cp;
                $arr = $c->back($info,$status,$data);
            }
        }
        elseif(ENTRANCE=='index'){
            $arr['msg'] = $info;
            $arr['code'] = $status;
            if (isset($data['file'])) { $arr['file'] = MAC_PATH .  $data['file'] . '?'. mt_rand(1000, 9999); } // error answers carry no file
        }
        else{
            $arr['msg'] = $info;
            $arr['code'] = $status;
            $arr['data'] = $data;
        }
        return $arr;
    }

}