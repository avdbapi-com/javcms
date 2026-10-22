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
namespace app\index\controller;
use think\Controller;

class Label extends Base
{
    public function __construct()
    {
        parent::__construct();

        $dispatch = request()->dispatch();
        if (isset($dispatch['module'])) {
            $file = $dispatch['module'][2];
            $param = mac_param_url();
            if(!empty($param['file'])){
                $file = $param['file'];
            }
            $file = str_replace('\\','/',$file);
            // Legal pages (SEO-ADMIN-BRIEF F.1): terms, privacy, dmca, 2257, cookies render from LegalPages in the theme
            // frame when enabled (a theme's own label/<slug>.html is ignored), else answer 404.
            if (\app\common\util\LegalPages::isSlug($file)) {
                $html = \app\common\util\LegalPages::render($this, $file);
                if ($html === null) {
                    $this->page_error(lang('page_not_found'));
                }
                echo $html;
                exit;
            }
            if(strpos($file,'/')!==false || strpos($file,'..')!==false || !file_exists($GLOBALS['MAC_ROOT_TEMPLATE'] . 'label/'. $file.'.html')){
                // Unknown label page: HTTP 404 with the theme's 404 page (was a 200 "invalid request" message).
                $this->page_error(lang('page_not_found'));
            }
            // label/<file>/page/<n>: the SEO head needs the page number for its title and self-canonical.
            $this->assign('param', $param);
            echo $this->label_fetch('label/'.$file);
        }
        exit;
    }

    public function index()
    {

    }

}
