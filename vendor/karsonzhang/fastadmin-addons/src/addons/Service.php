<?php

namespace think\addons;

use fast\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use think\Db;
use think\Exception;
use ZipArchive;

/* *
 * Plugin service
 * @package think\\addons */
class Service
{

    /* *
 * Download a plugin from the remote server
 *
 * @param string $name Plugin name
 * @param array $extend Additional parameters
 * @return string
 * @throws AddonException
 * @throws Exception */
    public static function download($name, $extend = [])
    {
        $addonTmpDir = RUNTIME_PATH . 'addons' . DS;
        if (!is_dir($addonTmpDir)) {
            @mkdir($addonTmpDir, 0755, true);
        }
        $tmpFile = $addonTmpDir . $name . ".zip";
        $options = [
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'X-REQUESTED-WITH: XMLHttpRequest'
            ]
        ];
        $ret = Http::sendRequest(self::getServerUrl() . '/addon/download', array_merge(['name' => $name], $extend), 'GET', $options);
        if ($ret['ret']) {
            if (substr($ret['msg'], 0, 1) == '{') {
                $json = (array)json_decode($ret['msg'], true);
                // Download from the returned URL when provided
                if ($json['data'] && isset($json['data']['url'])) {
                    array_pop($options);
                    $ret = Http::sendRequest($json['data']['url'], [], 'GET', $options);
                    if (!$ret['ret']) {
                        // Throw an exception for the download failure
                        throw new AddonException($json['msg'], $json['code'], $json['data']);
                    }
                } else {
                    // Throw an exception for the download failure
                    throw new AddonException($json['msg'], $json['code'], $json['data']);
                }
            }
            if ($write = fopen($tmpFile, 'w')) {
                fwrite($write, $ret['msg']);
                fclose($write);
                return $tmpFile;
            }
            throw new Exception("No permission to write to temporary files");
        }
        throw new Exception("Unable to download remote files");
    }

    /* *
 * Extract the plugin archive
 *
 * @param string $name Plugin name
 * @return string
 * @throws Exception */
    public static function unzip($name)
    {
        $file = RUNTIME_PATH . 'addons' . DS . $name . '.zip';
        $dir = ADDON_PATH . $name . DS;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($file) !== true) {
                throw new Exception('Unable to open the zip file');
            }
            if (!$zip->extractTo($dir)) {
                $zip->close();
                throw new Exception('Unable to extract the file');
            }
            $zip->close();
            return $dir;
        }
        throw new Exception("Unable to extract files; make sure ZipArchive is installed correctly");
    }

    /* *
 * Back up the plugin
 * @param string $name Plugin name
 * @return bool
 * @throws Exception */
    public static function backup($name)
    {
        $file = RUNTIME_PATH . 'addons' . DS . $name . '-backup-' . date("YmdHis") . '.zip';
        $dir = ADDON_PATH . $name . DS;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            $zip->open($file, ZipArchive::CREATE);
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $filePath = $fileinfo->getPathName();
                $localName = str_replace($dir, '', $filePath);
                if ($fileinfo->isFile()) {
                    $zip->addFile($filePath, $localName);
                } elseif ($fileinfo->isDir()) {
                    $zip->addEmptyDir($localName);
                }
            }
            $zip->close();
            return true;
        }
        throw new Exception("Unable to compress files; make sure ZipArchive is installed correctly");
    }

    /* *
 * Check whether the plugin is complete
 *
 * @param string $name Plugin name
 * @return boolean
 * @throws Exception */
    public static function check($name)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon does not exist');
        }
        $addonClass = get_addon_class($name);
        if (!$addonClass) {
            throw new Exception("The plugin entry class does not exist");
        }
        $addon = new $addonClass();
        if (!$addon->checkInfo()) {
            throw new Exception("Plugin metadata is incomplete");
        }
        return true;
    }

    /* *
 * Check for conflicting files
 *
 * @param string $name Plugin name
 * @return boolean
 * @throws AddonException */
    public static function noconflict($name)
    {
        // Check for conflicting files
        $list = self::getGlobalFiles($name, true);
        if ($list) {
            // Reject conflicting files
            throw new AddonException("Conflicting files found", -3, ['conflictlist' => $list]);
        }
        return true;
    }

    /* *
 * Import SQL
 *
 * @param string $name Plugin name
 * @return boolean */
    public static function importsql($name)
    {
        $sqlFile = ADDON_PATH . $name . DS . 'install.sql';
        if (is_file($sqlFile)) {
            $lines = file($sqlFile);
            $templine = '';
            foreach ($lines as $line) {
                if (substr($line, 0, 2) == '--' || $line == '' || substr($line, 0, 2) == '/*') {
                    continue;
                }

                $templine .= $line;
                if (substr(trim($line), -1, 1) == ';') {
                    $templine = str_ireplace('__PREFIX__', config('database.prefix'), $templine);
                    $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
                    try {
                        Db::getPdo()->exec($templine);
                    } catch (\PDOException $e) {
                        // $e->getMessage ();
                    }
                    $templine = '';
                }
            }
        }
        return true;
    }

    /* *
 * Refresh plugin assets and configuration
 *
 * @return boolean
 * @throws Exception */
    public static function refresh()
    {
        // Refresh addons.js
        $addons = get_addon_list();
        $bootstrapArr = [];
        foreach ($addons as $name => $addon) {
            $bootstrapFile = ADDON_PATH . $name . DS . 'bootstrap.js';
            if ($addon['state'] && is_file($bootstrapFile)) {
                $bootstrapArr[] = file_get_contents($bootstrapFile);
            }
        }
        $addonsFile = ROOT_PATH . str_replace("/", DS, "static/js/addons.js");
        if ($handle = fopen($addonsFile, 'w')) {
            $tpl = <<<EOD
define([], function () {
    {__JS__}
});
EOD;
            fwrite($handle, str_replace("{__JS__}", implode("\n", $bootstrapArr), $tpl));
            fclose($handle);
        } else {
            throw new Exception("The addons.js file is not writable");
        }

        $file = APP_PATH . 'extra' . DS . 'addons.php';

        $config = get_addon_autoload_config(true);
        if ($config['autoload']) {
            return;
        }

        if (!is_really_writable($file)) {
            throw new Exception("The addons.php file is not writable");
        }

        if ($handle = fopen($file, 'w')) {
            fwrite($handle, "<?php\n\n" . "return " . var_export($config, true) . ";");
            fclose($handle);
        } else {
            throw new Exception("The file is not writable");
        }
        return true;
    }

    /* *
 * Install a plugin
 *
 * @param string $name Plugin name
 * @param boolean $force Whether to overwrite existing files
 * @param array $extend Additional parameters
 * @return boolean
 * @throws Exception
 * @throws AddonException */
    public static function install($name, $force = false, $extend = [])
    {
        if (!$name || (is_dir(ADDON_PATH . $name) && !$force)) {
            throw new Exception('Addon already exists');
        }

        // Download a plugin from the remote server
        $tmpFile = Service::download($name, $extend);

        // Extract the plugin archive
        $addonDir = Service::unzip($name);

        // Remove temporary files
        @unlink($tmpFile);

        try {
            // Check whether the plugin is complete
            Service::check($name);

            if (!$force) {
                Service::noconflict($name);
            }
        } catch (AddonException $e) {
            @rmdirs($addonDir);
            throw new AddonException($e->getMessage(), $e->getCode(), $e->getData());
        } catch (Exception $e) {
            @rmdirs($addonDir);
            throw new Exception($e->getMessage());
        }

        // Copy file
        $sourceAssetsDir = self::getSourceAssetsDir($name);
        $destAssetsDir = self::getDestAssetsDir($name);
        if (is_dir($sourceAssetsDir)) {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }
        foreach (self::getCheckDirs() as $k => $dir) {
            if (is_dir($addonDir . $dir)) {
                copydirs($addonDir . $dir, ROOT_PATH . $dir);
            }
        }

        try {
            // Enable this plugin by default
            $info = get_addon_info($name);
            if (!$info['state']) {
                $info['state'] = 1;
                set_addon_info($name, $info);
            }

            // Execute the installation script
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                $addon->install();
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // Import plugin SQL
        Service::importsql($name);

        // Refresh plugin assets and configuration
        Service::refresh();
        return true;
    }

    /* *
 * Uninstall a plugin
 *
 * @param string $name
 * @param boolean $force Whether to remove conflicting global files
 * @return boolean
 * @throws Exception */
    public static function uninstall($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon does not exist');
        }

        if (!$force) {
            Service::noconflict($name);
        }

        // Remove the published plugin assets directory
        $destAssetsDir = self::getDestAssetsDir($name);
        if (is_dir($destAssetsDir)) {
            rmdirs($destAssetsDir);
        }

        // Remove plugin files from global directories
        if ($force) {
            $list = Service::getGlobalFiles($name);
            foreach ($list as $k => $v) {
                @unlink(ROOT_PATH . $v);
            }
        }

        // Execute the uninstallation script
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                $addon->uninstall();
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // Remove the plugin directory
        rmdirs(ADDON_PATH . $name);

        // Refresh plugin assets and configuration
        Service::refresh();
        return true;
    }

    /* *
 * Enable a plugin
 * @param string $name Plugin name
 * @param boolean $force Whether to overwrite existing files
 * @return boolean */
    public static function enable($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon does not exist');
        }

        if (!$force) {
            Service::noconflict($name);
        }

        $addonDir = ADDON_PATH . $name . DS;

        // Copy file
        $sourceAssetsDir = self::getSourceAssetsDir($name);
        $destAssetsDir = self::getDestAssetsDir($name);
        if (is_dir($sourceAssetsDir)) {
            copydirs($sourceAssetsDir, $destAssetsDir);
        }
        foreach (self::getCheckDirs() as $k => $dir) {
            if (is_dir($addonDir . $dir)) {
                copydirs($addonDir . $dir, ROOT_PATH . $dir);
            }
        }

        // Execute the enable script
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();
                if (method_exists($class, "enable")) {
                    $addon->enable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        $info = get_addon_info($name);
        $info['state'] = 1;
        unset($info['url']);

        set_addon_info($name, $info);

        // Refresh plugin assets and configuration
        Service::refresh();
        return true;
    }

    /* *
 * Disable
 *
 * @param string $name Plugin name
 * @param boolean $force Whether to forcibly disable
 * @return boolean
 * @throws Exception */
    public static function disable($name, $force = false)
    {
        if (!$name || !is_dir(ADDON_PATH . $name)) {
            throw new Exception('Addon does not exist');
        }
        if (!$force) {
            Service::noconflict($name);
        }

        // Remove the published plugin assets directory
        $destAssetsDir = self::getDestAssetsDir($name);
        if (is_dir($destAssetsDir)) {
            rmdirs($destAssetsDir);
        }

        $dirs = [];
        // Remove plugin files from global directories
        $list = Service::getGlobalFiles($name);
        foreach ($list as $k => $v) {
            $dirs[] = dirname(ROOT_PATH . $v);
            @unlink(ROOT_PATH . $v);
        }

        // Remove empty plugin directories
        $dirs = array_filter(array_unique($dirs));
        foreach ($dirs as $k => $v) {
            remove_empty_folder($v);
        }

        $info = get_addon_info($name);
        $info['state'] = 0;
        unset($info['url']);

        set_addon_info($name, $info);

        // Execute the plugin disable method
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();

                if (method_exists($class, "disable")) {
                    $addon->disable();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // Refresh plugin assets and configuration
        Service::refresh();
        return true;
    }

    /* *
 * Upgrade a plugin
 *
 * @param string $name Plugin name
 * @param array $extend Additional parameters */
    public static function upgrade($name, $extend = [])
    {
        $info = get_addon_info($name);
        if ($info['state']) {
            throw new Exception(__('Please disable addon first'));
        }
        $config = get_addon_config($name);
        if ($config) {
            // Backup configuration
        }

        // Back up plugin files
        Service::backup($name);

        // Download a plugin from the remote server
        $tmpFile = Service::download($name, $extend);

        // Extract the plugin archive
        $addonDir = Service::unzip($name);

        // Remove temporary files
        @unlink($tmpFile);

        if ($config) {
            // Restore configuration
            set_addon_config($name, $config);
        }

        // Import plugin SQL
        Service::importsql($name);

        // Execute the upgrade script
        try {
            $class = get_addon_class($name);
            if (class_exists($class)) {
                $addon = new $class();

                if (method_exists($class, "upgrade")) {
                    $addon->upgrade();
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        // Refresh plugin assets and configuration
        Service::refresh();

        return true;
    }

    /* *
 * Get plugin files copied into global directories
 *
 * @param string $name plugin name
 * @return array */
    public static function getGlobalFiles($name, $onlyconflict = false)
    {
        $list = [];
        $addonDir = ADDON_PATH . $name . DS;
        // Scan the plugin directory for files copied into global directories
        foreach (self::getCheckDirs() as $k => $dir) {
            $checkDir = ROOT_PATH . DS . $dir . DS;
            if (!is_dir($checkDir)) {
                continue;
            }
            // Check for a directory outside the plugin
            if (is_dir($addonDir . $dir)) {
                // Match all files
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($addonDir . $dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
                );

                foreach ($files as $fileinfo) {
                    if ($fileinfo->isFile()) {
                        $filePath = $fileinfo->getPathName();
                        $path = str_replace($addonDir, '', $filePath);
                        if ($onlyconflict) {
                            $destPath = ROOT_PATH . $path;
                            if (is_file($destPath)) {
                                if (filesize($filePath) != filesize($destPath) || md5_file($filePath) != md5_file($destPath)) {
                                    $list[] = $path;
                                }
                            }
                        } else {
                            $list[] = $path;
                        }
                    }
                }
            }
        }
        return $list;
    }

    /* *
 * Get the source directory for plugin assets
 * @param string $name
 * @return string */
    protected static function getSourceAssetsDir($name)
    {
        return ADDON_PATH . $name . DS . 'assets' . DS;
    }

    /* *
 * Get or create the published plugin assets directory
 * @param string $name
 * @return string */
    protected static function getDestAssetsDir($name)
    {
        $assetsDir = ROOT_PATH . str_replace("/", DS, "static/addons/{$name}/");
        if (!is_dir($assetsDir)) {
            mkdir($assetsDir, 0755, true);
        }
        return $assetsDir;
    }

    /* *
 * Get the remote server URL
 * @return string */
    protected static function getServerUrl()
    {
        return config('fastadmin.api_url');
    }

    /* *
 * Get the global directories checked for plugin files
 * @return array */
    protected static function getCheckDirs()
    {
        return [
            'application',
            'static'
        ];
    }

}
