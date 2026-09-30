<?php


namespace WCAA\Console\System;


use DI\Annotation\Inject;
use Prometheus\CollectorRegistry;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Console\Components\ComponentsControl;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\Devices\DeviceInterfaceTag;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceInterfaceTagStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\System\ScheduleReportsStorage;
use WCAA\Storage\System\ScheduleStorage;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Storage\UserStorage;

class PrometheusMetricsExporter extends AbstractCommand
{
    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @Inject
     * @var CollectorRegistry
     */
    protected $promRegistry;

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $prometheusMetrics;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

    /**
     * @Inject
     * @var ComponentInjector
     */
    protected $components;

    /**
     * @Inject
     * @var ScheduleReportsStorage
     */
    protected $scheduleReportStorage;


    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceTagStorage
     */
    protected $deviceInterfacesTags;


    /**
     * @Inject
     * @var ScheduleStorage
     */
    protected $schedules;

    protected function configure()
    {
        $this->setName("system:prom-metrics-exporter")
            ->setDescription("Prometheus metrics exporter");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Start write sys metrics");

        $output->writeln("Write components metrics");
        $this->writeComponents();

        $output->writeln("Write schedules info");
        $this->writeSchedules();

        $output->writeln("Write device interface tags and starred");
        $this->deviceInterfaceTags();

        return self::SUCCESS;
    }


    function writeComponents() {
        $registry = $this->promRegistry->getOrRegisterGauge('sys', 'component', 'Components list with state', [
            'key',
        ]);
        foreach ($this->components->fetchComponents() as $component) {
            $registry->set($component['enabled'] ? 1 : 0, [
                'key' => $component['name'],
            ]);
        }
    }
    function writeSchedules() {
        $registry = $this->promRegistry->getOrRegisterGauge('sys', 'schedule', 'Schedules list with state', [
            'key',
            'cron',
            'command',
        ]);
        foreach ($this->schedules->fetchAll() as $schedule) {
            $registry->set($schedule->getState() === 'ENABLED' ? 1 : 0, [
                'key' => $schedule->getKey(),
                'cron' => $schedule->getCrontab(),
                'command' => $schedule->getCommand(),
            ]);
        }
    }

    function deviceInterfaceTags()
    {
        foreach ($this->deviceInterfacesTags->getTagsByInterfaces() as $tg) {
            $tags = join(",", $tg['tags']);
            /**
             * @var $iface DeviceInterface
             */
            $iface = $tg['interface'];
            $this->prometheusMetrics->setGauge("storage_iface_tags",1, [
                'tags' => $tags,
                'dev_id' => $iface->getDevice()->getId(),
                'ip' => $iface->getDevice()->getIp(),
                'iface_type' => $iface->getType(),
                'iface_id' => $iface->getBindKey(),
                'iface_name' => $iface->getName(),
            ], "Interface tags", 180);
        }
        foreach ($this->deviceInterfacesTags->getFavoriteInterfaces() as $iface) {
            $this->prometheusMetrics->setGauge("storage_iface_favorite",1, [
                'dev_id' => $iface->getDevice()->getId(),
                'ip' => $iface->getDevice()->getIp(),
                'iface_type' => $iface->getType(),
                'iface_id' => $iface->getBindKey(),
                'iface_name' => $iface->getName(),
            ], "Favorite interfaces", 180);
        }
    }
}
