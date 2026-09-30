<?php

namespace WCC\Pinger\Console;




use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCC\Pinger\Controllers\Controller;

class UpdateExporterStatuses extends AbstractComponentCommand
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    function config()
    {
       $this->setName('update-exporter-statuses')
           ->setDescription("Update exporter statuses");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Start update metrics...");
        $this->controller->recalcHostStatusMetrics();
        $output->writeln("Success updated!");
        return self::SUCCESS;
    }



}
