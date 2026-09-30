<?php


namespace WCC\Diagnostic\Controllers;

use Monolog\Logger;
use WCAA\App;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Diagnostic\Exceptions\EquipmentNotSupported;
use WCC\Switches\Controllers\SwitchesController;

/**
 * Class Controller
 * @package WCC\NoDenyPlus
 */
class InterfaceDiager extends AbstractComponentController
{
    /**
     * @var SwitchesController|null
     */
    protected $switchController = null;

    /**
     * @var \WCC\Olts\Controllers\Controller
     */
    protected $oltController = null;

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;

    public function __construct(ComponentInjector $componentInjector, Logger $logger, App $app)
    {
        if($componentInjector->isComponentEnabled('olts')) {
            $this->oltController = $app->getContainer()->get(\WCC\Olts\Controllers\Controller::class);
        }
        if($componentInjector->isComponentEnabled('switches')) {
            $this->switchController = $app->getContainer()->get(SwitchesController::class);
        }

        parent::__construct($componentInjector, $logger);
    }

    public function diagByInterface(DeviceInterface $iface, $from = 'cache', $load_modules = null) {
        $data = null;
        $deviceStatus = $this->getDeviceOnlineStatus($iface->getDevice());
        if($deviceStatus['error']) {
            $from = 'store';
        }
        switch ($iface->getDevice()->getModel()->getType()) {
            case 'SWITCH': $data = $this->diagFromSwitch($iface, $from, $load_modules); break;
            case 'OLT': $data = $this->diagFromOlt($iface, $from, $load_modules); break;
            default:
                throw new EquipmentNotSupported("Diagnostic not supported by current device");
        }

        return [
            'iface' => $iface->getAsArray(),
            'diagnostic' => $data,
            'device_status' => $deviceStatus,
        ];
    }

    protected function getDeviceOnlineStatus(Device $device) {
        $this->switcherCore->setUser(App::getInstance()->getSysUser());
        $data = $this->switcherCore->fromDevice([
            (new Request())
                ->setDevice($device)
            ->setModule('system')
        ])->getFirstByModule('system');
        return [
            'data' =>$data->getError() ? null : $data->getData(),
            'error' =>$data->getError() ? $data->getError()['message'] : null,
        ];
    }

    protected function diagFromSwitch(DeviceInterface $iface, $from = 'cache', $load_modules = null) {
        if(!$load_modules) {
            $load_modules = $this->moduleConfig['load_modules']['switch'];
        }
        $result = $this->switchController
            ->setDevice($iface->getDevice())
            ->setUser(App::getInstance()->getSysUser())
            ->getInterfaceFullInfo($from, $iface->getBindKey(), $load_modules);
        if(count($result) > 0) {
            $result = $result[0];
        }
        return ['data' => $result, 'meta' => $this->switchController->getLastMeta()];
    }

    protected function diagFromOlt(DeviceInterface $iface, $from = 'cache', $load_modules = null) {
        if(!$load_modules) {
            $load_modules = $this->moduleConfig['load_modules']['olt'];
        }
        $result = $this->oltController
            ->setDevice($iface->getDevice())
            ->setUser(App::getInstance()->getSysUser())
            ->getOnuInfo($iface->getBindKey(), $from, $load_modules);
        return ['data' => $result, 'meta' => $this->oltController->getLastMeta()];
    }

}
