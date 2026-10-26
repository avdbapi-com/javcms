<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\util;
use think\Db;

// Database backup and restore helper.
class Database {
    /* *
 * file pointer
 * @var resource */
    private $fp;

    /* *
 * Backup or restore file details.
 * @var array */
    private $file;

    /* *
 * Estimated size of the current backup part, in bytes.
 * @var integer|float */
    private $size = 0;

    /* *
 * Backup configuration.
 * @var array */
    private $config;

    /* *
 * Store the file details and backup configuration.
 * @param array $file File details for backup or restore
 * @param array $config backup configuration information
 * @param string $type Operation type, retained for compatibility; unused by this constructor */
    public function __construct($file, $config, $type = 'export'){
        $this->file   = $file;
        $this->config = $config;
    }

    /* *
 * Open the current backup part, or start another part when the size limit is exceeded.
 * @param integer|float $size Estimated size of the data to write, in bytes */
    private function open($size){
        if($this->fp){
            $this->size += $size;
            if($this->size > $this->config['part']){
                $this->config['compress'] ? @gzclose($this->fp) : @fclose($this->fp);
                $this->fp = null;
                $this->file['part']++;
                session('backup_file', $this->file);
                $this->create();
            }
        } else {
            $backuppath = $this->config['path'];
            $filename   = "{$backuppath}{$this->file['name']}-{$this->file['part']}.sql";
            if($this->config['compress']){
                $filename = "{$filename}.gz";
                $this->fp = @gzopen($filename, "a{$this->config['level']}");
            } else {
                $this->fp = @fopen($filename, 'a');
            }
            $this->size = filesize($filename) + $size;
        }
    }

    /* *
 * Write the SQL backup header and disable foreign-key checks in the generated SQL.
 * @return integer|false Number of bytes written, or false on failure */
    public function create(){
        $sql  = "-- -----------------------------\n";
        $sql .= "-- Think MySQL Data Transfer \n";
        $sql .= "-- \n";
        $sql .= "-- Host     : " . config('database.hostname') . "\n";
        $sql .= "-- Port     : " . config('database.hostport') . "\n";
        $sql .= "-- Database : " . config('database.database') . "\n";
        $sql .= "-- \n";
        $sql .= "-- Part : #{$this->file['part']}\n";
        $sql .= "-- Date : " . date("Y-m-d H:i:s") . "\n";
        $sql .= "-- -----------------------------\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        return $this->write($sql);
    }

    /* *
 * Write an SQL statement
 * @param string $sql The SQL statement to write
 * @return integer|false Number of bytes written, or false on failure */
    private function write($sql){
        $size = strlen($sql);
        
        // Estimate compressed output as half the uncompressed size.
        // Use this estimate to decide when to start another backup part.
        $size = $this->config['compress'] ? $size / 2 : $size;
        
        $this->open($size); 
        return $this->config['compress'] ? @gzwrite($this->fp, $sql) : @fwrite($this->fp, $sql);
    }

    /* *
 * Back up a table in batches of up to 1,000 rows.
 * @param string $table table name
 * @param integer $start Starting row offset
 * @return array|integer|false Next offset and total row count, 0 when finished, or false on failure */
    public function backup($table, $start){
        // Backup table structure
        if(0 == $start){
            $result = Db::query("SHOW CREATE TABLE `{$table}`");
            $sql  = "\n";
            $sql .= "-- -----------------------------\n";
            $sql .= "-- Table structure for `{$table}`\n";
            $sql .= "-- -----------------------------\n";
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= trim($result[0]['Create Table']) . ";\n\n";
            if(false === $this->write($sql)){
                return false;
            }
        }

        // Count all rows in the table.
        $result = Db::query("SELECT COUNT(*) AS count FROM `{$table}`");
        $count  = $result['0']['count'];
            
        // Back up table data
        if($count){
            // Write the SQL comment introducing the table rows.
            if(0 == $start){
                $sql  = "-- -----------------------------\n";
                $sql .= "-- Records of `{$table}`\n";
                $sql .= "-- -----------------------------\n";
                $this->write($sql);
            }

            // Backup data records
            $start = (int)$start;
            $result = Db::query("SELECT * FROM `{$table}` LIMIT ?, 1000", [$start]);
            foreach ($result as $row) {
                $row = array_map('addslashes', $row);
                $one = implode("', '", $row);
                $one = str_replace([chr(10),chr(13)],'',$one);
                $sql = "INSERT INTO `{$table}` VALUES ('" . $one . "');\n";
                if(false === $this->write($sql)){
                    return false;
                }
            }

            // Return the next offset when more rows remain.
            if($count > $start + 1000){
                return array($start + 1000, $count);
            }
        }

        // Signal that this table is complete.
        return 0;
    }

    public function import($start){
        // Read the backup file and execute complete SQL statements.
        if($this->config['compress']){
            $gz   = gzopen($this->file[1], 'r');
            $size = 0;
        } else {
            $size = filesize($this->file[1]);
            $gz   = fopen($this->file[1], 'r');
        }
        
        $sql  = '';
        if($start){
            $this->config['compress'] ? gzseek($gz, $start) : fseek($gz, $start);
        }
        
        for($i = 0; $i < 1000; $i++){
            $sql .= $this->config['compress'] ? gzgets($gz) : fgets($gz);

            if(preg_match('/.*;$/', trim($sql))){
                if(false !== Db::execute($sql)){
                    $start += strlen($sql);
                } else {
                    $this->closeRead($gz);
                    return false;
                }
                $sql = '';
            } elseif ($this->config['compress'] ? gzeof($gz) : feof($gz)) {
                $this->closeRead($gz);
                return 0;
            }
        }

        $this->closeRead($gz);
        return array($start, $size);
    }

    /* *
 * Close the handle import() opened for one call (each call opens the part again and seeks). */
    private function closeRead($gz){
        if (is_resource($gz)) {
            $this->config['compress'] ? @gzclose($gz) : @fclose($gz);
        }
    }

    /* *
 * Close the backup file handle when this object is destroyed. */
    public function __destruct(){
        // A restore never opens $this->fp. PHP 8 throws a TypeError for gzclose(null) ("System Error" after a good restore).
        if (is_resource($this->fp)) {
            $this->config['compress'] ? @gzclose($this->fp) : @fclose($this->fp);
        }
    }
}