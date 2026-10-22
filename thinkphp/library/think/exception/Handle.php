<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\exception;

use Exception;
use think\App;
use think\Config;
use think\console\Output;
use think\Lang;
use think\Log;
use think\Response;

class Handle
{
    protected $render;
    protected $ignoreReport = [
        '\\think\\exception\\HttpException',
    ];

    public function setRender($render)
    {
        $this->render = $render;
    }

    /* *
 * Report or log an exception.
 *
 * @param \\Exception $exception
 * @return void */
    public function report(Exception $exception)
    {
        if (!$this->isIgnoreReport($exception)) {
            // Collect exception details
            if (App::$debug) {
                $data = [
                    'file'    => $exception->getFile(),
                    'line'    => $exception->getLine(),
                    'message' => $this->getMessage($exception),
                    'code'    => $this->getCode($exception),
                ];
                $log = "[{$data['code']}]{$data['message']}[{$data['file']}:{$data['line']}]";
            } else {
                $data = [
                    'code'    => $this->getCode($exception),
                    'message' => $this->getMessage($exception),
                ];
                $log = "[{$data['code']}]{$data['message']}";
            }

            if (Config::get('record_trace')) {
                $log .= "\r\n" . $exception->getTraceAsString();
            }

            Log::record($log, 'error');
        }
    }

    protected function isIgnoreReport(Exception $exception)
    {
        foreach ($this->ignoreReport as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }
        return false;
    }

    /* *
 * Render an exception into an HTTP response.
 *
 * @param \\Exception $e
 * @return Response */
    public function render(Exception $e)
    {
        if ($this->render && $this->render instanceof \Closure) {
            $result = call_user_func_array($this->render, [$e]);
            if ($result) {
                return $result;
            }
        }

        if ($e instanceof HttpException) {
            return $this->renderHttpException($e);
        } else {
            return $this->convertExceptionToResponse($e);
        }
    }

    /* *
 * @param Output $output
 * @param Exception $e */
    public function renderForConsole(Output $output, Exception $e)
    {
        if (App::$debug) {
            $output->setVerbosity(Output::VERBOSITY_DEBUG);
        }
        $output->renderException($e);
    }

    /* *
 * @param HttpException $e
 * @return Response */
    protected function renderHttpException(HttpException $e)
    {
        $status   = $e->getStatusCode();
        $template = Config::get('http_exception_template');
        if (!App::$debug && !empty($template[$status])) {
            return Response::create($template[$status], 'view', $status)->assign(['e' => $e]);
        } else {
            return $this->convertExceptionToResponse($e);
        }
    }

    /* *
 * @param Exception $exception
 * @return Response */
    protected function convertExceptionToResponse(Exception $exception)
    {
        // Collect exception details
        if (App::$debug) {
            // Include detailed error information in debug mode
            $data = [
                'name'    => get_class($exception),
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
                'message' => $this->getMessage($exception),
                'trace'   => $exception->getTrace(),
                'code'    => $this->getCode($exception),
                'source'  => $this->getSourceCode($exception),
                'datas'   => $this->getExtendData($exception),
                'tables'  => [
                    'GET Data'              => $_GET,
                    'POST Data'             => $_POST,
                    'Files'                 => $_FILES,
                    'Cookies'               => $_COOKIE,
                    'Session'               => isset($_SESSION) ? $_SESSION : [],
                    'Server/Request Data'   => $_SERVER,
                    'Environment Variables' => $_ENV,
                    'ThinkPHP Constants'    => $this->getConst(),
                ],
            ];
        } else {
            // Outside debug mode, include only the error code and message
            $data = [
                'code'    => $this->getCode($exception),
                'message' => $this->getMessage($exception),
            ];

            if (!Config::get('show_error_msg')) {
                // Detailed error information is not displayed
                $data['message'] = Config::get('error_message');
            }
        }

        // Leave one output buffer open
        while (ob_get_level() > 1) {
            ob_end_clean();
        }

        $data['echo'] = ob_get_clean();

        ob_start();
        extract($data);
        include Config::get('exception_tmpl');
        // Retrieve the rendered content and close the output buffer
        $content  = ob_get_clean();
        $response = new Response($content, 'html');

        if ($exception instanceof HttpException) {
            $statusCode = $exception->getStatusCode();
            $response->header($exception->getHeaders());
        }

        if (!isset($statusCode)) {
            $statusCode = 500;
        }
        $response->code($statusCode);
        return $response;
    }

    /* *
 * Get the error code
 * ErrorException uses its severity level when its error code is zero
 * @param \Exception $exception
 * @return integer error code */
    protected function getCode(Exception $exception)
    {
        $code = $exception->getCode();
        if (!$code && $exception instanceof ErrorException) {
            $code = $exception->getSeverity();
        }
        return $code;
    }

    /* *
 * Get the error message
 * Translate recognized message keys when rendering outside the CLI
 * @param \Exception $exception
 * @return string Error message */
    protected function getMessage(Exception $exception)
    {
        $message = $exception->getMessage();
        if (IS_CLI) {
            return $message;
        }

        if (strpos($message, ':')) {
            $name    = strstr($message, ':', true);
            $message = Lang::has($name) ? Lang::get($name) . strstr($message, ':') : $message;
        } elseif (strpos($message, ',')) {
            $name    = strstr($message, ',', true);
            $message = Lang::has($name) ? Lang::get($name) . ':' . substr(strstr($message, ','), 1) : $message;
        } elseif (Lang::has($message)) {
            $message = Lang::get($message);
        }
        return $message;
    }

    /* *
 * Get a source code excerpt around the error
 * Include up to nine lines before and after the error line
 * @param \Exception $exception
 * @return array Error file contents */
    protected function getSourceCode(Exception $exception)
    {
        // Read an excerpt starting up to nine lines before the error
        $line  = $exception->getLine();
        $first = ($line - 9 > 0) ? $line - 9 : 1;

        try {
            $contents = file($exception->getFile());
            $source   = [
                'first'  => $first,
                'source' => array_slice($contents, $first - 1, 19),
            ];
        } catch (Exception $e) {
            $source = [];
        }
        return $source;
    }

    /* *
 * Get additional exception data
 * Supply diagnostic data for HTML rendering in debug mode
 * @param \Exception $exception
 * @return array Additional data attached to the exception */
    protected function getExtendData(Exception $exception)
    {
        $data = [];
        if ($exception instanceof \think\Exception) {
            $data = $exception->getData();
        }
        return $data;
    }

    /* *
 * Get a list of constants
 * @return array list of constants */
    private static function getConst()
    {
        return get_defined_constants(true)['user'];
    }
}
