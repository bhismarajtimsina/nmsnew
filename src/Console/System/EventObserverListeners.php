<?php


namespace WCAA\Console\System;


use DI\Annotation\Inject;
use Symfony\Component\Console\Helper\Table;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EventObserverListeners extends AbstractCommand
{
    /**
     * @Inject
     * @var App
     */
    protected $app;

    protected function configure()
    {
        $this->setName("system:events:listeners")
            ->setDescription("Return list of active event listeners")
        ;

    }
    function execute(InputInterface $input, OutputInterface $output)
    {
        $table = new Table($output);
        $table->setHeaders([
            'Class','Match events',
        ]);
        foreach ($this->app->conf('event_listeners') as $listener) {
            $obj = $this->app->getContainer()->get($listener);
            $table->addRow([
               $listener ,
               $obj->getEventType(),
            ]);
        }
        $table->render();
        return self::SUCCESS;
    }
}