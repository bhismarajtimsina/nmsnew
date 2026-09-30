<?php

namespace WCAA\Infrastructure\Components\Installer;

use Monolog\Logger;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\StorageMigrationSystem\MigrationExecutor;

abstract class InstallerAbstract
{
    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var Logger
     */
    protected $logger;

    protected $componentConfig = null;

    /**
     * Array of migration names
     *
     * @var string[]
     */
    protected $migrationsList = [];


    /**
     * @var MigrationExecutor
     */
    protected $migrationExecutor;

    /**
     * @param Logger $logger
     * @param MigrationExecutor $migrationExecutor
     * @throws \Exception
     */

    function __construct(Logger $logger, MigrationExecutor $migrationExecutor)
    {
        $this->migrationExecutor = $migrationExecutor;
        $this->logger = $logger;
        $this->componentConfig = $this->getComponentConfig();

        $name = "^{$this->componentConfig['name']}:.*$";
        $this->migrationsList = array_values(
            array_filter($this->migrationExecutor->getMigrationsList(), function ($migration) use ($name) {
                return preg_match("/{$name}/", $migration['name']);
            })
        );
    }

    function __invoke(?InputInterface $input = null, ?OutputInterface $output = null)
    {
        $this->output = $output;
        $this->input = $input;
        return $this;
    }

    function executeMigrations($type = 'up', $breakOnError = true)
    {
        $this->log("Start execute component migrations");
        foreach ($this->migrationsList as $migrationName) {
            if($migrationName['is_up'] && $type == 'up') {
                $this->log("Migration  '{$migrationName['name']}' already exists!");
                continue;
            }
            if ($error = $this->migrationExecutor->executeMigration($migrationName['name'], $type, $breakOnError)) {
                $this->log("Error execute migration '{$migrationName['name']}' - {$error->getMessage()}", 'error');
                throw $error;
            }
            $this->log("Migration '{$migrationName['name']}' success migrated!");
        }
        return $this;
    }


    protected function getComponentConfig()
    {
        /**
         * @TODO Гребанный быдлокод
         */
        $rc = new \ReflectionClass(get_class($this));
        $path = "/config.php";
        for($i = 0; $i<5; $i++) {
            if (file_exists(dirname($rc->getFileName()) . $path)) {
                return (require dirname($rc->getFileName()) . $path);
            }
            $path = "/.." . $path;
        }
        throw new \Exception("Configuration file for component not found!");
    }

    protected function log($message, $level = 'debug', $context = [])
    {
        if ($this->output) {
            $this->output->writeln($message);
        }
        $this->logger->log($level, $message, $context);
        return $this;
    }


    abstract function install();

    abstract function uninstall();

    function enable()
    {
        $this->logger->warning("Component '{$this->componentConfig['name']}' doesn't support enabled!");
    }
    function disable()
    {
        $this->logger->warning("Component '{$this->componentConfig['name']}' doesn't support enabled!");
    }
}
