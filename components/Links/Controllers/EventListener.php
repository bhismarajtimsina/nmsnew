<?php

namespace WCC\Links\Controllers;


use DI\Container;
use Monolog\Logger;
use WCAA\App;
use WCAA\Infrastructure\Events\Observer;
use WCC\Events\Controllers\EventProcessors\AlertmanagerEventProcessor;
use WCC\Events\Models\AlertmanagerRule;
use WCC\Events\Storage\AlertmanagerRulesStorage;

class EventListener extends Observer
{

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var Container
     */
    protected $container;

    function __construct(Logger $logger, Container $container)
    {
        $this->logger = $logger->withName("links-listener");
        $this->container = $container;
    }


    function notify(\SplSubject $subject, $event, $data = null)
    {
        $alertmanager = $this->container->get(\WCC\Events\Controllers\Controller::class)->getAlertManager();

        $this->logger->info("Received event $event into links, start to update alertmanager configuration");
        if (isset($data['LINKS_UTILIZATION_MAX_PRC_FOR_ALERT']) && isset($data['LINKS_UTILIZATION_CALCULATE_PERIOD'])) {
            $rule = (new AlertmanagerRule())
                ->setFor("15m")
                ->setGroupName("links")
                ->setAlertName("high_link_utilization")
                ->setSeverity("warning")
                ->setInternal(true)
                ->setEnabled(true)
                ->setExpression("max_over_time(link_utilization_prc[{$data['LINKS_UTILIZATION_CALCULATE_PERIOD']}]) > {$data['LINKS_UTILIZATION_MAX_PRC_FOR_ALERT']}")
                ->setAnnotationDescription('Link {{ $labels.src_device_ip }}->{{ $labels.dest_device_ip }} utilization is too high in one direction - {{ humanize $value }}% ')
                ->setAnnotationSummary("High link utilization")
            ;
            $alertmanager->addOrUpdateInternalAlertRule($rule);
        } else {
            $this->logger->error("Not found fields LINKS_UTILIZATION_CALCULATE_PERIOD and LINKS_UTILIZATION_MAX_PRC_FOR_ALERT");
        }
        $alertmanager->applyStoredRules();
    }
    function getEventType()
    {
        return "system:env-parameters-updated";
    }
}