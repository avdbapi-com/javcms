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

class HasManyThrough extends Relation
{
    // Related-model foreign key referencing the intermediate model
    protected $throughKey;
    // Intermediate model class
    protected $through;

    /* *
 * Constructor
 * @access public
 * @param Model $parent Parent model object
 * @param string $model model name
 * @param string $through intermediate model name
 * @param string $foreignKey Associated foreign key
 * @param string $throughKey Associated foreign key
 * @param string $localKey Associated primary key */
    public function __construct(Model $parent, $model, $through, $foreignKey, $throughKey, $localKey)
    {
        $this->parent     = $parent;
        $this->model      = $model;
        $this->through    = $through;
        $this->foreignKey = $foreignKey;
        $this->throughKey = $throughKey;
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

        return $this->relation($subRelation)->select();
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
    {}

    /* *
 * Eager-load relations for a single model
 * @access public
 * @param Model $result data object
 * @param string $relation Current relation name
 * @param string $subRelation Nested relation name
 * @param \Closure $closure closure
 * @return void */
    public function eagerlyResult(&$result, $relation, $subRelation, $closure)
    {}

    /* *
 * Count related records
 * @access public
 * @param Model $result Data object
 * @param \Closure $closure Closure
 * @return integer */
    public function relationCount($result, $closure)
    {}

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

    /* *
 * Apply base query constraints once
 * @access protected
 * @return void */
    protected function baseQuery()
    {
        if (empty($this->baseQuery) && $this->parent->getData()) {
            $through      = $this->through;
            $alias        = Loader::parseName(basename(str_replace('\\', '/', $this->model)));
            $throughTable = $through::getTable();
            $pk           = (new $through)->getPk();
            $throughKey   = $this->throughKey;
            $modelTable   = $this->parent->getTable();
            $this->query->field($alias . '.*')->alias($alias)
                ->join($throughTable, $throughTable . '.' . $pk . '=' . $alias . '.' . $throughKey)
                ->join($modelTable, $modelTable . '.' . $this->localKey . '=' . $throughTable . '.' . $this->foreignKey)
                ->where($throughTable . '.' . $this->foreignKey, $this->parent->{$this->localKey});
            $this->baseQuery = true;
        }
    }

}
