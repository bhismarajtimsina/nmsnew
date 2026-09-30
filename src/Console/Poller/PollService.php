<?php


namespace WCAA\Console\Poller;

use Cocur\BackgroundProcess\BackgroundProcess;
use Hoa\Stream\IStream\Out;
use Monolog\Logger;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\Poller\PollerProcessor;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Pollers\PollerProcessing;
use WCAA\Storage\PollerData\PollerProcessingStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Pinger\Controllers\Controller;

class PollService extends AbstractCommand
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var PollerProcessingStorage
     */
    protected $pollerProcessingStorage;


    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $prom;

    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @Inject
     * @var Logger
     */
    protected $logger;

    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("poller:poll-service")
            ->addOption("disable-checks", 'd', InputOption::VALUE_NEGATABLE, "Disable any checks (as pinger, as last check interval)", false)
            ->addOption("run-once", 'once', InputOption::VALUE_NEGATABLE, "Run once", false)
            ->setDescription("Service with allow starting polling service. Must run in cicles");
    }

    function clearOldPollers(OutputInterface $output) {
        $activePollers = $this->pollerProcessingStorage->getInProcess();
        foreach ($activePollers as $poller) {
            $output->writeln("Close old poller processing after restart poll service: ". json_encode($poller->getAsArrayLite()));
            $poller->setStopAt(date("Y-m-d H:i:s"))
                ->setError(new \Exception("Close old after restarting poll service"))
                ->setStatus(PollerProcessing::STATUS_FAILED);
            $this->pollerProcessingStorage->update($poller);
        }
    }

    function execute(InputInterface $input, OutputInterface $output)
    {

        $this->output->writeln("<comment>Start poller</comment>");
        $this->output->writeln("Count of proc processes: " . App::getInstance()->conf('poller.proc_concurrency'));
        $cicles = 0;

        $this->clearOldPollers($output);

        $processes = [];
        do {
            $this->output->writeln("Start cicle=$cicles");
            $devices = $this->storage->flushIds()->fetchAll();
            $output->writeln("Found devices in storage: " . count($devices));
            if (!$input->getOption('disable-checks')) {
                $devices = array_filter($devices, function ($device) use ($output){
                    if(!$device->isEnabled()) $output->writeln("Device {$device->getIp()} disabled, ignoring...");
                    return $device->isEnabled();
                });
                $devices = $this->filterByCollectInterval($devices);
            }
            $this->output->writeln("Count device for polling: " . count($devices));
            $this->prom->setGauge("system_poller_devices_queue", count($devices));
             foreach ($devices as $device) {
                if (!$input->getOption('disable-checks') && !$this->isPollAllowedByPinger($device)) {
                    $output->writeln("<comment>Polling from device {$device->getIp()} is denied, device status is down over ICMP</comment>");
                    continue;
                }
                 if(isset($processes[$device->getId()])) {
                     $output->writeln("<comment>Poller for {$device->getIp()} was started early</comment>");
                 } else {
                     $proccess = new BackgroundProcess("wca poller:poll {$device->getId()}");
                     $proccess->run();
                     $output->writeln("<info>Start proc proccess for {$device->getName()} ({$device->getIp()}) with pid={$proccess->getPid()}</info>");
                     $processes[$device->getId()] = [
                         'proc' => $proccess,
                         'device' => $device,
                     ];
                 }
                CHECK_STATE:
                $countInWork = 0;
                foreach ($processes as $key => $proc) {
                    if ($proc['proc']->isRunning()) {
                        $countInWork++;
                    } else {
                        $output->writeln("<info>Device {$proc['device']->getName()} ({$proc['device']->getIp()}) finished work!</info>");
                        unset($processes[$key]);
                    }
                }
                $this->prom->setGauge("system_poller_procs_busy_count", $countInWork);
                $this->prom->setGauge("system_poller_procs_all_count", App::getInstance()->conf('poller.proc_concurrency'));
                if (App::getInstance()->conf('poller.proc_concurrency') <= $countInWork) {
                    sleep(1);
                    if ($output->isVerbose()) {
                        $output->writeln("Max worker reached! Wait before check. Count devices in work={$countInWork}");
                    }
                    goto CHECK_STATE;
                }
            }
             $cicles++;
             sleep(3);
        } while (!$input->getOption('run-once'));
        return self::SUCCESS;
    }


    protected function isPollAllowedByPinger(Device $device)
    {
        if (!$this->app->conf('poller.ignore_down_devices')) {
            return true;
        }
        if ($this->app->getComponentInjector()->isComponentEnabled('pinger')
        ) {
            /**
             * @var Controller $controller
             */
            $controller = $this->app->getComponentInjector()->getController('pinger');
            if ($status = $controller->getDeviceStatus($device)) {
                return $status->isUp();
            } else {
                return false;
            }
        } else {
            return true;
        }
    }

    protected function filterByCollectInterval($devices = [])
    {
        $devices = array_filter($devices, function (Device $device) {
            if (isset($device->getParams()['poller_config']) && $device->getParams()['poller_config']) {
                $config = $device->getParams()['poller_config'];
            } else if (isset($device->getModel()->getParams()['poller_config']) && $device->getModel()->getParams()['poller_config']) {
                $config = $device->getModel()->getParams()['poller_config'];
            } else if ($pollers = $device->getModel()->getPollers()) {
                $config = [];
                foreach ($pollers as $poller => $interval) {
                    $config[$poller] = [
                        'enabled' => true,
                        'interval' => $interval,
                    ];
                }
            } else {
                $this->output->writeln("<error>Polling for model {$device->getModel()->getName()} not supported</error>");
                return false;
            }

            if(count(array_filter($config, function ($c) {
                return $c['enabled'];
            })) == 0) {
                $this->output->writeln("<comment>NotificationSender for device {$device->getIp()} or model ({$device->getModel()->getName()}) disabled!</comment>");
                return false;
            }

            foreach ($this->pollerProcessingStorage->getLatests($device) as $last) {
                $time = \DateTime::createFromFormat("Y-m-d H:i:s", $last->getStartAt())->getTimestamp();
                if(!isset($config[$last->getPoller()])) {
                    $this->output->writeln("<error>Poller with name {$last->getPoller()} not found in supported pollers for device {$device->getIp()}</error>");
                    continue;
                }
                $interval = $config[$last->getPoller()]['interval'];
                if($interval > (time() - $time)) {
                    unset($config[$last->getPoller()]);
                }
            }
            if(count(array_filter($config, function ($c) {
                    return $c['enabled'];
                })) == 0) {
                return false;
            }
            return true;
        });
        return array_values($devices);
    }
}
