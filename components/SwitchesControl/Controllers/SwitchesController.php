<?php


namespace WCC\SwitchesControl\Controllers;


use Monolog\Logger;
use WCAA\App;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Poller\Interfaces\PollerCountersInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerFdbInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceListInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerResourcesInterface;
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
class SwitchesController extends \WCC\Switches\Controllers\SwitchesController
{

    public function clearIfaceCounters($interface) {
        $resp = $this->callCore('clear_iface_counters', ['interface' => $interface], 'device');
        if($resp->getError()) {
            throw new SupportException($resp->getError()['message']);
        }
        return $resp->getData();
    }

    public function rebootDevice()
    {
        return $this->callCore('reboot', [], 'device')->getData();
    }

    public function clearCounters()
    {
        return $this->callCore('clear_counters', [], 'device')->getData();
    }

    public function saveConfig()
    {
        return $this->callCore('save_config', [], 'device')->getData();
    }

    public function setDescription($interface, $description)
    {
        $resp = $this->callCore('ctrl_port_descr', [
            'interface' => $interface,
            'description' => $description,
        ], 'device');
        if($resp->getError()) {
            throw new SupportException($resp->getError()['message']);
        }
        return $resp->getData();
    }

    public function setPortState($interface, $state)
    {
        $resp = $this->callCore('ctrl_port_state', [
            'interface' => $interface,
            'state' => $state,
        ], 'device');
        if($resp->getError()) {
            throw new SupportException($resp->getError()['message']);
        }
        return $resp->getData();
    }

    public function setPortSpeed($interface, $speed)
    {
        $resp = $this->callCore('ctrl_port_speed', [
            'interface' => $interface,
            'speed' => $speed,
        ], 'device');
        if($resp->getError()) {
            throw new SupportException($resp->getError()['message']);
        }
        return $resp->getData();
    }

    public function setVlanPort($interface, $vlanId, $type, $action)
    {
        return $this->callCore('ctrl_vlan_port', [
            'interface' => $interface,
            'id' => $vlanId,
            'type' => $type,
            'action' => $action,
        ], 'device')->getData();
    }

}
