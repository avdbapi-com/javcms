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

use think\exception\HttpException;

class Route
{
    // routing rules
    private static $rules = [
        'get'     => [],
        'post'    => [],
        'put'     => [],
        'delete'  => [],
        'patch'   => [],
        'head'    => [],
        'options' => [],
        '*'       => [],
        'alias'   => [],
        'domain'  => [],
        'pattern' => [],
        'name'    => [],
    ];

    // REST resource action definitions
    private static $rest = [
        'index'  => ['get', '', 'index'],
        'create' => ['get', '/create', 'create'],
        'edit'   => ['get', '/:id/edit', 'edit'],
        'read'   => ['get', '/:id', 'read'],
        'save'   => ['post', '', 'save'],
        'update' => ['put', '/:id', 'update'],
        'delete' => ['delete', '/:id', 'delete'],
    ];

    // Method prefixes for different request types
    private static $methodPrefix = [
        'get'    => 'get',
        'post'   => 'post',
        'put'    => 'put',
        'delete' => 'delete',
        'patch'  => 'patch',
    ];

    // subdomains
    private static $subDomain = '';
    // Domain binding
    private static $bind = [];
    // Current group information
    private static $group = [];
    // Current subdomain binding
    private static $domainBind;
    private static $domainRule;
    // Current domain
    private static $domain;
    // Options recorded during route matching
    private static $option = [];

    /* *
 * Register route variable patterns
 * @access public
 * @param string|array $name Variable name or an array of variable patterns
 * @param string $rule Variable pattern
 * @return void */
    public static function pattern($name = null, $rule = '')
    {
        if (is_array($name)) {
            self::$rules['pattern'] = array_merge(self::$rules['pattern'], $name);
        } else {
            self::$rules['pattern'][$name] = $rule;
        }
    }

    /* *
 * Register domain routing rules or bindings
 * @access public
 * @param string|array $domain Domain name or an array of domain rules
 * @param mixed $rule routing rules
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @return void */
    public static function domain($domain, $rule = '', $option = [], $pattern = [])
    {
        if (is_array($domain)) {
            foreach ($domain as $key => $item) {
                self::domain($key, $item, $option, $pattern);
            }
        } elseif ($rule instanceof \Closure) {
            // Execute closure
            self::setDomain($domain);
            call_user_func_array($rule, []);
            self::setDomain(null);
        } elseif (is_array($rule)) {
            self::setDomain($domain);
            self::group('', function () use ($rule) {
                // Register routing rules for the current domain
                self::registerRules($rule);
            }, $option, $pattern);
            self::setDomain(null);
        } else {
            self::$rules['domain'][$domain]['[bind]'] = [$rule, $option, $pattern];
        }
    }

    private static function setDomain($domain)
    {
        self::$domain = $domain;
    }

    /* *
 * Set route binding
 * @access public
 * @param mixed $bind binding info
 * @param string $type Binding type: module (default), namespace, class or controller
 * @return void */
    public static function bind($bind, $type = 'module')
    {
        self::$bind = ['type' => $type, $type => $bind];
    }

    /* *
 * Set or get named-route definitions
 * @access public
 * @param string|array $name Route name or an array of named-route definitions
 * @param array $value route address and variable information
 * @return array|null */
    public static function name($name = '', $value = null)
    {
        if (is_array($name)) {
            return self::$rules['name'] = $name;
        } elseif ('' === $name) {
            return self::$rules['name'];
        } elseif (!is_null($value)) {
            self::$rules['name'][strtolower($name)][] = $value;
        } else {
            $name = strtolower($name);
            return isset(self::$rules['name'][$name]) ? self::$rules['name'][$name] : null;
        }
    }

    /* *
 * Read route bindings
 * @access public
 * @param string $type The binding type
 * @return mixed */
    public static function getBind($type)
    {
        return isset(self::$bind[$type]) ? self::$bind[$type] : null;
    }

    /* *
 * Import a routing-rule array
 * @access public
 * @param array $rule routing rules
 * @param string $type Request type
 * @return void */
    public static function import(array $rule, $type = '*')
    {
        // Register domain rules
        if (isset($rule['__domain__'])) {
            self::domain($rule['__domain__']);
            unset($rule['__domain__']);
        }

        // Register variable patterns
        if (isset($rule['__pattern__'])) {
            self::pattern($rule['__pattern__']);
            unset($rule['__pattern__']);
        }

        // Register route aliases
        if (isset($rule['__alias__'])) {
            self::alias($rule['__alias__']);
            unset($rule['__alias__']);
        }

        // Register resource routes
        if (isset($rule['__rest__'])) {
            self::resource($rule['__rest__']);
            unset($rule['__rest__']);
        }

        self::registerRules($rule, strtolower($type));
    }

    // Register routes in batches
    protected static function registerRules($rules, $type = '*')
    {
        foreach ($rules as $key => $val) {
            if (is_numeric($key)) {
                $key = array_shift($val);
            }
            if (empty($val)) {
                continue;
            }
            if (is_string($key) && 0 === strpos($key, '[')) {
                $key = substr($key, 1, -1);
                self::group($key, $val);
            } elseif (is_array($val)) {
                self::setRule($key, $val[0], $type, $val[1], isset($val[2]) ? $val[2] : []);
            } else {
                self::setRule($key, $val, $type);
            }
        }
    }

    /* *
 * Register routing rules
 * @access public
 * @param string|array $rule routing rules
 * @param string $route route address
 * @param string $type Request type
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @return void */
    public static function rule($rule, $route = '', $type = '*', $option = [], $pattern = [])
    {
        $group = self::getGroup('name');

        if (!is_null($group)) {
            // Inherit options and variable patterns from the current route group
            $option  = array_merge(self::getGroup('option'), $option);
            $pattern = array_merge(self::getGroup('pattern'), $pattern);
        }

        $type = strtolower($type);

        if (strpos($type, '|')) {
            $option['method'] = $type;
            $type             = '*';
        }
        if (is_array($rule) && empty($route)) {
            foreach ($rule as $key => $val) {
                if (is_numeric($key)) {
                    $key = array_shift($val);
                }
                if (is_array($val)) {
                    $route    = $val[0];
                    $option1  = array_merge($option, $val[1]);
                    $pattern1 = array_merge($pattern, isset($val[2]) ? $val[2] : []);
                } else {
                    $option1  = null;
                    $pattern1 = null;
                    $route    = $val;
                }
                self::setRule($key, $route, $type, !is_null($option1) ? $option1 : $option, !is_null($pattern1) ? $pattern1 : $pattern, $group);
            }
        } else {
            self::setRule($rule, $route, $type, $option, $pattern, $group);
        }

    }

    /* *
 * Set up routing rules
 * @access protected
 * @param string|array $rule routing rules
 * @param string $route route address
 * @param string $type Request type
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @param string $group the group it belongs to
 * @return void */
    protected static function setRule($rule, $route, $type = '*', $option = [], $pattern = [], $group = '')
    {
        if (is_array($rule)) {
            $name = $rule[0];
            $rule = $rule[1];
        } elseif (is_string($route)) {
            $name = $route;
        }
        if (!isset($option['complete_match'])) {
            if (Config::get('route_complete_match')) {
                $option['complete_match'] = true;
            } elseif ('$' == substr($rule, -1, 1)) {
                // Require a complete match for a rule ending in $
                $option['complete_match'] = true;
            }
        } elseif (empty($option['complete_match']) && '$' == substr($rule, -1, 1)) {
            // Require a complete match for a rule ending in $
            $option['complete_match'] = true;
        }

        if ('$' == substr($rule, -1, 1)) {
            $rule = substr($rule, 0, -1);
        }

        if ('/' != $rule || $group) {
            $rule = trim($rule, '/');
        }
        $vars = self::parseVar($rule);
        if (isset($name)) {
            $key    = $group ? $group . ($rule ? '/' . $rule : '') : $rule;
            $suffix = isset($option['ext']) ? $option['ext'] : null;
            self::name($name, [$key, $vars, self::$domain, $suffix]);
        }
        if (isset($option['modular'])) {
            $route = $option['modular'] . '/' . $route;
        }
        if ($group) {
            if ('*' != $type) {
                $option['method'] = $type;
            }
            if (self::$domain) {
                self::$rules['domain'][self::$domain]['*'][$group]['rule'][] = ['rule' => $rule, 'route' => $route, 'var' => $vars, 'option' => $option, 'pattern' => $pattern];
            } else {
                self::$rules['*'][$group]['rule'][] = ['rule' => $rule, 'route' => $route, 'var' => $vars, 'option' => $option, 'pattern' => $pattern];
            }
        } else {
            if ('*' != $type && isset(self::$rules['*'][$rule])) {
                unset(self::$rules['*'][$rule]);
            }
            if (self::$domain) {
                self::$rules['domain'][self::$domain][$type][$rule] = ['rule' => $rule, 'route' => $route, 'var' => $vars, 'option' => $option, 'pattern' => $pattern];
            } else {
                self::$rules[$type][$rule] = ['rule' => $rule, 'route' => $route, 'var' => $vars, 'option' => $option, 'pattern' => $pattern];
            }
            if ('*' == $type) {
                // Register route shortcuts
                foreach (['get', 'post', 'put', 'delete', 'patch', 'head', 'options'] as $method) {
                    if (self::$domain && !isset(self::$rules['domain'][self::$domain][$method][$rule])) {
                        self::$rules['domain'][self::$domain][$method][$rule] = true;
                    } elseif (!self::$domain && !isset(self::$rules[$method][$rule])) {
                        self::$rules[$method][$rule] = true;
                    }
                }
            }
        }
    }

    /* *
 * Record options for a matched route
 * @access protected
 * @param array $options Route options
 * @return void */
    protected static function setOption($options = [])
    {
        self::$option[] = $options;
    }

    /* *
 * Get options recorded during route matching
 * @access public
 * @return array */
    public static function getOption()
    {
        return self::$option;
    }

    /* *
 * Get current group information
 * @access public
 * @param string $type Group field: name, option or pattern
 * @return mixed */
    public static function getGroup($type)
    {
        if (isset(self::$group[$type])) {
            return self::$group[$type];
        } else {
            return 'name' == $type ? null : [];
        }
    }

    /* *
 * Set the current route group
 * @access public
 * @param string $name Group name
 * @param array $option Route group options
 * @param array $pattern Route group variable patterns
 * @return void */
    public static function setGroup($name, $option = [], $pattern = [])
    {
        self::$group['name']    = $name;
        self::$group['option']  = $option ?: [];
        self::$group['pattern'] = $pattern ?: [];
    }

    /* *
 * Register route groups
 * @access public
 * @param string|array $name Group name or an array of group options
 * @param array|\Closure $routes Route definitions or a registration callback
 * @param array $option Route group options
 * @param array $pattern Variable patterns
 * @return void */
    public static function group($name, $routes, $option = [], $pattern = [])
    {
        if (is_array($name)) {
            $option = $name;
            $name   = isset($option['name']) ? $option['name'] : '';
        }
        // Include the parent group in the group name
        $currentGroup = self::getGroup('name');
        if ($currentGroup) {
            $name = $currentGroup . ($name ? '/' . ltrim($name, '/') : '');
        }
        if (!empty($name)) {
            if ($routes instanceof \Closure) {
                $currentOption  = self::getGroup('option');
                $currentPattern = self::getGroup('pattern');
                self::setGroup($name, array_merge($currentOption, $option), array_merge($currentPattern, $pattern));
                call_user_func_array($routes, []);
                self::setGroup($currentGroup, $currentOption, $currentPattern);
                if ($currentGroup != $name) {
                    self::$rules['*'][$name]['route']   = '';
                    self::$rules['*'][$name]['var']     = self::parseVar($name);
                    self::$rules['*'][$name]['option']  = $option;
                    self::$rules['*'][$name]['pattern'] = $pattern;
                }
            } else {
                $item          = [];
                $completeMatch = Config::get('route_complete_match');
                foreach ($routes as $key => $val) {
                    if (is_numeric($key)) {
                        $key = array_shift($val);
                    }
                    if (is_array($val)) {
                        $route    = $val[0];
                        $option1  = array_merge($option, isset($val[1]) ? $val[1] : []);
                        $pattern1 = array_merge($pattern, isset($val[2]) ? $val[2] : []);
                    } else {
                        $route = $val;
                    }

                    $options  = isset($option1) ? $option1 : $option;
                    $patterns = isset($pattern1) ? $pattern1 : $pattern;
                    if ('$' == substr($key, -1, 1)) {
                        // Require a complete match for a rule ending in $
                        $options['complete_match'] = true;
                        $key                       = substr($key, 0, -1);
                    } elseif ($completeMatch) {
                        $options['complete_match'] = true;
                    }
                    $key    = trim($key, '/');
                    $vars   = self::parseVar($key);
                    $item[] = ['rule' => $key, 'route' => $route, 'var' => $vars, 'option' => $options, 'pattern' => $patterns];
                    // Register the named-route definition
                    $suffix = isset($options['ext']) ? $options['ext'] : null;
                    self::name($route, [$name . ($key ? '/' . $key : ''), $vars, self::$domain, $suffix]);
                }
                self::$rules['*'][$name] = ['rule' => $item, 'route' => '', 'var' => [], 'option' => $option, 'pattern' => $pattern];
            }

            foreach (['get', 'post', 'put', 'delete', 'patch', 'head', 'options'] as $method) {
                if (!isset(self::$rules[$method][$name])) {
                    self::$rules[$method][$name] = true;
                } elseif (is_array(self::$rules[$method][$name])) {
                    self::$rules[$method][$name] = array_merge(self::$rules['*'][$name], self::$rules[$method][$name]);
                }
            }

        } elseif ($routes instanceof \Closure) {
            // Register routes through a closure without a group name
            $currentOption  = self::getGroup('option');
            $currentPattern = self::getGroup('pattern');
            self::setGroup('', array_merge($currentOption, $option), array_merge($currentPattern, $pattern));
            call_user_func_array($routes, []);
            self::setGroup($currentGroup, $currentOption, $currentPattern);
        } else {
            // Register routes in batches
            self::rule($routes, '', '*', $option, $pattern);
        }
    }

    /* *
 * Register routes for any request method
 * @access public
 * @param string|array $rule Routing rules
 * @param string $route Routing address
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @return void */
    public static function any($rule, $route = '', $option = [], $pattern = [])
    {
        self::rule($rule, $route, '*', $option, $pattern);
    }

    /* *
 * Register GET routes
 * @access public
 * @param string|array $rule Routing rules
 * @param string $route Routing address
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @return void */
    public static function get($rule, $route = '', $option = [], $pattern = [])
    {
        self::rule($rule, $route, 'GET', $option, $pattern);
    }

    /* *
 * Register a POST route
 * @access public
 * @param string|array $rule Routing rules
 * @param string $route Routing address
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @return void */
    public static function post($rule, $route = '', $option = [], $pattern = [])
    {
        self::rule($rule, $route, 'POST', $option, $pattern);
    }

    /* *
 * Register a PUT route
 * @access public
 * @param string|array $rule Routing rules
 * @param string $route Routing address
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @return void */
    public static function put($rule, $route = '', $option = [], $pattern = [])
    {
        self::rule($rule, $route, 'PUT', $option, $pattern);
    }

    /* *
 * Register a DELETE route
 * @access public
 * @param string|array $rule Routing rules
 * @param string $route Routing address
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @return void */
    public static function delete($rule, $route = '', $option = [], $pattern = [])
    {
        self::rule($rule, $route, 'DELETE', $option, $pattern);
    }

    /* *
 * Register PATCH routes
 * @access public
 * @param string|array $rule Routing rules
 * @param string $route Routing address
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @return void */
    public static function patch($rule, $route = '', $option = [], $pattern = [])
    {
        self::rule($rule, $route, 'PATCH', $option, $pattern);
    }

    /* *
 * Register resource routes
 * @access public
 * @param string|array $rule Routing rules
 * @param string $route Routing address
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @return void */
    public static function resource($rule, $route = '', $option = [], $pattern = [])
    {
        if (is_array($rule)) {
            foreach ($rule as $key => $val) {
                if (is_array($val)) {
                    list($val, $option, $pattern) = array_pad($val, 3, []);
                }
                self::resource($key, $val, $option, $pattern);
            }
        } else {
            if (strpos($rule, '.')) {
                // Register nested resource routes
                $array = explode('.', $rule);
                $last  = array_pop($array);
                $item  = [];
                foreach ($array as $val) {
                    $item[] = $val . '/:' . (isset($option['var'][$val]) ? $option['var'][$val] : $val . '_id');
                }
                $rule = implode('/', $item) . '/' . $last;
            }
            // Register resource routes
            foreach (self::$rest as $key => $val) {
                if ((isset($option['only']) && !in_array($key, $option['only']))
                    || (isset($option['except']) && in_array($key, $option['except']))) {
                    continue;
                }
                if (isset($last) && strpos($val[1], ':id') && isset($option['var'][$last])) {
                    $val[1] = str_replace(':id', ':' . $option['var'][$last], $val[1]);
                } elseif (strpos($val[1], ':id') && isset($option['var'][$rule])) {
                    $val[1] = str_replace(':id', ':' . $option['var'][$rule], $val[1]);
                }
                $item           = ltrim($rule . $val[1], '/');
                $option['rest'] = $key;
                self::rule($item . '$', $route . '/' . $val[2], $val[0], $option, $pattern);
            }
        }
    }

    /* *
 * Register controller routes with action prefixes for each request method
 * @access public
 * @param string $rule routing rules
 * @param string $route route address
 * @param array $option Route options
 * @param array $pattern Variable patterns
 * @return void */
    public static function controller($rule, $route = '', $option = [], $pattern = [])
    {
        foreach (self::$methodPrefix as $type => $val) {
            self::$type($rule . '/:action', $route . '/' . $val . ':action', $option, $pattern);
        }
    }

    /* *
 * Register an alias route
 * @access public
 * @param string|array $rule route alias
 * @param string $route route address
 * @param array $option Route options
 * @return void */
    public static function alias($rule = null, $route = '', $option = [])
    {
        if (is_array($rule)) {
            self::$rules['alias'] = array_merge(self::$rules['alias'], $rule);
        } else {
            self::$rules['alias'][$rule] = $option ? [$route, $option] : $route;
        }
    }

    /* *
 * Set method prefixes under different request types
 * @access public
 * @param string|array $method Request method or an array of method prefixes
 * @param string $prefix Action method prefix
 * @return void */
    public static function setMethodPrefix($method, $prefix = '')
    {
        if (is_array($method)) {
            self::$methodPrefix = array_merge(self::$methodPrefix, array_change_key_case($method));
        } else {
            self::$methodPrefix[strtolower($method)] = $prefix;
        }
    }

    /* *
 * Define or update REST resource actions
 * @access public
 * @param string|array $name Action name or an array of action definitions
 * @param array|bool $resource Action definition, or whether to replace all definitions when name is an array
 * @return void */
    public static function rest($name, $resource = [])
    {
        if (is_array($name)) {
            self::$rest = $resource ? $name : array_merge(self::$rest, $name);
        } else {
            self::$rest[$name] = $resource;
        }
    }

    /* *
 * Register a fallback for requests that do not match a routing rule
 * @access public
 * @param string $route route address
 * @param string $method request type
 * @param array $option Route options
 * @return void */
    public static function miss($route, $method = '*', $option = [])
    {
        self::rule('__miss__', $route, $method, $option, []);
    }

    /* *
 * Register an automatically parsed URL route
 * @access public
 * @param string $route route address
 * @return void */
    public static function auto($route)
    {
        self::rule('__auto__', $route, '*', [], []);
    }

    /* *
 * Get or set route definitions in batches
 * @access public
 * @param mixed $rules request type or array of route definitions
 * @return array */
    public static function rules($rules = '')
    {
        if (is_array($rules)) {
            self::$rules = $rules;
        } elseif ($rules) {
            return true === $rules ? self::$rules : self::$rules[strtolower($rules)];
        } else {
            $rules = self::$rules;
            unset($rules['pattern'], $rules['alias'], $rules['domain'], $rules['name']);
            return $rules;
        }
    }

    /* *
 * Check domain routing rules and bindings
 * @access public
 * @param Request $request Request object
 * @param array $currentRules Routing rules for the current request method
 * @param string $method Request method
 * @return void */
    public static function checkDomain($request, &$currentRules, $method = 'get')
    {
        // Domain name rules
        $rules = self::$rules['domain'];
        // Enable subdomain deployment to support second-level and third-level domains
        if (!empty($rules)) {
            $host = $request->host(true);
            if (isset($rules[$host])) {
                // Full domain name or IP configuration
                $item = $rules[$host];
            } else {
                $rootDomain = Config::get('url_domain_root');
                if ($rootDomain) {
                    // Use the configured root domain, such as thinkphp.cn or 163.com.cn; configure it for suffixes such as com.cn or net.cn
                    $domain = explode('.', rtrim(stristr($host, $rootDomain, true), '.'));
                } else {
                    $domain = explode('.', $host, -2);
                }
                // Subdomain configuration
                if (!empty($domain)) {
                    // Current subdomain
                    $subDomain       = implode('.', $domain);
                    self::$subDomain = $subDomain;
                    $domain2         = array_pop($domain);
                    if ($domain) {
                        // There is a third-level domain
                        $domain3 = array_pop($domain);
                    }
                    if ($subDomain && isset($rules[$subDomain])) {
                        // Subdomain configuration
                        $item = $rules[$subDomain];
                    } elseif (isset($rules['*.' . $domain2]) && !empty($domain3)) {
                        // Wildcard third-level domain
                        $item      = $rules['*.' . $domain2];
                        $panDomain = $domain3;
                    } elseif (isset($rules['*']) && !empty($domain2)) {
                        // Wildcard second-level domain
                        if ('www' != $domain2) {
                            $item      = $rules['*'];
                            $panDomain = $domain2;
                        }
                    }
                }
            }
            if (!empty($item)) {
                if (isset($panDomain)) {
                    // Save the matched wildcard domain segment
                    $request->route(['__domain__' => $panDomain]);
                }
                if (isset($item['[bind]'])) {
                    // Parse subdomain deployment rules
                    list($rule, $option, $pattern) = $item['[bind]'];
                    if (!empty($option['https']) && !$request->isSsl()) {
                        // https://
                        throw new HttpException(404, 'An HTTPS request is required: ' . $host);
                    }

                    if (strpos($rule, '?')) {
                        // Pass in other parameters
                        $array  = parse_url($rule);
                        $result = $array['path'];
                        parse_str($array['query'], $params);
                        if (isset($panDomain)) {
                            $pos = array_search('*', $params);
                            if (false !== $pos) {
                                // Use the wildcard domain segment as a parameter
                                $params[$pos] = $panDomain;
                            }
                        }
                        $_GET = array_merge($_GET, $params);
                    } else {
                        $result = $rule;
                    }

                    if (0 === strpos($result, '\\')) {
                        // Bind to a namespace such as \app\index\behavior
                        self::$bind = ['type' => 'namespace', 'namespace' => $result];
                    } elseif (0 === strpos($result, '@')) {
                        // Bind to a class such as @app\index\controller\User
                        self::$bind = ['type' => 'class', 'class' => substr($result, 1)];
                    } else {
                        // bind to a module/controller such as index/user
                        self::$bind = ['type' => 'module', 'module' => $result];
                    }
                    self::$domainBind = true;
                } else {
                    self::$domainRule = $item;
                    $currentRules     = isset($item[$method]) ? $item[$method] : $item['*'];
                }
            }
        }
    }

    /* *
 * Detect URL routes
 * @access public
 * @param Request $request Request object
 * @param string $url URL
 * @param string $depr URL separator
 * @param bool $checkDomain Whether to check domain routing rules
 * @return false|array */
    public static function check($request, $url, $depr = '/', $checkDomain = false)
    {
        // Check the parsing cache
        if (!App::$debug && Config::get('route_check_cache')) {
            $key = self::getCheckCacheKey($request);
            if (Cache::has($key)) {
                list($rule, $route, $pathinfo, $option, $matches) = Cache::get($key);
                return self::parseRule($rule, $route, $pathinfo, $option, $matches, true);
            }
        }

        // Separator replacement ensures that route definitions use uniform separators
        $url = str_replace($depr, '|', $url);

        if (isset(self::$rules['alias'][$url]) || isset(self::$rules['alias'][strstr($url, '|', true)])) {
            // Detect route aliases
            $result = self::checkRouteAlias($request, $url, $depr);
            if (false !== $result) {
                return $result;
            }
        }
        $method = strtolower($request->method());
        // Get the routing rules for the current request type
        $rules = isset(self::$rules[$method]) ? self::$rules[$method] : [];
        // Check domain name deployment
        if ($checkDomain) {
            self::checkDomain($request, $rules, $method);
        }
        // Detect URL bindings
        $return = self::checkUrlBind($url, $rules, $depr);
        if (false !== $return) {
            return $return;
        }
        if ('|' != $url) {
            $url = rtrim($url, '|');
        }
        $item = str_replace('|', '/', $url);
        if (isset($rules[$item])) {
            // Static route rule detection
            $rule = $rules[$item];
            if (true === $rule) {
                $rule = self::getRouteExpress($item);
            }
            if (!empty($rule['route']) && self::checkOption($rule['option'], $request)) {
                self::setOption($rule['option']);
                return self::parseRule($item, $rule['route'], $url, $rule['option']);
            }
        }

        // Routing rule detection
        if (!empty($rules)) {
            return self::checkRoute($request, $rules, $url, $depr);
        }
        return false;
    }

    private static function getRouteExpress($key)
    {
        return self::$domainRule ? self::$domainRule['*'][$key] : self::$rules['*'][$key];
    }

    /* *
 * Check routing rules
 * @access private
 * @param Request $request
 * @param array $rules routing rules
 * @param string $url URL address
 * @param string $depr URL separator
 * @param string $group route group name
 * @param array $options Route group options
 * @return mixed */
    private static function checkRoute($request, $rules, $url, $depr = '/', $group = '', $options = [])
    {
        foreach ($rules as $key => $item) {
            if (true === $item) {
                $item = self::getRouteExpress($key);
            }
            if (!isset($item['rule'])) {
                continue;
            }
            $rule    = $item['rule'];
            $route   = $item['route'];
            $vars    = $item['var'];
            $option  = $item['option'];
            $pattern = $item['pattern'];

            // Check route options against the request
            if (!self::checkOption($option, $request)) {
                continue;
            }

            if (isset($option['ext'])) {
                // A route-specific ext option takes precedence over the configured URL suffix
                $url = preg_replace('/\.' . $request->ext() . '$/i', '', $url);
            }

            if (is_array($rule)) {
                // Check the route group
                $pos = strpos(str_replace('<', ':', $key), ':');
                if (false !== $pos) {
                    $str = substr($key, 0, $pos);
                } else {
                    $str = $key;
                }
                if (is_string($str) && $str && 0 !== stripos(str_replace('|', '/', $url), $str)) {
                    continue;
                }
                self::setOption($option);
                $result = self::checkRoute($request, $rule, $url, $depr, $key, $option);
                if (false !== $result) {
                    return $result;
                }
            } elseif ($route) {
                if ('__miss__' == $rule || '__auto__' == $rule) {
                    // Specify a special route
                    $var    = trim($rule, '__');
                    ${$var} = $item;
                    continue;
                }
                if ($group) {
                    $rule = $group . ($rule ? '/' . ltrim($rule, '/') : '');
                }

                self::setOption($option);
                if (isset($options['bind_model']) && isset($option['bind_model'])) {
                    $option['bind_model'] = array_merge($options['bind_model'], $option['bind_model']);
                }
                $result = self::checkRule($rule, $route, $url, $pattern, $option, $depr);
                if (false !== $result) {
                    return $result;
                }
            }
        }
        if (isset($auto)) {
            // Automatically parse URL addresses
            return self::parseUrl($auto['route'] . '/' . $url, $depr);
        } elseif (isset($miss)) {
            // Use the fallback route when no rule matches
            return self::parseRule('', $miss['route'], $url, $miss['option']);
        }
        return false;
    }

    /* *
 * Detect route aliases
 * @access private
 * @param Request $request
 * @param string $url URL
 * @param string $depr URL separator
 * @return mixed */
    private static function checkRouteAlias($request, $url, $depr)
    {
        $array = explode('|', $url);
        $alias = array_shift($array);
        $item  = self::$rules['alias'][$alias];

        if (is_array($item)) {
            list($rule, $option) = $item;
            $action              = $array[0];
            if (isset($option['allow']) && !in_array($action, explode(',', $option['allow']))) {
                // Reject actions outside the allow list
                return false;
            } elseif (isset($option['except']) && in_array($action, explode(',', $option['except']))) {
                // Exclude actions
                return false;
            }
            if (isset($option['method'][$action])) {
                $option['method'] = $option['method'][$action];
            }
        } else {
            $rule = $item;
        }
        $bind = implode('|', $array);
        // Check route options against the request
        if (isset($option) && !self::checkOption($option, $request)) {
            // Route mismatch
            return false;
        } elseif (0 === strpos($rule, '\\')) {
            // Route to class
            return self::bindToClass($bind, substr($rule, 1), $depr);
        } elseif (0 === strpos($rule, '@')) {
            // Routing to controller classes
            return self::bindToController($bind, substr($rule, 1), $depr);
        } else {
            // Routing to Module/Controller
            return self::bindToModule($bind, $rule, $depr);
        }
    }

    /* *
 * Check URL bindings
 * @access private
 * @param string $url URL
 * @param array $rules Routing rules
 * @param string $depr URL separator
 * @return mixed */
    private static function checkUrlBind(&$url, &$rules, $depr = '/')
    {
        if (!empty(self::$bind)) {
            $type = self::$bind['type'];
            $bind = self::$bind[$type];
            // Record binding information
            App::$debug && Log::record('[ BIND ] ' . var_export($bind, true), 'info');
            // If there is a URL binding, the binding is checked
            switch ($type) {
                case 'class':
                    // Bind to a class
                    return self::bindToClass($url, $bind, $depr);
                case 'controller':
                    // Bind to a controller class
                    return self::bindToController($url, $bind, $depr);
                case 'namespace':
                    // Bind to a namespace
                    return self::bindToNamespace($url, $bind, $depr);
            }
        }
        return false;
    }

    /* *
 * Bind to a class
 * @access public
 * @param string $url URL address
 * @param string $class class name (with namespace)
 * @param string $depr URL separator
 * @return array */
    public static function bindToClass($url, $class, $depr = '/')
    {
        $url    = str_replace($depr, '|', $url);
        $array  = explode('|', $url, 2);
        $action = !empty($array[0]) ? $array[0] : Config::get('default_action');
        if (!empty($array[1])) {
            self::parseUrlParams($array[1]);
        }
        return ['type' => 'method', 'method' => [$class, $action], 'var' => []];
    }

    /* *
 * Bind to a namespace
 * @access public
 * @param string $url URL
 * @param string $namespace Namespace name
 * @param string $depr URL separator
 * @return array */
    public static function bindToNamespace($url, $namespace, $depr = '/')
    {
        $url    = str_replace($depr, '|', $url);
        $array  = explode('|', $url, 3);
        $class  = !empty($array[0]) ? $array[0] : Config::get('default_controller');
        $method = !empty($array[1]) ? $array[1] : Config::get('default_action');
        if (!empty($array[2])) {
            self::parseUrlParams($array[2]);
        }
        return ['type' => 'method', 'method' => [$namespace . '\\' . Loader::parseName($class, 1), $method], 'var' => []];
    }

    /* *
 * Bind to the controller class
 * @access public
 * @param string $url URL address
 * @param string $controller controller name (supports index/user with module name)
 * @param string $depr URL separator
 * @return array */
    public static function bindToController($url, $controller, $depr = '/')
    {
        $url    = str_replace($depr, '|', $url);
        $array  = explode('|', $url, 2);
        $action = !empty($array[0]) ? $array[0] : Config::get('default_action');
        if (!empty($array[1])) {
            self::parseUrlParams($array[1]);
        }
        return ['type' => 'controller', 'controller' => $controller . '/' . $action, 'var' => []];
    }

    /* *
 * Bind to the module/controller
 * @access public
 * @param string $url URL address
 * @param string $controller Module/controller path
 * @param string $depr URL separator
 * @return array */
    public static function bindToModule($url, $controller, $depr = '/')
    {
        $url    = str_replace($depr, '|', $url);
        $array  = explode('|', $url, 2);
        $action = !empty($array[0]) ? $array[0] : Config::get('default_action');
        if (!empty($array[1])) {
            self::parseUrlParams($array[1]);
        }
        return ['type' => 'module', 'module' => $controller . '/' . $action];
    }

    /* *
 * Check route options against the request
 * @access private
 * @param array $option Route options
 * @param Request $request Request object
 * @return bool */
    private static function checkOption($option, $request)
    {
        if ((isset($option['method']) && is_string($option['method']) && false === stripos($option['method'], $request->method()))
            || (isset($option['ajax']) && $option['ajax'] && !$request->isAjax()) // Require an AJAX request
             || (isset($option['ajax']) && !$option['ajax'] && $request->isAjax()) // Reject AJAX requests
             || (isset($option['pjax']) && $option['pjax'] && !$request->isPjax()) // Require a PJAX request
             || (isset($option['pjax']) && !$option['pjax'] && $request->isPjax()) // Reject PJAX requests
             || (isset($option['ext']) && false === stripos('|' . $option['ext'] . '|', '|' . $request->ext() . '|')) // Check allowed URL suffixes
             || (isset($option['deny_ext']) && false !== stripos('|' . $option['deny_ext'] . '|', '|' . $request->ext() . '|'))
            || (isset($option['domain']) && !in_array($option['domain'], [$_SERVER['HTTP_HOST'], self::$subDomain])) // Domain name check
             || (isset($option['https']) && $option['https'] && !$request->isSsl()) // Require HTTPS
             || (isset($option['https']) && !$option['https'] && $request->isSsl()) // Reject HTTPS requests
             || (!empty($option['before_behavior']) && false === Hook::exec($option['before_behavior'])) // Check the before-route behavior result
             || (!empty($option['callback']) && is_callable($option['callback']) && false === call_user_func($option['callback'])) // Check the custom callback result
        ) {
            return false;
        }
        return true;
    }

    /* *
 * Check routing rules
 * @access private
 * @param string $rule routing rules
 * @param string $route route address
 * @param string $url URL address
 * @param array $pattern Variable patterns
 * @param array $option Route options
 * @param string $depr URL separator (global)
 * @return array|false */
    private static function checkRule($rule, $route, $url, $pattern, $option, $depr)
    {
        // Check the full rule definition
        if (isset($pattern['__url__']) && !preg_match(0 === strpos($pattern['__url__'], '/') ? $pattern['__url__'] : '/^' . $pattern['__url__'] . '/', str_replace('|', $depr, $url))) {
            return false;
        }
        // Normalize the route-specific parameter separator
        if (isset($option['param_depr'])) {
            $url = str_replace(['|', $option['param_depr']], [$depr, '|'], $url);
        }

        $len1 = substr_count($url, '|');
        $len2 = substr_count($rule, '/');
        // Whether the extra parameters are merged
        $merge = !empty($option['merge_extra_vars']);
        if ($merge && $len1 > $len2) {
            $url = str_replace('|', $depr, $url);
            $url = implode('|', explode($depr, $url, $len2 + 1));
        }

        if ($len1 >= $len2 || strpos($rule, '[')) {
            if (!empty($option['complete_match'])) {
                // Require a complete match
                if (!$merge && $len1 != $len2 && (false === strpos($rule, '[') || $len1 > $len2 || $len1 < $len2 - substr_count($rule, '['))) {
                    return false;
                }
            }
            $pattern = array_merge(self::$rules['pattern'], $pattern);
            if (false !== $match = self::match($url, $rule, $pattern)) {
                // Match to route rules
                return self::parseRule($rule, $route, $url, $option, $match);
            }
        }
        return false;
    }

    /* *
 * Parse module URL address [module/controller/action?] parameter 1 = value 1 & parameter 2 = value 2...
 * @access public
 * @param string $url URL address
 * @param string $depr URL separator
 * @param bool $autoSearch Whether to search nested controller directories
 * @return array */
    public static function parseUrl($url, $depr = '/', $autoSearch = false)
    {

        if (isset(self::$bind['module'])) {
            $bind = str_replace('/', $depr, self::$bind['module']);
            // If there are module/controller bindings
            $url = $bind . ('.' != substr($bind, -1) ? $depr : '') . ltrim($url, $depr);
        }
        $url              = str_replace($depr, '|', $url);
        list($path, $var) = self::parseUrlPath($url);
        $route            = [null, null, null];
        if (isset($path)) {
            // Parse the module
            $module = Config::get('app_multi_module') ? array_shift($path) : null;
            if ($autoSearch) {
                // Auto search controller
                $dir    = APP_PATH . ($module ? $module . DS : '') . Config::get('url_controller_layer');
                $suffix = App::$suffix || Config::get('controller_suffix') ? ucfirst(Config::get('url_controller_layer')) : '';
                $item   = [];
                $find   = false;
                foreach ($path as $val) {
                    $item[] = $val;
                    $file   = $dir . DS . str_replace('.', DS, $val) . $suffix . EXT;
                    $file   = pathinfo($file, PATHINFO_DIRNAME) . DS . Loader::parseName(pathinfo($file, PATHINFO_FILENAME), 1) . EXT;
                    if (is_file($file)) {
                        $find = true;
                        break;
                    } else {
                        $dir .= DS . Loader::parseName($val);
                    }
                }
                if ($find) {
                    $controller = implode('.', $item);
                    $path       = array_slice($path, count($item));
                } else {
                    $controller = array_shift($path);
                }
            } else {
                // Parse the controller
                $controller = !empty($path) ? array_shift($path) : null;
            }
            // Parse the action
            $action = !empty($path) ? array_shift($path) : null;
            // Parsing additional parameters
            self::parseUrlParams(empty($path) ? '' : implode('|', $path));
            // Assemble the module, controller and action
            $route = [$module, $controller, $action];
            // Check whether the destination already has a named-route definition
            $name  = strtolower($module . '/' . Loader::parseName($controller, 1) . '/' . $action);
            $name2 = '';
            if (empty($module) || isset($bind) && $module == $bind) {
                $name2 = strtolower(Loader::parseName($controller, 1) . '/' . $action);
            }

            if (isset(self::$rules['name'][$name]) || isset(self::$rules['name'][$name2])) {
                throw new HttpException(404, 'invalid request:' . str_replace('|', $depr, $url));
            }
        }
        return ['type' => 'module', 'module' => $route];
    }

    /* *
 * Parse URL pathinfo parameters and variables
 * @access private
 * @param string $url URL address
 * @return array */
    private static function parseUrlPath($url)
    {
        // Separator replacement ensures that route definitions use uniform separators
        $url = str_replace('|', '/', $url);
        $url = trim($url, '/');
        $var = [];
        if (false !== strpos($url, '?')) {
            // [Module/Controller/Operation?] parameter 1 = value 1 & parameter 2 = value 2...
            $info = parse_url($url);
            $path = explode('/', $info['path']);
            parse_str($info['query'], $var);
        } elseif (strpos($url, '/')) {
            // [Module/Controller/Operation]
            $path = explode('/', $url);
        } else {
            $path = [$url];
        }
        return [$path, $var];
    }

    /* *
 * Check whether the URL matches a routing rule
 * @access private
 * @param string $url URL
 * @param string $rule Routing rules
 * @param array $pattern Variable patterns
 * @return array|false */
    private static function match($url, $rule, $pattern)
    {
        $m2 = explode('/', $rule);
        $m1 = explode('|', $url);

        $var = [];
        foreach ($m2 as $key => $val) {
            // Extract variables embedded in a segment, such as <id><name>
            if (false !== strpos($val, '<') && preg_match_all('/<(\w+(\??))>/', $val, $matches)) {
                $value   = [];
                $replace = [];
                foreach ($matches[1] as $name) {
                    if (strpos($name, '?')) {
                        $name      = substr($name, 0, -1);
                        $replace[] = '(' . (isset($pattern[$name]) ? $pattern[$name] : '\w+') . ')?';
                    } else {
                        $replace[] = '(' . (isset($pattern[$name]) ? $pattern[$name] : '\w+') . ')';
                    }
                    $value[] = $name;
                }
                $val = str_replace($matches[0], $replace, $val);
                if (preg_match('/^' . $val . '$/', isset($m1[$key]) ? $m1[$key] : '', $match)) {
                    array_shift($match);
                    foreach ($value as $k => $name) {
                        if (isset($match[$k])) {
                            $var[$name] = $match[$k];
                        }
                    }
                    continue;
                } else {
                    return false;
                }
            }

            if (0 === strpos($val, '[:')) {
                // Optional parameters
                $val      = substr($val, 1, -1);
                $optional = true;
            } else {
                $optional = false;
            }
            if (0 === strpos($val, ':')) {
                // URL parameters
                $name = substr($val, 1);
                if (!$optional && !isset($m1[$key])) {
                    return false;
                }
                if (isset($m1[$key]) && isset($pattern[$name])) {
                    // Check the variable pattern
                    if ($pattern[$name] instanceof \Closure) {
                        $result = call_user_func_array($pattern[$name], [$m1[$key]]);
                        if (false === $result) {
                            return false;
                        }
                    } elseif (!preg_match(0 === strpos($pattern[$name], '/') ? $pattern[$name] : '/^' . $pattern[$name] . '$/', $m1[$key])) {
                        return false;
                    }
                }
                $var[$name] = isset($m1[$key]) ? $m1[$key] : '';
            } elseif (!isset($m1[$key]) || 0 !== strcasecmp($val, $m1[$key])) {
                return false;
            }
        }
        // Returns an array of dynamic variables in the URL after a successful match
        return $var;
    }

    /* *
 * Parse a matched routing rule into dispatch information
 * @access private
 * @param string $rule routing rules
 * @param string $route route address
 * @param string $pathinfo URL address
 * @param array $option Route options
 * @param array $matches matching variables
 * @param bool $fromCache Whether the parsed rule came from the cache
 * @return array */
    private static function parseRule($rule, $route, $pathinfo, $option = [], $matches = [], $fromCache = false)
    {
        $request = Request::instance();

        // Save parsing cache
        if (Config::get('route_check_cache') && !$fromCache) {
            try {
                $key = self::getCheckCacheKey($request);
                Cache::tag('route_check')->set($key, [$rule, $route, $pathinfo, $option, $matches]);
            } catch (\Exception $e) {

            }
        }

        // Parsing route rules
        if ($rule) {
            $rule = explode('/', $rule);
            // Get the parameters in the URL address
            $paths = explode('|', $pathinfo);
            foreach ($rule as $item) {
                $fun = '';
                if (0 === strpos($item, '[:')) {
                    $item = substr($item, 1, -1);
                }
                if (0 === strpos($item, ':')) {
                    $var           = substr($item, 1);
                    $matches[$var] = array_shift($paths);
                } else {
                    // Remove static path segments from the remaining parameters
                    array_shift($paths);
                }
            }
        } else {
            $paths = explode('|', $pathinfo);
        }

        // Apply the route address prefix
        if (is_string($route) && isset($option['prefix'])) {
            // Route address prefixes
            $route = $option['prefix'] . $route;
        }
        // Replace variables in route addresses
        if (is_string($route) && !empty($matches)) {
            foreach ($matches as $key => $val) {
                if (false !== strpos($route, ':' . $key)) {
                    $route = str_replace(':' . $key, $val, $route);
                }
            }
        }

        // Binding model data
        if (isset($option['bind_model'])) {
            $bind = [];
            foreach ($option['bind_model'] as $key => $val) {
                if ($val instanceof \Closure) {
                    $result = call_user_func_array($val, [$matches]);
                } else {
                    if (is_array($val)) {
                        $fields    = explode('&', $val[1]);
                        $model     = $val[0];
                        $exception = isset($val[2]) ? $val[2] : true;
                    } else {
                        $fields    = ['id'];
                        $model     = $val;
                        $exception = true;
                    }
                    $where = [];
                    $match = true;
                    foreach ($fields as $field) {
                        if (!isset($matches[$field])) {
                            $match = false;
                            break;
                        } else {
                            $where[$field] = $matches[$field];
                        }
                    }
                    if ($match) {
                        $query  = strpos($model, '\\') ? $model::where($where) : Loader::model($model)->where($where);
                        $result = $query->failException($exception)->find();
                    }
                }
                if (!empty($result)) {
                    $bind[$key] = $result;
                }
            }
            $request->bind($bind);
        }

        if (!empty($option['response'])) {
            Hook::add('response_send', $option['response']);
        }

        // Parsing additional parameters
        self::parseUrlParams(empty($paths) ? '' : implode('|', $paths), $matches);
        // Record matching route information
        $request->routeInfo(['rule' => $rule, 'route' => $route, 'option' => $option, 'var' => $matches]);

        // Run after-route behaviors
        if (!empty($option['after_behavior'])) {
            if ($option['after_behavior'] instanceof \Closure) {
                $result = call_user_func_array($option['after_behavior'], []);
            } else {
                foreach ((array) $option['after_behavior'] as $behavior) {
                    $result = Hook::exec($behavior, '');
                    if (!is_null($result)) {
                        break;
                    }
                }
            }
            // Use dispatch information returned by the behavior
            if ($result instanceof Response) {
                return ['type' => 'response', 'response' => $result];
            } elseif (is_array($result)) {
                return $result;
            }
        }

        if ($route instanceof \Closure) {
            // Execute closure
            $result = ['type' => 'function', 'function' => $route];
        } elseif (0 === strpos($route, '/') || strpos($route, '://')) {
            // Route to the redirected address
            $result = ['type' => 'redirect', 'url' => $route, 'status' => isset($option['status']) ? $option['status'] : 301];
        } elseif (false !== strpos($route, '\\')) {
            // Route to method
            list($path, $var) = self::parseUrlPath($route);
            $route            = str_replace('/', '@', implode('/', $path));
            $method           = strpos($route, '@') ? explode('@', $route) : $route;
            $result           = ['type' => 'method', 'method' => $method, 'var' => $var];
        } elseif (0 === strpos($route, '@')) {
            // Routing to the controller
            $route             = substr($route, 1);
            list($route, $var) = self::parseUrlPath($route);
            $result            = ['type' => 'controller', 'controller' => implode('/', $route), 'var' => $var];
            $request->action(array_pop($route));
            $request->controller($route ? array_pop($route) : Config::get('default_controller'));
            $request->module($route ? array_pop($route) : Config::get('default_module'));
            App::$modulePath = APP_PATH . (Config::get('app_multi_module') ? $request->module() . DS : '');
        } else {
            // Routing to Module/Controller/Operation
            $result = self::parseModule($route, isset($option['convert']) ? $option['convert'] : false);
        }
        // Enable request caching
        if ($request->isGet() && isset($option['cache'])) {
            $cache = $option['cache'];
            if (is_array($cache)) {
                list($key, $expire, $tag) = array_pad($cache, 3, null);
            } else {
                $key    = str_replace('|', '/', $pathinfo);
                $expire = $cache;
                $tag    = null;
            }
            $request->cache($key, $expire, $tag);
        }
        return $result;
    }

    /* *
 * Parse the URL as a module/controller/action destination
 * @access private
 * @param string $url URL address
 * @param bool $convert Whether to automatically convert URL addresses
 * @return array */
    private static function parseModule($url, $convert = false)
    {
        list($path, $var) = self::parseUrlPath($url);
        $action           = array_pop($path);
        $controller       = !empty($path) ? array_pop($path) : null;
        $module           = Config::get('app_multi_module') && !empty($path) ? array_pop($path) : null;
        $method           = Request::instance()->method();
        if (Config::get('use_action_prefix') && !empty(self::$methodPrefix[$method])) {
            // Action method prefix support
            $action = 0 !== strpos($action, self::$methodPrefix[$method]) ? self::$methodPrefix[$method] . $action : $action;
        }
        // Set the route variables for the current request
        Request::instance()->route($var);
        // Routing to Module/Controller/Operation
        return ['type' => 'module', 'module' => [$module, $controller, $action], 'convert' => $convert];
    }

    /* *
 * Parse URL parameters and store them in the request
 * @access private
 * @param string $url Remaining URL parameter segments
 * @param array $var variables
 * @return void */
    private static function parseUrlParams($url, &$var = [])
    {
        if ($url) {
            if (Config::get('url_param_type')) {
                $var += explode('|', $url);
            } else {
                preg_replace_callback('/(\w+)\|([^\|]+)/', function ($match) use (&$var) {
                    $var[$match[1]] = strip_tags($match[2]);
                }, $url);
            }
        }
        // Set the parameters for the current request
        Request::instance()->route($var);
    }

    // Analyze variables in routing rules
    private static function parseVar($rule)
    {
        // Extracting variables from routing rules
        $var = [];
        foreach (explode('/', $rule) as $val) {
            $optional = false;
            if (false !== strpos($val, '<') && preg_match_all('/<(\w+(\??))>/', $val, $matches)) {
                foreach ($matches[1] as $name) {
                    if (strpos($name, '?')) {
                        $name     = substr($name, 0, -1);
                        $optional = true;
                    } else {
                        $optional = false;
                    }
                    $var[$name] = $optional ? 2 : 1;
                }
            }

            if (0 === strpos($val, '[:')) {
                // Optional parameters
                $optional = true;
                $val      = substr($val, 1, -1);
            }
            if (0 === strpos($val, ':')) {
                // URL parameters
                $name       = substr($val, 1);
                $var[$name] = $optional ? 2 : 1;
            }
        }
        return $var;
    }

    /* *
 * Get the key of the route parsing cache
 * @param Request $request
 * @return string */
    private static function getCheckCacheKey(Request $request)
    {
        static $key;

        if (empty($key)) {
            if ($callback = Config::get('route_check_cache_key')) {
                $key = call_user_func($callback, $request);
            } else {
                $key = "{$request->host(true)}|{$request->method()}|{$request->path()}";
            }
        }

        return $key;
    }
}
