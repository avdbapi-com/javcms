<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

namespace think\console;

use think\Console;
use think\console\input\Argument;
use think\console\input\Definition;
use think\console\input\Option;

class Command
{

    /* * @var Console */
    private $console;
    private $name;
    private $aliases = [];
    private $definition;
    private $help;
    private $description;
    private $ignoreValidationErrors          = false;
    private $consoleDefinitionMerged         = false;
    private $consoleDefinitionMergedWithArgs = false;
    private $code;
    private $synopsis = [];
    private $usages   = [];

    /* * @var Input */
    protected $input;

    /* * @var Output */
    protected $output;

    /* *
 * Initialize and configure the command
 * @param string|null $name Command name; configure() must set it when omitted
 * @throws \LogicException
 * @api */
    public function __construct($name = null)
    {
        $this->definition = new Definition();

        if (null !== $name) {
            $this->setName($name);
        }

        $this->configure();

        if (!$this->name) {
            throw new \LogicException(sprintf('The command defined in "%s" cannot have an empty name.', get_class($this)));
        }
    }

    /* *
 * Ignore validation errors */
    public function ignoreValidationErrors()
    {
        $this->ignoreValidationErrors = true;
    }

    /* *
 * Set the console associated with this command
 * @param Console $console */
    public function setConsole(?Console $console = null)
    {
        $this->console = $console;
    }

    /* *
 * Get the console
 * @return Console
 * @api */
    public function getConsole()
    {
        return $this->console;
    }

    /* *
 * Check whether this command is enabled
 * @return bool */
    public function isEnabled()
    {
        return true;
    }

    /* *
 * Configure the command */
    protected function configure()
    {
    }

    /* *
 * Execute the command
 * @param Input $input
 * @param Output $output
 * @return null|int
 * @throws \\LogicException
 * @see setCode() */
    protected function execute(Input $input, Output $output)
    {
        throw new \LogicException('You must override the execute() method in the concrete command class.');
    }

    /* *
 * Interact with the user before executing the command
 * @param Input $input
 * @param Output $output */
    protected function interact(Input $input, Output $output)
    {
    }

    /* *
 * Initialize the command before interaction and execution
 * @param Input $input Console input
 * @param Output $output Console output */
    protected function initialize(Input $input, Output $output)
    {
    }

    /* *
 * Run the command and return its exit status
 * @param Input $input
 * @param Output $output
 * @return int
 * @throws \\Exception
 * @see setCode()
 * @see execute() */
    public function run(Input $input, Output $output)
    {
        $this->input  = $input;
        $this->output = $output;

        $this->getSynopsis(true);
        $this->getSynopsis(false);

        $this->mergeConsoleDefinition();

        try {
            $input->bind($this->definition);
        } catch (\Exception $e) {
            if (!$this->ignoreValidationErrors) {
                throw $e;
            }
        }

        $this->initialize($input, $output);

        if ($input->isInteractive()) {
            $this->interact($input, $output);
        }

        $input->validate();

        if ($this->code) {
            $statusCode = call_user_func($this->code, $input, $output);
        } else {
            $statusCode = $this->execute($input, $output);
        }

        return is_numeric($statusCode) ? (int) $statusCode : 0;
    }

    /* *
 * Set the callable used to execute the command
 * @param callable $code Execution callback receiving Input and Output instances
 * @return Command
 * @throws \\InvalidArgumentException
 * @see execute() */
    public function setCode(callable $code)
    {
        if (!is_callable($code)) {
            throw new \InvalidArgumentException('Invalid callable provided to Command::setCode.');
        }

        if (PHP_VERSION_ID >= 50400 && $code instanceof \Closure) {
            $r = new \ReflectionFunction($code);
            if (null === $r->getClosureThis()) {
                $code = \Closure::bind($code, $this);
            }
        }

        $this->code = $code;

        return $this;
    }

    /* *
 * Merge the console argument and option definitions
 * @param bool $mergeArgs */
    public function mergeConsoleDefinition($mergeArgs = true)
    {
        if (null === $this->console
            || (true === $this->consoleDefinitionMerged
                && ($this->consoleDefinitionMergedWithArgs || !$mergeArgs))
        ) {
            return;
        }

        if ($mergeArgs) {
            $currentArguments = $this->definition->getArguments();
            $this->definition->setArguments($this->console->getDefinition()->getArguments());
            $this->definition->addArguments($currentArguments);
        }

        $this->definition->addOptions($this->console->getDefinition()->getOptions());

        $this->consoleDefinitionMerged = true;
        if ($mergeArgs) {
            $this->consoleDefinitionMergedWithArgs = true;
        }
    }

    /* *
 * Set the command argument and option definitions
 * @param array|Definition $definition
 * @return Command
 * @api */
    public function setDefinition($definition)
    {
        if ($definition instanceof Definition) {
            $this->definition = $definition;
        } else {
            $this->definition->setDefinition($definition);
        }

        $this->consoleDefinitionMerged = false;

        return $this;
    }

    /* *
 * Get the command argument and option definitions
 * @return Definition
 * @api */
    public function getDefinition()
    {
        return $this->definition;
    }

    /* *
 * Get the definitions for the current command
 * @return Definition */
    public function getNativeDefinition()
    {
        return $this->getDefinition();
    }

    /* *
 * Add a command argument
 * @param string $name Argument name
 * @param int $mode Argument or option mode
 * @param string $description
 * @param mixed $default Default value
 * @return Command */
    public function addArgument($name, $mode = null, $description = '', $default = null)
    {
        $this->definition->addArgument(new Argument($name, $mode, $description, $default));

        return $this;
    }

    /* *
 * Add options
 * @param string $name option name
 * @param string $shortcut Option shortcut
 * @param int $mode Argument or option mode
 * @param string $description
 * @param mixed $default Default value
 * @return Command */
    public function addOption($name, $shortcut = null, $mode = null, $description = '', $default = null)
    {
        $this->definition->addOption(new Option($name, $shortcut, $mode, $description, $default));

        return $this;
    }

    /* *
 * Set the command name
 * @param string $name
 * @return Command
 * @throws \InvalidArgumentException */
    public function setName($name)
    {
        $this->validateName($name);

        $this->name = $name;

        return $this;
    }

    /* *
 * Get the command name
 * @return string */
    public function getName()
    {
        return $this->name;
    }

    /* *
 * Set the command description
 * @param string $description
 * @return Command */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /* *
 * Get description
 * @return string */
    public function getDescription()
    {
        return $this->description;
    }

    /* *
 * Set the command help text
 * @param string $help
 * @return Command */
    public function setHelp($help)
    {
        $this->help = $help;

        return $this;
    }

    /* *
 * Get help information
 * @return string */
    public function getHelp()
    {
        return $this->help;
    }

    /* *
 * Expand command placeholders in the help text
 * @return string */
    public function getProcessedHelp()
    {
        $name = $this->name;

        $placeholders = [
            '%command.name%',
            '%command.full_name%',
        ];
        $replacements = [
            $name,
            $_SERVER['PHP_SELF'] . ' ' . $name,
        ];

        return str_replace($placeholders, $replacements, $this->getHelp());
    }

    /* *
 * Set the command aliases
 * @param string [] $aliases
 * @return Command
 * @throws \InvalidArgumentException */
    public function setAliases($aliases)
    {
        if (!is_array($aliases) && !$aliases instanceof \Traversable) {
            throw new \InvalidArgumentException('$aliases must be an array or an instance of \Traversable');
        }

        foreach ($aliases as $alias) {
            $this->validateName($alias);
        }

        $this->aliases = $aliases;

        return $this;
    }

    /* *
 * Get aliases
 * @return array */
    public function getAliases()
    {
        return $this->aliases;
    }

    /* *
 * Get the command synopsis
 * @param bool $short Whether to use the abbreviated option synopsis
 * @return string */
    public function getSynopsis($short = false)
    {
        $key = $short ? 'short' : 'long';

        if (!isset($this->synopsis[$key])) {
            $this->synopsis[$key] = trim(sprintf('%s %s', $this->name, $this->definition->getSynopsis($short)));
        }

        return $this->synopsis[$key];
    }

    /* *
 * Add usage instructions
 * @param string $usage
 * @return $this */
    public function addUsage($usage)
    {
        if (0 !== strpos($usage, $this->name)) {
            $usage = sprintf('%s %s', $this->name, $usage);
        }

        $this->usages[] = $usage;

        return $this;
    }

    /* *
 * Get usage instructions
 * @return array */
    public function getUsages()
    {
        return $this->usages;
    }

    /* *
 * Validate a command name or alias
 * @param string $name
 * @throws \InvalidArgumentException */
    private function validateName($name)
    {
        if (!preg_match('/^[^\:]++(\:[^\:]++)*$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Command name "%s" is invalid.', $name));
        }
    }
}
