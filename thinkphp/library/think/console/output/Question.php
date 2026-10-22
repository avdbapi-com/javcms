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

class Question
{

    private $question;
    private $attempts;
    private $hidden         = false;
    private $hiddenFallback = true;
    private $autocompleterValues;
    private $validator;
    private $default;
    private $normalizer;

    /* *
 * Initialize the question and default answer
 * @param string $question Question text
 * @param mixed $default Default answer */
    public function __construct($question, $default = null)
    {
        $this->question = $question;
        $this->default  = $default;
    }

    /* *
 * Get the question text
 * @return string */
    public function getQuestion()
    {
        return $this->question;
    }

    /* *
 * Get the default answer
 * @return mixed */
    public function getDefault()
    {
        return $this->default;
    }

    /* *
 * Check whether input should be hidden
 * @return bool */
    public function isHidden()
    {
        return $this->hidden;
    }

    /* *
 * Set whether input should be hidden
 * @param bool $hidden
 * @return Question */
    public function setHidden($hidden)
    {
        if ($this->autocompleterValues) {
            throw new \LogicException('A hidden question cannot use the autocompleter.');
        }

        $this->hidden = (bool) $hidden;

        return $this;
    }

    /* *
 * Check whether visible input is allowed when hidden input is unavailable
 * @return bool */
    public function isHiddenFallback()
    {
        return $this->hiddenFallback;
    }

    /* *
 * Set whether to fall back to visible input when hidden input is unavailable
 * @param bool $fallback
 * @return Question */
    public function setHiddenFallback($fallback)
    {
        $this->hiddenFallback = (bool) $fallback;

        return $this;
    }

    /* *
 * Get the autocomplete suggestions
 * @return null|array|\Traversable */
    public function getAutocompleterValues()
    {
        return $this->autocompleterValues;
    }

    /* *
 * Set the autocomplete suggestions
 * @param null|array|\Traversable $values Autocomplete suggestions
 * @return Question
 * @throws \InvalidArgumentException
 * @throws \LogicException */
    public function setAutocompleterValues($values)
    {
        if (is_array($values) && $this->isAssoc($values)) {
            $values = array_merge(array_keys($values), array_values($values));
        }

        if (null !== $values && !is_array($values)) {
            if (!$values instanceof \Traversable || $values instanceof \Countable) {
                throw new \InvalidArgumentException('Autocompleter values can be either an array, `null` or an object implementing both `Countable` and `Traversable` interfaces.');
            }
        }

        if ($this->hidden) {
            throw new \LogicException('A hidden question cannot use the autocompleter.');
        }

        $this->autocompleterValues = $values;

        return $this;
    }

    /* *
 * Set up an answer validator
 * @param null|callable $validator
 * @return Question The current instance */
    public function setValidator($validator)
    {
        $this->validator = $validator;

        return $this;
    }

    /* *
 * Get the answer validator
 * @return null|callable */
    public function getValidator()
    {
        return $this->validator;
    }

    /* *
 * Set the maximum number of validation attempts
 * @param null|int $attempts Maximum attempts; null allows unlimited attempts
 * @return Question
 * @throws \InvalidArgumentException */
    public function setMaxAttempts($attempts)
    {
        if (null !== $attempts && $attempts < 1) {
            throw new \InvalidArgumentException('Maximum number of attempts must be a positive value.');
        }

        $this->attempts = $attempts;

        return $this;
    }

    /* *
 * Get the maximum number of retries
 * @return null|int */
    public function getMaxAttempts()
    {
        return $this->attempts;
    }

    /* *
 * Set the callback that normalizes the answer
 * @param string|\\Closure $normalizer
 * @return Question */
    public function setNormalizer($normalizer)
    {
        $this->normalizer = $normalizer;

        return $this;
    }

    /* *
 * Get the answer normalizer
 * The normalizer can be a callable string, a closure, or an object implementing __invoke.
 * @return string|\\Closure */
    public function getNormalizer()
    {
        return $this->normalizer;
    }

    protected function isAssoc($array)
    {
        return (bool) count(array_filter(array_keys($array), 'is_string'));
    }
}
