<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\debug;

use think\Cache;
use think\Config;
use think\Db;
use think\Debug;
use think\Request;
use think\Response;

/* *
 * Generate the page trace debugging panel */
class Html
{
    protected $config = [
        'trace_file' => '',
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
    public function __construct(array $config = [])
    {
        $this->config['trace_file'] = THINK_PATH . 'tpl/page_trace.tpl';
        $this->config               = array_merge($this->config, $config);
    }

    /* *
 * Build the page trace panel for the response
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
        $runtime = number_format(microtime(true) - THINK_START_TIME, 10, '.', '');
        $reqs    = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';
        $mem     = number_format((memory_get_usage() - THINK_START_MEM) / 1024, 2);

        // Assemble page trace information
        if (isset($_SERVER['HTTP_HOST'])) {
            $uri = $_SERVER['SERVER_PROTOCOL'] . ' ' . $_SERVER['REQUEST_METHOD'] . ' : ' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        } else {
            $uri = 'cmd:' . implode(' ', $_SERVER['argv']);
        }
        $base = [
            'Request Info' => date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']) . ' ' . $uri,
            'Runtime' => number_format($runtime, 6) . 's [ Throughput: ' . $reqs . 'req/s ] Memory Usage: ' . $mem . 'kb Files Loaded: ' . count(get_included_files()),
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
        // Render the page trace template
        ob_start();
        include $this->config['trace_file'];
        return ob_get_clean();
    }

}
