<?php


namespace WCC\SensorDevices\Controllers;


use Monolog\Logger;
use WCAA\App;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Poller\Interfaces\PollerSensorsInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSystemInterface;
use WCAA\Infrastructure\Poller\ResponseToPollerWriter;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Interfaces\ControllerInterface;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\ResponsesWrapper;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Events\Models\Event;
use WCC\Events\Models\EventFilter;
use WCC\Events\Storage\EventsStorage;

/**
 * Class Controller
 * @package WCC\SensorDevices
 */
class Controller extends AbstractComponentController implements PollerSensorsInterface, ControllerInterface, PollerSystemInterface
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
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var ResponseToPollerWriter
     */
    protected $responseToPollerWrapper;

    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventStorage;

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
     * @var \WCC\PrometheusWrapper\Controllers\Controller
     */
    protected $promWrapper;

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

    public function setDevice(Device $device)
    {
        $this->_coreMeta = null;
        $this->meta = [];
        $this->device = $device;
        $this->logger->info("Setted device - {$device->getIp()}", $device->getAsArray());
        return $this;
    }


    /**
     * @param $type
     * @param $id
     * @return \WCC\Events\Models\Event[]
     */
    function getActiveAlertsBySensor($type, $id)
    {
        return array_filter($this->eventStorage->getNotResolvedBy('', $this->device), function (Event $e) use ($type, $id) {
            switch ($type) {
                case 'power_control_output_list': $type='power_output'; break;
                case 'digital_lines_list': $type='digital_line'; break;
                case 'analog_lines_list': $type='analog_line'; break;
                case 'power_sensor_state': $type='power_sensor'; break;
            }
            if (!isset($e->getLabels()['type'])) return false;
            if (!isset($e->getLabels()['sensor_id'])) return false;
            return $e->getLabels()['type'] == $type && $e->getLabels()['sensor_id'] == $id;
        });
    }

    function getAllSensorsData()
    {
        $data = $this->getSensors('device');
        $response = [];
        if ($data['power_control_output_list']) {
            foreach ($data['power_control_output_list'] as $dt) {
                $response[] = [
                    'id' => $dt['id'],
                    'name' => $dt['name'],
                    'type' => 'power_output',
                    'value' => $dt['mode'] == 'On' ? 1 : 0,
                ];
            }
        }
        if ($data['digital_lines_list']) {
            foreach ($data['digital_lines_list'] as $dt) {
                $response[] = [
                    'id' => $dt['id'],
                    'name' => $dt['name'],
                    'type' => 'digital_line',
                    'value' => $dt['value'] == 'High' ? 1 : 0,
                ];
            }
        }
        if ($data['analog_lines_list']) {
            foreach ($data['analog_lines_list'] as $dt) {
                $response[] = [
                    'id' => $dt['id'],
                    'name' => $dt['name'],
                    'type' => 'analog_line',
                    'value' => $dt['value'],
                ];
            }
        }
        if ($data['power_sensor_state']) {
            $response[] = [
                'id' => 0,
                'name' => 'main',
                'type' => 'power_sensor',
                'value' => $data['power_sensor_state']['state'] == 'OK' ? 1 : 0,
            ];
        }

        return $response;
    }

    public function getSensorConfiguration($type, $id)
    {
        if (isset($this->device->getParams()['sensors'][$type][$id])) {
            return $this->device->getParams()['sensors'][$type][$id];
        } else {
            return [
                'unit' => null,
                'mark_as' => null,
                'hidden' => false,
            ];
        }
    }

    public function updateSensorConfiguration($type, $id, $data)
    {
        $params = $this->device->getParams();
        $params['sensors'][$type][$id] = $data;
        $this->device->setParams($params);
        $this->deviceStorage->update($this->device);
        return $data;
    }

    public function setModuleDisabled($module, $disabled = true)
    {
        $params = $this->device->getParams();
        $disabledModules = [];
        if (isset($params['disabled_modules']) && is_array($params['disabled_modules'])) {
            $disabledModules = $params['disabled_modules'];
        }
        if ($disabled) {
            if (!in_array($module, $disabledModules)) {
                $disabledModules[] = $module;
            }
        } else {
            if (($key = array_search($module, $disabledModules)) !== false) {
                unset($disabledModules[$key]);
            }
        }
        $params['disabled_modules'] = array_values($disabledModules);
        $this->device->setParams($params);
        $this->deviceStorage->update($this->device);
        return $params['disabled_modules'];
    }

    public function getModulesDisabled()
    {
        $params = $this->device->getParams();
        $disabledModules = [];
        if (isset($params['disabled_modules']) && is_array($params['disabled_modules'])) {
            $disabledModules = $params['disabled_modules'];
        }
        return $disabledModules;
    }

    public function getSensors($from, $loadOnly = [])
    {

        $disabledModules = [];
        if (isset($this->device->getParams()['disabled_modules']) && is_array($this->device->getParams()['disabled_modules'])) {
            $disabledModules = $this->device->getParams()['disabled_modules'];
        }
        $requests = [];
        $modules = array_filter($this->getSupportedModules(), function ($supported) use ($disabledModules) {
            return !in_array($supported, $disabledModules);
        });
        if ($loadOnly) {
            foreach ($modules as $key => $moduleName) {
                if (!in_array($moduleName, $loadOnly)) {
                    unset($modules[$key]);
                }
            }
            $modules = array_values($modules);
        }

        if (in_array('power_control_output_list', $modules)) $requests[] = $this->_newRequest('power_control_output_list');
        if (in_array('digital_lines_list', $modules)) $requests[] = $this->_newRequest('digital_lines_list');
        if (in_array('analog_lines_list', $modules)) $requests[] = $this->_newRequest('analog_lines_list');
        if (in_array('power_sensor_state', $modules)) $requests[] = $this->_newRequest('power_sensor_state');
        if (in_array('knock_sensor_state', $modules)) $requests[] = $this->_newRequest('knock_sensor_state');

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
        $this->responseToPollerWrapper->process($responses);
        $this->generateMeta($responses);


        //Read responses
        $RESPONSE = [
            'digital_lines_list' => null,
            'analog_lines_list' => null,
            'power_control_output_list' => null,
            'power_sensor_state' => null,
            'knock_sensor_state' => null,
        ];

        if (in_array('power_control_output_list', $modules)) {
            $resp = $responses->getFirstByModule('power_control_output_list');
            if (!$resp->getError()) {
                $RESPONSE['power_control_output_list'] = $resp->getData();
            }
        }
        if (in_array('digital_lines_list', $modules)) {
            $resp = $responses->getFirstByModule('digital_lines_list');
            if (!$resp->getError()) {
                $RESPONSE['digital_lines_list'] = $resp->getData();
            }
        }
        if (in_array('analog_lines_list', $modules)) {
            $resp = $responses->getFirstByModule('analog_lines_list');
            if (!$resp->getError()) {
                $RESPONSE['analog_lines_list'] = $resp->getData();
            }
        }
        if (in_array('power_sensor_state', $modules)) {
            $resp = $responses->getFirstByModule('power_sensor_state');
            if (!$resp->getError()) {
                $RESPONSE['power_sensor_state'] = $resp->getData();
            }
        }
        if (in_array('knock_sensor_state', $modules)) {
            $resp = $responses->getFirstByModule('knock_sensor_state');
            if (!$resp->getError()) {
                $RESPONSE['knock_sensor_state'] = $resp->getData();
            }
        }

        return $RESPONSE;
    }


    public function setDeviceName($name)
    {
        $data = $this->callCore('ctrl_set_device_name', ['name' => $name], 'device');
        if ($data->getError()) {
            throw new SupportException($data->getError()['message']);
        }
        return $data->getData();
    }

    public function setDeviceDescription($description)
    {
        $data = $this->callCore('ctrl_set_device_description', ['description' => $description], 'device');
        if ($data->getError()) {
            throw new SupportException($data->getError()['message']);
        }
        return $data->getData();
    }

    public function controlPowerOutput($id, $params = [])
    {
        $params['id'] = $id;
        $data = $this->callCore('ctrl_power_control_output', $params, 'device');
        if ($data->getError()) {
            throw new SupportException($data->getError()['message']);
        }
        return $data->getData();
    }

    public function controlDigitalLine($id, $params)
    {
        $params['id'] = $id;
        $data = $this->callCore('ctrl_digital_line', $params, 'device');
        if ($data->getError()) {
            throw new SupportException($data->getError()['message']);
        }
        return $data->getData();
    }

    public function controlAnalogLine($id, $params)
    {
        $params['id'] = $id;
        $data = $this->callCore('ctrl_analog_line', $params, 'device');
        if ($data->getError()) {
            throw new SupportException($data->getError()['message']);
        }
        return $data->getData();
    }

    public function getLastMeta()
    {
        return $this->meta;
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

    function getSupportedModules()
    {
        if ($this->_coreMeta) {
            return $this->_coreMeta['modules'];
        }
        return $this->getDeviceCoreMeta()['modules'];
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

    private function _newRequest($module, $arguments = [])
    {
        return (new Request())
            ->setDevice($this->device)
            ->setModule($module)
            ->setArguments($arguments);
    }

    function getSystemInfo()
    {
        return $this->callCore('system', [], 'device')->getData();
    }


    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $promMetrics;
    function getSeriesForSensor($type, $sensorId, $start, $end, $step)
    {
        switch ($type) {
            case 'power_control_output_list': $type='power_output'; break;
            case 'digital_lines_list': $type='digital_line'; break;
            case 'analog_lines_list': $type='analog_line'; break;
            case 'power_sensor_state': $type='power_sensor'; break;
        }

        $metric = $this->promMetrics->getLastValues('device_sensor', [
            'device_id' => $this->device->getId(),
            'type' => $type,
            'sensor_id' => $sensorId,
        ]);
        $name = "Sensor";
        if(isset($metric[0]['labels']['name'])) {
            $name = $metric[0]['labels']['name'];
        }

        return $this->promWrapper->convertToChartFormat(
            $this->promWrapper->seriesRequest([
                sprintf('device_sensor{dev_id="%d", type="%s", sensor_id="%d"}', $this->device->getId(), $type, $sensorId),
            ], $start, $end, $step),
            [
                'device_sensor' => [
                    'label' => $name,
                    'borderColor' => 'rgba(10, 115, 24, 1)',
                    'backgroundColor' => 'rgba(10, 115, 24, 0.3)',
                ]
            ]
        );
    }

    function getDeviceStats()
    {
        $data = [
            'events' => null,
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

        return $data;
    }
}
