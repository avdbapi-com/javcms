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

namespace think\response;

use think\Request;
use think\Response;
use think\Session;
use think\Url;

class Redirect extends Response
{

    protected $options = [];

    // URL parameters
    protected $params = [];

    public function __construct($data = '', $code = 302, array $header = [], array $options = [])
    {
        parent::__construct($data, $code, $header, $options);
        $this->cacheControl('no-cache,must-revalidate');
    }

    /* *
 * Set the Location header to the redirect target
 * @access protected
 * @param mixed $data Unused response data
 * @return void */
    protected function output($data)
    {
        $this->header['Location'] = $this->getTargetUrl();
        return;
    }

    /* *
 * Store flash session values for the redirect
 * @access public
 * @param string|array $name Variable name or array
 * @param mixed $value Value to store
 * @return $this */
    public function with($name, $value = null)
    {
        if (is_array($name)) {
            foreach ($name as $key => $val) {
                Session::flash($key, $val);
            }
        } else {
            Session::flash($name, $value);
        }
        return $this;
    }

    /* *
 * Get the redirect target URL
 * @return string */
    public function getTargetUrl()
    {
        if (strpos($this->data, '://') || (0 === strpos($this->data, '/') && empty($this->params))) {
            return $this->data;
        } else {
            return Url::build($this->data, $this->params);
        }
    }

    public function params($params = [])
    {
        $this->params = $params;
        return $this;
    }

    /* *
 * Store the current URL in the session for later restoration
 * @return $this */
    public function remember()
    {
        Session::set('redirect_url', Request::instance()->url());
        return $this;
    }

    /* *
 * Restore the saved URL as the redirect target and remove it from the session
 * @return $this */
    public function restore()
    {
        if (Session::has('redirect_url')) {
            $this->data = Session::get('redirect_url');
            Session::delete('redirect_url');
        }
        return $this;
    }
}
