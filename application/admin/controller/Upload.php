<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\admin\controller;
use think\Db;



class Upload extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    // xss-fix-B (security r8 P1-3): the old picture-upload popup index() had no link, posted without a token and
    // printed ?path= / ?id= raw. It is removed, so upload/index answers 404 like annex/info.

    public function test()
    {
        $temp_file = tempnam(sys_get_temp_dir(), 'Tux');
        if($temp_file){
            // r7 repair (sweep): a write test must not leave a new empty file in the temp folder on every visit.
            @unlink($temp_file);
            echo lang('admin/upload/test_write_ok').'：' . $temp_file;
        }
        else{
            echo lang('admin/upload/test_write_err').'：' . sys_get_temp_dir() ;
        }
    }

    public function upload($p=[])
    {
        // r5 repair (security r5 P2-1, defence in depth): an upload is a POST from this admin's own pages. It passes with
        // the admin token (body __token__ or the X-CSRF-Token header), or when the browser marks the request as coming
        // from this same site (Sec-Fetch-Site / Origin / Referer) - the layui uploader and the rich-text editors send no
        // token. A GET, or a POST from another site, is refused and saves nothing.
        if (!$this->uploadRequestOk()) {
            return json(['code' => 0, 'msg' => lang('admin/ux/system/token_expired')]);
        }
		return model('Upload')->upload($p);
    }

    private function uploadRequestOk()
    {
        $req = request();
        if (!$req->isPost()) {
            return false;
        }
        $sent = $req->post('__token__', '');
        $sent = is_string($sent) ? trim($sent) : '';
        if ($sent === '') {
            $sent = trim((string)$req->header('X-CSRF-Token'));
        }
        if ($sent !== '' && hash_equals((string)mac_admin_csrf_token(), $sent)) {
            return true;
        }
        $site = strtolower(trim((string)$req->header('Sec-Fetch-Site')));
        if ($site !== '') {
            return $site === 'same-origin';
        }
        $host = strtolower((string)$req->header('Host'));
        foreach (['Origin', 'Referer'] as $h) {
            $v = trim((string)$req->header($h));
            if ($v === '' || $v === 'null') {
                continue;
            }
            $u = parse_url($v);
            if (!is_array($u) || empty($u['host'])) {
                return false;
            }
            $from = strtolower($u['host']) . (isset($u['port']) ? ':' . $u['port'] : '');
            return $host !== '' && ($from === $host || preg_replace('/:(80|443)$/', '', $host) === $from);
        }
        return false;
    }


}
