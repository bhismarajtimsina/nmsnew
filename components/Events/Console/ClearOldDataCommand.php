<?php

namespace WCC\Events\Console;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCC\Events\Controllers\Alertmanager;
use WCC\Events\Storage\EventsStorage;

class ClearOldDataCommand extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventsStorage;

    function config()
    {
        $this->setName("retention")
            ->addArgument("days", InputArgument::REQUIRED, "Days to remove old events")
            ->setDescription("Remove old resolved events");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
       while(true) {
           $events = $this->eventsStorage->getForRetentionByDays($input->getArgument('days'), 5000);
           if(count($events) === 0) {
               break;
           }
           $output->writeln("Found " . count($events) . " events for retention by " . $input->getArgument('days'));
           $output->writeln("Start clearing...");
           foreach ($events as $event) {
               $output->writeln("Clear event {$event->getName()} with id {$event->getId()}");
               $this->eventsStorage->delete($event);
           }
       }
       $output->writeln("Success finish clearing!");
       return self::SUCCESS;
    }
}