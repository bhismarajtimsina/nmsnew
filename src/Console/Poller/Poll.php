<?php


namespace WCAA\Console\Poller;

use Cocur\BackgroundProcess\BackgroundProcess;
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
use WCAA\Storage\PollerData\PollerProcessingStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Pinger\Controllers\Controller;

class Poll extends AbstractCommand
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @var array
     */
    protected function configure()
    {

        $this->setName("poller:poll")
            ->addArgument("device-id", InputArgument::REQUIRED, "Set device ID for work")
            ->addOption("poller-name", 'p', InputOption::VALUE_OPTIONAL, "Set poller name", '')
            ->addOption("disable-checks", 'd', InputOption::VALUE_NEGATABLE, "Disable any checks (as pinger, as last check interval)", false)
            ->setDescription("Execute scheduled poller");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $device = $this->storage->getById($input->getArgument('device-id'));
        if(!$input->getOption('disable-checks') && !$this->isPollAllowedByPinger($device)) {
            $output->writeln("Device {$device->getIp()} is DOWN, polling disabled");
            return self::SUCCESS;
        }
        $output->writeln("Init poller for device {$device->getIp()}");
        $poll = App::getInstance()->getContainer()->get(PollerProcessor::class)
            ->setUser(App::getInstance()->getConsoleUser())
            ->setDevice($device)
            ->setConsoleOutput($output)
            ->setLogger(App::getInstance()->getContainer()->get(Logger::class));
        if($input->getOption('poller-name')) {
            $poll->poll([$input->getOption('poller-name')], $input->getOption('disable-checks'));
        } else {
            $poll->poll([], $input->getOption('disable-checks'));
        }
        $output->writeln("Finished work with {$device->getIp()}");
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
}
