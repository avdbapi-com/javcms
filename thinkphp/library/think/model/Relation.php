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

namespace think\model;

use think\db\Query;
use think\Exception;
use think\Model;

/* *
 * Class Relation
 * @package think\model
 *
 * @mixin Query */
abstract class Relation
{
    // Parent model object
    protected $parent;
    /* * @var Model Related model class */
    protected $model;
    /* * @var Query Related model query object */
    protected $query;
    // Related-table foreign key
    protected $foreignKey;
    // Local relation key
    protected $localKey;
    // Base query initialization flag
    protected $baseQuery;
    // Whether the relation refers to the same model
    protected $selfRelation;

    /* *
 * Get the parent model
 * @access public
 * @return Model */
    public function getParent()
    {
        return $this->parent;
    }

    /* *
 * Get the related model instance
 * @access public
 * @return Model */
    public function getModel()
    {
        return $this->query->getModel();
    }

    /* *
 * Get the related query object
 * @access public
 * @return Query */
    public function getQuery()
    {
        return $this->query;
    }

    /* *
 * Mark the current relation as a self-relation
 * @access public
 * @param bool $self Whether the relation refers to the same model
 * @return $this */
    public function selfRelation($self = true)
    {
        $this->selfRelation = $self;
        return $this;
    }

    /* *
 * Check whether this is a self-relation
 * @access public
 * @return bool */
    public function isSelfRelation()
    {
        return $this->selfRelation;
    }

    /* *
 * Build a collection of related models
 * @access public
 * @param array $resultSet dataset
 * @return mixed */
    protected function resultSetBuild($resultSet)
    {
        return (new $this->model)->toCollection($resultSet);
    }

    protected function getQueryFields($model)
    {
        $fields = $this->query->getOptions('field');
        return $this->getRelationQueryFields($fields, $model);
    }

    protected function getRelationQueryFields($fields, $model)
    {
        if ($fields) {

            if (is_string($fields)) {
                $fields = explode(',', $fields);
            }

            foreach ($fields as &$field) {
                if (false === strpos($field, '.')) {
                    $field = $model . '.' . $field;
                }
            }
        } else {
            $fields = $model . '.*';
        }

        return $fields;
    }

    /* *
 * Apply base query constraints once
 * @access protected
 * @return void */
    protected function baseQuery()
    {}

    public function __call($method, $args)
    {
        if ($this->query) {
            // Apply base query constraints
            $this->baseQuery();

            $result = call_user_func_array([$this->query, $method], $args);
            if ($result instanceof Query) {
                return $this;
            } else {
                $this->baseQuery = false;
                return $result;
            }
        } else {
            throw new Exception('method not exists:' . __CLASS__ . '->' . $method);
        }
    }
}
