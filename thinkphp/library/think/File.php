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

use SplFileObject;

class File extends SplFileObject
{
    /* *
 * @var string|array Error message key, optionally paired with replacement values */
    private $error = '';

    /* *
 * @var string Current file path */
    protected $filename;

    /* *
 * @var string|null Saved file name */
    protected $saveName;

    /* *
 * @var string|callable File naming rule */
    protected $rule = 'date';

    /* *
 * @var array Upload validation rules */
    protected $validate = [];

    /* *
 * @var bool|null Whether test mode is enabled */
    protected $isTest;

    /* *
 * @var array|null Upload information */
    protected $info;

    /* *
 * @var array Cached file hashes by algorithm */
    protected $hash = [];

    /* *
 * File constructor.
 * @access public
 * @param string $filename filename
 * @param string $mode access mode */
    public function __construct($filename, $mode = 'r')
    {
        parent::__construct($filename, $mode);
        $this->filename = $this->getRealPath() ?: $this->getPathname();
    }

    /* *
 * Set test mode for file validation and moving
 * @access public
 * @param bool $test Whether to enable test mode
 * @return $this */
    public function isTest($test = false)
    {
        $this->isTest = $test;

        return $this;
    }

    /* *
 * Set the upload information
 * @access public
 * @param array $info Upload file info
 * @return $this */
    public function setUploadInfo($info)
    {
        $this->info = $info;

        return $this;
    }

    /* *
 * Get an upload information field, or all information when the field is not set
 * @access public
 * @param string $name Upload information field name
 * @return mixed */
    public function getInfo($name = '')
    {
        return isset($this->info[$name]) ? $this->info[$name] : $this->info;
    }

    /* *
 * Get the saved file name
 * @access public
 * @return string|null */
    public function getSaveName()
    {
        return $this->saveName;
    }

    /* *
 * Set the saved file name
 * @access public
 * @param string $saveName Saved file name
 * @return $this */
    public function setSaveName($saveName)
    {
        $this->saveName = $saveName;

        return $this;
    }

    /* *
 * Get the hash value of the file
 * @access public
 * @param string $type Hash algorithm
 * @return string|false */
    public function hash($type = 'sha1')
    {
        if (!isset($this->hash[$type])) {
            $this->hash[$type] = hash_file($type, $this->filename);
        }

        return $this->hash[$type];
    }

    /* *
 * Ensure the directory exists, creating it if necessary
 * @access protected
 * @param string $path directory
 * @return boolean */
    protected function checkPath($path)
    {
        if (is_dir($path) || mkdir($path, 0755, true)) {
            return true;
        }

        $this->error = ['directory {:path} creation failed', ['path' => $path]];

        return false;
    }

    /* *
 * Get the file MIME type
 * @access public
 * @return string|false */
    public function getMime()
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        return finfo_file($finfo, $this->filename);
    }

    /* *
 * Set file naming rules
 * @access public
 * @param string|callable $rule Naming rule or callback
 * @return $this */
    public function rule($rule)
    {
        $this->rule = $rule;

        return $this;
    }

    /* *
 * Set upload validation rules
 * @access public
 * @param array $rule Validation rules
 * @return $this */
    public function validate(array $rule = [])
    {
        $this->validate = $rule;

        return $this;
    }

    /* *
 * Check that this is an uploaded file, or an existing file in test mode
 * @access public
 * @return bool */
    public function isValid()
    {
        return $this->isTest ? is_file($this->filename) : is_uploaded_file($this->filename);
    }

    /* *
 * Validate the uploaded file using the supplied or stored rules
 * @access public
 * @param array $rule validation rules
 * @return bool */
    public function check($rule = [])
    {
        $rule = $rule ?: $this->validate;

        /* Check file size */
        if (isset($rule['size']) && !$this->checkSize($rule['size'])) {
            $this->error = 'filesize not match';
            return false;
        }

        /* Check the file MIME type */
        if (isset($rule['type']) && !$this->checkMime($rule['type'])) {
            $this->error = 'mimetype to upload is not allowed';
            return false;
        }

        /* Check the file extension */
        if (isset($rule['ext']) && !$this->checkExt($rule['ext'])) {
            $this->error = 'extensions to upload is not allowed';
            return false;
        }

        /* Check image files */
        if (!$this->checkImg()) {
            $this->error = 'illegal image files';
            return false;
        }

        return true;
    }

    /* *
 * Check whether the uploaded file extension is allowed
 * @access public
 * @param array|string $ext Allowed extensions as an array or comma-separated string
 * @return bool */
    public function checkExt($ext)
    {
        if (is_string($ext)) {
            $ext = explode(',', $ext);
        }

        $extension = strtolower(pathinfo($this->getInfo('name'), PATHINFO_EXTENSION));

        return in_array($extension, $ext);
    }

    /* *
 * Check for a recognized image type when the extension is in the image list
 * @access public
 * @return bool */
    public function checkImg()
    {
        $extension = strtolower(pathinfo($this->getInfo('name'), PATHINFO_EXTENSION));

        // For listed image extensions, require a recognized image type; accept other extensions here
        return !in_array($extension, ['gif', 'jpg', 'jpeg', 'bmp', 'png', 'swf']) || in_array($this->getImageType($this->filename), [1, 2, 3, 4, 6, 13]);
    }

    /* *
 * Detect the image type
 * @access protected
 * @param string $image Image file path
 * @return bool|int */
    protected function getImageType($image)
    {
        if (function_exists('exif_imagetype')) {
            return exif_imagetype($image);
        }

        try {
            $info = getimagesize($image);
            return $info ? $info[2] : false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /* *
 * Check the size of uploaded files
 * @access public
 * @param integer $size Maximum size in bytes
 * @return bool */
    public function checkSize($size)
    {
        return $this->getSize() <= $size;
    }

    /* *
 * Check whether the uploaded file MIME type is allowed
 * @access public
 * @param array|string $mime Allowed MIME types as an array or comma-separated string
 * @return bool */
    public function checkMime($mime)
    {
        $mime = is_string($mime) ? explode(',', $mime) : $mime;

        return in_array(strtolower($this->getMime()), $mime);
    }

    /* *
 * Validate and move the uploaded file to its destination
 * @access public
 * @param string $path Destination directory
 * @param string|bool $savename File name; true generates one, while false or an empty string uses the original name
 * @param boolean $replace Whether to overwrite a file with the same name
 * @return false|File */
    public function move($path, $savename = true, $replace = true)
    {
        // Convert a reported upload error code to an error message key
        if (!empty($this->info['error'])) {
            $this->error($this->info['error']);
            return false;
        }

        // Verify that the source is a valid upload or test file
        if (!$this->isValid()) {
            $this->error = 'upload illegal files';
            return false;
        }

        // Apply upload validation rules
        if (!$this->check()) {
            return false;
        }

        $path = rtrim($path, DS) . DS;
        // Determine the saved file name
        $saveName = $this->buildSaveName($savename);
        $filename = $path . $saveName;

        // Ensure the destination directory exists
        if (false === $this->checkPath(dirname($filename))) {
            return false;
        }

        // Reject an existing destination when overwriting is disabled
        if (!$replace && is_file($filename)) {
            $this->error = ['has the same filename: {:filename}', ['filename' => $filename]];
            return false;
        }

        /* Move a file */
        if ($this->isTest) {
            rename($this->filename, $filename);
        } elseif (!move_uploaded_file($this->filename, $filename)) {
            $this->error = 'upload write error';
            return false;
        }

        // Create a File instance for the destination
        $file = new self($filename);
        $file->setSaveName($saveName)->setUploadInfo($this->info);

        return $file;
    }

    /* *
 * Build the saved file name
 * @access protected
 * @param string|bool $savename File name; true generates one, while false or an empty string uses the original name
 * @return string */
    protected function buildSaveName($savename)
    {
        // Automatically generate file names
        if (true === $savename) {
            if ($this->rule instanceof \Closure) {
                $savename = call_user_func_array($this->rule, [$this]);
            } else {
                switch ($this->rule) {
                    case 'date':
                        $savename = date('Ymd') . DS . md5(microtime(true));
                        break;
                    default:
                        if (in_array($this->rule, hash_algos())) {
                            $hash     = $this->hash($this->rule);
                            $savename = substr($hash, 0, 2) . DS . substr($hash, 2);
                        } elseif (is_callable($this->rule)) {
                            $savename = call_user_func($this->rule);
                        } else {
                            $savename = date('Ymd') . DS . md5(microtime(true));
                        }
                }
            }
        } elseif ('' === $savename || false === $savename) {
            $savename = $this->getInfo('name');
        }

        if (!strpos($savename, '.')) {
            $savename .= '.' . pathinfo($this->getInfo('name'), PATHINFO_EXTENSION);
        }

        return $savename;
    }

    /* *
 * Set the message key for an upload error code
 * @access private
 * @param int $errorNo Upload error code
 * @return $this */
    private function error($errorNo)
    {
        switch ($errorNo) {
            case 1:
            case 2:
                $this->error = 'upload File size exceeds the maximum value';
                break;
            case 3:
                $this->error = 'only the portion of file is uploaded';
                break;
            case 4:
                $this->error = 'no file to uploaded';
                break;
            case 6:
                $this->error = 'upload temp dir not found';
                break;
            case 7:
                $this->error = 'file write error';
                break;
            default:
                $this->error = 'unknown upload error';
        }

        return $this;
    }

    /* *
 * Get the error message, using the current language when a translation exists
 * @access public
 * @return string */
    public function getError()
    {
        if (is_array($this->error)) {
            list($msg, $vars) = $this->error;
        } else {
            $msg  = $this->error;
            $vars = [];
        }

        return Lang::has($msg) ? Lang::get($msg, $vars) : $msg;
    }

    /* *
 * Use the called method name as the file hash algorithm
 * @access public
 * @param string $method Hash algorithm name
 * @param mixed $args Unused method arguments
 * @return string|false */
    public function __call($method, $args)
    {
        return $this->hash($method);
    }
}
