<?php


namespace WCAA\Console\Supervisor;


use DI\Annotation\Inject;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\Supervisor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RestartSupervisor extends AbstractCommand
{
    /**
     * @Inject
     * @var Supervisor
     */
    protected $supervisor;

    /**
     * @return void
     */

    protected function configure()
    {
        $this->setName("supervisor:restart")
            ->setDescription("Restart supervisor");

    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $this->supervisor->restart();
        return self::SUCCESS;
    }
}