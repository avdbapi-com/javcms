<?php

return [
    // +----------------------------------------------------------------------
    // | App settings
    // +----------------------------------------------------------------------
    // Default host address
    'app_host'               => '',
    // App Debug Mode
    'app_debug'              => false,
    // Application trace output
    'app_trace'              => false,
    // App mode status
    'app_status'             => '',
    // Whether to support multiple modules
    'app_multi_module'       => true,
    // Automatically bind the entry script to a module
    'auto_bind_module'       => false,
    // Registered root namespaces
    'root_namespace'         => [],
    // Additional function files to load
    'extra_file_list'        => [THINK_PATH . 'helper' . EXT],
    // Default output type
    'default_return_type'    => 'html',
    // Default AJAX data return format, optional json xml...
    'default_ajax_return'    => 'json',
    // Default JSONP callback name
    'default_jsonp_handler'  => 'jsonpReturn',
    // Request parameter containing the JSONP callback name
    'var_jsonp_handler'      => 'callback',
    // default time zone
    'default_timezone'       => 'PRC',
    // Enable language switching
    'lang_switch_on'         => false,
    // Global default filters; separate multiple filters with commas
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
    'deny_module_list'       => ['common'],
    // Default controller name
    'default_controller'     => 'Index',
    // Default action name
    'default_action'         => 'index',
    // default validator
    'default_validate'       => '',
    // Fallback controller name when the requested controller is missing
    'empty_controller'       => 'Error',
    // Use action-method prefixes
    'use_action_prefix'      => false,
    // Suffix appended to action method names
    'action_suffix'          => '',
    // Automatically search for controllers
    'controller_auto_search' => false,

    // +----------------------------------------------------------------------
    // | URL settings
    // +----------------------------------------------------------------------

    // Compatibility-mode request parameter containing PATHINFO
    'var_pathinfo'           => 's',
    // Server variables checked for PATHINFO
    'pathinfo_fetch'         => ['ORIG_PATH_INFO', 'REDIRECT_PATH_INFO', 'REDIRECT_URL'],
    // PATHINFO segment separator
    'pathinfo_depr'          => '/',
    // Server variable indicating HTTPS forwarded by a proxy
    'https_agent_name'       => '',
    // URL pseudo-static suffix
    'url_html_suffix'        => 'html',
    // Generate URL parameters as a query string
    'url_common_param'       => false,
    // URL parameter format: 0 for name/value pairs, 1 for positional values
    'url_param_type'         => 0,
    // Whether to enable routing
    'url_route_on'           => true,
    // Routing configuration files (multiple configurations are supported)
    'route_config_file'      => ['route'],
    // Require complete route matches
    'route_complete_match'   => false,
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
    // Form parameter overriding the HTTP request method
    'var_method'             => '_method',
    // Request parameter identifying an AJAX request
    'var_ajax'               => '_ajax',
    // Request parameter identifying a PJAX request
    'var_pjax'               => '_pjax',
    // Enable automatic request caching or specify custom cache rules
    'request_cache'          => false,
    // Request cache lifetime
    'request_cache_expire'   => null,
    // Global request cache exclusion rules
    'request_cache_except'   => [],

    // +----------------------------------------------------------------------
    // | Template settings
    // +----------------------------------------------------------------------

    'template'               => [
        // Automatic template naming: 1 for lowercase with underscores, 2 for lowercase
        'auto_rule'    => 1,
        // Template engine type: PHP, Think or an extension
        'type'         => 'Think',
        // Base view directory for all modules
        'view_base'    => '',
        // View directory; leave empty for automatic resolution
        'view_path'    => '',
        // template suffix
        'view_suffix'  => 'html',
        // template file name separator
        'view_depr'    => DS,
        // Template expression opening delimiter
        'tpl_begin'    => '{',
        // Template expression closing delimiter
        'tpl_end'      => '}',
        // Tag library opening delimiter
        'taglib_begin' => '{',
        // Tag library closing delimiter
        'taglib_end'   => '}',
    ],

    // String replacements applied to rendered view output
    'view_replace_str'       => [],
    // Default template for success and error redirects
    'dispatch_success_tmpl'  => THINK_PATH . 'tpl' . DS . 'dispatch_jump.tpl',
    'dispatch_error_tmpl'    => THINK_PATH . 'tpl' . DS . 'dispatch_jump.tpl',

    // +----------------------------------------------------------------------
    // | Exception and error settings
    // +----------------------------------------------------------------------

    // Exception page template
    'exception_tmpl'         => THINK_PATH . 'tpl' . DS . 'think_exception.tpl',

    // Default error message used outside debug mode
    'error_message'          => 'An error occurred. Please try again later.',
    // Show detailed error messages
    'show_error_msg'         => false,
    // Custom exception handler class; leave empty to use \think\exception\Handle
    'exception_handle'       => '',
    // Whether to record trace information to the log
    'record_trace'           => false,

    // +----------------------------------------------------------------------
    // | Log settings
    // +----------------------------------------------------------------------

    'log'                    => [
        // Logging driver: File, Socket or a custom driver
        'type'  => 'File',
        // Directory where logs are saved
        'path'  => LOG_PATH,
        // Logging levels
        'level' => [],
    ],

    // +----------------------------------------------------------------------
    // | Trace settings are valid when app_trace is enabled
    // +----------------------------------------------------------------------
    'trace'                  => [
        // Trace driver: Html, Console or a custom driver
        'type' => 'Html',
    ],

    // +----------------------------------------------------------------------
    // | Cache settings
    // +----------------------------------------------------------------------

    'cache'                  => [
        // Cache driver
        'type'   => 'File',
        // Cache storage directory
        'path'   => CACHE_PATH,
        // cache prefix
        'prefix' => '',
        // Cache lifetime; 0 means no expiration
        'expire' => 0,
    ],

    // +----------------------------------------------------------------------
    // | Session settings
    // +----------------------------------------------------------------------

    'session'                => [
        'id'             => '',
        // Session ID request parameter for cross-domain Flash uploads
        'var_session_id' => '',
        // SESSION prefix
        'prefix'         => 'think',
        // Session driver; Redis, Memcache and Memcached are supported
        'type'           => '',
        // Whether to automatically start the session
        'auto_start'     => true,
        'httponly'       => true,
        'secure'         => false,
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

    // +----------------------------------------------------------------------
    // | Database settings
    // +----------------------------------------------------------------------

    'database'               => [
        // database type
        'type'            => 'mysql',
        // DSN database connection configuration
        'dsn'             => '',
        // server address
        'hostname'        => '127.0.0.1',
        // database name
        'database'        => '',
        // database user name
        'username'        => 'root',
        // database password
        'password'        => '',
        // database connection port
        'hostport'        => '',
        // database connection parameters
        'params'          => [],
        // Database character encoding; defaults to utf8
        'charset'         => 'utf8',
        // database table prefix
        'prefix'          => '',
        // database debugging mode
        'debug'           => false,
        // Database deployment method: 0 centralized (single server), 1 distributed (master/slave server)
        'deploy'          => 0,
        // Use separate database connections for reads and writes
        'rw_separate'     => false,
        // Number of primary servers after read/write separation
        'master_num'      => 1,
        // Replica server index
        'slave_no'        => '',
        // Strictly validate field names
        'fields_strict'   => true,
        // Data set return type
        'resultset_type'  => 'array',
        // Automatically write to the timestamp field
        'auto_timestamp'  => false,
        // Output format for date and time fields
        'datetime_format' => 'Y-m-d H:i:s',
        // Enable SQL performance analysis
        'sql_explain'     => false,
    ],

    // Pagination configuration
    'paginate'               => [
        'type'      => 'bootstrap',
        'var_page'  => 'page',
        'list_rows' => 15,
    ],

    // console configuration
    'console'                => [
        'name'    => 'Think Console',
        'version' => '0.1',
        'user'    => null,
    ],

];
