<?php


namespace WCAA\Console\Schedule;

use WCAA\Console\AbstractCommand;
use WCAA\Models\System\Schedule;
use WCAA\Models\System\ScheduleReport;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Storage\System\ScheduleReportsStorage;
use WCAA\Storage\System\ScheduleStorage;

class ScheduleExecutor extends AbstractCommand
{
    /**
     * @Inject
     * @var ScheduleStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var ScheduleReportsStorage
     */
    protected $scheduleReportsStorage;

    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("schedule:executor")
            ->setDescription("Run schedule jobs in system. Must run every minute in cron");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Init jobs");
        $jobs = $this->generateJobList();
        $resolver = (new \Cron\Resolver\ArrayResolver());
        $resolver->addJobs($jobs);

        $cron = new \Cron\Cron();
        $cron->setExecutor(new \Cron\Executor\Executor());
        $cron->setResolver($resolver);
        $reports = $cron->run();
        $output->writeln("Run jobs");
        while (true) {
            if($cron->isRunning()) {
                $output->write(".");
                sleep(1);
            } else {
                break;
            }
        }
        $output->writeln("Working with jobs finished");
        foreach ($jobs as $res) {
            /**
             * @var $crontab Schedule
             */
            $crontab = $res->crontab;
            if($report = $res->getReport()) {
                $output->writeln("commandId={$crontab->getId()}, command={$crontab->getCommand()}, start={$report->getStartTime()}, end={$report->getEndTime()}");
                $this->scheduleReportsStorage->add(
                    (new ScheduleReport())
                        ->setStartAt(date("Y-m-d H:i:s", round($report->getStartTime())))
                        ->setStopAt(date("Y-m-d H:i:s", round($report->getEndTime())))
                        ->setIsSuccessful($report->isSuccessful())
                        ->setSchedule($crontab)
                        ->setError(join("\n", $report->getError()))
                        ->setOutput(join("\n", $report->getOutput()))
                );
                $this->storage->update($crontab->setLatest(date("Y-m-d H:i:s", round($report->getStartTime()))));
            }
        }
        return self::SUCCESS;
    }

    /**
     * @return \Cron\Job\ShellJob[]
     * @throws \Exception
     */
    function generateJobList() {
        $jobs = [];
        foreach ($this->storage->fetchCycle() as $crontab) {
            try {
                $job = new \Cron\Job\ShellJob();
                $job->setSchedule(new \Cron\Schedule\CrontabSchedule(trim($crontab->getCrontab())));
                $job->setCommand("{$crontab->getCommand()}", null, null, null, 3600);
                $job->crontab = $crontab;
                $jobs[] = $job;
            } catch (\Exception $e) {
                $this->output->writeln("<error>Failed initialize job with name {$crontab->getKey()}, crontab '{$crontab->getCrontab()}': {$e->getMessage()}</error>");
            }
        }
        return $jobs;
    }

}
