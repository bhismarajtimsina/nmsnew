<?php


namespace WCAA\Console\Supervisor;


use DI\Annotation\Inject;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\Supervisor;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessControl extends AbstractCommand
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
        $this->setName("supervisor:control")
            ->addArgument('process-name', InputArgument::REQUIRED, "Name of process")
            ->addArgument('action', InputArgument::REQUIRED, "Action, variants - restart|stop|start")
            ->setDescription("Process control");

    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $processes = $this->supervisor->getProcesses();
        $process = null;
        foreach ($processes as $proc) {
            if($proc->getName() == $input->getArgument('process-name')) {
                $process = $proc;
                break;
            }
        }
        if(!$process) {
            $this->output->writeln("<error>Process with name {$input->getArgument('process-name')} not found</error>");
            return  self::INVALID;
        }
        switch ($input->getArgument('action')) {
            case 'restart': $this->supervisor->restartProcess($process); break;
            case 'stop': $this->supervisor->stopProcess($process); break;
            case 'start': $this->supervisor->startProcess($process); break;
            default:
                $this->output->writeln("<error>Action {$input->getArgument('action')} not supported</error>");
                return  self::INVALID;
        }
        return self::SUCCESS;
    }
}