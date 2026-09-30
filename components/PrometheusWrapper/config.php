<?php

use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return [
    //Key of module. key must be uniq for components
    'name' => 'prometheus_wrapper',
    'installer' => \WCC\PrometheusWrapper\Installer::class,
    // List of rules
    'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),

    //For work with another components in system you can create class with public method.
    //For access to object of module controller you must use ModuleInjector::getController(<name of module>)
    'controller' => \WCC\PrometheusWrapper\Controllers\Controller::class,

    //List of processors
    //You can add methods for listen events.
    //For create event listener object you must implement ModuleEventListenerInterface.
    //Every event return object \WCAA\Models\Event
    'events' => [],

    'routes' => [
        ['methods' => ['POST'], 'pattern'=>'/chart-optical-signals-series', 'callable'=>\WCC\PrometheusWrapper\Api\OpticalSignalStrengthSeriesAction::class],
        ['methods' => ['POST'], 'pattern'=>'/chart-optical-temp-series', 'callable'=>\WCC\PrometheusWrapper\Api\OpticalTempSeriesAction::class],
        ['methods' => ['POST'], 'pattern'=>'/chart-optical-voltage-series', 'callable'=>\WCC\PrometheusWrapper\Api\OpticalVoltageSeriesAction::class],
        ['methods' => ['POST'], 'pattern'=>'/chart-traffic-counter-series', 'callable'=>\WCC\PrometheusWrapper\Api\TrafficCounterSeriesAction::class],
        ['methods' => ['POST'], 'pattern'=>'/chart-errors-counter-series', 'callable'=>\WCC\PrometheusWrapper\Api\ErrorCounterSeries::class],
        ['methods' => ['POST'], 'pattern'=>'/chart-cpu-load-series', 'callable'=>\WCC\PrometheusWrapper\Api\CpuSeriesAction::class],
        ['methods' => ['POST'], 'pattern'=>'/chart-memory-load-series', 'callable'=>\WCC\PrometheusWrapper\Api\RamSeriesAction::class],
        ['methods' => ['POST'], 'pattern'=>'/chart-temperature-series', 'callable'=>\WCC\PrometheusWrapper\Api\SystemTempSeriesAction::class],
        ['methods' => ['POST'], 'pattern'=>'/chart-disk-load-series', 'callable'=>\WCC\PrometheusWrapper\Api\DiskSeriesAction::class],
    ],

    //List of console commands
    //For create some console command you must create classes extended from WCAA\Modules\AbstractComponentCommand
    //For work with console used symfony console (https://symfony.com/doc/current/components/console.html)
    'console' => [
        \WCC\PrometheusWrapper\Console\LastValuesByDevice::class,
    ],

    'env_params' => [],
    'description' => 'Prometheus integration(Working with Prometheus API)',
];
