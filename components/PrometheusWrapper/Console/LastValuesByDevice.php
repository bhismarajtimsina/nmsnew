<?php

namespace WCC\PrometheusWrapper\Console;




use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Pinger\Controllers\Controller;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;

class LastValuesByDevice extends AbstractComponentCommand
{
    /**
     * @Inject
     * @var PrometheusMetricsTempStore
     */
    protected $metrics;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    function config()
    {
       $this->setName('last-values')
           ->addArgument('device-ip', InputArgument::REQUIRED, "Device IP", )
           ->addArgument("metric-names", InputArgument::REQUIRED, "Metric names, splitted by comma")
           ->setDescription("Get last metrics values by device");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $device = $this->deviceStorage->getByIp($input->getArgument('device-ip'));
        $metrics = explode(",", $input->getArgument('metric-names'));
        $data = $this->metrics->getLastMetricsByDevice($device, $metrics);
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK), );
        return self::SUCCESS;
    }



}
