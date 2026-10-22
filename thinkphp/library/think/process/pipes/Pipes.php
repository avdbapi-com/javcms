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

namespace think\process\pipes;

abstract class Pipes
{

    /* * @var array */
    public $pipes = [];

    /* * @var string */
    protected $inputBuffer = '';
    /* * @var resource|null */
    protected $input;

    /* * @var bool */
    private $blocked = true;

    const CHUNK_SIZE = 16384;

    /* *
 * Return the descriptor specification for proc_open
 * @return array */
    abstract public function getDescriptors();

    /* *
 * Return temporary filenames indexed by stream, if the pipes use temporary files.
 * @return string[] */
    abstract public function getFiles();

    /* *
 * Read output and write pending input using file handles and pipes.
 * @param bool $blocking Whether to wait for stream readiness
 * @param bool $close Whether to close drained pipes that have reached EOF.
 * @return string[] */
    abstract public function readAndWrite($blocking, $close = false);

    /* *
 * Report the open state of the pipe handler.
 * @return bool */
    abstract public function areOpen();

    /* *
 * {@inheritdoc} */
    public function close()
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        $this->pipes = [];
    }

    /* *
 * Check whether the last error reports an interrupted system call
 * @return bool */
    protected function hasSystemCallBeenInterrupted()
    {
        $lastError = error_get_last();

        return isset($lastError['message']) && false !== stripos($lastError['message'], 'interrupted system call');
    }

    protected function unblock()
    {
        if (!$this->blocked) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, 0);
        }
        if (null !== $this->input) {
            stream_set_blocking($this->input, 0);
        }

        $this->blocked = false;
    }
}
