<?php

namespace WCC\SensorDevices\Api;

use Psr\Http\Message\ResponseInterface as Response;

class ListSensors extends SensorApiControllerAbstract
{
    function call()
    {
        $from = 'cache';
        $loadOnly = null;
        if(isset($this->request->getQueryParams()['from'])) {
            $from = $this->request->getQueryParams()['from'];
        }
        if(isset($this->request->getQueryParams()['load_only'])) {
            $loadOnly = explode(",", trim($this->request->getQueryParams()['load_only']));
        }
        try {
            $sensorsByTypes = $this->controller->getSensors($from, $loadOnly);
            foreach ($sensorsByTypes as $type=>$sensors) {
                if($sensors) {
                    foreach ($sensors as $id=>$sensor) {
                        if(isset($sensor['id'])) {
                            $sensorsByTypes[$type][$id]['config'] = $this->controller->getSensorConfiguration($type, $sensor['id']);
                            $sensorsByTypes[$type][$id]['alerts'] = array_map(function ($f) {
                                return $f->getAsArrayLite();
                            },$this->controller->getActiveAlertsBySensor($type, $sensor['id']));
                        }
                    }
                }
            }
            $this->addActionSuccess("sensors:statuses", "Returned status of sensors from device {$this->device->getName()}",  [
                'load_only' => $loadOnly,
                'from' => $from,
                'device_id' => $this->device->getId(),
            ], $this->device);
            return $this->respondWithData($sensorsByTypes, $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->addActionFailed("sensors:statuses", "Error loading status of sensors from device {$this->device->getName()}", $e, [
                'load_only' => $loadOnly,
                'from' => $from,
                'device_id' => $this->device->getId(),
            ], $this->device);
            throw $e;
        }
    }
}