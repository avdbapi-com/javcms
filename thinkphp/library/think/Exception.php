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

class Exception extends \Exception
{
    /* *
 * @var array Additional debugging data displayed on the exception page */
    protected $data = [];

    /* *
 * Set additional debugging data for an exception
 * The data will be displayed in the following format
 *
 * Exception Data
 * --------------------------------
 * Label 1
 * key1 value1
 * key2 value2
 * Label 2
 * key1 value1
 * key2 value2
 *
 * @access protected
 * @param string $label Section label for data displayed on the exception page
 * @param array $data The data to be displayed must be an associative array
 * @return void */
    final protected function setData($label, array $data)
    {
        $this->data[$label] = $data;
    }

    /* *
 * Get additional debugging data for the exception
 * This data is displayed on exception pages to help with debugging
 * @access public
 * @return array */
    final public function getData()
    {
        return $this->data;
    }

}
