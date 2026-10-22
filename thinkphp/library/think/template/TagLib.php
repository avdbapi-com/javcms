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

namespace think\template;

use think\Exception;

/* *
 * Base class for compiling ThinkPHP tag libraries
 * @category Think
 * @package Think
 * @subpackage Template
 * @author    liu21st <liu21st@gmail.com>
 */
class TagLib
{

    /* *
 * Tag library XML definition file
 * @var string
 * @access protected */
    protected $xml  = '';
    protected $tags = []; // Tag definitions
    /* *
 * Tag library name
 * @var string
 * @access protected */
    protected $tagLib = '';

    /* *
 * List of tags in the tag library
 * @var array
 * @access protected */
    protected $tagList = [];

    /* *
 * Tag library parsing data
 * @var array
 * @access protected */
    protected $parse = [];

    /* *
 * Whether the tag library is valid
 * @var bool
 * @access protected */
    protected $valid = false;

    /* *
 * Current template engine instance
 * @var object
 * @access protected */
    protected $tpl;

    protected $comparison = [' nheq ' => ' !== ', ' heq ' => ' === ', ' neq ' => ' != ', ' eq ' => ' == ', ' egt ' => ' >= ', ' gt ' => ' > ', ' elt ' => ' <= ', ' lt ' => ' < '];

    /* *
 * Initialize the tag library with a template engine
 * @access public
 * @param \think\Template $template Template engine instance */
    public function __construct($template)
    {
        $this->tpl = $template;
    }

    /* *
 * Compile template tags using this tag library
 * @access public
 * @param string $content template content
 * @param string $lib tag library name
 * @return void */
    public function parseTag(&$content, $lib = '')
    {
        $tags = [];
        $lib  = $lib ? strtolower($lib) . ':' : '';
        foreach ($this->tags as $name => $val) {
            $close                      = !isset($val['close']) || $val['close'] ? 1 : 0;
            $tags[$close][$lib . $name] = $name;
            if (isset($val['alias'])) {
                // Register tag aliases
                $array = (array) $val['alias'];
                foreach (explode(',', $array[0]) as $v) {
                    $tags[$close][$lib . $v] = $name;
                }
            }
        }

        // Compile tags with closing tags
        if (!empty($tags[1])) {
            $nodes = [];
            $regex = $this->getRegex(array_keys($tags[1]), 1);
            if (preg_match_all($regex, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                $right = [];
                foreach ($matches as $match) {
                    if ('' == $match[1][0]) {
                        $name = strtolower($match[2][0]);
                        // Match the closing tag to the most recent opening tag
                        if (!empty($right[$name])) {
                            // $match[0][1] is the position of the closing tag in the template
                            $nodes[$match[0][1]] = [
                                'name'  => $name,
                                'begin' => array_pop($right[$name]), // Opening tag
                                'end'   => $match[0], // Closing tag
                            ];
                        }
                    } else {
                        // Push the opening tag onto the stack
                        $right[strtolower($match[1][0])][] = $match[0];
                    }
                }
                unset($right, $matches);
                // Sort tags by descending template position
                krsort($nodes);
            }

            $break = '<!--###break###--!>';
            if ($nodes) {
                $beginArray = [];
                // Replace tags from the end of the template toward the beginning
                foreach ($nodes as $pos => $node) {
                    // Corresponding tag name
                    $name  = $tags[1][$node['name']];
                    $alias = $lib . $name != $node['name'] ? ($lib ? strstr($node['name'], $lib) : $node['name']) : '';
                    // Parse tag attributes
                    $attrs  = $this->parseAttr($node['begin'][0], $name, $alias);
                    $method = 'tag' . $name;
                    // Split the compiled tag into opening and closing replacements
                    $replace = explode($break, $this->$method($attrs, $break));
                    if (count($replace) > 1) {
                        while ($beginArray) {
                            $begin = end($beginArray);
                            // Leave a pending opening tag in the stack while processing its nested tags
                            if ($node['end'][1] > $begin['pos']) {
                                break;
                            } else {
                                // Pop an opening tag whose nested tags have been processed
                                $begin = array_pop($beginArray);
                                // Replace the opening tag
                                $content = substr_replace($content, $begin['str'], $begin['pos'], $begin['len']);
                            }
                        }
                        // Replace the closing tag
                        $content = substr_replace($content, $replace[1], $node['end'][1], strlen($node['end'][0]));
                        // Defer the opening-tag replacement until nested tags are processed
                        $beginArray[] = ['pos' => $node['begin'][1], 'len' => strlen($node['begin'][0]), 'str' => $replace[0]];
                    }
                }
                while ($beginArray) {
                    $begin = array_pop($beginArray);
                    // Replace the opening tag
                    $content = substr_replace($content, $begin['str'], $begin['pos'], $begin['len']);
                }
            }
        }
        // Compile self-closing tags
        if (!empty($tags[0])) {
            $regex   = $this->getRegex(array_keys($tags[0]), 0);
            $content = preg_replace_callback($regex, function ($matches) use (&$tags, &$lib) {
                // Corresponding tag name
                $name  = $tags[0][strtolower($matches[1])];
                $alias = $lib . $name != $matches[1] ? ($lib ? strstr($matches[1], $lib) : $matches[1]) : '';
                // Parse tag attributes
                $attrs  = $this->parseAttr($matches[0], $name, $alias);
                $method = 'tag' . $name;
                return $this->$method($attrs, '');
            }, $content);
        }
        return;
    }

    /* *
 * Build a regular expression for the specified tags
 * @access public
 * @param array|string $tags Tag names
 * @param boolean $close Whether the tags have closing tags
 * @return string */
    public function getRegex($tags, $close)
    {
        $begin   = $this->tpl->config('taglib_begin');
        $end     = $this->tpl->config('taglib_end');
        $single  = strlen(ltrim($begin, '\\')) == 1 && strlen(ltrim($end, '\\')) == 1 ? true : false;
        $tagName = is_array($tags) ? implode('|', $tags) : $tags;
        if ($single) {
            if ($close) {
                // Match opening and closing tags
                $regex = $begin . '(?:(' . $tagName . ')\b(?>[^' . $end . ']*)|\/(' . $tagName . '))' . $end;
            } else {
                $regex = $begin . '(' . $tagName . ')\b(?>[^' . $end . ']*)' . $end;
            }
        } else {
            if ($close) {
                // Match opening and closing tags
                $regex = $begin . '(?:(' . $tagName . ')\b(?>(?:(?!' . $end . ').)*)|\/(' . $tagName . '))' . $end;
            } else {
                $regex = $begin . '(' . $tagName . ')\b(?>(?:(?!' . $end . ').)*)' . $end;
            }
        }
        return '/' . $regex . '/is';
    }

    /* *
 * Parse tag attributes with a regular expression
 * @access public
 * @param string $str tag attribute string
 * @param string $name tag name
 * @param string $alias alias
 * @return array */
    public function parseAttr($str, $name, $alias = '')
    {
        $regex  = '/\s+(?>(?P<name>[\w-]+)\s*)=(?>\s*)([\"\'])(?P<value>(?:(?!\\2).)*)\\2/is';
        $result = [];
        if (preg_match_all($regex, $str, $matches)) {
            foreach ($matches['name'] as $key => $val) {
                $result[$val] = $matches['value'][$key];
            }
            if (!isset($this->tags[$name])) {
                // Check if an alias definition exists
                foreach ($this->tags as $key => $val) {
                    if (isset($val['alias'])) {
                        $array = (array) $val['alias'];
                        if (in_array($name, explode(',', $array[0]))) {
                            $tag           = $val;
                            $type          = !empty($array[1]) ? $array[1] : 'type';
                            $result[$type] = $name;
                            break;
                        }
                    }
                }
            } else {
                $tag = $this->tags[$name];
                // A tag alias has been set
                if (!empty($alias) && isset($tag['alias'])) {
                    $type          = !empty($tag['alias'][1]) ? $tag['alias'][1] : 'type';
                    $result[$type] = $alias;
                }
            }
            if (!empty($tag['must'])) {
                $must = explode(',', $tag['must']);
                foreach ($must as $name) {
                    if (!isset($result[$name])) {
                        throw new Exception('tag attr must:' . $name);
                    }
                }
            }
        } else {
            // Handle tags that accept expressions directly
            if (!empty($this->tags[$name]['expression'])) {
                static $_taglibs;
                if (!isset($_taglibs[$name])) {
                    $_taglibs[$name][0] = strlen($this->tpl->config('taglib_begin_origin') . $name);
                    $_taglibs[$name][1] = strlen($this->tpl->config('taglib_end_origin'));
                }
                $result['expression'] = substr($str, $_taglibs[$name][0], -$_taglibs[$name][1]);
                // Remove the trailing slash from a self-closing tag
                $result['expression'] = rtrim($result['expression'], '/');
                $result['expression'] = trim($result['expression']);
            } elseif (empty($this->tags[$name]) || !empty($this->tags[$name]['attr'])) {
                throw new Exception('tag error:' . $name);
            }
        }
        return $result;
    }

    /* *
 * Parse conditional expressions
 * @access public
 * @param string $condition expression tag content
 * @return string */
    public function parseCondition($condition)
    {
        if (strpos($condition, ':')) {
            $condition = ' ' . substr(strstr($condition, ':'), 1);
        }
        $condition = str_ireplace(array_keys($this->comparison), array_values($this->comparison), $condition);
        $this->tpl->parseVar($condition);
        // $this->tpl->parsevarFunction ($condition);//XXX: This call parses pipe-separated filters, but conflicts with | and || operators in expressions
        return $condition;
    }

    /* *
 * Normalize a variable or function expression
 * @access public
 * @param string $name Variable or function expression
 * @return string */
    public function autoBuildVar(&$name)
    {
        $flag = substr($name, 0, 1);
        if (':' == $flag) {
            // Remove the leading : from a function expression before parsing
            $name = substr($name, 1);
        } elseif ('$' != $flag && preg_match('/[a-zA-Z_]/', $flag)) {
            // XXX: This variable-name check may need improvement
            // Constants do not require parsing
            if (defined($name)) {
                return $name;
            }
            // Prefix a variable name with $ when it is not a constant
            $name = '$' . $name;
        }
        $this->tpl->parseVar($name);
        $this->tpl->parseVarFunction($name);
        return $name;
    }

    /* *
 * Get a list of tags
 * @access public
 * @return array */
    // Get tag definitions
    public function getTags()
    {
        return $this->tags;
    }
}
