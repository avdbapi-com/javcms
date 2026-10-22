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

namespace think;

use BadMethodCallException;
use InvalidArgumentException;
use think\db\Query;
use think\exception\ValidateException;
use think\model\Collection as ModelCollection;
use think\model\Relation;
use think\model\relation\BelongsTo;
use think\model\relation\BelongsToMany;
use think\model\relation\HasMany;
use think\model\relation\HasManyThrough;
use think\model\relation\HasOne;
use think\model\relation\MorphMany;
use think\model\relation\MorphOne;
use think\model\relation\MorphTo;

/* *
 * Class Model
 * @package think
 * @mixin Query */
abstract class Model implements \JsonSerializable, \ArrayAccess
{
    // Database query object pool
    protected static $links = [];
    // database configuration
    protected $connection = [];
    // Parent model in a relationship
    protected $parent;
    // Query class override
    protected $query;
    // Current model name
    protected $name;
    // Data table name
    protected $table;
    // Current class name
    protected $class;
    // Callback events
    private static $event = [];
    // Error message or validation errors
    protected $error;
    // Field validation rules
    protected $validate;
    // Primary key; use an array for a composite key, or leave unset for automatic detection
    protected $pk;
    // Allowed fields; true loads the table fields automatically
    protected $field = [];
    // Fields excluded from writes
    protected $except = [];
    // Obsolete fields to discard
    protected $disuse = [];
    // Read-only fields
    protected $readonly = [];
    // Attributes included in output
    protected $visible = [];
    // Hidden attributes
    protected $hidden = [];
    // Attributes to append to output
    protected $append = [];
    // Current model data
    protected $data = [];
    // Original model data
    protected $origin = [];
    // Related models
    protected $relation = [];

    // Fields to populate automatically on every save
    protected $auto = [];
    // Fields to populate automatically on insert
    protected $insert = [];
    // Fields to populate automatically on update
    protected $update = [];
    // Whether to write timestamps automatically; a string specifies the time-field type
    protected $autoWriteTimestamp;
    // Creation-time field
    protected $createTime = 'create_time';
    // Update-time field
    protected $updateTime = 'update_time';
    // Default format for reading date and time fields
    protected $dateFormat;
    // Field type or format conversion
    protected $type = [];
    // Whether saving updates an existing record
    protected $isUpdate = false;
    // Whether inserts use REPLACE
    protected $replace = false;
    // Whether to force all data to be updated
    protected $force = false;
    // Update conditions
    protected $updateWhere;
    // Whether validation failures throw an exception
    protected $failException = false;
    // Global query scope
    protected $useGlobalScope = true;
    // Whether validation collects multiple errors
    protected $batchValidate = false;
    // Result-set container type
    protected $resultSetType;
    // Relationships to write automatically
    protected $relationWrite;

    /* *
 * Model classes that have already been initialized.
 *
 * @var array */
    protected static $initialized = [];

    /* *
 * Models configured to read from the primary database in a replicated setup
 * @var array */
    protected static $readMaster;

    /* *
 * Construct the model
 * @access public
 * @param array|object $data */
    public function __construct($data = [])
    {
        if (is_object($data)) {
            $this->data = get_object_vars($data);
        } else {
            $this->data = $data;
        }

        if ($this->disuse) {
            // Remove obsolete fields
            foreach ((array) $this->disuse as $key) {
                if (array_key_exists($key, $this->data)) {
                    unset($this->data[$key]);
                }
            }
        }

        // Record the original data
        $this->origin = $this->data;

        // Current class name
        $this->class = get_called_class();

        if (empty($this->name)) {
            // Current model name
            $name       = str_replace('\\', '/', $this->class);
            $this->name = basename($name);
            if (Config::get('class_suffix')) {
                $suffix     = basename(dirname($name));
                $this->name = substr($this->name, 0, -strlen($suffix));
            }
        }

        if (is_null($this->autoWriteTimestamp)) {
            // Automatically write timestamps
            $this->autoWriteTimestamp = $this->getQuery()->getConfig('auto_timestamp');
        }

        if (is_null($this->dateFormat)) {
            // Set the timestamp format
            $this->dateFormat = $this->getQuery()->getConfig('datetime_format');
        }

        if (is_null($this->resultSetType)) {
            $this->resultSetType = $this->getQuery()->getConfig('resultset_type');
        }
        // Initialize the model class
        $this->initialize();
    }

    /* *
 * Configure reads from the primary database in a replicated setup
 * @access public
 * @param bool $all Whether this applies to all models
 * @return $this */
    public function readMaster($all = false)
    {
        $model = $all ? '*' : $this->class;

        static::$readMaster[$model] = true;
        return $this;
    }

    /* *
 * Create a query object for the model
 * @access protected
 * @return Query */
    protected function buildQuery()
    {
        // Merge the database configuration
        if (!empty($this->connection)) {
            if (is_array($this->connection)) {
                $connection = array_merge(Config::get('database'), $this->connection);
            } else {
                $connection = $this->connection;
            }
        } else {
            $connection = [];
        }

        $con = Db::connect($connection);
        // Set the current model to ensure that the query returns the model object
        $queryClass = $this->query ?: $con->getConfig('query');
        $query      = new $queryClass($con, $this);

        if (isset(static::$readMaster['*']) || isset(static::$readMaster[$this->class])) {
            $query->master(true);
        }

        // Set the current data table and model name
        if (!empty($this->table)) {
            $query->setTable($this->table);
        } else {
            $query->name($this->name);
        }

        if (!empty($this->pk)) {
            $query->pk($this->pk);
        }

        return $query;
    }

    /* *
 * Create a new model instance
 * @access public
 * @param array|object $data
 * @param bool $isUpdate Whether saving updates an existing record
 * @param mixed $where Update conditions
 * @return Model */
    public function newInstance($data = [], $isUpdate = false, $where = null)
    {
        return (new static($data))->isUpdate($isUpdate, $where);
    }

    /* *
 * Get the query object of the current model
 * @access public
 * @param bool $buildNewQuery create a new query object
 * @return Query */
    public function getQuery($buildNewQuery = false)
    {
        if ($buildNewQuery) {
            return $this->buildQuery();
        } elseif (!isset(self::$links[$this->class])) {
            // Create a model query object
            self::$links[$this->class] = $this->buildQuery();
        }

        return self::$links[$this->class];
    }

    /* *
 * Get the database query object for the current model
 * @access public
 * @param bool $useBaseQuery Whether to call the global query scope
 * @param bool $buildNewQuery create a new query object
 * @return Query */
    public function db($useBaseQuery = true, $buildNewQuery = true)
    {
        $query = $this->getQuery($buildNewQuery);

        // global scope
        if ($useBaseQuery && method_exists($this, 'base')) {
            call_user_func_array([$this, 'base'], [ & $query]);
        }

        // Returns the database query object for the current model
        return $query;
    }

    /* *
 * Initialize the model
 * @access protected
 * @return void */
    protected function initialize()
    {
        $class = get_class($this);
        if (!isset(static::$initialized[$class])) {
            static::$initialized[$class] = true;
            static::init();
        }
    }

    /* *
 * Model initialization hook
 * @access protected
 * @return void */
    protected static function init()
    {
    }

    /* *
 * Set the parent model in a relationship
 * @access public
 * @param Model $model Parent model
 * @return $this */
    public function setParent($model)
    {
        $this->parent = $model;
        return $this;
    }

    /* *
 * Get the parent model in a relationship
 * @access public
 * @return Model|null */
    public function getParent()
    {
        return $this->parent;
    }

    /* *
 * Set model data or a single attribute
 * @access public
 * @param array|object|string $data Data or an attribute name
 * @param mixed $value Attribute value, or true to apply setters to the supplied data
 * @return $this */
    public function data($data, $value = null)
    {
        if (is_string($data)) {
            $this->data[$data] = $value;
        } else {
            // Clear data
            $this->data = [];
            if (is_object($data)) {
                $data = get_object_vars($data);
            }
            if (true === $value) {
                // Apply attribute setters to the supplied data
                foreach ($data as $key => $value) {
                    $this->setAttr($key, $value, $data);
                }
            } else {
                $this->data = $data;
            }
        }
        return $this;
    }

    /* *
 * Get raw model data or related data; throw if the requested attribute does not exist
 * @access public
 * @param string|null $name Attribute name; null returns all model data
 * @return mixed
 * @throws InvalidArgumentException */
    public function getData($name = null)
    {
        if (is_null($name)) {
            return $this->data;
        } elseif (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        } elseif (array_key_exists($name, $this->relation)) {
            return $this->relation[$name];
        } else {
            throw new InvalidArgumentException('property not exists:' . $this->class . '->' . $name);
        }
    }

    /* *
 * Configure automatic timestamp writing
 * @access public
 * @param bool|string $auto Whether to write timestamps, or the time-field type
 * @return $this */
    public function isAutoWriteTimestamp($auto)
    {
        $this->autoWriteTimestamp = $auto;
        return $this;
    }

    /* *
 * Set whether updates write all data without comparing it to the original data
 * @access public
 * @param bool $force
 * @return $this */
    public function force($force = true)
    {
        $this->force = $force;
        return $this;
    }

    /* *
 * Set an attribute using its setter or configured type conversion
 * @access public
 * @param string $name attribute name
 * @param mixed $value property value
 * @param array $data
 * @return $this */
    public function setAttr($name, $value, $data = [])
    {
        if (is_null($value) && $this->autoWriteTimestamp && in_array($name, [$this->createTime, $this->updateTime])) {
            // Automatically written timestamp field
            $value = $this->autoWriteTimestamp($name);
        } else {
            // Look for an attribute setter
            $method = 'set' . Loader::parseName($name, 1) . 'Attr';
            if (method_exists($this, $method)) {
                $value = $this->$method($value, array_merge($this->data, $data), $this->relation);
            } elseif (isset($this->type[$name])) {
                // Type conversion
                $value = $this->writeTransform($value, $this->type[$name]);
            }
        }

        // Set data object properties
        $this->data[$name] = $value;
        return $this;
    }

    /* *
 * Get model data associated with the current model
 * @access public
 * @param string $name Association method name
 * @return mixed */
    public function getRelation($name = null)
    {
        if (is_null($name)) {
            return $this->relation;
        } elseif (array_key_exists($name, $this->relation)) {
            return $this->relation[$name];
        } else {
            return;
        }
    }

    /* *
 * Set associated data object values
 * @access public
 * @param string $name attribute name
 * @param mixed $value property value
 * @return $this */
    public function setRelation($name, $value)
    {
        $this->relation[$name] = $value;
        return $this;
    }

    /* *
 * Automatically write timestamps
 * @access protected
 * @param string $name timestamp field
 * @return mixed */
    protected function autoWriteTimestamp($name)
    {
        if (isset($this->type[$name])) {
            $type = $this->type[$name];
            if (strpos($type, ':')) {
                list($type, $param) = explode(':', $type, 2);
            }
            switch ($type) {
                case 'datetime':
                case 'date':
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value  = $this->formatDateTime(time(), $format);
                    break;
                case 'timestamp':
                case 'integer':
                default:
                    $value = time();
                    break;
            }
        } elseif (is_string($this->autoWriteTimestamp) && in_array(strtolower($this->autoWriteTimestamp), [
            'datetime',
            'date',
            'timestamp',
        ])
        ) {
            $value = $this->formatDateTime(time(), $this->dateFormat);
        } else {
            $value = $this->formatDateTime(time(), $this->dateFormat, true);
        }
        return $value;
    }

    /* *
 * Format a date or time value
 * @access protected
 * @param mixed $time date expression
 * @param mixed $format date format
 * @param bool $timestamp Whether to skip date formatting for a timestamp
 * @return mixed */
    protected function formatDateTime($time, $format, $timestamp = false)
    {
        if (false !== strpos($format, '\\')) {
            $time = new $format($time);
        } elseif (!$timestamp && false !== $format) {
            $time = date($format, $time);
        }
        return $time;
    }

    /* *
 * Convert an attribute value for storage
 * @access protected
 * @param mixed $value value
 * @param string|array $type The type to convert
 * @return mixed */
    protected function writeTransform($value, $type)
    {
        if (is_null($value)) {
            return;
        }

        if (is_array($type)) {
            list($type, $param) = $type;
        } elseif (strpos($type, ':')) {
            list($type, $param) = explode(':', $type, 2);
        }
        switch ($type) {
            case 'integer':
                $value = (int) $value;
                break;
            case 'float':
                if (empty($param)) {
                    $value = (float) $value;
                } else {
                    $value = (float) number_format($value, $param, '.', '');
                }
                break;
            case 'boolean':
                $value = (bool) $value;
                break;
            case 'timestamp':
                if (!is_numeric($value)) {
                    $value = strtotime($value);
                }
                break;
            case 'datetime':
                $format = !empty($param) ? $param : $this->dateFormat;
                $value  = is_numeric($value) ? $value : strtotime($value);
                $value  = $this->formatDateTime($value, $format);
                break;
            case 'object':
                if (is_object($value)) {
                    $value = json_encode($value, JSON_FORCE_OBJECT);
                }
                break;
            case 'array':
                $value = (array) $value;
            case 'json':
                $option = !empty($param) ? (int) $param : JSON_UNESCAPED_UNICODE;
                $value  = json_encode($value, $option);
                break;
            case 'serialize':
                $value = serialize($value);
                break;

        }
        return $value;
    }

    /* *
 * Get an attribute using its getter, type conversion, or relationship
 * @access public
 * @param string $name
 * @return mixed
 * @throws InvalidArgumentException */
    public function getAttr($name)
    {
        try {
            $notFound = false;
            $value    = $this->getData($name);
        } catch (InvalidArgumentException $e) {
            $notFound = true;
            $value    = null;
        }

        // Detect attribute getters
        $method = 'get' . Loader::parseName($name, 1) . 'Attr';
        if (method_exists($this, $method)) {
            $value = $this->$method($value, $this->data, $this->relation);
        } elseif (isset($this->type[$name])) {
            // Type conversion
            $value = $this->readTransform($value, $this->type[$name]);
        } elseif (in_array($name, [$this->createTime, $this->updateTime])) {
            if (is_string($this->autoWriteTimestamp) && in_array(strtolower($this->autoWriteTimestamp), [
                'datetime',
                'date',
                'timestamp',
            ])
            ) {
                $value = $this->formatDateTime(strtotime($value), $this->dateFormat);
            } else {
                $value = $this->formatDateTime($value, $this->dateFormat);
            }
        } elseif ($notFound) {
            $relation = Loader::parseName($name, 1, false);
            if (method_exists($this, $relation)) {
                $modelRelation = $this->$relation();
                // Load relationship data when the attribute is not present
                $value = $this->getRelationData($modelRelation);
                // Cache the relationship value
                $this->relation[$name] = $value;
            } else {
                throw new InvalidArgumentException('property not exists:' . $this->class . '->' . $name);
            }
        }
        return $value;
    }

    /* *
 * Get data for a relationship, reusing the parent model when applicable
 * @access protected
 * @param Relation $modelRelation Relationship definition
 * @return mixed
 * @throws BadMethodCallException */
    protected function getRelationData(Relation $modelRelation)
    {
        if ($this->parent && !$modelRelation->isSelfRelation() && get_class($modelRelation->getModel()) == get_class($this->parent)) {
            $value = $this->parent;
        } else {
            // Load the relationship data
            if (method_exists($modelRelation, 'getRelation')) {
                $value = $modelRelation->getRelation();
            } else {
                throw new BadMethodCallException('method not exists:' . get_class($modelRelation) . '-> getRelation');
            }
        }
        return $value;
    }

    /* *
 * Convert a stored attribute value for reading
 * @access protected
 * @param mixed $value value
 * @param string|array $type The type to convert
 * @return mixed */
    protected function readTransform($value, $type)
    {
        if (is_null($value)) {
            return;
        }

        if (is_array($type)) {
            list($type, $param) = $type;
        } elseif (strpos($type, ':')) {
            list($type, $param) = explode(':', $type, 2);
        }
        switch ($type) {
            case 'integer':
                $value = (int) $value;
                break;
            case 'float':
                if (empty($param)) {
                    $value = (float) $value;
                } else {
                    $value = (float) number_format($value, $param, '.', '');
                }
                break;
            case 'boolean':
                $value = (bool) $value;
                break;
            case 'timestamp':
                if (!is_null($value)) {
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value  = $this->formatDateTime($value, $format);
                }
                break;
            case 'datetime':
                if (!is_null($value)) {
                    $format = !empty($param) ? $param : $this->dateFormat;
                    $value  = $this->formatDateTime(strtotime($value), $format);
                }
                break;
            case 'json':
                $value = json_decode($value, true);
                break;
            case 'array':
                $value = empty($value) ? [] : json_decode($value, true);
                break;
            case 'object':
                $value = empty($value) ? new \stdClass() : json_decode($value);
                break;
            case 'serialize':
                try {
                    $value = unserialize($value);
                } catch (\Exception $e) {
                    $value = null;
                }
                break;
            default:
                if (false !== strpos($type, '\\')) {
                    // object type
                    $value = new $type($value);
                }
        }
        return $value;
    }

    /* *
 * Set attributes to append to output
 * @access public
 * @param array $append attribute list
 * @param bool $override Whether to replace the existing append list
 * @return $this */
    public function append($append = [], $override = false)
    {
        $this->append = $override ? $append : array_merge($this->append, $append);
        return $this;
    }

    /* *
 * Copy selected attributes from a related model into this model
 * @access public
 * @param string $relation Relationship method name
 * @param string|array $append Attributes to copy, optionally keyed by their destination names
 * @return $this
 * @throws Exception */
    public function appendRelationAttr($relation, $append)
    {
        if (is_string($append)) {
            $append = explode(',', $append);
        }

        $relation = Loader::parseName($relation, 1, false);

        // Get associated data
        if (isset($this->relation[$relation])) {
            $model = $this->relation[$relation];
        } else {
            $model = $this->getRelationData($this->$relation());
        }

        if ($model instanceof Model) {
            foreach ($append as $key => $attr) {
                $key = is_numeric($key) ? $attr : $key;
                if (isset($this->data[$key])) {
                    throw new Exception('bind attr has exists:' . $key);
                } else {
                    $this->data[$key] = $model->getAttr($attr);
                }
            }
        }
        return $this;
    }

    /* *
 * Set output properties to be hidden
 * @access public
 * @param array $hidden list of attributes
 * @param bool $override Whether to replace the existing hidden list
 * @return $this */
    public function hidden($hidden = [], $override = false)
    {
        $this->hidden = $override ? $hidden : array_merge($this->hidden, $hidden);
        return $this;
    }

    /* *
 * Set attributes to include in output
 * @access public
 * @param array $visible
 * @param bool $override Whether to replace the existing visible list
 * @return $this */
    public function visible($visible = [], $override = false)
    {
        $this->visible = $override ? $visible : array_merge($this->visible, $visible);
        return $this;
    }

    /* *
 * Parse hidden and displayed attributes
 * @access protected
 * @param array $attrs properties
 * @param array $result result set
 * @param bool $visible
 * @return array */
    protected function parseAttr($attrs, &$result, $visible = true)
    {
        $array = [];
        foreach ($attrs as $key => $val) {
            if (is_array($val)) {
                if ($visible) {
                    $array[] = $key;
                }
                $result[$key] = $val;
            } elseif (strpos($val, '.')) {
                list($key, $name) = explode('.', $val);
                if ($visible) {
                    $array[] = $key;
                }
                $result[$key][] = $name;
            } else {
                $array[] = $val;
            }
        }
        return $array;
    }

    /* *
 * Convert a related model or model collection to an array
 * @access protected
 * @param Model|ModelCollection $model
 * @param array $visible Visible attributes by relationship
 * @param array $hidden Hidden attributes by relationship
 * @param string $key Relationship key
 * @return array */
    protected function subToArray($model, $visible, $hidden, $key)
    {
        // Apply visibility rules to the related model or collection
        if (isset($visible[$key])) {
            $model->visible($visible[$key]);
        } elseif (isset($hidden[$key])) {
            $model->hidden($hidden[$key]);
        }
        return $model->toArray();
    }

    /* *
 * Convert the current model object to an array
 * @access public
 * @return array */
    public function toArray()
    {
        $item    = [];
        $visible = [];
        $hidden  = [];

        $data = array_merge($this->data, $this->relation);

        // Filter attributes
        if (!empty($this->visible)) {
            $array = $this->parseAttr($this->visible, $visible);
            $data  = array_intersect_key($data, array_flip($array));
        } elseif (!empty($this->hidden)) {
            $array = $this->parseAttr($this->hidden, $hidden, false);
            $data  = array_diff_key($data, array_flip($array));
        }

        foreach ($data as $key => $val) {
            if ($val instanceof Model || $val instanceof ModelCollection) {
                // Convert a related model or model collection
                $item[$key] = $this->subToArray($val, $visible, $hidden, $key);
            } elseif (is_array($val) && reset($val) instanceof Model) {
                // Convert an array of related models
                $arr = [];
                foreach ($val as $k => $value) {
                    $arr[$k] = $this->subToArray($value, $visible, $hidden, $key);
                }
                $item[$key] = $arr;
            } else {
                // model properties
                $item[$key] = $this->getAttr($key);
            }
        }
        // Append requested attributes and relationships
        if (!empty($this->append)) {
            foreach ($this->append as $key => $name) {
                if (is_array($name)) {
                    // Append associated object properties
                    $relation   = $this->getAttr($key);
                    $item[$key] = $relation->append($name)->toArray();
                } elseif (strpos($name, '.')) {
                    list($key, $attr) = explode('.', $name);
                    // Append associated object properties
                    $relation   = $this->getAttr($key);
                    $item[$key] = $relation->append([$attr])->toArray();
                } else {
                    $relation = Loader::parseName($name, 1, false);
                    if (method_exists($this, $relation)) {
                        $modelRelation = $this->$relation();
                        $value         = $this->getRelationData($modelRelation);

                        if (method_exists($modelRelation, 'getBindAttr')) {
                            $bindAttr = $modelRelation->getBindAttr();
                            if ($bindAttr) {
                                foreach ($bindAttr as $key => $attr) {
                                    $key = is_numeric($key) ? $attr : $key;
                                    if (isset($this->data[$key])) {
                                        throw new Exception('bind attr has exists:' . $key);
                                    } else {
                                        $item[$key] = $value ? $value->getAttr($attr) : null;
                                    }
                                }
                                continue;
                            }
                        }
                        $item[$name] = $value;
                    } else {
                        $item[$name] = $this->getAttr($name);
                    }
                }
            }
        }
        return !empty($item) ? $item : [];
    }

    /* *
 * Convert the current model to JSON
 * @access public
 * @param integer $options JSON encoding options
 * @return string|false */
    public function toJson($options = JSON_UNESCAPED_UNICODE)
    {
        return json_encode($this->toArray(), $options);
    }

    /* *
 * Clear the loaded relationships of the current model
 * @access public
 * @return $this */
    public function removeRelation()
    {
        $this->relation = [];
        return $this;
    }

    /* *
 * Convert a result set to the configured collection type, if enabled
 * @access public
 * @param array|\think\Collection $collection Result set
 * @return mixed */
    public function toCollection($collection)
    {
        if ($this->resultSetType) {
            if ('collection' == $this->resultSetType) {
                $collection = new ModelCollection($collection);
            } elseif (false !== strpos($this->resultSetType, '\\')) {
                $class      = $this->resultSetType;
                $collection = new $class($collection);
            }
        }
        return $collection;
    }

    /* *
 * Set relationships to save or delete with the current model
 * @access public
 * @param mixed $relation
 * @return $this */
    public function together($relation)
    {
        if (is_string($relation)) {
            $relation = explode(',', $relation);
        }
        $this->relationWrite = $relation;
        return $this;
    }

    /* *
 * Get the primary key of the model object
 * @access public
 * @param string $name model name
 * @return mixed */
    public function getPk($name = '')
    {
        if (!empty($name)) {
            $table = $this->getQuery()->getTable($name);
            return $this->getQuery()->getPk($table);
        } elseif (empty($this->pk)) {
            $this->pk = $this->getQuery()->getPk();
        }
        return $this->pk;
    }

    /* *
 * Determine whether a field name is a primary key field
 * @access protected
 * @param string $key name
 * @return bool */
    protected function isPk($key)
    {
        $pk = $this->getPk();
        if (is_string($pk) && $pk == $key) {
            return true;
        } elseif (is_array($pk) && in_array($key, $pk)) {
            return true;
        }
        return false;
    }

    /* *
 * Set whether inserts use REPLACE
 * @access public
 * @param bool $replace
 * @return $this */
    public function replace($replace = true)
    {
        $this->replace = $replace;
        return $this;
    }

    /* *
 * Save current data object
 * @access public
 * @param array $data
 * @param array $where Update conditions
 * @param string $sequence autoincrement sequence name
 * @return integer|false */
    public function save($data = [], $where = [], $sequence = null)
    {
        if (is_string($data)) {
            $sequence = $data;
            $data     = [];
        }

        // Validate the supplied data
        if (!empty($data)) {
            if (!$this->validateData($data)) {
                return false;
            }

            // Apply attribute setters to the supplied data
            foreach ($data as $key => $value) {
                $this->setAttr($key, $value, $data);
            }
        }

        if (!empty($where)) {
            $this->isUpdate    = true;
            $this->updateWhere = $where;
        }

        // Gather data for relationships written with this model
        if (!empty($this->relationWrite)) {
            $relation = [];
            foreach ($this->relationWrite as $key => $name) {
                if (is_array($name)) {
                    if (key($name) === 0) {
                        $relation[$key] = [];
                        foreach ($name as $val) {
                            if (isset($this->data[$val])) {
                                $relation[$key][$val] = $this->data[$val];
                                unset($this->data[$val]);
                            }
                        }
                    } else {
                        $relation[$key] = $name;
                    }
                } elseif (isset($this->relation[$name])) {
                    $relation[$name] = $this->relation[$name];
                } elseif (isset($this->data[$name])) {
                    $relation[$name] = $this->data[$name];
                    unset($this->data[$name]);
                }
            }
        }

        // Populate fields configured for every save
        $this->autoCompleteData($this->auto);

        // Event callbacks
        if (false === $this->trigger('before_write', $this)) {
            return false;
        }
        $pk = $this->getPk();
        if ($this->isUpdate) {
            // Populate fields configured for updates
            $this->autoCompleteData($this->update);

            // Event callbacks
            if (false === $this->trigger('before_update', $this)) {
                return false;
            }

            // Get updated data
            $data = $this->getChangedData();

            if (empty($data) || (count($data) == 1 && is_string($pk) && isset($data[$pk]))) {
                // Update related models
                if (isset($relation)) {
                    $this->autoRelationUpdate($relation);
                }
                return 0;
            } elseif ($this->autoWriteTimestamp && $this->updateTime && !isset($data[$this->updateTime])) {
                // Automatically write update time
                $data[$this->updateTime]       = $this->autoWriteTimestamp($this->updateTime);
                $this->data[$this->updateTime] = $data[$this->updateTime];
            }

            if (empty($where) && !empty($this->updateWhere)) {
                $where = $this->updateWhere;
            }

            // Preserve primary key data
            foreach ($this->data as $key => $val) {
                if ($this->isPk($key)) {
                    $data[$key] = $val;
                }
            }

            $array = [];

            foreach ((array) $pk as $key) {
                if (isset($data[$key])) {
                    $array[$key] = $data[$key];
                    unset($data[$key]);
                }
            }

            if (!empty($array)) {
                $where = $array;
            }

            // Determine the fields allowed in the update
            $allowFields = $this->checkAllowField(array_merge($this->auto, $this->update));

            // Model updates
            if (!empty($allowFields)) {
                $result = $this->getQuery()->where($where)->strict(false)->field($allowFields)->update($data);
            } else {
                $result = $this->getQuery()->where($where)->update($data);
            }

            // Update related models
            if (isset($relation)) {
                $this->autoRelationUpdate($relation);
            }

            // Update callbacks
            $this->trigger('after_update', $this);

        } else {
            // Populate fields configured for inserts
            $this->autoCompleteData($this->insert);

            // Automatically write creation time and update time
            if ($this->autoWriteTimestamp) {
                if ($this->createTime && !isset($this->data[$this->createTime])) {
                    $this->data[$this->createTime] = $this->autoWriteTimestamp($this->createTime);
                }
                if ($this->updateTime && !isset($this->data[$this->updateTime])) {
                    $this->data[$this->updateTime] = $this->autoWriteTimestamp($this->updateTime);
                }
            }

            if (false === $this->trigger('before_insert', $this)) {
                return false;
            }

            // Determine the fields allowed in the insert
            $allowFields = $this->checkAllowField(array_merge($this->auto, $this->insert));
            if (!empty($allowFields)) {
                $result = $this->getQuery()->strict(false)->field($allowFields)->insert($this->data, $this->replace, false, $sequence);
            } else {
                $result = $this->getQuery()->insert($this->data, $this->replace, false, $sequence);
            }

            // Get the generated primary-key value
            if ($result && $insertId = $this->getQuery()->getLastInsID($sequence)) {
                foreach ((array) $pk as $key) {
                    if (!isset($this->data[$key]) || '' == $this->data[$key]) {
                        $this->data[$key] = $insertId;
                    }
                }
            }

            // Save related data
            if (isset($relation)) {
                foreach ($relation as $name => $val) {
                    $method = Loader::parseName($name, 1, false);
                    $this->$method()->save($val);
                }
            }

            // Mark subsequent saves as updates
            $this->isUpdate = true;

            // Run after-insert callbacks
            $this->trigger('after_insert', $this);
        }
        // Run after-write callbacks
        $this->trigger('after_write', $this);

        // Refresh the original-data snapshot
        $this->origin = $this->data;

        return $result;
    }

    protected function checkAllowField($auto = [])
    {
        if (true === $this->field) {
            $this->field = $this->getQuery()->getTableInfo('', 'fields');
            $field       = $this->field;
        } elseif (!empty($this->field)) {
            $field = array_merge($this->field, $auto);
            if ($this->autoWriteTimestamp) {
                array_push($field, $this->createTime, $this->updateTime);
            }
        } elseif (!empty($this->except)) {
            $fields      = $this->getQuery()->getTableInfo('', 'fields');
            $field       = array_diff($fields, (array) $this->except);
            $this->field = $field;
        } else {
            $field = [];
        }

        if ($this->disuse) {
            // Exclude obsolete fields
            $field = array_diff($field, (array) $this->disuse);
        }
        return $field;
    }

    protected function autoRelationUpdate($relation)
    {
        foreach ($relation as $name => $val) {
            if ($val instanceof Model) {
                $val->save();
            } else {
                unset($this->data[$name]);
                $model = $this->getAttr($name);
                if ($model instanceof Model) {
                    $model->save($val);
                }
            }
        }
    }

    /* *
 * Get changed data and exclude read-only data
 * @access public
 * @return array */
    public function getChangedData()
    {
        if ($this->force) {
            $data = $this->data;
        } else {
            $data = array_udiff_assoc($this->data, $this->origin, function ($a, $b) {
                if ((empty($a) || empty($b)) && $a !== $b) {
                    return 1;
                }
                return is_object($a) || $a != $b ? 1 : 0;
            });
        }

        if (!empty($this->readonly)) {
            // Read-only fields are not allowed to be updated
            foreach ($this->readonly as $key => $field) {
                if (isset($data[$field])) {
                    unset($data[$field]);
                }
            }
        }

        return $data;
    }

    /* *
 * Increment a field, optionally with a delay
 * @access public
 * @param string $field name
 * @param integer $step Increment amount
 * @param integer $lazyTime Delay in seconds
 * @return integer|true
 * @throws Exception */
    public function setInc($field, $step = 1, $lazyTime = 0)
    {
        // Resolve update conditions
        $where = $this->getWhere();

        $result = $this->getQuery()->where($where)->setInc($field, $step, $lazyTime);
        if (true !== $result) {
            $this->data[$field] += $step;
        }

        return $result;
    }

    /* *
 * Decrement a field, optionally with a delay
 * @access public
 * @param string $field name
 * @param integer $step Decrement amount
 * @param integer $lazyTime Delay in seconds
 * @return integer|true
 * @throws Exception */
    public function setDec($field, $step = 1, $lazyTime = 0)
    {
        // Resolve update conditions
        $where  = $this->getWhere();
        $result = $this->getQuery()->where($where)->setDec($field, $step, $lazyTime);
        if (true !== $result) {
            $this->data[$field] -= $step;
        }

        return $result;
    }

    /* *
 * Get update conditions
 * @access protected
 * @return mixed */
    protected function getWhere()
    {
        // Prefer the primary key when determining conditions
        $pk = $this->getPk();

        if (is_string($pk) && isset($this->data[$pk])) {
            $where = [$pk => $this->data[$pk]];
        } elseif (!empty($this->updateWhere)) {
            $where = $this->updateWhere;
        } else {
            $where = null;
        }
        return $where;
    }

    /* *
 * Save multiple records
 * @access public
 * @param array $dataSet data
 * @param bool $replace Whether primary-key presence selects an update rather than an insert
 * @return array|\think\Collection|false
 * @throws \Exception */
    public function saveAll($dataSet, $replace = true)
    {
        if ($this->validate) {
            // Validate every record before saving
            $validate = $this->validate;
            foreach ($dataSet as $data) {
                if (!$this->validateData($data, $validate)) {
                    return false;
                }
            }
        }

        $result = [];
        $db     = $this->getQuery();
        $db->startTrans();
        try {
            $pk = $this->getPk();
            if (is_string($pk) && $replace) {
                $auto = true;
            }
            foreach ($dataSet as $key => $data) {
                if ($this->isUpdate || (!empty($auto) && isset($data[$pk]))) {
                    $result[$key] = self::update($data, [], $this->field);
                } else {
                    $result[$key] = self::create($data, $this->field);
                }
            }
            $db->commit();
            return $this->toCollection($result);
        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }
    }

    /* *
 * Set the fields that are allowed to be written
 * @access public
 * @param string|array|bool $field Allowed fields; true restricts writes to table fields
 * @return $this */
    public function allowField($field)
    {
        if (is_string($field)) {
            $field = explode(',', $field);
        }
        $this->field = $field;
        return $this;
    }

    /* *
 * Set fields to exclude from writes
 * @access public
 * @param string|array $field Fields to exclude
 * @return $this */
    public function except($field)
    {
        if (is_string($field)) {
            $field = explode(',', $field);
        }
        $this->except = $field;
        return $this;
    }

    /* *
 * Set a read-only field
 * @access public
 * @param mixed $field read-only field
 * @return $this */
    public function readonly($field)
    {
        if (is_string($field)) {
            $field = explode(',', $field);
        }
        $this->readonly = $field;
        return $this;
    }

    /* *
 * Set whether saving updates an existing record
 * @access public
 * @param bool $update
 * @param mixed $where
 * @return $this */
    public function isUpdate($update = true, $where = null)
    {
        $this->isUpdate = $update;
        if (!empty($where)) {
            $this->updateWhere = $where;
        }
        return $this;
    }

    /* *
 * Populate configured fields automatically
 * @access protected
 * @param array $auto Fields and optional values to populate
 * @return void */
    protected function autoCompleteData($auto = [])
    {
        foreach ($auto as $field => $value) {
            if (is_integer($field)) {
                $field = $value;
                $value = null;
            }

            if (!isset($this->data[$field])) {
                $default = null;
            } else {
                $default = $this->data[$field];
            }

            $this->setAttr($field, !is_null($value) ? $value : $default);
        }
    }

    /* *
 * Delete current records
 * @access public
 * @return integer|false */
    public function delete()
    {
        if (false === $this->trigger('before_delete', $this)) {
            return false;
        }

        // Delete conditions
        $where = $this->getWhere();

        // Delete current model data
        $result = $this->getQuery()->where($where)->delete();

        // Delete related models
        if (!empty($this->relationWrite)) {
            foreach ($this->relationWrite as $key => $name) {
                $name  = is_numeric($key) ? $name : $key;
                $model = $this->getAttr($name);
                if ($model instanceof Model) {
                    $model->delete();
                }
            }
        }

        $this->trigger('after_delete', $this);
        // Clear the original data
        $this->origin = [];

        return $result;
    }

    /* *
 * Set fields to populate automatically through attribute setters
 * @access public
 * @param array $fields Fields to populate automatically
 * @return $this */
    public function auto($fields)
    {
        $this->auto = $fields;
        return $this;
    }

    /* *
 * Set field validation
 * @access public
 * @param array|string|bool $rule Rules or validator name; true uses the model name
 * @param array $msg Validation messages
 * @param bool $batch Whether to collect multiple validation errors
 * @return $this */
    public function validate($rule = true, $msg = [], $batch = false)
    {
        if (is_array($rule)) {
            $this->validate = [
                'rule' => $rule,
                'msg'  => $msg,
            ];
        } else {
            $this->validate = true === $rule ? $this->name : $rule;
        }
        $this->batchValidate = $batch;
        return $this;
    }

    /* *
 * Set whether validation failures throw an exception
 * @access public
 * @param bool $fail Whether to throw an exception
 * @return $this */
    public function validateFailException($fail = true)
    {
        $this->failException = $fail;
        return $this;
    }

    /* *
 * Validate data automatically
 * @access protected
 * @param array $data Data to validate
 * @param mixed $rule Validation rules or validator name
 * @param bool|null $batch Whether to collect multiple errors; null uses the model setting
 * @return bool */
    protected function validateData($data, $rule = null, $batch = null)
    {
        $info = is_null($rule) ? $this->validate : $rule;

        if (!empty($info)) {
            if (is_array($info)) {
                $validate = Loader::validate();
                $validate->rule($info['rule']);
                $validate->message($info['msg']);
            } else {
                $name = is_string($info) ? $info : $this->name;
                if (strpos($name, '.')) {
                    list($name, $scene) = explode('.', $name);
                }
                $validate = Loader::validate($name);
                if (!empty($scene)) {
                    $validate->scene($scene);
                }
            }
            $batch = is_null($batch) ? $this->batchValidate : $batch;

            if (!$validate->batch($batch)->check($data)) {
                $this->error = $validate->getError();
                if ($this->failException) {
                    throw new ValidateException($this->error);
                } else {
                    return false;
                }
            }
            $this->validate = null;
        }
        return true;
    }

    /* *
 * Return the model error message or validation errors
 * @access public
 * @return string|array */
    public function getError()
    {
        return $this->error;
    }

    /* *
 * Register an event callback
 * @access public
 * @param string $event event name
 * @param callable $callback callback method
 * @param bool $override Whether to replace existing callbacks for this event
 * @return void */
    public static function event($event, $callback, $override = false)
    {
        $class = get_called_class();
        if ($override) {
            self::$event[$class][$event] = [];
        }
        self::$event[$class][$event][] = $callback;
    }

    /* *
 * Trigger event
 * @access protected
 * @param string $event event name
 * @param mixed $params Event data passed by reference
 * @return bool */
    protected function trigger($event, &$params)
    {
        if (isset(self::$event[$this->class][$event])) {
            foreach (self::$event[$this->class][$event] as $callback) {
                if (is_callable($callback)) {
                    $result = call_user_func_array($callback, [ & $params]);
                    if (false === $result) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /* *
 * Create and save a new model
 * @access public
 * @param array $data array
 * @param array|string|bool|null $field Fields allowed to be written
 * @return static */
    public static function create($data = [], $field = null)
    {
        $model = new static();
        if (!empty($field)) {
            $model->allowField($field);
        }
        $model->isUpdate(false)->save($data, []);
        return $model;
    }

    /* *
 * Update data
 * @access public
 * @param array $data array
 * @param array $where Update conditions
 * @param array|string|bool|null $field Fields allowed to be written
 * @return static */
    public static function update($data = [], $where = [], $field = null)
    {
        $model = new static();
        if (!empty($field)) {
            $model->allowField($field);
        }
        $result = $model->isUpdate(true)->save($data, $where);
        return $model;
    }

    /* *
 * Find a single record
 * @access public
 * @param mixed $data primary key value or search conditions (closure)
 * @param array|string $with Relationships to eager-load
 * @param bool $cache Whether to cache
 * @return static|null
 * @throws \think\exception\DbException */
    public static function get($data, $with = [], $cache = false)
    {
        if (is_null($data)) {
            return;
        }

        if (true === $with || is_int($with)) {
            $cache = $with;
            $with  = [];
        }
        $query = static::parseQuery($data, $with, $cache);
        return $query->find($data);
    }

    /* *
 * Find all records
 * @access public
 * @param mixed $data primary key list or search conditions (closure)
 * @param array|string $with Relationships to eager-load
 * @param bool $cache Whether to cache
 * @return static[]|\think\Collection|false
 * @throws \think\exception\DbException */
    public static function all($data = null, $with = [], $cache = false)
    {
        if (true === $with || is_int($with)) {
            $cache = $with;
            $with  = [];
        }
        $query = static::parseQuery($data, $with, $cache);
        return $query->select($data);
    }

    /* *
 * Build a query from primary keys, conditions, a closure, or a Query object
 * @access protected
 * @param mixed $data primary key list or search conditions (closure)
 * @param array|string $with Relationships to eager-load
 * @param bool $cache Whether to cache
 * @return Query */
    protected static function parseQuery(&$data, $with, $cache)
    {
        $result = self::with($with)->cache($cache);
        if (is_array($data) && key($data) !== 0) {
            $result = $result->where($data);
            $data   = null;
        } elseif ($data instanceof \Closure) {
            call_user_func_array($data, [ & $result]);
            $data = null;
        } elseif ($data instanceof Query) {
            $result = $data->with($with)->cache($cache);
            $data   = null;
        }
        return $result;
    }

    /* *
 * Delete records
 * @access public
 * @param mixed $data Primary keys, an array of conditions, or a query closure
 * @return integer number of successfully deleted records */
    public static function destroy($data)
    {
        $model = new static();
        $query = $model->db();
        if (empty($data) && 0 !== $data) {
            return 0;
        } elseif (is_array($data) && key($data) !== 0) {
            $query->where($data);
            $data = null;
        } elseif ($data instanceof \Closure) {
            call_user_func_array($data, [ & $query]);
            $data = null;
        }
        $resultSet = $query->select($data);
        $count     = 0;
        if ($resultSet) {
            foreach ($resultSet as $data) {
                $result = $data->delete();
                $count += $result;
            }
        }
        return $count;
    }

    /* *
 * Apply named query scopes or a scope closure
 * @access public
 * @param string|array|\Closure $name Scope names or a closure
 * @param mixed ...$params Additional arguments passed to each scope
 * @return Query */
    public static function scope($name)
    {
        $model  = new static();
        $query  = $model->db();
        $params = func_get_args();
        array_shift($params);
        array_unshift($params, $query);
        if ($name instanceof \Closure) {
            call_user_func_array($name, $params);
        } elseif (is_string($name)) {
            $name = explode(',', $name);
        }
        if (is_array($name)) {
            foreach ($name as $scope) {
                $method = 'scope' . trim($scope);
                if (method_exists($model, $method)) {
                    call_user_func_array([$model, $method], $params);
                }
            }
        }
        return $query;
    }

    /* *
 * Set whether to use the global query scope
 * @param bool $use Whether to enable global query scope
 * @access public
 * @return Query */
    public static function useGlobalScope($use)
    {
        $model = new static();
        return $model->db($use);
    }

    /* *
 * Query the current model based on associated conditions
 * @access public
 * @param string $relation association method name
 * @param mixed $operator comparison operator
 * @param integer $count number
 * @param string $id Field to count in the related table
 * @return Relation|Query */
    public static function has($relation, $operator = '>=', $count = 1, $id = '*')
    {
        $relation = (new static())->$relation();
        if (is_array($operator) || $operator instanceof \Closure) {
            return $relation->hasWhere($operator);
        }
        return $relation->has($operator, $count, $id);
    }

    /* *
 * Query the current model based on associated conditions
 * @access public
 * @param string $relation association method name
 * @param mixed $where search conditions (array or closure)
 * @param mixed $fields field
 * @return Relation|Query */
    public static function hasWhere($relation, $where = [], $fields = null)
    {
        return (new static())->$relation()->hasWhere($where, $fields);
    }

    /* *
 * Resolve a model name to its fully qualified class name
 * @access protected
 * @param string $model name (or full class name)
 * @return string */
    protected function parseModel($model)
    {
        if (false === strpos($model, '\\')) {
            $path = explode('\\', get_called_class());
            array_pop($path);
            array_push($path, Loader::parseName($model, 1));
            $model = implode('\\', $path);
        }
        return $model;
    }

    /* *
 * Query data associated with the current model
 * @access public
 * @param string|array $relations association name
 * @return $this */
    public function relationQuery($relations)
    {
        if (is_string($relations)) {
            $relations = explode(',', $relations);
        }

        foreach ($relations as $key => $relation) {
            $subRelation = '';
            $closure     = null;
            if ($relation instanceof \Closure) {
                // Use a closure to constrain the relationship query
                $closure  = $relation;
                $relation = $key;
            }
            if (is_array($relation)) {
                $subRelation = $relation;
                $relation    = $key;
            } elseif (strpos($relation, '.')) {
                list($relation, $subRelation) = explode('.', $relation, 2);
            }
            $method                = Loader::parseName($relation, 1, false);
            $this->data[$relation] = $this->$method()->getRelation($subRelation, $closure);
        }
        return $this;
    }

    /* *
 * Eager-load relationships for a result set
 * @access public
 * @param array $resultSet dataset
 * @param string|array $relation Relationships to load
 * @return void */
    public function eagerlyResultSet(&$resultSet, $relation)
    {
        $relations = is_string($relation) ? explode(',', $relation) : $relation;
        foreach ($relations as $key => $relation) {
            $subRelation = '';
            $closure     = false;
            if ($relation instanceof \Closure) {
                $closure  = $relation;
                $relation = $key;
            }
            if (is_array($relation)) {
                $subRelation = $relation;
                $relation    = $key;
            } elseif (strpos($relation, '.')) {
                list($relation, $subRelation) = explode('.', $relation, 2);
            }
            $relation = Loader::parseName($relation, 1, false);
            $this->$relation()->eagerlyResultSet($resultSet, $relation, $subRelation, $closure);
        }
    }

    /* *
 * Eager-load relationships for a model
 * @access public
 * @param Model $result data object
 * @param string|array $relation Relationships to load
 * @return void */
    public function eagerlyResult(&$result, $relation)
    {
        $relations = is_string($relation) ? explode(',', $relation) : $relation;

        foreach ($relations as $key => $relation) {
            $subRelation = '';
            $closure     = false;
            if ($relation instanceof \Closure) {
                $closure  = $relation;
                $relation = $key;
            }
            if (is_array($relation)) {
                $subRelation = $relation;
                $relation    = $key;
            } elseif (strpos($relation, '.')) {
                list($relation, $subRelation) = explode('.', $relation, 2);
            }
            $relation = Loader::parseName($relation, 1, false);
            $this->$relation()->eagerlyResult($result, $relation, $subRelation, $closure);
        }
    }

    /* *
 * Add relationship counts to a model
 * @access public
 * @param Model $result data object
 * @param string|array $relation association name
 * @return void */
    public function relationCount(&$result, $relation)
    {
        $relations = is_string($relation) ? explode(',', $relation) : $relation;

        foreach ($relations as $key => $relation) {
            $closure = false;
            if ($relation instanceof \Closure) {
                $closure  = $relation;
                $relation = $key;
            } elseif (is_string($key)) {
                $name     = $relation;
                $relation = $key;
            }
            $relation = Loader::parseName($relation, 1, false);
            $count    = $this->$relation()->relationCount($result, $closure);
            if (!isset($name)) {
                $name = Loader::parseName($relation) . '_count';
            }
            $result->setAttr($name, $count);
        }
    }

    /* *
 * Get the default foreign-key name for a model
 * @access protected
 * @param string $name model name
 * @return string */
    protected function getForeignKey($name)
    {
        if (strpos($name, '\\')) {
            $name = basename(str_replace('\\', '/', $name));
        }
        return Loader::parseName($name) . '_id';
    }

    /* *
 * Define a one-to-one relationship
 * @access public
 * @param string $model model name
 * @param string $foreignKey Associated foreign key
 * @param string $localKey Current model primary key
 * @param array $alias alias definitions (deprecated)
 * @param string $joinType JOIN type
 * @return HasOne */
    public function hasOne($model, $foreignKey = '', $localKey = '', $alias = [], $joinType = 'INNER')
    {
        // Resolve the related model and keys
        $model      = $this->parseModel($model);
        $localKey   = $localKey ?: $this->getPk();
        $foreignKey = $foreignKey ?: $this->getForeignKey($this->name);
        return new HasOne($this, $model, $foreignKey, $localKey, $joinType);
    }

    /* *
 * Define an inverse relationship to a parent model
 * @access public
 * @param string $model model name
 * @param string $foreignKey Foreign key on the current model
 * @param string $localKey Key on the related model
 * @param array $alias Alias definitions (unused)
 * @param string $joinType JOIN type
 * @return BelongsTo */
    public function belongsTo($model, $foreignKey = '', $localKey = '', $alias = [], $joinType = 'INNER')
    {
        // Resolve the related model and keys
        $model      = $this->parseModel($model);
        $foreignKey = $foreignKey ?: $this->getForeignKey($model);
        $localKey   = $localKey ?: (new $model)->getPk();
        $trace      = debug_backtrace(false, 2);
        $relation   = Loader::parseName($trace[1]['function']);
        return new BelongsTo($this, $model, $foreignKey, $localKey, $joinType, $relation);
    }

    /* *
 * Define a one-to-many relationship
 * @access public
 * @param string $model model name
 * @param string $foreignKey Foreign key on the related model
 * @param string $localKey Key on the current model
 * @return HasMany */
    public function hasMany($model, $foreignKey = '', $localKey = '')
    {
        // Resolve the related model and keys
        $model      = $this->parseModel($model);
        $localKey   = $localKey ?: $this->getPk();
        $foreignKey = $foreignKey ?: $this->getForeignKey($this->name);
        return new HasMany($this, $model, $foreignKey, $localKey);
    }

    /* *
 * Define a one-to-many relationship through an intermediate model
 * @access public
 * @param string $model model name
 * @param string $through intermediate model name
 * @param string $foreignKey Foreign key on the intermediate model
 * @param string $throughKey Foreign key on the final related model
 * @param string $localKey Key on the current model
 * @return HasManyThrough */
    public function hasManyThrough($model, $through, $foreignKey = '', $throughKey = '', $localKey = '')
    {
        // Resolve both related models and their keys
        $model      = $this->parseModel($model);
        $through    = $this->parseModel($through);
        $localKey   = $localKey ?: $this->getPk();
        $foreignKey = $foreignKey ?: $this->getForeignKey($this->name);
        $throughKey = $throughKey ?: $this->getForeignKey($through);
        return new HasManyThrough($this, $model, $through, $foreignKey, $throughKey, $localKey);
    }

    /* *
 * Define a many-to-many relationship
 * @access public
 * @param string $model model name
 * @param string $table Junction table name
 * @param string $foreignKey Junction-table key for the related model
 * @param string $localKey Junction-table key for the current model
 * @return BelongsToMany */
    public function belongsToMany($model, $table = '', $foreignKey = '', $localKey = '')
    {
        // Resolve the related model, junction table and keys
        $model      = $this->parseModel($model);
        $name       = Loader::parseName(basename(str_replace('\\', '/', $model)));
        $table      = $table ?: Loader::parseName($this->name) . '_' . $name;
        $foreignKey = $foreignKey ?: $name . '_id';
        $localKey   = $localKey ?: $this->getForeignKey($this->name);
        return new BelongsToMany($this, $model, $table, $foreignKey, $localKey);
    }

    /* *
 * Define a polymorphic one-to-many relationship
 * @access public
 * @param string $model model name
 * @param string|array $morph polymorphic field information
 * @param string $type polymorphic type
 * @return MorphMany */
    public function morphMany($model, $morph = null, $type = '')
    {
        // Resolve the model and polymorphic fields
        $model = $this->parseModel($model);
        if (is_null($morph)) {
            $trace = debug_backtrace(false, 2);
            $morph = Loader::parseName($trace[1]['function']);
        }
        $type = $type ?: get_class($this);
        if (is_array($morph)) {
            list($morphType, $foreignKey) = $morph;
        } else {
            $morphType  = $morph . '_type';
            $foreignKey = $morph . '_id';
        }
        return new MorphMany($this, $model, $foreignKey, $morphType, $type);
    }

    /* *
 * Define a polymorphic one-to-one relationship
 * @access public
 * @param string $model Related model name
 * @param string|array|null $morph Field prefix or a pair of type and foreign-key fields
 * @param string $type Polymorphic type value
 * @return MorphOne */
    public function morphOne($model, $morph = null, $type = '')
    {
        // Resolve the model and polymorphic fields
        $model = $this->parseModel($model);
        if (is_null($morph)) {
            $trace = debug_backtrace(false, 2);
            $morph = Loader::parseName($trace[1]['function']);
        }
        $type = $type ?: get_class($this);
        if (is_array($morph)) {
            list($morphType, $foreignKey) = $morph;
        } else {
            $morphType  = $morph . '_type';
            $foreignKey = $morph . '_id';
        }
        return new MorphOne($this, $model, $foreignKey, $morphType, $type);
    }

    /* *
 * Define an inverse polymorphic relationship
 * @access public
 * @param string|array|null $morph Field prefix or a pair of type and foreign-key fields
 * @param array $alias Polymorphic type-to-model aliases
 * @return MorphTo */
    public function morphTo($morph = null, $alias = [])
    {
        $trace    = debug_backtrace(false, 2);
        $relation = Loader::parseName($trace[1]['function']);

        if (is_null($morph)) {
            $morph = $relation;
        }
        // Resolve the polymorphic fields
        if (is_array($morph)) {
            list($morphType, $foreignKey) = $morph;
        } else {
            $morphType  = $morph . '_type';
            $foreignKey = $morph . '_id';
        }
        return new MorphTo($this, $morphType, $foreignKey, $alias, $relation);
    }

    public function __call($method, $args)
    {
        $query = $this->db(true, false);
        if (method_exists($this, 'scope' . $method)) {
            // Dynamically invoke a named query scope
            $method = 'scope' . $method;
            array_unshift($args, $query);
            call_user_func_array([$this, $method], $args);
            return $this;
        } else {
            return call_user_func_array([$query, $method], $args);
        }
    }

    public static function __callStatic($method, $args)
    {
        $model = new static();
        $query = $model->db();
        if (method_exists($model, 'scope' . $method)) {
            // Dynamically invoke a named query scope
            $method = 'scope' . $method;
            array_unshift($args, $query);

            call_user_func_array([$model, $method], $args);
            return $query;
        } else {
            return call_user_func_array([$query, $method], $args);
        }
    }

    /* *
 * Set a model attribute through setAttr
 * @access public
 * @param string $name name
 * @param mixed $value value
 * @return void */
    public function __set($name, $value)
    {
        $this->setAttr($name, $value);
    }

    /* *
 * Get a model attribute through getAttr
 * @access public
 * @param string $name name
 * @return mixed */
    public function __get($name)
    {
        return $this->getAttr($name);
    }

    /* *
 * Check whether a model attribute exists or can be resolved
 * @access public
 * @param string $name name
 * @return boolean */
    public function __isset($name)
    {
        try {
            if (array_key_exists($name, $this->data) || array_key_exists($name, $this->relation)) {
                return true;
            } else {
                $this->getAttr($name);
                return true;
            }
        } catch (InvalidArgumentException $e) {
            return false;
        }

    }

    /* *
 * Remove an attribute from model data and loaded relationships
 * @access public
 * @param string $name name
 * @return void */
    public function __unset($name)
    {
        unset($this->data[$name], $this->relation[$name]);
    }

    public function __toString()
    {
        return $this->toJson();
    }

    // JsonSerializable
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    // ArrayAccess
    #[\ReturnTypeWillChange]
    public function offsetSet($name, $value)
    {
        $this->setAttr($name, $value);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($name)
    {
        return $this->__isset($name);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($name)
    {
        $this->__unset($name);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($name)
    {
        return $this->getAttr($name);
    }

    /* *
 * Deserialization post-processing */
    public function __wakeup()
    {
        $this->initialize();
    }

    /* *
 * Register a before-insert event callback
 * @param callable $callback Event callback
 * @param bool $override Whether to replace existing callbacks for this event */
    protected static function beforeInsert($callback, $override = false)
    {
        self::event('before_insert', $callback, $override);
    }

    protected static function afterInsert($callback, $override = false)
    {
        self::event('after_insert', $callback, $override);
    }

    protected static function beforeUpdate($callback, $override = false)
    {
        self::event('before_update', $callback, $override);
    }

    protected static function afterUpdate($callback, $override = false)
    {
        self::event('after_update', $callback, $override);
    }

    protected static function beforeWrite($callback, $override = false)
    {
        self::event('before_write', $callback, $override);
    }

    protected static function afterWrite($callback, $override = false)
    {
        self::event('after_write', $callback, $override);
    }

    protected static function beforeDelete($callback, $override = false)
    {
        self::event('before_delete', $callback, $override);
    }

    protected static function afterDelete($callback, $override = false)
    {
        self::event('after_delete', $callback, $override);
    }

}
