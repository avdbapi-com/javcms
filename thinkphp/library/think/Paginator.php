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
use Traversable;

abstract class Paginator implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /* * @var bool Whether to use simple pagination */
    protected $simple = false;

    /* * @var Collection Items on the current page */
    protected $items;

    /* * @var integer Current page */
    protected $currentPage;

    /* * @var integer Last page number */
    protected $lastPage;

    /* * @var integer|null Total number of items */
    protected $total;

    /* * @var integer Number of items per page */
    protected $listRows;

    /* * @var bool Whether there is a next page */
    protected $hasMore;

    /* * @var array Pagination options */
    protected $options = [
        'var_page' => 'page',
        'path'     => '/',
        'query'    => [],
        'fragment' => '',
    ];

    /* * @var Collection|null One-item collection from the next page in simple mode */
    protected $nextItem;

    public function __construct($items, $listRows, $currentPage = null, $total = null, $simple = false, $options = [])
    {
        $this->options = array_merge($this->options, $options);

        $this->options['path'] = '/' != $this->options['path'] ? rtrim($this->options['path'], '/') : $this->options['path'];

        $this->simple   = $simple;
        $this->listRows = $listRows;

        if (!$items instanceof Collection) {
            $items = Collection::make($items);
        }

        if ($simple) {
            $this->currentPage = $this->setCurrentPage($currentPage);
            $this->hasMore     = count($items) > ($this->listRows);
            if ($this->hasMore) {
                $this->nextItem = $items->slice($this->listRows, 1);
            }
            $items = $items->slice(0, $this->listRows);
        } else {
            $this->total       = $total;
            $this->lastPage    = (int) ceil($total / $listRows);
            $this->currentPage = $this->setCurrentPage($currentPage);
            $this->hasMore     = $this->currentPage < $this->lastPage;
        }
        $this->items = $items;
    }

    /* *
 * @param mixed $items Items to paginate
 * @param int $listRows Number of items per page
 * @param int|null $currentPage Current page number
 * @param int|null $total Total number of items
 * @param bool $simple Whether to use simple pagination
 * @param array $options Pagination options
 * @return Paginator */
    public static function make($items, $listRows, $currentPage = null, $total = null, $simple = false, $options = [])
    {
        return new static($items, $listRows, $currentPage, $total, $simple, $options);
    }

    protected function setCurrentPage($currentPage)
    {
        if (!$this->simple && $currentPage > $this->lastPage) {
            return $this->lastPage > 0 ? $this->lastPage : 1;
        }

        return $currentPage;
    }

    /* *
 * Get the link corresponding to the page number
 *
 * @param $page
 * @return string */
    protected function url($page)
    {
        if ($page <= 0) {
            $page = 1;
        }

        if (strpos($this->options['path'], '[PAGE]') === false) {
            $parameters = [$this->options['var_page'] => $page];
            $path       = $this->options['path'];
        } else {
            $parameters = [];
            $path       = str_replace('[PAGE]', $page, $this->options['path']);
        }
        if (count($this->options['query']) > 0) {
            $parameters = array_merge($this->options['query'], $parameters);
        }
        $url = $path;
        if (!empty($parameters)) {
            $url .= '?' . http_build_query($parameters, '', '&');
        }
        return $url . $this->buildFragment();
    }

    /* *
 * Get the current page number from the request, or use the default
 * @param string $varPage Request parameter containing the page number
 * @param int $default Fallback page number
 * @return int */
    public static function getCurrentPage($varPage = 'page', $default = 1)
    {
        $page = (int) Request::instance()->param($varPage);

        if (filter_var($page, FILTER_VALIDATE_INT) !== false && $page >= 1) {
            return $page;
        }

        return $default;
    }

    /* *
 * Get the base URL of the current request
 * @return string */
    public static function getCurrentPath()
    {
        return Request::instance()->baseUrl();
    }

    public function total()
    {
        if ($this->simple) {
            throw new \DomainException('not support total');
        }
        return $this->total;
    }

    public function listRows()
    {
        return $this->listRows;
    }

    public function currentPage()
    {
        return $this->currentPage;
    }

    public function lastPage()
    {
        if ($this->simple) {
            throw new \DomainException('not support last');
        }
        return $this->lastPage;
    }

    /* *
 * Check whether pagination links are needed
 * @return boolean */
    public function hasPages()
    {
        return !(1 == $this->currentPage && !$this->hasMore);
    }

    /* *
 * Create links for an inclusive range of page numbers
 *
 * @param int $start
 * @param int $end
 * @return array */
    public function getUrlRange($start, $end)
    {
        $urls = [];

        for ($page = $start; $page <= $end; $page++) {
            $urls[$page] = $this->url($page);
        }

        return $urls;
    }

    /* *
 * Set the fragment for generated URLs
 *
 * @param string|null $fragment
 * @return $this */
    public function fragment($fragment)
    {
        $this->options['fragment'] = $fragment;
        return $this;
    }

    /* *
 * Add query parameters, excluding the page-number parameter
 *
 * @param array|string $key
 * @param string|null $value
 * @return $this */
    public function appends($key, $value = null)
    {
        if (!is_array($key)) {
            $queries = [$key => $value];
        } else {
            $queries = $key;
        }

        foreach ($queries as $k => $v) {
            if ($k !== $this->options['var_page']) {
                $this->options['query'][$k] = $v;
            }
        }

        return $this;
    }

    /* *
 * Build the URL fragment, including its leading hash when present
 *
 * @return string */
    protected function buildFragment()
    {
        return $this->options['fragment'] ? '#' . $this->options['fragment'] : '';
    }

    /* *
 * Render pagination HTML
 * @return mixed */
    abstract public function render();

    public function items()
    {
        return $this->items->all();
    }

    public function getCollection()
    {
        return $this->items;
    }

    public function isEmpty()
    {
        return $this->items->isEmpty();
    }

    /* *
 * Run a callback for each item, stopping if it returns false
 *
 * @param callable $callback Receives the item and key; its result replaces non-object items
 * @return $this */
    public function each(callable $callback)
    {
        foreach ($this->items as $key => $item) {
            $result = $callback($item, $key);
            if (false === $result) {
                break;
            } elseif (!is_object($item)) {
                $this->items[$key] = $result;
            }
        }

        return $this;
    }

    /* *
 * Get an iterator over the current page items
 * @return Traversable An instance of an object implementing <b>Iterator</b> or
 * <b>Traversable</b> */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->items->all());
    }

    /* *
 * Check whether an item exists at the specified offset
 * @param mixed $offset
 * @return bool */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return $this->items->offsetExists($offset);
    }

    /* *
 * Get the item at the specified offset
 * @param mixed $offset
 * @return mixed */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->items->offsetGet($offset);
    }

    /* *
 * Set an item at the specified offset, or append it when the offset is null
 * @param mixed $offset
 * @param mixed $value */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        $this->items->offsetSet($offset, $value);
    }

    /* *
 * Remove the item at the specified offset
 * @param mixed $offset
 * @return void
 * @since 5.0.0 */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        $this->items->offsetUnset($offset);
    }

    /* *
 * Count the items on the current page */
    #[\ReturnTypeWillChange]
    public function count()
    {
        return $this->items->count();
    }

    public function __toString()
    {
        return (string) $this->render();
    }

    public function toArray()
    {
        if ($this->simple) {
            return [
                'per_page'     => $this->listRows,
                'current_page' => $this->currentPage,
                'has_more'     => $this->hasMore,
                'next_item'    => $this->nextItem,
                'data'         => $this->items->toArray(),
            ];
        } else {
            return [
                'total'        => $this->total,
                'per_page'     => $this->listRows,
                'current_page' => $this->currentPage,
                'last_page'    => $this->lastPage,
                'data'         => $this->items->toArray(),
            ];
        }

    }

    /* *
 * Specify data which should be serialized to JSON */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    public function __call($name, $arguments)
    {
        $collection = $this->getCollection();

        $result = call_user_func_array([$collection, $name], $arguments);

        if ($result === $collection) {
            return $this;
        }

        return $result;
    }

}
