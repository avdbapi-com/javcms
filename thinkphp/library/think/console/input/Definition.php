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

class Definition
{

    /* *
 * @var Argument[] */
    private $arguments;

    private $requiredCount;
    private $hasAnArrayArgument = false;
    private $hasOptional;

    /* *
 * @var Option[] */
    private $options;
    private $shortcuts;

    /* *
 * Initialize the argument and option definitions
 * @param array $definition
 * @api */
    public function __construct(array $definition = [])
    {
        $this->setDefinition($definition);
    }

    /* *
 * Set the command argument and option definitions
 * @param array $definition Argument and option definitions */
    public function setDefinition(array $definition)
    {
        $arguments = [];
        $options   = [];
        foreach ($definition as $item) {
            if ($item instanceof Option) {
                $options[] = $item;
            } else {
                $arguments[] = $item;
            }
        }

        $this->setArguments($arguments);
        $this->setOptions($options);
    }

    /* *
 * Replace the argument definitions
 * @param Argument[] $arguments Argument definitions */
    public function setArguments($arguments = [])
    {
        $this->arguments          = [];
        $this->requiredCount      = 0;
        $this->hasOptional        = false;
        $this->hasAnArrayArgument = false;
        $this->addArguments($arguments);
    }

    /* *
 * Add argument definitions
 * @param Argument[] $arguments Argument definitions
 * @api */
    public function addArguments($arguments = [])
    {
        if (null !== $arguments) {
            foreach ($arguments as $argument) {
                $this->addArgument($argument);
            }
        }
    }

    /* *
 * Add an argument definition
 * @param Argument $argument Argument definition
 * @throws \LogicException */
    public function addArgument(Argument $argument)
    {
        if (isset($this->arguments[$argument->getName()])) {
            throw new \LogicException(sprintf('An argument with name "%s" already exists.', $argument->getName()));
        }

        if ($this->hasAnArrayArgument) {
            throw new \LogicException('Cannot add an argument after an array argument.');
        }

        if ($argument->isRequired() && $this->hasOptional) {
            throw new \LogicException('Cannot add a required argument after an optional one.');
        }

        if ($argument->isArray()) {
            $this->hasAnArrayArgument = true;
        }

        if ($argument->isRequired()) {
            ++$this->requiredCount;
        } else {
            $this->hasOptional = true;
        }

        $this->arguments[$argument->getName()] = $argument;
    }

    /* *
 * Get an argument definition by name or position
 * @param string|int $name Argument name or position
 * @return Argument Argument definition
 * @throws \InvalidArgumentException */
    public function getArgument($name)
    {
        if (!$this->hasArgument($name)) {
            throw new \InvalidArgumentException(sprintf('The "%s" argument does not exist.', $name));
        }

        $arguments = is_int($name) ? array_values($this->arguments) : $this->arguments;

        return $arguments[$name];
    }

    /* *
 * Check whether an argument is defined by name or position
 * @param string|int $name Argument name or position
 * @return bool
 * @api */
    public function hasArgument($name)
    {
        $arguments = is_int($name) ? array_values($this->arguments) : $this->arguments;

        return isset($arguments[$name]);
    }

    /* *
 * Get all argument definitions
 * @return Argument[] Argument definitions */
    public function getArguments()
    {
        return $this->arguments;
    }

    /* *
 * Get the maximum number of accepted arguments
 * @return int */
    public function getArgumentCount()
    {
        return $this->hasAnArrayArgument ? PHP_INT_MAX : count($this->arguments);
    }

    /* *
 * Get the number of required arguments
 * @return int */
    public function getArgumentRequiredCount()
    {
        return $this->requiredCount;
    }

    /* *
 * Get the default values for all arguments
 * @return array */
    public function getArgumentDefaults()
    {
        $values = [];
        foreach ($this->arguments as $argument) {
            $values[$argument->getName()] = $argument->getDefault();
        }

        return $values;
    }

    /* *
 * Replace the option definitions
 * @param Option[] $options Options */
    public function setOptions($options = [])
    {
        $this->options   = [];
        $this->shortcuts = [];
        $this->addOptions($options);
    }

    /* *
 * Add options
 * @param Option[] $options Options
 * @api */
    public function addOptions($options = [])
    {
        foreach ($options as $option) {
            $this->addOption($option);
        }
    }

    /* *
 * Add an option
 * @param Option $option Option
 * @throws \\LogicException
 * @api */
    public function addOption(Option $option)
    {
        if (isset($this->options[$option->getName()]) && !$option->equals($this->options[$option->getName()])) {
            throw new \LogicException(sprintf('An option named "%s" already exists.', $option->getName()));
        }

        if ($option->getShortcut()) {
            foreach (explode('|', $option->getShortcut()) as $shortcut) {
                if (isset($this->shortcuts[$shortcut])
                    && !$option->equals($this->options[$this->shortcuts[$shortcut]])
                ) {
                    throw new \LogicException(sprintf('An option with shortcut "%s" already exists.', $shortcut));
                }
            }
        }

        $this->options[$option->getName()] = $option;
        if ($option->getShortcut()) {
            foreach (explode('|', $option->getShortcut()) as $shortcut) {
                $this->shortcuts[$shortcut] = $option->getName();
            }
        }
    }

    /* *
 * Get an option definition by name
 * @param string $name
 * @return Option
 * @throws \InvalidArgumentException
 * @api */
    public function getOption($name)
    {
        if (!$this->hasOption($name)) {
            throw new \InvalidArgumentException(sprintf('The "--%s" option does not exist.', $name));
        }

        return $this->options[$name];
    }

    /* *
 * Check whether an option is defined by name
 * @param string $name option name
 * @return bool
 * @api */
    public function hasOption($name)
    {
        return isset($this->options[$name]);
    }

    /* *
 * Get all options
 * @return Option []
 * @api */
    public function getOptions()
    {
        return $this->options;
    }

    /* *
 * Check whether an option shortcut is defined
 * @param string $name short name
 * @return bool */
    public function hasShortcut($name)
    {
        return isset($this->shortcuts[$name]);
    }

    /* *
 * Get an option definition by shortcut
 * @param string $shortcut short name
 * @return Option */
    public function getOptionForShortcut($shortcut)
    {
        return $this->getOption($this->shortcutToName($shortcut));
    }

    /* *
 * Get the default values for all options
 * @return array */
    public function getOptionDefaults()
    {
        $values = [];
        foreach ($this->options as $option) {
            $values[$option->getName()] = $option->getDefault();
        }

        return $values;
    }

    /* *
 * Resolve an option shortcut to its full name
 * @param string $shortcut Option shortcut
 * @return string
 * @throws \InvalidArgumentException */
    private function shortcutToName($shortcut)
    {
        if (!isset($this->shortcuts[$shortcut])) {
            throw new \InvalidArgumentException(sprintf('The "-%s" option does not exist.', $shortcut));
        }

        return $this->shortcuts[$shortcut];
    }

    /* *
 * Get the argument and option synopsis
 * @param bool $short Whether to abbreviate the option list
 * @return string */
    public function getSynopsis($short = false)
    {
        $elements = [];

        if ($short && $this->getOptions()) {
            $elements[] = '[options]';
        } elseif (!$short) {
            foreach ($this->getOptions() as $option) {
                $value = '';
                if ($option->acceptValue()) {
                    $value = sprintf(' %s%s%s', $option->isValueOptional() ? '[' : '', strtoupper($option->getName()), $option->isValueOptional() ? ']' : '');
                }

                $shortcut   = $option->getShortcut() ? sprintf('-%s|', $option->getShortcut()) : '';
                $elements[] = sprintf('[%s--%s%s]', $shortcut, $option->getName(), $value);
            }
        }

        if (count($elements) && $this->getArguments()) {
            $elements[] = '[--]';
        }

        foreach ($this->getArguments() as $argument) {
            $element = '<' . $argument->getName() . '>';
            if (!$argument->isRequired()) {
                $element = '[' . $element . ']';
            } elseif ($argument->isArray()) {
                $element .= ' (' . $element . ')';
            }

            if ($argument->isArray()) {
                $element .= '...';
            }

            $elements[] = $element;
        }

        return implode(' ', $elements);
    }
}
