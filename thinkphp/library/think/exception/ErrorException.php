<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: 麦当苗儿 <zuojiazi@vip.qq.com> <http://zjzit.cn>
// +----------------------------------------------------------------------

namespace think\exception;

use think\Exception;

/* *
 * ThinkPHP error exception
 * Wrap errors captured by set_error_handler and register_shutdown_function
 * In addition to the features inherited from think\Exception,
 * behavior is otherwise similar to PHP's built-in \ErrorException. */
class ErrorException extends Exception
{
    /* *
 * Error severity level
 * @var integer */
    protected $severity;

    /* *
 * Construct an error exception
 * @param integer $severity Error severity level
 * @param string $message Error message
 * @param string $file Path to the file in which the error occurred
 * @param integer $line Line on which the error occurred
 * @param array $context Variables in scope when the error occurred */
    public function __construct($severity, $message, $file, $line, array $context = [])
    {
        $this->severity = $severity;
        $this->message  = $message;
        $this->file     = $file;
        $this->line     = $line;
        $this->code     = 0;

        empty($context) || $this->setData('Error Context', $context);
    }

    /* *
 * Get the error severity level
 * @return integer Error severity level */
    final public function getSeverity()
    {
        return $this->severity;
    }
}
