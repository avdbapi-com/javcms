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

use think\Exception;
use think\Loader;
use think\Model;
use think\model\Relation;

class MorphTo extends Relation
{
    // polymorphic field
    protected $morphKey;
    protected $morphType;
    // polymorphic aliases
    protected $alias;
    protected $relation;

    /* *
 * Constructor
 * @access public
 * @param Model $parent Parent model object
 * @param string $morphType polymorphic field name
 * @param string $morphKey foreign key name
 * @param array $alias polymorphic alias definitions
 * @param string $relation association name */
    public function __construct(Model $parent, $morphType, $morphKey, $alias = [], $relation = null)
    {
        $this->parent    = $parent;
        $this->morphType = $morphType;
        $this->morphKey  = $morphKey;
        $this->alias     = $alias;
        $this->relation  = $relation;
    }

    /* *
 * Get an instance of the current associated model class
 * @access public
 * @return Model */
    public function getModel()
    {
        $morphType = $this->morphType;
        $model     = $this->parseModel($this->parent->$morphType);
        return (new $model);
    }

    /* *
 * Lazy-load related data
 * @param string $subRelation Nested relation name
 * @param \Closure $closure Query closure
 * @return mixed */
    public function getRelation($subRelation = '', $closure = null)
    {
        $morphKey  = $this->morphKey;
        $morphType = $this->morphType;
        // polymorphic model
        $model = $this->parseModel($this->parent->$morphType);
        // primary key data
        $pk            = $this->parent->$morphKey;
        $relationModel = (new $model)->relation($subRelation)->find($pk);

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
 * Resolve the fully qualified model class name
 * @access protected
 * @param string $model name (or full class name)
 * @return string */
    protected function parseModel($model)
    {
        if (isset($this->alias[$model])) {
            $model = $this->alias[$model];
        }
        if (false === strpos($model, '\\')) {
            $path = explode('\\', get_class($this->parent));
            array_pop($path);
            array_push($path, Loader::parseName($model, 1));
            $model = implode('\\', $path);
        }
        return $model;
    }

    /* *
 * Set polymorphic aliases
 * @access public
 * @param array $alias alias definition
 * @return $this */
    public function setAlias($alias)
    {
        $this->alias = $alias;
        return $this;
    }

    /* *
 * Remove related query parameters
 * @access public
 * @return $this */
    public function removeOption()
    {
        return $this;
    }

    /* *
 * Eager-load relations for a result set
 * @access public
 * @param array $resultSet data set
 * @param string $relation Current relation name
 * @param string $subRelation Nested relation name
 * @param \Closure $closure Closure
 * @return void
 * @throws Exception */
    public function eagerlyResultSet(&$resultSet, $relation, $subRelation, $closure)
    {
        $morphKey  = $this->morphKey;
        $morphType = $this->morphType;
        $range     = [];
        foreach ($resultSet as $result) {
            // Get a list of associated foreign keys
            if (!empty($result->$morphKey)) {
                $range[$result->$morphType][] = $result->$morphKey;
            }
        }

        if (!empty($range)) {
            // Relation attribute name
            $attr = Loader::parseName($relation);
            foreach ($range as $key => $val) {
                // polymorphic type mapping
                $model = $this->parseModel($key);
                $obj   = new $model;
                $pk    = $obj->getPk();
                $list  = $obj->all($val, $subRelation);
                $data  = [];
                foreach ($list as $k => $vo) {
                    $data[$vo->$pk] = $vo;
                }
                foreach ($resultSet as $result) {
                    if ($key == $result->$morphType) {
                        // Related model
                        if (!isset($data[$result->$morphKey])) {
                            throw new Exception('relation data not exists :' . $this->model);
                        } else {
                            $relationModel = $data[$result->$morphKey];
                            $relationModel->setParent(clone $result);
                            $relationModel->isUpdate(true);

                            $result->setRelation($attr, $relationModel);
                        }
                    }
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
    public function eagerlyResult(&$result, $relation, $subRelation, $closure)
    {
        $morphKey  = $this->morphKey;
        $morphType = $this->morphType;
        // polymorphic type mapping
        $model = $this->parseModel($result->{$this->morphType});
        $this->eagerlyMorphToOne($model, $relation, $result, $subRelation);
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
 * Eager-load a MORPH TO relation
 * @access public
 * @param string $model Related model class name
 * @param string $relation Relation name
 * @param $result
 * @param string $subRelation
 * @return void */
    protected function eagerlyMorphToOne($model, $relation, &$result, $subRelation = '')
    {
        // Eager-loading supports nested relations
        $pk   = $this->parent->{$this->morphKey};
        $data = (new $model)->with($subRelation)->find($pk);
        if ($data) {
            $data->setParent(clone $result);
            $data->isUpdate(true);
        }
        $result->setRelation(Loader::parseName($relation), $data ?: null);
    }

    /* *
 * Add associated data
 * @access public
 * @param Model $model associated model object
 * @param string $type polymorphic type
 * @return Model */
    public function associate($model, $type = '')
    {
        $morphKey  = $this->morphKey;
        $morphType = $this->morphType;
        $pk        = $model->getPk();

        $this->parent->setAttr($morphKey, $model->$pk);
        $this->parent->setAttr($morphType, $type ?: get_class($model));
        $this->parent->save();

        return $this->parent->setRelation($this->relation, $model);
    }

    /* *
 * Remove the model association
 * @access public
 * @return Model */
    public function dissociate()
    {
        $morphKey  = $this->morphKey;
        $morphType = $this->morphType;

        $this->parent->setAttr($morphKey, null);
        $this->parent->setAttr($morphType, null);
        $this->parent->save();

        return $this->parent->setRelation($this->relation, null);
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
