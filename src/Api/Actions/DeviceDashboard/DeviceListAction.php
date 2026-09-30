<?php


namespace WCAA\Api\Actions\DeviceDashboard;


use Monolog\Logger;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Models\Pollers\PollerProcessing;
use WCAA\Models\Devices\Device;
use WCAA\Storage\PollerData\PollerProcessingStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCC\Pinger\Controllers\Controller;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;

class DeviceListAction extends PrivateAction
{

    /**
     * @var DeviceStorage
     */
    protected $storage;



    /**
     * @var DeviceInterfaceStorage
     */
    protected $interfacesStorage;


    /**
     * @var Controller
     */
    protected $pingerStatuses;

    /**
     * @param DeviceStorage $storage
     * @param Logger $logger
     */
    function __construct(DeviceInterfaceStorage $ifacesStorage, ComponentInjector $componentController, DeviceStorage $storage, Logger $logger)
    {
        $this->storage = $storage;
        $this->interfacesStorage = $ifacesStorage;

        if($componentController->isComponentEnabled('pinger')) {
            $this->pingerStatuses = $componentController->getController('pinger');
        }

        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $devices = [];
        $body = [];
        try {
            $body = $this->getFormData();
        } catch (\Exception $e) {}
        $params = $this->request->getQueryParams();
        if (isset($params['query'])) {
            $list = $this->storage->getBySearchLine(strtolower($params['query']));
        } elseif (isset($body['query'])) {
            $list = $this->storage->getBySearchLine(strtolower($body['query']));
        } else {
            $list = $this->storage->fetchAll();
        }

        $list = array_filter($list, function ($device) {
           return $device->isEnabled();
        });

        if(isset($params['groups']) && $params['groups']) {
            foreach ($list as $num=>$device) {
                if(!array_filter($params['groups'], function ($group) use ($device) {
                    return $group['id'] == $device->getGroup()->getId();
                })) {
                    unset($list[$num]);
                }
            }
        }

        if(isset($body['models']) && $body['models']) {
            foreach ($list as $num=>$device) {
                if(!array_filter($body['models'], function ($model) use ($device) {
                    return $model['id'] == $device->getModel()->getId();
                })) {
                    unset($list[$num]);
                }
            }
        }

        $pingerStatuses = [];
        if($this->pingerStatuses) foreach ($this->pingerStatuses->getPingerDeviceStatuses(false) as $status) {
            $stArr = $status->getAsArray();
            unset($stArr['device']);
            $pingerStatuses[$status->device_id] = $stArr;
        }

        $interfacesStat = [];
        if(!_env('DEVICES_LIST_DISABLE_IFACE_STAT', false)) {
            foreach ($this->interfacesStorage->getStatByDeviceId() as $devId => $stat) {
                unset($stat['device_id']);
                $interfacesStat[$devId] = $stat;
            }
        }


        $userDeviceGroups = $this->user->getDeviceGroups();
        foreach ($list as $device) {
            if(!array_filter($userDeviceGroups, function ($e) use ($device){
                    return $device->getGroup()->getId() === $e->getId();
                }) && ($this->user->getId() > 0 && $this->user->getRole()->getId() > 0)) {
                continue;
            }
            $dev = $device->getAsArray();
            unset($dev['model']['params']);
            unset($dev['model']['pollers']);
            unset($dev['model']['controller']);
            unset($dev['access']);
            unset($dev['params']);
            unset($dev['group']['created_at']);
            unset($dev['mac']);
            unset($dev['serial']);
            unset($dev['coordinates']);
            unset($dev['created_at']);
            unset($dev['group']['description']);
            if(isset($pingerStatuses[$dev['id']])) {
                $dev['pinger'] = $pingerStatuses[$dev['id']];
            } else {
                $dev['pinger'] = null;
            }
            if(isset($interfacesStat[$dev['id']])) {
                $dev['ifaces_stat'] = $interfacesStat[$dev['id']];
            } else {
                $dev['ifaces_stat'] = null;
            }
            unset($dev['pinger']['id']);
            unset($dev['pinger']['last_change']);
            $devices[] = $dev;
        }

        if(isset($this->request->getQueryParams()['sort'])) {
            switch ($this->request->getQueryParams()['sort']) {
                case 'ip':
                    usort($devices, function ($elem1, $elem2) {
                        return inet_pton($elem1['ip']) > inet_pton($elem2['ip']);
                    });
                    break;
                case 'name':
                    usort($devices, function ($elem1, $elem2) {
                        return $elem1['name'] > $elem2['name'];
                    });
                    break;
                case 'location':
                    usort($devices, function ($elem1, $elem2) {
                        return $elem1['location'] > $elem2['location'];
                    });
                    break;
            }
        }

        foreach ($devices as $id=>$device) {
            $sorting = $id + 1;
            if(isset($device['pinger']['latency'])) {
                $latency = $device['pinger']['latency'];
                if($latency <= 0) {
                    $latency = -1;
                } else {
                    $latency = 1;
                }
                $sorting = $sorting + (1000000 * $latency);
            }
            $devices[$id]['sort'] = $sorting;
        }

        if(isset($this->request->getQueryParams()['down_on_top']) && $this->request->getQueryParams()['down_on_top'] == 'yes') {
            usort($devices, function ($elem1, $elem2)  {
                return $elem1['sort'] > $elem2['sort'];
            });
        }

        $data = $this->paginationFromParams($devices);
        return $this->respondWithData($data['data'], $data['meta']);
    }
}
