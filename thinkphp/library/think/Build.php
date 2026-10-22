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

class Build
{
    /* *
 * Create directories and files based on incoming build data
 * @access public
 * @param array $build build list
 * @param string $namespace Application namespace
 * @param bool $suffix Whether to append layer names to generated class names
 * @return void
 * @throws Exception */
    public static function run(array $build = [], $namespace = 'app', $suffix = false)
    {
        // Build lock file
        $lock = APP_PATH . 'build.lock';

        // Build only when the lock file is not writable; try to create it first
        if (!is_writable($lock)) {
            if (!touch($lock)) {
                throw new Exception(
                    'The application directory [' . APP_PATH . '] is not writable, and the directory cannot be created automatically!<br>Please create the project directory manually.',
                    10006
                );
            }

            foreach ($build as $module => $list) {
                if ('__dir__' == $module) {
                    // Create the listed directories
                    self::buildDir($list);
                } elseif ('__file__' == $module) {
                    // Create the listed files
                    self::buildFile($list);
                } else {
                    // Create a module
                    self::module($module, $list, $namespace, $suffix);
                }
            }

            // Unlock
            unlink($lock);
        }
    }

    /* *
 * Create the listed directories
 * @access protected
 * @param array $list list of directories
 * @return void */
    protected static function buildDir($list)
    {
        foreach ($list as $dir) {
            // Create the directory if it does not exist
            !is_dir(APP_PATH . $dir) && mkdir(APP_PATH . $dir, 0755, true);
        }
    }

    /* *
 * Create the listed files
 * @access protected
 * @param array $list list of files
 * @return void */
    protected static function buildFile($list)
    {
        foreach ($list as $file) {
            // Create the parent directory first
            if (!is_dir(APP_PATH . dirname($file))) {
                mkdir(APP_PATH . dirname($file), 0755, true);
            }

            // Create the file if it does not exist
            if (!is_file(APP_PATH . $file)) {
                file_put_contents(
                    APP_PATH . $file,
                    'php' == pathinfo($file, PATHINFO_EXTENSION) ? "<?php\n" : ''
                );
            }
        }
    }

    /* *
 * Create a module
 * @access public
 * @param string $module module name
 * @param array $list build list
 * @param string $namespace Application namespace
 * @param bool $suffix Whether to append layer names to generated class names
 * @return void */
    public static function module($module = '', $list = [], $namespace = 'app', $suffix = false)
    {
        $module = $module ?: '';

        // Create a module directory
        !is_dir(APP_PATH . $module) && mkdir(APP_PATH . $module);

        // Generate common files and a welcome controller unless the module name matches the runtime directory basename
        if (basename(RUNTIME_PATH) != $module) {
            self::buildCommon($module);
            self::buildHello($module, $namespace, $suffix);
        }

        // Use the default module files and directories when no build list is supplied
        if (empty($list)) {
            $list = [
                '__file__' => ['config.php', 'common.php'],
                '__dir__'  => ['controller', 'model', 'view'],
            ];
        }

        // Create subdirectories and files
        foreach ($list as $path => $file) {
            $modulePath = APP_PATH . $module . DS;

            if ('__dir__' == $path) {
                // Generating subdirectories
                foreach ($file as $dir) {
                    self::checkDirBuild($modulePath . $dir);
                }
            } elseif ('__file__' == $path) {
                // Create missing files, adding an opening tag to PHP files
                foreach ($file as $name) {
                    if (!is_file($modulePath . $name)) {
                        file_put_contents(
                            $modulePath . $name,
                            'php' == pathinfo($name, PATHINFO_EXTENSION) ? "<?php\n" : ''
                        );
                    }
                }
            } else {
                // Generating relevant MVC files
                foreach ($file as $val) {
                    $val      = trim($val);
                    $filename = $modulePath . $path . DS . $val . ($suffix ? ucfirst($path) : '') . EXT;
                    $space    = $namespace . '\\' . ($module ? $module . '\\' : '') . $path;
                    $class    = $val . ($suffix ? ucfirst($path) : '');

                    switch ($path) {
                        case 'controller': // controllers
                            $content = "<?php\nnamespace {$space};\n\nclass {$class}\n{\n\n}";
                            break;
                        case 'model': // models
                            $content = "<?php\nnamespace {$space};\n\nuse think\Model;\n\nclass {$class} extends Model\n{\n\n}";
                            break;
                        case 'view': // view
                            $filename = $modulePath . $path . DS . $val . '.html';
                            self::checkDirBuild(dirname($filename));
                            $content = '';
                            break;
                        default:
                            // Other class files
                            $content = "<?php\nnamespace {$space};\n\nclass {$class}\n{\n\n}";
                    }

                    if (!is_file($filename)) {
                        file_put_contents($filename, $content);
                    }
                }
            }
        }
    }

    /* *
 * Create a welcome controller for the module
 * @access protected
 * @param string $module module name
 * @param string $namespace Application namespace
 * @param bool $suffix Whether to append Controller to the generated class name
 * @return void */
    protected static function buildHello($module, $namespace, $suffix = false)
    {
        $filename = APP_PATH . ($module ? $module . DS : '') .
            'controller' . DS . 'Index' .
            ($suffix ? 'Controller' : '') . EXT;

        if (!is_file($filename)) {
            $module = $module ? $module . '\\' : '';
            $suffix = $suffix ? 'Controller' : '';
            $content = str_replace(
                ['{$app}', '{$module}', '{layer}', '{$suffix}'],
                [$namespace, $module, 'controller', $suffix],
                file_get_contents(THINK_PATH . 'tpl' . DS . 'default_index.tpl')
            );

            self::checkDirBuild(dirname($filename));
            file_put_contents($filename, $content);
        }
    }

    /* *
 * Create module configuration and common files
 * @access protected
 * @param string $module module name
 * @return void */
    protected static function buildCommon($module)
    {
        $config = CONF_PATH . ($module ? $module . DS : '') . 'config.php';

        self::checkDirBuild(dirname($config));

        if (!is_file($config)) {
            file_put_contents($config, "<?php\n// Configuration File\nreturn [\n\n];");
        }

        $common = APP_PATH . ($module ? $module . DS : '') . 'common.php';
        if (!is_file($common)) file_put_contents($common, "<?php\n");
    }

    /* *
 * Create a directory if it does not exist
 * @access protected
 * @param string $dirname Directory path
 * @return void */
    protected static function checkDirBuild($dirname)
    {
        !is_dir($dirname) && mkdir($dirname, 0755, true);
    }
}
