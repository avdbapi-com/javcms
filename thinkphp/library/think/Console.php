<?php
// +----------------------------------------------------------------------
// | TopThink [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2015 http://www.topthink.com All rights reserved.
// +----------------------------------------------------------------------
// | Author: zhangyajun <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think;

use think\console\Command;
use think\console\command\Help as HelpCommand;
use think\console\Input;
use think\console\input\Argument as InputArgument;
use think\console\input\Definition as InputDefinition;
use think\console\input\Option as InputOption;
use think\console\Output;
use think\console\output\driver\Buffer;

class Console
{
    /* *
 * @var string Console application name */
    private $name;

    /* *
 * @var string Console application version */
    private $version;

    /* *
 * @var Command[] Registered commands */
    private $commands = [];

    /* *
 * @var bool Whether help for the selected command was requested */
    private $wantHelps = false;

    /* *
 * @var bool Whether to catch exceptions */
    private $catchExceptions = true;

    /* *
 * @var bool Whether to automatically exit execution */
    private $autoExit = true;

    /* *
 * @var InputDefinition Input Definition */
    private $definition;

    /* *
 * @var string Command executed by default */
    private $defaultCommand;

    /* *
 * @var array Commands provided by default */
    private static $defaultCommands = [
        "think\\console\\command\\Help",
        "think\\console\\command\\Lists",
        "think\\console\\command\\Build",
        "think\\console\\command\\Clear",
        "think\\console\\command\\make\\Controller",
        "think\\console\\command\\make\\Model",
        "think\\console\\command\\optimize\\Autoload",
        "think\\console\\command\\optimize\\Config",
        "think\\console\\command\\optimize\\Route",
        "think\\console\\command\\optimize\\Schema",
    ];

    /* *
 * Console constructor.
 * @access public
 * @param string $name name
 * @param string $version version
 * @param null|string $user Username to run under */
    public function __construct($name = 'UNKNOWN', $version = 'UNKNOWN', $user = null)
    {
        $this->name    = $name;
        $this->version = $version;

        if ($user) {
            $this->setUser($user);
        }

        $this->defaultCommand = 'list';
        $this->definition     = $this->getDefaultInputDefinition();

        foreach ($this->getDefaultCommands() as $command) {
            $this->add($command);
        }
    }

    /* *
 * Set the execution user
 * @param string $user Username */
    public function setUser($user)
    {
        $user = posix_getpwnam($user);
        if ($user) {
            posix_setuid($user['uid']);
            posix_setgid($user['gid']);
        }
    }

    /* *
 * Initialize Console
 * @access public
 * @param bool $run Whether to run Console
 * @return int|Console */
    public static function init($run = true)
    {
        static $console;

        if (!$console) {
            $config = Config::get('console');
            // Create the console instance.
            $console = new self($config['name'], $config['version'], $config['user']);

            // Load configured command classes.
            if (is_file(CONF_PATH . 'command' . EXT)) {
                $commands = include CONF_PATH . 'command' . EXT;

                if (is_array($commands)) {
                    foreach ($commands as $command) {
                        class_exists($command) &&
                        is_subclass_of($command, "\\think\\console\\Command") &&
                        $console->add(new $command());  // Register the command.
                    }
                }
            }
        }

        return $run ? $console->run() : $console;
    }

    /* *
 * Invoke a command
 * @access public
 * @param string $command
 * @param array $parameters
 * @param string $driver
 * @return Output */
    public static function call($command, array $parameters = [], $driver = 'buffer')
    {
        $console = self::init(false);

        array_unshift($parameters, $command);

        $input  = new Input($parameters);
        $output = new Output($driver);

        $console->setCatchExceptions(false);
        $console->find($command)->run($input, $output);

        return $output;
    }

    /* *
 * Run the console using command-line input.
 * @access public
 * @return int
 * @throws \Exception */
    public function run()
    {
        $input  = new Input();
        $output = new Output();

        $this->configureIO($input, $output);

        try {
            $exitCode = $this->doRun($input, $output);
        } catch (\Exception $e) {
            if (!$this->catchExceptions) throw $e;

            $output->renderException($e);

            $exitCode = $e->getCode();

            if (is_numeric($exitCode)) {
                $exitCode = ((int) $exitCode) ?: 1;
            } else {
                $exitCode = 1;
            }
        }

        if ($this->autoExit) {
            if ($exitCode > 255) $exitCode = 255;

            exit($exitCode);
        }

        return $exitCode;
    }

    /* *
 * Process global options and execute the selected command.
 * @access public
 * @param Input $input
 * @param Output $output
 * @return int */
    public function doRun(Input $input, Output $output)
    {
        // Get version information
        if (true === $input->hasParameterOption(['--version', '-V'])) {
            $output->writeln($this->getLongVersion());

            return 0;
        }

        $name = $this->getCommandName($input);

        // Get help
        if (true === $input->hasParameterOption(['--help', '-h'])) {
            if (!$name) {
                $name  = 'help';
                $input = new Input(['help']);
            } else {
                $this->wantHelps = true;
            }
        }

        if (!$name) {
            $name  = $this->defaultCommand;
            $input = new Input([$this->defaultCommand]);
        }

        return $this->doRunCommand($this->find($name), $input, $output);
    }

    /* *
 * Set input parameter definitions
 * @access public
 * @param InputDefinition $definition Input definition
 * @return $this */
    public function setDefinition(InputDefinition $definition)
    {
        $this->definition = $definition;

        return $this;
    }

    /* *
 * Get the input definition.
 * @access public
 * @return InputDefinition */
    public function getDefinition()
    {
        return $this->definition;
    }

    /* *
 * Get help information
 * @access public
 * @return string */
    public function getHelp()
    {
        return $this->getLongVersion();
    }

    /* *
 * Set whether to catch exceptions
 * @access public
 * @param bool $boolean Whether to catch exceptions
 * @return $this */
    public function setCatchExceptions($boolean)
    {
        $this->catchExceptions = (bool) $boolean;

        return $this;
    }

    /* *
 * Set whether to automatically exit
 * @access public
 * @param bool $boolean Whether to exit automatically
 * @return $this */
    public function setAutoExit($boolean)
    {
        $this->autoExit = (bool) $boolean;

        return $this;
    }

    /* *
 * Get the console application name.
 * @access public
 * @return string */
    public function getName()
    {
        return $this->name;
    }

    /* *
 * Set the console application name.
 * @access public
 * @param string $name name
 * @return $this */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /* *
 * Get the version
 * @access public
 * @return string */
    public function getVersion()
    {
        return $this->version;
    }

    /* *
 * Set version
 * @access public
 * @param string $version version info
 * @return $this */
    public function setVersion($version)
    {
        $this->version = $version;

        return $this;
    }

    /* *
 * Get the formatted application name and version.
 * @access public
 * @return string */
    public function getLongVersion()
    {
        if ('UNKNOWN' !== $this->getName() && 'UNKNOWN' !== $this->getVersion()) {
            return sprintf(
                '<info>%s</info> version <comment>%s</comment>',
                $this->getName(),
                $this->getVersion()
            );
        }

        return '<info>Console Tool</info>';
    }

    /* *
 * Register a command by name.
 * @access public
 * @param string $name Command name
 * @return Command */
    public function register($name)
    {
        return $this->add(new Command($name));
    }

    /* *
 * Add multiple commands.
 * @access public
 * @param Command[] $commands Command instances
 * @return $this */
    public function addCommands(array $commands)
    {
        foreach ($commands as $command) $this->add($command);

        return $this;
    }

    /* *
 * Add a command
 * @access public
 * @param Command $command Command instance
 * @return Command|bool */
    public function add(Command $command)
    {
        if (!$command->isEnabled()) {
            $command->setConsole(null);
            return false;
        }

        $command->setConsole($this);

        if (null === $command->getDefinition()) {
            throw new \LogicException(
                sprintf('Command class "%s" is not correctly initialized. You probably forgot to call the parent constructor.', get_class($command))
            );
        }

        $this->commands[$command->getName()] = $command;

        foreach ($command->getAliases() as $alias) {
            $this->commands[$alias] = $command;
        }

        return $command;
    }

    /* *
 * Get a registered command, or its help command when help was requested.
 * @access public
 * @param string $name
 * @return Command
 * @throws \InvalidArgumentException */
    public function get($name)
    {
        if (!isset($this->commands[$name])) {
            throw new \InvalidArgumentException(
                sprintf('The command "%s" does not exist.', $name)
            );
        }

        $command = $this->commands[$name];

        if ($this->wantHelps) {
            $this->wantHelps = false;

            /* * @var HelpCommand $helpCommand */
            $helpCommand = $this->get('help');
            $helpCommand->setCommand($command);

            return $helpCommand;
        }

        return $command;
    }

    /* *
 * Whether a command exists
 * @access public
 * @param string $name Command name
 * @return bool */
    public function has($name)
    {
        return isset($this->commands[$name]);
    }

    /* *
 * Get all command namespaces, including those used by aliases.
 * @access public
 * @return array */
    public function getNamespaces()
    {
        $namespaces = [];

        foreach ($this->commands as $command) {
            $namespaces = array_merge(
                $namespaces, $this->extractAllNamespaces($command->getName())
            );

            foreach ($command->getAliases() as $alias) {
                $namespaces = array_merge(
                    $namespaces, $this->extractAllNamespaces($alias)
                );
            }
        }

        return array_values(array_unique(array_filter($namespaces)));
    }

    /* *
 * Resolve a command namespace, allowing abbreviated segments.
 * @access public
 * @param string $namespace
 * @return string
 * @throws \InvalidArgumentException */
    public function findNamespace($namespace)
    {
        $expr = preg_replace_callback('{([^:]+|)}', function ($matches) {
            return preg_quote($matches[1]) . '[^:]*';
        }, $namespace);

        $allNamespaces = $this->getNamespaces();
        $namespaces    = preg_grep('{^' . $expr . '}', $allNamespaces);

        if (empty($namespaces)) {
            $message = sprintf(
                'There are no commands defined in the "%s" namespace.', $namespace
            );

            if ($alternatives = $this->findAlternatives($namespace, $allNamespaces)) {
                if (1 == count($alternatives)) {
                    $message .= "\n\nDid you mean this?\n    ";
                } else {
                    $message .= "\n\nDid you mean one of these?\n    ";
                }

                $message .= implode("\n    ", $alternatives);
            }

            throw new \InvalidArgumentException($message);
        }

        $exact = in_array($namespace, $namespaces, true);

        if (count($namespaces) > 1 && !$exact) {
            throw new \InvalidArgumentException(
                sprintf(
                    'The namespace "%s" is ambiguous (%s).',
                    $namespace,
                    $this->getAbbreviationSuggestions(array_values($namespaces)))
            );
        }

        return $exact ? $namespace : reset($namespaces);
    }

    /* *
 * Find a command by name or alias, allowing abbreviated segments.
 * @access public
 * @param string $name
 * @return Command
 * @throws \InvalidArgumentException */
    public function find($name)
    {
        $expr = preg_replace_callback('{([^:]+|)}', function ($matches) {
            return preg_quote($matches[1]) . '[^:]*';
        }, $name);

        $allCommands = array_keys($this->commands);
        $commands    = preg_grep('{^' . $expr . '}', $allCommands);

        if (empty($commands) || count(preg_grep('{^' . $expr . '$}', $commands)) < 1) {
            if (false !== ($pos = strrpos($name, ':'))) {
                $this->findNamespace(substr($name, 0, $pos));
            }

            $message = sprintf('Command "%s" is not defined.', $name);

            if ($alternatives = $this->findAlternatives($name, $allCommands)) {
                if (1 == count($alternatives)) {
                    $message .= "\n\nDid you mean this?\n    ";
                } else {
                    $message .= "\n\nDid you mean one of these?\n    ";
                }
                $message .= implode("\n    ", $alternatives);
            }

            throw new \InvalidArgumentException($message);
        }

        if (count($commands) > 1) {
            $commandList = $this->commands;
            $commands    = array_filter($commands, function ($nameOrAlias) use ($commandList, $commands) {
                $commandName = $commandList[$nameOrAlias]->getName();

                return $commandName === $nameOrAlias || !in_array($commandName, $commands);
            });
        }

        $exact = in_array($name, $commands, true);
        if (count($commands) > 1 && !$exact) {
            $suggestions = $this->getAbbreviationSuggestions(array_values($commands));

            throw new \InvalidArgumentException(
                sprintf('Command "%s" is ambiguous (%s).', $name, $suggestions)
            );
        }

        return $this->get($exact ? $name : reset($commands));
    }

    /* *
 * Get registered commands, optionally filtered by namespace.
 * @access public
 * @param string $namespace
 * @return Command[] */
    public function all($namespace = null)
    {
        if (null === $namespace) return $this->commands;

        $commands = [];

        foreach ($this->commands as $name => $command) {
            $ext = $this->extractNamespace($name, substr_count($namespace, ':') + 1);

            if ($ext === $namespace) $commands[$name] = $command;
        }

        return $commands;
    }

    /* *
 * Map command-name prefixes to the full names they can abbreviate.
 * @access public
 * @param array $names Command names
 * @return array */
    public static function getAbbreviations($names)
    {
        $abbrevs = [];
        foreach ($names as $name) {
            for ($len = strlen($name); $len > 0; --$len) {
                $abbrev             = substr($name, 0, $len);
                $abbrevs[$abbrev][] = $name;
            }
        }

        return $abbrevs;
    }

    /* *
 * Configure input and output instances based on user parameters and options
 * @access protected
 * @param Input $input input instance
 * @param Output $output Output instance
 * @return void */
    protected function configureIO(Input $input, Output $output)
    {
        if (true === $input->hasParameterOption(['--ansi'])) {
            $output->setDecorated(true);
        } elseif (true === $input->hasParameterOption(['--no-ansi'])) {
            $output->setDecorated(false);
        }

        if (true === $input->hasParameterOption(['--no-interaction', '-n'])) {
            $input->setInteractive(false);
        }

        if (true === $input->hasParameterOption(['--quiet', '-q'])) {
            $output->setVerbosity(Output::VERBOSITY_QUIET);
        } else {
            if ($input->hasParameterOption('-vvv') || $input->hasParameterOption('--verbose=3') || $input->getParameterOption('--verbose') === 3) {
                $output->setVerbosity(Output::VERBOSITY_DEBUG);
            } elseif ($input->hasParameterOption('-vv') || $input->hasParameterOption('--verbose=2') || $input->getParameterOption('--verbose') === 2) {
                $output->setVerbosity(Output::VERBOSITY_VERY_VERBOSE);
            } elseif ($input->hasParameterOption('-v') || $input->hasParameterOption('--verbose=1') || $input->hasParameterOption('--verbose') || $input->getParameterOption('--verbose')) {
                $output->setVerbosity(Output::VERBOSITY_VERBOSE);
            }
        }
    }

    /* *
 * Execute a command with the supplied input and output.
 * @access protected
 * @param Command $command Command instance
 * @param Input $input input instance
 * @param Output $output Output instance
 * @return int
 * @throws \Exception */
    protected function doRunCommand(Command $command, Input $input, Output $output)
    {
        return $command->run($input, $output);
    }

    /* *
 * Get the command name from the first input argument.
 * @access protected
 * @param Input $input input
 * @return string */
    protected function getCommandName(Input $input)
    {
        return $input->getFirstArgument();
    }

    /* *
 * Get the default input definition
 * @access protected
 * @return InputDefinition */
    protected function getDefaultInputDefinition()
    {
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this console version'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages: 1 for verbose output, 2 for more verbose output and 3 for debug'),
            new InputOption('--ansi', '', InputOption::VALUE_NONE, 'Force ANSI output'),
            new InputOption('--no-ansi', '', InputOption::VALUE_NONE, 'Disable ANSI output'),
            new InputOption('--no-interaction', '-n', InputOption::VALUE_NONE, 'Do not ask any interactive question'),
        ]);
    }

    /* *
 * Instantiate the default commands.
 * @access protected
 * @return Command[] */
    protected function getDefaultCommands()
    {
        $defaultCommands = [];

        foreach (self::$defaultCommands as $class) {
            if (class_exists($class) && is_subclass_of($class, "think\\console\\Command")) {
                $defaultCommands[] = new $class();
            }
        }

        return $defaultCommands;
    }

    /* *
 * Add default command classes.
 * @access public
 * @param array $classes Command class names
 * @return void */
    public static function addDefaultCommands(array $classes)
    {
        self::$defaultCommands = array_merge(self::$defaultCommands, $classes);
    }

    /* *
 * Format a short list of abbreviation suggestions.
 * @access private
 * @param array $abbrevs Matching names
 * @return string */
    private function getAbbreviationSuggestions($abbrevs)
    {
        return sprintf(
            '%s, %s%s',
            $abbrevs[0],
            $abbrevs[1],
            count($abbrevs) > 2 ? sprintf(' and %d more', count($abbrevs) - 2) : ''
        );
    }

    /* *
 * Returns the namespace portion of the command
 * @access public
 * @param string $name Command name
 * @param int|null $limit Maximum number of namespace segments; null includes all
 * @return string */
    public function extractNamespace($name, $limit = null)
    {
        $parts = explode(':', $name);
        array_pop($parts);

        return implode(':', null === $limit ? $parts : array_slice($parts, 0, $limit));
    }

    /* *
 * Find alternative suggestions
 * @access private
 * @param string $name Command or namespace name
 * @param array|\Traversable $collection Candidate names
 * @return array */
    private function findAlternatives($name, $collection)
    {
        $threshold       = 1e3;
        $alternatives    = [];
        $collectionParts = [];

        foreach ($collection as $item) {
            $collectionParts[$item] = explode(':', $item);
        }

        foreach (explode(':', $name) as $i => $subname) {
            foreach ($collectionParts as $collectionName => $parts) {
                $exists = isset($alternatives[$collectionName]);

                if (!isset($parts[$i]) && $exists) {
                    $alternatives[$collectionName] += $threshold;
                    continue;
                } elseif (!isset($parts[$i])) {
                    continue;
                }

                $lev = levenshtein($subname, $parts[$i]);

                if ($lev <= strlen($subname) / 3 ||
                    '' !== $subname &&
                    false !== strpos($parts[$i], $subname)
                ) {
                    $alternatives[$collectionName] = $exists ?
                        $alternatives[$collectionName] + $lev :
                        $lev;
                } elseif ($exists) {
                    $alternatives[$collectionName] += $threshold;
                }
            }
        }

        foreach ($collection as $item) {
            $lev = levenshtein($name, $item);

            if ($lev <= strlen($name) / 3 || false !== strpos($item, $name)) {
                $alternatives[$item] = isset($alternatives[$item]) ?
                    $alternatives[$item] - $lev :
                    $lev;
            }
        }

        $alternatives = array_filter($alternatives, function ($lev) use ($threshold) {
            return $lev < 2 * $threshold;
        });

        asort($alternatives);

        return array_keys($alternatives);
    }

    /* *
 * Set the default command
 * @access public
 * @param string $commandName Command name
 * @return $this */
    public function setDefaultCommand($commandName)
    {
        $this->defaultCommand = $commandName;

        return $this;
    }

    /* *
 * Extract every namespace prefix from a command name.
 * @access private
 * @param string $name Command name
 * @return array */
    private function extractAllNamespaces($name)
    {
        $namespaces = [];

        foreach (explode(':', $name, -1) as $part) {
            if (count($namespaces)) {
                $namespaces[] = end($namespaces) . ':' . $part;
            } else {
                $namespaces[] = $part;
            }
        }

        return $namespaces;
    }

}
