<?php


namespace WCC\Oxidized\Controllers;


use Curl\Curl;
use Meklis\PromClient\Client;
use Monolog\Logger;
use SwitcherCore\Modules\Helper;
use WCAA\App;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * Class Controller
 * @package WCC\Oxidized
 */
class Controller extends \WCC\PrometheusWrapper\Controllers\Controller
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @var Curl
     */
    protected $curl;

    protected $oxidizedUrl = null;

    public function __construct(App $app, ComponentInjector $componentInjector, Logger $logger)
    {
        $curl = new Curl();
        $curl->setTimeout(30);
        $curl->setHeader('Content-Type', 'application/json');
        $curl->setJsonDecoder(function ($response) {
            return json_decode($response, true);
        });
        $this->curl = $curl;
        $this->oxidizedUrl = _env('OXIDIZED_URL', 'http://wca-oxidized:8888/oxidized');

        parent::__construct($app, $componentInjector, $logger);
    }



    function getDevicesList()
    {
        $devices = [];
        foreach ($this->deviceStorage->fetchAll() as $dev) {
            if (!$dev->isEnabled()) continue;
            $modelKey = $this->getParametersByModel($dev);
            if (!$modelKey) continue;
            if (!isset($dev->getParams()['oxidized_enabled'])) continue;
            if (!$dev->getParams()['oxidized_enabled']) continue;
            $swDev = $this->getSwDevice($dev);
            $dvs = [
                'ip' => $dev->getIp(),
                'name' => $dev->getIp(),
                'full_name' => $dev->getName(),
                'login' => $swDev->getLogin(),
                'password' => $swDev->getPassword(),
                'model' => $modelKey['model'],
                'enable' => $modelKey['enable'],
                'remove_secret' => $modelKey['remove_secret'],
            ];
            if ($swDev->consoleConnectionType == 'ssh') {
                $dvs['input'] = 'ssh';
                $dvs['ssh_port'] = $swDev->consolePort;
            }
            if ($swDev->consoleConnectionType == 'telnet') {
                $dvs['input'] = 'telnet';
                $dvs['telnet_port'] = $swDev->consolePort;
            }
            $devices[] = $dvs;
        }
        return $devices;
    }

    function reloadConfig()
    {
        $this->curl->get($this->oxidizedUrl . '/reload.json');
        return true;
    }

    function getConfig(Device $device)
    {
        $data = $this->curl->get($this->oxidizedUrl . '/node/fetch/' . $device->getIp());
        return $data;
    }

    function getLinks(Device $device)
    {
        return [
          'versions' => "/oxidized/node/version?node_full={$device->getIp()}",
          'config' => "/oxidized/node/fetch/{$device->getIp()}",
        ];
    }

    function nodeStat(Device $device)
    {
        $data = $this->curl->get("{$this->oxidizedUrl}/node/show/{$device->getIp()}.json");
        return $data;
    }

    function getParametersByModel(Device $dev)
    {
        if (isset($dev->getParams()['oxidized']) && $dev->getParams()['oxidized']) {
            return $dev->getParams()['oxidized'];
        }
        if (isset($dev->getModel()->getParams()['oxidized']) && $dev->getModel()->getParams()['oxidized']) {
            return $dev->getModel()->getParams()['oxidized'];
        }
        if (isset($this->moduleConfig['models_map'][$dev->getModel()->getKey()])) {
            return $this->moduleConfig['models_map'][$dev->getModel()->getKey()];
        }
        return null;
    }

    function isSecretMustRemoved($model)
    {
        $conf = $this->moduleConfig['remove_secrets_from_models'];
        return in_array($model, $conf);
    }

    function getSwDevice(Device $dev)
    {
        $params = App::getInstance()->getConfig()['switcher_core'];

        $pubCommunity = $dev->getAccess()->getPublicCommunity();
        $privateCommunity = $dev->getAccess()->getPrivateCommunity();
        $login = $dev->getAccess()->getLogin();
        $password = $dev->getAccess()->getPassword();

        if ($dev->getModel()) {
            $modelParams = $dev->getModel()->getParams();
            if (isset($modelParams['access']['login'])) {
                $login = $modelParams['access']['login'];
            }
            if (isset($modelParams['access']['password'])) {
                $password = $modelParams['access']['password'];
            }
            if (isset($modelParams['access']['public_community'])) {
                $pubCommunity = $modelParams['access']['public_community'];
            }
            if (isset($modelParams['access']['private_community'])) {
                $privateCommunity = $modelParams['access']['private_community'];
            }
        }
        $devParams = $dev->getParams();
        if (isset($devParams['access']['login'])) {
            $login = $devParams['access']['login'];
        }
        if (isset($devParams['access']['password'])) {
            $password = $devParams['access']['password'];
        }
        if (isset($devParams['access']['public_community'])) {
            $pubCommunity = $devParams['access']['public_community'];
        }
        if (isset($devParams['access']['private_community'])) {
            $privateCommunity = $devParams['access']['private_community'];
        }

        $device = (new \SwitcherCore\Switcher\Device())
            ->setIp($dev->getIp())
            ->setPublicCommunity($pubCommunity)
            ->setPrivateCommunity($privateCommunity)
            ->setLogin($login)
            ->setPassword($password);
        if ($model = $dev->getModel()) {
            $device->setModelKey($model->getKey());
        }
        $acParam = $dev->getAccess()->getParams();
        if (isset($acParam['sw_core_connection'])) {
            $conn = $acParam['sw_core_connection'];
            if (isset($conn['console_port']) && $conn['console_port']) {
                $params['console_port'] = $conn['console_port'];
            }
            if (isset($conn['snmp_version']) && $conn['snmp_version']) {
                $params['snmp_version'] = $conn['snmp_version'];
            }
            if (isset($conn['console_wait_byte_sec']) && $conn['console_wait_byte_sec']) {
                $params['console_wait_byte_sec'] = $conn['console_wait_byte_sec'];
            }
            if (isset($conn['console_timeout_sec']) && $conn['console_timeout_sec']) {
                $params['console_timeout_sec'] = $conn['console_timeout_sec'];
            }
            if (isset($conn['console_connection_type']) && $conn['console_connection_type']) {
                $params['console_connection_type'] = $conn['console_connection_type'];
            }
            if (isset($conn['snmp_timeout_sec']) && $conn['snmp_timeout_sec']) {
                $params['snmp_timeout_sec'] = $conn['snmp_timeout_sec'];
            }
            if (isset($conn['snmp_repeats']) && $conn['snmp_repeats']) {
                $params['snmp_repeats'] = $conn['snmp_repeats'];
            }
            if (isset($conn['mikrotik_api_port']) && $conn['mikrotik_api_port']) {
                $params['mikrotik_api_port'] = $conn['mikrotik_api_port'];
            }
            if (isset($conn['snmp_port']) && $conn['snmp_port']) {
                $params['snmp_port'] = $conn['snmp_port'];
            }
        }

        $modelParams = $dev->getModel();
        if ($modelParams && isset($modelParams->getParams()['sw_core_connection'])) {
            $modelConnParams = $modelParams->getParams()['sw_core_connection'];
            if (isset($modelConnParams['console_port']) && $modelConnParams['console_port']) {
                $params['console_port'] = $modelConnParams['console_port'];
            }
            if (isset($modelConnParams['console_timeout_sec']) && $modelConnParams['console_timeout_sec']) {
                $params['console_timeout_sec'] = $modelConnParams['console_timeout_sec'];
            }
            if (isset($modelConnParams['console_connection_type']) && $modelConnParams['console_connection_type']) {
                $params['console_connection_type'] = $modelConnParams['console_connection_type'];
            }
            if (isset($modelConnParams['snmp_timeout_sec']) && $modelConnParams['snmp_timeout_sec']) {
                $params['snmp_timeout_sec'] = $modelConnParams['snmp_timeout_sec'];
            }
            if (isset($modelConnParams['snmp_repeats']) && $modelConnParams['snmp_repeats']) {
                $params['snmp_repeats'] = $modelConnParams['snmp_repeats'];
            }
            if (isset($modelConnParams['mikrotik_api_port']) && $modelConnParams['mikrotik_api_port']) {
                $params['mikrotik_api_port'] = $modelConnParams['mikrotik_api_port'];
            }
            if (isset($modelConnParams['snmp_port']) && $modelConnParams['snmp_port']) {
                $params['snmp_port'] = $modelConnParams['snmp_port'];
            }
            if (isset($modelConnParams['snmp_version']) && $modelConnParams['snmp_version']) {
                $params['snmp_version'] = $modelConnParams['snmp_version'];
            }
            if (isset($modelConnParams['console_wait_byte_sec']) && $modelConnParams['console_wait_byte_sec']) {
                $params['console_wait_byte_sec'] = $modelConnParams['console_wait_byte_sec'];
            }
        }
        $devParam = $dev->getParams();
        if (isset($devParam['sw_core_connection'])) {
            $conn = $devParam['sw_core_connection'];
            if (isset($conn['console_port']) && $conn['console_port']) {
                $params['console_port'] = $conn['console_port'];
            }
            if (isset($conn['console_timeout_sec']) && $conn['console_timeout_sec']) {
                $params['console_timeout_sec'] = $conn['console_timeout_sec'];
            }
            if (isset($conn['console_connection_type']) && $conn['console_connection_type']) {
                $params['console_connection_type'] = $conn['console_connection_type'];
            }
            if (isset($conn['snmp_timeout_sec']) && $conn['snmp_timeout_sec']) {
                $params['snmp_timeout_sec'] = $conn['snmp_timeout_sec'];
            }
            if (isset($conn['snmp_repeats']) && $conn['snmp_repeats']) {
                $params['snmp_repeats'] = $conn['snmp_repeats'];
            }
            if (isset($conn['mikrotik_api_port']) && $conn['mikrotik_api_port']) {
                $params['mikrotik_api_port'] = $conn['mikrotik_api_port'];
            }
            if (isset($conn['snmp_port']) && $conn['snmp_port']) {
                $params['snmp_port'] = $conn['snmp_port'];
            }
            if (isset($conn['snmp_version']) && $conn['snmp_version']) {
                $params['snmp_version'] = $conn['snmp_version'];
            }
            if (isset($conn['console_wait_byte_sec']) && $conn['console_wait_byte_sec']) {
                $params['console_wait_byte_sec'] = $conn['console_wait_byte_sec'];
            }
        }
        $device->consolePort = $params['console_port'];
        $device->consoleTimeout = $params['console_timeout_sec'];
        $device->consoleWaitByteSec = $params['console_wait_byte_sec'];
        $device->consoleConnectionType = $params['console_connection_type'];
        $device->mikrotikApiPort = $params['mikrotik_api_port'];
        $device->snmpTimeoutSec = $params['snmp_timeout_sec'];
        $device->snmpRepeats = $params['snmp_repeats'];
        $device->snmpPort = $params['snmp_port'];
        $device->snmpVersion = $params['snmp_version'];

        return $device;
    }
}
