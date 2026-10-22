<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: zhangyajun <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /* *
 * @var array Collection items */
    protected $items = [];

    /* *
 * Collection constructor.
 * @access public
 * @param array $items data */
    public function __construct($items = [])
    {
        $this->items = $this->convertToArray($items);
    }

    /* *
 * Create an instance of Collection
 * @access public
 * @param array $items data
 * @return static */
    public static function make($items = [])
    {
        return new static($items);
    }

    /* *
 * Determine whether the data is empty
 * @access public
 * @return bool */
    public function isEmpty()
    {
        return empty($this->items);
    }

    /* *
 * Convert the collection and any model or collection items to arrays
 * @access public
 * @return array */
    public function toArray()
    {
        return array_map(function ($value) {
            return ($value instanceof Model || $value instanceof self) ?
                $value->toArray() :
                $value;
        }, $this->items);
    }

    /* *
 * Return all items without converting them
 * @access public
 * @return array */
    public function all()
    {
        return $this->items;
    }

    /* *
 * Exchange keys and values in an array
 * @access public
 * @return static */
    public function flip()
    {
        return new static(array_flip($this->items));
    }

    /* *
 * Returns a new Collection instance composed of all the keys in the array
 * @access public
 * @return static */
    public function keys()
    {
        return new static(array_keys($this->items));
    }

    /* *
 * Returns a new Collection instance composed of all the values in the array
 * @access public
 * @return static */
    public function values()
    {
        return new static(array_values($this->items));
    }

    /* *
 * Merge arrays and return a new Collection instance
 * @access public
 * @param mixed $items new data
 * @return static */
    public function merge($items)
    {
        return new static(array_merge($this->items, $this->convertToArray($items)));
    }

    /* *
 * Return a new collection of values not present in the supplied items
 * @access public
 * @param mixed $items data for comparison
 * @return static */
    public function diff($items)
    {
        return new static(array_diff($this->items, $this->convertToArray($items)));
    }

    /* *
 * Return a new collection of values also present in the supplied items
 * @access public
 * @param mixed $items comparison data
 * @return static */
    public function intersect($items)
    {
        return new static(array_intersect($this->items, $this->convertToArray($items)));
    }

    /* *
 * Remove and return the last item
 * @access public
 * @return mixed */
    public function pop()
    {
        return array_pop($this->items);
    }

    /* *
 * Remove and return the first item
 * @access public
 * @return mixed */
    public function shift()
    {
        return array_shift($this->items);
    }

    /* *
 * Insert an element at the beginning of an array
 * @access public
 * @param mixed $value value
 * @param mixed $key key name
 * @return void */
    public function unshift($value, $key = null)
    {
        if (is_null($key)) {
            array_unshift($this->items, $value);
        } else {
            $this->items = [$key => $value] + $this->items;
        }
    }

    /* *
 * Insert an element at the end of the array
 * @access public
 * @param mixed $value value
 * @param mixed $key key name
 * @return void */
    public function push($value, $key = null)
    {
        if (is_null($key)) {
            $this->items[] = $value;
        } else {
            $this->items[$key] = $value;
        }
    }

    /* *
 * Reduce the items to a single result using a callback
 * @access public
 * @param callable $callback callback function
 * @param mixed $initial Initial accumulator value
 * @return mixed */
    public function reduce(callable $callback, $initial = null)
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /* *
 * Return a new collection with the items in reverse order
 * @access public
 * @return static */
    public function reverse()
    {
        return new static(array_reverse($this->items));
    }

    /* *
 * Split the items into chunks, each represented by a collection
 * @access public
 * @param int $size Maximum number of items per chunk
 * @param bool $preserveKeys Whether to preserve the original keys
 * @return static */
    public function chunk($size, $preserveKeys = false)
    {
        $chunks = [];

        foreach (array_chunk($this->items, $size, $preserveKeys) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    /* *
 * Run a callback for each item, stopping if it returns false
 * @access public
 * @param callable $callback Receives the item and key; its result replaces non-object items
 * @return $this */
    public function each(callable $callback)
    {
        foreach ($this->items as $key => $item) {
            $result = $callback($item, $key);

            if (false === $result) {
                break;
            }

            if (!is_object($item)) {
                $this->items[$key] = $result;
            }
        }

        return $this;
    }

    /* *
 * Filter items with a callback, or remove falsey values when no callback is supplied
 * @access public
 * @param callable|null $callback callback function
 * @return static */
    public function filter(?callable $callback = null)
    {
        return new static($callback === null ? array_filter($this->items) : array_filter($this->items, $callback));
    }

    /* *
 * Return values from a column of the items
 * @access public
 * @param mixed $columnKey Column to return, or null to return entire rows
 * @param mixed $indexKey Column to use as result keys, or null for numeric keys
 * @return array */
    public function column($columnKey, $indexKey = null)
    {
        if (function_exists('array_column')) {
            return array_column($this->items, $columnKey, $indexKey);
        }

        $result = [];
        foreach ($this->items as $row) {
            $key    = $value = null;
            $keySet = $valueSet = false;

            if (null !== $indexKey && array_key_exists($indexKey, $row)) {
                $key    = (string) $row[$indexKey];
                $keySet = true;
            }

            if (null === $columnKey) {
                $valueSet = true;
                $value    = $row;
            } elseif (is_array($row) && array_key_exists($columnKey, $row)) {
                $valueSet = true;
                $value    = $row[$columnKey];
            }

            if ($valueSet) {
                if ($keySet) {
                    $result[$key] = $value;
                } else {
                    $result[] = $value;
                }
            }
        }

        return $result;
    }

    /* *
 * Return a new collection sorted by value, preserving item keys
 * @access public
 * @param callable|null $callback Comparison function, or null for the default comparison
 * @return static */
    public function sort(?callable $callback = null)
    {
        $items    = $this->items;
        $callback = $callback ?: function ($a, $b) {
            return $a == $b ? 0 : (($a < $b) ? -1 : 1);
        };

        uasort($items, $callback);
        return new static($items);
    }

    /* *
 * Return a new collection with shuffled items and numeric keys
 * @access public
 * @return static */
    public function shuffle()
    {
        $items = $this->items;

        shuffle($items);
        return new static($items);
    }

    /* *
 * Return a slice of the items as a new collection
 * @access public
 * @param int $offset Starting position; negative values count from the end
 * @param int|null $length Number of items, null for the remainder, or negative to omit trailing items
 * @param bool $preserveKeys Whether to keep the original key names
 * @return static */
    public function slice($offset, $length = null, $preserveKeys = false)
    {
        return new static(array_slice($this->items, $offset, $length, $preserveKeys));
    }

    /* *
 * Whether the specified key exists
 * @access public
 * @param mixed $offset key name
 * @return bool */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->items);
    }

    /* *
 * Get the value corresponding to the specified key
 * @access public
 * @param mixed $offset key name
 * @return mixed */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->items[$offset];
    }

    /* *
 * Set an item by key, or append it when the key is null
 * @access public
 * @param mixed $offset key name
 * @param mixed $value
 * @return void */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /* *
 * Remove the item at the specified key
 * @access public
 * @param mixed $offset key name
 * @return void */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->items[$offset]);
    }

    /* *
 * Count the items
 * @access public
 * @return int */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return count($this->items);
    }

    /* *
 * Get an iterator over the items
 * @access public
 * @return ArrayIterator */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->items);
    }

    /* *
 * Return array data for JSON serialization
 * @access public
 * @return array */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /* *
 * Encode the collection as JSON
 * @access public
 * @param integer $options JSON encoding flags
 * @return string */
    public function toJson($options = JSON_UNESCAPED_UNICODE)
    {
        return json_encode($this->toArray(), $options);
    }

    /* *
 * Return the collection as a JSON string
 * @access public
 * @return string */
    public function __toString()
    {
        return $this->toJson();
    }

    /* *
 * Extract items from a collection, or cast another value to an array
 * @access protected
 * @param mixed $items data
 * @return array */
    protected function convertToArray($items)
    {
        return $items instanceof self ? $items->all() : (array) $items;
    }
}
