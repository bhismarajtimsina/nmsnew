<?php


namespace WCC\RouterOS\Controllers;

use SwitcherCore\Modules\BDcom\SfpOpticalInfo;
use WCAA\App;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Poller\Interfaces\PollerArpsInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerBgpSessionsInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerCountersInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceListInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerResourcesInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSfpOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSystemInterface;
use WCAA\Interfaces\ControllerInterface;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Events\Models\EventFilter;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;

/**
 * Class Controller
 * @package WCC\RouterOS
 */
class Controller extends AbstractComponentController implements PollerBgpSessionsInterface,
    ControllerInterface,
    PollerArpsInterface,
    PollerInterfaceListInterface,
    PollerCountersInterface,
    PollerInterfaceStatusInterface,
    PollerResourcesInterface,
    PollerSystemInterface,
    PollerSfpOpticalStrengthInterface
{
    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcher;

    /**
     * @Inject
     * @var PrometheusMetricsTempStore
     */
    protected $lastProm;

    protected $meta = [];

    /**
     * @var Device
     */
    protected $device;

    function setDevice(Device $device)
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @Inject
     * @var User
     */
    protected $user;

    private $_interfaces = [];

    function setUser(User $user)
    {
        $this->user = $user;
        $this->switcher->setUser($user);
        return $this;
    }

    function getArps($parameters = [], string $from = 'cache')
    {
        $data = $this->call('arp_info', $parameters, $from);
        return $data;
    }

    function getLeases($parameters = [], string $from = 'cache')
    {
        if (!isset($parameters['hide_server_detail'])) {
            $parameters['hide_server_detail'] = 'yes';
        }
        $data = $this->call('lease_info', $parameters, $from);
        return array_map(function ($e) {
            $e['id'] = (int)hexdec(str_replace('*', '', $e['_id']));
            return $e;
        }, $data);
    }

    function getDhcpServerInfo($parameters = [], string $from = 'cache')
    {
        $data = $this->call('dhcp_server_info', $parameters, $from);
        return $data;
    }

    function getInterfaceVlanInfo($parameters = [], string $from = 'cache')
    {
        $data = $this->call('interface_vlan_info', $parameters, $from);
        return $data;
    }

    function getInterfaces($parameters = [], $from = 'cache')
    {

        $data = $this->call('interface_info', $parameters, $from);
        $ifaceType = function ($type) {
            switch ($type) {
                case 'ether':
                    return 'ETH';
                case 'vlan':
                    return 'VLAN';
            }
            return 'UNKNOWN';
        };
        return array_map(function ($e) use ($ifaceType) {
            $ret = [
                'id' => (int)hexdec(str_replace('*', '', $e['_id'])),
                'type' => $ifaceType($e['type']),
                '_type' => $e['type'],
            ];
            return array_merge($e, $ret);
        }, $data);
    }

    function getAddressListInfo($parameters = [], string $from = 'cache')
    {
        $data = $this->call('address_list_info', $parameters, $from);
        return $data;
    }

    function getSimpleQueueInfo($parameters = [], string $from = 'cache')
    {
        $data = $this->call('simple_queue_info', $parameters, $from);
        return $data;
    }

    function getBgpSessions($parameters = [], string $from = 'cache')
    {
        $data = $this->call('bgp_sessions', $parameters, $from);
        return $data;
    }

    function arpPing($parameters = [], string $from = 'cache')
    {
        $data = $this->call('arp_ping', $parameters, $from);
        return $data;
    }

    function getMeta()
    {
        return $this->meta;
    }

    function call($moduleName, $arguments, string $from)
    {
        $request = (new Request())
            ->setModule($moduleName)
            ->setDevice($this->device)
            ->setArguments($arguments);
        switch ($from) {
            case 'device':
                $data = $this->switcher->setUser($this->user)->fromDevice([$request]);
                break;
            case 'cache':
                $data = $this->switcher->setUser($this->user)->fromCache([$request]);
                break;
            case 'store':
                $data = $this->switcher->setUser($this->user)->fromStore([$request]);
                break;
            default:
                throw new \InvalidArgumentException("Type '$from' not supported");
        }
        $response = $data->getFirstByModule($moduleName);
        if (!$response->getData() && $response->getError()) {
            throw new SwitcherCoreException("SwitcherCore returned error: " . $response->getError()['message']);
        }
        $this->meta[$moduleName] = [
            'time' => $response->getTime(),
            'source' => $response->getSource(),
            'from_cache' => $response->getSource() !== 'device',
            'hash' => $response->getHash(),
            'error' => $response->getError(),
        ];
        return $response->getData();
    }


    /**
     * Collecters methods
     */


    function getInterfacesList(): array
    {
        if ($this->_interfaces) {
            return $this->_interfaces;
        }
        $this->_interfaces = $this->getInterfaces([], 'device');
        return $this->_interfaces;
    }

    function getCounters()
    {
        if ($this->_interfaces) {
            $ifaces = $this->_interfaces;
        } else {
            $ifaces = $this->getInterfaces([], 'device');
            $this->_interfaces = $ifaces;
        }
        return array_map(function ($e) {
            return [
                'interface' => [
                    'id' => $e['id'],
                    'name' => $e['name']
                ],
                'in_bytes' => isset($e['rx_byte']) && is_numeric($e['rx_byte']) ? $e['rx_byte'] : 0,
                'out_bytes' => isset($e['tx_byte']) && is_numeric($e['tx_byte']) ? $e['tx_byte'] : 0,
                'in_pkts' => isset($e['rx_packet']) && is_numeric($e['rx_packet']) ? $e['rx_packet'] : 0,
                'out_pkts' => isset($e['tx_packet']) && is_numeric($e['tx_packet']) ? $e['tx_packet'] : 0,
                'in_drop_pkts' => isset($e['rx_drop']) && is_numeric($e['rx_drop']) ? $e['rx_drop'] : 0,
                'out_drop_pkts' => isset($e['tx_drop']) && is_numeric($e['tx_drop']) ? $e['tx_drop'] : 0,
            ];
        }, $ifaces);
    }

    function getInterfaceStatuses()
    {
        if ($this->_interfaces) {
            $ifaces = $this->_interfaces;
        } else {
            $ifaces = $this->getInterfaces([], 'device');
            $this->_interfaces = $ifaces;
        }
        return array_map(function ($e) {
            return [
                'interface' => [
                    'id' => $e['id'],
                    'name' => $e['name']
                ],
                'status' => $e['running'] ? 'Up' : 'Down',
                'admin_state' => $e['disabled'] ? 'Disabled' : 'Enabled',
            ];
        }, $ifaces);
    }

    protected $_coreMeta;

    function getDeviceCoreMeta()
    {
        if ($this->_coreMeta) {
            return $this->_coreMeta;
        }
        try {
            $system = $this->call('system', [], 'cache');
        } catch (\Exception $e) {
            $system = $this->call('system', [], 'store');
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
                $response = $this->call('sys_resources', ['load_only' => 'memory,cpu'], $from);
                foreach ($response as $key => $res) {
                    $data[$key] = $res;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error getting resources from {$this->device->getIp()}: {$e->getMessage()}");
        }
        try {
            if (in_array('sys_temp', $this->getSupportedModules())) {
                $response = $this->call('sys_temp', [], $from);
                $data['temperatures'] = $response;
            }
        } catch (\Exception $e) {
            $this->logger->error("Error getting temperatures from {$this->device->getIp()}: {$e->getMessage()}");
        }
        return $data;
    }
    public function sfpOpticalInfo($from = 'device')
    {
        $resp = $this->callCore('sfp_optical', [], $from);
        if ($resp->getError()) {
            throw new SwitcherCoreException($resp->getError()['message']);
        }
        if ($resp->getData()) {
            return $resp->getData();
        }
       return [];
    }

    function getSystemInfo()
    {
        return $this->call('system', [], 'device');
    }

    function getDeviceStats()
    {
        $data = [
            'events' => null,
            'bgp_sessions' => null,
            'arps' => null,
            'leases' => null,
            'links' => null,
        ];
        try {
            if (App::getInstance()->getComponentInjector()->isComponentEnabled('events') &&
                $event = App::getInstance()->getComponentInjector()->getController('events')) {
                /**
                 * @var $event \WCC\Events\Controllers\Controller
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
            $last = $this->lastProm->getLastMetricsByDevice($this->device, [
                'router_arps_count',
                'router_bgp_session_state_established',
            ]);
            if (isset($last['router_arps_count'])) {
                $data['arps'] = 0;
                foreach ($last['router_arps_count'] as $vl) {
                    $data['arps'] += $vl['value'];
                }
            }
            if (isset($last['router_bgp_session_state_established'])) {
                $data['bgp_sessions'] = 0;
                foreach ($last['router_bgp_session_state_established'] as $vl) {
                    $data['bgp_sessions']++;
                }
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

}
