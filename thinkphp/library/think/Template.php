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

use think\exception\TemplateNotFoundException;
use think\template\TagLib;

/* *
 * Standalone ThinkPHP template engine
 * Supports template parsing for XML tags and normal tags
 * Compiles templates and supports caching compiled templates and rendered output */
class Template
{
    // template variables
    protected $data = [];
    // engine configuration
    protected $config = [
        'view_path'          => '', // template path
        'view_base'          => '',
        'view_suffix'        => 'html', // Default template file suffix
        'view_depr'          => DS,
        'cache_suffix'       => 'php', // Default template cache suffix
        'tpl_deny_func_list' => 'echo,exit', // Functions prohibited in template variable filters
        'tpl_deny_php'       => false, // Whether to reject native PHP code in templates
        'tpl_begin'          => '{', // Opening delimiter for ordinary template tags
        'tpl_end'            => '}', // Closing delimiter for ordinary template tags
        'strip_space'        => false, // Whether to remove whitespace between HTML tags and after tags at line endings
        'tpl_cache'          => true, // Whether to enable the template compilation cache. If set to false, it will be recompiled every time
        'compile_type'       => 'file', // Template compilation type
        'cache_prefix'       => '', // Prefix for compiled-template cache filenames
        'cache_time'         => 0, // Cache lifetime in seconds; 0 means no expiration
        'layout_on'          => false, // Whether to use a layout template
        'layout_name'        => 'layout', // Layout template entry file
        'layout_item'        => '{__CONTENT__}', // Placeholder replaced by template content in the layout
        'taglib_begin'       => '{', // Opening delimiter for tag-library tags
        'taglib_end'         => '}', // Closing delimiter for tag-library tags
        'taglib_load'        => true, // Whether to detect tag-library declarations in templates
        'taglib_build_in'    => 'cx', // Comma-separated built-in tag libraries, parsed in order without a library prefix
        'taglib_pre_load'    => '', // Comma-separated tag libraries loaded automatically; their tags require a library prefix
        'display_cache'      => false, // Whether to cache rendered output
        'cache_id'           => '', // Template cache ID
        'tpl_replace_string' => [],
        'tpl_var_identify'   => 'array', // Dotted variable access: array, obj or automatic detection for other values
    ];

    private $literal     = [];
    private $includeFile = []; // Referenced template paths and modification times
    protected $storage;

    /* *
 * Initialize the template engine configuration and storage driver
 * @access public
 * @param array $config */
    public function __construct(array $config = [])
    {
        $this->config['cache_path'] = TEMP_PATH;
        $this->config               = array_merge($this->config, $config);

        $this->config['taglib_begin_origin'] = $this->config['taglib_begin'];
        $this->config['taglib_end_origin']   = $this->config['taglib_end'];

        $this->config['taglib_begin'] = preg_quote($this->config['taglib_begin'], '/');
        $this->config['taglib_end']   = preg_quote($this->config['taglib_end'], '/');
        $this->config['tpl_begin']    = preg_quote($this->config['tpl_begin'], '/');
        $this->config['tpl_end']      = preg_quote($this->config['tpl_end'], '/');

        // Initialize the compiled-template storage driver
        $type          = $this->config['compile_type'] ? $this->config['compile_type'] : 'File';
        $class         = false !== strpos($type, '\\') ? $type : '\\think\\template\\driver\\' . ucwords($type);
        $this->storage = new $class();
    }

    /* *
 * Assign template variables
 * @access public
 * @param mixed $name
 * @param mixed $value
 * @return void */
    public function assign($name, $value = '')
    {
        if (is_array($name)) {
            $this->data = array_merge($this->data, $name);
        } else {
            $this->data[$name] = $value;
        }
    }

    /* *
 * Set a template engine configuration value
 * @access public
 * @param mixed $name
 * @param mixed $value */
    public function __set($name, $value)
    {
        $this->config[$name] = $value;
    }

    /* *
 * Set configuration values or get one configuration value
 * @access public
 * @param array|string $config
 * @return mixed */
    public function config($config)
    {
        if (is_array($config)) {
            $this->config = array_merge($this->config, $config);
        } elseif (isset($this->config[$config])) {
            return $this->config[$config];
        } else {
            return;
        }
    }

    /* *
 * Get template variables
 * @access public
 * @param string $name variable name
 * @return mixed */
    public function get($name = '')
    {
        if ('' == $name) {
            return $this->data;
        } else {
            $data = $this->data;
            foreach (explode('.', $name) as $key => $val) {
                if (isset($data[$val])) {
                    $data = $data[$val];
                } else {
                    $data = null;
                    break;
                }
            }
            return $data;
        }
    }

    /* *
 * Render template files
 * @access public
 * @param string $template template file
 * @param array $vars template variables
 * @param array $config Template engine configuration overrides
 * @return void */
    public function fetch($template, $vars = [], $config = [])
    {
        if ($vars) {
            $this->data = $vars;
        }
        if ($config) {
            $this->config($config);
        }
        if (!empty($this->config['cache_id']) && $this->config['display_cache']) {
            // Read the rendering cache
            $cacheContent = Cache::get($this->config['cache_id']);
            if (false !== $cacheContent) {
                echo $cacheContent;
                return;
            }
        }
        $template = $this->parseTemplateFile($template);
        if ($template) {
            $cacheFile = $this->config['cache_path'] . $this->config['cache_prefix'] . md5($this->config['layout_name'] . $template) . '.' . ltrim($this->config['cache_suffix'], '.');
            if (!$this->checkCache($cacheFile)) {
                // Recompile the template when the compiled cache is invalid
                $content = file_get_contents($template);
                $this->compiler($content, $cacheFile);
            }
            // Capture the rendered output
            ob_start();
            ob_implicit_flush(0);
            // Render the compiled template using the assigned variables
            $this->storage->read($cacheFile, $this->data);
            // Retrieve the rendered output and close the output buffer
            $content = ob_get_clean();
            if (!empty($this->config['cache_id']) && $this->config['display_cache']) {
                // Cache the rendered output
                Cache::set($this->config['cache_id'], $content, $this->config['cache_time']);
            }
            echo $content;
        }
    }

    /* *
 * Render template content
 * @access public
 * @param string $content template content
 * @param array $vars template variables
 * @param array $config Template engine configuration overrides
 * @return void */
    public function display($content, $vars = [], $config = [])
    {
        if ($vars) {
            $this->data = $vars;
        }
        if ($config) {
            $this->config($config);
        }
        $cacheFile = $this->config['cache_path'] . $this->config['cache_prefix'] . md5($content) . '.' . ltrim($this->config['cache_suffix'], '.');
        if (!$this->checkCache($cacheFile)) {
            // Compile the template content when the compiled cache is invalid
            $this->compiler($content, $cacheFile);
        }
        // Render the compiled template using the assigned variables
        $this->storage->read($cacheFile, $this->data);
    }

    /* *
 * Set the layout
 * @access public
 * @param mixed $name Layout template name; false disables the layout
 * @param string $replace Placeholder replaced by template content
 * @return Template */
    public function layout($name, $replace = '')
    {
        if (false === $name) {
            // Disable the layout
            $this->config['layout_on'] = false;
        } else {
            // Enable the layout
            $this->config['layout_on'] = true;
            // The name must be a string
            if (is_string($name)) {
                $this->config['layout_name'] = $name;
            }
            if (!empty($replace)) {
                $this->config['layout_item'] = $replace;
            }
        }
        return $this;
    }

    /* *
 * Check if the compilation cache is valid
 * If not valid, recompilation is required
 * @access private
 * @param string $cacheFile cache file name
 * @return boolean */
    private function checkCache($cacheFile)
    {
        // Caching is not enabled
        if (!$this->config['tpl_cache']) {
            return false;
        }
        // The cache file does not exist
        if (!is_file($cacheFile)) {
            return false;
        }
        // Reading the cache file failed
        if (!$handle = @fopen($cacheFile, "r")) {
            return false;
        }
        // Read the first line
        preg_match('/\/\*(.+?)\*\//', fgets($handle), $matches);
        if (!isset($matches[1])) {
            return false;
        }
        $includeFile = unserialize($matches[1]);
        if (!is_array($includeFile)) {
            return false;
        }
        // Check the template files for updates
        foreach ($includeFile as $path => $time) {
            if (is_file($path) && filemtime($path) > $time) {
                // If the template file is updated, the cache needs to be updated
                return false;
            }
        }
        // Check whether the compilation storage is valid
        return $this->storage->check($cacheFile, $this->config['cache_time']);
    }

    /* *
 * Check whether cached rendered output exists
 * @access public
 * @param string $cacheId The id of the cache
 * @return boolean */
    public function isCache($cacheId)
    {
        if ($cacheId && $this->config['display_cache']) {
            // Check the rendered-output cache
            return Cache::has($cacheId);
        }
        return false;
    }

    /* *
 * Compile the contents of the template file
 * @access private
 * @param string $content template content
 * @param string $cacheFile cache file name
 * @return void */
    private function compiler(&$content, $cacheFile)
    {
        // Determine whether the layout is enabled
        if ($this->config['layout_on']) {
            if (false !== strpos($content, '{__NOLAYOUT__}')) {
                // Allow this template to opt out of the layout
                $content = str_replace('{__NOLAYOUT__}', '', $content);
            } else {
                // Read layout templates
                $layoutFile = $this->parseTemplateFile($this->config['layout_name']);
                if ($layoutFile) {
                    // Insert template content into the layout placeholder
                    $content = str_replace($this->config['layout_item'], $content, file_get_contents($layoutFile));
                }
            }
        } else {
            $content = str_replace('{__NOLAYOUT__}', '', $content);
        }

        // Parse the template content
        $this->parse($content);
        if ($this->config['strip_space']) {
            /* Remove whitespace between HTML tags and after tags at line endings */
            $find    = ['~>\s+<~', '~>(\s+\n|\r)~'];
            $replace = ['><', '>'];
            $content = preg_replace($find, $replace, $content);
        }
        // Optimize the generated php code
        $content = preg_replace('/\?>\s*<\?php\s(?!echo\b)/s', '', $content);
        // Apply configured string replacements
        $replace = $this->config['tpl_replace_string'];
        $content = str_replace(array_keys($replace), array_values($replace), $content);
        // Add the direct-access guard and referenced-template metadata
        $content = '<?php if (!defined(\'THINK_PATH\')) exit(); /*' . serialize($this->includeFile) . '*/ ?>' . "\n" . $content;
        // Store the compiled template
        $this->storage->write($cacheFile, $content);
        $this->includeFile = [];
        return;
    }

    /* *
 * Parse template content
 * Supports ordinary tags and custom tag libraries
 * @access public
 * @param string $content The template content to parse
 * @return void */
    public function parse(&$content)
    {
        // Skip empty content
        if (empty($content)) {
            return;
        }
        // Protect literal-tag content from parsing
        $this->parseLiteral($content);
        // Parse template inheritance
        $this->parseExtend($content);
        // Parse layout tags
        $this->parseLayout($content);
        // Check include syntax
        $this->parseInclude($content);
        // Protect literal-tag content introduced by included templates
        $this->parseLiteral($content);
        // Normalize PHP opening tags and enforce the native-PHP restriction
        $this->parsePhp($content);

        // Find tag libraries declared in the template
        // One declaration can list multiple tag libraries
        // Usually placed at the top of the file
        // Mode：<taglib name=\"html,mytag...\" />
        // Detect declarations only when taglib_load is enabled
        if ($this->config['taglib_load']) {
            $tagLibs = $this->getIncludeTagLib($content);
            if (!empty($tagLibs)) {
                // Parse each declared tag library
                foreach ($tagLibs as $tagLibName) {
                    $this->parseTagLib($tagLibName, $content);
                }
            }
        }
        // Preloaded libraries need no declaration, but their tags still require a library prefix
        if ($this->config['taglib_pre_load']) {
            $tagLibs = explode(',', $this->config['taglib_pre_load']);
            foreach ($tagLibs as $tag) {
                $this->parseTagLib($tag, $content);
            }
        }
        // The built-in tag library can be used without taglib tag import and does not require the tag library XML prefix
        $tagLibs = explode(',', $this->config['taglib_build_in']);
        foreach ($tagLibs as $tag) {
            $this->parseTagLib($tag, $content, true);
        }
        // Parse plain template tag {$tagName}
        $this->parseTag($content);

        // Restore protected literal-tag content
        $this->parseLiteral($content, true);
        return;
    }

    /* *
 * Normalize PHP opening tags and enforce the native-PHP restriction
 * @access private
 * @param string $content The template content to parse
 * @return void
 * @throws \think\Exception */
    private function parsePhp(&$content)
    {
        // Output non-PHP opening sequences such as XML declarations as literal text
        $content = preg_replace('/(<\?(?!php|=|$))/i', '<?php echo \'\\1\'; ?>' . "\n", $content);
        // Reject native PHP when tpl_deny_php is enabled
        if ($this->config['tpl_deny_php'] && false !== strpos($content, '<?php')) {
            throw new Exception('not allow php tag', 11600);
        }
        return;
    }

    /* *
 * Parse layout tags in templates
 * @access private
 * @param string $content The template content to parse
 * @return void */
    private function parseLayout(&$content)
    {
        // Read layout tags in a template
        if (preg_match($this->getRegex('layout'), $content, $matches)) {
            // Remove the layout declaration
            $content = str_replace($matches[0], '', $content);
            // Parse layout tags
            $array = $this->parseAttr($matches[0]);
            if (!$this->config['layout_on'] || $this->config['layout_name'] != $array['name']) {
                // Read layout templates
                $layoutFile = $this->parseTemplateFile($array['name']);
                if ($layoutFile) {
                    $replace = isset($array['replace']) ? $array['replace'] : $this->config['layout_item'];
                    // Insert template content into the layout placeholder
                    $content = str_replace($replace, $content, file_get_contents($layoutFile));
                }
            }
        } else {
            $content = str_replace('{__NOLAYOUT__}', '', $content);
        }
        return;
    }

    /* *
 * Parse the include tag in the template
 * @access private
 * @param string $content The template content to parse
 * @return void */
    private function parseInclude(&$content)
    {
        $regex = $this->getRegex('include');
        $func  = function ($template) use (&$func, &$regex, &$content) {
            if (preg_match_all($regex, $template, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $array = $this->parseAttr($match[0]);
                    $file  = $array['file'];
                    unset($array['file']);
                    // Parse the template file name and read the content
                    $parseStr = $this->parseTemplateName($file);
                    foreach ($array as $k => $v) {
                        // Converts a string starting with $ to a template variable
                        if (0 === strpos($v, '$')) {
                            $v = $this->get(substr($v, 1));
                        }
                        $parseStr = str_replace('[' . $k . ']', $v, $parseStr);
                    }
                    $content = str_replace($match[0], $parseStr, $content);
                    // Recursively expand includes introduced by this template
                    $func($parseStr);
                }
                unset($matches);
            }
        };
        // Replace include tags in templates
        $func($content);
        return;
    }

    /* *
 * Parse the extend tag in the template
 * @access private
 * @param string $content The template content to parse
 * @return void */
    private function parseExtend(&$content)
    {
        $regex  = $this->getRegex('extend');
        $array  = $blocks  = $baseBlocks  = [];
        $extend = '';
        $func   = function ($template) use (&$func, &$regex, &$array, &$extend, &$blocks, &$baseBlocks) {
            if (preg_match($regex, $template, $matches)) {
                if (!isset($array[$matches['name']])) {
                    $array[$matches['name']] = 1;
                    // Read the parent template
                    $extend = $this->parseTemplateName($matches['name']);
                    // Check inheritance recursively
                    $func($extend);
                    // Get block tag content
                    $blocks = array_merge($blocks, $this->parseBlock($template));
                    return;
                }
            } else {
                // Get the top-level template block tag content
                $baseBlocks = $this->parseBlock($template, true);
                if (empty($extend)) {
                    // Use the template itself when it has blocks but no extend tag
                    $extend = $template;
                }
            }
        };

        $func($content);
        if (!empty($extend)) {
            if ($baseBlocks) {
                $children = [];
                foreach ($baseBlocks as $name => $val) {
                    $replace = $val['content'];
                    if (!empty($children[$name])) {
                        // Replace nested blocks
                        foreach ($children[$name] as $key) {
                            $replace = str_replace($baseBlocks[$key]['begin'] . $baseBlocks[$key]['content'] . $baseBlocks[$key]['end'], $blocks[$key]['content'], $replace);
                        }
                    }
                    if (isset($blocks[$name])) {
                        // Replace {__BLOCK__} or {__block__} in the child block with the parent block content
                        $replace = str_replace(['{__BLOCK__}', '{__block__}'], $replace, $blocks[$name]['content']);
                        if (!empty($val['parent'])) {
                            // Propagate a nested block replacement into its parent block
                            $parent = $val['parent'];
                            if (isset($blocks[$parent])) {
                                $blocks[$parent]['content'] = str_replace($blocks[$name]['begin'] . $blocks[$name]['content'] . $blocks[$name]['end'], $replace, $blocks[$parent]['content']);
                            }
                            $blocks[$name]['content'] = $replace;
                            $children[$parent][]      = $name;
                            continue;
                        }
                    } elseif (!empty($val['parent'])) {
                        // Preserve a nested block when the child template does not override it
                        $children[$val['parent']][] = $name;
                        $blocks[$name]              = $val;
                    }
                    if (!$val['parent']) {
                        // Replace the top-level block tag in the template
                        $extend = str_replace($val['begin'] . $val['content'] . $val['end'], $replace, $extend);
                    }
                }
            }
            $content = $extend;
            unset($blocks, $baseBlocks);
        }
        return;
    }

    /* *
 * Protect or restore literal-tag content
 * @access private
 * @param string $content template content
 * @param boolean $restore Whether to restore previously protected content
 * @return void */
    private function parseLiteral(&$content, $restore = false)
    {
        $regex = $this->getRegex($restore ? 'restoreliteral' : 'literal');
        if (preg_match_all($regex, $content, $matches, PREG_SET_ORDER)) {
            if (!$restore) {
                $count = count($this->literal);
                // Replace literal-tag content with placeholders
                foreach ($matches as $match) {
                    $this->literal[] = substr($match[0], strlen($match[1]), -strlen($match[2]));
                    $content         = str_replace($match[0], "<!--###literal{$count}###-->", $content);
                    $count++;
                }
            } else {
                // Restore literal-tag content from placeholders
                foreach ($matches as $match) {
                    $content = str_replace($match[0], $this->literal[$match[1]], $content);
                }
                // Clear the literal record
                $this->literal = [];
            }
            unset($matches);
        }
        return;
    }

    /* *
 * Extract block tags and their content from the template
 * @access private
 * @param string $content template content
 * @param boolean $sort Whether to sort blocks by their closing-tag positions
 * @return array */
    private function parseBlock(&$content, $sort = false)
    {
        $regex  = $this->getRegex('block');
        $result = [];
        if (preg_match_all($regex, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $right = $keys = [];
            foreach ($matches as $match) {
                if (empty($match['name'][0])) {
                    if (count($right) > 0) {
                        $tag                  = array_pop($right);
                        $start                = $tag['offset'] + strlen($tag['tag']);
                        $length               = $match[0][1] - $start;
                        $result[$tag['name']] = [
                            'begin'   => $tag['tag'],
                            'content' => substr($content, $start, $length),
                            'end'     => $match[0][0],
                            'parent'  => count($right) ? end($right)['name'] : '',
                        ];
                        $keys[$tag['name']] = $match[0][1];
                    }
                } else {
                    // Push the opening block tag onto the stack
                    $right[] = [
                        'name'   => $match[2][0],
                        'offset' => $match[0][1],
                        'tag'    => $match[0][0],
                    ];
                }
            }
            unset($right, $matches);
            if ($sort) {
                // Sort by the position of the block tag end mark in the template
                array_multisort($keys, $result);
            }
        }
        return $result;
    }

    /* *
 * Find the tag-library declaration in the template
 * Remove the declaration and return its library names
 * @access private
 * @param string $content template content
 * @return array|null */
    private function getIncludeTagLib(&$content)
    {
        // Search for TagLib tags
        if (preg_match($this->getRegex('taglib'), $content, $matches)) {
            // Remove the tag-library declaration
            $content = str_replace($matches[0], '', $content);
            return explode(',', $matches['name']);
        }
        return;
    }

    /* *
 * Parse tags belonging to a tag library
 * @access public
 * @param string $tagLib tag library to parse
 * @param string $content The template content to parse
 * @param boolean $hide Whether to hide the tag library prefix
 * @return void */
    public function parseTagLib($tagLib, &$content, $hide = false)
    {
        if (false !== strpos($tagLib, '\\')) {
            // Support for specifying a namespace for a tag library
            $className = $tagLib;
            $tagLib    = substr($tagLib, strrpos($tagLib, '\\') + 1);
        } else {
            $className = '\\think\\template\\taglib\\' . ucwords($tagLib);
        }
        /* * @var TagLib $tLib */
        $tLib = new $className($this);
        $tLib->parseTag($content, $hide ? '' : $tagLib);
        return;
    }

    /* *
 * Parse tag attributes
 * @access public
 * @param string $str attribute string
 * @param string $name Attribute name to retrieve when present
 * @return array|string */
    public function parseAttr($str, $name = null)
    {
        $regex = '/\s+(?>(?P<name>[\w-]+)\s*)=(?>\s*)([\"\'])(?P<value>(?:(?!\\2).)*)\\2/is';
        $array = [];
        if (preg_match_all($regex, $str, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $array[$match['name']] = $match['value'];
            }
            unset($matches);
        }
        if (!empty($name) && isset($array[$name])) {
            return $array[$name];
        } else {
            return $array;
        }
    }

    /* *
 * Compile ordinary template tags
 * Format: {tagName: args [|content]}
 * @access private
 * @param string $content The template content to parse
 * @return void */
    private function parseTag(&$content)
    {
        $regex = $this->getRegex('tag');
        if (preg_match_all($regex, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $str  = stripslashes($match[1]);
                $flag = substr($str, 0, 1);
                switch ($flag) {
                    case '$':
                        // Parse template variable format {$varName}
                        // Parse a conditional expression when the variable tag contains ?
                        if (false !== $pos = strpos($str, '?')) {
                            $array = preg_split('/([!=]={1,2}|(?<!-)[><]={0,1})/', substr($str, 0, $pos), 2, PREG_SPLIT_DELIM_CAPTURE);
                            $name  = $array[0];
                            $this->parseVar($name);
                            $this->parseVarFunction($name);

                            $str = trim(substr($str, $pos + 1));
                            $this->parseVar($str);
                            $first = substr($str, 0, 1);
                            if (strpos($name, ')')) {
                                // The compiled variable expression contains parentheses
                                if (isset($array[1])) {
                                    $this->parseVar($array[2]);
                                    $name .= $array[1] . $array[2];
                                }
                                switch ($first) {
                                    case '?':
                                        $str = '<?php echo (' . $name . ') ? ' . $name . ' : ' . substr($str, 1) . '; ?>';
                                        break;
                                    case '=':
                                        $str = '<?php if(' . $name . ') echo ' . substr($str, 1) . '; ?>';
                                        break;
                                    default:
                                        $str = '<?php echo ' . $name . '?' . $str . '; ?>';
                                }
                            } else {
                                if (isset($array[1])) {
                                    $this->parseVar($array[2]);
                                    $express = $name . $array[1] . $array[2];
                                } else {
                                    $express = false;
                                }
                                // Handle a variable expression without parentheses
                                switch ($first) {
                                    case '?':
                                        // {$varname?? \' xxx\'} Output $varname when set; otherwise output xxx.
                                        $str = '<?php echo ' . ($express ?: 'isset(' . $name . ')') . '?' . $name . ':' . substr($str, 1) . '; ?>';
                                        break;
                                    case '=':
                                        // {$varname? =\'xxx\'} Output xxx when $varname is nonempty.
                                        $str = '<?php if(' . ($express ?: '!empty(' . $name . ')') . ') echo ' . substr($str, 1) . '; ?>';
                                        break;
                                    case ':':
                                        // {$varname? :\ 'xxx\ '} Output $varname when nonempty; otherwise output xxx.
                                        $str = '<?php echo ' . ($express ?: '!empty(' . $name . ')') . '?' . $name . $str . '; ?>';
                                        break;
                                    default:
                                        $str = '<?php echo ' . ($express ?: '!empty(' . $name . ')') . '?' . $str . '; ?>';
                                }
                            }
                        } else {
                            $this->parseVar($str);
                            $this->parseVarFunction($str);
                            $str = '<?php echo ' . $str . '; ?>';
                        }
                        break;
                    case ':':
                        // Outputs the result of a function
                        $str = substr($str, 1);
                        $this->parseVar($str);
                        $str = '<?php echo ' . $str . '; ?>';
                        break;
                    case '~':
                        // Execute a function
                        $str = substr($str, 1);
                        $this->parseVar($str);
                        $str = '<?php ' . $str . '; ?>';
                        break;
                    case '-':
                    case '+':
                        // Output an increment or decrement expression
                        $this->parseVar($str);
                        $str = '<?php echo ' . $str . '; ?>';
                        break;
                    case '/':
                        // Remove template comment tags
                        $flag2 = substr($str, 1, 1);
                        if ('/' == $flag2 || ('*' == $flag2 && substr(rtrim($str), -2) == '*/')) {
                            $str = '';
                        }
                        break;
                    default:
                        // Unrecognized tags are returned directly
                        $str = $this->config['tpl_begin'] . $str . $this->config['tpl_end'];
                        break;
                }
                $content = str_replace($match[0], $str, $content);
            }
            unset($matches);
        }
        return;
    }

    /* *
 * Compile dotted and colon-separated template variable access
 * Format: {$varname|function1|function2=arg1, arg2}
 * @access public
 * @param string $varStr Expression containing template variables
 * @return void */
    public function parseVar(&$varStr)
    {
        $varStr = trim($varStr);
        if (preg_match_all('/\$[a-zA-Z_](?>\w*)(?:[:\.][0-9a-zA-Z_](?>\w*))+/', $varStr, $matches, PREG_OFFSET_CAPTURE)) {
            static $_varParseList = [];
            while ($matches[0]) {
                $match = array_pop($matches[0]);
                // Reuse the compiled expression for a previously parsed variable
                if (isset($_varParseList[$match[0]])) {
                    $parseStr = $_varParseList[$match[0]];
                } else {
                    if (strpos($match[0], '.')) {
                        $vars  = explode('.', $match[0]);
                        $first = array_shift($vars);
                        if ('$Think' == $first) {
                            // Compile $Think. variables without requiring template assignment
                            $parseStr = $this->parseThinkVar($vars);
                        } elseif ('$Request' == $first) {
                            // Compile access to Request object methods
                            $method = array_shift($vars);
                            if (!empty($vars)) {
                                $params = implode('.', $vars);
                                if ('true' != $params) {
                                    $params = '\'' . $params . '\'';
                                }
                            } else {
                                $params = '';
                            }
                            $parseStr = '\think\Request::instance()->' . $method . '(' . $params . ')';
                        } else {
                            switch ($this->config['tpl_var_identify']) {
                                case 'array': // Recognize as an array
                                    $parseStr = $first . '[\'' . implode('\'][\'', $vars) . '\']';
                                    break;
                                case 'obj': // Recognize as an object
                                    $parseStr = $first . '->' . implode('->', $vars);
                                    break;
                                default: // Automatically determine arrays or objects
                                    $parseStr = '(is_array(' . $first . ')?' . $first . '[\'' . implode('\'][\'', $vars) . '\']:' . $first . '->' . implode('->', $vars) . ')';
                            }
                        }
                    } else {
                        $parseStr = str_replace(':', '->', $match[0]);
                    }
                    $_varParseList[$match[0]] = $parseStr;
                }
                $varStr = substr_replace($varStr, $parseStr, $match[1], strlen($match[0]));
            }
            unset($matches);
        }
        return;
    }

    /* *
 * Parse variables that use functions in the template
 * format {$varname|function1|function2=arg1, arg2}
 * @access public
 * @param string $varStr variable string
 * @return void */
    public function parseVarFunction(&$varStr)
    {
        if (false == strpos($varStr, '|')) {
            return;
        }
        static $_varFunctionList = [];
        $_key                    = md5($varStr);
        // Reuse the compiled expression for a previously parsed filter chain
        if (isset($_varFunctionList[$_key])) {
            $varStr = $_varFunctionList[$_key];
        } else {
            $varArray = explode('|', $varStr);
            // Get variable names
            $name = array_shift($varArray);
            // Build the variable filter chain
            $length = count($varArray);
            // Get a list of prohibited functions in the template
            $template_deny_funs = explode(',', $this->config['tpl_deny_func_list']);
            for ($i = 0; $i < $length; $i++) {
                $args = explode('=', $varArray[$i], 2);
                // Parse the filter name
                $fun = trim($args[0]);
                switch ($fun) {
                    case 'default': // Default-value filter
                        if (false === strpos($name, '(')) {
                            $name = '(isset(' . $name . ') && (' . $name . ' !== \'\')?' . $name . ':' . $args[1] . ')';
                        } else {
                            $name = '(' . $name . ' ?: ' . $args[1] . ')';
                        }
                        break;
                    default: // Generic template functions
                        if (!in_array($fun, $template_deny_funs)) {
                            if (isset($args[1])) {
                                if (strstr($args[1], '###')) {
                                    $args[1] = str_replace('###', $name, $args[1]);
                                    $name    = "$fun($args[1])";
                                } else {
                                    $name = "$fun($name,$args[1])";
                                }
                            } else {
                                if (!empty($args[0])) {
                                    $name = "$fun($name)";
                                }
                            }
                        }
                }
            }
            $_varFunctionList[$_key] = $name;
            $varStr                  = $name;
        }
        return;
    }

    /* *
 * Compile special template variables
 * Variables beginning with $Think. access framework values
 * @access public
 * @param array $vars Special variable path segments
 * @return string */
    public function parseThinkVar($vars)
    {
        $type  = strtoupper(trim(array_shift($vars)));
        $param = implode('.', $vars);
        if ($vars) {
            switch ($type) {
                case 'SERVER':
                    $parseStr = '\\think\\Request::instance()->server(\'' . $param . '\')';
                    break;
                case 'GET':
                    $parseStr = '\\think\\Request::instance()->get(\'' . $param . '\')';
                    break;
                case 'POST':
                    $parseStr = '\\think\\Request::instance()->post(\'' . $param . '\')';
                    break;
                case 'COOKIE':
                    $parseStr = '\\think\\Cookie::get(\'' . $param . '\')';
                    break;
                case 'SESSION':
                    $parseStr = '\\think\\Session::get(\'' . $param . '\')';
                    break;
                case 'ENV':
                    $parseStr = '\\think\\Request::instance()->env(\'' . $param . '\')';
                    break;
                case 'REQUEST':
                    $parseStr = '\\think\\Request::instance()->request(\'' . $param . '\')';
                    break;
                case 'CONST':
                    $parseStr = strtoupper($param);
                    break;
                case 'LANG':
                    $parseStr = '\\think\\Lang::get(\'' . $param . '\')';
                    break;
                case 'CONFIG':
                    $parseStr = '\\think\\Config::get(\'' . $param . '\')';
                    break;
                default:
                    $parseStr = '\'\'';
                    break;
            }
        } else {
            switch ($type) {
                case 'NOW':
                    $parseStr = "date('Y-m-d g:i a',time())";
                    break;
                case 'VERSION':
                    $parseStr = 'THINK_VERSION';
                    break;
                case 'LDELIM':
                    $parseStr = '\'' . ltrim($this->config['tpl_begin'], '\\') . '\'';
                    break;
                case 'RDELIM':
                    $parseStr = '\'' . ltrim($this->config['tpl_end'], '\\') . '\'';
                    break;
                default:
                    if (defined($type)) {
                        $parseStr = $type;
                    } else {
                        $parseStr = '';
                    }
            }
        }
        return $parseStr;
    }

    /* *
 * Resolve comma-separated template filenames and concatenate their contents
 * @access private
 * @param string $templateName template filename
 * @return string */
    private function parseTemplateName($templateName)
    {
        $array    = explode(',', $templateName);
        $parseStr = '';
        foreach ($array as $templateName) {
            if (empty($templateName)) {
                continue;
            }
            if (0 === strpos($templateName, '$')) {
                // Resolve a template filename from a template variable
                $templateName = $this->get(substr($templateName, 1));
            }
            $template = $this->parseTemplateFile($templateName);
            if ($template) {
                // Get template file content
                $parseStr .= file_get_contents($template);
            }
        }
        return $parseStr;
    }

    /* *
 * Parse template file names
 * @access private
 * @param string $template filename
 * @return string */
    private function parseTemplateFile($template)
    {
        if ('' == pathinfo($template, PATHINFO_EXTENSION)) {
            if (strpos($template, '@')) {
                list($module, $template) = explode('@', $template);
            }
            if (0 !== strpos($template, '/')) {
                $template = str_replace(['/', ':'], $this->config['view_depr'], $template);
            } else {
                $template = str_replace(['/', ':'], $this->config['view_depr'], substr($template, 1));
            }
            if ($this->config['view_base']) {
                $module = isset($module) ? $module : Request::instance()->module();
                $path   = $this->config['view_base'] . ($module ? $module . DS : '');
            } else {
                $path = isset($module) ? APP_PATH . $module . DS . basename($this->config['view_path']) . DS : $this->config['view_path'];
            }
            $template = realpath($path . $template . '.' . ltrim($this->config['view_suffix'], '.'));
        }

        if (is_file($template)) {
            // Record when the template file was updated
            $this->includeFile[$template] = filemtime($template);
            return $template;
        } else {
            throw new TemplateNotFoundException('template not exists:' . $template, $template);
        }
    }

    /* *
 * Build a regular expression for a template tag
 * @access private
 * @param string $tagName tag name
 * @return string */
    private function getRegex($tagName)
    {
        $regex = '';
        if ('tag' == $tagName) {
            $begin = $this->config['tpl_begin'];
            $end   = $this->config['tpl_end'];
            if (strlen(ltrim($begin, '\\')) == 1 && strlen(ltrim($end, '\\')) == 1) {
                $regex = $begin . '((?:[\$]{1,2}[a-wA-w_]|[\:\~][\$a-wA-w_]|[+]{2}[\$][a-wA-w_]|[-]{2}[\$][a-wA-w_]|\/[\*\/])(?>[^' . $end . ']*))' . $end;
            } else {
                $regex = $begin . '((?:[\$]{1,2}[a-wA-w_]|[\:\~][\$a-wA-w_]|[+]{2}[\$][a-wA-w_]|[-]{2}[\$][a-wA-w_]|\/[\*\/])(?>(?:(?!' . $end . ').)*))' . $end;
            }
        } else {
            $begin  = $this->config['taglib_begin'];
            $end    = $this->config['taglib_end'];
            $single = strlen(ltrim($begin, '\\')) == 1 && strlen(ltrim($end, '\\')) == 1 ? true : false;
            switch ($tagName) {
                case 'block':
                    if ($single) {
                        $regex = $begin . '(?:' . $tagName . '\b(?>(?:(?!name=).)*)\bname=([\'\"])(?P<name>[\$\w\-\/\.]+)\\1(?>[^' . $end . ']*)|\/' . $tagName . ')' . $end;
                    } else {
                        $regex = $begin . '(?:' . $tagName . '\b(?>(?:(?!name=).)*)\bname=([\'\"])(?P<name>[\$\w\-\/\.]+)\\1(?>(?:(?!' . $end . ').)*)|\/' . $tagName . ')' . $end;
                    }
                    break;
                case 'literal':
                    if ($single) {
                        $regex = '(' . $begin . $tagName . '\b(?>[^' . $end . ']*)' . $end . ')';
                        $regex .= '(?:(?>[^' . $begin . ']*)(?>(?!' . $begin . '(?>' . $tagName . '\b[^' . $end . ']*|\/' . $tagName . ')' . $end . ')' . $begin . '[^' . $begin . ']*)*)';
                        $regex .= '(' . $begin . '\/' . $tagName . $end . ')';
                    } else {
                        $regex = '(' . $begin . $tagName . '\b(?>(?:(?!' . $end . ').)*)' . $end . ')';
                        $regex .= '(?:(?>(?:(?!' . $begin . ').)*)(?>(?!' . $begin . '(?>' . $tagName . '\b(?>(?:(?!' . $end . ').)*)|\/' . $tagName . ')' . $end . ')' . $begin . '(?>(?:(?!' . $begin . ').)*))*)';
                        $regex .= '(' . $begin . '\/' . $tagName . $end . ')';
                    }
                    break;
                case 'restoreliteral':
                    $regex = '<!--###literal(\d+)###-->';
                    break;
                case 'include':
                    $name = 'file';
                case 'taglib':
                case 'layout':
                case 'extend':
                    if (empty($name)) {
                        $name = 'name';
                    }
                    if ($single) {
                        $regex = $begin . $tagName . '\b(?>(?:(?!' . $name . '=).)*)\b' . $name . '=([\'\"])(?P<name>[\$\w\-\/\.\:@,\\\\]+)\\1(?>[^' . $end . ']*)' . $end;
                    } else {
                        $regex = $begin . $tagName . '\b(?>(?:(?!' . $name . '=).)*)\b' . $name . '=([\'\"])(?P<name>[\$\w\-\/\.\:@,\\\\]+)\\1(?>(?:(?!' . $end . ').)*)' . $end;
                    }
                    break;
            }
        }
        return '/' . $regex . '/is';
    }
}
