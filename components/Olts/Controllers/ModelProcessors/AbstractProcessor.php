<?php

namespace WCC\Olts\Controllers\ModelProcessors;

use DI\Annotation\Inject;
use Monolog\Logger;
use WCAA\App;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Poller\ResponseToPollerWriter;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\ResponsesWrapper;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Events\Controllers\Controller;
use WCC\Events\Models\EventFilter;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;

abstract class AbstractProcessor
{


    /**
     * @Inject
     * @var Logger
     */
    protected $logger;

    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @var array
     */

    protected $moduleConfig;

    /**
     * @var ComponentInjector
     */
    protected $moduleInjector;


    private function getModuleName()
    {
        $rc = new \ReflectionClass(get_class($this));
        if (!preg_match('#/Controllers#', dirname($rc->getFileName()))) {
            throw new \Exception("Controllers must be placed in <component_path>/<Component>/Controllers");
        }
        return (require dirname($rc->getFileName()) . "/../../config.php")['name'];
    }

    function setLogger(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @Inject
     * @var ResponseToPollerWriter
     */
    protected $responseToPollerWrapper;

    /**
     * @Inject
     * @var PrometheusMetricsTempStore
     */
    protected $lastProm;

    /**
     * @var array
     */
    private $meta = [];

    /**
     * @var SwitcherCore
     */
    protected $core;

    /**
     * @var Device
     */
    protected $device;

    /**
     * @var User
     */
    protected $user;

    /**
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaces;


    /**
     * @Inject
     * @var OntIdentStorage
     */
    protected $ontIdentStorage;


    /**
     * @Inject
     * @var FdbHistoryStorage
     */
    protected $fdbStorage;

    function __construct(ComponentInjector $componentInjector, Logger $logger, SwitcherCore $core, DeviceInterfaceStorage $devIface)
    {
        $this->core = $core;
        $this->deviceInterfaces = $devIface;
        $this->moduleInjector = $componentInjector;
        $this->moduleConfig = $componentInjector->getEnabledComponentConfig($this->getModuleName());
        $this->logger = $logger->withName("component." . $this->moduleConfig['name']);
    }

    function setUser(User $user)
    {
        $this->user = $user;
        $this->logger->info("Setted user - {$user->getName()}", $user->getAsArray());
        $this->core = $this->core->setUser($user);
        return $this;
    }

    function setDevice(Device $device)
    {
        $this->_coreMeta = null;
        $this->meta = [];
        $this->device = $device;
        $this->logger->info("Setted device - {$device->getIp()}", $device->getAsArray());
        return $this;
    }

    public function getCardsList($from = 'cache')
    {
        if ($this->isModuleSupported('card_list')) {
            $responses = $this->processRequests(
                [(new Request())->setModule('card_list')->setDevice($this->device)],
                $from);
            return $responses->getFirstByModule('card_list')->getData();
        } else {
            throw new \Exception("Module card_list not supported by device");
        }
    }

    public function getCardsStatus($from = 'cache')
    {
        if ($this->isModuleSupported('card_status')) {
            $responses = $this->processRequests(
                [(new Request())->setModule('card_status')->setDevice($this->device)],
                $from);
            return $responses->getFirstByModule('card_status')->getData();
        } else {
            throw new \Exception("Module card_status not supported by device");
        }
    }

    public function getPhysicalInterfacesStatus($interface = null, $from = 'cache')
    {
        if (!$this->isModuleSupported('link_info')) {
            throw new \Exception("Module link_info not supported by device");
        }

        if ($interface) {
            $requests = [
                (new Request())->setModule('link_info')->setArguments(['interface' => $interface])->setDevice($this->device),
                (new Request())->setModule('interface_counters')->setArguments(['interface' => $interface])->setDevice($this->device),
                (new Request())->setModule('interface_descriptions')->setArguments(['interface' => $interface])->setDevice($this->device),
            ];
            if ($this->isModuleSupported('sfp_optical')) $requests[] = (new Request())->setModule('sfp_optical')->setArguments(['interface' => $interface])->setDevice($this->device);
            if ($this->isModuleSupported('sfp_media')) $requests[] = (new Request())->setModule('sfp_media')->setArguments(['interface' => $interface])->setDevice($this->device);

        } else {
            $requests = [
                (new Request())->setModule('link_info')->setDevice($this->device),
                (new Request())->setModule('interface_counters')->setArguments(['interface_type' => 'PHYSICAL'])->setDevice($this->device),
                (new Request())->setModule('interface_descriptions')->setArguments(['interface_type' => 'PHYSICAL'])->setDevice($this->device),
            ];
            if ($this->isModuleSupported('sfp_optical')) $requests[] = (new Request())->setModule('sfp_optical')->setArguments(['interface' => $interface])->setDevice($this->device);

        }

        $responses = $this->processRequests(
            $requests,
            $from);
        if ($responses->getFirstByModule('link_info')->getError()) {
            throw new \Exception($responses->getFirstByModule('link_info')->getError()['message']);
        }
        $links = $responses->getFirstByModule('link_info')->getData();
        $data = [];
        foreach ($links as $link) {
            $data[$link['interface']['id']] = $link;
            $data[$link['interface']['id']]['counters'] = null;
            $data[$link['interface']['id']]['description'] = null;
            $data[$link['interface']['id']]['optical'] = null;
            $data[$link['interface']['id']]['sfp_media'] = null;
        }

        if ($responses->getFirstByModule('interface_counters')->getError()) {
            return array_values($data);
        }
        $counters = $responses->getFirstByModule('interface_counters')->getData();
        foreach ($counters as $counter) {
            $id = $counter['interface']['id'];
            if (!isset($data[$id])) continue;
            unset($counter['interface']);
            $data[$id]['counters'] = $counter;
        }
        if (!$responses->getFirstByModule('interface_descriptions')->getError()) {
            $descriptions = $responses->getFirstByModule('interface_descriptions')->getData();
            foreach ($descriptions as $description) {
                $id = $description['interface']['id'];
                if (!isset($data[$id])) continue;
                $data[$id]['description'] = $description['description'];
            }
        }
        if ($this->isModuleSupported('sfp_optical') && !$responses->getFirstByModule('sfp_optical')->getError()) {
            foreach ($responses->getFirstByModule('sfp_optical')->getData() as $dt) {
                $id = $dt['interface']['id'];
                if (!isset($data[$id])) continue;
                unset($dt['interface']);
                $data[$id]['optical'] = $dt;
            }
        }
        if ($this->isModuleSupported('sfp_media') && $interface && !$responses->getFirstByModule('sfp_media')->getError()) {
            foreach ($responses->getFirstByModule('sfp_media')->getData() as $dt) {
                $id = $dt['interface']['id'];
                unset($data['interface']);
                $data[$id]['sfp_media'] = $dt;
            }
        }

        if ($this->device->getParamByName('disable_save_description_on_physical_ifaces')) {
            $storedIfaces = $this->deviceInterfaces->getByDevice($this->device, null, false);
            foreach ($storedIfaces as $iface) {
                if (!isset($data[$iface->getBindKey()])) continue;
                $data[$iface->getBindKey()]['description'] = $iface->getDescription();
            }
        }

        return array_values($data);
    }

    public function getUnregisteredOnts($from = 'cache')
    {
        if ($this->isModuleSupported('unregistered_onts')) {
            $responses = $this->processRequests(
                [(new Request())->setModule('unregistered_onts')->setDevice($this->device)],
                $from);
            return $responses->getFirstByModule('unregistered_onts')->getData();
        } else {
            throw new \Exception("Module unregistered_onts not supported by device");
        }
    }

    public function getGponProfiles($from = 'cache')
    {
        if ($this->isModuleSupported('gpon_profiles')) {
            $responses = $this->processRequests(
                [(new Request())->setModule('gpon_profiles')->setDevice($this->device)],
                $from);
            return $responses->getFirstByModule('gpon_profiles')->getData();
        } else {
            throw new \Exception("Module gpon_profiles not supported by device");
        }
    }

    public function getOntConfiguration($from = 'cache')
    {
        if ($this->isModuleSupported('pon_onts_configuration')) {
            $responses = $this->processRequests(
                [(new Request())->setModule('pon_onts_configuration')->setDevice($this->device)],
                $from);
            return $responses->getFirstByModule('pon_onts_configuration')->getData();
        } else {
            throw new \Exception("Module gpon_profiles not supported by device");
        }
    }

    public function getPonInterfacesList($from = 'cache')
    {
        if (!$this->isModuleSupported('pon_ports_list')) {
            throw new \InvalidArgumentException("Module pon_ports_list not supported by device");
        }
        $requests = [(new Request())->setModule('pon_ports_list')->setDevice($this->device)];
        if ($this->isModuleSupported('sfp_optical')) {
            $requests[] = (new Request())->setArguments(['load_only' => 'temp'])->setModule('sfp_optical')->setDevice($this->device);
        }
        if (!$this->device->getParamByName('disable_save_description_on_physical_ifaces') && $this->isModuleSupported('interface_descriptions')) {
            $requests[] = (new Request())->setArguments(['interface_type' => 'PHYSICAL'])->setModule('interface_descriptions')->setDevice($this->device);
        }
        $responses = $this->processRequests($requests, $from);
        $data = [];
        if(!$responses->getFirstByModule('pon_ports_list')->getData()) {
            throw new SupportException("Interfaces not found. If you believe this is an error, contact the administrator.");
        }
        foreach ($responses->getFirstByModule('pon_ports_list')->getData() as $resp) {
            $data[$resp['id']] = $resp;
            $data[$resp['id']]['pon_port_size'] = null;
            $data[$resp['id']]['description'] = null;
            if ($meta = $this->getDeviceCoreMeta()) {
                $data[$resp['id']]['pon_port_size'] = isset($meta['extra']['pon_port_size']) ? $meta['extra']['pon_port_size'] : null;
            }
            if (isset($resp['_pon_max_ont_size'])) {
                $data[$resp['id']]['pon_port_size'] = $resp['_pon_max_ont_size'];
            }
            $data[$resp['id']]['optical'] = [
                'tx' => null,
                'temp' => null,
            ];
        }

        if ($this->isModuleAllowed('ont_list', 'sfp_optical')) {
            if ($dt = $responses->getFirstByModule('sfp_optical')->getData()) {
                foreach ($dt as $d) {
                    if (!isset($data[$d['interface']['id']])) continue;
                    $data[$d['interface']['id']]['optical']['tx'] = isset($d['tx_power']) ? $d['tx_power'] : null;
                    $data[$d['interface']['id']]['optical']['temp'] = isset($d['temp']) ? $d['temp'] : null;
                }
            }
        }
        if (!$this->device->getParamByName('disable_save_description_on_physical_ifaces') && $this->isModuleSupported('interface_descriptions')) {
            $dt = $responses->getFirstByModule('interface_descriptions');
            if (!$dt->getError() && $dt->getData()) {
                foreach ($dt->getData() as $d) {
                    if (!isset($data[$d['interface']['id']])) continue;
                    $data[$d['interface']['id']]['description'] = $d['description'];
                }
            }
        } elseif ($this->device->getParamByName('disable_save_description_on_physical_ifaces')) {
            $storedIfaces = $this->deviceInterfaces->getByDevice($this->device, 'PON', false);
            foreach ($storedIfaces as $iface) {
                if (!isset($data[$iface->getBindKey()])) continue;
                $data[$iface->getBindKey()]['description'] = $iface->getDescription();
            }
        }
        return array_values($data);
    }

    public function getOntsListInfo($from = 'cache')
    {
        $requests = [];
        if ($this->isModuleAllowed('ont_list', 'pon_onts_mac_addr')) {
            $requests[] = (new Request())->setModule('pon_onts_mac_addr')->setDevice($this->device);
        }
        if ($this->isModuleAllowed('ont_list', 'pon_onts_status')) {
            $requests[] = (new Request())->setModule('pon_onts_status')->setDevice($this->device);
        }
        if ($this->isModuleAllowed('ont_list', 'pon_onts_serial')) {
            $requests[] = (new Request())->setModule('pon_onts_serial')->setDevice($this->device);
        }
        if ($this->isModuleAllowed('ont_list', 'interface_descriptions')) {
            $requests[] = (new Request())->setModule('interface_descriptions')->setDevice($this->device);
        }
        if (!$this->device->getModel()->getParamByName('show_optical_info_from_history')) {
            if ($this->isModuleAllowed('ont_list', 'pon_onts_optical')) {
                $loadOnly = 'rx,temp,distance';
                if ($this->device->getModel()->getParamByName('optical_load_only')) {
                    $loadOnly = $this->device->getModel()->getParamByName('optical_load_only');
                }
                $requests[] = (new Request())->setArguments(['load_only' => $loadOnly])->setModule('pon_onts_optical')->setDevice($this->device);
            }
        }
        $responses = $this->processRequests($requests, $from);
        $data = [];
        $dts = $responses->getFirstByModule('pon_onts_status');
        if ($error = $dts->getError()) {
            $this->logger->error($error['message']);
            return [];
        }
        foreach ($dts->getData() as $d) {
            if (!isset($d['interface']['id'])) continue;
            $data[$d['interface']['id']] = [
                'interface' => $d['interface'],
                'status' => isset($d['status']) ? $d['status'] : null,
                'bind_status' => isset($d['bind_status']) ? $d['bind_status'] : null,
                'admin_state' => isset($d['admin_state']) ? $d['admin_state'] : null,
                'optical' => [
                    'rx' => null,
                    'olt_rx' => null,
                    'temp' => null,
                    'voltage' => null,
                    'distance' => null,
                ],
                'description' => null,
                'ident' => [
                    'value' => null,
                    'type' => null,
                ],
            ];
        }

        if ($this->isModuleAllowed('ont_list', 'pon_onts_mac_addr')) {
            if ($dt = $responses->getFirstByModule('pon_onts_mac_addr')->getData()) {
                foreach ($dt as $d) {
                    if (!isset($data[$d['interface']['id']])) continue;
                    $data[$d['interface']['id']]['ident']['value'] = $d['mac_address'];
                    $data[$d['interface']['id']]['ident']['type'] = 'MAC';
                }
            }
        }

        if ($this->isModuleAllowed('ont_list', 'pon_onts_serial')) {
            if ($dt = $responses->getFirstByModule('pon_onts_serial')->getData()) {
                foreach ($dt as $d) {
                    if (!isset($data[$d['interface']['id']])) continue;
                    $data[$d['interface']['id']]['ident']['value'] = $d['serial'];
                    $data[$d['interface']['id']]['ident']['type'] = 'SN';
                }
            }
        }

        if ($this->isModuleAllowed('ont_list', 'interface_descriptions')) {
            if ($dt = $responses->getFirstByModule('interface_descriptions')->getData()) {
                foreach ($dt as $d) {
                    if (!isset($data[$d['interface']['id']])) continue;
                    $data[$d['interface']['id']]['description'] = $d['description'];
                }
            }
        }

        if (!$this->device->getModel()->getParamByName('show_optical_info_from_history')) {
            if ($this->isModuleAllowed('ont_list', 'pon_onts_optical') && $dt = $responses->getFirstByModule('pon_onts_optical')->getData()) {
                foreach ($dt as $d) {
                    if (!isset($d['interface'])) {
                        continue;
                    }
                    if (!isset($data[$d['interface']['id']])) continue;
                    $data[$d['interface']['id']]['optical']['rx'] = isset($d['rx']) ? $d['rx'] : null;
                    $data[$d['interface']['id']]['optical']['olt_rx'] = isset($d['olt_rx']) ? $d['olt_rx'] : null;
                    $data[$d['interface']['id']]['optical']['tx'] = isset($d['tx']) ? $d['tx'] : null;;
                    $data[$d['interface']['id']]['optical']['temp'] = isset($d['temp']) ? $d['temp'] : null;;
                    $data[$d['interface']['id']]['optical']['voltage'] = isset($d['voltage']) ? $d['voltage'] : null;;
                    $data[$d['interface']['id']]['optical']['distance'] = isset($d['distance']) ? $d['distance'] : null;;
                }
            }
        } else {
            $opticalInfo = $this->getLastOpticalInfoByDevice($this->device);
            if ($opticalInfo) {
                foreach ($opticalInfo as $ifaceId => $d) {
                    if (!isset($data[$ifaceId])) continue;
                    $data[$ifaceId]['optical']['rx'] = isset($d['optical_rx']) ? (float)$d['optical_rx'] : null;
                    $data[$ifaceId]['optical']['olt_rx'] = isset($d['optical_olt_rx']) ? (float)$d['optical_olt_rx'] : null;
                    $data[$ifaceId]['optical']['tx'] = isset($d['optical_tx']) ? (float)$d['optical_tx'] : null;
                    $data[$ifaceId]['optical']['temp'] = isset($d['optical_temperature']) ? (float)$d['optical_temperature'] : null;;
                    $data[$ifaceId]['optical']['voltage'] = isset($d['optical_voltage']) ? (float)$d['optical_voltage'] : null;;
                    $data[$ifaceId]['optical']['distance'] = isset($d['optical_distance']) ? (float)$d['optical_distance'] : null;
                }
            }
        }
        return array_values($data);
    }

    function getLastOpticalInfoByDevice(Device $device)
    {
        $data = $this->lastProm->getLastMetricsByDevice($device, [
            'optical_rx',
            'optical_tx',
            'optical_olt_rx',
            'optical_voltage',
            'optical_temperature',
            'optical_distance',
        ]);
        $ifaces = [];
        foreach ($data as $metricName => $metrics) {
            foreach ($metrics as $metric) {
                $ifaces[$metric['labels']['iface_id']][$metricName] = $metric['value'];
            }
        }
        return $ifaces;
    }

    function getLastOpticalInfoByDeviceInterface(DeviceInterface $iface)
    {
        $data = $this->lastProm->getLastMetricsByInterface($iface, [
            'optical_rx',
            'optical_tx',
            'optical_olt_rx',
            'optical_voltage',
            'optical_temperature',
            'optical_distance',
        ]);
        $ifaces = [];
        foreach ($data as $metricName => $metrics) {
            foreach ($metrics as $metric) {
                $ifaces[$metric['labels']['iface_id']][$metricName] = $metric['value'];
            }
        }
        return array_values($ifaces);
    }

    protected function getRequestsListForOntInfo($interface, $loadOnly = [], $disableModules = [])
    {
        $requests = [];
        $modules = $this->getSupportedModules();
        if ($disableModules) {
            foreach ($modules as $index => $moduleName) {
                if (in_array($moduleName, $disableModules)) {
                    unset($modules[$index]);
                }
            }
        }
        if ($loadOnly) {
            foreach ($modules as $index => $moduleName) {
                if (!in_array($moduleName, $loadOnly)) {
                    unset($modules[$index]);
                }
            }
            $modules = array_values($modules);
        }
        if ($this->isModuleAllowed('ont_info', 'interface_descriptions') && in_array('interface_descriptions', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('interface_descriptions')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'interface_counters') && in_array('interface_counters', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('interface_counters')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'pon_onts_status') && in_array('pon_onts_status', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('pon_onts_status')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'pon_onts_vendor') && in_array('pon_onts_vendor', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('pon_onts_vendor')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'fdb') && in_array('fdb', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('fdb')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'pon_onts_reasons') && in_array('pon_onts_reasons', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('pon_onts_reasons')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'pon_onts_optical') && in_array('pon_onts_optical', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('pon_onts_optical')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'uni_interfaces_status') && in_array('uni_interfaces_status', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('uni_interfaces_status')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'uni_interfaces_vlans') && in_array('uni_interfaces_vlans', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('uni_interfaces_vlans')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'pon_onts_mac_addr') && in_array('pon_onts_mac_addr', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('pon_onts_mac_addr')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'pon_onts_serial') && in_array('pon_onts_serial', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('pon_onts_serial')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'pon_onts_configuration') && in_array('pon_onts_configuration', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('pon_onts_configuration')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'onu_ip_host') && in_array('onu_ip_host', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('onu_ip_host')->setArguments(['interface' => $interface]);
        }
        if ($this->isModuleAllowed('ont_info', 'pon_onts_down_history') && in_array('pon_onts_down_history', $modules)) {
            $requests[] = (new Request())->setDevice($this->device)->setModule('pon_onts_down_history')->setArguments(['interface' => $interface]);
        }
        return $requests;
    }

    public function getOnuHistoryInfo($ifaceBindKey)
    {
        $data = [
            'interface' => null,
            'vendor' => [
                'model' => null,
                'vendor' => null,
                'ver_hardware' => null,
                'ver_software' => null,
                'versions' => null,
                'omcc_version' => null,
                'model_id' => null,
            ],
            'status' => [
                '_onu_disabled' => null,
                'online' => null,
                'bind' => null,
                'admin' => null,
            ],
            'description' => null,
            'name' => null,
            'fdb' => [],
            'ident' => [
                'value' => null,
                'type' => null,
            ],
            'optical' => [
                'olt_rx' => null,
                'rx' => null,
                'tx' => null,
                'voltage' => null,
                'temp' => null,
                'distance' => null,
            ],
        ];
        $interface = $this->deviceInterfaces->getByDeviceAndKey($this->device, $ifaceBindKey);
        $data['interface'] = [
            'id' => $interface->getBindKey(),
            'name' => $interface->getName(),
            'type' => $interface->getType(),
        ];
        $data['name'] = $interface->getDescription();
        $data['description'] = $interface->getDescription();
        $data['status']['online'] = $interface->getStatus() === 'Up' ? 'Online' : 'Offline';
        $data['status']['bind'] = $interface->getStatus() === 'Up' ? 'Online' : $interface->getStatus();

        /**
         * 'optical_rx',
         * 'optical_tx',
         * 'optical_olt_rx',
         * 'optical_voltage',
         * 'optical_temperature',
         * 'optical_distance',
         */
        $optical = $this->getLastOpticalInfoByDeviceInterface($interface);
        if (isset($optical[0])) {
            $opt = $optical[0];
            $data['optical'] = [
                'olt_rx' => isset($opt['optical_olt_rx']) ? $opt['optical_olt_rx'] : null,
                'rx' => isset($opt['optical_rx']) ? $opt['optical_rx'] : null,
                'tx' => isset($opt['optical_tx']) ? $opt['optical_tx'] : null,
                'voltage' => isset($opt['optical_voltage']) ? $opt['optical_voltage'] : null,
                'distance' => isset($opt['optical_distance']) ? $opt['optical_distance'] : null,
                'temp' => isset($opt['optical_temperature']) ? $opt['optical_temperature'] : null,
            ];
        }
        $ident = $this->ontIdentStorage->getByInterface($interface);
        if ($ident) {
            $data['vendor'] = $ident->getVendorInfo();
            $data['ident'] = [
                'value' => $ident->getIdent(),
                'type' => $ident->getType(),
            ];
        }

        $fdb = $this->fdbStorage->getByInterface($interface, true);
        foreach ($fdb as $f) {
            $data['fdb'][] = [
                'mac_address' => $f->getMacAddress(),
                'vlan_id' => $f->getVlanId(),
            ];
        }

        return $data;
    }

    public function getOnuInfo($interface, $from = 'cache', $loadOnly = [])
    {

        $disabledModules = [];
        if (in_array($this->device->getModel()->getVendor(), ['ZTE', 'Huawei'])) {
            $iface = $this->parseInterface($interface);
            if ($iface['_technology'] === 'gpon') {
                $disabledModules[] = 'pon_onts_mac_addr';
            } elseif ($iface['_technology'] === 'epon') {
                $disabledModules[] = 'pon_onts_serial';
            }
        }

        $requests = $this->getRequestsListForOntInfo($interface, $loadOnly, $disabledModules);
        $responses = $this->processRequests($requests, $from);
        $data = [
            'interface' => null,
            'vendor' => [
                'model' => null,
                'vendor' => null,
                'ver_hardware' => null,
                'ver_software' => null,
                'versions' => null,
                'omcc_version' => null,
                'model_id' => null,
            ],
            'status' => [
                '_onu_disabled' => null,
                'online' => null,
                'bind' => null,
                'admin' => null,
            ],
            'uni' => null,
            'description' => null,
            'name' => null,
            'fdb' => [],
            'ident' => [
                'value' => null,
                'type' => null,
            ],
            'reasons' => [
                'history_table' => null,
                'last_reg' => null,
                'last_reg_since' => null,
                'last_dereg' => null,
                'last_change' => null,
                'last_dereg_since' => null,
                'last_down_reason' => null,
            ],
            'counters' => [
                'in_errors' => null,
                'out_errors' => null,
                'in_discards' => null,
                'out_discards' => null,
                'in_octets' => null,
                'out_octets' => null,
                'in_multicast_pkts' => null,
                'out_multicast_pkts' => null,
                'in_broadcast_pkts' => null,
                'out_broadcast_pkts' => null,
            ],
            'optical' => [
                'olt_rx' => null,
                'rx' => null,
                'tx' => null,
                'voltage' => null,
                'temp' => null,
                'distance' => null,
            ],
            'configuration' => null,
            'profiles' => null,
            'ip_host' => null,
        ];

        $modules = $this->getSupportedModules();
        if ($loadOnly) {
            foreach ($modules as $key => $moduleName) {
                if (!in_array($moduleName, $loadOnly)) {
                    unset($modules[$key]);
                }
            }
            $modules = array_values($modules);
        }
        if ($disabledModules) {
            foreach ($modules as $index => $moduleName) {
                if (in_array($moduleName, $disabledModules)) {
                    unset($modules[$index]);
                }
            }
        }
        if (in_array('pon_onts_status', $modules) && $this->isModuleAllowed('ont_info', 'pon_onts_status')) {
            if ($dt = $responses->getFirstByModule('pon_onts_status')->getData()) {
                $info = $dt[0];
                $data['status']['online'] = isset($info['status']) ? $info['status'] : null;
                $data['status']['bind'] = isset($info['bind_status']) ? $info['bind_status'] : null;
                $data['status']['admin'] = isset($info['admin_state']) ? $info['admin_state'] : null;
                $data['status']['_conf_status'] = isset($info['conf_status']) ? $info['conf_status'] : null;
                $data['status']['_onu_disabled'] = isset($info['_onu_disabled']) ? $info['_onu_disabled'] : null;
                $data['interface'] = $info['interface'];
                if ($data['status']['_onu_disabled']) {
                    $data['status']['admin'] = "DisabledByAdmin";
                }
            }
        }
        if (in_array('pon_onts_configuration', $modules) && $this->isModuleAllowed('ont_info', 'pon_onts_configuration')) {
            if ($dt = $responses->getFirstByModule('pon_onts_configuration')->getData()) {
                $info = $dt[0];
                if (isset($info['srv'])) {
                    $info['srv_profile'] = $info['srv']['name'] . " (" . $info['srv']['id'] . ")";
                }
                if (isset($info['line'])) {
                    $info['line_profile'] = $info['line']['name'] . " (" . $info['line']['id'] . ")";
                }
                unset($info['line'], $info['srv'], $info['interface'], $info['_line_id'], $info['_srv_id']);
                $data['configuration'] = $info;
            }
        }
        if (in_array('interface_counters', $modules) && $this->isModuleAllowed('ont_info', 'interface_counters')) {
            if ($dt = $responses->getFirstByModule('interface_counters')->getData()) {
                $info = $dt[0];
                $data['counters'] = [
                    'in_errors' => isset($info['in_errors']) ? $info['in_errors'] : null,
                    'out_errors' => isset($info['out_errors']) ? $info['out_errors'] : null,
                    'in_discards' => isset($info['in_discards']) ? $info['in_discards'] : null,
                    'out_discards' => isset($info['out_discards']) ? $info['out_discards'] : null,
                    'in_octets' => isset($info['in_octets']) ? $info['in_octets'] : null,
                    'out_octets' => isset($info['out_octets']) ? $info['out_octets'] : null,
                    'in_multicast_pkts' => isset($info['in_multicast_pkts']) ? $info['in_multicast_pkts'] : null,
                    'out_multicast_pkts' => isset($info['out_multicast_pkts']) ? $info['out_multicast_pkts'] : null,
                    'in_broadcast_pkts' => isset($info['in_broadcast_pkts']) ? $info['in_broadcast_pkts'] : null,
                    'out_broadcast_pkts' => isset($info['out_broadcast_pkts']) ? $info['out_broadcast_pkts'] : null,
                ];
            }
        }
        if (in_array('pon_onts_vendor', $modules) && $this->isModuleAllowed('ont_info', 'pon_onts_vendor')) {
            if ($dt = $responses->getFirstByModule('pon_onts_vendor')->getData()) {
                $deviceInfo = $dt[0];
                $data['vendor'] = $deviceInfo;
            }
        }
        if (in_array('onu_ip_host', $modules) && $this->isModuleAllowed('ont_info', 'onu_ip_host')) {
            if ($dt = $responses->getFirstByModule('onu_ip_host')->getData()) {
                $data['ip_host'] = isset($dt['data']) ? $dt['data'] : null;
            }
        }
        if (in_array('fdb', $modules) && $this->isModuleAllowed('ont_info', 'fdb')) {
            $fdb = $responses->getFirstByModule('fdb');
            if ($fdb->getError()) {
                $data['fdb'] = null;
            } elseif ($dt = $fdb->getData()) {
                $data['fdb'] = array_map(function ($e) {
                    unset($e['interface']);
                    if (!isset($e['uni'])) $e['uni'] = null;
                    if (!isset($e['status'])) $e['status'] = null;
                    if (!isset($e['vlan_id'])) $e['vlan_id'] = null;
                    return $e;
                }, $dt);
            }
        }
        if (in_array('pon_onts_mac_addr', $modules) && $this->isModuleAllowed('ont_info', 'pon_onts_mac_addr') &&
            $dt = $responses->getFirstByModule('pon_onts_mac_addr')->getData()) {
            $data['ident']['value'] = $dt[0]['mac_address'];
            $data['ident']['type'] = 'MAC';
        }
        if (in_array('pon_onts_serial', $modules) && $this->isModuleAllowed('ont_info', 'pon_onts_serial') &&
            $dt = $responses->getFirstByModule('pon_onts_serial')->getData()) {
            $data['ident']['value'] = $dt[0]['serial'];
            $data['ident']['type'] = 'SN';
        }

        if (in_array('interface_descriptions', $modules) && $this->isModuleAllowed('ont_info', 'interface_descriptions') &&
            $dt = $responses->getFirstByModule('interface_descriptions')->getData()) {
            $data['description'] = $dt[0]['description'];
        }

        if (in_array('pon_onts_reasons', $modules) && $this->isModuleAllowed('ont_info', 'pon_onts_reasons') &&
            $dt = $responses->getFirstByModule('pon_onts_reasons')->getData()) {
            $statusDetailed = $dt[0];
            $historyTable = null;
            if (isset($statusDetailed['history_table']) && $statusDetailed['history_table'] && isset($statusDetailed['history_table']['logs'])) {
                $historyTable = $statusDetailed['history_table']['logs'];
            }
            $data['reasons']['history_table'] = $historyTable;
            $data['reasons']['last_change_since'] = isset($statusDetailed['last_change_since']) ? $statusDetailed['last_change_since'] : null;
            $data['reasons']['last_reg'] = isset($statusDetailed['last_reg']) ? $statusDetailed['last_reg'] : null;
            $data['reasons']['last_reg_since'] = isset($statusDetailed['last_reg_since']) ? $statusDetailed['last_reg_since'] : null;
            $data['reasons']['last_dereg'] = isset($statusDetailed['last_dereg']) ? $statusDetailed['last_dereg'] : null;
            $data['reasons']['last_dereg_since'] = isset($statusDetailed['last_dereg_since']) ? $statusDetailed['last_dereg_since'] : null;
            $data['reasons']['last_down_reason'] = isset($statusDetailed['last_down_reason']) ? $statusDetailed['last_down_reason'] : null;
        }

        if (in_array('pon_onts_down_history', $modules) && $this->isModuleAllowed('ont_info', 'pon_onts_down_history') &&
            $dt = $responses->getFirstByModule('pon_onts_down_history')->getData()) {
            $statusDetailed = $dt[0];
            $historyTable = null;
            if (isset($statusDetailed['logs'])) {
                $historyTable = $statusDetailed['logs'];
            }
            $data['reasons']['history_table'] = $historyTable;
        }

        if (in_array('pon_onts_optical', $modules) && $this->isModuleAllowed('ont_info', 'pon_onts_optical') &&
            $dt = $responses->getFirstByModule('pon_onts_optical')->getData()) {
            $data['optical'] = [
                'olt_rx' => isset($dt[0]['olt_rx']) ? $dt[0]['olt_rx'] : null,
                'olt_tx' => isset($dt[0]['olt_tx']) ? $dt[0]['olt_tx'] : null,
                'rx' => isset($dt[0]['rx']) ? $dt[0]['rx'] : null,
                'tx' => isset($dt[0]['tx']) ? $dt[0]['tx'] : null,
                'voltage' => isset($dt[0]['voltage']) ? $dt[0]['voltage'] : null,
                'temp' => isset($dt[0]['temp']) ? $dt[0]['temp'] : null,
                'distance' => isset($dt[0]['distance']) ? $dt[0]['distance'] : null,
            ];
        }

        $UNIs = [];
        if (in_array('uni_interfaces_status', $modules) && $this->isModuleAllowed('ont_info', 'uni_interfaces_status') && $dt = $responses->getFirstByModule('uni_interfaces_status')->getData()) {
            foreach ($dt[0]['unis'] as $uni) {
                if (!isset($uni['num'])) continue;
                $UNIs[$uni['num']] = $uni;
            }
        }
        if (in_array('uni_interfaces_vlans', $modules) && $this->isModuleAllowed('ont_info', 'uni_interfaces_vlans') && $UNIs && $dt = $responses->getFirstByModule('uni_interfaces_vlans')->getData()) {
            foreach ($dt[0]['unis'] as $uni) {
                if (!isset($uni['num'])) continue;
                foreach ($uni as $key => $v) {
                    if ($key == 'num') continue;
                    $UNIs[$uni['num']][$key] = $v;
                }
            }
        }
        $data['uni'] = array_values($UNIs);
        return $data;
    }

    function getLastMeta()
    {
        return $this->meta;
    }

    public function rebootOnu($onu)
    {
        return $this->callCore('ctrl_ont_reboot', ['interface' => $onu], 'device');
    }

    public function resetOnu($onu)
    {
        return $this->callCore('ctrl_ont_reset', ['interface' => $onu], 'device');
    }

    public function ctrlOnuDisable($onu, $state = 'enable')
    {
        return $this->callCore('ctrl_ont_disable', ['interface' => $onu, 'state' => $state], 'device');
    }

    public function ctrlOnuDescription($onu, $description = '')
    {
        return $this->callCore('ctrl_ont_descr', ['interface' => $onu, 'description' => $description], 'device');
    }

    public function ctrlChangeAdminState($onu, $uniNum, $state)
    {
        return $this->callCore('ctrl_ont_uni_admin_state', ['interface' => $onu, 'num' => $uniNum, 'state' => $state], 'device');
    }


    public function deregOnu($onu)
    {
        $iface = $this->parseInterface($onu);
        // ctrl_ont_delete now returns its own step-by-step transcript (same
        // {command, output, success} shape multi_console_command already
        // uses for macros/registration) instead of nothing — shaped the
        // same way here so the frontend can show it with the same terminal
        // display it already has for those.
        $commands = $this->callCore('ctrl_ont_delete', ['interface' => $onu], 'device');
        try {
            $this->deviceInterfaces->delete($this->deviceInterfaces->getByDeviceAndKey($this->device, $iface['id']));
        } catch (\Exception $e) {
            $this->logger->error("failed to delete interface from system storage: " . $e->getMessage());
        }
        return ['commands' => $commands];
    }

    // "Clear PON" — bulk-deletes every ONT on a PON port with one
    // device-side command instead of looping per-ONT (ctrl_ont_delete_port
    // vs. deregOnu's ctrl_ont_delete). By design this does NOT fall back
    // to the per-ONT path for ONTs the device rejects (e.g. ones still
    // carrying service-ports) — it reports exactly what the device
    // reports, same as ctrl_ont_delete_port itself does. Since a bulk
    // response doesn't enumerate which individual ONTs succeeded, this
    // can't update device_interfaces per-record the way deregOnu does;
    // instead it invalidates this device's ONT-tree cache so the next
    // read is a genuine poll that reconciles whatever the device's real
    // state ends up being.
    public function clearPonPort($port)
    {
        return $this->callCore('ctrl_ont_delete_port', ['interface' => $port], 'device');
    }

    public function parseInterface($interface)
    {
        try {
            return $this->callCore('parse_interface', ['interface' => $interface], 'store');
        } catch (\Exception $e) {
            return $this->callCore('parse_interface', ['interface' => $interface], 'device');
        }
    }

    /**
     * @param $moduleName
     * @param array $params
     * @param string $from
     * @return mixed
     * @throws \WCAA\Storage\Exceptions\RecordNotFoundException
     */
    protected function callCore($moduleName, $params = [], $from = 'cache')
    {
        $request = (new Request())
            ->setModule($moduleName)
            ->setDevice($this->device)
            ->setArguments($params);
        $responses = $this->processRequests([$request], $from);
        $resp = $responses->getFirstByModule($moduleName);
        if ($resp->getError()) {
            throw new SwitcherCoreException($resp->getError()['message']);
        }
        return $resp->getData();
    }

    function getInterfacesList(): array
    {
        $response = [];
        $onts = $this->callCore('pon_onts_status', [], 'device');
        foreach ($onts as $ont) {
            $response[$ont['interface']['id']] = $ont['interface'];
            $response[$ont['interface']['id']]['description'] = '';
        }

        if ($this->isModuleSupported('interfaces_list')) {
            $ifaces = $this->callCore('interfaces_list', [], 'device');
            foreach ($ifaces as $port) {
                if ($port['type'] === 'PON') continue;
                $response[$port['id']] = $port;
                $response[$port['id']]['description'] = '';
            }
        }

        $ports = $this->callCore('pon_ports_list', [], 'device');
        foreach ($ports as $port) {
            $response[$port['id']] = $port;
            $response[$port['id']]['description'] = '';
        }

        $descriptions = $this->callCore('interface_descriptions', ['interface_type' => 'PHYSICAL'], 'device');
        foreach ($descriptions as $descr) {
            if (!isset($response[$descr['interface']['id']])) continue;
            $response[$descr['interface']['id']]['description'] = $descr['description'];
        }
        $descriptions = $this->callCore('interface_descriptions', ['interface_type' => 'ONU'], 'device');
        foreach ($descriptions as $descr) {
            if (!isset($response[$descr['interface']['id']])) continue;
            $response[$descr['interface']['id']]['description'] = $descr['description'];
        }
        return array_values($response);
    }

    function getSystemInfo()
    {
        try {
            return $this->callCore('system', [], 'device');
        } catch (\Throwable $e) {
            return $this->callCore('system', [], 'store');
        }
    }


    function getResources($from = 'cache')
    {
        $data = [
            'cpu' => null,
            'disk' => null,
            'interfaces' => null,
            'cards' => null,
            'memory' => null,
            'temperatures' => null,
        ];
        try {
            if (in_array('sys_resources', $this->getSupportedModules())) {
                $response = $this->callCore('sys_resources', ['load_only' => 'memory,cpu'], $from);
                foreach ($response as $key => $res) {
                    $data[$key] = $res;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error getting resources from {$this->device->getIp()}: {$e->getMessage()}");
        }
        try {
            if (in_array('sys_temp', $this->getSupportedModules())) {
                $response = $this->callCore('sys_temp', [], $from);
                $data['temperatures'] = $response;
            }
        } catch (\Exception $e) {
            $this->logger->error("Error getting temperatures from {$this->device->getIp()}: {$e->getMessage()}");
        }
        return $data;
    }

    function getFdbTable()
    {
        $data = $this->callCore('fdb', [], 'device');
        return array_values($data);
    }

    function getInterfaceStatuses()
    {
        $RESPONSE = [];
        $data = $this->callCore('pon_onts_status', [], 'device');
        try {
            foreach ($data as $e) {
                if (!isset($e['interface'])) {
                    $this->logger->error("Returned core response not contain array with interface field", ['response' => $e]);
                    throw new \Exception("Returned core response not contain array with interface field. See log for get response");
                }
            }
            $RESPONSE = array_map(function ($e) {
                return [
                    'interface' => $e['interface'],
                    'status' => $e['status'],
                    'bind_status' => isset($e['bind_status']) ? $e['bind_status'] : null,
                    'admin_state' => isset($e['admin_state']) ? $e['admin_state'] : null,
                    'nway_speed' => null,
                ];
            }, $data);
        } catch (\Throwable $e) {
            $this->logger->error("error get mapped interfaces: {$e->getMessage()}", ['mapped' => $data]);
            throw $e;
        }
        if ($this->isModuleSupported('link_info')) {
            $data = $this->callCore('link_info', [], 'device');
            try {
                foreach ($data as $e) {
                    if (!isset($e['interface'])) {
                        $this->logger->error("Returned core response not contain array with interface field", ['response' => $e]);
                        throw new \Exception("Returned core response not contain array with interface field. See log for get response");
                    }
                }
                $RESPONSE = array_merge($RESPONSE, array_map(function ($e) {
                    return [
                        'interface' => $e['interface'],
                        'status' => $e['oper_status'],
                        'admin_state' => isset($e['admin_state']) ? $e['admin_state'] : null,
                        'nway_speed' => $e['nway_status'],
                    ];
                }, $data));
            } catch (\Throwable $e) {
                $this->logger->error("error get mapped interfaces: {$e->getMessage()}", ['mapped' => $data]);
                throw $e;
            }
        }
        return $RESPONSE;
    }

    function getOntIdentification()
    {
        $response = [];
        if (in_array('pon_onts_mac_addr', $this->getSupportedModules())) {
            $response = array_merge($response, array_map(function ($e) {
                return [
                    'interface' => $e['interface'],
                    'type' => 'MAC',
                    'ident' => $e['mac_address'],
                ];
            }, $this->callCore('pon_onts_mac_addr', [], 'device')));
        }
        if (in_array('pon_onts_serial', $this->getSupportedModules())) {
            $response = array_merge($response, array_map(function ($e) {
                return [
                    'interface' => $e['interface'],
                    'type' => 'SERIAL',
                    'ident' => $e['serial'],
                ];
            }, $this->callCore('pon_onts_serial', [], 'device')));
        }
        return $response;
    }

    function getOntVendorInfo()
    {
        $response = [];
        if (in_array('pon_onts_vendor', $this->getSupportedModules())) {
            $response = $this->callCore('pon_onts_vendor', [], 'device');
        }
        return $response;
    }

    function getOpticalStrength()
    {
        $optical = array_map(function ($e) {
            return [
                'interface' => $e['interface'],
                'rx' => isset($e['rx']) ? $e['rx'] : null,
                'tx' => isset($e['tx']) ? $e['tx'] : null,
                'voltage' => $e['voltage'],
                'temp' => $e['temp'],
                'distance' => isset($e['distance']) ? $e['distance'] : null,
                'attenuation' => null,
                'olt_rx' => isset($e['olt_rx']) ? $e['olt_rx'] : null,
                'olt_tx' => isset($e['olt_tx']) ? $e['olt_tx'] : null,
            ];
        }, $this->callCore('pon_onts_optical', [], 'device'));
        return $optical;
    }

    function getCounters()
    {
        return array_merge(
            $this->callCore('interface_counters', ['interface_type' => 'PHYSICAL'], 'device'),
            $this->callCore('interface_counters', ['interface_type' => 'ONU'], 'device'),
        );
    }

    protected $_coreMeta;

    function getDeviceCoreMeta()
    {
        if ($this->_coreMeta) {
            return $this->_coreMeta;
        }
        try {
            $system = $this->callCore('system', [], 'cache');
        } catch (\Exception $e) {
            $system = $this->callCore('system', [], 'store');
        }
        if (!isset($system['meta'])) {
            throw new \Exception("Error get modules list from system");
        }
        $this->_coreMeta = $system['meta'];
        return $this->_coreMeta;
    }

    function getSupportedModules()
    {
        if ($this->_coreMeta) {
            return $this->_coreMeta['modules'];
        }
        return $this->getDeviceCoreMeta()['modules'];
    }

    function isModuleSupported($moduleName)
    {
        return in_array($moduleName, $this->getSupportedModules());
    }

    protected function isModuleAllowed($type, $moduleName)
    {
        $ontInfo = [];
        $mustBeChecked = false;
        if (!$this->isModuleSupported($moduleName)) {
            return false;
        }
        $loadModules = $this->device->getModel()->getParamByName('modules_loading');
        if ($loadModules && isset($loadModules[$type])) {
            $ontInfo = $loadModules[$type];
            $mustBeChecked = true;
        }
        $loadModules = $this->device->getParams();
        if ($loadModules && isset($loadModules['modules_loading']) && isset($loadModules['modules_loading'][$type])) {
            $ontInfo = $loadModules['modules_loading'][$type];
            $mustBeChecked = true;
        }
        if (!is_array($ontInfo)) {
            throw new \Exception("Incorrect modules_loading param on device or model");
        }
        if (!$mustBeChecked) {
            return true;
        }
        return in_array($moduleName, $ontInfo);
    }

    /**
     * @param ResponsesWrapper $response
     * @return array
     */
    protected function generateMeta(ResponsesWrapper $response)
    {
        $response = $response->filterByDevice($this->device);
        $meta = [];
        foreach ($response->getAllResponses() as $resp) {
            $meta[$resp->getModule()] = [
                'time' => $resp->getTime(),
                'source' => $resp->getSource(),
                'from_cache' => $resp->getSource() !== 'device',
                'hash' => $resp->getHash(),
                'error' => $resp->getError(),
                'device_ip' => $this->device->getIp(),
                'spent' => $resp->getSpent() ? round($resp->getSpent(), 3) : null,
            ];
        }
        return $meta;
    }

    protected function processRequests($requests, $from = 'cache', $concurrency = false)
    {
        $responses = [];
        switch ($from) {
            case 'device':
                $responses = $this->core->fromDevice($requests, $concurrency);
                $this->responseToPollerWrapper->process($responses);
                break;
            case 'cache':
                $responses = $this->core->fromCache($requests);
                break;
            case 'store':
                $responses = $this->core->fromStore($requests);
                break;
            default:
                throw new \Exception("Incorrect source from - {$from}");
        }
        $this->meta = array_merge($this->meta, $this->generateMeta($responses));
        return $responses;
    }

    function getPonPortLoadingStat()
    {
        $ponPorts = [];
        foreach ($this->getPonInterfacesList() as $port) {
            $ponPorts[$port['id']] = [
                'interface' => $port,
                'max' => $port['pon_port_size'],
                'current' => 0,
            ];
        }
        foreach ($this->getOntsListInfo() as $ont) {
            if ($ont['interface']['parent'] && isset($ponPorts[$ont['interface']['parent']])) {
                $ponPorts[$ont['interface']['parent']]['current']++;
            }
        }
        return $ponPorts;
    }

    function getDeviceStats()
    {
        $data = [
            'unregistered_onts' => null,
            'events' => null,
            'links' => null,
        ];
        try {
            if ($this->isModuleSupported('unregistered_onts')) {
                $dt = $this->getUnregisteredOnts('store');
                $data['unregistered_onts'] = is_array($dt) ? count($dt) : 0;
            }
        } catch (\Throwable $e) {
        }

        try {
            if (App::getInstance()->getComponentInjector()->isComponentEnabled('events') &&
                $event = App::getInstance()->getComponentInjector()->getController('events')) {
                /**
                 * @var $event Controller
                 */
                $events = $event->getEventsByFilter((new EventFilter())
                    ->setDevice($this->device)
                    ->setUser($this->user)
                    ->setOnlyNotResolved(true)
                );
                $data['events'] = is_array($events) ? count($events) : 0;
            }
        } catch (\Throwable $e) {
        }
        try {
            if (App::getInstance()->getComponentInjector()->isComponentEnabled('links') &&
                $links = App::getInstance()->getComponentInjector()->getController('links')) {
                /**
                 * @var $links \WCC\Links\Controllers\Controller
                 */
                $linksByDevice = $links->getByDevice($this->device);
                $data['links'] = is_array($linksByDevice) ? count($linksByDevice) : 0;
            }
        } catch (\Throwable $e) {
        }

        return $data;
    }

    function resetPort($interface = '')
    {
        $data = $this->callCore('ctrl_reset_port', ['interface' => $interface], 'device');
        return $data;
    }

    function changePortDescription($interface, $description = '')
    {
        if ($this->device->getParamByName('disable_save_description_on_physical_ifaces')) {
            $iface = $this->callCore('parse_interface', ['interface' => $interface]);
            $storedIface = $this->deviceInterfaces->getByDeviceAndKey($this->device, $iface['id']);
            $this->deviceInterfaces->update($storedIface->setDescription($description));
            return $storedIface->getAsArray();
        }
        $data = $this->callCore('ctrl_port_descr', ['interface' => $interface, 'description' => $description], 'device');
        return $data;
    }

    public function diagSfpInterface($interface, $from = 'device')
    {
        $resp = $this->callCore('sfp_diag', ['interface' => $interface], $from);
        if ($resp->getError()) {
            throw new SwitcherCoreException($resp->getError()['message']);
        }
        if (isset($resp->getData()[0])) {
            return $resp->getData()[0];
        }
        throw new SwitcherCoreException("Empty response for sfp_diag");
    }

    public function diagSfpOpticalInterface($interface, $from = 'device')
    {
        $resp = $this->callCore('sfp_optical', ['interface' => $interface], $from);
        if ($resp->getError()) {
            throw new SwitcherCoreException($resp->getError()['message']);
        }
        if (isset($resp->getData()[0])) {
            return $resp->getData()[0];
        }
        return null;
    }

    public function sfpOpticalInfo($from = 'device')
    {
        if(!in_array('sfp_optical', $this->getSupportedModules())) {
            return [];
        }
        $resp = $this->callCore('sfp_optical', [], $from);
        if (isset($resp)) {
            return $resp;
        }
        return [];
    }

    /**
     *
     * Возвращает массив в виде
     *   [
     *      '1' => [
     *                'interface' => [id=>, name=>],
     *                '<module_name>' => ['data' => <module_data - any>, 'error' => <error - string> // В зависимости от вызываемых модулей, поля будут соответствующие
     *             ]
     *   ]
     *
     * @param $interfaces array Должен быть массив из ID интерфейсов
     * @param $modules array Должен быть массив из модулей
     * @return array
     */
    function callModulesByInterfaces($interfaces, $modules, $from = 'cache')
    {
        $requests = [];
        $ifaceResponses = [];
        foreach ($interfaces as $interface) {
            foreach ($modules as $module) {
                $requests[] = (new Request())->setModule($module)->setDevice($this->device)->setArguments(['interface' => $interface]);
                $ifaceResponses[$interface][$module] = [
                    'data' => null,
                    'error' => null,
                ];
            }
        }
        $responses = $this->processRequests($requests, $from);
        foreach ($responses->getAllResponses() as $resp) {
            $ifaceInfo = $resp->getData();
            $iface = isset($ifaceInfo[0]['interface']) ? $ifaceInfo[0]['interface'] : null;
            unset($ifaceInfo[0]['interface']);
            $ifaceResponses[$resp->getArguments()['interface']]['interface'] = $iface;
            $ifaceResponses[$resp->getArguments()['interface']][$resp->getModule()] = [
                'data' => isset($ifaceInfo[0]) ? $ifaceInfo[0] : null,
                'error' => $resp->getError(),
            ];
        }
        return $ifaceResponses;
    }
}