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

class Template extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $param = input();
        $path = $param['path'];
        $path = str_replace('\\','',$path);
        $path = str_replace('/','',$path);

        if(empty($path)){
            $path = '.@template';
        }

        if(substr($path,0,10) != ".@template") { $path = ".@template"; }
        if(count( explode(".@",$path) ) > 2) {
            $this->error(lang('illegal_request'));
            return;
        }

        $uppath = substr($path,0,strrpos($path,"@"));
        $ischild = 0;
        if ($path !=".@template"){
            $ischild = 1;
        }

        $config = config('maccms.site');
        if($param['current']==1){
            $path = '.@template@' . $config['template_dir'] .'@' . $config['html_dir'] ;
            $ischild = 0;
            $pp = str_replace('@','/',$path);
            $filters = $pp.'/*';
        }
        elseif($param['label']==1){
            $path = '.@template@' . $config['template_dir'] .'@' . $config['html_dir'] ;
            $ischild = 0;
            $pp = str_replace('@','/',$path);
            $filters = $pp.'/label/*';
        }
        elseif($param['ads']==1){
            $path = '.@template@' . $config['template_dir'] .'@' . $config['html_dir'] ;
            $ischild = 0;
            $pp = str_replace('@','/',$path);
            $filters = $pp.'/ads/*';
        }
        else{
            $pp = str_replace('@','/',$path);
            $filters = $pp.'/*';
        }

        $this->assign('curpath',$path);
        $this->assign('uppath',$uppath);
        $this->assign('ischild',$ischild);

        $num_path = 0;
        $num_file = 0;
        $sum_size = 0;
        $files = [];

        if(is_dir($pp)) {
            $farr = glob($filters);
            if ($farr) {
                foreach ($farr as $f) {

                    if(is_dir($f)) {
                            $num_path++;
                            $tmp_path = str_replace('./template/', '.@template/', $f);
                            $tmp_path = str_replace('/', '@', $tmp_path);
                            $tmp_name = str_replace($path . '@', '', $tmp_path);
                            $ftime = filemtime($f);

                            $files[] = ['isfile' => 0, 'name' => $tmp_name, 'path' => $tmp_path, 'note'=>lang('dir'), 'time' => $ftime];
                    }
                    elseif(is_file($f)) {
                        $num_file++;
                        $fsize = filesize($f);
                        $sum_size += $fsize;
                        $fsize = mac_format_size($fsize);
                        $ftime = filemtime($f);
                        $tmp_path = mac_convert_encoding($f, "UTF-8", "GB2312");

                        $path_info = @pathinfo($f);
                        $tmp_path = $path_info['dirname'];
                        $tmp_name = $path_info['basename'];

                        $files[] = ['isfile' => 1, 'name' => $tmp_name, 'path' => $tmp_path, 'fullname'=> $tmp_path.'/'.$tmp_name, 'size' => $fsize,'note'=>lang('file'), 'time' => $ftime];
                    }
                }
            }
        }
        $this->assign('sum_size',mac_format_size($sum_size));
        $this->assign('num_file',$num_file);
        $this->assign('num_path',$num_path);
        $this->assign('files',$files);

        $this->assign('title', self::uxTitle('admin/ux/menu/theme_files', 'admin/template/title'));
        return $this->fetch('admin@template/index');
    }

    /** Page title from a menu label key of the new menu; the old title while that key is not shipped yet. */
    protected static function uxTitle($key, $fallback)
    {
        $text = lang($key);
        return ($text === $key || $text === '') ? lang($fallback) : $text;
    }

    public function ads()
    {
        $adsdir = $GLOBALS['config']['site']['ads_dir'];
        if(empty($adsdir)){
            $adsdir='ads';
        }
        $path = './template/'.$GLOBALS['config']['site']['template_dir'].'/'.$adsdir ;
        if(!file_exists($path)){
            mac_mkdirss($path);
        }

        $filters = $path.'/*.js';
        $num_file=0;
        $sum_size=0;
        $farr = glob($filters);
        if ($farr) {
            foreach ($farr as $f) {
                if(is_file($f)) {
                    $num_file++;
                    $fsize = filesize($f);
                    $sum_size += $fsize;
                    $fsize = mac_format_size($fsize);
                    $ftime = filemtime($f);
                    $tmp_path = mac_convert_encoding($f, "UTF-8", "GB2312");

                    $path_info = @pathinfo($f);
                    $tmp_path = $path_info['dirname'];
                    $tmp_name = $path_info['basename'];

                    $files[] = ['isfile' => 1, 'name' => $tmp_name, 'path' => $tmp_path, 'fullname'=> $tmp_path.'/'.$tmp_name, 'size' => $fsize,'note'=>lang('file'), 'time' => $ftime];
                }
            }
        }
        $this->assign('curpath',$path);
        $this->assign('sum_size',mac_format_size($sum_size));
        $this->assign('num_file',$num_file);
        $this->assign('files',$files);
        $this->assign('title',lang('admin/template/ads/title'));
        return $this->fetch('admin@template/ads');
    }

    public function info()
    {
        $param = input();

        $fname = $param['fname'];
        $fpath = $param['fpath'];

        if( empty($fpath)){
            $this->error(lang('param_err').': template path is required (1).');
            return;
        }
        $fpath = str_replace('@','/',$fpath);
        $fullname = $fpath .'/' .$fname;
        $fullname = str_replace('\\','/',$fullname);

        if( (substr($fullname,0,10) != "./template") || count( explode("./",$fullname) ) > 2) {
            $this->error(lang('param_err').': template path is not allowed (2).');
            return;
        }
        $path = pathinfo($fullname);
        if(!empty($fname)) {
            $extarr = array('html', 'htm', 'js', 'xml');
            if (!in_array($path['extension'], $extarr)) {
                $this->error(lang('admin/template/ext_safe_tip'));
                return;
            }
        }

        // F33: theme files are read-only here. Saving always failed (the filter below refuses template tags).
        if (Request()->isPost()) {
            return $this->error(lang('admin/ux/system/template_readonly'));
        }
        if (empty($fname) || !is_file($fullname)) {
            $this->error(lang('param_err'));
            return;
        }

        $filter = '<\?|php|eval|server|assert|get|post|request|cookie|session|input|env|config|call|global|dump|print|phpinfo|fputs|fopen|global|chr|strtr|pack|system|gzuncompress|shell|base64|file|proc|preg|call|ini|exec\s*\(|passthru\s*\(|popen\s*\(|show_source\s*\(|create_function\s*\(|register_shutdown_function\s*\(|register_tick_function\s*\(|error_log\s*\(|{:|{$|{~|{-|{+|{/';
        $this->assign('filter',$filter);

        if (Request()->isPost()) {
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }

            $validate = \think\Loader::validate('Template');
            if(!$validate->check($param)){
                return $this->error($validate->getError());
            }

            $fcontent = $param['fcontent'];
            $r = mac_reg_replace($fcontent,$filter,"*");
            if($fcontent !== $r){
                $this->error(lang('admin/template/php_safe_tip'));
                return;
            }
            $res = @fwrite(fopen($fullname,'wb'),$fcontent);

            if($res===false){
                return $this->error(lang('save_err'));
            }
            return $this->success(lang('save_ok'));
        }

        $fcontent = (string)@file_get_contents($fullname);
        // Code viewer: one escaped line per entry (the view prints them inside <pre class="ax-code">).
        $lines = preg_split('/\r\n|\n|\r/', $fcontent);
        foreach ($lines as &$line) {
            $line = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        unset($line);
        $this->assign('fname',$fname);
        $this->assign('fpath',$fpath);
        $this->assign('lines',$lines);
        $this->assign('title', $fname);

        return $this->fetch('admin@template/info');
    }

    public function del()
    {
        // F33: theme files are not deleted from the admin any more (route and permission pair kept).
        return $this->error(lang('admin/ux/system/template_readonly'));
        $param = input();
        $fname = $param['fname'];
        if(!empty($fname)){
            if(!is_array($fname)){
                $fname = [$fname];
            }
            foreach($fname as $a){
                $a = str_replace('\\','/',$a);

                if( (substr($a,0,10) != "./template") || count( explode("./",$a) ) > 2) {

                }
                else{
                    $a = mac_convert_encoding($a,"UTF-8","GB2312");
                    if(file_exists($a)){ @unlink($a); }
                }
            }
        }
        return $this->success(lang('del_ok'));
    }

    public function wizard()
    {
        $this->assign('title',lang('admin/template/wizard/title'));
        return $this->fetch('admin@template/wizard');
    }

}
