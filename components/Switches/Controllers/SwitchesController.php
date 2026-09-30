<?php


namespace WCC\Switches\Controllers;


use Monolog\Logger;
use WCAA\App;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Poller\Interfaces\PollerCountersInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerFdbInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerLldpInfoInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceListInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerResourcesInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSfpOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSystemInterface;
use WCAA\Infrastructure\Poller\ResponseToPollerWriter;
use WCAA\Interfaces\ControllerInterface;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\ResponsesWrapper;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Events\Controllers\Controller;
use WCC\Events\Models\EventFilter;

/**
 * Class Controller
 * @package WCC\Switches
 */
class SwitchesController extends AbstractComponentController
    implements PollerSystemInterface,
    PollerFdbInterface,
    ControllerInterface,
    PollerInterfaceStatusInterface,
    PollerInterfaceListInterface,
    PollerCountersInterface,
    PollerResourcesInterface,
    PollerSfpOpticalStrengthInterface,
    PollerLldpInfoInterface
{

    /**
     * @var array
     */
    private $meta;

    /**
     * @var SwitcherCore
     */
    protected $switcher;


    /**
     * @Inject
     * @var ResponseToPollerWriter
     */
    protected $responseToPollerWrapper;

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

    public function __construct(ComponentInjector $componentInjector, Logger $logger, SwitcherCore $switcher, DeviceInterfaceStorage $devIface)
    {
        $this->switcher = $switcher;
        $this->deviceInterfaces = $devIface;
        parent::__construct($componentInjector, $logger);
    }

    public function setUser(User $user)
    {
        $this->user = $user;
        $this->logger->info("Setted user - {$user->getName()}", $user->getAsArray());
        $this->switcher = $this->switcher->setUser($user);
        return $this;
    }

    public function diagCableInterface($interface, $from = 'device')
    {
        $resp = $this->callCore('cable_diag', ['interface' => $interface], $from);
        if ($resp->getError()) {
            throw new SwitcherCoreException($resp->getError()['message']);
        }
        if (isset($resp->getData()[0])) {
            return $resp->getData()[0];
        }
        return null;
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
        $resp = $this->callCore('sfp_optical',[], $from);
        if ($resp->getError()) {
            throw new SwitcherCoreException($resp->getError()['message']);
        }
        if ( $resp->getData()) {
            return $resp->getData();
        }
        return [];
    }

    public function getVlans($from = 'cache')
    {
        $requests[] = $this->_newRequest('vlans', []);
        $requests[] = $this->_newRequest('interfaces_list', []);
        $requests[] = $this->_newRequest('vlans_by_port', []);

        switch ($from) {
            case 'device':
                $responses = $this->switcher->fromDevice($requests, false);
                break;
            case 'cache':
                $responses = $this->switcher->fromCache($requests);
                break;
            case 'store':
                $responses = $this->switcher->fromStore($requests);
                break;
            default:
                throw new \InvalidArgumentException("From with name '$from' not supported");
        }
        $responses = $responses->filterByDevice($this->device);
        $this->generateMeta($responses);

        $vlans = $responses->getFirstByModule('vlans');
        if ($vlans->getError()) {
            throw new SwitcherCoreException($vlans->getError()['message']);
        }
        $vlByPorts = $responses->getFirstByModule('vlans_by_port');
        if ($vlByPorts->getError()) {
            throw new SwitcherCoreException($vlByPorts->getError()['message']);
        }
        return [
            'vlans' => $vlans->getData(),
            'vlans_by_port' => $vlByPorts->getData(),
        ];
    }

    public function getLinkInfo($from = 'device', $interface = null)
    {
        return $this->callCore('link_info', ['interface' => $interface], $from)->getData();
    }

    public function setDevice(Device $device)
    {
        $this->_coreMeta = null;
        $this->meta = [];
        $this->device = $device;
        $this->logger->info("Setted device - {$device->getIp()}", $device->getAsArray());
        return $this;
    }

    public function getInterfaceFullInfo($from, $interface = null, $loadOnly = [])
    {
        $RESPONSE = [];
        $requests = [];
        $modules = $this->getSupportedModules();
        if ($loadOnly) {
            foreach ($modules as $key => $moduleName) {
                if (!in_array($moduleName, $loadOnly)) {
                    unset($modules[$key]);
                }
            }
            $modules = array_values($modules);
        }

        if (in_array('interfaces_list', $modules)) $requests[] = $this->_newRequest('interfaces_list', ['interface' => $interface]);
        if (!$this->isBdcomSwitch() && in_array('fdb', $modules)) $requests[] = $this->_newRequest('fdb', ['interface' => $interface]);
        if (in_array('link_info', $modules)) $requests[] = $this->_newRequest('link_info', ['interface' => $interface]);
        if (in_array('errors', $modules)) $requests[] = $this->_newRequest('errors', ['interface' => $interface]);
        if (in_array('rmon', $modules)) $requests[] = $this->_newRequest('rmon', ['interface' => $interface]);
        if (in_array('interface_counters', $modules)) $requests[] = $this->_newRequest('interface_counters', ['interface' => $interface]);
        if (in_array('interface_descriptions', $modules)) $requests[] = $this->_newRequest('interface_descriptions', ['interface' => $interface]);
        if (in_array('vlans_by_port', $modules)) $requests[] = $this->_newRequest('vlans_by_port', ['interface' => $interface]);
        if (in_array('sfp_optical', $modules)) $requests[] = $this->_newRequest('sfp_optical', ['interface' => $interface]);
        if ($interface && in_array('sfp_media', $modules)) $requests[] = $this->_newRequest('sfp_media', ['interface' => $interface]);

        if ($this->device->getParamByName('enable_auto_cable_diag')) {
            if (in_array('cable_diag', $modules)) $requests[] = $this->_newRequest('cable_diag', ['interface' => $interface]);
        }

        switch ($from) {
            case 'device':
                $responses = $this->switcher->fromDevice($requests, false);
                break;
            case 'cache':
                $responses = $this->switcher->fromCache($requests);
                break;
            case 'store':
                // $catchErrors=true: this batches MANY modules in one call
                // (interfaces_list, fdb, sfp_optical, counters, ...) and
                // fromStore()'s default ($catchErrors=false) throws the
                // instant ANY ONE of them isn't in cache — confirmed live:
                // an OLT with no cached `fdb` entry was taking down this
                // WHOLE endpoint with a 500, hiding perfectly good
                // sfp_optical/counters/description data for every other
                // module on the same device. Every module this method
                // reads is already defensively guarded with `$dt->getError()`
                // checks below (fdb explicitly treats an error as "no
                // data" already) — this just makes fromStore() actually
                // hand back that per-module error instead of aborting the
                // whole batch.
                $responses = $this->switcher->fromStore($requests, true, true);
                break;
            default:
                throw new \InvalidArgumentException("From with name '$from' not supported");
        }
        $responses = $responses->filterByDevice($this->device);
        $this->responseToPollerWrapper->process($responses);
        $this->generateMeta($responses);

        if ($dt = $responses->getFirstByModule('interfaces_list')) {
            if ($dt->getError()) {
                throw new \Exception($dt->getError()['message']);
            }
            foreach ($dt->getData() as $i) {
                $RESPONSE[$i['id']]['interface'] = $i;
                $RESPONSE[$i['id']]['fdb'] = [];
                $RESPONSE[$i['id']]['link_info'] = null;
                $RESPONSE[$i['id']]['errors'] = null;
                $RESPONSE[$i['id']]['cable_diag'] = null;
                $RESPONSE[$i['id']]['optical'] = null;
                $RESPONSE[$i['id']]['sfp_media'] = null;
                $RESPONSE[$i['id']]['rmon'] = null;
                $RESPONSE[$i['id']]['counters'] = null;
                $RESPONSE[$i['id']]['description'] = null;
            }
        } else {
            throw new \Exception("Required interfaces_list module");
        }
        if (in_array('fdb', $modules)) {
            if ($dt = $responses->getFirstByModule('fdb')) {
                $fdbData = $dt->getError() ? null : $dt->getData();
                if (is_array($fdbData)) {
                    foreach ($fdbData as $fdb) {
                        // Guard against a module reporting an interface id
                        // that `interfaces_list` itself doesn't know about
                        // (confirmed live: BDCOM's own counters/description
                        // walks can include a port interfaces_list's port
                        // discovery missed) — without this, such a row
                        // would auto-vivify a new $RESPONSE entry lacking
                        // 'interface', crashing the usort below.
                        if (!isset($RESPONSE[$fdb['interface']['id']])) continue;
                        $RESPONSE[$fdb['interface']['id']]['fdb'][] = [
                            'vlan_id' => $fdb['vlan_id'],
                            'status' => $fdb['status'],
                            'mac_address' => $fdb['mac_address'],
                        ];
                    }
                } else {
                    foreach ($RESPONSE as $id=>$dt) {
                        $RESPONSE[$id]['fdb'] = null;
                    }
                }
            } else {
                foreach ($RESPONSE as $id=>$dt) {
                    $RESPONSE[$id]['fdb'] = null;
                }
            }
        }
        if (in_array('link_info', $modules)) {
            if ($dt = $responses->getFirstByModule('link_info')->getData()) {
                foreach ($dt as $port) {
                    $id = $port['interface']['id'];
                    if (!isset($RESPONSE[$id])) continue;
                    unset($port['interface']);
                    $RESPONSE[$id]['link_info'][] = $port;
                }
            }
        }
        if (in_array('errors', $modules)) {
            if ($dt = $responses->getFirstByModule('errors')->getData()) {
                foreach ($dt as $port) {
                    $id = $port['interface']['id'];
                    if (!isset($RESPONSE[$id])) continue;
                    unset($port['interface']);
                    $RESPONSE[$id]['errors'] = $port;
                }
            }
        }
        if (in_array('interface_descriptions', $modules)) {
            if ($dt = $responses->getFirstByModule('interface_descriptions')->getData()) {
                foreach ($dt as $port) {
                    $id = $port['interface']['id'];
                    if (!isset($RESPONSE[$id])) continue;
                    unset($port['interface']);
                    $RESPONSE[$id]['description'] = $port;
                }
            }
        }
        if (in_array('interface_counters', $modules)) {
            if ($dt = $responses->getFirstByModule('interface_counters')->getData()) {
                foreach ($dt as $port) {
                    $id = $port['interface']['id'];
                    if (!isset($RESPONSE[$id])) continue;
                    unset($port['interface']);
                    $RESPONSE[$id]['counters'] = $port;
                }
            }
        }

        if ($this->device->getParamByName('enable_auto_cable_diag')) {
            if (in_array('cable_diag', $modules)) {
                if ($dt = $responses->getFirstByModule('cable_diag')->getData()) {
                    foreach ($dt as $port) {
                        $id = $port['interface']['id'];
                        if (!isset($RESPONSE[$id])) continue;
                        unset($port['interface']);
                        $RESPONSE[$id]['cable_diag'] = $port;
                    }
                }
            }
        }
        if (in_array('sfp_optical', $modules)) {
            if ($dt = $responses->getFirstByModule('sfp_optical')) {
                if (!$dt->getError()) {
                    foreach ($dt->getData() as $port) {
                        $id = $port['interface']['id'];
                        if (!isset($RESPONSE[$id])) continue;
                        unset($port['interface']);
                        $RESPONSE[$id]['optical'] = $port;
                    }
                }
            }
        }
        if ($interface && in_array('sfp_media', $modules)) {
            if ($dt = $responses->getFirstByModule('sfp_media')) {
                if (!$dt->getError()) {
                    foreach ($dt->getData() as $port) {
                        $id = $port['interface']['id'];
                        if (!isset($RESPONSE[$id])) continue;
                        unset($port['interface']);
                        $RESPONSE[$id]['sfp_media'] = $port;
                    }
                }
            }
        }
        if (in_array('vlans_by_port', $modules)) {
            if ($dt = $responses->getFirstByModule('vlans_by_port')->getData()) {
                foreach ($dt as $d) {
                    if (!isset($RESPONSE[$d['interface']['id']])) continue;
                    foreach ($d['untagged'] as $vl) {
                        $RESPONSE[$d['interface']['id']]['vlans'][] = [
                            'id' => $vl['id'],
                            'name' => $vl['name'],
                            'type' => 'untagged',
                        ];
                    }
                    foreach ($d['tagged'] as $vl) {
                        $RESPONSE[$d['interface']['id']]['vlans'][] = [
                            'id' => $vl['id'],
                            'name' => $vl['name'],
                            'type' => 'tagged',
                        ];
                    }
                }
            }
        }
        if (in_array('rmon', $modules)) {
            if ($dt = $responses->getFirstByModule('rmon')->getData()) {
                foreach ($dt as $rmon) {
                    $id = $rmon['interface']['id'];
                    if (!isset($RESPONSE[$id])) continue;
                    unset($rmon['interface']);
                    $RESPONSE[$id]['rmon'] = $rmon;
                }
            }
        }
        $resp = array_values($RESPONSE);
        usort($resp, function ($a, $b) {
            return strcmp($a["interface"]["name"], $b["interface"]["name"]);
        });
        return $resp;
    }

    public function getLastMeta()
    {
        return $this->meta;
    }

    public function parseInterface($interface)
    {
        try {
            $data = $this->callCore('parse_interface', ['interface' => $interface], 'store');
        } catch (\Exception $e) {
            $data = $this->callCore('parse_interface', ['interface' => $interface], 'device');
        }
        if ($data->getError()) {
            throw new SwitcherCoreException($data->getError()['message']);
        }
        $this->meta = $data->getMeta();
        return $data->getData();
    }

    public function getFdbTable()
    {
        if ($this->isBdcomSwitch()) {
            return [];
        }

        $fdbs = $this->callCore('fdb', [], 'device');
        if ($fdbs->getError()) {
            throw new SwitcherCoreException($fdbs->getError()['message']);
        }
        $mapped = array_map(function ($e) {
            if (!$e['vlan_id']) $e['vlan_id'] = 0;
            return $e;
        }, $fdbs->getData());
        return array_values($mapped);
    }

    private function isBdcomSwitch(): bool
    {
        return $this->device
            && $this->device->getModel()
            && strtolower($this->device->getModel()->getVendor()) === 'bdcom';
    }

    public function getInterfacesList(): array
    {
        $response = [];
        $data = $this->callCore('interfaces_list', [], 'device');
        if (!$data->getError()) {
            foreach ($data->getData() as $iface) {
                $response[$iface['id']] = $iface;
            }
        } else {
            throw new SwitcherCoreException($data->getError()['message']);
        }

        if (in_array('interface_descriptions', $this->getSupportedModules())) {
            $descriptions = $this->callCore('interface_descriptions', [], 'device');
            if (!$descriptions->getError()) {
                foreach ($descriptions->getData() as $description) {
                    if (!isset($response[$description['interface']['id']])) continue;
                    $response[$description['interface']['id']]['description'] = $description['description'];
                }
            }
        }
        return array_values($response);
    }

    public function getSystemInfo()
    {
        $resp = $this->callCore('system', [], 'device');
        if ($resp->getError()) {
            throw new \Exception($resp->getError()['message']);
        }
        return $resp->getData();
    }

    function getErrors()
    {
        if (!in_array('errors', $this->getSupportedModules())) {
            return null;
        }
        $data = $this->callCore('errors', [], 'device');
        if ($err = $data->getError()) {
            throw new \Exception($err['message'], $err['code']);
        }
        return $data->getData();
    }

    function getRmonCounters()
    {
        if (!in_array('rmon', $this->getSupportedModules())) {
            return null;
        }
        $data = $this->callCore('rmon', [], 'device');
        if ($err = $data->getError()) {
            throw new \Exception($err['message'], $err['code']);
        }

        /**
         *         "stat_in_octets": 0,
         * "stat_in_undersize_pkts": 0,
         * "stat_in_oversize_pkts": 0,
         * "stat_in_fragments_pkts": 0,
         * "stat_in_crc_pkts": 0,
         * "stat_in_drop_pkts": 0,
         * "stat_in_jabber_pkts": 0,
         * "stat_out_octets": 0,
         * "stat_out_undersize_pkts": 0,
         * "stat_out_oversize_pkts": 0,
         * "stat_out_fragments_pkts": 0,
         * "stat_out_crc_pkts": 0,
         * "stat_out_drop_pkts": 0,
         * "stat_out_jabber": 0
         */
        return array_map(function ($e) {
            $array = [];
            foreach ($e as $name => $value) {
                switch ($name) {
                    case 'ether_stats_crc_align_errors':
                        $name = 'in_crc_pkts';
                        break;
                    case 'ether_stats_undersize_pkts':
                        $name = 'in_undersize_pkts';
                        break;
                    case 'ether_stats_oversize_pkts':
                        $name = 'in_oversize_pkts';
                        break;
                    case 'ether_stats_fragments':
                        $name = 'in_fragments_pkts';
                        break;
                    case 'ether_stats_jabber':
                        $name = 'in_jabber_pkts';
                        break;
                    case 'ether_stats_collisions':
                        $name = 'in_collision_pkts';
                        break;
                    case 'ether_stats_drop_events':
                        $name = 'in_drop_pkts';
                        break;
                }
                $array[$name] = $value;
            }
            return $array;
        }, $data->getData());
    }

    function getPortStatistic()
    {
        if (!in_array('interface_counters', $this->getSupportedModules())) {
            return null;
        }
        $data = $this->callCore('interface_counters', [], 'device');
        if ($err = $data->getError()) {
            throw new \Exception($err['message'], $err['code']);
        }
        return array_map(function ($e) {
            $array = [];
            foreach ($e as $name => $value) {
                $array[$name] = $value;
            }
            return $array;
        }, $data->getData());
    }

    function getCounters()
    {
        $response = [];
        if ($data = $this->getPortStatistic()) {
            foreach ($data as $d) {
                $response[$d['interface']['id']] = array_merge(isset($response[$d['interface']['id']]) ? $response[$d['interface']['id']] : [], $d);
            }
        }
        if ($data = $this->getErrors()) {
            foreach ($data as $d) {
                $response[$d['interface']['id']] = array_merge(isset($response[$d['interface']['id']]) ? $response[$d['interface']['id']] : [], $d);
            }
        }
        return $response;
    }

    /**
     * @param $moduleName
     * @param array $params
     * @param string $from
     * @return \WCAA\SwitcherCore\Response
     * @throws \WCAA\Storage\Exceptions\RecordNotFoundException
     */
    protected function callCore($moduleName, $params = [], $from = 'cache')
    {
        $request = (new Request())
            ->setModule($moduleName)
            ->setDevice($this->device)
            ->setArguments($params);
        switch ($from) {
            case 'device':
                $data = $this->switcher->fromDevice([$request]);
                break;
            case 'cache':
                $data = $this->switcher->fromCache([$request]);
                break;
            case 'store':
                $data = $this->switcher->fromStore([$request]);
                break;
            default:
                throw new \InvalidArgumentException("Type '$from' not supported");
        }

        return $data->getFirstByModule($moduleName);
    }

    /**
     * @param ResponsesWrapper $response
     */
    protected function generateMeta(ResponsesWrapper $response)
    {
        $response = $response->filterByDevice($this->device);
        $this->meta = [];
        foreach ($response->getAllResponses() as $resp) {
            $this->meta[$resp->getModule()] = [
                'time' => $resp->getTime(),
                'source' => $resp->getSource(),
                'from_cache' => $resp->getSource() !== 'device',
                'hash' => $resp->getHash(),
                'error' => $resp->getError(),
            ];
        }
    }

    function getInterfaceStatuses()
    {
        $responses = $this->switcher->fromDevice([
            (new Request())->setDevice($this->device)->setModule('link_info')
        ], false);
        $RESP = [];
        $resp = $responses->getFirstByModule('link_info');
        if ($resp->getError()) {
            throw new SwitcherCoreException($resp->getError()['message']);
        }
        foreach ($resp->getData() as $dat) {
            $RESP[] = [
                'interface' => $dat['interface'],
                'status' => $dat['oper_status'],
                'nway_status' => isset($dat['nway_status']) ? $dat['nway_status'] : null,
                'admin_state' => $dat['admin_state'] == 'Disabled' ? 'Disabled' : 'Enabled',
                'type' => isset($dat['type']) ? $dat['type'] : null,
            ];
        }
        return $RESP;
    }


    function getSupportedModules()
    {
        if ($this->_coreMeta) {
            return $this->_coreMeta['modules'];
        }
        return $this->getDeviceCoreMeta()['modules'];
    }

    /**
     * @see \WCAA\Infrastructure\Poller\Interfaces\PollerLldpInfoInterface
     */
    function getLldpInfo()
    {
        if (!in_array('lldp_info', $this->getSupportedModules())) {
            return null;
        }
        $dt = $this->callCore('lldp_info', [], 'device');
        if (!$dt || $dt->getError()) {
            throw new \Exception($dt ? $dt->getError()['message'] : "No response for lldp_info");
        }
        return $dt->getData();
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
        if (!isset($system->getData()['meta'])) {
            throw new \Exception("Error get modules list from system");
        }
        $this->_coreMeta = $system->getData()['meta'];
        return $this->_coreMeta;
    }

    function getResources($from = 'cache')
    {
        $this->meta = [];

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
                $this->meta['sys_resources'] = [
                    'time' => $response->getTime(),
                    'source' => $response->getSource(),
                    'from_cache' => $response->getSource() !== 'device',
                    'hash' => $response->getHash(),
                    'error' => $response->getError(),
                ];

                if (!$response->getError()) {
                    foreach ($response->getDataAsArray() as $key => $res) {
                        $data[$key] = $res;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error getting resources from {$this->device->getIp()}: {$e->getMessage()}");
        }
        try {
            if (in_array('sys_temp', $this->getSupportedModules())) {
                $response = $this->callCore('sys_temp', [], $from);
                $this->meta['sys_temp'] = [
                    'time' => $response->getTime(),
                    'source' => $response->getSource(),
                    'from_cache' => $response->getSource() !== 'device',
                    'hash' => $response->getHash(),
                    'error' => $response->getError(),
                ];
                if (!$response->getError()) {
                    $data['temperatures'] = $response->getDataAsArray();
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error getting temperatures from {$this->device->getIp()}: {$e->getMessage()}");
        }
        return $data;
    }

    function getDeviceStats()
    {
        $data = [
            'events' => null,
            'vlans' => null,
            'fdb' => null,
            'links' => null,
        ];
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

    private function _newRequest($module, $arguments = [])
    {
        return (new Request())
            ->setDevice($this->device)
            ->setModule($module)
            ->setArguments($arguments);
    }
}
