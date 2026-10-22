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

use think\exception\ClassNotFoundException;

class Validate
{
    // Shared validator instance
    protected static $instance;

    // Custom validation callbacks
    protected static $type = [];

    // Validation type aliases
    protected $alias = [
        '>' => 'gt', '>=' => 'egt', '<' => 'lt', '<=' => 'elt', '=' => 'eq', 'same' => 'eq',
    ];

    // Currently validated rules
    protected $rule = [];

    // Custom validation error messages
    protected $message = [];
    // Validation field descriptions
    protected $field = [];

    // Default validation error message keys
    protected static $typeMsg = [
        'require'     => ':attribute require',
        'number'      => ':attribute must be numeric',
        'integer'     => ':attribute must be integer',
        'float'       => ':attribute must be float',
        'boolean'     => ':attribute must be bool',
        'email'       => ':attribute not a valid email address',
        'array'       => ':attribute must be a array',
        'accepted'    => ':attribute must be yes,on or 1',
        'date'        => ':attribute not a valid datetime',
        'file'        => ':attribute not a valid file',
        'image'       => ':attribute not a valid image',
        'alpha'       => ':attribute must be alpha',
        'alphaNum'    => ':attribute must be alpha-numeric',
        'alphaDash'   => ':attribute must be alpha-numeric, dash, underscore',
        'activeUrl'   => ':attribute not a valid domain or ip',
        'chs'         => ':attribute must be chinese',
        'chsAlpha'    => ':attribute must be chinese or alpha',
        'chsAlphaNum' => ':attribute must be chinese,alpha-numeric',
        'chsDash'     => ':attribute must be chinese,alpha-numeric,underscore, dash',
        'url'         => ':attribute not a valid url',
        'ip'          => ':attribute not a valid ip',
        'dateFormat'  => ':attribute must be dateFormat of :rule',
        'in'          => ':attribute must be in :rule',
        'notIn'       => ':attribute be notin :rule',
        'between'     => ':attribute must between :1 - :2',
        'notBetween'  => ':attribute not between :1 - :2',
        'length'      => 'size of :attribute must be :rule',
        'max'         => 'max size of :attribute must be :rule',
        'min'         => 'min size of :attribute must be :rule',
        'after'       => ':attribute cannot be less than :rule',
        'before'      => ':attribute cannot exceed :rule',
        'afterWith'   => ':attribute cannot be less than :rule',
        'beforeWith'  => ':attribute cannot exceed :rule',
        'expire'      => ':attribute not within :rule',
        'allowIp'     => 'access IP is not allowed',
        'denyIp'      => 'access IP denied',
        'confirm'     => ':attribute out of accord with :2',
        'different'   => ':attribute cannot be same with :2',
        'egt'         => ':attribute must greater than or equal :rule',
        'gt'          => ':attribute must greater than :rule',
        'elt'         => ':attribute must less than or equal :rule',
        'lt'          => ':attribute must less than :rule',
        'eq'          => ':attribute must equal :rule',
        'unique'      => ':attribute has exists',
        'regex'       => ':attribute not conform to the rules',
        'method'      => 'invalid Request method',
        'token'       => 'invalid token',
        'fileSize'    => 'filesize not match',
        'fileExt'     => 'extensions to upload is not allowed',
        'fileMime'    => 'mimetype to upload is not allowed',
    ];

    // Current validation scene
    protected $currentScene = null;

    // Named regular-expression patterns; regex = [\'zip\' => \'\\ d {6}\',...]
    protected $regex = [];

    // Validation scenes; scene = [\'edit\'=>\'name1,name2,...\']
    protected $scene = [];

    // Validation errors
    protected $error = [];

    // Whether to collect errors for multiple fields
    protected $batch = false;

    /* *
 * Constructor
 * @access public
 * @param array $rules validation rules
 * @param array $message Custom validation error messages
 * @param array $field Field display titles */
    public function __construct(array $rules = [], $message = [], $field = [])
    {
        $this->rule    = array_merge($this->rule, $rules);
        $this->message = array_merge($this->message, $message);
        $this->field   = array_merge($this->field, $field);
    }

    /* *
 * Get the shared validator instance
 * @access public
 * @param array $rules Validation rules
 * @param array $message Custom validation error messages
 * @param array $field Field display titles
 * @return Validate */
    public static function make($rules = [], $message = [], $field = [])
    {
        if (is_null(self::$instance)) {
            self::$instance = new self($rules, $message, $field);
        }
        return self::$instance;
    }

    /* *
 * Add field validation rules
 * @access public
 * @param string|array $name Field name or an array of field rules
 * @param mixed $rule Validation rules
 * @return Validate */
    public function rule($name, $rule = '')
    {
        if (is_array($name)) {
            $this->rule = array_merge($this->rule, $name);
        } else {
            $this->rule[$name] = $rule;
        }
        return $this;
    }

    /* *
 * Register custom validation callbacks
 * @access public
 * @param string|array $type Validation type name or an array of callbacks
 * @param mixed $callback callback method (or closure)
 * @return void */
    public static function extend($type, $callback = null)
    {
        if (is_array($type)) {
            self::$type = array_merge(self::$type, $type);
        } else {
            self::$type[$type] = $callback;
        }
    }

    /* *
 * Set default validation error messages
 * @access public
 * @param string|array $type Validation rule type name or array
 * @param string $msg Error message or language key
 * @return void */
    public static function setTypeMsg($type, $msg = null)
    {
        if (is_array($type)) {
            self::$typeMsg = array_merge(self::$typeMsg, $type);
        } else {
            self::$typeMsg[$type] = $msg;
        }
    }

    /* *
 * Set custom validation error messages
 * @access public
 * @param string|array $name Field name or an array of messages
 * @param string $message Error message
 * @return Validate */
    public function message($name, $message = '')
    {
        if (is_array($name)) {
            $this->message = array_merge($this->message, $name);
        } else {
            $this->message[$name] = $message;
        }
        return $this;
    }

    /* *
 * Select a validation scene or set its field rules
 * @access public
 * @param string|array $name Scene name or scene setting array
 * @param mixed $fields fields to validate
 * @return Validate */
    public function scene($name, $fields = null)
    {
        if (is_array($name)) {
            $this->scene = array_merge($this->scene, $name);
        }if (is_null($fields)) {
            // Set the current scene
            $this->currentScene = $name;
        } else {
            // Set the scene's field rules
            $this->scene[$name] = $fields;
        }
        return $this;
    }

    /* *
 * Check whether a validation scene is defined
 * @access public
 * @param string $name scene name
 * @return bool */
    public function hasScene($name)
    {
        return isset($this->scene[$name]);
    }

    /* *
 * Set whether to collect errors for multiple fields
 * @access public
 * @param bool $batch Whether to collect errors instead of stopping at the first failed field
 * @return Validate */
    public function batch($batch = true)
    {
        $this->batch = $batch;
        return $this;
    }

    /* *
 * Validate data using the specified rules and scene
 * @access public
 * @param array $data
 * @param mixed $rules validation rules
 * @param string $scene Validation scene
 * @return bool */
    public function check($data, $rules = [], $scene = '')
    {
        $this->error = [];

        if (empty($rules)) {
            // Read validation rules
            $rules = $this->rule;
        }

        // Resolve the selected validation scene
        $scene = $this->getScene($scene);
        if (is_array($scene)) {
            // Resolve scene fields and rule overrides
            $change = [];
            $array  = [];
            foreach ($scene as $k => $val) {
                if (is_numeric($k)) {
                    $array[] = $val;
                } else {
                    $array[]    = $k;
                    $change[$k] = $val;
                }
            }
        }

        foreach ($rules as $key => $item) {
            // field => rule1|rule2... field=>[\'rule1\',\'rule2\',...]
            if (is_numeric($key)) {
                // [field,rule1|rule2,msg1|msg2]
                $key  = $item[0];
                $rule = $item[1];
                if (isset($item[2])) {
                    $msg = is_string($item[2]) ? explode('|', $item[2]) : $item[2];
                } else {
                    $msg = [];
                }
            } else {
                $rule = $item;
                $msg  = [];
            }
            if (strpos($key, '|')) {
                // Use Field|Description to provide a field display title
                list($key, $title) = explode('|', $key);
            } else {
                $title = isset($this->field[$key]) ? $this->field[$key] : $key;
            }

            // Apply the selected scene
            if (!empty($scene)) {
                if ($scene instanceof \Closure && !call_user_func_array($scene, [$key, $data])) {
                    continue;
                } elseif (is_array($scene)) {
                    if (!in_array($key, $array)) {
                        continue;
                    } elseif (isset($change[$key])) {
                        // Override the field's validation rules
                        $rule = $change[$key];
                    }
                }
            }

            // Read the field value, including two-dimensional array keys
            $value = $this->getDataValue($data, $key);

            // Field validation
            if ($rule instanceof \Closure) {
                // Anonymous function validation supports passing in data for both the current field and all fields
                $result = call_user_func_array($rule, [$value, $data]);
            } else {
                $result = $this->checkItem($key, $value, $rule, $data, $title, $msg);
            }

            if (true !== $result) {
                // A result other than true indicates validation failure
                if (!empty($this->batch)) {
                    // Collect the field's validation error
                    if (is_array($result)) {
                        $this->error = array_merge($this->error, $result);
                    } else {
                        $this->error[$key] = $result;
                    }
                } else {
                    $this->error = $result;
                    return false;
                }
            }
        }
        return !empty($this->error) ? false : true;
    }

    /* *
 * Validate data according to validation rules
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rules Validation rules
 * @return mixed */
    protected function checkRule($value, $rules)
    {
        if ($rules instanceof \Closure) {
            return call_user_func_array($rules, [$value]);
        } elseif (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        foreach ($rules as $key => $rule) {
            if ($rule instanceof \Closure) {
                $result = call_user_func_array($rule, [$value]);
            } else {
                // Resolve the validation type
                list($type, $rule) = $this->getValidateType($key, $rule);

                $callback = isset(self::$type[$type]) ? self::$type[$type] : [$this, $type];

                $result = call_user_func_array($callback, [$value, $rule]);
            }

            if (true !== $result) {
                return $result;
            }
        }

        return true;
    }

    /* *
 * Validate one field and return its result or error message
 * @access protected
 * @param string $field Field name
 * @param mixed $value field value
 * @param mixed $rules validation rules
 * @param array $data
 * @param string $title Field display title
 * @param array $msg Per-rule error messages
 * @return mixed */
    protected function checkItem($field, $value, $rules, $data, $title = '', $msg = [])
    {
        // Supports multi-rule validation require|in: a, b, c|... or [\'require\', \'in\' => \'a, b, c\',...]
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }
        $i = 0;
        foreach ($rules as $key => $rule) {
            if ($rule instanceof \Closure) {
                $result = call_user_func_array($rule, [$value, $data]);
                $info   = is_numeric($key) ? '' : $key;
            } else {
                // Resolve the validation type
                list($type, $rule, $info) = $this->getValidateType($key, $rule);

                // Run required-field rules, or validate a value that is neither null nor an empty string
                if (0 === strpos($info, 'require') || (!is_null($value) && '' !== $value)) {
                    // Choose the registered callback or validator method
                    $callback = isset(self::$type[$type]) ? self::$type[$type] : [$this, $type];
                    // Validate the field value
                    $result = call_user_func_array($callback, [$value, $rule, $data, $field, $title]);
                } else {
                    $result = true;
                }
            }

            if (false === $result) {
                // Resolve the error message for a failed rule
                if (isset($msg[$i])) {
                    $message = $msg[$i];
                    if (is_string($message) && strpos($message, '{%') === 0) {
                        $message = Lang::get(substr($message, 2, -1));
                    }
                } else {
                    $message = $this->getRuleMsg($field, $title, $info, $rule);
                }
                return $message;
            } elseif (true !== $result) {
                // Return a custom error message
                if (is_string($result) && false !== strpos($result, ':')) {
                    $result = str_replace([':attribute', ':rule'], [$title, (string) $rule], $result);
                }
                return $result;
            }
            $i++;
        }
        return $result;
    }

    /* *
 * Resolve the validation type, rule value and message identifier
 * @access protected
 * @param mixed $key
 * @param mixed $rule
 * @return array */
    protected function getValidateType($key, $rule)
    {
        // Resolve the validation type
        if (!is_numeric($key)) {
            return [$key, $rule, $key];
        }

        if (strpos($rule, ':')) {
            list($type, $rule) = explode(':', $rule, 2);
            if (isset($this->alias[$type])) {
                // Resolve a validation type alias
                $type = $this->alias[$type];
            }
            $info = $type;
        } elseif (method_exists($this, $rule)) {
            $type = $rule;
            $info = $rule;
            $rule = '';
        } else {
            $type = 'is';
            $info = $rule;
        }

        return [$type, $rule, $info];
    }

    /* *
 * Check whether the value strictly matches another field
 * @access protected
 * @param mixed $value field value
 * @param mixed $rule Comparison field name
 * @param array $data
 * @param string $field Current field name
 * @return bool */
    protected function confirm($value, $rule, $data, $field = '')
    {
        if ('' == $rule) {
            if (strpos($field, '_confirm')) {
                $rule = strstr($field, '_confirm', true);
            } else {
                $rule = $field . '_confirm';
            }
        }
        return $this->getDataValue($data, $rule) === $value;
    }

    /* *
 * Check whether the value differs from another field
 * @access protected
 * @param mixed $value field value
 * @param mixed $rule Comparison field name
 * @param array $data
 * @return bool */
    protected function different($value, $rule, $data)
    {
        return $this->getDataValue($data, $rule) != $value;
    }

    /* *
 * Verify if it is greater than or equal to a certain value
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Comparison field name or numeric value
 * @param array $data
 * @return bool */
    protected function egt($value, $rule, $data)
    {
        $val = $this->getDataValue($data, $rule);
        return !is_null($val) && $value >= $val;
    }

    /* *
 * Verify that it is greater than a certain value
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Comparison field name or numeric value
 * @param array $data
 * @return bool */
    protected function gt($value, $rule, $data)
    {
        $val = $this->getDataValue($data, $rule);
        return !is_null($val) && $value > $val;
    }

    /* *
 * Verify if it is less than or equal to a certain value
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Comparison field name or numeric value
 * @param array $data
 * @return bool */
    protected function elt($value, $rule, $data)
    {
        $val = $this->getDataValue($data, $rule);
        return !is_null($val) && $value <= $val;
    }

    /* *
 * Verify that it is less than a certain value
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Comparison field name or numeric value
 * @param array $data
 * @return bool */
    protected function lt($value, $rule, $data)
    {
        $val = $this->getDataValue($data, $rule);
        return !is_null($val) && $value < $val;
    }

    /* *
 * Verify that it's equal to a value
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Comparison value
 * @return bool */
    protected function eq($value, $rule)
    {
        return $value == $rule;
    }

    /* *
 * Check a built-in type or registered validation rule
 * @access protected
 * @param mixed $value Field value
 * @param string $rule Validation type or regular-expression pattern
 * @param array $data Input data
 * @return bool */
    protected function is($value, $rule, $data = [])
    {
        switch ($rule) {
            case 'require':
                // Check the required-value rule
                $result = !empty($value) || '0' == $value;
                break;
            case 'accepted':
                // Check for an accepted value
                $result = in_array($value, ['1', 'on', 'yes']);
                break;
            case 'date':
                // Is it a valid date
                $result = false !== strtotime($value);
                break;
            case 'alpha':
                // Only letters are allowed
                $result = $this->regex($value, '/^[A-Za-z]+$/');
                break;
            case 'alphaNum':
                // Only letters and numbers are allowed
                $result = $this->regex($value, '/^[A-Za-z0-9]+$/');
                break;
            case 'alphaDash':
                // Only letters, numbers, underscores and dashes are allowed
                $result = $this->regex($value, '/^[A-Za-z0-9\-\_]+$/');
                break;
            case 'chs':
                // Only Chinese characters are allowed
                $result = $this->regex($value, '/^[\x{4e00}-\x{9fa5}]+$/u');
                break;
            case 'chsAlpha':
                // Only Chinese characters and letters are allowed
                $result = $this->regex($value, '/^[\x{4e00}-\x{9fa5}a-zA-Z]+$/u');
                break;
            case 'chsAlphaNum':
                // Only Chinese characters, letters, and numbers are allowed
                $result = $this->regex($value, '/^[\x{4e00}-\x{9fa5}a-zA-Z0-9]+$/u');
                break;
            case 'chsDash':
                // Only Chinese characters, letters, numbers, underscores and dashes are allowed
                $result = $this->regex($value, '/^[\x{4e00}-\x{9fa5}a-zA-Z0-9\_\-]+$/u');
                break;
            case 'activeUrl':
                // Check for a DNS record
                $result = checkdnsrr($value);
                break;
            case 'ip':
                // Is it an IP address
                $result = $this->filter($value, [FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6]);
                break;
            case 'url':
                // Whether it is a URL address
                $result = $this->filter($value, FILTER_VALIDATE_URL);
                break;
            case 'float':
                // Is it a float
                $result = $this->filter($value, FILTER_VALIDATE_FLOAT);
                break;
            case 'number':
                $result = is_numeric($value);
                break;
            case 'integer':
                // Is it an integer
                $result = $this->filter($value, FILTER_VALIDATE_INT);
                break;
            case 'email':
                // Is this an email address
                $result = $this->filter($value, FILTER_VALIDATE_EMAIL);
                break;
            case 'boolean':
                // Whether it is a boolean value
                $result = in_array($value, [true, false, 0, 1, '0', '1'], true);
                break;
            case 'array':
                // Is it an array
                $result = is_array($value);
                break;
            case 'file':
                $result = $value instanceof File;
                break;
            case 'image':
                $result = $value instanceof File && in_array($this->getImageType($value->getRealPath()), [1, 2, 3, 6]);
                break;
            case 'token':
                $result = $this->token($value, '__token__', $data);
                break;
            default:
                if (isset(self::$type[$rule])) {
                    // Run a registered custom validation rule
                    $result = call_user_func_array(self::$type[$rule], [$value]);
                } else {
                    // Apply a regular-expression rule
                    $result = $this->regex($value, $rule);
                }
        }
        return $result;
    }

    // Detect the image type
    protected function getImageType($image)
    {
        if (function_exists('exif_imagetype')) {
            return exif_imagetype($image);
        } else {
            try {
                $info = getimagesize($image);
                return $info ? $info[2] : false;
            } catch (\Exception $e) {
                return false;
            }
        }
    }

    /* *
 * Check DNS records of type A, MX, NS, SOA, PTR, CNAME, AAAA, A6, SRV, NAPTR, TXT or ANY
 * @access protected
 * @param mixed $value field value
 * @param mixed $rule DNS record type
 * @return bool */
    protected function activeUrl($value, $rule)
    {
        if (!in_array($rule, ['A', 'MX', 'NS', 'SOA', 'PTR', 'CNAME', 'AAAA', 'A6', 'SRV', 'NAPTR', 'TXT', 'ANY'])) {
            $rule = 'MX';
        }
        return checkdnsrr($value, $rule);
    }

    /* *
 * Verify if the IP is valid
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule IP version: ipv4 or ipv6
 * @return bool */
    protected function ip($value, $rule)
    {
        if (!in_array($rule, ['ipv4', 'ipv6'])) {
            $rule = 'ipv4';
        }
        return $this->filter($value, [FILTER_VALIDATE_IP, 'ipv6' == $rule ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4]);
    }

    /* *
 * Check uploaded file extensions
 * @access protected
 * @param mixed $file Uploaded file or array of files
 * @param mixed $rule Allowed extensions
 * @return bool */
    protected function fileExt($file, $rule)
    {
        if (is_array($file)) {
            foreach ($file as $item) {
                if (!($item instanceof File) || !$item->checkExt($rule)) {
                    return false;
                }
            }
            return true;
        } elseif ($file instanceof File) {
            return $file->checkExt($rule);
        } else {
            return false;
        }
    }

    /* *
 * Check uploaded file MIME types
 * @access protected
 * @param mixed $file Uploaded file or array of files
 * @param mixed $rule Allowed MIME types
 * @return bool */
    protected function fileMime($file, $rule)
    {
        if (is_array($file)) {
            foreach ($file as $item) {
                if (!($item instanceof File) || !$item->checkMime($rule)) {
                    return false;
                }
            }
            return true;
        } elseif ($file instanceof File) {
            return $file->checkMime($rule);
        } else {
            return false;
        }
    }

    /* *
 * Check uploaded file sizes
 * @access protected
 * @param mixed $file Uploaded file or array of files
 * @param mixed $rule Maximum file size in bytes
 * @return bool */
    protected function fileSize($file, $rule)
    {
        if (is_array($file)) {
            foreach ($file as $item) {
                if (!($item instanceof File) || !$item->checkSize($rule)) {
                    return false;
                }
            }
            return true;
        } elseif ($file instanceof File) {
            return $file->checkSize($rule);
        } else {
            return false;
        }
    }

    /* *
 * Verify the width, height and type of the image
 * @access protected
 * @param mixed $file Upload file
 * @param mixed $rule Expected width, height and optional image type
 * @return bool */
    protected function image($file, $rule)
    {
        if (!($file instanceof File)) {
            return false;
        }
        if ($rule) {
            $rule                        = explode(',', $rule);
            list($width, $height, $type) = getimagesize($file->getRealPath());
            if (isset($rule[2])) {
                $imageType = strtolower($rule[2]);
                if ('jpeg' == $imageType) {
                    $imageType = 'jpg';
                }
                if (image_type_to_extension($type, false) != $imageType) {
                    return false;
                }
            }

            list($w, $h) = $rule;
            return $w == $width && $h == $height;
        } else {
            return in_array($this->getImageType($file->getRealPath()), [1, 2, 3, 6]);
        }
    }

    /* *
 * Check the HTTP request method
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Expected HTTP method
 * @return bool */
    protected function method($value, $rule)
    {
        $method = Request::instance()->method();
        return strtoupper($rule) == $method;
    }

    /* *
 * Verify that the time and date match the specified format
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Date and time format
 * @return bool */
    protected function dateFormat($value, $rule)
    {
        $info = date_parse_from_format($rule, $value);
        return 0 == $info['warning_count'] && 0 == $info['error_count'];
    }

    /* *
 * Check that the field value is unique
 * @access protected
 * @param mixed $value field value
 * @param mixed $rule validation rule format: data table, field name, exclude ID, primary key name
 * @param array $data
 * @param string $field Validation field name
 * @return bool */
    protected function unique($value, $rule, $data, $field)
    {
        if (is_string($rule)) {
            $rule = explode(',', $rule);
        }
        if (false !== strpos($rule[0], '\\')) {
            // Specify the model class
            $db = new $rule[0];
        } else {
            try {
                $db = Loader::model($rule[0]);
            } catch (ClassNotFoundException $e) {
                $db = Db::name($rule[0]);
            }
        }
        $key = isset($rule[1]) ? $rule[1] : $field;

        if (strpos($key, '^')) {
            // Build uniqueness criteria from multiple fields
            $fields = explode('^', $key);
            foreach ($fields as $key) {
                if (isset($data[$key])) {
                    $map[$key] = $data[$key];
                }
            }
        } elseif (strpos($key, '=')) {
            parse_str($key, $map);
        } elseif (isset($data[$field])) {
            $map[$key] = $data[$field];
        } else {
            $map = [];
        }

        $pk = isset($rule[3]) ? $rule[3] : $db->getPk();
        if (is_string($pk)) {
            if (isset($rule[2])) {
                $map[$pk] = ['neq', $rule[2]];
            } elseif (isset($data[$pk])) {
                $map[$pk] = ['neq', $data[$pk]];
            }
        }
        if ($db->where($map)->field($pk)->find()) {
            return false;
        }
        return true;
    }

    /* *
 * Validate using a behavior callback
 * @access protected
 * @param mixed $value field value
 * @param mixed $rule Behavior identifier
 * @param array $data
 * @return mixed */
    protected function behavior($value, $rule, $data)
    {
        return Hook::exec($rule, '', $data);
    }

    /* *
 * Validate using filter_var
 * @access protected
 * @param mixed $value field value
 * @param mixed $rule Filter identifier with optional flags or options
 * @return bool */
    protected function filter($value, $rule)
    {
        if (is_string($rule) && strpos($rule, ',')) {
            list($rule, $param) = explode(',', $rule);
        } elseif (is_array($rule)) {
            $param = isset($rule[1]) ? $rule[1] : null;
            $rule  = $rule[0];
        } else {
            $param = null;
        }
        return false !== filter_var($value, is_int($rule) ? $rule : filter_id($rule), $param);
    }

    /* *
 * Require this field when another field equals the specified value
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Comparison field name and value
 * @param array $data
 * @return bool */
    protected function requireIf($value, $rule, $data)
    {
        list($field, $val) = explode(',', $rule);
        if ($this->getDataValue($data, $field) == $val) {
            return !empty($value) || '0' == $value;
        } else {
            return true;
        }
    }

    /* *
 * Require this field when the callback returns a truthy value
 * @access protected
 * @param mixed $value field value
 * @param mixed $rule Callback receiving the field value and input data
 * @param array $data
 * @return bool */
    protected function requireCallback($value, $rule, $data)
    {
        $result = call_user_func_array($rule, [$value, $data]);
        if ($result) {
            return !empty($value) || '0' == $value;
        } else {
            return true;
        }
    }

    /* *
 * Require this field when another field has a nonempty value
 * @access protected
 * @param mixed $value field value
 * @param mixed $rule Other field name
 * @param array $data
 * @return bool */
    protected function requireWith($value, $rule, $data)
    {
        $val = $this->getDataValue($data, $rule);
        if (!empty($val)) {
            return !empty($value) || '0' == $value;
        } else {
            return true;
        }
    }

    /* *
 * Check whether the value is in the allowed list
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Allowed values
 * @return bool */
    protected function in($value, $rule)
    {
        return in_array($value, is_array($rule) ? $rule : explode(',', $rule));
    }

    /* *
 * Check whether the value is absent from the excluded list
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Excluded values
 * @return bool */
    protected function notIn($value, $rule)
    {
        return !in_array($value, is_array($rule) ? $rule : explode(',', $rule));
    }

    /* *
 * Check whether the value is within the inclusive range
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Minimum and maximum values
 * @return bool */
    protected function between($value, $rule)
    {
        if (is_string($rule)) {
            $rule = explode(',', $rule);
        }
        list($min, $max) = $rule;
        return $value >= $min && $value <= $max;
    }

    /* *
 * Check whether the value is outside the inclusive range
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Minimum and maximum values
 * @return bool */
    protected function notBetween($value, $rule)
    {
        if (is_string($rule)) {
            $rule = explode(',', $rule);
        }
        list($min, $max) = $rule;
        return $value < $min || $value > $max;
    }

    /* *
 * Check string length, array count or file size
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Required length or inclusive range
 * @return bool */
    protected function length($value, $rule)
    {
        if (is_array($value)) {
            $length = count($value);
        } elseif ($value instanceof File) {
            $length = $value->getSize();
        } else {
            $length = mb_strlen((string) $value);
        }

        if (strpos($rule, ',')) {
            // length range
            list($min, $max) = explode(',', $rule);
            return $length >= $min && $length <= $max;
        } else {
            // Check the specified length
            return $length == $rule;
        }
    }

    /* *
 * Check the maximum string length, array count or file size
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Maximum length, count or size
 * @return bool */
    protected function max($value, $rule)
    {
        if (is_array($value)) {
            $length = count($value);
        } elseif ($value instanceof File) {
            $length = $value->getSize();
        } else {
            $length = mb_strlen((string) $value);
        }
        return $length <= $rule;
    }

    /* *
 * Check the minimum string length, array count or file size
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Minimum length, count or size
 * @return bool */
    protected function min($value, $rule)
    {
        if (is_array($value)) {
            $length = count($value);
        } elseif ($value instanceof File) {
            $length = $value->getSize();
        } else {
            $length = mb_strlen((string) $value);
        }
        return $length >= $rule;
    }

    /* *
 * Check whether the date is on or after the specified date
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Comparison date
 * @param array $data
 * @return bool */
    protected function after($value, $rule, $data)
    {
        return strtotime($value) >= strtotime($rule);
    }

    /* *
 * Check whether the date is on or before the specified date
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Comparison date
 * @param array $data
 * @return bool */
    protected function before($value, $rule, $data)
    {
        return strtotime($value) <= strtotime($rule);
    }

    /* *
 * Check whether the date is on or after another date field
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Comparison field name
 * @param array $data
 * @return bool */
    protected function afterWith($value, $rule, $data)
    {
        $rule = $this->getDataValue($data, $rule);
        return !is_null($rule) && strtotime($value) >= strtotime($rule);
    }

    /* *
 * Check whether the date is on or before another date field
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Comparison field name
 * @param array $data
 * @return bool */
    protected function beforeWith($value, $rule, $data)
    {
        $rule = $this->getDataValue($data, $rule);
        return !is_null($rule) && strtotime($value) <= strtotime($rule);
    }

    /* *
 * Check whether the request time is within the inclusive time range
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Start and end times
 * @return bool */
    protected function expire($value, $rule)
    {
        if (is_string($rule)) {
            $rule = explode(',', $rule);
        }
        list($start, $end) = $rule;
        if (!is_numeric($start)) {
            $start = strtotime($start);
        }

        if (!is_numeric($end)) {
            $end = strtotime($end);
        }
        return $_SERVER['REQUEST_TIME'] >= $start && $_SERVER['REQUEST_TIME'] <= $end;
    }

    /* *
 * Check whether the client IP address is allowed
 * @access protected
 * @param string $value field value
 * @param mixed $rule Allowed IP addresses
 * @return bool */
    protected function allowIp($value, $rule)
    {
        return in_array($_SERVER['REMOTE_ADDR'], is_array($rule) ? $rule : explode(',', $rule));
    }

    /* *
 * Check whether the client IP address is absent from the denied list
 * @access protected
 * @param string $value field value
 * @param mixed $rule Denied IP addresses
 * @return bool */
    protected function denyIp($value, $rule)
    {
        return !in_array($_SERVER['REMOTE_ADDR'], is_array($rule) ? $rule : explode(',', $rule));
    }

    /* *
 * Validate a scalar value against a regular expression
 * @access protected
 * @param mixed $value field value
 * @param mixed $rule Regular-expression pattern or registered pattern name
 * @return bool */
    protected function regex($value, $rule)
    {
        if (isset($this->regex[$rule])) {
            $rule = $this->regex[$rule];
        }
        if (0 !== strpos($rule, '/') && !preg_match('/\/[imsU]{0,4}$/', $rule)) {
            // Wrap an undelimited pattern with slashes and start/end anchors
            $rule = '/^' . $rule . '$/';
        }
        return is_scalar($value) && 1 === preg_match($rule, (string) $value);
    }

    /* *
 * Validate form tokens
 * @access protected
 * @param mixed $value Field value
 * @param mixed $rule Token field and session key
 * @param array $data
 * @return bool */
    protected function token($value, $rule, $data)
    {
        $rule = !empty($rule) ? $rule : '__token__';
        if (!isset($data[$rule]) || !Session::has($rule)) {
            // Token data is invalid
            return false;
        }

        // Token verification
        if (isset($data[$rule]) && Session::get($rule) === $data[$rule]) {
            // Prevent duplicate submissions
            Session::delete($rule); // Remove the stored token after successful validation
            return true;
        }
        // Remove the stored token after a mismatch
        Session::delete($rule);
        return false;
    }

    // Get validation errors
    public function getError()
    {
        return $this->error;
    }

    /* *
 * Read a field value or return a numeric comparison value
 * @access protected
 * @param array $data
 * @param string $key Field key, two-dimensional dotted key, or numeric value
 * @return mixed */
    protected function getDataValue($data, $key)
    {
        if (is_numeric($key)) {
            $value = $key;
        } elseif (strpos($key, '.')) {
            // Supports two-dimensional array validation
            list($name1, $name2) = explode('.', $key);
            $value               = isset($data[$name1][$name2]) ? $data[$name1][$name2] : null;
        } else {
            $value = isset($data[$key]) ? $data[$key] : null;
        }
        return $value;
    }

    /* *
 * Get error messages for validation rules
 * @access protected
 * @param string $attribute Field name
 * @param string $title Field display title
 * @param string $type Validation rule name
 * @param mixed $rule Validation rule value
 * @return string */
    protected function getRuleMsg($attribute, $title, $type, $rule)
    {
        if (isset($this->message[$attribute . '.' . $type])) {
            $msg = $this->message[$attribute . '.' . $type];
        } elseif (isset($this->message[$attribute][$type])) {
            $msg = $this->message[$attribute][$type];
        } elseif (isset($this->message[$attribute])) {
            $msg = $this->message[$attribute];
        } elseif (isset(self::$typeMsg[$type])) {
            $msg = self::$typeMsg[$type];
        } elseif (0 === strpos($type, 'require')) {
            $msg = self::$typeMsg['require'];
        } else {
            $msg = $title . Lang::get('not conform to the rules');
        }

        if (is_string($msg) && 0 === strpos($msg, '{%')) {
            $msg = Lang::get(substr($msg, 2, -1));
        } elseif (Lang::has($msg)) {
            $msg = Lang::get($msg);
        }

        if (is_string($msg) && is_scalar($rule) && false !== strpos($msg, ':')) {
            // variable substitution
            if (is_string($rule) && strpos($rule, ',')) {
                $array = array_pad(explode(',', $rule), 3, '');
            } else {
                $array = array_pad([], 3, '');
            }
            $msg = str_replace(
                [':attribute', ':rule', ':1', ':2', ':3'],
                [$title, (string) $rule, $array[0], $array[1], $array[2]],
                $msg);
        }
        return $msg;
    }

    /* *
 * Get the field rules for the selected validation scene
 * @access protected
 * @param string $scene Validation scene name
 * @return array */
    protected function getScene($scene = '')
    {
        if (empty($scene)) {
            // Use the current scene when none is specified
            $scene = $this->currentScene;
        }

        if (!empty($scene) && isset($this->scene[$scene])) {
            // Read the configured validation scene
            $scene = $this->scene[$scene];
            if (is_string($scene)) {
                $scene = explode(',', $scene);
            }
        } else {
            $scene = [];
        }
        return $scene;
    }

    public static function __callStatic($method, $params)
    {
        $class = self::make();
        if (method_exists($class, $method)) {
            return call_user_func_array([$class, $method], $params);
        } else {
            throw new \BadMethodCallException('method not exists:' . __CLASS__ . '->' . $method);
        }
    }
}
