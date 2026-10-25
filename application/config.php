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
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
return [
    // +----------------------------------------------------------------------
    // | App settings
    // +----------------------------------------------------------------------

    // App namespaces
    'app_namespace'          => 'app',
    // App Debug Mode
    'app_debug'              => false,
    // Apply trace
    'app_trace'              => false,
    // App mode status
    'app_status'             => '',
    // Whether to support multiple modules
    'app_multi_module'       => true,
    // Automatic entry binding module
    'auto_bind_module'       => false,
    // Registered root namespaces
    'root_namespace'         => [],
    // extension function file
    'extra_file_list'        => [THINK_PATH . 'helper' . EXT],
    // Default output type
    'default_return_type'    => 'html',
    // Default AJAX data return format, optional json xml...
    'default_ajax_return'    => 'json',
    // Processing method for returns in the default JSONP format
    'default_jsonp_handler'  => 'jsonpReturn',
    // Default JSONP processing method
    'var_jsonp_handler'      => 'callback',
    // default time zone
    'default_timezone'       => 'UTC', // global default (was PRC); Site settings > Time zone overrides it (ADMIN-IA-BRIEF.md HP-3)
    // Whether to enable multi-language
    'lang_switch_on'         => false,
    // The default global filter method separates multiple ones with commas
    'default_filter'         => '',
    // default language
    'default_lang'           => 'en-us',
    // Application class library suffix
    'class_suffix'           => false,
    // Controller class suffix
    'controller_suffix'      => false,

    // +----------------------------------------------------------------------
    // | Module settings
    // +----------------------------------------------------------------------

    // Default module name
    'default_module'         => 'index',
    // Block access to modules
    'deny_module_list'       => ['common','data'],
    // Default controller name
    'default_controller'     => 'Index',
    // Default action name
    'default_action'         => 'index',
    // default validator
    'default_validate'       => '',
    // Default empty controller name
    'empty_controller'       => 'MyError',
    // Operation method suffix
    'action_suffix'          => '',
    // Auto search controller
    'controller_auto_search' => false,

    // +----------------------------------------------------------------------
    // | URL settings
    // +----------------------------------------------------------------------

    // PATHINFO variable names are used in compatibility mode
    'var_pathinfo'           => 's',
    // GET COMPATIBLE PATH_INFO
    'pathinfo_fetch'         => ['ORIG_PATH_INFO', 'REDIRECT_PATH_INFO', 'REDIRECT_URL'],
    // pathinfo
    'pathinfo_depr'          => '-',
    // URL pseudo-static suffix
    'url_html_suffix'        => 'html|htm|shtm|shtml|xml|txt',
    // Normal URL parameters are used for automatic generation
    'url_common_param'       => false,
    // URL parameter method 0 parse in pairs by name 1 parse in order
    'url_param_type'         => 0,
    // Whether to enable routing
    'url_route_on'           => false,
    // The route uses an exact match
    'route_complete_match'   => false,
    // Routing configuration files (multiple configurations are supported)
    'route_config_file'      => ['route'],
    // Whether to force the use of routes
    'url_route_must'         => false,
    // Domain name deployment
    'url_domain_deploy'      => false,
    // Domain root, such as thinkphp.cn
    'url_domain_root'        => '',
    // Whether to automatically convert controller and action names in URLs
    'url_convert'            => true,
    // Default access controller layer
    'url_controller_layer'   => 'controller',
    // Form request type masquerade variable
    'var_method'             => '_method',
    // Form ajax masquerade variables
    'var_ajax'               => '_ajax',
    // Form pjax masquerade variables
    'var_pjax'               => '_pjax',
    // Whether to enable request caching true automatic caching supports setting request caching rules
    'request_cache'          => false,
    // Request cache expiration date
    'request_cache_expire'   => null,
    // Global request cache exclusion rules
    'request_cache_except'   => [],
    // Renamed video category addresses: old type_en => current type_en. A GET for an old address that no live
    // category uses answers HTTP 301 with the current one; page, filters and query string are kept
    // (/category/avdb-6-2/?ui_lang=vi -> /category/hentai-2/?ui_lang=vi). See All::type_slug_redirect().
    // avdb-6 / avdb-7 were the old importer's automatic addresses of the AVDB categories Hentai and English subtitle.
    'category_slug_redirects' => [
        'avdb-6' => 'hentai',
        'avdb-7' => 'english-subtitle',
    ],

    // +----------------------------------------------------------------------
    // | Template settings
    // +----------------------------------------------------------------------

    'template'               => [
        // Template engine type support php think support extensions
        'type'         => 'Think',
        // template path
        'view_path'    => './template/',
        // template suffix
        'view_suffix'  => 'html',
        // template file name separator
        'view_depr'    => DS,
        // Template engine normal tag start tag
        'tpl_begin'    => '{',
        // Template engine normal tag end tag
        'tpl_end'      => '}',
        // Tag library tag start tagging
        'taglib_begin' => '{',
        // Tag library tag end tag
        'taglib_end'   => '}',
        // Pre-loaded tag library
        'taglib_pre_load'     =>    'app\common\taglib\Maccms',
    ],

    // View output string content replacement
    'view_replace_str'       => [],
    // Default redirects to the template file corresponding to the page
    'dispatch_success_tmpl'  => THINK_PATH . 'tpl' . DS . 'dispatch_jump.tpl',
    'dispatch_error_tmpl'    => THINK_PATH . 'tpl' . DS . 'dispatch_jump.tpl',

    // +----------------------------------------------------------------------
    // | Abnormal and incorrect settings
    // +----------------------------------------------------------------------

    // Template files for unusual pages
    'exception_tmpl'         => THINK_PATH . 'tpl' . DS . 'think_exception.tpl',

    // error display message, not in debug mode valid
    'error_message'          => 'Something went wrong. Please try again later.',
    // Never print exception text to visitors (debug mode and the admin/installer entrances keep details, see ErrorHandle)
    'show_error_msg'         => false,
    // Public error pages: themed 404, clean 500, details only in debug mode (application/common/util/ErrorHandle.php)
    'exception_handle'       => '\\app\\common\\util\\ErrorHandle',

    // +----------------------------------------------------------------------
    // | Log settings
    // +----------------------------------------------------------------------

    'log'                    => [
        // Logging method, built-in file socket test supports extensions
        'type'  => 'test',
        // Directory where logs are saved
        'path'  => LOG_PATH,
        // Logging levels
        'level' => ['sql'],
    ],

    // +----------------------------------------------------------------------
    // | Trace settings are valid when app_trace is enabled
    // +----------------------------------------------------------------------
    'trace'                  => [
        // Built-in Html Console support extensions
        'type' => 'Html',
    ],

    // +----------------------------------------------------------------------
    // | Cache settings
    // +----------------------------------------------------------------------

    'cache'                  => [
        // Drive method
        'type'   => 'File',
        // Cache storage directory
        'path'   => CACHE_PATH,
        // cache prefix
        'prefix' => '',
        // The cache expiration date 0 indicates a permanent cache
        'expire' => 600,
    ],

    // +----------------------------------------------------------------------
    // | Session settings
    // +----------------------------------------------------------------------

    'session'                => [
        'id'             => '',
        // SESSION_ID submission variable to solve cross-domain flash upload
        'var_session_id' => '',
        // SESSION prefix
        'prefix'         => 'think',
        // The driving method supports redis memcache memcached
        'type'           => '',
        // Whether to automatically start the session
        'auto_start'     => true,
        // Session cookie never readable by page scripts (all entrances: index.php, javcms.php admin, install.php).
        // index.php already sets this itself (public-security 2026-10-14); this line covers the admin entrance too.
        // think\Session::init() applies it with ini_set('session.cookie_httponly', ...).
        'httponly'       => true,
    ],

    // +----------------------------------------------------------------------
    // | Cookie settings
    // +----------------------------------------------------------------------
    'cookie'                 => [
        // cookie name prefix
        'prefix'    => '',
        // Cookie storage time
        'expire'    => 0,
        // Cookie storage path
        'path'      => '/',
        // Valid domain name for cookies
        'domain'    => '',
        // Enable secure transmission of cookies
        'secure'    => false,
        // httponly settings
        'httponly'  => '',
        // Whether to use setcookie
        'setcookie' => true,
    ],

    // Pagination configuration
    'paginate'               => [
        'type'      => 'bootstrap',
        'var_page'  => 'page',
        'list_rows' => 20,
    ],

];
