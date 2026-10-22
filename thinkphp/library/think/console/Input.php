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

namespace think\console;

use think\console\input\Argument;
use think\console\input\Definition;
use think\console\input\Option;

class Input
{

    /* *
 * @var Definition */
    protected $definition;

    /* *
 * @var Option[] */
    protected $options = [];

    /* *
 * @var Argument[] */
    protected $arguments = [];

    protected $interactive = true;

    private $tokens;
    private $parsed;

    public function __construct($argv = null)
    {
        if (null === $argv) {
            $argv = $_SERVER['argv'];
            // Remove the script name from the argument vector
            array_shift($argv);
        }

        $this->tokens = $argv;

        $this->definition = new Definition();
    }

    protected function setTokens(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /* *
 * Bind the input to an argument and option definition
 * @param Definition $definition Input argument and option definitions */
    public function bind(Definition $definition)
    {
        $this->arguments  = [];
        $this->options    = [];
        $this->definition = $definition;

        $this->parse();
    }

    /* *
 * Parse the input tokens into arguments and options */
    protected function parse()
    {
        $parseOptions = true;
        $this->parsed = $this->tokens;
        while (null !== $token = array_shift($this->parsed)) {
            if ($parseOptions && '' == $token) {
                $this->parseArgument($token);
            } elseif ($parseOptions && '--' == $token) {
                $parseOptions = false;
            } elseif ($parseOptions && 0 === strpos($token, '--')) {
                $this->parseLongOption($token);
            } elseif ($parseOptions && '-' === $token[0] && '-' !== $token) {
                $this->parseShortOption($token);
            } else {
                $this->parseArgument($token);
            }
        }
    }

    /* *
 * Parse short options
 * @param string $token Current input token */
    private function parseShortOption($token)
    {
        $name = substr($token, 1);

        if (strlen($name) > 1) {
            if ($this->definition->hasShortcut($name[0])
                && $this->definition->getOptionForShortcut($name[0])->acceptValue()
            ) {
                $this->addShortOption($name[0], substr($name, 1));
            } else {
                $this->parseShortOptionSet($name);
            }
        } else {
            $this->addShortOption($name, null);
        }
    }

    /* *
 * Parse a group of short options
 * @param string $name
 * @throws \RuntimeException */
    private function parseShortOptionSet($name)
    {
        $len = strlen($name);
        for ($i = 0; $i < $len; ++$i) {
            if (!$this->definition->hasShortcut($name[$i])) {
                throw new \RuntimeException(sprintf('The "-%s" option does not exist.', $name[$i]));
            }

            $option = $this->definition->getOptionForShortcut($name[$i]);
            if ($option->acceptValue()) {
                $this->addLongOption($option->getName(), $i === $len - 1 ? null : substr($name, $i + 1));

                break;
            } else {
                $this->addLongOption($option->getName(), null);
            }
        }
    }

    /* *
 * Parse a long option
 * @param string $token Current input token */
    private function parseLongOption($token)
    {
        $name = substr($token, 2);

        if (false !== $pos = strpos($name, '=')) {
            $this->addLongOption(substr($name, 0, $pos), substr($name, $pos + 1));
        } else {
            $this->addLongOption($name, null);
        }
    }

    /* *
 * Parse a positional argument
 * @param string $token
 * @throws \RuntimeException */
    private function parseArgument($token)
    {
        $c = count($this->arguments);

        if ($this->definition->hasArgument($c)) {
            $arg = $this->definition->getArgument($c);

            $this->arguments[$arg->getName()] = $arg->isArray() ? [$token] : $token;

        } elseif ($this->definition->hasArgument($c - 1) && $this->definition->getArgument($c - 1)->isArray()) {
            $arg = $this->definition->getArgument($c - 1);

            $this->arguments[$arg->getName()][] = $token;
        } else {
            throw new \RuntimeException('Too many arguments.');
        }
    }

    /* *
 * Add a value for a short option
 * @param string $shortcut Option shortcut
 * @param mixed $value
 * @throws \RuntimeException */
    private function addShortOption($shortcut, $value)
    {
        if (!$this->definition->hasShortcut($shortcut)) {
            throw new \RuntimeException(sprintf('The "-%s" option does not exist.', $shortcut));
        }

        $this->addLongOption($this->definition->getOptionForShortcut($shortcut)->getName(), $value);
    }

    /* *
 * Add the value of a long option
 * @param string $name option name
 * @param mixed $value
 * @throws \RuntimeException */
    private function addLongOption($name, $value)
    {
        if (!$this->definition->hasOption($name)) {
            throw new \RuntimeException(sprintf('The "--%s" option does not exist.', $name));
        }

        $option = $this->definition->getOption($name);

        if (false === $value) {
            $value = null;
        }

        if (null !== $value && !$option->acceptValue()) {
            throw new \RuntimeException(sprintf('The "--%s" option does not accept a value.', $name, $value));
        }

        if (null === $value && $option->acceptValue() && count($this->parsed)) {
            $next = array_shift($this->parsed);
            if (isset($next[0]) && '-' !== $next[0]) {
                $value = $next;
            } elseif (empty($next)) {
                $value = '';
            } else {
                array_unshift($this->parsed, $next);
            }
        }

        if (null === $value) {
            if ($option->isValueRequired()) {
                throw new \RuntimeException(sprintf('The "--%s" option requires a value.', $name));
            }

            if (!$option->isArray()) {
                $value = $option->isValueOptional() ? $option->getDefault() : true;
            }
        }

        if ($option->isArray()) {
            $this->options[$name][] = $value;
        } else {
            $this->options[$name] = $value;
        }
    }

    /* *
 * Get the first positional argument from the original tokens
 * @return string|null */
    public function getFirstArgument()
    {
        foreach ($this->tokens as $token) {
            if ($token && '-' === $token[0]) {
                continue;
            }

            return $token;
        }
        return;
    }

    /* *
 * Check whether the original tokens contain an option
 * @param string|array $values Option names to check
 * @return bool */
    public function hasParameterOption($values)
    {
        $values = (array) $values;

        foreach ($this->tokens as $token) {
            foreach ($values as $value) {
                if ($token === $value || 0 === strpos($token, $value . '=')) {
                    return true;
                }
            }
        }

        return false;
    }

    /* *
 * Get an option value from the original tokens
 * @param string|array $values Option names to check
 * @param mixed $default Default value
 * @return mixed The option value */
    public function getParameterOption($values, $default = false)
    {
        $values = (array) $values;
        $tokens = $this->tokens;

        while (0 < count($tokens)) {
            $token = array_shift($tokens);

            foreach ($values as $value) {
                if ($token === $value || 0 === strpos($token, $value . '=')) {
                    if (false !== $pos = strpos($token, '=')) {
                        return substr($token, $pos + 1);
                    }

                    return array_shift($tokens);
                }
            }
        }

        return $default;
    }

    /* *
 * Validate that all required arguments were provided
 * @throws \RuntimeException */
    public function validate()
    {
        if (count($this->arguments) < $this->definition->getArgumentRequiredCount()) {
            throw new \RuntimeException('Not enough arguments.');
        }
    }

    /* *
 * Check if the input is interactive
 * @return bool */
    public function isInteractive()
    {
        return $this->interactive;
    }

    /* *
 * Set whether the input is interactive
 * @param bool $interactive Whether user interaction is enabled */
    public function setInteractive($interactive)
    {
        $this->interactive = (bool) $interactive;
    }

    /* *
 * Get all argument values, including defaults
 * @return array */
    public function getArguments()
    {
        return array_merge($this->definition->getArgumentDefaults(), $this->arguments);
    }

    /* *
 * Get an argument value by name
 * @param string $name Argument name
 * @return mixed
 * @throws \InvalidArgumentException */
    public function getArgument($name)
    {
        if (!$this->definition->hasArgument($name)) {
            throw new \InvalidArgumentException(sprintf('The "%s" argument does not exist.', $name));
        }

        return isset($this->arguments[$name]) ? $this->arguments[$name] : $this->definition->getArgument($name)
            ->getDefault();
    }

    /* *
 * Set an argument value
 * @param string $name Argument name
 * @param string $value
 * @throws \InvalidArgumentException */
    public function setArgument($name, $value)
    {
        if (!$this->definition->hasArgument($name)) {
            throw new \InvalidArgumentException(sprintf('The "%s" argument does not exist.', $name));
        }

        $this->arguments[$name] = $value;
    }

    /* *
 * Check whether an argument is defined
 * @param string|int $name Argument name or position
 * @return bool */
    public function hasArgument($name)
    {
        return $this->definition->hasArgument($name);
    }

    /* *
 * Get all options
 * @return array */
    public function getOptions()
    {
        return array_merge($this->definition->getOptionDefaults(), $this->options);
    }

    /* *
 * Get an option value by name
 * @param string $name
 * @return mixed
 * @throws \InvalidArgumentException */
    public function getOption($name)
    {
        if (!$this->definition->hasOption($name)) {
            throw new \InvalidArgumentException(sprintf('The "%s" option does not exist.', $name));
        }

        return isset($this->options[$name]) ? $this->options[$name] : $this->definition->getOption($name)->getDefault();
    }

    /* *
 * Set an option value
 * @param string $name Option name
 * @param string|bool $value
 * @throws \InvalidArgumentException */
    public function setOption($name, $value)
    {
        if (!$this->definition->hasOption($name)) {
            throw new \InvalidArgumentException(sprintf('The "%s" option does not exist.', $name));
        }

        $this->options[$name] = $value;
    }

    /* *
 * Check whether a defined option has a parsed value
 * @param string $name option name
 * @return bool */
    public function hasOption($name)
    {
        return $this->definition->hasOption($name) && isset($this->options[$name]);
    }

    /* *
 * Escape a token for use in a command-line string
 * @param string $token
 * @return string */
    public function escapeToken($token)
    {
        return preg_match('{^[\w-]+$}', $token) ? $token : escapeshellarg($token);
    }

    /* *
 * Returns a string of arguments passed to the command
 * @return string */
    public function __toString()
    {
        $tokens = array_map(function ($token) {
            if (preg_match('{^(-[^=]+=)(.+)}', $token, $match)) {
                return $match[1] . $this->escapeToken($match[2]);
            }

            if ($token && '-' !== $token[0]) {
                return $this->escapeToken($token);
            }

            return $token;
        }, $this->tokens);

        return implode(' ', $tokens);
    }
}
