<?php


namespace WCC\TrapService\Console;


use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Interfaces\CacheInterface;
use WCC\TrapService\Controllers\Controller;
use WCC\TrapService\Storage\TrapLogStorage;

class ClearOldLogs extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var TrapLogStorage
     */
    protected $storage;

    function config()
    {
        //For all module console commands added prefix - module name
        $this->setName('clear-old-logs')
            ->addArgument("days", InputArgument::OPTIONAL, "Days before clear", 30)
            ->setDescription("Clear old logs.");
    }


    function exec(InputInterface $input, OutputInterface $output)
    {
        $deletedCount = $this->storage->clearOldLogs($input->getArgument('days'));
        $this->output->writeln("Success deleted {$deletedCount} logs!");
        return self::SUCCESS;
    }

}
