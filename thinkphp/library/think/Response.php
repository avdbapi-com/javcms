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

use think\response\Json as JsonResponse;
use think\response\Jsonp as JsonpResponse;
use think\response\Redirect as RedirectResponse;
use think\response\View as ViewResponse;
use think\response\Xml as XmlResponse;

class Response
{
    // raw data
    protected $data;

    // Default content type
    protected $contentType = 'text/html';

    // character set
    protected $charset = 'utf-8';

    // HTTP status code
    protected $code = 200;

    // output parameters
    protected $options = [];
    // Response headers
    protected $header = [];

    protected $content = null;

    /* *
 * Constructor
 * @access public
 * @param mixed $data output data
 * @param int $code
 * @param array $header
 * @param array $options Output parameters */
    public function __construct($data = '', $code = 200, array $header = [], $options = [])
    {
        $this->data($data);
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        $this->contentType($this->contentType, $this->charset);
        $this->header = array_merge($this->header, $header);
        $this->code   = $code;
    }

    /* *
 * Create a response object
 * @access public
 * @param mixed $data output data
 * @param string $type Output type
 * @param int $code
 * @param array $header
 * @param array $options Output parameters
 * @return Response|JsonResponse|ViewResponse|XmlResponse|RedirectResponse|JsonpResponse */
    public static function create($data = '', $type = '', $code = 200, array $header = [], $options = [])
    {
        $class = false !== strpos($type, '\\') ? $type : '\\think\\response\\' . ucfirst(strtolower($type));
        if (class_exists($class)) {
            $response = new $class($data, $code, $header, $options);
        } else {
            $response = new static($data, $code, $header, $options);
        }

        return $response;
    }

    /* *
 * Send the response
 * @access public
 * @return mixed
 * @throws\\ InvalidArgumentException */
    public function send()
    {
        // Listen to response_send
        Hook::listen('response_send', $this);

        // Prepare the response body
        $data = $this->getContent();

        // Inject trace output when enabled
        if (Env::get('app_trace', Config::get('app_trace'))) {
            Debug::inject($this, $data);
        }

        if (200 == $this->code) {
            $cache = Request::instance()->getCache();
            if ($cache) {
                $this->header['Cache-Control'] = 'max-age=' . $cache[1] . ',must-revalidate';
                $this->header['Last-Modified'] = gmdate('D, d M Y H:i:s') . ' GMT';
                $this->header['Expires']       = gmdate('D, d M Y H:i:s', $_SERVER['REQUEST_TIME'] + $cache[1]) . ' GMT';
                Cache::tag($cache[2])->set($cache[0], [$data, $this->header], $cache[1]);
            }
        }

        if (!headers_sent() && !empty($this->header)) {
            // Send status code
            http_response_code($this->code);
            // Send response headers
            foreach ($this->header as $name => $val) {
                if (is_null($val)) {
                    header($name);
                } else {
                    header($name . ':' . $val);
                }
            }
        }

        echo $data;

        if (function_exists('fastcgi_finish_request')) {
            // Finish sending the response before continuing processing
            fastcgi_finish_request();
        }

        // Invoke the response_end hook
        Hook::listen('response_end', $this);

        // Flush session data for non-redirect responses
        if (!($this instanceof RedirectResponse)) {
            Session::flush();
        }
    }

    /* *
 * Prepare output data
 * @access protected
 * @param mixed $data The data to be processed
 * @return mixed */
    protected function output($data)
    {
        return $data;
    }

    /* *
 * Set output options
 * @access public
 * @param mixed $options Output parameters
 * @return $this */
    public function options($options = [])
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /* *
 * Set response data
 * @access public
 * @param mixed $data output data
 * @return $this */
    public function data($data)
    {
        $this->data = $data;
        return $this;
    }

    /* *
 * Set response headers
 * @access public
 * @param string|array $name Header name or array of headers
 * @param string $value Header value
 * @return $this */
    public function header($name, $value = null)
    {
        if (is_array($name)) {
            $this->header = array_merge($this->header, $name);
        } else {
            $this->header[$name] = $value;
        }
        return $this;
    }

    /* *
 * Set the response body
 * @param $content
 * @return $this */
    public function content($content)
    {
        if (null !== $content && !is_string($content) && !is_numeric($content) && !is_callable([
            $content,
            '__toString',
        ])
        ) {
            throw new \InvalidArgumentException(sprintf('variable type error: %s', gettype($content)));
        }

        $this->content = (string) $content;

        return $this;
    }

    /* *
 * Set the HTTP status code
 * @param integer $code status code
 * @return $this */
    public function code($code)
    {
        $this->code = $code;
        return $this;
    }

    /* *
 * Set the Last-Modified header
 * @param string $time
 * @return $this */
    public function lastModified($time)
    {
        $this->header['Last-Modified'] = $time;
        return $this;
    }

    /* *
 * Set the Expires header
 * @param string $time
 * @return $this */
    public function expires($time)
    {
        $this->header['Expires'] = $time;
        return $this;
    }

    /* *
 * Set the ETag header
 * @param string $eTag
 * @return $this */
    public function eTag($eTag)
    {
        $this->header['ETag'] = $eTag;
        return $this;
    }

    /* *
 * Set cache-control directives
 * @param string $cache Cache-control directives
 * @return $this */
    public function cacheControl($cache)
    {
        $this->header['Cache-control'] = $cache;
        return $this;
    }

    /* *
 * Set the response content type and charset
 * @param string $contentType output type
 * @param string $charset output encoding
 * @return $this */
    public function contentType($contentType, $charset = 'utf-8')
    {
        $this->header['Content-Type'] = $contentType . '; charset=' . $charset;
        return $this;
    }

    /* *
 * Get response headers
 * @param string $name header name
 * @return mixed */
    public function getHeader($name = '')
    {
        if (!empty($name)) {
            return isset($this->header[$name]) ? $this->header[$name] : null;
        } else {
            return $this->header;
        }
    }

    /* *
 * Get raw data
 * @return mixed */
    public function getData()
    {
        return $this->data;
    }

    /* *
 * Get the response body
 * @return mixed */
    public function getContent()
    {
        if (null == $this->content) {
            $content = $this->output($this->data);

            if (null !== $content && !is_string($content) && !is_numeric($content) && !is_callable([
                $content,
                '__toString',
            ])
            ) {
                throw new \InvalidArgumentException(sprintf('variable type error: %s', gettype($content)));
            }

            $this->content = (string) $content;
        }
        return $this->content;
    }

    /* *
 * Get the HTTP status code
 * @return integer */
    public function getCode()
    {
        return $this->code;
    }
}
