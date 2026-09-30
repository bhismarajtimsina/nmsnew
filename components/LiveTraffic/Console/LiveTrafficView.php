<?php

namespace WCC\LiveTraffic\Console;

use Khill\Duration\Duration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Events\Controllers\Alertmanager;
use WCC\LiveTraffic\Controllers\Controller;

class LiveTrafficView extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    function config()
    {
        $this->setName("view")
            ->addArgument('device', InputArgument::REQUIRED, 'Device IP address')
            ->addArgument('interface', InputArgument::REQUIRED, "Interface name")
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Interval to get traffic', '5s')
            ->setDescription("View realtime traffic on interface");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
       $duration = new Duration($input->getOption('interval'));
       if($duration->seconds < 2) {
           throw new \Exception("Min interval - 2s");
       }
       $device = $this->deviceStorage->getByIp($input->getArgument('device'));
       $interfaces = array_filter($this->deviceInterfaceStorage->getByDevice($device, null, false), function ($iface) use ($input) {
           return
               strtolower($input->getArgument('interface')) == strtolower($iface->getName()) ||
               strtolower($input->getArgument('interface')) == strtolower($iface->getBindKey());
       });
       if(count($interfaces) > 1) {
           throw new \Exception("Found more one interface. Please, set correct and fully name");
       }
       if(count($interfaces) == 0) {
           throw new \Exception("Interface not found. Please, set correct interface name");
       }
       $interface = array_values($interfaces)[0];
       $output->writeln("----------------------------------------------------------------------");
       $output->writeln("<comment>Device: {$device->getName()} ({$device->getIp()})</comment>");
       $output->writeln("<comment>Iface:  {$interface->getName()}</comment>");
       $output->writeln("----------------------------------------------------------------------");
       $output->writeln("Traffic");
       $startAt = time();
       while (true) {
           $trafficStat = $this->controller->getTraffic($interface);
           $currentInterval = time() - $startAt;
           $inMbps = round($trafficStat['rate']['in_bps'] / 1024 / 1024, 2);
           $outMbps = round($trafficStat['rate']['out_bps'] / 1024 / 1024, 2);
           $output->writeln(
               sprintf("%-20s (%-5s) <info>%10sMbps %10sMbps</info>", date("Y-m-d H:i:s"), $currentInterval, $inMbps, $outMbps)
           );
           sleep($duration->seconds - 1);
       }
    }

}