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

class Url
{
    // Root prefix for generated URLs
    protected static $root;
    protected static $bindCheck;

    /* *
 * Generate a URL using reverse routing
 * @param string $url Route address
 * @param string|array $vars parameters (supports arrays and strings) a=val&b=val2... [\'a\' =>\' val1 \',\' b \'=>\' val2 \']
 * @param string|bool $suffix URL suffix; true uses the configured suffix
 * @param boolean|string $domain Whether to include a domain, or an explicit domain
 * @return string */
    public static function build($url = '', $vars = '', $suffix = true, $domain = false)
    {
        if (false === $domain && Route::rules('domain')) {
            $domain = true;
        }
        // Parsing URLs
        if (0 === strpos($url, '[') && $pos = strpos($url, ']')) {
            // [name] indicates that the URL is generated using the route name
            $name = substr($url, 1, $pos - 1);
            $url  = 'name' . substr($url, $pos + 1);
        }
        if (false === strpos($url, '://') && 0 !== strpos($url, '/')) {
            $info = parse_url($url);
            $url  = !empty($info['path']) ? $info['path'] : '';
            if (isset($info['fragment'])) {
                // Parse the URL fragment
                $anchor = $info['fragment'];
                if (false !== strpos($anchor, '?')) {
                    // Parsing parameters
                    list($anchor, $info['query']) = explode('?', $anchor, 2);
                }
                if (false !== strpos($anchor, '@')) {
                    // Resolve domain names
                    list($anchor, $domain) = explode('@', $anchor, 2);
                }
            } elseif (strpos($url, '@') && false === strpos($url, '\\')) {
                // Resolve domain names
                list($url, $domain) = explode('@', $url, 2);
            }
        }

        // Parsing parameters
        if (is_string($vars)) {
            // aaa=1&bbb=2 converted to array
            parse_str($vars, $vars);
        }

        if ($url) {
            $rule = Route::name(isset($name) ? $name : $url . (isset($info['query']) ? '?' . $info['query'] : ''));
            if (is_null($rule) && isset($info['query'])) {
                $rule = Route::name($url);
                // Merge URL query parameters into vars
                parse_str($info['query'], $params);
                $vars = array_merge($params, $vars);
                unset($info['query']);
            }
        }
        if (!empty($rule) && $match = self::getRuleUrl($rule, $vars)) {
            // Use the matched named route
            $url = $match[0];
            // Replace optional separators
            $url = preg_replace(['/(\W)\?$/', '/(\W)\?/'], ['', '\1'], $url);
            if (!empty($match[1])) {
                $domain = $match[1];
            }
            if (!is_null($match[2])) {
                $suffix = $match[2];
            }
        } elseif (!empty($rule) && isset($name)) {
            throw new \InvalidArgumentException('route name not exists:' . $name);
        } else {
            // Check alias routes
            $alias      = Route::rules('alias');
            $matchAlias = false;
            if ($alias) {
                // Resolve route aliases
                foreach ($alias as $key => $val) {
                    if (is_array($val)) {
                        $val = $val[0];
                    }
                    if (0 === strpos($url, $val)) {
                        $url        = $key . substr($url, strlen($val));
                        $matchAlias = true;
                        break;
                    }
                }
            }
            if (!$matchAlias) {
                // Parse the URL directly when no alias matches
                $url = self::parseUrl($url, $domain);
            }
            if (isset($info['query'])) {
                // Merge URL query parameters into vars
                parse_str($info['query'], $params);
                $vars = array_merge($params, $vars);
            }
        }

        // Detect URL bindings
        if (!self::$bindCheck) {
            $type = Route::getBind('type');
            if ($type) {
                $bind = Route::getBind($type);
                if ($bind && 0 === strpos($url, $bind)) {
                    $url = substr($url, strlen($bind) + 1);
                }
            }
        }
        // Restore URL separators
        $depr = Config::get('pathinfo_depr');
        $url  = str_replace('/', $depr, $url);

        // URL suffix
        $suffix = in_array($url, ['/', '']) ? '' : self::parseSuffix($suffix);
        // URL fragment
        $anchor = !empty($anchor) ? '#' . $anchor : '';
        // Assemble URL parameters
        if (!empty($vars)) {
            // Adding parameters
            if (Config::get('url_common_param')) {
                $vars = http_build_query($vars);
                $url .= $suffix . '?' . $vars . $anchor;
            } else {
                $paramType = Config::get('url_param_type');
                foreach ($vars as $var => $val) {
                    if ('' !== trim($val)) {
                        if ($paramType) {
                            $url .= $depr . urlencode($val);
                        } else {
                            $url .= $depr . $var . $depr . urlencode($val);
                        }
                    }
                }
                $url .= $suffix . $anchor;
            }
        } else {
            $url .= $suffix . $anchor;
        }
        // Build the domain prefix
        $domain = self::parseDomain($url, $domain);
        // URL assembly
        $url = $domain . rtrim(self::$root ?: Request::instance()->root(), '/') . '/' . ltrim($url, '/');

        self::$bindCheck = false;
        return $url;
    }

    // Directly parse URL addresses
    protected static function parseUrl($url, &$domain)
    {
        $request = Request::instance();
        if (0 === strpos($url, '/')) {
            // Resolve directly as a route address
            $url = substr($url, 1);
        } elseif (false !== strpos($url, '\\')) {
            // Convert a class path to a URL path
            $url = ltrim(str_replace('\\', '/', $url), '/');
        } elseif (0 === strpos($url, '@')) {
            // Remove the controller-path prefix
            $url = substr($url, 1);
        } else {
            // Parse to module/controller/action
            $module  = $request->module();
            $domains = Route::rules('domain');
            if (true === $domain && 2 == substr_count($url, '/')) {
                $current = $request->host();
                $match   = [];
                $pos     = [];
                foreach ($domains as $key => $item) {
                    if (isset($item['[bind]']) && 0 === strpos($url, $item['[bind]'][0])) {
                        $pos[$key] = strlen($item['[bind]'][0]) + 1;
                        $match[]   = $key;
                        $module    = '';
                    }
                }
                if ($match) {
                    $domain = current($match);
                    foreach ($match as $item) {
                        if (0 === strpos($current, $item)) {
                            $domain = $item;
                        }
                    }
                    self::$bindCheck = true;
                    $url             = substr($url, $pos[$domain]);
                }
            } elseif ($domain) {
                if (isset($domains[$domain]['[bind]'][0])) {
                    $bindModule = $domains[$domain]['[bind]'][0];
                    if ($bindModule && !in_array($bindModule[0], ['\\', '@'])) {
                        $module = '';
                    }
                }
            }
            $module = $module ? $module . '/' : '';

            $controller = $request->controller();
            if ('' == $url) {
                // An empty string uses the current module/controller/action
                $action = $request->action();
            } else {
                $path       = explode('/', $url);
                $action     = array_pop($path);
                $controller = empty($path) ? $controller : array_pop($path);
                $module     = empty($path) ? $module : array_pop($path) . '/';
            }
            if (Config::get('url_convert')) {
                $action     = strtolower($action);
                $controller = Loader::parseName($controller);
            }
            $url = $module . $controller . '/' . $action;
        }
        return $url;
    }

    // Build the domain prefix
    protected static function parseDomain(&$url, $domain)
    {
        if (!$domain) {
            return '';
        }
        $request    = Request::instance();
        $rootDomain = Config::get('url_domain_root');
        if (true === $domain) {
            // Automatically determine domain names
            $domain = Config::get('app_host') ?: $request->host();

            $domains = Route::rules('domain');
            if ($domains) {
                $route_domain = array_keys($domains);
                foreach ($route_domain as $domain_prefix) {
                    if (0 === strpos($domain_prefix, '*.') && strpos($domain, ltrim($domain_prefix, '*.')) !== false) {
                        foreach ($domains as $key => $rule) {
                            $rule = is_array($rule) ? $rule[0] : $rule;
                            if (is_string($rule) && false === strpos($key, '*') && 0 === strpos($url, $rule)) {
                                $url    = ltrim($url, $rule);
                                $domain = $key;
                                // Generate corresponding subdomain
                                if (!empty($rootDomain)) {
                                    $domain .= $rootDomain;
                                }
                                break;
                            } elseif (false !== strpos($key, '*')) {
                                if (!empty($rootDomain)) {
                                    $domain .= $rootDomain;
                                }
                                break;
                            }
                        }
                    }
                }
            }

        } else {
            if (empty($rootDomain)) {
                $host       = Config::get('app_host') ?: $request->host();
                $rootDomain = substr_count($host, '.') > 1 ? substr(strstr($host, '.'), 1) : $host;
            }
            if (substr_count($domain, '.') < 2 && !strpos($domain, $rootDomain)) {
                $domain .= '.' . $rootDomain;
            }
        }
        if (false !== strpos($domain, '://')) {
            $scheme = '';
        } else {
            $scheme = $request->isSsl() || Config::get('is_https') ? 'https://' : 'http://';
        }
        return $scheme . $domain;
    }

    // Parse URL suffixes
    protected static function parseSuffix($suffix)
    {
        if ($suffix) {
            $suffix = true === $suffix ? Config::get('url_html_suffix') : $suffix;
            if ($pos = strpos($suffix, '|')) {
                $suffix = substr($suffix, 0, $pos);
            }
        }
        return (empty($suffix) || 0 === strpos($suffix, '.')) ? $suffix : '.' . $suffix;
    }

    // Match route addresses
    public static function getRuleUrl($rule, &$vars = [])
    {
        foreach ($rule as $item) {
            list($url, $pattern, $domain, $suffix) = $item;
            if (empty($pattern)) {
                return [rtrim($url, '$'), $domain, $suffix];
            }
            $type = Config::get('url_common_param');
            foreach ($pattern as $key => $val) {
                if (isset($vars[$key])) {
                    $url = str_replace(['[:' . $key . ']', '<' . $key . '?>', ':' . $key . '', '<' . $key . '>'], $type ? $vars[$key] : urlencode($vars[$key]), $url);
                    unset($vars[$key]);
                    $result = [$url, $domain, $suffix];
                } elseif (2 == $val) {
                    $url    = str_replace(['/[:' . $key . ']', '[:' . $key . ']', '<' . $key . '?>'], '', $url);
                    $result = [$url, $domain, $suffix];
                } else {
                    break;
                }
            }
            if (isset($result)) {
                return $result;
            }
        }
        return false;
    }

    // Set the root prefix for generated URLs
    public static function root($root)
    {
        self::$root = $root;
        Request::instance()->root($root);
    }
}
