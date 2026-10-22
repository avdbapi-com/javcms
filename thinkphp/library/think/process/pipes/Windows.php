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

use think\Process;

class Windows extends Pipes
{

    /* * @var array */
    private $files = [];
    /* * @var array */
    private $fileHandles = [];
    /* * @var array */
    private $readBytes = [
        Process::STDOUT => 0,
        Process::STDERR => 0,
    ];
    /* * @var bool */
    private $disableOutput;

    public function __construct($disableOutput, $input)
    {
        $this->disableOutput = (bool) $disableOutput;

        if (!$this->disableOutput) {

            $this->files = [
                Process::STDOUT => tempnam(sys_get_temp_dir(), 'sf_proc_stdout'),
                Process::STDERR => tempnam(sys_get_temp_dir(), 'sf_proc_stderr'),
            ];
            foreach ($this->files as $offset => $file) {
                $this->fileHandles[$offset] = fopen($this->files[$offset], 'rb');
                if (false === $this->fileHandles[$offset]) {
                    throw new \RuntimeException('Unable to open a temporary file for reading process output. Check the temporary directory and file permissions.');
                }
            }
        }

        if (is_resource($input)) {
            $this->input = $input;
        } else {
            $this->inputBuffer = $input;
        }
    }

    public function __destruct()
    {
        $this->close();
        $this->removeFiles();
    }

    /* *
 * {@inheritdoc} */
    public function getDescriptors()
    {
        if ($this->disableOutput) {
            $nullstream = fopen('NUL', 'c');

            return [
                ['pipe', 'r'],
                $nullstream,
                $nullstream,
            ];
        }

        return [
            ['pipe', 'r'],
            ['file', 'NUL', 'w'],
            ['file', 'NUL', 'w'],
        ];
    }

    /* *
 * {@inheritdoc} */
    public function getFiles()
    {
        return $this->files;
    }

    /* *
 * {@inheritdoc} */
    public function readAndWrite($blocking, $close = false)
    {
        $this->write($blocking, $close);

        $read = [];
        $fh   = $this->fileHandles;
        foreach ($fh as $type => $fileHandle) {
            if (0 !== fseek($fileHandle, $this->readBytes[$type])) {
                continue;
            }
            $data     = '';
            $dataread = null;
            while (!feof($fileHandle)) {
                if (false !== $dataread = fread($fileHandle, self::CHUNK_SIZE)) {
                    $data .= $dataread;
                }
            }
            if (0 < $length = strlen($data)) {
                $this->readBytes[$type] += $length;
                $read[$type] = $data;
            }

            if (false === $dataread || (true === $close && feof($fileHandle) && '' === $data)) {
                fclose($this->fileHandles[$type]);
                unset($this->fileHandles[$type]);
            }
        }

        return $read;
    }

    /* *
 * {@inheritdoc} */
    public function areOpen()
    {
        return (bool) $this->pipes && (bool) $this->fileHandles;
    }

    /* *
 * {@inheritdoc} */
    public function close()
    {
        parent::close();
        foreach ($this->fileHandles as $handle) {
            fclose($handle);
        }
        $this->fileHandles = [];
    }

    /* *
 * Create a Windows pipe handler for the process
 * @param Process $process
 * @param mixed $input Input to pass to STDIN
 * @return self */
    public static function create(Process $process, $input)
    {
        return new static($process->isOutputDisabled(), $input);
    }

    /* *
 * Delete temporary files */
    private function removeFiles()
    {
        foreach ($this->files as $filename) {
            if (file_exists($filename)) {
                @unlink($filename);
            }
        }
        $this->files = [];
    }

    /* *
 * Read available input and write it to the process's STDIN
 * @param bool $blocking Whether to wait briefly for stream readiness
 * @param bool $close Whether to release an input stream at EOF when no more data is read */
    private function write($blocking, $close)
    {
        if (empty($this->pipes)) {
            return;
        }

        $this->unblock();

        $r = null !== $this->input ? ['input' => $this->input] : null;
        $w = isset($this->pipes[0]) ? [$this->pipes[0]] : null;
        $e = null;

        if (false === $n = @stream_select($r, $w, $e, 0, $blocking ? Process::TIMEOUT_PRECISION * 1E6 : 0)) {
            if (!$this->hasSystemCallBeenInterrupted()) {
                $this->pipes = [];
            }

            return;
        }

        if (0 === $n) {
            return;
        }

        if (null !== $w && 0 < count($r)) {
            $data = '';
            while ($dataread = fread($r['input'], self::CHUNK_SIZE)) {
                $data .= $dataread;
            }

            $this->inputBuffer .= $data;

            if (false === $data || (true === $close && feof($r['input']) && '' === $data)) {
                $this->input = null;
            }
        }

        if (null !== $w && 0 < count($w)) {
            while (strlen($this->inputBuffer)) {
                $written = fwrite($w[0], $this->inputBuffer, 2 << 18);
                if ($written > 0) {
                    $this->inputBuffer = (string) substr($this->inputBuffer, $written);
                } else {
                    break;
                }
            }
        }

        if ('' === $this->inputBuffer && null === $this->input && isset($this->pipes[0])) {
            fclose($this->pipes[0]);
            unset($this->pipes[0]);
        }
    }
}
