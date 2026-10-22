<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\console\input;

class Argument
{

    const REQUIRED = 1;
    const OPTIONAL = 2;
    const IS_ARRAY = 4;

    private $name;
    private $mode;
    private $default;
    private $description;

    /* *
 * Initialize the argument definition
 * @param string $name Argument name
 * @param int $mode Argument mode: REQUIRED or OPTIONAL, optionally combined with IS_ARRAY
 * @param string $description
 * @param mixed $default Default argument value
 * @throws \InvalidArgumentException */
    public function __construct($name, $mode = null, $description = '', $default = null)
    {
        if (null === $mode) {
            $mode = self::OPTIONAL;
        } elseif (!is_int($mode) || $mode > 7 || $mode < 1) {
            throw new \InvalidArgumentException(sprintf('Argument mode "%s" is not valid.', $mode));
        }

        $this->name        = $name;
        $this->mode        = $mode;
        $this->description = $description;

        $this->setDefault($default);
    }

    /* *
 * Get the argument name
 * @return string */
    public function getName()
    {
        return $this->name;
    }

    /* *
 * Check whether the argument is required
 * @return bool */
    public function isRequired()
    {
        return self::REQUIRED === (self::REQUIRED & $this->mode);
    }

    /* *
 * Check whether the argument accepts multiple values
 * @return bool */
    public function isArray()
    {
        return self::IS_ARRAY === (self::IS_ARRAY & $this->mode);
    }

    /* *
 * Set the default argument value
 * @param mixed $default Default value
 * @throws \LogicException */
    public function setDefault($default = null)
    {
        if (self::REQUIRED === $this->mode && null !== $default) {
            throw new \LogicException('Cannot set a default value except for InputArgument::OPTIONAL mode.');
        }

        if ($this->isArray()) {
            if (null === $default) {
                $default = [];
            } elseif (!is_array($default)) {
                throw new \LogicException('A default value for an array argument must be an array.');
            }
        }

        $this->default = $default;
    }

    /* *
 * Get the default value
 * @return mixed */
    public function getDefault()
    {
        return $this->default;
    }

    /* *
 * Get description
 * @return string */
    public function getDescription()
    {
        return $this->description;
    }
}
