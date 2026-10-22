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

class Hook
{
    /* *
 * @var array Behaviors registered for each tag */
    private static $tags = [];

    /* *
 * Register one or more behaviors for a tag
 * @access public
 * @param string $tag tag name
 * @param mixed $behavior Behavior or list of behaviors to register
 * @param bool $first Prepend a single behavior to the tag
 * @return void */
    public static function add($tag, $behavior, $first = false)
    {
        isset(self::$tags[$tag]) || self::$tags[$tag] = [];

        if (is_array($behavior) && !is_callable($behavior)) {
            if (!array_key_exists('_overlay', $behavior) || !$behavior['_overlay']) {
                unset($behavior['_overlay']);
                self::$tags[$tag] = array_merge(self::$tags[$tag], $behavior);
            } else {
                unset($behavior['_overlay']);
                self::$tags[$tag] = $behavior;
            }
        } elseif ($first) {
            array_unshift(self::$tags[$tag], $behavior);
        } else {
            self::$tags[$tag][] = $behavior;
        }
    }

    /* *
 * Import behavior registrations for multiple tags
 * @access public
 * @param array $tags Behaviors grouped by tag
 * @param boolean $recursive Whether to merge behaviors into existing tags
 * @return void */
    public static function import(array $tags, $recursive = true)
    {
        if ($recursive) {
            foreach ($tags as $tag => $behavior) {
                self::add($tag, $behavior);
            }
        } else {
            self::$tags = $tags + self::$tags;
        }
    }

    /* *
 * Get registered behaviors
 * @access public
 * @param string $tag Tag name; leave empty to get all tags
 * @return array */
    public static function get($tag = '')
    {
        if (empty($tag)) {
            return self::$tags;
        }

        return array_key_exists($tag, self::$tags) ? self::$tags[$tag] : [];
    }

    /* *
 * Execute the behaviors registered for a tag
 * @access public
 * @param string $tag tag name
 * @param mixed $params Incoming parameters
 * @param mixed $extra Additional parameters
 * @param bool $once Stop after the first non-null result
 * @return mixed */
    public static function listen($tag, &$params = null, $extra = null, $once = false)
    {
        $results = [];

        foreach (static::get($tag) as $key => $name) {
            $results[$key] = self::exec($name, $tag, $params, $extra);

            // Stop on false, or on the first non-null result when $once is true.
            if (false === $results[$key] || (!is_null($results[$key]) && $once)) {
                break;
            }
        }

        return $once ? end($results) : $results;
    }

    /* *
 * Execute a behavior
 * @access public
 * @param mixed $class The behavior to perform
 * @param string $tag method name (tag name)
 * @param mixed $params Parameters passed to the behavior
 * @param mixed $extra Additional parameters
 * @return mixed */
    public static function exec($class, $tag = '', &$params = null, $extra = null)
    {
        App::$debug && Debug::remark('behavior_start', 'time');

        $method = Loader::parseName($tag, 1, false);

        if ($class instanceof \Closure) {
            $result = call_user_func_array($class, [ & $params, $extra]);
            $class  = 'Closure';
        } elseif (is_array($class)) {
            list($class, $method) = $class;

            $result = (new $class())->$method($params, $extra);
            $class  = $class . '->' . $method;
        } elseif (is_object($class)) {
            $result = $class->$method($params, $extra);
            $class  = get_class($class);
        } elseif (strpos($class, '::')) {
            $result = call_user_func_array($class, [ & $params, $extra]);
        } else {
            $obj    = new $class();
            $method = ($tag && is_callable([$obj, $method])) ? $method : 'run';
            $result = $obj->$method($params, $extra);
        }

        if (App::$debug) {
            Debug::remark('behavior_end', 'time');
            Log::record('[ BEHAVIOR ] Run ' . $class . ' @' . $tag . ' [ RunTime:' . Debug::getRangeTime('behavior_start', 'behavior_end') . 's ]', 'info');
        }

        return $result;
    }

}
