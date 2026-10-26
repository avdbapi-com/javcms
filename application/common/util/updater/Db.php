<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\util\updater;

/**
 * The site's own database, opened with plain PDO from application/database.php (the frozen engine and the bridge run
 * without the framework). Only the site's database is used; credentials are never logged or shown.
 */
final class Db
{
    /** @var \PDO|null */
    private static $pdo = null;
    /** @var array */
    private static $cfg = array();

    /** Settings of application/database.php (null when missing). */
    public static function config()
    {
        if (!self::$cfg) {
            $file = Util::abs('application/database.php');
            if (!is_file($file)) {
                return null;
            }
            $c = include $file;
            if (!is_array($c) || empty($c['database'])) {
                return null;
            }
            self::$cfg = $c;
        }
        return self::$cfg;
    }

    /** Connection without statement time limit, or null. */
    public static function pdo()
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $c = self::config();
        if ($c === null || !class_exists('PDO')) {
            return null;
        }
        try {
            $dsn = 'mysql:host=' . $c['hostname'] . ';port=' . (isset($c['hostport']) && $c['hostport'] !== '' ? (int)$c['hostport'] : 3306)
                . ';dbname=' . $c['database'] . ';charset=' . (!empty($c['charset']) ? $c['charset'] : 'utf8mb4');
            $pdo = new \PDO($dsn, (string)$c['username'], (string)$c['password'], array(
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_TIMEOUT => 10,
            ));
        } catch (\Throwable $e) {
            return null;
        }
        $v = self::versionOf($pdo);
        try {
            $pdo->exec(stripos($v, 'mariadb') !== false ? 'SET SESSION max_statement_time = 0' : 'SET SESSION MAX_EXECUTION_TIME = 0');
        } catch (\Throwable $e) {
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    /** Drops the cached connection (tests; after a long pause). */
    public static function close()
    {
        self::$pdo = null;
        self::$cfg = array();
    }

    public static function prefix()
    {
        $c = self::config();
        return $c === null ? '' : (string)($c['prefix'] ?? '');
    }

    public static function name()
    {
        $c = self::config();
        return $c === null ? '' : (string)$c['database'];
    }

    private static function versionOf(\PDO $pdo)
    {
        try {
            return (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** ['kind' => 'mariadb'|'mysql', 'version' => '10.11.6', 'raw' => ...] or null. */
    public static function server()
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return null;
        }
        $raw = self::versionOf($pdo);
        $kind = stripos($raw, 'mariadb') !== false ? 'mariadb' : 'mysql';
        preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $raw, $m);
        return array('kind' => $kind, 'version' => $m[1] ?? '0', 'raw' => $raw);
    }

    /** Tables of the site's prefix: name => ['rows' (estimate), 'bytes' (data + index)]. */
    public static function tables()
    {
        $pdo = self::pdo();
        if ($pdo === null) {
            return array();
        }
        $out = array();
        $pre = self::prefix();
        try {
            $st = $pdo->query('SHOW TABLE STATUS');
            foreach ($st->fetchAll() as $r) {
                $name = (string)$r['Name'];
                if ($pre !== '' && strncmp($name, $pre, strlen($pre)) !== 0) {
                    continue;
                }
                if (isset($r['Engine']) && $r['Engine'] === null) {
                    continue; // a view
                }
                $out[$name] = array('rows' => (int)$r['Rows'], 'bytes' => (int)$r['Data_length'] + (int)$r['Index_length'], 'data' => (int)$r['Data_length']);
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    public static function quoteName($name)
    {
        return '`' . str_replace('`', '``', (string)$name) . '`';
    }
}
