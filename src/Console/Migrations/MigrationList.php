<?php


namespace WCAA\Console\Migrations;


use DI\Annotation\Inject;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\StorageMigrationSystem\MigrationCollector;
use WCAA\Interfaces\CacheInterface;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrationList extends AbstractCommand
{
    /**
     * @Inject
     * @var MigrationCollector
     */
    protected $collector;

    protected function configure()
    {
        $this->setName("migration:list")
            ->setDescription("Flush all keys in cache")
        ;
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $list = $this->collector->getMigrationsList();
        $table = new Table($output);
        $table->setHeaders([
            'Name',
            'Up path',
            'Down path',
            'Is Up',
        ]);
        foreach ($list as $key) {
            if(!isset($key['name'])) continue;
            $table->addRow([
                $key['name'],
                $key['paths']['up'],
                $key['paths']['down'],
                $key['is_up'] ? "Yes" : "No",
            ]);
        }
        $table->render();

        foreach ($this->collector->getDuplicatePrefixes() as $prefix => $names) {
            $output->writeln("<comment>WARNING: migration prefix {$prefix} is used more than once: " . join(", ", $names) . "</comment>");
            $output->writeln("<comment>         Both run, but their relative order is decided by the text after the number.</comment>");
        }
        return self::SUCCESS;
    }
}