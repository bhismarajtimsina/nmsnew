<?php


namespace WCAA\Console\Schedule;

use Symfony\Component\Console\Helper\Table;
use WCAA\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Storage\System\ScheduleStorage;

class ScheduleList extends AbstractCommand
{
    /**
     * @Inject
     * @var ScheduleStorage
     */
    protected $generator;


    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("schedule:list")
            ->setDescription("List of crontab files");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $table = new Table($output);
        $output->writeln("<comment>Table of crontabs</comment>");
        $table->setHeaders([
           'ID',
           'State',
           'Component',
           'Period',
           'Command',
        ]);
        foreach ($this->generator->fetchAll(false) as $crontab) {
            $table->addRow([
                $crontab->getId(),
                $crontab->getState(),
                $crontab->getComponent() === null ? 'NULL' : $crontab->getComponent()->getKey(),
                $crontab->getCrontab(),
                $crontab->getCommand(),
            ]);
        }
        $table->render();
        return self::SUCCESS;
    }

}