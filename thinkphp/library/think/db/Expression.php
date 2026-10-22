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

namespace think\db;

class Expression
{
    /* *
 * Raw SQL expression value
 *
 * @var string */
    protected $value;

    /* *
 * Create a raw SQL expression
 *
 * @param string $value
 * @return void */
    public function __construct($value)
    {
        $this->value = $value;
    }

    /* *
 * Get the raw SQL expression value
 *
 * @return string */
    public function getValue()
    {
        return $this->value;
    }

    public function __toString()
    {
        return (string) $this->value;
    }
}
