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

namespace think\session\driver;

use SessionHandler;
use think\Exception;

class Redis extends SessionHandler
{
    /* * @var \Redis|null Redis connection */
    protected $handler = null;
    protected $config  = [
        'host'         => '127.0.0.1', // Redis host
        'port'         => 6379, // Redis port
        'password'     => '', // Authentication password
        'select'       => 0, // Redis database index
        'expire'       => 3600, // Session lifetime in seconds
        'timeout'      => 0, // Connection timeout in seconds
        'persistent'   => true, // Use a persistent connection
        'session_name' => '', // Session key prefix
    ];

    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, $config);
    }

    /* *
 * Initialize Redis session storage
 * @access public
 * @param string $savePath Unused session save path
 * @param string $sessName Unused session name
 * @return bool
 * @throws Exception */
    #[\ReturnTypeWillChange]
    public function open($savePath, $sessName)
    {
        // Check whether the Redis extension is loaded
        if (!extension_loaded('redis')) {
            throw new Exception('not support:redis');
        }
        $this->handler = new \Redis;

        // Connect to the configured Redis server
        $func = $this->config['persistent'] ? 'pconnect' : 'connect';
        $this->handler->$func($this->config['host'], $this->config['port'], $this->config['timeout']);

        if ('' != $this->config['password']) {
            $this->handler->auth($this->config['password']);
        }

        if (0 != $this->config['select']) {
            $this->handler->select($this->config['select']);
        }

        return true;
    }

    /* *
 * Close the session storage connection
 * @access public */
    #[\ReturnTypeWillChange]
    public function close()
    {
        $this->gc(ini_get('session.gc_maxlifetime'));
        $this->handler->close();
        $this->handler = null;
        return true;
    }

    /* *
 * Read the stored session data
 * @access public
 * @param string $sessID Session ID
 * @return string */
    #[\ReturnTypeWillChange]
    public function read($sessID)
    {
        return (string) $this->handler->get($this->config['session_name'] . $sessID);
    }

    /* *
 * Write the session data
 * @access public
 * @param string $sessID Session ID
 * @param string $sessData Encoded session data
 * @return bool */
    #[\ReturnTypeWillChange]
    public function write($sessID, $sessData)
    {
        if ($this->config['expire'] > 0) {
            return $this->handler->setex($this->config['session_name'] . $sessID, $this->config['expire'], $sessData);
        } else {
            return $this->handler->set($this->config['session_name'] . $sessID, $sessData);
        }
    }

    /* *
 * Delete the stored session data
 * @access public
 * @param string $sessID Session ID
 * @return bool */
    #[\ReturnTypeWillChange]
    public function destroy($sessID)
    {
        return $this->handler->delete($this->config['session_name'] . $sessID) > 0;
    }

    /* *
 * Return success without explicit garbage collection
 * @access public
 * @param int|string $sessMaxLifeTime Unused maximum session lifetime
 * @return bool */
    #[\ReturnTypeWillChange]
    public function gc($sessMaxLifeTime)
    {
        return true;
    }
}
