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

class Memcached extends SessionHandler
{
    protected $handler = null;
    protected $config  = [
        'host'         => '127.0.0.1', // Comma-separated Memcached hosts
        'port'         => 11211, // Comma-separated Memcached ports
        'expire'       => 3600, // Session expiration
        'timeout'      => 0, // Connection timeout in milliseconds
        'session_name' => '', // Session key prefix
        'username'     => '', // Authentication username
        'password'     => '', // Authentication password
    ];

    public function __construct($config = [])
    {
        $this->config = array_merge($this->config, $config);
    }

    /* *
 * Initialize Memcached session storage
 * @access public
 * @param string $savePath Unused session save path
 * @param string $sessName Unused session name */
    #[\ReturnTypeWillChange]
    public function open($savePath, $sessName)
    {
        // Check whether the Memcached extension is loaded
        if (!extension_loaded('memcached')) {
            throw new Exception('not support:memcached');
        }
        $this->handler = new \Memcached;
        // Set the connection timeout in milliseconds
        if ($this->config['timeout'] > 0) {
            $this->handler->setOption(\Memcached::OPT_CONNECT_TIMEOUT, $this->config['timeout']);
        }
        // Parse the configured server list
        $hosts = explode(',', $this->config['host']);
        $ports = explode(',', $this->config['port']);
        if (empty($ports[0])) {
            $ports[0] = 11211;
        }
        // Build the server list for the connection pool
        $servers = [];
        foreach ((array) $hosts as $i => $host) {
            $servers[] = [$host, (isset($ports[$i]) ? $ports[$i] : $ports[0]), 1];
        }
        $this->handler->addServers($servers);
        if ('' != $this->config['username']) {
            $this->handler->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
            $this->handler->setSaslAuthData($this->config['username'], $this->config['password']);
        }
        return true;
    }

    /* *
 * Close the session storage connections
 * @access public */
    #[\ReturnTypeWillChange]
    public function close()
    {
        $this->gc(ini_get('session.gc_maxlifetime'));
        $this->handler->quit();
        $this->handler = null;
        return true;
    }

    /* *
 * Read the stored session data
 * @access public
 * @param string $sessID Session ID */
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
        return $this->handler->set($this->config['session_name'] . $sessID, $sessData, $this->config['expire']);
    }

    /* *
 * Delete the stored session data
 * @access public
 * @param string $sessID Session ID
 * @return bool */
    #[\ReturnTypeWillChange]
    public function destroy($sessID)
    {
        return $this->handler->delete($this->config['session_name'] . $sessID);
    }

    /* *
 * Return success without explicit garbage collection
 * @access public
 * @param int|string $sessMaxLifeTime Unused maximum session lifetime
 * @return true */
    #[\ReturnTypeWillChange]
    public function gc($sessMaxLifeTime)
    {
        return true;
    }
}
