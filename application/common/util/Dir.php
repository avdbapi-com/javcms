<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;
class Dir {

    private $_values = array();
    public $error = "";

    /* *
 * Load the directory entries for this instance.
 * @param string $path directory path */
    public function __construct($path = '', $pattern = '*') {
        if (!$path) return false;
        if (substr($path, -1) != "/") $path .= "/";
        $this->_values = self::listFile($path, $pattern);
    }


    /* *
 * Create a directory and any missing parent directories.
 * @param string $path directory
 * @param integer $mode permissions
 * @return boolean */
    public static function create($path, $mode = 0755) {
      if(is_dir($path)) return TRUE;
      $path = str_replace("\\", "/", $path);
      if(substr($path, -1) != '/') $path = $path.'/';
      $temp = explode('/', $path);
      $cur_dir = '';
      $max = count($temp) - 1;
      for($i=0; $i<$max; $i++) {
        $cur_dir .= $temp[$i].'/';
        if (@is_dir($cur_dir)) continue;
        @mkdir($cur_dir, $mode, true);
        @chmod($cur_dir, $mode);
      }
      return is_dir($path);
    }

    /* *
 * List matching filesystem entries and their metadata.
 * @param mixed $pathname path */
    public static function listFile($pathname, $pattern = '*') {
        static $_listDirs = array();
        $guid = md5($pathname . $pattern);
        if (!isset($_listDirs[$guid])) {
            $dir = array();
            $list = glob($pathname . $pattern);
            foreach ($list as $i => $file) {
                // $dir[$i][\'filename\'] = basename($file);
                // Avoid basename() issues with Chinese filenames.
                // Remove the path prefix without changing the filename encoding.
                $dir[$i]['filename'] = preg_replace('/^.+[\\\\\\/]/', '', $file);
                $dir[$i]['pathname'] = realpath($file);
                $dir[$i]['owner'] = fileowner($file);
                $dir[$i]['perms'] = fileperms($file);
                $dir[$i]['inode'] = fileinode($file);
                $dir[$i]['group'] = filegroup($file);
                $dir[$i]['path'] = dirname($file);
                $dir[$i]['atime'] = fileatime($file);
                $dir[$i]['ctime'] = filectime($file);
                $dir[$i]['size'] = filesize($file);
                $dir[$i]['type'] = filetype($file);
                $dir[$i]['ext'] = is_file($file) ? strtolower(substr(strrchr(basename($file), '.'), 1)) : '';
                $dir[$i]['mtime'] = filemtime($file);
                $dir[$i]['isDir'] = is_dir($file);
                $dir[$i]['isFile'] = is_file($file);
                $dir[$i]['isLink'] = is_link($file);
                // $dir[$i][\'isExecutable\']= function_exists(\'is_executable\')?is_executable($file):\'\';
                $dir[$i]['isReadable'] = is_readable($file);
                $dir[$i]['isWritable'] = is_writable($file);
            }
            $cmp_func = static function ($a, $b) {
                $k = 'isDir';
                if ($a[$k] == $b[$k]) return 0;
                return $a[$k] > $b[$k] ? -1 : 1;
            };
            // Sort directories before files.
            usort($dir, $cmp_func);
            $_listDirs[$guid] = $dir;
            return $dir;
        } else {
            return $_listDirs[$guid];
        }
    }

    /* *
 * Return the current array element.
 * @return array */
    public static function current($arr) {
        if (!is_array($arr)) {
            return false;
        }
        return current($arr);
    }

    /* *
 * The time the file was last accessed
 * @return integer */
    public function getATime() {
        $current = $this->current($this->_values);
        return $current['atime'];
    }

    /* *
 * Get the inode modification time of the file
 * @return integer */
    public function getCTime() {
        $current = $this->current($this->_values);
        return $current['ctime'];
    }

    /* *
 * Return a Dir instance for the current directory entry.
 * @return Dir|false */
    public function getChildren() {
        $current = $this->current($this->_values);
        if ($current['isDir']) {
            return new Dir($current['pathname']);
        }
        return false;
    }

    /* *
 * Get the file name
 * @return string */
    public function getFilename() {
        $current = $this->current($this->_values);
        return $current['filename'];
    }

    /* *
 * Get the group ID of the file.
 * @return integer */
    public function getGroup() {
        $current = $this->current($this->_values);
        return $current['group'];
    }

    /* *
 * Get the file's inode.
 * @return integer */
    public function getInode() {
        $current = $this->current($this->_values);
        return $current['inode'];
    }

    /* *
 * Get the last modification time of the file
 * @return integer */
    public function getMTime() {
        $current = $this->current($this->_values);
        return $current['mtime'];
    }

    /* *
 * Get the file owner's user ID.
 * @return integer */
    function getOwner() {
        $current = $this->current($this->_values);
        return $current['owner'];
    }

    /* *
 * Get the file path, not including the file name
 * @return string */
    public function getPath() {
        $current = $this->current($this->_values);
        return $current['path'];
    }

    /* *
 * Get the full path of the file, including the file name
 * @return string */
    public function getPathname() {
        $current = $this->current($this->_values);
        return $current['pathname'];
    }

    /* *
 * Get the file permissions.
 * @return integer */
    public function getPerms() {
        $current = $this->current($this->_values);
        return $current['perms'];
    }

    /* *
 * Get the size of the file
 * @return integer */
    public function getSize() {
        $current = $this->current($this->_values);
        return $current['size'];
    }

    /* *
 * Get file type
 * @return string */
    public function getType() {
        $current = $this->current($this->_values);
        return $current['type'];
    }

    /* *
 * Is this a directory
 * @return boolean */
    public function isDir() {
        $current = $this->current($this->_values);
        return $current['isDir'];
    }

    /* *
 * Is this a file
 * @return boolean */
    public function isFile() {
        $current = $this->current($this->_values);
        return $current['isFile'];
    }

    /* *
 * Whether the file is a symbolic link
 * @return boolean */
    public function isLink() {
        $current = $this->current($this->_values);
        return $current['isLink'];
    }

    /* *
 * Return the cached executable flag, if present.
 * @return boolean|null */
    public function isExecutable() {
        $current = $this->current($this->_values);
        return $current['isExecutable'];
    }

    /* *
 * Whether the file is readable
 * @return boolean */
    public function isReadable() {
        $current = $this->current($this->_values);
        return $current['isReadable'];
    }

    /* *
 * Return the directory entries as an iterable ArrayObject.
 * @return \ArrayObject */
    public function getIterator() {
        return new \ArrayObject($this->_values);
    }

    // Return the directory entries as an array.
    public function toArray() {
        return $this->_values;
    }

    // static method
    /* *
 * Check whether the directory is empty.
 * @return boolean */
    public static function isEmpty($directory) {
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != "..") {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }

    /* *
 * List entry names, excluding the current and parent directory entries.
 * @return array */
    public static function getList($directory) {
        $scandir = scandir($directory);
        $dir = [];
        foreach ($scandir as $k => $v) {
            if ($v == '.' || $v == '..') {
                continue;
            }
            $dir[] = $v;
        }
        return $dir;
    }

    /* *
 * Delete the directory and its contents recursively.
 * @return void */
    public static function delDir($directory, $subdir = true) {
        if (is_dir($directory) == false) {
            return false;
        }
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != "..") {
                is_dir("$directory/$file") ?
                                Dir::delDir("$directory/$file") :
                                @unlink("$directory/$file");
            }
        }
        if (readdir($handle) == false) {
            closedir($handle);
            rmdir($directory);
        }
    }

    /* *
 * Delete files directly inside the directory; keep the directory and its subdirectories.
 * @return void */
    public static function del($directory) {
        if (is_dir($directory) == false) {
            return false;
        }
        $handle = opendir($directory);
        while (($file = readdir($handle)) !== false) {
            if ($file != "." && $file != ".." && is_file("$directory/$file")) {
                unlink("$directory/$file");
            }
        }
        closedir($handle);
    }

    /* *
 * Copy the directory and its contents recursively.
 * @return void */
    public static function copyDir($source, $destination) {
        if (is_dir($source) == false) {
            return false;
        }
        if (is_dir($destination) == false) {
            mkdir($destination, 0755);
        }
        $handle = opendir($source);
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                if (is_dir("$source/$file")) {
                    Dir::copyDir("$source/$file", "$destination/$file");
                } else {
                    copy("$source/$file", "$destination/$file");
                }
            }
        }
        closedir($handle);
    }

}

?>
