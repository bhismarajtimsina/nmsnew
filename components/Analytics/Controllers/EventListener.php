<?php

namespace WCC\Analytics\Controllers;


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
        $this->logger = $logger->withName("analytics-listener");
        $this->container = $container;
    }


    function notify(\SplSubject $subject, $event, $data = null)
    {
        $alertmanager = $this->container->get(\WCC\Events\Controllers\Controller::class)->getAlertManager();

        $this->logger->info("Received event $event, start to update alertmanager configuration");
        if (isset($data['ANALYTICS_MIN_RX_SIGNAL']) && isset($data['ANALYTICS_MAX_RX_SIGNAL'])) {
            $rule = (new AlertmanagerRule())
                ->setFor("15m")
                ->setGroupName("analytics")
                ->setAlertName("bad_optical_level_rx")
                ->setSeverity("warning")
                ->setInternal(true)
                ->setEnabled(true)
                ->setExpression("optical_rx{iface_type=\"ONU\"} > {$data['ANALYTICS_MAX_RX_SIGNAL']} or optical_rx{iface_type=\"ONU\"} < {$data['ANALYTICS_MIN_RX_SIGNAL']}")
                ->setAnnotationDescription('The RX signal level on ONU {{ $labels.iface_name }}, OLT {{ $labels.ip }} has exceeded the limit - {{ humanize $value }}dBm')
                ->setAnnotationSummary("Signal Level Issues")
            ;
            $alertmanager->addOrUpdateInternalAlertRule($rule);
        } else {
            $this->logger->error("Not found fields ANALYTICS_MIN_RX_SIGNAL and ANALYTICS_MAX_RX_SIGNAL");
        }
        if (isset($data['ANALYTICS_MIN_OLT_RX_SIGNAL']) && isset($data['ANALYTICS_MAX_OLT_RX_SIGNAL'])) {
            $rule = (new AlertmanagerRule())
                ->setFor("15m")
                ->setGroupName("analytics")
                ->setAlertName("bad_optical_level_olt_rx")
                ->setSeverity("warning")
                ->setInternal(true)
                ->setEnabled(true)
                ->setExpression("optical_olt_rx{iface_type=\"ONU\"} > {$data['ANALYTICS_MAX_OLT_RX_SIGNAL']} or optical_olt_rx{iface_type=\"ONU\"} < {$data['ANALYTICS_MIN_OLT_RX_SIGNAL']}")
                ->setAnnotationDescription('The OLT RX signal level on OLT for ONU {{ $labels.iface_name }}, OLT {{ $labels.ip }} has exceeded the limit - {{ humanize $value }}dBm')
                ->setAnnotationSummary("Signal Level Issues")
            ;
            $alertmanager->addOrUpdateInternalAlertRule($rule);
        } else {
            $this->logger->error("Not found fields ANALYTICS_MIN_OLT_RX_SIGNAL and ANALYTICS_MAX_OLT_RX_SIGNAL");
        }
        if (isset($data['ANALYTICS_MIN_SFP_RX_SIGNAL']) && isset($data['ANALYTICS_MAX_SFP_RX_SIGNAL'])) {
            $rule = (new AlertmanagerRule())
                ->setFor("5m")
                ->setGroupName("analytics")
                ->setAlertName("bad_optical_sfp_rx")
                ->setSeverity("warning")
                ->setInternal(true)
                ->setEnabled(true)
                ->setExpression("optical_rx{iface_type != \"ONU\"} > {$data['ANALYTICS_MAX_SFP_RX_SIGNAL']} or optical_rx{iface_type != \"ONU\"} < {$data['ANALYTICS_MIN_SFP_RX_SIGNAL']}")
                ->setAnnotationDescription('The RX signal level on SFP {{ $labels.iface_name }}, IP {{ $labels.ip }} has exceeded the limit - {{ humanize $value }}dBm')
                ->setAnnotationSummary("Bad SFP signal")
            ;
            $alertmanager->addOrUpdateInternalAlertRule($rule);
        } else {
            $this->logger->error("Not found fields ANALYTICS_MIN_SFP_RX_SIGNAL and ANALYTICS_MAX_SFP_RX_SIGNAL");
        }
        $alertmanager->applyStoredRules();
    }


    function getEventType()
    {
        return "system:env-parameters-updated";
    }


}