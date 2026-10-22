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

namespace think;

use think\console\Output as ConsoleOutput;
use think\exception\ErrorException;
use think\exception\Handle;
use think\exception\ThrowableError;

class Error
{
    /* *
 * Registration exception handling
 * @access public
 * @return void */
    public static function register()
    {
        error_reporting(E_ALL);
        set_error_handler([__CLASS__, 'appError']);
        set_exception_handler([__CLASS__, 'appException']);
        register_shutdown_function([__CLASS__, 'appShutdown']);
    }

    /* *
 * Exception handling
 * @access public
 * @param \\Exception|\\Throwable $e abnormal
 * @return void */
    public static function appException($e)
    {
        if (!$e instanceof \Exception) {
            $e = new ThrowableError($e);
        }

        $handler = self::getExceptionHandler();
        $handler->report($e);

        if (IS_CLI) {
            $handler->renderForConsole(new ConsoleOutput, $e);
        } else {
            $handler->render($e)->send();
        }
    }

    /* *
 * Handyman
 * @access pubblica
 * @param integer $errno
 * @param integer $errstr Detail Information
 * @param string $errfile Download File
 * @param integer $errline output value
 * @return void
 * @throws ErrorException */
    public static function appError($errno, $errstr, $errfile = '', $errline = 0)
    {
        $exception = new ErrorException($errno, $errstr, $errfile, $errline);

        // Those who meet the abnormal processing will host the error information to think\\exception\\ErrorException
        if (error_reporting() & $errno) {
            throw $exception;
        }

        self::getExceptionHandler()->report($exception);
    }

    /* *
 * Abnormal suspension processing
 * @access public
 * @return void */
    public static function appShutdown()
    {
        // Host error messages to think\\ ErrorException
        if (!is_null($error = error_get_last()) && self::isFatal($error['type'])) {
            self::appException(new ErrorException(
                $error['type'], $error['message'], $error['file'], $error['line']
            ));
        }

        // Write to log
        Log::save();
    }

    /* *
 * Determine if the type of error is fatal
 * @access protected
 * @param int $type error type
 * @return bool */
    protected static function isFatal($type)
    {
        return in_array($type, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE]);
    }

    /* *
 * Get an instance of exception handling
 * @access public
 * @return Handle */
    public static function getExceptionHandler()
    {
        static $handle;

        if (!$handle) {
            // Exception handling handle
            $class = Config::get('exception_handle');

            if ($class && is_string($class) && class_exists($class) &&
                is_subclass_of($class, "\\think\\exception\\Handle")
            ) {
                $handle = new $class;
            } else {
                $handle = new Handle;

                if ($class instanceof \Closure) {
                    $handle->setRender($class);
                }

            }
        }

        return $handle;
    }
}
