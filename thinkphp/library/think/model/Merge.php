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

use think\Db;
use think\db\Query;
use think\Model;

class Merge extends Model
{

    protected $relationModel = []; // HAS ONE related model list
    protected $fk            = ''; // Foreign key name; defaults to the parent model name followed by _id
    protected $mapFields     = []; // Field mappings used to avoid ambiguous names, such as array('id' => 'user.id')

    /* *
 * Constructor
 * @access public
 * @param array|object $data */
    public function __construct($data = [])
    {
        parent::__construct($data);

        // Set the default foreign key name to support only a single foreign key
        if (empty($this->fk)) {
            $this->fk = strtolower($this->name) . '_id';
        }
    }

    /* *
 * Find a single record
 * @access public
 * @param mixed $data primary key value or search conditions (closure)
 * @param string|array $with Relations to eager-load
 * @param bool $cache Whether to cache
 * @return \think\Model */
    public static function get($data = null, $with = [], $cache = false)
    {
        $query = self::parseQuery($data, $with, $cache);
        $query = self::attachQuery($query);
        return $query->find($data);
    }

    /* *
 * Add queries for related tables
 * @access protected
 * @param \think\db\Query $query Query object
 * @return \think\db\Query */
    protected static function attachQuery($query)
    {
        $class  = new static();
        $master = $class->name;
        $fields = self::getModelField($query, $master, '', $class->mapFields, $class->field);
        $query->alias($master)->field($fields);

        foreach ($class->relationModel as $key => $model) {
            $name  = is_int($key) ? $model : $key;
            $table = is_int($key) ? $query->getTable($name) : $model;
            $query->join($table . ' ' . $name, $name . '.' . $class->fk . '=' . $master . '.' . $class->getPk());
            $fields = self::getModelField($query, $name, $table, $class->mapFields, $class->field);
            $query->field($fields);
        }
        return $query;
    }

    /* *
 * Get related model fields and resolve ambiguous field names
 * @access protected
 * @param \think\db\Query $query Query object
 * @param string $name model name
 * @param string $table associated table name
 * @param array $map field mapping
 * @param array $fields query fields
 * @return array */
    protected static function getModelField($query, $name, $table = '', $map = [], $fields = [])
    {
        // Get model field information
        $fields = $fields ?: $query->getTableInfo($table, 'fields');
        $array  = [];
        foreach ($fields as $field) {
            if ($key = array_search($name . '.' . $field, $map)) {
                // Apply the field mapping
                $array[] = $name . '.' . $field . ' AS ' . $key;
            } else {
                $array[] = $field;
            }
        }
        return $array;
    }

    /* *
 * Find all records
 * @access public
 * @param mixed $data primary key list or search conditions (closure)
 * @param array|string $with Relations to eager-load
 * @param bool $cache
 * @return array|false|string */
    public static function all($data = null, $with = [], $cache = false)
    {
        $query = self::parseQuery($data, $with, $cache);
        $query = self::attachQuery($query);
        return $query->select($data);
    }

    /* *
 * Prepare data for a model write
 * @access public
 * @param string $model model name
 * @param array $data
 * @return array */
    protected function parseData($model, $data)
    {
        $item = [];
        foreach ($data as $key => $val) {
            if ($this->fk != $key && array_key_exists($key, $this->mapFields)) {
                list($name, $key) = explode('.', $this->mapFields[$key]);
                if ($model == $name) {
                    $item[$key] = $val;
                }
            } else {
                $item[$key] = $val;
            }
        }
        return $item;
    }

    /* *
 * Save model data and associated data
 * @access public
 * @param mixed $data
 * @param array $where Update conditions
 * @param string $sequence autoincrement sequence name
 * @return false|int
 * @throws \Exception */
    public function save($data = [], $where = [], $sequence = null)
    {
        if (!empty($data)) {
            // Validate data automatically
            if (!$this->validateData($data)) {
                return false;
            }
            // Assign values to model attributes
            foreach ($data as $key => $value) {
                $this->setAttr($key, $value, $data);
            }
            if (!empty($where)) {
                $this->isUpdate = true;
            }
        }

        // Complete data automatically
        $this->autoCompleteData($this->auto);

        // Set the update timestamp automatically
        if ($this->autoWriteTimestamp && $this->updateTime && !isset($this->data[$this->updateTime])) {
            $this->setAttr($this->updateTime, null);
        }

        // Event callbacks
        if (false === $this->trigger('before_write', $this)) {
            return false;
        }

        $db = $this->db();
        $db->startTrans();
        $pk = $this->getPk();
        try {
            if ($this->isUpdate) {
                // Complete write data automatically
                $this->autoCompleteData($this->update);

                if (false === $this->trigger('before_update', $this)) {
                    return false;
                }

                if (empty($where) && !empty($this->updateWhere)) {
                    $where = $this->updateWhere;
                }

                // Get updated data
                $data = $this->getChangedData();
                // Preserve primary key data
                foreach ($this->data as $key => $val) {
                    if ($this->isPk($key)) {
                        $data[$key] = $val;
                    }
                }
                // Prepare model data
                $data = $this->parseData($this->name, $data);
                if (is_string($pk) && isset($data[$pk])) {
                    if (!isset($where[$pk])) {
                        unset($where);
                        $where[$pk] = $data[$pk];
                    }
                    unset($data[$pk]);
                }
                // Write data to the main table
                $result = $db->strict(false)->where($where)->update($data);

                // Write data to related tables
                foreach ($this->relationModel as $key => $model) {
                    $name  = is_int($key) ? $model : $key;
                    $table = is_int($key) ? $db->getTable($model) : $model;
                    // Prepare related model data
                    $data = $this->parseData($name, $data);
                    if (Db::table($table)->strict(false)->where($this->fk, $this->data[$this->getPk()])->update($data)) {
                        $result = 1;
                    }
                }

                // Trigger the completion callback
                $this->trigger('after_update', $this);
            } else {
                // Complete write data automatically
                $this->autoCompleteData($this->insert);

                // Set the creation timestamp automatically
                if ($this->autoWriteTimestamp && $this->createTime && !isset($this->data[$this->createTime])) {
                    $this->setAttr($this->createTime, null);
                }

                if (false === $this->trigger('before_insert', $this)) {
                    return false;
                }

                // Prepare model data
                $data = $this->parseData($this->name, $this->data);
                // Write data to the main table
                $result = $db->name($this->name)->strict(false)->insert($data);
                if ($result) {
                    $insertId = $db->getLastInsID($sequence);
                    // Set the foreign key value
                    if ($insertId) {
                        if (is_string($pk)) {
                            $this->data[$pk] = $insertId;
                        }
                        $this->data[$this->fk] = $insertId;
                    }

                    // Write data to related tables
                    $source = $this->data;
                    if ($insertId && is_string($pk) && isset($source[$pk]) && $this->fk != $pk) {
                        unset($source[$pk]);
                    }
                    foreach ($this->relationModel as $key => $model) {
                        $name  = is_int($key) ? $model : $key;
                        $table = is_int($key) ? $db->getTable($model) : $model;
                        // Prepare related model data
                        $data = $this->parseData($name, $source);
                        Db::table($table)->strict(false)->insert($data);
                    }
                }
                // Mark as updated
                $this->isUpdate = true;
                // Trigger the completion callback
                $this->trigger('after_insert', $this);
            }
            $db->commit();
            // Trigger the write completion callback
            $this->trigger('after_write', $this);

            $this->origin = $this->data;
            return $result;
        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }
    }

    /* *
 * Delete the current record and its related data
 * @access public
 * @return int
 * @throws \Exception */
    public function delete()
    {
        if (false === $this->trigger('before_delete', $this)) {
            return false;
        }

        $db = $this->db();
        $db->startTrans();
        try {
            $result = $db->delete($this->data);
            if ($result) {
                // Get primary key data
                $pk = $this->data[$this->getPk()];

                // Delete associated data
                foreach ($this->relationModel as $key => $model) {
                    $table = is_int($key) ? $db->getTable($model) : $model;
                    $query = new Query;
                    $query->table($table)->where($this->fk, $pk)->delete();
                }
            }
            $this->trigger('after_delete', $this);
            $db->commit();
            return $result;
        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }
    }

}
