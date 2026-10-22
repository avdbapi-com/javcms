<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yangweijie <yangweijiester@gmail.com>
// +----------------------------------------------------------------------

namespace think\debug;

use think\Cache;
use think\Config;
use think\Db;
use think\Debug;
use think\Request;
use think\Response;

/* *
 * Generate browser console debugging output */
class Console
{
    protected $config = [
        'trace_tabs' => [
            'base' => 'Basic',
            'file' => 'Files',
            'info' => 'Info',
            'notice|error' => 'Error',
            'sql' => 'SQL',
            'debug|log' => 'Debug',
        ],
        
    ];

    // Initialize the debugging configuration
    public function __construct($config = [])
    {
        if (is_array($config)) {
            $this->config = array_merge($this->config, $config);
        }
    }

    /* *
 * Build console output for the response
 * @access public
 * @param Response $response Response object
 * @param array $log Log messages grouped by level
 * @return string|bool */
    public function output(Response $response, array $log = [])
    {
        $request     = Request::instance();
        $contentType = $response->getHeader('Content-Type');
        $accept      = $request->header('accept');
        if (strpos($accept, 'application/json') === 0 || $request->isAjax()) {
            return false;
        } elseif (!empty($contentType) && strpos($contentType, 'html') === false) {
            return false;
        }
        // Collect request performance information
        $runtime = number_format(microtime(true) - THINK_START_TIME, 10);
        $reqs    = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';
        $mem     = number_format((memory_get_usage() - THINK_START_MEM) / 1024, 2);

        if (isset($_SERVER['HTTP_HOST'])) {
            $uri = $_SERVER['SERVER_PROTOCOL'] . ' ' . $_SERVER['REQUEST_METHOD'] . ' : ' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        } else {
            $uri = 'cmd:' . implode(' ', $_SERVER['argv']);
        }

        // Assemble page trace information
        $base = [
            'Request Info' => date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']) . ' ' . $uri,
            'Runtime' => number_format($runtime, 6) . 's [Throughput: ' . $reqs . 'req/s] Memory Usage: ' . $mem . 'kb Files Loaded: ' . count(get_included_files()),
            'Query Info' => Db::$queryTimes . ' queries ' . Db::$executeTimes . ' writes',
            'Cache Info' => Cache::$readTimes . ' reads, ' . Cache::$writeTimes . ' writes',
            'Config Loaded' => count(Config::get()),
        ];
        
        if (session_id()) {
            $base['Session Info'] = 'SESSION_ID=' . session_id();
        }
        

        $info = Debug::getFile(true);

        // Assemble page trace information
        $trace = [];
        foreach ($this->config['trace_tabs'] as $name => $title) {
            $name = strtolower($name);
            switch ($name) {
                case 'base': // basic information
                    $trace[$title] = $base;
                    break;
                case 'file': // file information
                    $trace[$title] = $info;
                    break;
                default: // Debugging information
                    if (strpos($name, '|')) {
                        // Merge the configured log levels into one trace tab
                        $names  = explode('|', $name);
                        $result = [];
                        foreach ($names as $name) {
                            $result = array_merge($result, isset($log[$name]) ? $log[$name] : []);
                        }
                        $trace[$title] = $result;
                    } else {
                        $trace[$title] = isset($log[$name]) ? $log[$name] : '';
                    }
            }
        }

        // Generate JavaScript for the browser console
        $lines = '';
        foreach ($trace as $type => $msg) {
            $lines .= $this->console($type, $msg);
        }
        $js = <<<JS

<script type='text/javascript'>
{$lines}
</script>
JS;
        return $js;
    }

    protected function console($type, $msg)
    {
        $type       = strtolower($type);
        $trace_tabs = array_values($this->config['trace_tabs']);
        $line[]     = ($type == $trace_tabs[0] || 'Debug' == $type || 'Error' == $type)
        ? "console.group('{$type}');"
        : "console.groupCollapsed('{$type}');";

        foreach ((array) $msg as $key => $m) {
            switch ($type) {
                case 'Debug':
                    $var_type = gettype($m);
                    if (in_array($var_type, ['array', 'string'])) {
                        $line[] = "console.log(" . json_encode($m) . ");";
                    } else {
                        $line[] = "console.log(" . json_encode(var_export($m, 1)) . ");";
                    }
                    break;
                case 'Error':
                    $msg    = str_replace("\n", '\n', json_encode($m));
                    $style  = 'color:#F4006B;font-size:14px;';
                    $line[] = "console.error(\"%c{$msg}\", \"{$style}\");";
                    break;
                case 'sql':
                    $msg    = str_replace("\n", '\n', $m);
                    $style  = "color:#009bb4;";
                    $line[] = "console.log(\"%c{$msg}\", \"{$style}\");";
                    break;
                default:
                    $m      = is_string($key) ? $key . ' ' . $m : $key + 1 . ' ' . $m;
                    $msg    = json_encode($m);
                    $line[] = "console.log({$msg});";
                    break;
            }
        }
        $line[] = "console.groupEnd();";
        return implode(PHP_EOL, $line);
    }

}
