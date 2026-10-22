<?php
// +----------------------------------------------------------------------
// | TopThink [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2015 http://www.topthink.com All rights reserved.
// +----------------------------------------------------------------------
// | Author: zhangyajun <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\process;

class Utils
{

    /* *
 * Quote and escape a command-line argument
 * @param string $argument
 * @return string */
    public static function escapeArgument($argument)
    {

        if ('' === $argument) {
            return escapeshellarg($argument);
        }
        $escapedArgument = '';
        $quote           = false;
        foreach (preg_split('/(")/i', $argument, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if ('"' === $part) {
                $escapedArgument .= '\\"';
            } elseif (self::isSurroundedBy($part, '%')) {
                // Avoid environment variable expansion
                $escapedArgument .= '^%"' . substr($part, 1, -1) . '"^%';
            } else {
                // escape trailing backslash
                if ('\\' === substr($part, -1)) {
                    $part .= '\\';
                }
                $quote = true;
                $escapedArgument .= $part;
            }
        }
        if ($quote) {
            $escapedArgument = '"' . $escapedArgument . '"';
        }
        return $escapedArgument;
    }

    /* *
 * Validate process input and convert scalar values to strings
 * @param string $caller Caller name used in error messages
 * @param mixed $input Input to validate
 * @return string|resource|null
 * @throws \InvalidArgumentException */
    public static function validateInput($caller, $input)
    {
        if (null !== $input) {
            if (is_resource($input)) {
                return $input;
            }
            if (is_scalar($input)) {
                return (string) $input;
            }
            throw new \InvalidArgumentException(sprintf('%s only accepts strings or stream resources.', $caller));
        }
        return $input;
    }

    private static function isSurroundedBy($arg, $char)
    {
        return 2 < strlen($arg) && $char === $arg[0] && $char === $arg[strlen($arg) - 1];
    }

}
