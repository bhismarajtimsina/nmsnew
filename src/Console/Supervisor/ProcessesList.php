<?php


namespace WCAA\Console\Supervisor;


use DI\Annotation\Inject;
use Symfony\Component\Console\Helper\Table;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\Supervisor;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessesList extends AbstractCommand
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
        $this->setName("supervisor:processes-list")
            ->setDescription("Return list of processes");

    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $processes = $this->supervisor->getProcessesInfo();
        $table = new Table($output);
        $table->setHeaders([
            'PID',
            'Process name',
            'Start At',
            'Stop At',
            'Exit status',
            'Description',
            'State name',
        ]);
        foreach ($processes as $process) {
            $table->addRow([
                $process['pid'],
                $process['name'],
                (new \DateTime())->setTimestamp((int)$process['start'])->format("Y-m-d H:i:s"),
                $process['stop'] == 0 ? '' : (new \DateTime())->setTimestamp((int)$process['stop'])->format("Y-m-d H:i:s"),
                $process['exitstatus'],
                $process['description'],
                $process['statename'],
            ]);
        }
        $table->render();
        return self::SUCCESS;
    }
}