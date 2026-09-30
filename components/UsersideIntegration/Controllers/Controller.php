<?php


namespace WCC\UsersideIntegration\Controllers;


use DI\Annotation\Inject;
use GuzzleHttp\Client;
use Monolog\Logger;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Models\Devices\DeviceGroup;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\UtelsIntegration\Storage\BoxObjectStorage;

/**
 * Class Controller
 * @package WCC\UsersideIntegration
 */
class Controller extends AbstractComponentController
{

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $deviceModelStorage;

    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $deviceAccessStorage;

    /**
     * @Inject
     * @var UsersideApiClient
     */
    protected $usersideAPI;

    protected $isDebug;

    /**
     * @return mixed
     */
    public function getIsDebug()
    {
        return $this->isDebug;
    }

    public function setIsDebug($isDebug)
    {
        $this->isDebug = $isDebug;
        return $this;
    }


    protected function getUsersideDevicesWithFullData()
    {
        $this->log("INFO", "Start working with devices");
        $this->log("INFO", "Start get nodes");
        $nodes = $this->usersideAPI->getNodes(UsersideApiClient::NODE_TYPE_COMMUNICATION_UNIT);
        $this->log("INFO", "Nodes received, count: " . count($nodes));
        $listDevicesFromUserside = array_filter($this->usersideAPI->getDevices('all'), function ($dev) {
            return $dev['ip'] != "";
        });
        $listDevicesFromUserside = array_map(function ($dev) use ($nodes) {
            $dev['coordinates'] = null;
            if (isset($nodes[$dev['node_id']]['coordinates'])) {
                $coordinates = $nodes[$dev['node_id']]['coordinates'];
                $coordinates['display_name'] = $nodes[$dev['node_id']]['name'];
                $dev['coordinates'] = $coordinates;
            }
            return $dev;
        }, $listDevicesFromUserside);
        $usersideDevices = [];
        foreach ($listDevicesFromUserside as $device) {
            $ip = long2ip($device['ip']);
            if($ip) {
                $usersideDevices[$ip] = $device;
            }
        }
        return $usersideDevices;
    }


    protected function fetchDeviceAccessesFromUserside($usersideDevices)
    {
        $accesses = [];
        foreach ($usersideDevices as $device) {
            $accessKey = sha1("{$device['snmp_community']}-{$device['telnet_login']}-{$device['telnet_pass']}");
            $uniq = substr(sha1($accessKey), 0, 6);
            $accesses[$accessKey] = (new DeviceAccess())
                ->setLogin($device['telnet_login'])
                ->setName("{$device['snmp_community']}  (sync from userside - $uniq)")
                ->setPublicCommunity($device['snmp_community'])
                ->setPrivateCommunity($device['snmp_community'])
                ->setPassword($device['telnet_pass']);
        }
        return array_values($accesses);
    }

    /**
     * @return bool
     * @throws \Exception
     */
    function syncDevices()
    {
        $deviceGroup = $this->deviceGroupStorage->getById(_env('USERSIDE_ADD_NEW_DEVICES_TO_GROUP_ID', -1));
        $usersideDevices = $this->getUsersideDevicesWithFullData();
        $usersideAccesses = $this->fetchDeviceAccessesFromUserside($usersideDevices);
        $this->log("INFO", "Count unique devices by IP: " . count($usersideDevices) . ", count accesses: " . count($usersideAccesses));

        $this->log("INFO", "Start working with accesses");
        $accesses = [];
        foreach ($this->deviceAccessStorage->fetchAll() as $access) {
            $accesses["{$access->getPublicCommunity()}-{$access->getLogin()}-{$access->getPassword()}"] = $access;
        }
        $this->log("INFO", "Received " . count($accesses) . " local accesses");
        $this->log("INFO", "Fetching accesses from userside");
        foreach ($usersideAccesses as $access) {
            $accessKey = "{$access->getPublicCommunity()}-{$access->getLogin()}-{$access->getPassword()}";
            if (isset($accesses[$accessKey])) {
                continue;
            }
            $this->log("INFO", "Adding new access - {$access->getName()}");
            try {
                $accesses[$accessKey] = $this->deviceAccessStorage->add($access);
            } catch (\Exception $e) {
                $this->log("WARN", "Failed to add new access - {$access->getName()}");
            }
        }
        $this->log("INFO", "Received " . count($accesses) . " summary accesses");

        $existedDevices = [];
        foreach ($this->deviceStorage->fetchAll() as $device) {
            $existedDevices[$device->getIp()] = $device;
        }
        $this->log("INFO", "Received " . count($existedDevices) . " local devices");

        $this->log("INFO", "Start add new devices");
        foreach ($usersideDevices as $device) {
            $accessKey = "{$device['snmp_community']}-{$device['telnet_login']}-{$device['telnet_pass']}";
            $deviceKey = long2ip($device['ip']);

            if (isset($existedDevices[$deviceKey])) {
                $this->log("INFO", "Already existed device {$deviceKey} in system, continue...");
                continue;
            }

            if (!isset($accesses[$accessKey])) {
                $this->log("WARN", "Access for device  {$deviceKey} not found in support, ignoring device adding");
                continue;
            }

            try {
                $newDev = $this->addNewDevice(
                    (new Device())
                        ->setIp($deviceKey)
                        ->setAccess($accesses[$accessKey])
                        ->setName($device['name'])
                        ->setParamByName('userside', [
                            'id' => $device['ip'],
                            'inventory_section_type_id' => $device['inventory_section_type_id'],
                            'entrance' => $device['entrance'],
                            'host' => $device['host'],
                            'date_add' => $device['date_add'],
                            'inventory_id' => $device['inventory_id'],
                            'location' => $device['location'],
                            'node_id' => $device['node_id'],
                            'customer_id' => $device['customer_id'],
                            'interfaces' => $device['interfaces'],
                        ])
                        ->setGroup($deviceGroup)
                        ->setLocation($device['location'])
                        ->setCoordinates($device['coordinates'])
                        ->setDescription($device['comment'])
                );
                $existedDevices[$deviceKey] = $newDev;
                $this->log("WARN", "Added new device {$deviceKey} to system as model {$newDev->getModel()->getName()}");
            } catch (\Exception $e) {
                $this->log("ERROR", "Error adding device {$deviceKey} - {$e->getMessage()}");
            }
        }

        $this->log('INFO', "Updated existed devices");
        $checkFields = _env('USERSIDE_UPDATE_DEVICES_FIELDS', []);

        foreach ($usersideDevices as $device) {
            $accessKey = "{$device['snmp_community']}-{$device['telnet_login']}-{$device['telnet_pass']}";
            $deviceKey = long2ip($device['ip']);

            if (!isset($existedDevices[$deviceKey])) {
                continue;
            }
            $existedDevice = $existedDevices[$deviceKey];
            $mustUpdateReasons = [];
            if ($device['comment'] != $existedDevice->getDescription() && in_array('comment', $checkFields)) {
                $mustUpdateReasons[] = "comment";
                $this->log("INFO", "old comment='{$existedDevice->getDescription()}', new comment='{$device['comment']}'");
                $existedDevice->setDescription($device['comment']);
            }
            if ($device['location'] != $existedDevice->getLocation() && in_array('location', $checkFields)) {
                $mustUpdateReasons[] = "location";
                $existedDevice->setLocation($device['location']);
            }
            if ($device['name'] != $existedDevice->getName() && in_array('name', $checkFields)) {
                $mustUpdateReasons[] = "name";
                $existedDevice->setName($device['name']);
            }
            if (in_array('coordinates', $checkFields)) {
                $existedLat = isset($existedDevice->getCoordinates()['lat']) ? $existedDevice->getCoordinates()['lat'] : 0;
                $existedLon = isset($existedDevice->getCoordinates()['lon']) ? $existedDevice->getCoordinates()['lon'] : 0;
                if (
                    isset($device['coordinates']['lat'])
                    && isset($device['coordinates']['lon'])
                    && round($existedLat, 4) != round($device['coordinates']['lat'], 4)
                    && round($existedLon, 4) != round($device['coordinates']['lon'], 4)
                ) {
                    $mustUpdateReasons[] = "coordinates";
                    $this->log("INFO", "old coordinates=" . json_encode($existedDevice->getCoordinates(), JSON_UNESCAPED_UNICODE) . ", new coordinates=" . json_encode($device['coordinates'], JSON_UNESCAPED_UNICODE));
                    $existedDevice->setCoordinates($device['coordinates']);
                }
            }
            if ($mustUpdateReasons) {
                $this->log('WARN', "Updating existed device - {$deviceKey}, reasons - " . json_encode($mustUpdateReasons, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $this->deviceStorage->update($existedDevice->setParamByName('userside', [
                    'id' => $device['ip'],
                    'inventory_section_type_id' => $device['inventory_section_type_id'],
                    'entrance' => $device['entrance'],
                    'host' => $device['host'],
                    'date_add' => $device['date_add'],
                    'inventory_id' => $device['inventory_id'],
                    'location' => $device['location'],
                    'node_id' => $device['node_id'],
                    'customer_id' => $device['customer_id'],
                    'interfaces' => $device['interfaces'],
                ]));
            }
        }

        if (_env('USERSIDE_DELETE_NOT_EXISTED_DEVICES')) {
            $this->log("WARN", "Deleting not existed devices enabled, start checking");
            foreach ($existedDevices as $ip => $device) {
                if (!isset($usersideDevices[$ip]) && isset($device->getParamByName('userside')['id'])) {
                    $this->log("WARN", "Device {$ip} not found in userside, deleting");
                    $this->deviceStorage->delete($device);
                }
            }
        }
        return true;
    }

    protected function getkeyFromAccess(DeviceAccess $access)
    {
        return "{$access->getPublicCommunity()}-{$access->getLogin()}-{$access->getPassword()}";
    }

    protected function addNewDevice(Device $device)
    {

        $system = $this->switcherCore->getCore($device)->action('system');
        $model = $this->deviceModelStorage->getByKey($system['meta']['key']);
        if (!$model) {
            throw new SupportException("device '{$system['meta']['name']}' not supported by agent at this time");
        }
        $device->setModel($model)
            ->setMac(isset($system['mac_addr']) ? $system['mac_addr'] : '')
            ->setSerial(isset($system['serial_num']) ? $system['serial_num'] : '');
        return $this->deviceStorage->add($device);
    }


    function getNodes($nodeTypeIds = [])
    {
        $splitters = [];
        foreach ($this->usersideAPI->getSplitters() as $splitter) {
            $splitters[$splitter['node_id']][] = $splitter;
        }

        $nodes = [];
        foreach ($nodeTypeIds as $nodeTypeId) {
            foreach ($this->usersideAPI->getNodes($nodeTypeId) as $box) {
                $box['type_id'] = $nodeTypeId;
                $box['number'] = isset($box['number']) ? trim(html_entity_decode($box['number'])): null;
                $box['splitters'] = isset($splitters[$box['id']]) ? $splitters[$box['id']] : [];
                $nodes[$box['id']] = $box;
            }
        }
        return $nodes;
    }



    /**
     * @var OutputInterface
     */
    protected $_output;

    function setConsoleOutput(OutputInterface $output)
    {
        $this->_output = $output;
        return $this;
    }

}
