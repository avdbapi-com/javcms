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

use think\Collection;
use think\Db;
use think\db\Query;
use think\Exception;
use think\Loader;
use think\Model;
use think\model\Pivot;
use think\model\Relation;
use think\Paginator;

class BelongsToMany extends Relation
{
    // Pivot table name
    protected $middle;
    // Pivot model class name
    protected $pivotName;
    // Pivot model object
    protected $pivot;
    // Pivot relation attribute name
    protected $pivotDataName = 'pivot';

    /* *
 * Constructor
 * @access public
 * @param Model $parent Parent model object
 * @param string $model model name
 * @param string $table middle table name
 * @param string $foreignKey Associated model foreign key
 * @param string $localKey Current model associated key */
    public function __construct(Model $parent, $model, $table, $foreignKey, $localKey)
    {
        $this->parent     = $parent;
        $this->model      = $model;
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;
        if (false !== strpos($table, '\\')) {
            $this->pivotName = $table;
            $this->middle    = basename(str_replace('\\', '/', $table));
        } else {
            $this->middle = $table;
        }
        $this->query = (new $model)->db();
        $this->pivot = $this->newPivot();

        if ('think\model\Pivot' == get_class($this->pivot)) {
            $this->pivot->name($this->middle);
        }
    }

    /* *
 * Set the pivot model class
 * @param $pivot
 * @return $this */
    public function pivot($pivot)
    {
        $this->pivotName = $pivot;
        return $this;
    }

    /* *
 * Set the pivot relation attribute name
 * @access public
 * @param string $name
 * @return $this */
    public function pivotDataName($name)
    {
        $this->pivotDataName = $name;
        return $this;
    }

    /* *
 * Get the pivot row update conditions
 * @param $data
 * @return array */
    protected function getUpdateWhere($data)
    {
        return [
            $this->localKey   => $data[$this->localKey],
            $this->foreignKey => $data[$this->foreignKey],
        ];
    }

    /* *
 * Create a pivot model instance
 * @param array $data
 * @param bool $isUpdate
 * @return Pivot
 * @throws Exception */
    protected function newPivot($data = [], $isUpdate = false)
    {
        $class = $this->pivotName ?: '\\think\\model\\Pivot';
        $pivot = new $class($data, $this->parent, $this->middle);
        if ($pivot instanceof Pivot) {
            return $isUpdate ? $pivot->isUpdate(true, $this->getUpdateWhere($data)) : $pivot;
        } else {
            throw new Exception('pivot model must extends: \think\model\Pivot');
        }
    }

    /* *
 * A
 * @param array|Collection|Paginator $models */
    protected function hydratePivot($models)
    {
        foreach ($models as $model) {
            $pivot = [];
            foreach ($model->getData() as $key => $val) {
                if (strpos($key, '__')) {
                    list($name, $attr) = explode('__', $key, 2);
                    if ('pivot' == $name) {
                        $pivot[$attr] = $val;
                        unset($model->$key);
                    }
                }
            }
            $model->setRelation($this->pivotDataName, $this->newPivot($pivot, true));
        }
    }

    /* *
 * Build the query for the parent model relation
 * @return Query */
    protected function buildQuery()
    {
        $foreignKey = $this->foreignKey;
        $localKey   = $this->localKey;
        $pk         = $this->parent->getPk();
        // Related query constraints
        $condition['pivot.' . $localKey] = $this->parent->$pk;
        return $this->belongsToManyQuery($foreignKey, $localKey, $condition);
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
        $result = $this->buildQuery()->relation($subRelation)->select();
        $this->hydratePivot($result);
        return $result;
    }

    /* *
 * Select related records
 * @param null $data
 * @return false|\PDOStatement|string|Collection */
    public function select($data = null)
    {
        $result = $this->buildQuery()->select($data);
        $this->hydratePivot($result);
        return $result;
    }

    /* *
 * Paginate related records
 * @param null $listRows
 * @param bool $simple
 * @param array $config
 * @return Paginator */
    public function paginate($listRows = null, $simple = false, $config = [])
    {
        $result = $this->buildQuery()->paginate($listRows, $simple, $config);
        $this->hydratePivot($result);
        return $result;
    }

    /* *
 * Find a related record
 * @param null $data
 * @return array|false|\PDOStatement|string|Model */
    public function find($data = null)
    {
        $result = $this->buildQuery()->find($data);
        if ($result) {
            $this->hydratePivot([$result]);
        }
        return $result;
    }

    /* *
 * Find related records or throw if none exist
 * @access public
 * @param array|string|Query|\Closure $data
 * @return array|\PDOStatement|string|Model */
    public function selectOrFail($data = null)
    {
        return $this->failException(true)->select($data);
    }

    /* *
 * Find a related record or throw if it does not exist
 * @access public
 * @param array|string|Query|\Closure $data
 * @return array|\PDOStatement|string|Model */
    public function findOrFail($data = null)
    {
        return $this->failException(true)->find($data);
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
 * @return Query
 * @throws Exception */
    public function hasWhere($where = [], $fields = null)
    {
        throw new Exception('relation not support: hasWhere');
    }

    /* *
 * Set the query conditions of the middle table
 * @param $field
 * @param null $op
 * @param null $condition
 * @return $this */
    public function wherePivot($field, $op = null, $condition = null)
    {
        $field = 'pivot.' . $field;
        $this->query->where($field, $op, $condition);
        return $this;
    }

    /* *
 * Eager-load relations for a result set
 * @access public
 * @param array $resultSet dataset
 * @param string $relation Current relation name
 * @param string $subRelation Nested relation name
 * @param \Closure $closure closure
 * @return void */
    public function eagerlyResultSet(&$resultSet, $relation, $subRelation, $closure)
    {
        $localKey   = $this->localKey;
        $foreignKey = $this->foreignKey;

        $pk    = $resultSet[0]->getPk();
        $range = [];
        foreach ($resultSet as $result) {
            // Get a list of associated foreign keys
            if (isset($result->$pk)) {
                $range[] = $result->$pk;
            }
        }

        if (!empty($range)) {
            // Query related data
            $data = $this->eagerlyManyToMany([
                'pivot.' . $localKey => [
                    'in',
                    $range,
                ],
            ], $relation, $subRelation);
            // Relation attribute name
            $attr = Loader::parseName($relation);
            // Attach related data to each model
            foreach ($resultSet as $result) {
                if (!isset($data[$result->$pk])) {
                    $data[$result->$pk] = [];
                }

                $result->setRelation($attr, $this->resultSetBuild($data[$result->$pk]));
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
            $pk = $result->$pk;
            // Query related data
            $data = $this->eagerlyManyToMany(['pivot.' . $this->localKey => $pk], $relation, $subRelation);

            // Attach related data to each model
            if (!isset($data[$pk])) {
                $data[$pk] = [];
            }
            $result->setRelation(Loader::parseName($relation), $this->resultSetBuild($data[$pk]));
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
        $pk    = $result->getPk();
        $count = 0;
        if (isset($result->$pk)) {
            $pk    = $result->$pk;
            $count = $this->belongsToManyQuery($this->foreignKey, $this->localKey, ['pivot.' . $this->localKey => $pk])->count();
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

        return $this->belongsToManyQuery($this->foreignKey, $this->localKey, [
            'pivot.' . $this->localKey => [
                'exp',
                Db::raw('=' . $this->parent->getTable() . '.' . $this->parent->getPk()),
            ],
        ])->fetchSql()->count();
    }

    /* *
 * Eager-load a many-to-many relation
 * @access public
 * @param array $where Eager-loading query conditions
 * @param string $relation association name
 * @param string $subRelation Nested relation name
 * @return array */
    protected function eagerlyManyToMany($where, $relation, $subRelation = '')
    {
        // Eager-loading supports nested relations
        $list = $this->belongsToManyQuery($this->foreignKey, $this->localKey, $where)->with($subRelation)->select();

        // Group related model data
        $data = [];
        foreach ($list as $set) {
            $pivot = [];
            foreach ($set->getData() as $key => $val) {
                if (strpos($key, '__')) {
                    list($name, $attr) = explode('__', $key, 2);
                    if ('pivot' == $name) {
                        $pivot[$attr] = $val;
                        unset($set->$key);
                    }
                }
            }
            $set->setRelation($this->pivotDataName, $this->newPivot($pivot, true));
            $data[$pivot[$this->localKey]][] = $set;
        }
        return $data;
    }

    /* *
 * Build a BELONGS TO MANY relation query
 * @access public
 * @param string $foreignKey Associated model associated key
 * @param string $localKey Current model association key
 * @param array $condition Related query conditions
 * @return Query */
    protected function belongsToManyQuery($foreignKey, $localKey, $condition = [])
    {
        // Build the related-table query
        $tableName = $this->query->getTable();
        $table     = $this->pivot->getTable();
        $fields    = $this->getQueryFields($tableName);

        $query = $this->query->field($fields)
            ->field(true, false, $table, 'pivot', 'pivot__');

        if (empty($this->baseQuery)) {
            $relationFk = $this->query->getPk();
            $query->join([$table => 'pivot'], 'pivot.' . $foreignKey . '=' . $tableName . '.' . $relationFk)
                ->where($condition);
        }
        return $query;
    }

    /* *
 * Save a related model
 * @access public
 * @param mixed $data The data can use an array to associate the model object and the associated object\'s primary key
 * @param array $pivot intermediate table additional data
 * @return integer */
    public function save($data, array $pivot = [])
    {
        // Save related table/intermediate table data
        return $this->attach($data, $pivot);
    }

    /* *
 * Save related models in batches
 * @access public
 * @param array $dataSet
 * @param array $pivot intermediate table additional data
 * @param bool $samePivot Whether all records share the same pivot data
 * @return integer */
    public function saveAll(array $dataSet, array $pivot = [], $samePivot = false)
    {
        $result = false;
        foreach ($dataSet as $key => $data) {
            if (!$samePivot) {
                $pivotData = isset($pivot[$key]) ? $pivot[$key] : [];
            } else {
                $pivotData = $pivot;
            }
            $result = $this->attach($data, $pivotData);
        }
        return $result;
    }

    /* *
 * Attach related records through the pivot table
 * @access public
 * @param mixed $data The data can use an array, an associative model object, or the primary key of an associated object
 * @param array $pivot intermediate table additional data
 * @return array|Pivot
 * @throws Exception */
    public function attach($data, $pivot = [])
    {
        if (is_array($data)) {
            if (key($data) === 0) {
                $id = $data;
            } else {
                // Save related table data
                $model = new $this->model;
                $model->save($data);
                $id = $model->getLastInsID();
            }
        } elseif (is_numeric($data) || is_string($data)) {
            // Write directly to the intermediate table based on the primary key of the associated table
            $id = $data;
        } elseif ($data instanceof Model) {
            // Write directly to the intermediate table based on the primary key of the associated table
            $relationFk = $data->getPk();
            $id         = $data->$relationFk;
        }

        if ($id) {
            // Save intermediate table data
            $pk                     = $this->parent->getPk();
            $pivot[$this->localKey] = $this->parent->$pk;
            $ids                    = (array) $id;
            foreach ($ids as $id) {
                $pivot[$this->foreignKey] = $id;
                $this->pivot->insert($pivot, true);
                $result[] = $this->newPivot($pivot, true);
            }
            if (count($result) == 1) {
                // Returns the intermediate table model object
                $result = $result[0];
            }
            return $result;
        } else {
            throw new Exception('miss relation data');
        }
    }

    /* *
 * Find an existing pivot record
 * @access public
 * @param mixed $data can use the associated model object or the associated object\'s primary key
 * @return Pivot
 * @throws Exception */
    public function attached($data)
    {
        if ($data instanceof Model) {
            $relationFk = $data->getPk();
            $id         = $data->$relationFk;
        } else {
            $id = $data;
        }

        $pk = $this->parent->getPk();

        $pivot = $this->pivot->where($this->localKey, $this->parent->$pk)->where($this->foreignKey, $id)->find();

        return $pivot ?: false;
    }

    /* *
 * Unlink data from an intermediate table
 * @access public
 * @param integer|array $data The data can use the associated object\'s primary key
 * @param bool $relationDel Whether to delete associated table data at the same time
 * @return integer */
    public function detach($data = null, $relationDel = false)
    {
        if (is_array($data)) {
            $id = $data;
        } elseif (is_numeric($data) || is_string($data)) {
            // Write directly to the intermediate table based on the primary key of the associated table
            $id = $data;
        } elseif ($data instanceof Model) {
            // Write directly to the intermediate table based on the primary key of the associated table
            $relationFk = $data->getPk();
            $id         = $data->$relationFk;
        }
        // Delete intermediate table data
        $pk                     = $this->parent->getPk();
        $pivot[$this->localKey] = $this->parent->$pk;
        if (isset($id)) {
            $pivot[$this->foreignKey] = is_array($id) ? ['in', $id] : $id;
        }
        $this->pivot->where($pivot)->delete();
        // Delete related table data
        if (isset($id) && $relationDel) {
            $model = $this->model;
            $model::destroy($id);
        }
    }

    /* *
 * Synchronize the attached related records
 * @param array $ids
 * @param bool $detaching
 * @return array */
    public function sync($ids, $detaching = true)
    {
        $changes = [
            'attached' => [],
            'detached' => [],
            'updated'  => [],
        ];
        $pk      = $this->parent->getPk();
        $current = $this->pivot->where($this->localKey, $this->parent->$pk)
            ->column($this->foreignKey);
        $records = [];

        foreach ($ids as $key => $value) {
            if (!is_array($value)) {
                $records[$value] = [];
            } else {
                $records[$key] = $value;
            }
        }

        $detach = array_diff($current, array_keys($records));

        if ($detaching && count($detach) > 0) {
            $this->detach($detach);

            $changes['detached'] = $detach;
        }

        foreach ($records as $id => $attributes) {
            if (!in_array($id, $current)) {
                $this->attach($id, $attributes);
                $changes['attached'][] = $id;
            } elseif (count($attributes) > 0 &&
                $this->attach($id, $attributes)
            ) {
                $changes['updated'][] = $id;
            }
        }

        return $changes;

    }

    /* *
 * Apply base query constraints once
 * @access protected
 * @return void */
    protected function baseQuery()
    {
        if (empty($this->baseQuery) && $this->parent->getData()) {
            $pk    = $this->parent->getPk();
            $table = $this->pivot->getTable();
            $this->query->join([$table => 'pivot'], 'pivot.' . $this->foreignKey . '=' . $this->query->getTable() . '.' . $this->query->getPk())->where('pivot.' . $this->localKey, $this->parent->$pk);
            $this->baseQuery = true;
        }
    }

}
