<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------
namespace think\console\command\optimize;

use think\Config as ThinkConfig;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

class Config extends Command
{
    /* * @var Output */
    protected $output;

    protected function configure()
    {
        $this->setName('optimize:config')
            ->addArgument('module', Argument::OPTIONAL, 'Module whose configuration cache should be built')
            ->setDescription('Build config and common file cache.');
    }

    protected function execute(Input $input, Output $output)
    {
        if ($input->getArgument('module')) {
            $module = $input->getArgument('module') . DS;
        } else {
            $module = '';
        }

        $content = '<?php ' . PHP_EOL . $this->buildCacheContent($module);

        if (!is_dir(RUNTIME_PATH . $module)) {
            @mkdir(RUNTIME_PATH . $module, 0755, true);
        }

        file_put_contents(RUNTIME_PATH . $module . 'init' . EXT, $content);

        $output->writeln('<info>Succeed!</info>');
    }

    protected function buildCacheContent($module)
    {
        $content = '';
        $path    = realpath(APP_PATH . $module) . DS;

        if ($module) {
            // Load module configuration
            $config = ThinkConfig::load(CONF_PATH . $module . 'config' . CONF_EXT);

            // Read the database configuration file
            $filename = CONF_PATH . $module . 'database' . CONF_EXT;
            ThinkConfig::load($filename, 'database');

            // Load application status configuration
            if ($config['app_status']) {
                $config = ThinkConfig::load(CONF_PATH . $module . $config['app_status'] . CONF_EXT);
            }
            // Read the extension configuration file
            if (is_dir(CONF_PATH . $module . 'extra')) {
                $dir   = CONF_PATH . $module . 'extra';
                $files = scandir($dir);
                foreach ($files as $file) {
                    if (strpos($file, CONF_EXT)) {
                        $filename = $dir . DS . $file;
                        ThinkConfig::load($filename, pathinfo($file, PATHINFO_FILENAME));
                    }
                }
            }
        }

        // Load hook definitions
        if (is_file(CONF_PATH . $module . 'tags' . EXT)) {
            $content .= '\think\Hook::import(' . (var_export(include CONF_PATH . $module . 'tags' . EXT, true)) . ');' . PHP_EOL;
        }

        // Load the common functions file
        if (is_file($path . 'common' . EXT)) {
            $content .= substr(php_strip_whitespace($path . 'common' . EXT), 5) . PHP_EOL;
        }

        $content .= '\think\Config::set(' . var_export(ThinkConfig::get(), true) . ');';
        return $content;
    }
}
