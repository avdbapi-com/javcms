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

namespace think\template\driver;

use think\Exception;

class File
{
    protected $cacheFile;

    // JavCMS 1.2.0 (SR12-1, HP-H-10): a compiled template is written to a temporary file and renamed into place, so no request
    // includes a half-written one. $ownCopies: this request's complete copies that could not be renamed into place (cache file =>
    // temporary file); $reading: what read() is including now, [own copy or '', the held cache file] (a stack: a template rendered
    // from inside another one keeps the outer entry); $copiesLeft: every own copy of this process not deleted yet. Each copy is
    // deleted right after it ran, at the latest when the request ends (an exit() or a fatal error inside a template).
    protected $ownCopies = [];
    protected $reading = [];
    private static $copiesLeft = [];
    private static $sweepRegistered = false;

    /* *
 * Write a compiled template to the cache file
 * @param string $cacheFile Compiled template cache filename
 * @param string $content Compiled template content
 * @return void */
    public function write($cacheFile, $content)
    {
        // Create the template cache directory when needed (a request that loses the race to create it goes on)
        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !self::quiet(function () use ($dir) {
            return mkdir($dir, 0755, true);
        }) && !is_dir($dir)) {
            throw new Exception('cache write error:' . $cacheFile, 11602);
        }
        // Write the compiled template to a temporary file of this request in the same folder (the name ends in .php, so every
        // runtime/temp cleaner removes it too), then rename it over the cache file: another request finds either no file (and
        // compiles for itself) or a complete one. file_put_contents() on the cache file itself truncated it first, so a request
        // arriving meanwhile included an empty or cut template (an empty or cut page, or a 500).
        $temp = $cacheFile . '.' . self::uniqueTag() . '.php';
        $written = self::quiet(function () use ($temp, $content) {
            return file_put_contents($temp, $content);
        });
        if ($written !== strlen($content)) {
            self::discard($temp);
            throw new Exception('cache write error:' . $cacheFile, 11602);
        }
        if (isset($this->ownCopies[$cacheFile])) {
            self::discard($this->ownCopies[$cacheFile]);
            unset($this->ownCopies[$cacheFile]);
        }
        if (self::quiet(function () use ($temp, $cacheFile) {
            return rename($temp, $cacheFile);
        })) {
            return;
        }
        // The rename was refused (Windows refuses it while another request has the cache file open). The complete temporary
        // file stays this request's own copy: read() includes it instead and deletes it; the cache file is left as it is.
        $this->ownCopies[$cacheFile] = $temp;
        self::$copiesLeft[$temp]     = true;
        if (!self::$sweepRegistered) {
            self::$sweepRegistered = true;
            register_shutdown_function(static function () {
                foreach (array_keys(self::$copiesLeft) as $copy) {
                    self::discard($copy);
                }
            });
        }
    }

    /* *
 * Load the compiled template with the supplied variables
 * @param string $cacheFile Compiled template cache filename
 * @param array $vars Template variables
 * @return void */
    public function read($cacheFile, $vars = [])
    {
        $this->cacheFile = $cacheFile;
        // This request's own copy of the compiled template when write() could not rename it into place; else the cache file,
        // held open while it runs (Windows then refuses to rename another copy over it, so its name never vanishes under the
        // include). No local variable: the template runs in this scope.
        $this->reading[] = isset($this->ownCopies[$cacheFile]) ? [$this->ownCopies[$cacheFile], null] : ['', self::pin($cacheFile)];
        unset($this->ownCopies[$cacheFile]);
        if (!empty($vars) && is_array($vars)) {
            // Extract template variables into the current scope
            extract($vars, EXTR_OVERWRITE);
        }
        try {
            // Load the template cache file (or this request's own copy, deleted right after it ran)
            include ('' !== end($this->reading)[0] ? end($this->reading)[0] : $this->cacheFile);
        } finally {
            self::release(array_pop($this->reading));
        }
    }

    /* *
 * Check whether the compiled template cache is valid
 * @param string $cacheFile Compiled template cache filename
 * @param int $cacheTime Cache lifetime in seconds; zero disables expiration
 * @return boolean */
    public function check($cacheFile, $cacheTime)
    {
        // Fresh facts about the file: another request may have renamed a new copy over it a moment ago
        clearstatcache(true, $cacheFile);
        // Return false when the cache file does not exist, or holds no byte: a write that never finished (before JavCMS 1.2.0
        // the driver wrote the file in place; the compiler writes no end marker, so a cut file shows only by its size)
        if (!file_exists($cacheFile) || !self::quiet(function () use ($cacheFile) {
            return filesize($cacheFile);
        })) {
            return false;
        }
        if (0 != $cacheTime && $_SERVER['REQUEST_TIME'] > filemtime($cacheFile) + $cacheTime) {
            // The cache file has expired
            return false;
        }
        return true;
    }

    /* *
 * JavCMS 1.2.0: a unique part for this request's temporary file names (the threads of mod_php share one process id)
 * @return string */
    private static function uniqueTag()
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return substr(md5(uniqid((string)mt_rand(), true)), 0, 16);
        }
    }

    /* *
 * JavCMS 1.2.0: open the cache file for reading, to keep it open while it runs. While another request renames a new copy over
 * it, Windows can report the name missing for a moment: a few short retries. Null when it cannot be opened (the include then
 * reports that as before).
 * @param string $file
 * @return resource|null */
    private static function pin($file)
    {
        for ($try = 0; $try < 20; $try++) {
            $handle = self::quiet(function () use ($file) {
                return fopen($file, 'rb');
            });
            if (is_resource($handle)) {
                return $handle;
            }
            usleep(1000);
        }
        return null;
    }

    /* *
 * JavCMS 1.2.0: after a read: close the held cache file, delete this request's own copy
 * @param array $entry [own copy or '', held handle or null]
 * @return void */
    private static function release($entry)
    {
        if (is_array($entry)) {
            if (isset($entry[1]) && is_resource($entry[1])) {
                fclose($entry[1]);
            }
            self::discard(isset($entry[0]) ? $entry[0] : '');
        }
    }

    /* *
 * JavCMS 1.2.0: delete one of this request's temporary files (a file that is gone already is fine)
 * @param string $file
 * @return void */
    private static function discard($file)
    {
        if (is_string($file) && '' !== $file) {
            unset(self::$copiesLeft[$file]);
            self::quiet(function () use ($file) {
                return unlink($file);
            });
        }
    }

    /* *
 * JavCMS 1.2.0: run a file system call whose failure is an expected outcome under concurrency; its warning never reaches the
 * framework's error handler (which turns a warning into an exception or a log line): the caller checks the result
 * @param callable $call
 * @return mixed */
    private static function quiet(callable $call)
    {
        set_error_handler(function () {
            return true;
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
