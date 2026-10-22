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

class HasOne extends OneToOne
{
    /* *
 * Constructor
 * @access public
 * @param Model $parent Parent model object
 * @param string $model model name
 * @param string $foreignKey Associated foreign key
 * @param string $localKey Current model primary key
 * @param string $joinType JOIN type */
    public function __construct(Model $parent, $model, $foreignKey, $localKey, $joinType = 'INNER')
    {
        $this->parent     = $parent;
        $this->model      = $model;
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;
        $this->joinType   = $joinType;
        $this->query      = (new $model)->db();
    }

    /* *
 * Lazy-load related data
 * @param string $subRelation Nested relation name
 * @param \Closure $closure Query closure
 * @return array|false|\PDOStatement|string|Model */
    public function getRelation($subRelation = '', $closure = null)
    {
        // Get the parent model key
        $localKey = $this->localKey;
        if ($closure) {
            call_user_func_array($closure, [ & $this->query]);
        }
        // Query the related model using the parent key
        $relationModel = $this->query
            ->removeWhereField($this->foreignKey)
            ->where($this->foreignKey, $this->parent->$localKey)
            ->relation($subRelation)
            ->find();

        if ($relationModel) {
            $relationModel->setParent(clone $this->parent);
        }

        return $relationModel;
    }

    /* *
 * Query the current model based on associated conditions
 * @access public
 * @return Query */
    public function has()
    {
        $table      = $this->query->getTable();
        $model      = basename(str_replace('\\', '/', get_class($this->parent)));
        $relation   = basename(str_replace('\\', '/', $this->model));
        $localKey   = $this->localKey;
        $foreignKey = $this->foreignKey;
        return $this->parent->db()
            ->alias($model)
            ->whereExists(function ($query) use ($table, $model, $relation, $localKey, $foreignKey) {
                $query->table([$table => $relation])->field($relation . '.' . $foreignKey)->whereExp($model . '.' . $localKey, '=' . $relation . '.' . $foreignKey);
            });
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
            ->join([$table => $relation], $model . '.' . $this->localKey . '=' . $relation . '.' . $this->foreignKey, $this->joinType)
            ->where($where);
    }

    /* *
 * Eager-load relations for a result set
 * @access public
 * @param array $resultSet dataset
 * @param string $relation Current relation name
 * @param string $subRelation Nested relation name
 * @param \Closure $closure closure
 * @return void */
    protected function eagerlySet(&$resultSet, $relation, $subRelation, $closure)
    {
        $localKey   = $this->localKey;
        $foreignKey = $this->foreignKey;

        $range = [];
        foreach ($resultSet as $result) {
            // Get a list of associated foreign keys
            if (isset($result->$localKey)) {
                $range[] = $result->$localKey;
            }
        }

        if (!empty($range)) {
            $this->query->removeWhereField($foreignKey);
            $data = $this->eagerlyWhere($this->query, [
                $foreignKey => [
                    'in',
                    $range,
                ],
            ], $foreignKey, $relation, $subRelation, $closure);
            // Relation attribute name
            $attr = Loader::parseName($relation);
            // Attach related data to each model
            foreach ($resultSet as $result) {
                // Related model
                if (!isset($data[$result->$localKey])) {
                    $relationModel = null;
                } else {
                    $relationModel = $data[$result->$localKey];
                    $relationModel->setParent(clone $result);
                    $relationModel->isUpdate(true);
                }
                if (!empty($this->bindAttr)) {
                    // Bind related attributes
                    $this->bindAttr($relationModel, $result, $this->bindAttr);
                } else {
                    // Set relation attributes
                    $result->setRelation($attr, $relationModel);
                }
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
    protected function eagerlyOne(&$result, $relation, $subRelation, $closure)
    {
        $localKey   = $this->localKey;
        $foreignKey = $this->foreignKey;
        $this->query->removeWhereField($foreignKey);
        $data = $this->eagerlyWhere($this->query, [$foreignKey => $result->$localKey], $foreignKey, $relation, $subRelation, $closure);

        // Related model
        if (!isset($data[$result->$localKey])) {
            $relationModel = null;
        } else {
            $relationModel = $data[$result->$localKey];
            $relationModel->setParent(clone $result);
            $relationModel->isUpdate(true);
        }
        if (!empty($this->bindAttr)) {
            // Bind related attributes
            $this->bindAttr($relationModel, $result, $this->bindAttr);
        } else {
            $result->setRelation(Loader::parseName($relation), $relationModel);
        }
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
                $this->query->where($this->foreignKey, '=', $this->parent->{$this->localKey});
            }

            $this->baseQuery = true;
        }
    }
}
