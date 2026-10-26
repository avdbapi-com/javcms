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

class VodPlayer extends Base
{
    var $_pre;
    public function __construct()
    {
        parent::__construct();
        $this->_pre = 'vodplayer';
    }

    public function index()
    {
        $list = config($this->_pre);
        $this->assign('list',$list);
        $this->assign('title',lang('admin/vodplayer/title'));
        return $this->fetch('admin@vodplayer/index');
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
            $code = $param['code'];
            unset($param['code']);
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
                return $this->ajaxErrorWithFreshToken(lang('write_err_config'));
            }

            $res = fwrite(fopen('./static/player/' . $param['from'].'.js','wb'),$code);
            if($res===false){
                return $this->ajaxErrorWithFreshToken(lang('wirte_err_codefile'));
            }
            cache('cache_data','1');
            return $this->ajaxSuccessWithFreshToken(lang('save_ok'));
        }

        $info = $list[$param['id']];
        if(!empty($info)){
            $code = file_get_contents('./static/player/' . $param['id'].'.js');
            $info['code'] = $code;
        }
        $this->assign('info',$info);
        $this->assign('title',lang('admin/vodplayer/title'));
        return $this->fetch('admin@vodplayer/info');
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

    public function export()
    {
        $param = input();
        $list = config($this->_pre);
        $info = $list[$param['id']];
        if(!empty($info)){
            $code = file_get_contents('./static/player/' . $param['id'].'.js');
            $info['code'] = $code;
        }

        header("Content-type: application/octet-stream");
        if(strpos($_SERVER['HTTP_USER_AGENT'], "MSIE")) {
            header("Content-Disposition: attachment; filename=mac_" . urlencode($info['from']) . '.txt');
        }
        else{
            header("Content-Disposition: attachment; filename=mac_" . $info['from'] . '.txt');
        }
        echo base64_encode(json_encode($info));
    }

    public function import()
    {
        if (request()->isPost()) {
            $param = input();
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->ajaxErrorWithFreshToken($validate->getError());
            }
            unset($param['__token__']);
            $file = $this->request->file('file');
            if (!($file instanceof \think\File) || $file->getInfo('error') !== UPLOAD_ERR_OK || !$file->isValid()) {
                return $this->ajaxErrorWithFreshToken(lang('format_err'));
            }
            if (!$file->check(['size' => 10240000, 'ext' => 'txt'])) {
                return $this->ajaxErrorWithFreshToken($file->getError());
            }

            $contents = @file_get_contents($file->getPathname());
            @unlink($file->getPathname());
            $decoded = is_string($contents) ? base64_decode($contents, true) : false;
            $data = $decoded === false ? null : json_decode($decoded, true);
            if (!is_array($data) || empty($data['status']) || empty($data['from']) || empty($data['sort'])
                || !is_scalar($data['status']) || !is_scalar($data['sort']) || !is_scalar($data['from'])
                || !array_key_exists('code', $data) || !is_scalar($data['code'])) {
                return $this->ajaxErrorWithFreshToken(lang('format_err'));
            }
            $from = (string) $data['from'];
            if (strpos($from, '.') !== false || strpos($from, '/') !== false || strpos($from, '\\') !== false || strpos($from, "\0") !== false) {
                return $this->ajaxErrorWithFreshToken(lang('param_err'));
            }
            if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $from)) {
                return $this->ajaxErrorWithFreshToken(lang('param_err'));
            }
            $code = $data['code'];
            unset($data['code']);

            $list = config($this->_pre);
            $list[$data['from']] = $data;
            $error = $this->writeImportedPlayerFiles(
                APP_PATH . 'extra/' . $this->_pre . '.php',
                './static/player/' . $from . '.js', $list, $code
            );
            if ($error !== null) {
                return $this->ajaxErrorWithFreshToken(lang($error));
            }
            return $this->ajaxSuccessWithFreshToken(lang('import_ok'));
        }
        else{
            return $this->fetch('admin@vodplayer/import');
        }
    }

    /** Stage both files before replacing either; retain a failed rollback backup. */
    private function writeImportedPlayerFiles($configPath, $playerPath, array $list, $code)
    {
        $files = [
            ['path' => $playerPath, 'contents' => (string) $code, 'error' => 'wirte_err_codefile'],
            ['path' => $configPath, 'contents' => "<?php\nreturn " . var_export($list, true) . ';', 'error' => 'write_err_config'],
        ];
        $staged = [];
        $backups = [];
        $applied = [];
        $retain = [];
        try {
            foreach ($files as $index => $file) {
                $path = $file['path'];
                $directory = realpath(dirname($path));
                if ($directory === false || !is_dir($directory) || (file_exists($path) && !is_file($path))) {
                    return $file['error'];
                }
                $mode = is_file($path) ? @fileperms($path) & 0777 : 0666 & ~umask();
                $temporary = @tempnam($directory, '.mac-import-');
                if ($temporary === false) {
                    return $file['error'];
                }
                $staged[$index] = $temporary;
                if (realpath(dirname($temporary)) !== $directory
                    || @file_put_contents($temporary, $file['contents']) !== strlen($file['contents'])
                    || !@chmod($temporary, $mode)) {
                    return $file['error'];
                }
                $backups[$index] = null;
                if (is_file($path)) {
                    $backup = @tempnam($directory, '.mac-import-');
                    if ($backup === false) {
                        return $file['error'];
                    }
                    $backups[$index] = $backup;
                    if (realpath(dirname($backup)) !== $directory || !@copy($path, $backup) || !@chmod($backup, $mode)) {
                        return $file['error'];
                    }
                }
            }
            foreach ($files as $index => $file) {
                if (!@rename($staged[$index], $file['path'])) {
                    foreach (array_reverse($applied) as $restore) {
                        $backup = $backups[$restore];
                        if (@file_get_contents($files[$restore]['path']) !== $files[$restore]['contents']) {
                            $retain[$restore] = true;
                            \think\Log::error('Player import rollback deferred; target changed: ' . json_encode([
                                'target' => $files[$restore]['path'], 'backup' => $backup,
                            ]));
                            continue;
                        }
                        $restored = $backup === null
                            ? @unlink($files[$restore]['path'])
                            : @rename($backup, $files[$restore]['path']);
                        if (!$restored) {
                            $retain[$restore] = true;
                            \think\Log::error('Player import rollback failed: ' . json_encode([
                                'target' => $files[$restore]['path'], 'backup' => $backup,
                            ]));
                        }
                    }
                    return $file['error'];
                }
                unset($staged[$index]);
                $applied[] = $index;
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($configPath, true);
            }
            return null;
        } finally {
            foreach ($staged as $temporary) {
                @chmod($temporary, 0666);
                @unlink($temporary);
            }
            foreach ($backups as $index => $backup) {
                if ($backup !== null && !isset($retain[$index])) {
                    @chmod($backup, 0666);
                    @unlink($backup);
                }
            }
        }
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
