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
use think\Loader;
use think\Model;
use think\model\Relation;

class HasMany extends Relation
{
    /* *
 * Constructor
 * @access public
 * @param Model $parent Parent model object
 * @param string $model model name
 * @param string $foreignKey Associated foreign key
 * @param string $localKey Current model primary key */
    public function __construct(Model $parent, $model, $foreignKey, $localKey)
    {
        $this->parent     = $parent;
        $this->model      = $model;
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;
        $this->query      = (new $model)->db();
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
        $list   = $this->relation($subRelation)->select();
        $parent = clone $this->parent;

        foreach ($list as &$model) {
            $model->setParent($parent);
        }

        return $list;
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
        $localKey = $this->localKey;
        $range    = [];
        foreach ($resultSet as $result) {
            // Get a list of associated foreign keys
            if (isset($result->$localKey)) {
                $range[] = $result->$localKey;
            }
        }

        if (!empty($range)) {
            $data = $this->eagerlyOneToMany($this->query, [
                $this->foreignKey => [
                    'in',
                    $range,
                ],
            ], $relation, $subRelation, $closure);
            // Relation attribute name
            $attr = Loader::parseName($relation);
            // Attach related data to each model
            foreach ($resultSet as $result) {
                if (!isset($data[$result->$localKey])) {
                    $data[$result->$localKey] = [];
                }

                foreach ($data[$result->$localKey] as &$relationModel) {
                    $relationModel->setParent(clone $result);
                }

                $result->setRelation($attr, $this->resultSetBuild($data[$result->$localKey]));
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
        $localKey = $this->localKey;

        if (isset($result->$localKey)) {
            $data = $this->eagerlyOneToMany($this->query, [$this->foreignKey => $result->$localKey], $relation, $subRelation, $closure);
            // Attach related data to each model
            if (!isset($data[$result->$localKey])) {
                $data[$result->$localKey] = [];
            }

            foreach ($data[$result->$localKey] as &$relationModel) {
                $relationModel->setParent(clone $result);
            }

            $result->setRelation(Loader::parseName($relation), $this->resultSetBuild($data[$result->$localKey]));
        }
    }

    /* *
 * Count related records
 * @access public
 * @param Model $result Data object
 * @param \Closure $closure Closure
 * @return integer */
    public function relationCount($result, $closure)
    {
        $localKey = $this->localKey;
        $count    = 0;
        if (isset($result->$localKey)) {
            if ($closure) {
                call_user_func_array($closure, [ & $this->query]);
            }
            $count = $this->query->where($this->foreignKey, $result->$localKey)->count();
        }
        return $count;
    }

    /* *
 * Build a related-record count subquery
 * @access public
 * @param \Closure $closure Closure
 * @param string $name Related-record count alias
 * @return string */
    public function getRelationCountQuery($closure, &$name = null)
    {
        if ($closure) {
            $return = call_user_func_array($closure, [ & $this->query]);
            if ($return && is_string($return)) {
                $name = $return;
            }
        }
        $localKey = $this->localKey ?: $this->parent->getPk();
        return $this->query->whereExp($this->foreignKey, '=' . $this->parent->getTable() . '.' . $localKey)->fetchSql()->count();
    }

    /* *
 * Eager-load a one-to-many relation
 * @access public
 * @param object $model Associated model object
 * @param array $where Eager-loading query conditions
 * @param string $relation association name
 * @param string $subRelation Nested relation name
 * @param bool $closure
 * @return array */
    protected function eagerlyOneToMany($model, $where, $relation, $subRelation = '', $closure = false)
    {
        $foreignKey = $this->foreignKey;
        // Eager-loading supports nested relations
        if ($closure) {
            call_user_func_array($closure, [ & $model]);
        }
        $list = $model->removeWhereField($foreignKey)->where($where)->with($subRelation)->select();

        // Group related model data
        $data = [];
        foreach ($list as $set) {
            $data[$set->$foreignKey][] = $set;
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
        $data[$this->foreignKey] = $this->parent->{$this->localKey};

        $model = new $this->model();
        return $model->save($data) ? $model : false;
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
        $data[$this->foreignKey] = $this->parent->{$this->localKey};

        return new $this->model($data);
    }

    /* *
 * Save related models in batches
 * @access public
 * @param array $dataSet
 * @return integer */
    public function saveAll(array $dataSet)
    {
        $result = false;
        foreach ($dataSet as $key => $data) {
            $result = $this->save($data);
        }
        return $result;
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
        $table    = $this->query->getTable();
        $model    = basename(str_replace('\\', '/', get_class($this->parent)));
        $relation = basename(str_replace('\\', '/', $this->model));

        return $this->parent->db()
            ->alias($model)
            ->field($model . '.*')
            ->join([$table => $relation], $model . '.' . $this->localKey . '=' . $relation . '.' . $this->foreignKey, $joinType)
            ->group($relation . '.' . $this->foreignKey)
            ->having('count(' . $id . ')' . $operator . $count);
    }

    /* *
 * Query the current model based on associated conditions
 * @access public
 * @param mixed $where search conditions (array or closure)
 * @param mixed $fields field
 * @return Query */
    public function hasWhere($where = [], $fields = null)
    {
        $table    = $this->query->getTable();
        $model    = basename(str_replace('\\', '/', get_class($this->parent)));
        $relation = basename(str_replace('\\', '/', $this->model));

        if (is_array($where)) {
            foreach ($where as $key => $val) {
                if (false === strpos($key, '.')) {
                    $where[$relation . '.' . $key] = $val;
                    unset($where[$key]);
                }
            }
        }

        $fields = $this->getRelationQueryFields($fields, $model);

        return $this->parent->db()->alias($model)
            ->field($fields)
            ->group($model . '.' . $this->localKey)
            ->join([$table => $relation], $model . '.' . $this->localKey . '=' . $relation . '.' . $this->foreignKey)
            ->where($where);
    }

    /* *
 * Apply base query constraints once
 * @access protected
 * @return void */
    protected function baseQuery()
    {
        if (empty($this->baseQuery)) {
            if (isset($this->parent->{$this->localKey})) {
                // Apply the parent model key to the related query
                $this->query->where($this->foreignKey, $this->parent->{$this->localKey});
            }
            $this->baseQuery = true;
        }
    }

}
