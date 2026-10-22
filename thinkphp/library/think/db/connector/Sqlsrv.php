<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2012 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\db\connector;

use PDO;
use think\db\Connection;

/* *
 * SQL Server database connector */
class Sqlsrv extends Connection
{
    // PDO connection parameters
    protected $params = [
        PDO::ATTR_CASE              => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];
    protected $builder = '\\think\\db\\builder\\Sqlsrv';
    /* *
 * Build the PDO connection DSN
 * @access protected
 * @param array $config connection information
 * @return string */
    protected function parseDsn($config)
    {
        $dsn = 'sqlsrv:Database=' . $config['database'] . ';Server=' . $config['hostname'];
        if (!empty($config['hostport'])) {
            $dsn .= ',' . $config['hostport'];
        }
        return $dsn;
    }

    /* *
 * Get table column metadata
 * @access public
 * @param string $tableName
 * @return array */
    public function getFields($tableName)
    {
        list($tableName) = explode(' ', $tableName);
        $tableNames      = explode('.', $tableName);
        $tableName       = isset($tableNames[1]) ? $tableNames[1] : $tableNames[0];

        $sql = "SELECT   column_name,   data_type,   column_default,   is_nullable
        FROM    information_schema.tables AS t
        JOIN    information_schema.columns AS c
        ON  t.table_catalog = c.table_catalog
        AND t.table_schema  = c.table_schema
        AND t.table_name    = c.table_name
        WHERE   t.table_name = '$tableName'";

        $pdo    = $this->query($sql, [], false, true);
        $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $info   = [];
        if ($result) {
            foreach ($result as $key => $val) {
                $val                       = array_change_key_case($val);
                $info[$val['column_name']] = [
                    'name'    => $val['column_name'],
                    'type'    => $val['data_type'],
                    'notnull' => (bool) ('' === $val['is_nullable']), // Interpret an empty nullability value as NOT NULL
                    'default' => $val['column_default'],
                    'primary' => false,
                    'autoinc' => false,
                ];
            }
        }
        $sql = "SELECT column_name FROM information_schema.key_column_usage WHERE table_name='$tableName'";
        // Start query timing
        $this->debug(true);
        $pdo = $this->linkID->query($sql);
        // Finish query timing
        $this->debug(false, $sql);
        $result = $pdo->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $info[$result['column_name']]['primary'] = true;
        }
        return $this->fieldCase($info);
    }

    /* *
 * Get table column metadata
 * @access public
 * @param string $dbName
 * @return array */
    public function getTables($dbName = '')
    {
        $sql = "SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_TYPE = 'BASE TABLE'
            ";

        $pdo    = $this->query($sql, [], false, true);
        $result = $pdo->fetchAll(PDO::FETCH_ASSOC);
        $info   = [];
        foreach ($result as $key => $val) {
            $info[$key] = current($val);
        }
        return $info;
    }

    /* *
 * Analyze SQL query performance
 * @access protected
 * @param string $sql
 * @return array */
    protected function getExplain($sql)
    {
        return [];
    }
}
