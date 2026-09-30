<?php


namespace WCC\PrometheusWrapper\Controllers;


use Meklis\PromClient\Client;
use Monolog\Logger;
use SwitcherCore\Modules\Helper;
use WCAA\App;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * Class Controller
 * @package WCC\PrometheusWrapper
 */
class Controller extends AbstractComponentController
{


    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $interfaceStorage;

    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @var Client
     */
    protected $promClient;

    protected $defaultTimeOffset = null;

    function __construct(App $app, ComponentInjector $componentInjector, Logger $logger)
    {
        parent::__construct($componentInjector, $logger);
        $this->defaultTimeOffset = 60 * 60 * 24;
        $this->promClient = new Client($app->conf('prometheus.url'));
    }

    function trafficCounterSeries($deviceId, $interfaceId, $start = null, $stop = null, $step = '30m')
    {
        return $this->seriesRequest([
            ['labels' => ['name' => 'iface_stat_out_octets'], 'query' => sprintf('rate(iface_stat_out_octets{dev_id="%d", iface_id="%d"}[%s]) * 8.388608', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_in_octets'], 'query' => sprintf('rate(iface_stat_in_octets{dev_id="%d", iface_id="%d"}[%s])  * 8.388608', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_out_multicast_pkts'], 'query' => sprintf('rate(iface_stat_out_multicast_pkts{dev_id="%d", iface_id="%d"}[%s])', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_in_multicast_pkts'], 'query' => sprintf('rate(iface_stat_in_multicast_pkts{dev_id="%d", iface_id="%d"}[%s])', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_out_broadcast_pkts'], 'query' => sprintf('rate(iface_stat_out_broadcast_pkts{dev_id="%d", iface_id="%d"}[%s])', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_in_broadcast_pkts'], 'query' => sprintf('rate(iface_stat_in_broadcast_pkts{dev_id="%d", iface_id="%d"}[%s])', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_in_drop_pkts'], 'query' => sprintf('rate(iface_stat_in_drop_pkts{dev_id="%d", iface_id="%d"}[%s]) * 8.388608', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_out_drop_pkts'], 'query' => sprintf('rate(iface_stat_out_drop_pkts{dev_id="%d", iface_id="%d"}[%s]) * 8.388608', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_in_bytes'], 'query' => sprintf('rate(iface_stat_in_bytes{dev_id="%d", iface_id="%d"}[%s]) * 8', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_out_bytes'], 'query' => sprintf('rate(iface_stat_out_bytes{dev_id="%d", iface_id="%d"}[%s]) * 8', $deviceId, $interfaceId, $step)],
        ], $start, $stop, $step);
    }

    function errorsCounterSeries($deviceId, $interfaceId, $start = null, $stop = null, $step = '30m')
    {
        return $this->seriesRequest([
            ['labels' => ['name' => 'iface_stat_in_errors'], 'query' => sprintf('rate(iface_stat_in_errors{dev_id="%d", iface_id="%d"}[%s])', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_out_errors'], 'query' => sprintf('rate(iface_stat_out_errors{dev_id="%d", iface_id="%d"}[%s])', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_in_discards'], 'query' => sprintf('rate(iface_stat_in_discards{dev_id="%d", iface_id="%d"}[%s])', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_out_discards'], 'query' => sprintf('rate(iface_stat_out_discards{dev_id="%d", iface_id="%d"}[%s])', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_in_crc_errors'], 'query' => sprintf('rate(iface_stat_in_crc_errors{dev_id="%d", iface_id="%d"}[%s])', $deviceId, $interfaceId, $step)],
            ['labels' => ['name' => 'iface_stat_out_crc_errors'], 'query' => sprintf('rate(iface_stat_out_crc_errors{dev_id="%d", iface_id="%d"}[%s])', $deviceId, $interfaceId, $step)],
          ], $start, $stop, $step);
    }


    function opticalSeries($deviceId, $interfaceId, $start = null, $stop = null, $step = '10m')
    {
        return $this->seriesRequest([
            sprintf('optical_rx{iface_id="%d", dev_id="%d"}', $interfaceId, $deviceId),
            sprintf('optical_tx{iface_id="%d", dev_id="%d"}', $interfaceId, $deviceId),
            sprintf('optical_olt_tx{iface_id="%d", dev_id="%d"}', $interfaceId, $deviceId),
            sprintf('optical_olt_rx{iface_id="%d", dev_id="%d"}', $interfaceId, $deviceId),
        ], $start, $stop, $step);
    }

    function ontStatusesSeries($deviceId = null, $start = null, $stop = null, $step = '10m')
    {
        if ($deviceId) {
            return $this->seriesRequest([
                ['labels' => ['name' => 'online'], 'query' => sprintf('sum (device_interface_status{iface_type="ONU", dev_id="%d"})', $deviceId)],
                ['labels' => ['name' => 'all'], 'query' => sprintf('count (device_interface_status{iface_type="ONU", dev_id="%d"})', $deviceId)],
            ], $start, $stop, $step);
        } else {
            return $this->seriesRequest([
                ['labels' => ['name' => 'online'], 'query' => sprintf('sum (device_interface_status{iface_type="ONU"})')],
                ['labels' => ['name' => 'all'], 'query' => sprintf('count (device_interface_status{iface_type="ONU"})')],
            ], $start, $stop, $step);
        }
    }

    function interfaceStatusesSeries($deviceId = null, $start = null, $stop = null, $step = '10m')
    {
        if ($deviceId) {
            return $this->seriesRequest([
                ['labels' => ['name' => 'online'], 'query' => sprintf('sum (device_interface_status{iface_type !~"ONU|PON", dev_id="%d"})', $deviceId)],
                ['labels' => ['name' => 'all'], 'query' => sprintf('count (device_interface_status{iface_type !~"ONU|PON", dev_id="%d"})', $deviceId)],
            ], $start, $stop, $step);
        } else {
            return $this->seriesRequest([
                ['labels' => ['name' => 'online'], 'query' => sprintf('sum (device_interface_status{iface_type !~"ONU|PON"})')],
                ['labels' => ['name' => 'all'], 'query' => sprintf('count (device_interface_status{iface_type !~"ONU|PON"})')],
            ], $start, $stop, $step);
        }
    }

    function deviceStatusesSeries($start = null, $stop = null, $step = '10m')
    {
        return $this->seriesRequest([
            ['labels' => ['name' => 'online'], 'query' => sprintf('count(pinger_host_status > 0)')],
            ['labels' => ['name' => 'all'], 'query' => sprintf('count(pinger_host_status)')],
        ], $start, $stop, $step);
    }

    function opticalTemp($deviceId, $interfaceId, $start = null, $stop = null, $step = '30m')
    {
        return $this->seriesRequest([
            sprintf('optical_temperature{iface_id="%d", dev_id="%d"}', $interfaceId, $deviceId),
        ], $start, $stop, $step);
    }

    function cpuLoad($deviceId, $start = null, $stop = null, $step = '30m')
    {
        return $this->seriesRequest([
            sprintf('device_resources_cpu_util{dev_id="%d"}', $deviceId),
        ], $start, $stop, $step);
    }

    function graphByExpression($expression, $start = null, $stop = null, $step = '30m')
    {
        return $this->seriesRequest([
            $expression,
        ], $start, $stop, $step);
    }

    function memoryLoad($deviceId, $start = null, $stop = null, $step = '30m')
    {
        return $this->seriesRequest([
            sprintf('device_resources_memory_util{dev_id="%d"}', $deviceId),
        ], $start, $stop, $step);
    }

    function systemTemp($deviceId, $start = null, $stop = null, $step = '30m')
    {
        return $this->seriesRequest([
            sprintf('device_resources_temperature{dev_id="%d"}', $deviceId),
        ], $start, $stop, $step);
    }

    function diskLoad($deviceId, $start = null, $stop = null, $step = '30m')
    {
        return $this->seriesRequest([
            sprintf('device_resources_disk_util{dev_id="%d"}', $deviceId),
        ], $start, $stop, $step);
    }

    function opticalVoltage($deviceId, $interfaceId, $start = null, $stop = null, $step = '30m')
    {
        return $this->seriesRequest([
            sprintf('optical_voltage{iface_id="%d", dev_id="%d"}', $interfaceId, $deviceId),
        ], $start, $stop, $step);
    }

    function seriesRequest($queries, $start, $stop, $step)
    {
        if (!$start) {
            $start = time() - $this->defaultTimeOffset;
        }
        if (!$stop) {
            $stop = time();
        }
        $start = round($start / 60 / 60, 0) * 60 * 60;
        $response = $this->promClient->queriesRange($queries, $start, $stop, $step);
        return array_values(array_filter($response, function ($e) {
            return count($e) > 0;
        }));
    }

    function queries($queries, $time = null)
    {
        if (!$time) {
            $time = time();
        }
        $response = $this->promClient->queries($queries, $time);
        return array_values(array_filter($response, function ($e) {
            return count($e) > 0;
        }));
    }

    function convertToBarFormat($data, $datasetParameters = [])
    {
        if (!isset($datasetParameters['backgroundColor'])) {
            $datasetParameters['backgroundColor'] = 'rgba(5, 100, 0, 0.9)';
        }
        if (!isset($datasetParameters['label'])) {
            $datasetParameters['label'] = 'NoLabel';
        }
        $barData = [
            'labels' => [],
            'datasets' => [
                [
                    'label' => $datasetParameters['label'],
                    'backgroundColor' => [],
                    'data' => [],
                ]
            ],
        ];
        foreach ($data as $dt) {
            $barData['labels'][] = $dt['label'];
            $barData['datasets'][0]['backgroundColor'][] = isset($dt['color']) ? $dt['color'] : $datasetParameters['backgroundColor'];
            $barData['datasets'][0]['data'][] = $dt['value'];
        }
        return $barData;
    }

    function convertToChartFormat($responses, $datasetParameters = [])
    {
        $chartData = [
            'labels' => [],
            'datasets' => [],
        ];
        foreach ($responses as $respons) {
            foreach ($respons as $respon) {
                $metricName = '';
                if (isset($respon['metric']['__name__'])) $metricName = $respon['metric']['__name__'];
                if (isset($respon['request']['name'])) $metricName = $respon['request']['name'];
                $dataset = [
                    'label' => $this->_getLabelFromMetricName($metricName),
                    'borderColor' => $this->_getLabelColorFromMetricName($metricName),
                    'backgroundColor' => $this->_getLabelColorFromMetricName($metricName, '0.3'),
                    'data' => [],
                    'fill' => true,
                    '__metric__' => $metricName,
                ];
                if (isset($datasetParameters[$metricName])) {
                    $dataset = array_merge($dataset, $datasetParameters[$metricName]);
                }
                $datasetData = [];
                foreach ($respon['values'] as $value) {
                    $chartData['labels'][$value[0]] = (new \DateTime())->setTimestamp($value[0])->format("d.m.y H:i");
                    if (preg_match('/^(iface_stat_out_.*)/', $metricName)) {
                        $val = -1 * (float)$value[1];
                    } else {
                        $val = (float)$value[1];
                    }
                    $datasetData[$value[0]] = $val;
                }
                $dataset['data'] = $datasetData;
                $chartData['datasets'][$metricName] = $dataset;
            }
        }
        foreach ($chartData['labels'] as $timestamp => $_) {
            foreach ($chartData['datasets'] as $metricName => $dataset) {
                if (!isset($chartData['datasets'][$metricName]['data'][$timestamp])) {
                    $chartData['datasets'][$metricName]['data'][$timestamp] = null;
                }
            }
        }
        foreach ($chartData['datasets'] as $metricName => $dataset) {
            ksort($chartData['datasets'][$metricName]['data']);
            $chartData['datasets'][$metricName]['data'] = array_values($chartData['datasets'][$metricName]['data']);
        }
        ksort($chartData['datasets']);
        ksort($chartData['labels']);
        $chartData['datasets'] = array_values($chartData['datasets']);
        $chartData['labels'] = array_values($chartData['labels']);
        return $chartData;
    }

    protected function _getLabelFromMetricName($metricName)
    {
        $label = $metricName;
        switch ($metricName) {
            case 'online':
                $label = 'Online';
                break;
            case 'offline':
                $label = 'Offline';
                break;
            case 'poweroff':
                $label = 'Power Off';
                break;
            case 'los':
                $label = 'LOS';
                break;
            case 'all':
                $label = 'All';
                break;
            case 'device_resources_memory_util':
                $label = 'RAM utilization %';
                break;
            case 'device_resources_cpu_util':
                $label = 'CPU utilization %';
                break;
            case 'device_resources_temperature':
                $label = 'Temperature °C';
                break;
            case 'device_resources_disk_util':
                $label = 'Disk utilization %';
                break;
            case 'optical_rx':
                $label = 'Optical RX (dB)';
                break;
            case 'optical_tx':
                $label = 'Optical TX (dB)';
                break;
            case 'optical_olt_tx':
                $label = 'Optical OLT TX (dB)';
                break;
            case 'optical_olt_rx':
                $label = 'Optical OLT RX (dB)';
                break;
            case 'optical_voltage':
                $label = 'Voltage (V)';
                break;
            case 'optical_temperature':
                $label = 'Temp (°C)';
                break;
            case 'iface_stat_out_octets':
            case 'iface_stat_out_bytes':
                $label = 'Out bytes';
                break;
            case 'iface_stat_in_octets':
            case 'iface_stat_in_bytes':
                $label = 'In bytes';
                break;
            case 'iface_stat_in_drop_pkts':
                $label = 'In drop pkts';
                break;
            case 'iface_stat_out_drop_pkts':
                $label = 'Out drop pkts';
                break;
            case 'iface_stat_in_multicast_pkts':
                $label = 'In multicast pkts';
                break;
            case 'iface_stat_out_multicast_pkts':
                $label = 'Out multicast pkts';
                break;
            case 'iface_stat_out_broadcast_pkts':
                $label = 'Out broadcast pkts';
                break;
            case 'iface_stat_in_broadcast_pkts':
                $label = 'In broadcast pkts';
                break;
            case 'iface_stat_in_errors': $label = 'IN errors'; break;
            case 'iface_stat_out_errors': $label = 'OUT errors'; break;
            case 'iface_stat_in_discards': $label = 'IN discards'; break;
            case 'iface_stat_out_discards': $label = 'OUT discards'; break;
            case 'iface_stat_in_crc_errors': $label = 'IN CRC errors'; break;
            case 'iface_stat_out_crc_errors': $label = 'OUT CRC errors'; break;

        }
        return $label;
    }

    protected function _getLabelColorFromMetricName($metricName, $opacity = 1)
    {
        $color = "rgba(0, 0, 0, $opacity)";
        switch ($metricName) {
            case 'optical_rx':
            case 'optical_voltage':
            case 'optical_temperature':
            case 'iface_stat_out_octets':
            case 'iface_stat_out_discards':
            case 'iface_stat_out_bytes':
                $color = "rgba(8, 123, 3, $opacity)";
                break;
            case 'optical_tx':
            case 'iface_stat_in_bytes':
            case 'iface_stat_in_octets':
            case 'device_resources_memory_util':
            case 'device_resources_cpu_util':
            case 'device_resources_temperature':
            case 'iface_stat_in_discards':
            case 'device_resources_disk_util':
                $color = "rgba(2, 2, 233, $opacity)";
                break;
            case 'optical_olt_tx':
            case 'iface_stat_in_drop_pkts':
            case 'iface_stat_out_multicast_pkts':
            case 'iface_stat_out_errors':
                $color = "rgba(1, 0, 181, $opacity)";
                break;
            case 'optical_olt_rx':
            case 'iface_stat_out_drop_pkts':
            case 'iface_stat_in_broadcast_pkts':
            case 'iface_stat_in_errors':
                $color = "rgba(233, 200, 2, $opacity)";
                break;
            case 'iface_stat_out_broadcast_pkts':
            case 'iface_stat_out_crc_errors':
                $color = "rgba(43, 223, 201, $opacity)";
                break;
            case 'iface_stat_in_multicast_pkts':
            case 'iface_stat_in_crc_errors':
                $color = "rgba(158, 0, 0, $opacity)";
                break;
            case 'online':
                $color = "rgba(10, 115, 24, 1)";
                break;
            case 'all':
                $color = "rgba(130, 130, 130, 0.6)";
                break;

        }
        return $color;
    }
}
