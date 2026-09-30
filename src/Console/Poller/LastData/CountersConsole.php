<?php

namespace WCAA\Console\Poller\LastData;

use Monolog\Logger;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\Poller\PollerProcessor;
use WCAA\Infrastructure\Poller\Pollers\CountersPoller;

class CountersConsole extends LastDataAbstract
{

    protected $pollerName = CountersPoller::class;

    protected function configure()
    {
        $this->setName("poller:last:counters")
            ->setDescription("Get last counters stat");

        parent::configure();
    }

    function exec(InputInterface $input, OutputInterface $output, $data = [])
    {
        $output->writeln("Try request counters data");
        $table = new Table($output);
        return self::SUCCESS;
    }


}