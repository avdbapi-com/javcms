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
// $config = array(\'ftp_host\'=>\'xxx.xxx.xxx.xxx\',\'ftp_port\'=>21,\'ftp_user\'=>\'xxx\',\'ftp_pwd\' =>\'xxxxx\',\'ftp_dir\'=>\'/\');
// $ftp=new ftp();
// $ftp->config($config);
// $ftp->connect();
// $ftp->put(ROOT_PATH.$file, $file);
// @unlink($this->getPicFile($file));
class Ftp{
	protected $_config = array( 'ftp_host'=>'www.test.com', 'ftp_port'=>'21', 'ftp_user'=>'maccms', 'ftp_pwd' =>'maccms', 'ftp_timeout'=>'30', 'ftp_dir' =>'/', 'ftp_pasv'=>1 );
	protected $_conn = null; 
	protected $_rs = null; 
	public function __construct($config=array()){ 
 		!function_exists('ftp_connect') && die('PHP FTP support is unavailable.'); 
 		$this->config($config);
	} 
	public function config($config=array()){ 
 		$this->_config = array_merge($this->_config, $config); 
	} 
	function connect(){ 
		$this->_conn = @ftp_connect($this->_config['ftp_host'],$this->_config['ftp_port'],$this->_config['ftp_timeout']); 
		if(!$this->_conn){
			return -1;// Unable to connect to the FTP server. Check the host and port.
		}
		$this->_rs = @ftp_login($this->_conn, $this->_config['ftp_user'], $this->_config['ftp_pwd']); 
		if(!$this->_rs){ 
			return -2; // FTP login failed. Check the username and password.
		} 
		$this->_config['ftp_pasv'] && $this->pasv(true); 
		if(!$this->chdir($this->_config['ftp_dir'])){ 
			return -3; // Unable to change to the configured FTP directory. Check that it exists.
		} 
		return $this; 
	}
	function chdir($dir){
		return @ftp_chdir($this->_conn,$dir);
	}
	function is_file($file){
		$buff = @ftp_mdtm($this->_conn, $file); 
		if($buff != -1){ 
			return true; 
		}else{
			return false; 
		} 
	}
	function pasv($mode=true){ 
		return @ftp_pasv($this->_conn, true);
	}
	function put($local_file, $remote_file, $mode='B'){ 
		if($mode == 'B'){ 
			$mode = FTP_BINARY; 
		}else{
			$mode = FTP_ASCII;
		}
		$this->mkdirs(dirname($remote_file)); 
		$rs = @ftp_put($this->_conn, $remote_file, $local_file, $mode); 
		return $rs; 
	}
	function mkdirs($dir){
		$dir = str_replace("\\",'/',$dir); 
		$dirs = explode('/', $dir); 
		$total = count($dirs);
		foreach($dirs as $val){
			if($val == '.'){
				continue;
			}
			if($this->chdir($val) == false){
				if(!$this->mkdir($val)){
					return false;// Unable to create the FTP directory.
				} 
				$this->chdir($val); 
			}
		}
		$this->chdir($this->_config['ftp_dir']);
		return true; 
	} 
	function mkdir($dir){ 
		return @ftp_mkdir($this->_conn, $dir); 
	} 
	function unlink($file){ 
		return @ftp_delete($this->_conn, $file); 
	}
	function rename($old_name, $new_name){ 
		return @ftp_rename($this->_conn, $old_name, $new_name); 
	}
	function rmdir($dir){ 
		return @ftp_rmdir($this->_conn, $dir);
	}
	function rmdirs($dir, $flag=1){
		$res = $this->rmdir($dir) || $this->unlink($dir); 
		if(!$res){ 
			$files = $this->nlist($dir); 
			if(empty($files)){ 
				return true; 
			} 
			foreach($files as $file){ 
				$file = basename($file); 
				$this->rmdirs($dir.'/'.$file); 
			} 
			if($flag){ 
				$this->rmdirs($dir); 
			} 
		} 
		return true; 
	} 
	function nlist($dir){ 
		return @ftp_nlist($this->_conn, $dir); 
	}
	function bye() {
   		return ftp_close($this->_conn);
	}	
} 
?>