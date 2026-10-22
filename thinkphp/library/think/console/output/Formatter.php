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
namespace think\console\output;

use think\console\output\formatter\Stack as StyleStack;
use think\console\output\formatter\Style;

class Formatter
{

    private $decorated = false;
    private $styles    = [];
    private $styleStack;

    /* *
 * Escape unescaped opening angle brackets in text
 * @param string $text
 * @return string */
    public static function escape($text)
    {
        return preg_replace('/([^\\\\]?)</is', '$1\\<', $text);
    }

    /* *
 * Initialize the command-line output styles */
    public function __construct()
    {
        $this->setStyle('error', new Style('white', 'red'));
        $this->setStyle('info', new Style('green'));
        $this->setStyle('comment', new Style('yellow'));
        $this->setStyle('question', new Style('black', 'cyan'));
        $this->setStyle('highlight', new Style('red'));
        $this->setStyle('warning', new Style('black', 'yellow'));

        $this->styleStack = new StyleStack();
    }

    /* *
 * Set whether output styling is enabled
 * @param bool $decorated Whether to apply output styles */
    public function setDecorated($decorated)
    {
        $this->decorated = (bool) $decorated;
    }

    /* *
 * Check whether output styling is enabled
 * @return bool */
    public function isDecorated()
    {
        return $this->decorated;
    }

    /* *
 * Add a new style
 * @param string $name style name
 * @param Style $style Style instance */
    public function setStyle($name, Style $style)
    {
        $this->styles[strtolower($name)] = $style;
    }

    /* *
 * Check whether a named style exists
 * @param string $name
 * @return bool */
    public function hasStyle($name)
    {
        return isset($this->styles[strtolower($name)]);
    }

    /* *
 * Get a named style
 * @param string $name
 * @return Style
 * @throws \InvalidArgumentException */
    public function getStyle($name)
    {
        if (!$this->hasStyle($name)) {
            throw new \InvalidArgumentException(sprintf('Undefined style: %s', $name));
        }

        return $this->styles[strtolower($name)];
    }

    /* *
 * Format text using its style tags
 * @param string $message Text containing optional style tags
 * @return string */
    public function format($message)
    {
        $offset   = 0;
        $output   = '';
        $tagRegex = '[a-z][a-z0-9_=;-]*';
        preg_match_all("#<(($tagRegex) | /($tagRegex)?)>#isx", $message, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i => $match) {
            $pos  = $match[1];
            $text = $match[0];

            if (0 != $pos && '\\' == $message[$pos - 1]) {
                continue;
            }

            $output .= $this->applyCurrentStyle(substr($message, $offset, $pos - $offset));
            $offset = $pos + strlen($text);

            if ($open = '/' != $text[1]) {
                $tag = $matches[1][$i][0];
            } else {
                $tag = isset($matches[3][$i][0]) ? $matches[3][$i][0] : '';
            }

            if (!$open && !$tag) {
                // </>
                $this->styleStack->pop();
            } elseif (false === $style = $this->createStyleFromString(strtolower($tag))) {
                $output .= $this->applyCurrentStyle($text);
            } elseif ($open) {
                $this->styleStack->push($style);
            } else {
                $this->styleStack->pop($style);
            }
        }

        $output .= $this->applyCurrentStyle(substr($message, $offset));

        return str_replace('\\<', '<', $output);
    }

    /* *
 * @return StyleStack */
    public function getStyleStack()
    {
        return $this->styleStack;
    }

    /* *
 * Create a new style instance from a string
 * @param string $string
 * @return Style|bool */
    private function createStyleFromString($string)
    {
        if (isset($this->styles[$string])) {
            return $this->styles[$string];
        }

        if (!preg_match_all('/([^=]+)=([^;]+)(;|$)/', strtolower($string), $matches, PREG_SET_ORDER)) {
            return false;
        }

        $style = new Style();
        foreach ($matches as $match) {
            array_shift($match);

            if ('fg' == $match[0]) {
                $style->setForeground($match[1]);
            } elseif ('bg' == $match[0]) {
                $style->setBackground($match[1]);
            } else {
                try {
                    $style->setOption($match[1]);
                } catch (\InvalidArgumentException $e) {
                    return false;
                }
            }
        }

        return $style;
    }

    /* *
 * Apply the current stack style to the text when styling is enabled
 * @param string $text Text to format
 * @return string */
    private function applyCurrentStyle($text)
    {
        return $this->isDecorated() && strlen($text) > 0 ? $this->styleStack->getCurrent()->apply($text) : $text;
    }
}
