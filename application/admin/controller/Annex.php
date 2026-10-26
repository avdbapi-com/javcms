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

class Annex extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function data()
    {
        $param = input();
        $param['page'] = max(1, intval($param['page']));
        // B5: 1..1000 rows per page (default = the admin page size).
        $param['limit'] = intval($param['limit']) < 1 ? $this->_pagesize : min((int)$param['limit'], 1000);

        $where = [];
        if (!empty($param['type'])) {
            $where['annex_type'] = ['eq', $param['type']];
        }
        if (!empty($param['mid'])) {
            $where['annex_mid'] = ['eq', $param['mid']];
        }
        if(!empty($param['wd'])){
            $param['wd'] = htmlspecialchars(urldecode($param['wd']));
            $where['annex_file'] = ['like','%'.$param['wd'].'%'];
        }

        $order='annex_time desc';
        $res = model('Annex')->listData($where,$order,$param['page'],$param['limit']);

        $this->assign('list', $res['list']);
        $this->assign('total', $res['total']);
        $this->assign('page', $res['page']);
        $this->assign('limit', $res['limit']);

        // F17: only draw the buttons this admin may use.
        $this->assign('can', ['del'=>$this->check_auth('annex','del'), 'check'=>$this->check_auth('annex','check'), 'init'=>$this->check_auth('annex','init'), 'file'=>$this->check_auth('annex','file')]);

        $param['page'] = '{page}';
        $param['limit'] = '{limit}';
        $this->assign('param', $param);

        $this->assign('title', lang('admin/ux/menu/uploaded_files'));
        return $this->fetch('admin@annex/index');
    }

    public function file()
    {
        $path = input('path');
        $path = str_replace('\\','',$path);
        $path = str_replace('/','',$path);

        if(empty($path)){
            $path = '@upload';
        }

        if(substr($path,0,7) != "@upload") { $path = "@upload"; }
        if(count( explode("..@",$path) ) > 1) {
            $this->error(lang('illegal_request'));
            return;
        }


        $uppath = substr($path,0,strrpos($path,"@"));

        $ischild = 0;
        if ($path !="@upload"){
            $ischild = 1;
        }
        $this->assign('uppath',$uppath);
        $this->assign('ischild',$ischild);


        $num_path = 0;
        $num_file = 0;
        $sum_size = 0;
        $filters = ",,cache,break,artcollect,downdata,playdata,export,vodcollect,";
        $files = [];

        $pp = str_replace('@','/',$path);

        if(is_dir('.'.$pp)){

            $farr = glob('.'.$pp.'/*');
            if($farr){
                foreach($farr as $f){

                    if ( is_dir($f) ){

                        if(strpos($filters,",".$f.",")<=0){
                            $num_path++;
                            $tmp_path = str_replace('./upload/','@upload/',$f);
                            $tmp_path = str_replace('/','@',$tmp_path);

                            $tmp_name = str_replace($path.'@','',$tmp_path);


                            $files[] = ['isfile'=>0,'name'=>$tmp_name,'path'=>$tmp_path];
                        }
                    }
                    elseif(is_file($f)){
                        if (strpos($f,".html") <=0 && strpos($f,".htm") <=0){
                            $num_file++;
                            $fsize = filesize($f);
                            $sum_size += $fsize;
                            $fsize = mac_format_size($fsize);
                            $ftime = filemtime($f);
                            $tmp_path = mac_convert_encoding($f,"UTF-8","GB2312");

                            $tmp_path = str_replace('./upload/','@upload/',$f);
                            $tmp_path = str_replace('/','@',$tmp_path);

                            $tmp_name = str_replace($path.'@',"",$tmp_path);
                            $tmp_path = str_replace('@','/',$tmp_path);

                            $files[] = ['isfile'=>1,'name'=>$tmp_name,'path'=>$tmp_path, 'size'=>$fsize, 'time'=>$ftime];
                        }
                    }

                }
            }
        }
        $this->assign('sum_size',mac_format_size($sum_size));
        $this->assign('num_file',$num_file);
        $this->assign('num_path',$num_path);

        $this->assign('files',$files);
        $this->assign('can', ['del'=>$this->check_auth('annex','del')]);

        $this->assign('title',lang('admin/annex/title'));
        return $this->fetch('admin@annex/file');
    }

    // r5 (admin-leftovers): the old annex/info page (a read-only copy of the actor editor with no Save) is removed.
    // Nothing linked to it; the Attachments list shows each file's name, size, type and link.

    public function del()
    {
        $param = input();
        if (!$this->axCsrfOk($param)) {
            return $this->error(lang('token_err'));
        }
        $ids = $param['ids'];

        if(!empty($ids)){
            if(is_array($ids)){
                foreach($ids as $k=>$v){
                    $ids[$k] = str_replace('./','',$v);
                }
            }
            $where=[];
            $where['annex_id|annex_file'] = ['in',$ids];
            $res = model('Annex')->delData($where);
            if($res['code']>1){
                return $this->error($res['msg']);
            }
            return $this->success($res['msg']);
        }
        return $this->error(lang('param_err'));
    }

    /**
     * F47 "Detect missing files": the scan only LISTS records whose file is gone (count + first 100 paths) and keeps
     * their ids in the admin session; "Remove {n} records" (op=remove, POST + token + confirm=1) deletes exactly those
     * records, re-checking that each file is still missing. Nothing is deleted by the scan.
     */
    public function check()
    {
        $param = input();
        $op = isset($param['op']) && is_string($param['op']) ? $param['op'] : 'scan';
        if ($op === 'remove') {
            $token = isset($param['__token__']) && is_string($param['__token__']) ? $param['__token__'] : '';
            if (!request()->isPost() || !isset($param['confirm']) || (string)$param['confirm'] !== '1') {
                return $this->error(lang('param_err'));
            }
            if ($token === '' || !hash_equals((string)mac_admin_csrf_token(), $token)) {
                return $this->error(lang('token_err'));
            }
            $ids = session('admin_annex_missing');
            if (!is_array($ids) || empty($ids)) {
                return $this->error(lang('admin/ux/content/missing_none'));
            }
            $gone = [];
            foreach (array_chunk(array_map('intval', $ids), 500) as $chunk) {
                $rows = Db::name('Annex')->field('annex_id,annex_file')->where('annex_id', 'in', $chunk)->select();
                foreach ($rows as $row) {
                    if (!file_exists('./' . $row['annex_file'])) {
                        $gone[] = (int)$row['annex_id'];
                    }
                }
            }
            foreach (array_chunk($gone, 500) as $chunk) {
                Db::name('Annex')->where('annex_id', 'in', $chunk)->delete();
            }
            session('admin_annex_missing', null);
            return $this->success(str_replace('{n}', (string)count($gone), lang('admin/ux/content/records_removed')), null, ['count' => count($gone)]);
        }

        $missing = [];
        $sample = [];
        Db::name('Annex')->field('annex_id,annex_file')->chunk(500, function ($rows) use (&$missing, &$sample) {
            foreach ($rows as $row) {
                if (!file_exists('./' . $row['annex_file'])) {
                    $missing[] = (int)$row['annex_id'];
                    if (count($sample) < 100) {
                        $sample[] = (string)$row['annex_file'];
                    }
                }
            }
        }, 'annex_id');
        session('admin_annex_missing', $missing);
        return $this->success('ok', null, ['count' => count($missing), 'paths' => $sample]);
    }

    public function init()
    {
        $param = input();

        if(!empty($param['ck'])){
            // close-security (security r4 P3-8): "Rebuild the file list" writes rows. Step 1 must be a POST with the admin
            // token (the page's Run button); the next steps carry a one-off run key kept in the session, never the token.
            $run = isset($param['run']) && is_string($param['run']) ? $param['run'] : '';
            unset($param['__token__']);
            if ($run !== '' && !request()->isPost()) {
                $saved = session('admin_annex_init_run');
                if (!is_string($saved) || $saved === '' || !hash_equals($saved, $run)) {
                    return $this->error(lang('token_err'));
                }
            } else {
                if (!request()->isPost() || !$this->axCsrfOk(input('post.'))) {
                    return $this->error(lang('token_err'));
                }
                $run = bin2hex(random_bytes(12));
                session('admin_annex_init_run', $run);
                $param = ['ck' => 1, 'run' => $run, 'start' => 1, 'tbi' => 0];
            }

            mac_echo('<style type="text/css">body{font-size:12px;color: #333333;line-height:21px;}span{font-weight:bold;color:#FF0000}</style>');

            $start = intval($param['start']);
            if($start<1){
                $start=1;
            }

            $pre = config('database.prefix');
            $schema = Db::query('select * from information_schema.columns where table_schema = ?', [config('database.database')]);
            $col_list = [];
            foreach ($schema as $k => $v) {
                $col_list[$v['TABLE_NAME']][$v['COLUMN_NAME']] = $v;
            }
            $tables = ['actor', 'art', 'topic', 'type', 'vod', 'website' ,'actor', 'role'];
            $param['tbi'] = intval($param['tbi']);
            if ($param['tbi'] >= count($tables)) {
                session('admin_annex_init_run', null);
                mac_echo(lang('admin/annex/check_ok'));
                die;
            }
            $tab = $tables[$param['tbi']];

            $where=[];
            $page_size = 500;
            $data_count = model($tab)->countData($where);
            $page_count = ceil($data_count / $page_size);

            if($start > $page_count){
                mac_echo(lang('admin/annex/check_jump',[$tab]));
                $param['tbi']++;
                $param['start'] = 1;
                $url = url('annex/init') . '?' . http_build_query($param);
                mac_jump($url, 3);
                exit;
            }

            mac_echo(lang('admin/annex/check_tip1',[$tab,$data_count,$page_count,$page_size,$start]));

            foreach ($col_list as $k1 => $v1) {
                $pre_tb = str_replace($pre, '', $k1);
                $si = array_search($pre_tb, $tables);
                if ($pre_tb !== $tab) {
                    continue;
                }
                $limit_str = ($page_size * ($page_count-$start)) .",".$page_size;
                $list = Db::name($pre_tb)->field('*')->limit($limit_str)->fetchSql(false)->select();

                $adds = [];
                foreach ($list as $k3 => $v3) {
                    $col_id = $tables[$si] . '_id';
                    $col_name = $tables[$si] . '_name';
                    $val_id = $v3[$col_id];;
                    $val_name = strip_tags($v3[$col_name]);
                    $ck = false;
                    $where2 = [];
                    $where2[$col_id] = $val_id;
                    $imgs = [];
                    $add = [];
                    $add['id'] = $val_id;
                    $add['name'] = $val_name;
                    $add['col_id'] = $col_id;

                    $col = $tables[$si] . '_pic';
                    $val = $v3[$col];
                    if (substr($val, 0, 6) == 'upload' && file_exists('./' . $val)) {
                        $imgs[] = ['annex_file' => $val, 'annex_time' => time(), 'annex_size' => filesize('./' . $val), 'annex_type' => 'image'];
                        $ck = true;
                    }
                    $col = $tables[$si] . '_pic_thumb';
                    $val = $v3[$col];
                    if (substr($val, 0, 6) == 'upload' && file_exists('./' . $val)) {
                        $imgs[] = ['annex_file' => $val, 'annex_time' => time(), 'annex_size' => filesize('./' . $val), 'annex_type' => 'image'];
                        $ck = true;
                    }
                    $col = $tables[$si] . '_pic_slide';
                    $val = $v3[$col];
                    if (substr($val, 0, 6) == 'upload' && file_exists('./' . $val)) {
                        $imgs[] = ['annex_file' => $val, 'annex_time' => time(), 'annex_size' => filesize('./' . $val), 'annex_type' => 'image'];
                        $ck = true;
                    }

                    $col = $tables[$si] . '_content';
                    $val = $v3[$col];
                    if (!empty($val)) {
                        $rule = mac_buildregx("<img[^>]*src\s*=\s*['" . chr(34) . "]?([\w/\-\:.]*)['" . chr(34) . "]?[^>]*>", "is");
                        preg_match_all($rule, $val, $matches);

                        $matchfieldarr = $matches[1];
                        foreach ($matchfieldarr as $f => $matchfieldstr) {
                            $img_src = trim(preg_replace("/[ \r\n\t\f]{1,}/", " ", $matchfieldstr));
                            if (substr($img_src, 0, 7) == '/upload' && file_exists('.' . $img_src)) {
                                $imgs[] = ['annex_file' => substr($img_src, 1), 'annex_time' => time(), 'annex_size' => filesize('.' . $img_src), 'annex_type' => 'image'];
                                $ck = true;
                            }
                        }
                    }
                    $add['imgs'] = $imgs;
                    $adds[] = $add;
                }
                if (!empty($adds)) {
                    $insert = [];
                    foreach ($adds as $k => $v) {
                        $des = '<font color=red>'.lang('skip').'</font>';
                        if (!empty($v['imgs'])) {
                            foreach($v['imgs'] as $k2 => $v2){
                                $where = [];
                                $where['annex_file'] = $v2['annex_file'];
                                $r = model('Annex')->infoData($where);
                                if ($r['code'] !== 1) {
                                    $insert[] = $v2;
                                    $des = '<font color=green>ok</font>';
                                }
                            }
                        }
                        mac_echo($v['name'] . '...' . $des);
                        model('Annex')->insertAll($insert);
                    }
                }
            }

            $param['start']++;
            $url = url('annex/init') . '?' . http_build_query($param);
            mac_jump($url, 3);
            exit;
        }
        return $this->fetch('admin@annex/init');
    }

    // CSRF: the list posts __token__ (mac_admin_csrf_token()); a write replayed without it is refused.
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
