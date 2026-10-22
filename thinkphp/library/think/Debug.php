<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think;

use think\exception\ClassNotFoundException;
use think\response\Redirect;

class Debug
{
    /* *
 * @var array interval time information */
    protected static $info = [];

    /* *
 * @var array Memory-usage and peak-memory marks */
    protected static $mem = [];

    /* *
 * Record a time mark and optional memory marks
 * @access public
 * @param string $name Mark name
 * @param mixed $value A float supplies time and memory-usage values; 'time' skips memory recording
 * @return void */
    public static function remark($name, $value = '')
    {
        self::$info[$name] = is_float($value) ? $value : microtime(true);

        if ('time' != $value) {
            self::$mem['mem'][$name]  = is_float($value) ? $value : memory_get_usage();
            self::$mem['peak'][$name] = memory_get_peak_usage();
        }
    }

    /* *
 * Get elapsed time between two marks, formatted in seconds
 * @access public
 * @param string $start start tag
 * @param string $end end tag
 * @param integer $dec decimal places
 * @return string */
    public static function getRangeTime($start, $end, $dec = 6)
    {
        if (!isset(self::$info[$end])) {
            self::$info[$end] = microtime(true);
        }

        return number_format((self::$info[$end] - self::$info[$start]), $dec);
    }

    /* *
 * Get elapsed time since framework startup, formatted in seconds
 * @access public
 * @param integer $dec decimal places
 * @return string */
    public static function getUseTime($dec = 6)
    {
        return number_format((microtime(true) - THINK_START_TIME), $dec);
    }

    /* *
 * Estimate request throughput from elapsed time
 * @access public
 * @return string */
    public static function getThroughputRate()
    {
        return number_format(1 / self::getUseTime(), 2) . 'req/s';
    }

    /* *
 * Get the memory-usage difference between two marks, with a unit
 * @access public
 * @param string $start start tag
 * @param string $end end tag
 * @param integer $dec decimal places
 * @return string */
    public static function getRangeMem($start, $end, $dec = 2)
    {
        if (!isset(self::$mem['mem'][$end])) {
            self::$mem['mem'][$end] = memory_get_usage();
        }

        $size = self::$mem['mem'][$end] - self::$mem['mem'][$start];
        $a    = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pos  = 0;

        while ($size >= 1024) {
            $size /= 1024;
            $pos++;
        }

        return round($size, $dec) . " " . $a[$pos];
    }

    /* *
 * Get the memory-usage change since framework startup, with a unit
 * @access public
 * @param integer $dec decimal places
 * @return string */
    public static function getUseMem($dec = 2)
    {
        $size = memory_get_usage() - THINK_START_MEM;
        $a    = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pos  = 0;

        while ($size >= 1024) {
            $size /= 1024;
            $pos++;
        }

        return round($size, $dec) . " " . $a[$pos];
    }

    /* *
 * Get the change in peak memory usage between two marks, with a unit
 * @access public
 * @param string $start start tag
 * @param string $end end tag
 * @param integer $dec decimal places
 * @return string */
    public static function getMemPeak($start, $end, $dec = 2)
    {
        if (!isset(self::$mem['peak'][$end])) {
            self::$mem['peak'][$end] = memory_get_peak_usage();
        }

        $size = self::$mem['peak'][$end] - self::$mem['peak'][$start];
        $a    = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pos  = 0;

        while ($size >= 1024) {
            $size /= 1024;
            $pos++;
        }

        return round($size, $dec) . " " . $a[$pos];
    }

    /* *
 * Get the included-file count or detailed file list
 * @access public
 * @param bool $detail Whether to return file names and sizes instead of a count
 * @return integer|array */
    public static function getFile($detail = false)
    {
        $files = get_included_files();

        if ($detail) {
            $info = [];

            foreach ($files as $file) {
                $info[] = $file . ' ( ' . number_format(filesize($file) / 1024, 2) . ' KB )';
            }

            return $info;
        }

        return count($files);
    }

    /* *
 * Browser-friendly variable output
 * @access public
 * @param mixed $var Value to dump
 * @param boolean $echo Whether to print output; false returns it as a string
 * @param string|null $label Optional output label
 * @param integer $flags Flags for htmlspecialchars
 * @return null|string */
    public static function dump($var, $echo = true, $label = null, $flags = ENT_SUBSTITUTE)
    {
        $label = (null === $label) ? '' : rtrim($label) . ':';

        ob_start();
        var_dump($var);
        $output = preg_replace('/\]\=\>\n(\s+)/m', '] => ', ob_get_clean());

        if (IS_CLI) {
            $output = PHP_EOL . $label . $output . PHP_EOL;
        } else {
            if (!extension_loaded('xdebug')) {
                $output = htmlspecialchars($output, $flags);
            }

            $output = '<pre>' . $label . $output . '</pre>';
        }

        if ($echo) {
            echo($output);
            return;
        }

        return $output;
    }

    /* *
 * Inject trace information into the response body
 * @access public
 * @param Response $response response instance
 * @param string $content Response body to update
 * @return void */
    public static function inject(Response $response, &$content)
    {
        $config = Config::get('trace');
        $type   = isset($config['type']) ? $config['type'] : 'Html';
        $class  = false !== strpos($type, '\\') ? $type : '\\think\\debug\\' . ucwords($type);

        unset($config['type']);

        if (!class_exists($class)) {
            throw new ClassNotFoundException('class not exists:' . $class, $class);
        }

        /* * @var \think\debug\Console|\think\debug\Html $trace */
        $trace = new $class($config);

        if ($response instanceof Redirect) {
            // TODO: Record trace information for redirects
        } else {
            $output = $trace->output($response, Log::getLog());

            if (is_string($output)) {
                // Insert trace output before </body>, or append it when no closing tag exists
                $pos = strripos($content, '</body>');
                if (false !== $pos) {
                    $content = substr($content, 0, $pos) . $output . substr($content, $pos);
                } else {
                    $content = $content . $output;
                }
            }
        }
    }
}
