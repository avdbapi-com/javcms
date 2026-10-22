<?php

namespace traits\model;

use think\Collection;
use think\db\Query;
use think\Model;

/* *
 * @mixin \think\Model */
trait SoftDelete
{
    /* *
 * Check whether the current record has been soft-deleted
 * @access public
 * @return boolean */
    public function trashed()
    {
        $field = $this->getDeleteTimeField();

        if ($field && !empty($this->data[$field])) {
            return true;
        }
        return false;
    }

    /* *
 * Query records including soft-deleted records
 * @access public
 * @return Query */
    public static function withTrashed()
    {
        return (new static )->getQuery();
    }

    /* *
 * Only query soft-deleted data
 * @access public
 * @return Query */
    public static function onlyTrashed()
    {
        $model = new static();
        $field = $model->getDeleteTimeField(true);

        if ($field) {
            return $model->getQuery()->useSoftDelete($field, ['not null', '']);
        } else {
            return $model->getQuery();
        }
    }

    /* *
 * Delete current records
 * @access public
 * @param bool $force Whether to permanently delete records
 * @return integer */
    public function delete($force = false)
    {
        if (false === $this->trigger('before_delete', $this)) {
            return false;
        }

        $name = $this->getDeleteTimeField();
        if ($name && !$force) {
            // Soft delete
            $this->data[$name] = $this->autoWriteTimestamp($name);
            $result            = $this->isUpdate()->save();
        } else {
            // Permanently delete the current model record
            $result = $this->getQuery()->where($this->getWhere())->delete();
        }

        // Delete related records
        if (!empty($this->relationWrite)) {
            foreach ($this->relationWrite as $key => $name) {
                $name   = is_numeric($key) ? $name : $key;
                $result = $this->getRelation($name);
                if ($result instanceof Model) {
                    $result->delete();
                } elseif ($result instanceof Collection || is_array($result)) {
                    foreach ($result as $model) {
                        $model->delete();
                    }
                }
            }
        }

        $this->trigger('after_delete', $this);

        // Clear the original data
        $this->origin = [];

        return $result;
    }

    /* *
 * Delete records
 * @access public
 * @param mixed $data Primary keys, query conditions, or a query closure
 * @param bool $force Whether to permanently delete records
 * @return integer number of successfully deleted records */
    public static function destroy($data, $force = false)
    {
        if (is_null($data)) {
            return 0;
        }

        // Include soft-deleted records in the query
        $query = (new static())->db(false);
        if (is_array($data) && key($data) !== 0) {
            $query->where($data);
            $data = null;
        } elseif ($data instanceof \Closure) {
            call_user_func_array($data, [ & $query]);
            $data = null;
        }

        $count = 0;
        if ($resultSet = $query->select($data)) {
            foreach ($resultSet as $data) {
                $result = $data->delete($force);
                $count += $result;
            }
        }

        return $count;
    }

    /* *
 * Restore soft-deleted records
 * @access public
 * @param array $where Update conditions
 * @return integer */
    public function restore($where = [])
    {
        if (empty($where)) {
            $pk         = $this->getPk();
            $where[$pk] = $this->getData($pk);
        }

        $name = $this->getDeleteTimeField();

        if ($name) {
            // Clear the deletion timestamp
            return $this->getQuery()
                ->useSoftDelete($name, ['not null', ''])
                ->where($where)
                ->update([$name => null]);
        } else {
            return 0;
        }
    }

    /* *
 * Exclude soft-deleted records from the default query
 * @access protected
 * @param Query $query Query to configure
 * @return Query */
    protected function base($query)
    {
        $field = $this->getDeleteTimeField(true);
        return $field ? $query->useSoftDelete($field) : $query;
    }

    /* *
 * Get the soft-delete timestamp field
 * @access protected
 * @param bool $read Whether to retain the table qualifier for a read operation
 * @return string|false Field name, or false when soft deletion is disabled */
    protected function getDeleteTimeField($read = false)
    {
        $field = property_exists($this, 'deleteTime') && isset($this->deleteTime) ?
        $this->deleteTime :
        'delete_time';

        if (false === $field) {
            return false;
        }

        if (!strpos($field, '.')) {
            $field = '__TABLE__.' . $field;
        }

        if (!$read && strpos($field, '.')) {
            $array = explode('.', $field);
            $field = array_pop($array);
        }

        return $field;
    }
}
