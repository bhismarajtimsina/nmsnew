<?php


namespace WCC\Analytics\Controllers;


use Meklis\PromClient\Client;
use Monolog\Logger;
use SwitcherCore\Modules\Helper;
use WCAA\App;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;

/**
 * Class Controller
 * @package WCC\Analytics
 */
class Controller extends \WCC\PrometheusWrapper\Controllers\Controller
{
    /**
     * @Inject
     * @var PrometheusMetricsTempStore
     */
    protected $tempStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;


    function ontStatusesByTime($time, $deviceId = null)
    {
        if ($deviceId) {
            $response = $this->queries([
                "device_interface_admin_state{iface_type=\"ONU\", dev_id=\"$deviceId\", }",
                "device_interface_status{iface_type=\"ONU\",dev_id=\"$deviceId\"}",
            ], $time);

        } else {
            $response = $this->queries([
                'device_interface_admin_state{iface_type="ONU"}',
                'device_interface_status{iface_type="ONU"}',
            ], $time);
        }
        return $response;
    }

    function deviceStatusesByTime($time)
    {
        $response = $this->queries([
            'device_uptime',
            'pinger_host_status',
        ], $time);
        return $response;
    }

    function ontStatusesSeries($deviceIds = null, $start = null, $stop = null, $step = '10m')
    {
        if ($deviceIds) {
            return $this->seriesRequest([
                ['labels' => ['name' => 'online'], 'query' => sprintf('count (device_interface_status{iface_type="ONU", dev_id=~"%s"} == 1)', join("|", $deviceIds))],
                ['labels' => ['name' => 'los'], 'query' => sprintf('count (device_interface_status{iface_type="ONU", dev_id=~"%s"} == -2)', join("|", $deviceIds))],
                ['labels' => ['name' => 'offline'], 'query' => sprintf('count (device_interface_status{iface_type="ONU", dev_id=~"%s"} == 0 or device_interface_status{iface_type="ONU", dev_id=~"%s"} == -1)', join("|", $deviceIds), join("|", $deviceIds))],
            ], $start, $stop, $step);
        } else {
            return $this->seriesRequest([
                ['labels' => ['name' => 'online'], 'query' => sprintf('count (device_interface_status{iface_type="ONU"} == 1)')],
                ['labels' => ['name' => 'los'], 'query' => sprintf('count (device_interface_status{iface_type="ONU"} == -2)')],
                ['labels' => ['name' => 'offline'], 'query' => sprintf('count (device_interface_status{iface_type="ONU"} == 0 or device_interface_status{iface_type="ONU"} == -1)')],
            ], $start, $stop, $step);
        }
    }

    function errorsCountIncreasingSeries($deviceIds = null, $start = null, $stop = null, $step = '10m')
    {
        if ($deviceIds) {
            return $this->seriesRequest([
                ['labels' => ['name' => 'in_errors'], 'query' => sprintf('count(delta(iface_stat_in_errors{dev_id=~"%s"}[%s]) > 0)', join("|", $deviceIds), $step)],
                ['labels' => ['name' => 'out_errors'], 'query' => sprintf('count(delta(iface_stat_out_errors{dev_id=~"%s"}[%s]) > 0)', join("|", $deviceIds), $step)],
            ], $start, $stop, $step);
        } else {
            return $this->seriesRequest([
                ['labels' => ['name' => 'in_errors'], 'query' => sprintf('count(delta(iface_stat_in_errors[%s]) > 0)', $step)],
                ['labels' => ['name' => 'out_errors'], 'query' => sprintf('count(delta(iface_stat_out_errors[%s]) > 0)', $step)],
            ], $start, $stop, $step);
        }
    }

    /**
     * @param DeviceInterface $iface
     * @param $start
     * @param $stop
     * @param $step
     * @return array
     */
    function errorsIncreasingSeriesByInterface(DeviceInterface $iface, $start = null, $stop = null, $step = '10m')
    {
        return $this->seriesRequest([
            ['labels' => ['name' => 'in_errors'], 'query' => sprintf('ceil(delta(iface_stat_in_errors{dev_id="%s", iface_id="%s"}[%s]))', $iface->getDevice()->getId(), $iface->getBindKey(), $step)],
            ['labels' => ['name' => 'out_errors'], 'query' => sprintf('ceil(delta(iface_stat_out_errors{dev_id="%s", iface_id="%s"}[%s]))',$iface->getDevice()->getId(), $iface->getBindKey(), $step)],
        ], $start, $stop, $step);
    }

    function interfaceStatusesSeries($deviceId = null, $start = null, $stop = null, $step = '10m')
    {
        if ($deviceId) {
            return $this->seriesRequest([
                ['labels' => ['name' => 'online'], 'query' => sprintf('count (device_interface_status{iface_type !~"ONU|PON", dev_id="%d"} == 1)', $deviceId)],
                ['labels' => ['name' => 'all'], 'query' => sprintf('count (device_interface_status{iface_type !~"ONU|PON", dev_id="%d"})', $deviceId)],
            ], $start, $stop, $step);
        } else {
            return $this->seriesRequest([
                ['labels' => ['name' => 'online'], 'query' => sprintf('count (device_interface_status{iface_type !~"ONU|PON"} == 1)')],
                ['labels' => ['name' => 'all'], 'query' => sprintf('count (device_interface_status{iface_type !~"ONU|PON"})')],
            ], $start, $stop, $step);
        }
    }

    function getOntsWithSignalLevelStrength($deviceIds = [], $metricName = 'rx', $level = null)
    {
        $ifaces = [];
        foreach ($deviceIds as $deviceId) {
            foreach ($this->interfaceStorage->getByDevice($this->deviceStorage->getById($deviceId), 'ONU', true) as $iface) {
                $ifaces[] = $iface;
            }
        }
        $devices = join("|", $deviceIds);
        if (!$level) {
            $level = "-50";
        }
        $opticalMetrics = $this->promClient->queries([
            sprintf('optical_rx{dev_id=~"%s"}', $devices),
            sprintf('optical_olt_rx{dev_id=~"%s"}', $devices),
            sprintf('optical_tx{dev_id=~"%s"}', $devices),
        ]);
        $opticalData = [];
        foreach ($opticalMetrics as $values) {
            foreach ($values as $value) {
                $opticalData["{$value['metric']['dev_id']}-{$value['metric']['iface_id']}"][$value['metric']['__name__']] = $value['value'][1];
            }
        }
        $metrics = [];
        foreach ($ifaces as $iface) {
            $key = "{$iface->getDevice()->getId()}-{$iface->getBindKey()}";
            if (!isset($opticalData[$key]["optical_{$metricName}"]) || ceil($opticalData[$key]["optical_{$metricName}"]) != $level) continue;
            $metrics[] = [
                'id' => $iface->getId(),
                'name' => $iface->getName(),
                'bind_key' => $iface->getBindKey(),
                'device' => [
                    'id' => $iface->getDevice()->getId(),
                    'name' => $iface->getDevice()->getName(),
                    'ip' => $iface->getDevice()->getIp(),
                ],
                'status' => $iface->getStatus(),
                'description' => $iface->getDescription(),
                'agreement' => $iface->getAgreement(),
                'optical_rx' => isset($opticalData[$key]['optical_rx']) ? $opticalData[$key]['optical_rx'] : null,
                'optical_tx' => isset($opticalData[$key]['optical_tx']) ? $opticalData[$key]['optical_tx'] : null,
                'optical_olt_rx' => isset($opticalData[$key]['optical_olt_rx']) ? $opticalData[$key]['optical_olt_rx'] : null,
            ];
        }
        return $metrics;
    }

    function ontCurrentSignalLevelsForBar($deviceIds = [], $metricName = 'rx')
    {
        $devices = join("|", $deviceIds);
        $levels = $this->promClient->query(sprintf('count_values("signal", ceil(optical_%s{dev_id=~"%s"}))', $metricName, $devices));
        $lvls = [];
        foreach ($levels as $level) {
            $color = 'rgba(5, 100, 0, 0.9)';
            if ($level['metric']['signal'] < -28) {
                $color = '#7a0000';
            }
            if ($level['metric']['signal'] > -16) {
                $color = '#b37100';
            }
            $lvls[$level['metric']['signal']] = [
                'label' => $level['metric']['signal'],
                'value' => $level['value'][1],
                'color' => $color,
            ];
        }
        krsort($lvls);
        return array_values($lvls);
    }


    /**
     * @param Device[] $devicesList
     * @param $period
     * @param $views
     * @return array
     */
    function getErrorsByDevices(array $devicesList = [], $period = '1d', $views = [], $time = null)
    {
        $deviceKeys = [];
        foreach ($devicesList as $dev) {
            $deviceKeys[$dev->getId()] = $dev;
        }
        $devices = join("|", array_keys($deviceKeys));
        if ($views) {
            $queries = [];
            if (in_array('errors', $views)) {
                $queries[] = ['labels' => ['name' => 'in_errors'], 'query' => sprintf('delta(iface_stat_in_errors{dev_id=~"%s"}[%s]) > 0', $devices, $period)];
                $queries[] = ['labels' => ['name' => 'out_errors'], 'query' => sprintf('delta(iface_stat_out_errors{dev_id=~"%s"}[%s]) > 0', $devices, $period)];
            }
            if (in_array('crc_errors', $views)) {
                $queries[] = ['labels' => ['name' => 'in_crc_errors'], 'query' => sprintf('delta(iface_stat_in_crc_errors{dev_id=~"%s"}[%s]) > 0', $devices, $period)];
                $queries[] = ['labels' => ['name' => 'out_crc_errors'], 'query' => sprintf('delta(iface_stat_out_crc_errors{dev_id=~"%s"}[%s]) > 0', $devices, $period)];
            }
            if (in_array('discards', $views)) {
                $queries[] = ['labels' => ['name' => 'in_discards'], 'query' => sprintf('delta(iface_stat_in_discards{dev_id=~"%s"}[%s]) > 0', $devices, $period)];
                $queries[] = ['labels' => ['name' => 'out_discards'], 'query' => sprintf('delta(iface_stat_out_discards{dev_id=~"%s"}[%s]) > 0', $devices, $period)];
            }
        } else {
            $queries = [
                ['labels' => ['name' => 'in_errors'], 'query' => sprintf('delta(iface_stat_in_errors{dev_id=~"%s"}[%s]) > 0', $devices, $period)],
                ['labels' => ['name' => 'out_errors'], 'query' => sprintf('delta(iface_stat_out_errors{dev_id=~"%s"}[%s]) > 0', $devices, $period)],
                ['labels' => ['name' => 'counter_in_errors'], 'query' => sprintf('iface_stat_in_errors{dev_id=~"%s"} > 0', $devices)],
                ['labels' => ['name' => 'counter_out_errors'], 'query' => sprintf('iface_stat_out_errors{dev_id=~"%s"} > 0', $devices)],
            ];
        }
        $metricData = $this->promClient->queries($queries, $time);
        $DATA = [];
        $interfaces = [];
        foreach ($metricData as $values) {
            foreach ($values as $value) {
                $key = "{$value['metric']['dev_id']}-{$value['metric']['iface_id']}";
                if (isset($interfaces[$key])) {
                    $iface = $interfaces[$key];
                } else {
                    try {
                        $iface = $this->deviceInterfaceStorage->getByDeviceAndKey($deviceKeys[$value['metric']['dev_id']], $value['metric']['iface_id']);
                        $interfaces[$key] = $iface;
                    } catch (\Exception $e) {
                        $this->logger->withName("analytics")->error("Error fill error increasing table - {$e->getMessage()}");
                        continue;
                    }
                }
                $DATA[$key]['interface'] = $iface->getAsArray();
                $DATA[$key][$value['request']['name']] = round($value['value'][1]);
                if (!isset($DATA[$key]['in_errors'])) $DATA[$key]['in_errors'] = 0;
                if (!isset($DATA[$key]['out_errors'])) $DATA[$key]['out_errors'] = 0;
                if (!isset($DATA[$key]['counter_in_errors'])) $DATA[$key]['counter_in_errors'] = 0;
                if (!isset($DATA[$key]['counter_out_errors'])) $DATA[$key]['counter_out_errors'] = 0;
                if (!isset($DATA[$key]['in_crc_errors'])) $DATA[$key]['in_crc_errors'] = 0;
                if (!isset($DATA[$key]['out_crc_errors'])) $DATA[$key]['out_crc_errors'] = 0;
                if (!isset($DATA[$key]['in_discards'])) $DATA[$key]['in_discards'] = 0;
                if (!isset($DATA[$key]['out_discards'])) $DATA[$key]['out_discards'] = 0;
            }
        }
        return array_values(array_filter($DATA, function ($e) {
            return $e['in_errors'] != 0 || $e['out_errors'] != 0;
        }));
    }

    /**
     * @param DeviceInterface $deviceInterfaces
     * @param $period
     * @return array
     * @throws \WCAA\Storage\Exceptions\RecordNotFoundException
     */
    function getErrorsByInterface(DeviceInterface $iface, $period = '1d')
    {
        $queries = [
            ['labels' => ['name' => 'in_errors'], 'query' => sprintf('delta(iface_stat_in_errors{dev_id="%s", iface_id="%s"}[%s]) > 0', $iface->getDevice()->getId(), $iface->getBindKey(), $period)],
            ['labels' => ['name' => 'out_errors'], 'query' => sprintf('delta(iface_stat_out_errors{dev_id="%s", iface_id="%s"}[%s]) > 0', $iface->getDevice()->getId(), $iface->getBindKey(), $period)],
            ['labels' => ['name' => 'in_crc_errors'], 'query' => sprintf('delta(iface_stat_in_crc_errors{dev_id="%s", iface_id="%s"}[%s]) > 0', $iface->getDevice()->getId(), $iface->getBindKey(), $period)],
            ['labels' => ['name' => 'out_crc_errors'], 'query' => sprintf('delta(iface_stat_out_crc_errors{dev_id="%s", iface_id="%s"}[%s]) > 0', $iface->getDevice()->getId(), $iface->getBindKey(), $period)],
            ['labels' => ['name' => 'in_discards'], 'query' => sprintf('delta(iface_stat_in_discards{dev_id="%s", iface_id="%s"}[%s]) > 0', $iface->getDevice()->getId(), $iface->getBindKey(), $period)],
            ['labels' => ['name' => 'out_discards'], 'query' => sprintf('delta(iface_stat_out_discards{dev_id="%s", iface_id="%s"}[%s]) > 0', $iface->getDevice()->getId(), $iface->getBindKey(), $period)],
        ];
        $metricData = $this->promClient->queries($queries);
        foreach ($metricData as $values) {
            foreach ($values as $value) {
                $DATA[$value['request']['name']] = round($value['value'][1]);
            }
        }
        if (!isset($DATA['in_errors'])) $DATA['in_errors'] = 0;
        if (!isset($DATA['out_errors'])) $DATA['out_errors'] = 0;
        if (!isset($DATA['in_crc_errors'])) $DATA['in_crc_errors'] = 0;
        if (!isset($DATA['out_crc_errors'])) $DATA['out_crc_errors'] = 0;
        if (!isset($DATA['in_discards'])) $DATA['in_discards'] = 0;
        if (!isset($DATA['out_discards'])) $DATA['out_discards'] = 0;

        return $DATA;
    }

    function deviceStatusesSeries($start = null, $stop = null, $step = '10m')
    {
        return $this->seriesRequest([
            ['labels' => ['name' => 'online'], 'query' => sprintf('count(pinger_host_status > 0)')],
            ['labels' => ['name' => 'all'], 'query' => sprintf('count(pinger_host_status)')],
        ], $start, $stop, $step);
    }

    function devicesStatusSeries($deviceIds = [], $start = null, $stop = null, $step = '10m')
    {
        $devices = join("|", $deviceIds);
        return $this->seriesRequest([
            ['labels' => ['name' => 'online'], 'query' => sprintf('count(pinger_host_status{dev_id=~"%s"} > 0)', $devices)],
            ['labels' => ['name' => 'all'], 'query' => sprintf('count(pinger_host_status{dev_id=~"%s"})', $devices)],
        ], $start, $stop, $step);
    }


    protected function _getLabelFromMetricName($metricName)
    {
        $label = $metricName;
        switch ($metricName) {
            case 'all':
                $label = 'All';
                break;
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
        }
        return $label;
    }

    protected function _getLabelColorFromMetricName($metricName, $opacity = 1)
    {
        $color = "rgba(0, 0, 0, $opacity)";
        switch ($metricName) {
            case 'online':
                $color = "rgba(10, 115, 24, 1)";
                break;
            case 'offline':
                $color = "rgba(150, 150, 150, 1)";
                break;
            case 'all':
                $color = "rgba(150, 150, 150, 0.6)";
                break;
            case 'poweroff':
                $color = "rgba(0, 49, 128, 1)";
                break;
            case 'los':
                $color = "rgba(139, 0, 0, 1)";
                break;
        }
        return $color;
    }

}
