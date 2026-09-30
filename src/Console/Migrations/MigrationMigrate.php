<?php


namespace WCAA\Console\Migrations;


use DI\Annotation\Inject;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\StorageMigrationSystem\MigrationCollector;
use WCAA\Infrastructure\StorageMigrationSystem\MigrationExecutor;
use WCAA\Infrastructure\StorageMigrationSystem\MigrationExecutorCallbackResult;
use WCAA\Interfaces\CacheInterface;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationMigrate extends AbstractCommand
{
    /**
     * @Inject
     * @var MigrationExecutor
     */
    protected $executor;

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    protected function configure()
    {
        $this->setName("migration:migrate")
            ->addArgument("migration_name", InputArgument::OPTIONAL, "Name of migration")
            ->addOption("force", "f", InputOption::VALUE_NEGATABLE, "Ignore errors - dont break migration on errors")
            ->addOption("up", "up", InputOption::VALUE_NEGATABLE, "Up selected migrations")
            ->addOption("down", "down", InputOption::VALUE_NEGATABLE, "Down selected migrations")
            ->setDescription("Migrate migrations. Default used up migrations")
        ;
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $name = $input->getArgument('migration_name');
        $type = $this->getActionType($input);
        $force = $input->getOption('force');
        $output->writeln("Use type of migration: {$type}");
        if(!$name) {
            $name = "sys:*";
        }
        if($name && !preg_match('/\*/', $name)) {
            $output->writeln("WARNING! You choose some migration. Fact of setted migration will not checked");
            $output->writeln("");
            $output->writeln("Read queries for migration with name $name ...");
            $queries = $this->executor->readSQLMigration($name, $type);
            $output->writeln("Run migration '$name'. Count $type queries: " . count($queries));
            $error = $this->executor->executeMigration($name, $type, !$force, $this->getCallBack($output));
            if($error === null) {
                $output->writeln("=====================================================");
                $output->writeln("SUCCESS! Migration with $name success migrated!");
                $output->writeln("=====================================================");
            } else {
                $output->writeln("=====================================================");
                $output->writeln("FAILED! Migration with $name failed!");
                $output->writeln("Last error: {$error->getMessage()}");
                $output->writeln("=====================================================");
            }
        } elseif ($name && preg_match('/\*/', $name)) {
            $name = str_replace("*", ".*", $name);
            $migrations = array_filter($this->executor->getMigrationsList(), function ($migration) use ($name) {
              return preg_match("/{$name}/", $migration['name']);
            });
            $this->executeMigrations($migrations, $type, $output, $force);
        }
        $output->writeln("Clear cache...");
        $this->cache->deleteByRegex("/^STORAGE:.*$/");
        return self::SUCCESS;
    }

    function executeMigrations($migrations, $type, $output, $force) {
        $output->writeln("Count of migrations in system ". count($migrations) . ":");
        $incrementor = 1;
        foreach ($migrations as $migration) {
            $output->writeln("\t{$incrementor}. {$migration['name']}");
            $incrementor++;
        }
        $output->writeln("");
        $output->writeln("-------------------------Migrations----------------------------");
        foreach ($migrations as $migration) {
            $output->writeln("===============================================================");
            $output->writeln("Run: {$migration['name']}");
            if($type === 'up' && $migration['is_up']) {
                $output->writeln("WARNING!!! Migration {$migration['name']} setuped early, ignoring...");
                continue;
            }
            if($type === 'down' && !$migration['is_up']) {
                $output->writeln("WARNING!!! Migration {$migration['name']} not setuped on server, ignoring...");
                continue;
            }
            if(!$migration['paths'][$type]) {
                $output->writeln("WARNING!!! Migration {$migration['name']} doesnt have $type queries, ignoring...");
                continue;
            }
            $output->writeln("Count queries to execute: " . count($this->executor->readSQLMigration($migration['name'], $type)));
            $output->writeln("Start execute migration! ");
            $start = microtime(true);
            if($output->isVerbose()) {
                $output->writeln('-------------------------------------------------');
            }
            $error = $this->executor->executeMigration($migration['name'], $type, !$force, $this->getCallBack($output));
            if($output->isVerbose()) {
                $output->writeln('-------------------------------------------------');
            }
            if($error === null) {
                $output->writeln("SUCCESS! Migration with name {$migration['name']} success migrated! Execution time: " . round(microtime(true) - $start, 3));
            } else {
                $output->writeln("Last error: {$error->getMessage()}");
                if(!$force) {
                    $output->writeln("");
                    $output->writeln("WORK WITH MIGRATIONS STOPPED!");
                    break;
                }
            }
        }
        $output->writeln("===============================================================");
        $output->writeln("Working with migrations finished!");
    }
    protected function getCallBack($output) {
        return function (MigrationExecutorCallbackResult $result) use ($output) {
            if($result->hasError()) {
                $output->writeln("ERROR execute query '{$result->getQuery()}': {$result->getError()->getMessage()}");
                return;
            }
            if($output->isVerbose()) {
                $output->writeln("Success: {$result->getQuery()}, time: {$result->getTookTime()}sec");
            }
        };

    }

    function getActionType(InputInterface $input) {
        //Choose type of action
        $up = $input->getOption('up');
        $down = $input->getOption('down');
        if($down) {
            return  'down';
        }
        if($up) {
            return  'up';
        }
        throw new InvalidOptionException("Incorrect option up|down. Only once of option can be used");
    }

}