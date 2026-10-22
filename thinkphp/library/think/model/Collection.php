<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: zhangyajun <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\model;

use think\Collection as BaseCollection;
use think\Model;

class Collection extends BaseCollection
{
    /* *
 * Eager-load relations after retrieving the collection
 * @access public
 * @param mixed $relation Relations to load
 * @return $this */
    public function load($relation)
    {
        $item = current($this->items);
        $item->eagerlyResultSet($this->items, $relation);
        return $this;
    }

    /* *
 * Set attributes to hide from output
 * @access public
 * @param array $hidden list of attributes
 * @param bool $override Whether to replace the existing attribute list
 * @return $this */
    public function hidden($hidden = [], $override = false)
    {
        $this->each(function ($model) use ($hidden, $override) {
            /* * @var Model $model */
            $model->hidden($hidden, $override);
        });
        return $this;
    }

    /* *
 * Set attributes to include in output
 * @param array $visible
 * @param bool $override Whether to replace the existing attribute list
 * @return $this */
    public function visible($visible = [], $override = false)
    {
        $this->each(function ($model) use ($visible, $override) {
            /* * @var Model $model */
            $model->visible($visible, $override);
        });
        return $this;
    }

    /* *
 * Append attributes to output
 * @access public
 * @param array $append attribute list
 * @param bool $override Whether to replace the existing attribute list
 * @return $this */
    public function append($append = [], $override = false)
    {
        $this->each(function ($model) use ($append, $override) {
            /* * @var Model $model */
            $model && $model->append($append, $override);
        });
        return $this;
    }

}
