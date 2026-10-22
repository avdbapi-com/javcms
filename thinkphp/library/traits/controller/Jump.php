<?php
/* *
 * usage:
 * load_trait(\'controller/Jump\');
 * class index
 * {
 * use \\traits\\controller\\Jump;
 * public function index(){
 * $this->error();
 * $this->redirect();
 * }
 * } */
namespace traits\controller;

use think\Config;
use think\exception\HttpResponseException;
use think\Request;
use think\Response;
use think\response\Redirect;
use think\Url;
use think\View as ViewTemplate;

trait Jump
{
    /* *
 * Create a success response with redirect details
 * @access protected
 * @param mixed $msg Response message
 * @param string|null $url Redirect URL
 * @param mixed $data Response data
 * @param int $wait Delay before redirecting, in seconds
 * @param array $header Response headers
 * @return void
 * @throws HttpResponseException */
    protected function success($msg = '', $url = null, $data = '', $wait = 3, array $header = [])
    {
        if (is_null($url) && !is_null(Request::instance()->server('HTTP_REFERER'))) {
            $url = Request::instance()->server('HTTP_REFERER');
        } elseif ('' !== $url && !strpos($url, '://') && 0 !== strpos($url, '/')) {
            $url = Url::build($url);
        }

        $type = $this->getResponseType();
        $result = [
            'code' => 1,
            'msg'  => $msg,
            'data' => $data,
            'url'  => $url,
            'wait' => $wait,
        ];

        if ('html' == strtolower($type)) {
            $template = Config::get('template');
            $view = Config::get('view_replace_str');

            $result = ViewTemplate::instance($template, $view)
                ->fetch(Config::get('dispatch_success_tmpl'), $result);
        }

        $response = Response::create($result, $type)->header($header);

        throw new HttpResponseException($response);
    }

    /* *
 * Create an error response with redirect details
 * @access protected
 * @param mixed $msg Response message
 * @param string|null $url Redirect URL
 * @param mixed $data Response data
 * @param int $wait Delay before redirecting, in seconds
 * @param array $header Response headers
 * @return void
 * @throws HttpResponseException */
    protected function error($msg = '', $url = null, $data = '', $wait = 3, array $header = [])
    {
        if (is_null($url)) {
            $url = Request::instance()->isAjax() ? '' : 'javascript:history.back(-1);';
        } elseif ('' !== $url && !strpos($url, '://') && 0 !== strpos($url, '/')) {
            $url = Url::build($url);
        }

        $type = $this->getResponseType();
        $result = [
            'code' => 0,
            'msg'  => $msg,
            'data' => $data,
            'url'  => $url,
            'wait' => $wait,
        ];

        if ('html' == strtolower($type)) {
            $template = Config::get('template');
            $view = Config::get('view_replace_str');

            $result = ViewTemplate::instance($template, $view)
                ->fetch(Config::get('dispatch_error_tmpl'), $result);
        }

        $response = Response::create($result, $type)->header($header);

        throw new HttpResponseException($response);
    }

    /* *
 * Create an API response containing the supplied data
 * @access protected
 * @param mixed $data The data to be returned
 * @param int $code Response status value
 * @param mixed $msg Response message
 * @param string $type Response data format
 * @param array $header Response headers
 * @return void
 * @throws HttpResponseException */
    protected function result($data, $code = 0, $msg = '', $type = '', array $header = [])
    {
        $result = [
            'code' => $code,
            'msg'  => $msg,
            'time' => Request::instance()->server('REQUEST_TIME'),
            'data' => $data,
        ];
        $type     = $type ?: $this->getResponseType();
        $response = Response::create($result, $type)->header($header);

        throw new HttpResponseException($response);
    }

    /* *
 * URL redirection
 * @access protected
 * @param string $url Redirect URL or URL expression
 * @param array|int $params URL parameters, or an HTTP status code
 * @param int $code HTTP status code
 * @param array $with Data to persist for the redirected request
 * @return void
 * @throws HttpResponseException */
    protected function redirect($url, $params = [], $code = 302, $with = [])
    {
        if (is_integer($params)) {
            $code   = $params;
            $params = [];
        }

        $response = new Redirect($url);
        $response->code($code)->params($params)->with($with);

        throw new HttpResponseException($response);
    }

    /* *
 * Get the current response output type
 * @access protected
 * @return string */
    protected function getResponseType()
    {
        return Request::instance()->isAjax()
            ? Config::get('default_ajax_return')
            : Config::get('default_return_type');
    }
}
