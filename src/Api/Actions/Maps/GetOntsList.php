<?php

namespace WCAA\Api\Actions\Maps;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\DeviceGroup;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCC\Links\Models\Link;
use WCC\Links\Storage\LinkStorage;
use WCC\PrometheusWrapper\Controllers\PrometheusMetricsTempStore;

class GetOntsList extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;


    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var OntIdentStorage
     */
    protected $ontIdentStorage;

    /**
     * @Inject
     * @var PrometheusMetricsTempStore
     */
    protected $promStorage;

    protected function action(): Response
    {
        $allowedGroups = $this->getDeviceGroupsIdsFromUser();

        $data = $this->getFormData();
        $groups = [];
        if (isset($data['groups'])) {
            foreach ($data['groups'] as $gr) {
                if (in_array($gr['id'], $allowedGroups)) {
                    $groups[] = new DeviceGroup($gr['id']);
                }
            }
        } else {
            foreach ($allowedGroups as $gr) {
                $groups[] = new DeviceGroup($gr);
            }
        }
        $response = [];
        $opticalData = $this->getOpticalInfo();
        $ontIdents = [];
        foreach ($this->ontIdentStorage->fetchAll(false) as $ident) {
            $ontIdents[$ident->interface_id] = $ident;
        }
        $allDevices = [];
        foreach ($groups as $group) {
            foreach ($this->deviceStorage->fetchByDeviceGroup($group, true) as $device) {
                $allDevices[] = $device;
            }
        }
        $ifacesByDevice = $this->deviceInterfaceStorage->getByDevicesGrouped($allDevices, 'ONU', true);
        foreach ($allDevices as $device) {
                foreach ($ifacesByDevice[$device->getId()] ?? [] as $iface) {
                    if(!$iface->getCoordinates()) continue;
                    $resp = $iface->getAsArrayLite();
                    if (isset($opticalData["{$iface->getDevice()->getId()}-{$iface->getBindKey()}"])) {
                        $optical = $opticalData["{$iface->getDevice()->getId()}-{$iface->getBindKey()}"];
                        $resp['optical'] = [
                            'rx' => isset($optical['optical_rx']) ? round((float)$optical['optical_rx'], 2) : null,
                            'olt_rx' => isset($optical['optical_olt_rx']) ? (float)$optical['optical_olt_rx'] : null,
                            'tx' => isset($optical['optical_tx']) ? (float)$optical['optical_tx'] : null,
                            'temperature' => isset($optical['optical_temperature']) ? (float)$optical['optical_temperature'] : null,
                            'distance' => isset($optical['optical_distance']) ? (float)$optical['optical_distance'] : null,
                        ];
                    } else {
                        $resp['optical'] = [
                            'rx' => null,
                            'olt_rx' => null,
                            'tx' => null,
                            'temperature' => null,
                            'distance' => null,
                        ];
                    }
                    $resp['ident'] = null;
                    if(isset($ontIdents[$iface->getId()])) {
                        $resp['ident'] = $ontIdents[$iface->getId()]->getIdent();
                    }
                    $response[] = $resp;
                }
        }
        return $this->respondWithData($response);
    }

    function getOpticalInfo()
    {
        $metrics = $this->promStorage->getLastByMetrics([
            'optical_rx',
            'optical_olt_rx',
            'optical_tx',
            'optical_temperature',
            'optical_distance',
        ]);
        $opticalData = [];
        foreach ($metrics as $metricName => $values) {
            foreach ($values as $value) {
                $opticalData["{$value['labels']['dev_id']}-{$value['labels']['iface_id']}"][$metricName] = $value['value'];
            }
        }
        return $opticalData;
    }
}