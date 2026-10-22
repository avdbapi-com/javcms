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

/* *
 * Class OneToOne
 * @package think\model\relation
 * */
abstract class OneToOne extends Relation
{
    // Eager-loading method: 0 = JOIN, 1 = IN
    protected $eagerlyType = 1;
    // The currently associated JOIN type
    protected $joinType;
    // The property to be bound
    protected $bindAttr = [];
    // Associated method name
    protected $relation;

    /* *
 * Set the join type
 * @access public
 * @param string $type JOIN type
 * @return $this */
    public function joinType($type)
    {
        $this->joinType = $type;
        return $this;
    }

    /* *
 * Eager-load a relation using JOIN
 * @access public
 * @param Query $query Query object
 * @param string $relation Associated name
 * @param string $subRelation Associated
 * @param \Closure $closure Query closure
 * @param bool $first
 * @return void */
    public function eagerly(Query $query, $relation, $subRelation, $closure, $first)
    {
        $name = Loader::parseName(basename(str_replace('\\', '/', get_class($query->getModel()))));

        if ($first) {
            $table = $query->getTable();
            $query->table([$table => $name]);
            if ($query->getOptions('field')) {
                $field = $query->getOptions('field');
                $query->removeOption('field');
            } else {
                $field = true;
            }
            $query->field($field, false, $table, $name);
            $field = null;
        }

        // Build the eager-loading join
        $joinTable = $this->query->getTable();
        $joinAlias = $relation;
        $query->via($joinAlias);

        if ($this instanceof BelongsTo) {
            $query->join([$joinTable => $joinAlias], $name . '.' . $this->foreignKey . '=' . $joinAlias . '.' . $this->localKey, $this->joinType);
        } else {
            $query->join([$joinTable => $joinAlias], $name . '.' . $this->localKey . '=' . $joinAlias . '.' . $this->foreignKey, $this->joinType);
        }

        if ($closure) {
            // Apply the query closure
            call_user_func_array($closure, [ & $query]);
            // Use withField to specify the associated fields, such as
            // $query->where([\'id\'=>1])->withField(\'id,name\');
            if ($query->getOptions('with_field')) {
                $field = $query->getOptions('with_field');
                $query->removeOption('with_field');
            }
        } elseif (isset($this->option['field'])) {
            $field = $this->option['field'];
        }
        $query->field(isset($field) ? $field : true, false, $joinTable, $joinAlias, $relation . '__');
    }

    /* *
 * Eager-load relations for a result set
 * @param array $resultSet
 * @param string $relation
 * @param string $subRelation
 * @param \Closure $closure
 * @return mixed */
    abstract protected function eagerlySet(&$resultSet, $relation, $subRelation, $closure);

    /* *
 * Eager-load relations for a single model
 * @param Model $result
 * @param string $relation
 * @param string $subRelation
 * @param \Closure $closure
 * @return mixed */
    abstract protected function eagerlyOne(&$result, $relation, $subRelation, $closure);

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
        if (1 == $this->eagerlyType) {
            // Query using IN
            $this->eagerlySet($resultSet, $relation, $subRelation, $closure);
        } else {
            // Hydrate related models from joined fields
            foreach ($resultSet as $result) {
                $this->match($this->model, $relation, $result);
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
        if (1 == $this->eagerlyType) {
            // Query using IN
            $this->eagerlyOne($result, $relation, $subRelation, $closure);
        } else {
            // Hydrate related models from joined fields
            $this->match($this->model, $relation, $result);
        }
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
        $model = new $this->model;
        // Save related table data
        $data[$this->foreignKey] = $this->parent->{$this->localKey};
        return $model->save($data) ? $model : false;
    }

    /* *
 * Set the eager-loading method
 * @access public
 * @param integer $type Eager-loading method: 0 = JOIN, 1 = IN
 * @return $this */
    public function setEagerlyType($type)
    {
        $this->eagerlyType = $type;
        return $this;
    }

    /* *
 * Get the eager-loading method
 * @access public
 * @return integer */
    public function getEagerlyType()
    {
        return $this->eagerlyType;
    }

    /* *
 * Bind the properties of the associated table to the parent model properties
 * @access public
 * @param mixed $attr list of properties to bind
 * @return $this */
    public function bind($attr)
    {
        if (is_string($attr)) {
            $attr = explode(',', $attr);
        }
        $this->bindAttr = $attr;
        return $this;
    }

    /* *
 * Get binding properties
 * @access public
 * @return array */
    public function getBindAttr()
    {
        return $this->bindAttr;
    }

    /* *
 * Count related records
 * @access public
 * @param Model $result Data object
 * @param \Closure $closure Closure
 * @return integer */
    public function relationCount($result, $closure)
    {
    }

    /* *
 * Hydrate a one-to-one relation from joined fields
 * @access public
 * @param string $model model name
 * @param string $relation association name
 * @param Model $result model object instance
 * @return void */
    protected function match($model, $relation, &$result)
    {
        // Reassemble model data
        foreach ($result->getData() as $key => $val) {
            if (strpos($key, '__')) {
                list($name, $attr) = explode('__', $key, 2);
                if ($name == $relation) {
                    $list[$name][$attr] = $val;
                    unset($result->$key);
                }
            }
        }

        if (isset($list[$relation])) {
            $relationModel = new $model($list[$relation]);
            $relationModel->setParent(clone $result);
            $relationModel->isUpdate(true);

            if (!empty($this->bindAttr)) {
                $this->bindAttr($relationModel, $result, $this->bindAttr);
            }
        } else {
            $relationModel = null;
        }
        $result->setRelation(Loader::parseName($relation), $relationModel);
    }

    /* *
 * Bind related attributes to the parent model
 * @access protected
 * @param Model $model Associated model object
 * @param Model $result Parent model object
 * @param array $bindAttr Binding attribute
 * @return void
 * @throws Exception */
    protected function bindAttr($model, &$result, $bindAttr)
    {
        foreach ($bindAttr as $key => $attr) {
            $key = is_numeric($key) ? $attr : $key;
            if (isset($result->$key)) {
                throw new Exception('bind attr has exists:' . $key);
            } else {
                $result->setAttr($key, $model ? $model->$attr : null);
            }
        }
    }

    /* *
 * Eager-load a one-to-one relation using IN
 * @access public
 * @param object $model Associated model object
 * @param array $where Eager-loading query conditions
 * @param string $key Associated key
 * @param string $relation association name
 * @param string $subRelation Nested relation name
 * @param bool|\Closure $closure
 * @return array */
    protected function eagerlyWhere($model, $where, $key, $relation, $subRelation = '', $closure = false)
    {
        $this->baseQuery = true;

        // Eager-loading supports nested relations
        if ($closure) {
            call_user_func_array($closure, [ & $model]);
            if ($field = $model->getOptions('with_field')) {
                $model->field($field)->removeOption('with_field');
            }
        }
        $list = $model->where($where)->with($subRelation)->select();

        // Group related model data
        $data = [];
        foreach ($list as $set) {
            $data[$set->$key] = $set;
        }
        return $data;
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
