<?php


namespace WCAA\Console\System;


use DI\Annotation\Inject;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RecalcActiveSessions extends AbstractCommand
{
    /**
     * @Inject
     * @var UserAuthKeyStorage
     */
    protected $app;

    protected function configure()
    {
        $this->setName("system:recalc-sessions")
            ->setDescription("Recalculate active user sessions")
        ;

    }
    function execute(InputInterface $input, OutputInterface $output)
    {
        $count = $this->app->recalcAllSessionStatus();
        $this->output->writeln("Recalculated. Count affected keys = {$count}");
        return self::SUCCESS;
    }
}