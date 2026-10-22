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

namespace think\model\relation;

use think\db\Query;
use think\Exception;
use think\Loader;
use think\Model;
use think\model\Relation;

class MorphOne extends Relation
{
    // polymorphic field
    protected $morphKey;
    protected $morphType;
    // polymorphic types
    protected $type;

    /* *
 * Constructor
 * @access public
 * @param Model $parent Parent model object
 * @param string $model model name
 * @param string $morphKey Associated foreign key
 * @param string $morphType polymorphic field name
 * @param string $type polymorphic type */
    public function __construct(Model $parent, $model, $morphKey, $morphType, $type)
    {
        $this->parent    = $parent;
        $this->model     = $model;
        $this->type      = $type;
        $this->morphKey  = $morphKey;
        $this->morphType = $morphType;
        $this->query     = (new $model)->db();
    }

    /* *
 * Lazy-load related data
 * @param string $subRelation Nested relation name
 * @param \Closure $closure Query closure
 * @return false|\PDOStatement|string|\think\Collection */
    public function getRelation($subRelation = '', $closure = null)
    {
        if ($closure) {
            call_user_func_array($closure, [ & $this->query]);
        }
        $relationModel = $this->relation($subRelation)->find();

        if ($relationModel) {
            $relationModel->setParent(clone $this->parent);
        }

        return $relationModel;
    }

    /* *
 * Query the current model based on associated conditions
 * @access public
 * @param string $operator comparison operator
 * @param integer $count number
 * @param string $id Related-table field to count
 * @param string $joinType JOIN type
 * @return Query */
    public function has($operator = '>=', $count = 1, $id = '*', $joinType = 'INNER')
    {
        return $this->parent;
    }

    /* *
 * Query the current model based on associated conditions
 * @access public
 * @param mixed $where search conditions (array or closure)
 * @param mixed $fields field
 * @return Query */
    public function hasWhere($where = [], $fields = null)
    {
        throw new Exception('relation not support: hasWhere');
    }

    /* *
 * Eager-load relations for a result set
 * @access public
 * @param array $resultSet Result set
 * @param string $relation Current relation name
 * @param string $subRelation
 * @param \Closure $closure
 * @return void */
    public function eagerlyResultSet(&$resultSet, $relation, $subRelation, $closure)
    {
        $morphType = $this->morphType;
        $morphKey  = $this->morphKey;
        $type      = $this->type;
        $range     = [];
        foreach ($resultSet as $result) {
            $pk = $result->getPk();
            // Get a list of associated foreign keys
            if (isset($result->$pk)) {
                $range[] = $result->$pk;
            }
        }

        if (!empty($range)) {
            $data = $this->eagerlyMorphToOne([
                $morphKey  => ['in', $range],
                $morphType => $type,
            ], $relation, $subRelation, $closure);
            // Relation attribute name
            $attr = Loader::parseName($relation);
            // Attach related data to each model
            foreach ($resultSet as $result) {
                if (!isset($data[$result->$pk])) {
                    $relationModel = null;
                } else {
                    $relationModel = $data[$result->$pk];
                    $relationModel->setParent(clone $result);
                    $relationModel->isUpdate(true);
                }

                $result->setRelation($attr, $relationModel);
            }
        }
    }

    /* *
 * Eager-load relations for a single model
 * @access public
 * @param Model $result data object
 * @param string $relation Current relation name
 * @param string $subRelation Nested relation name
 * @param \Closure $closure closure
 * @return void */
    public function eagerlyResult(&$result, $relation, $subRelation, $closure)
    {
        $pk = $result->getPk();
        if (isset($result->$pk)) {
            $pk   = $result->$pk;
            $data = $this->eagerlyMorphToOne([
                $this->morphKey  => $pk,
                $this->morphType => $this->type,
            ], $relation, $subRelation, $closure);

            if (isset($data[$pk])) {
                $relationModel = $data[$pk];
                $relationModel->setParent(clone $result);
                $relationModel->isUpdate(true);
            } else {
                $relationModel = null;
            }

            $result->setRelation(Loader::parseName($relation), $relationModel);
        }
    }

    /* *
 * Eager-load a polymorphic one-to-one relation
 * @access public
 * @param array $where Eager-loading query conditions
 * @param string $relation association name
 * @param string $subRelation Nested relation name
 * @param bool|\Closure $closure
 * @return array */
    protected function eagerlyMorphToOne($where, $relation, $subRelation = '', $closure = false)
    {
        // Eager-loading supports nested relations
        if ($closure) {
            call_user_func_array($closure, [ & $this]);
        }
        $list     = $this->query->where($where)->with($subRelation)->find();
        $morphKey = $this->morphKey;
        // Group related model data
        $data = [];
        foreach ($list as $set) {
            $data[$set->$morphKey][] = $set;
        }
        return $data;
    }

    /* *
 * Save a related model
 * @access public
 * @param mixed $data Related model data as an array or model instance
 * @return Model|false */
    public function save($data)
    {
        if ($data instanceof Model) {
            $data = $data->getData();
        }

        // Save related table data
        $pk = $this->parent->getPk();

        $data[$this->morphKey]  = $this->parent->$pk;
        $data[$this->morphType] = $this->type;

        $model = new $this->model();

        return $model->save() ? $model : false;
    }

    /* *
 * Create a related model without saving it
 * @param array $data
 * @return Model */
    public function make($data = [])
    {
        if ($data instanceof Model) {
            $data = $data->getData();
        }
        // Save related table data
        $pk = $this->parent->getPk();

        $data[$this->morphKey]  = $this->parent->$pk;
        $data[$this->morphType] = $this->type;

        return new $this->model($data);
    }

    /* *
 * Apply base query constraints once
 * @access protected
 * @return void */
    protected function baseQuery()
    {
        if (empty($this->baseQuery) && $this->parent->getData()) {
            $pk                    = $this->parent->getPk();
            $map[$this->morphKey]  = $this->parent->$pk;
            $map[$this->morphType] = $this->type;
            $this->query->where($map);
            $this->baseQuery = true;
        }
    }

    /* *
 * Build a related-record count subquery
 * @access public
 * @param \Closure $closure Closure
 * @param string $name Related-record count alias
 * @return string */
    public function getRelationCountQuery($closure, &$name = null)
    {
        throw new Exception('relation not support: withCount');
    }
}
