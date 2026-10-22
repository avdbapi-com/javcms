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

class BelongsTo extends OneToOne
{
    /* *
 * Constructor
 * @access public
 * @param Model $parent Parent model object
 * @param string $model model name
 * @param string $foreignKey Associated foreign key
 * @param string $localKey Associated primary key
 * @param string $joinType JOIN type
 * @param string $relation association name */
    public function __construct(Model $parent, $model, $foreignKey, $localKey, $joinType = 'INNER', $relation = null)
    {
        $this->parent     = $parent;
        $this->model      = $model;
        $this->foreignKey = $foreignKey;
        $this->localKey   = $localKey;
        $this->joinType   = $joinType;
        $this->query      = (new $model)->db();
        $this->relation   = $relation;
    }

    /* *
 * Lazy-load related data
 * @param string $subRelation Nested relation name
 * @param \Closure $closure Query closure
 * @access public
 * @return array|false|\PDOStatement|string|Model */
    public function getRelation($subRelation = '', $closure = null)
    {
        $foreignKey = $this->foreignKey;
        if ($closure) {
            call_user_func_array($closure, [ & $this->query]);
        }
        $relationModel = $this->query
            ->removeWhereField($this->localKey)
            ->where($this->localKey, $this->parent->$foreignKey)
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
 * @param string $operator comparison operator
 * @param integer $count number
 * @param string $id Related-table field to count
 * @return Query */
    public function has($operator = '>=', $count = 1, $id = '*')
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
            ->join([$table => $relation], $model . '.' . $this->foreignKey . '=' . $relation . '.' . $this->localKey, $this->joinType)
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
            if (isset($result->$foreignKey)) {
                $range[] = $result->$foreignKey;
            }
        }

        if (!empty($range)) {
            $this->query->removeWhereField($localKey);
            $data = $this->eagerlyWhere($this->query, [
                $localKey => [
                    'in',
                    $range,
                ],
            ], $localKey, $relation, $subRelation, $closure);
            // Relation attribute name
            $attr = Loader::parseName($relation);
            // Attach related data to each model
            foreach ($resultSet as $result) {
                // Related model
                if (!isset($data[$result->$foreignKey])) {
                    $relationModel = null;
                } else {
                    $relationModel = $data[$result->$foreignKey];
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
        $this->query->removeWhereField($localKey);
        $data = $this->eagerlyWhere($this->query, [$localKey => $result->$foreignKey], $localKey, $relation, $subRelation, $closure);
        // Related model
        if (!isset($data[$result->$foreignKey])) {
            $relationModel = null;
        } else {
            $relationModel = $data[$result->$foreignKey];
            $relationModel->setParent(clone $result);
            $relationModel->isUpdate(true);
        }
        if (!empty($this->bindAttr)) {
            // Bind related attributes
            $this->bindAttr($relationModel, $result, $this->bindAttr);
        } else {
            // Set relation attributes
            $result->setRelation(Loader::parseName($relation), $relationModel);
        }
    }

    /* *
 * Associate the parent with a related model
 * @access public
 * @param Model $model Related model
 * @return Model */
    public function associate($model)
    {
        $foreignKey = $this->foreignKey;
        $pk         = $model->getPk();

        $this->parent->setAttr($foreignKey, $model->$pk);
        $this->parent->save();

        return $this->parent->setRelation($this->relation, $model);
    }

    /* *
 * Remove the model association
 * @access public
 * @return Model */
    public function dissociate()
    {
        $foreignKey = $this->foreignKey;

        $this->parent->setAttr($foreignKey, null);
        $this->parent->save();

        return $this->parent->setRelation($this->relation, null);
    }

    /* *
 * Apply base query constraints once
 * @access protected
 * @return void */
    protected function baseQuery()
    {
        if (empty($this->baseQuery)) {
            if (isset($this->parent->{$this->foreignKey})) {
                // Apply the parent model key to the related query
                $this->query->where($this->localKey, '=', $this->parent->{$this->foreignKey});
            }

            $this->baseQuery = true;
        }
    }
}
