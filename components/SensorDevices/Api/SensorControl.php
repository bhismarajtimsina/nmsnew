<?php

namespace WCC\SensorDevices\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

class SensorControl extends SensorApiControllerAbstract
{
    protected $forbiddenInDemo = true;

    function call()
    {
        $form = $this->getFormData();

        $sensorType = $this->request->getAttribute('type');
        $sensorId = $this->request->getAttribute('id');

        try {
            $response = null;
            switch ($sensorType) {
                case 'digital_sensor': $response = $this->controller->controlDigitalLine($sensorId, $form); break;
                case 'analog_sensor': $response = $this->controller->controlAnalogLine($sensorId, $form); break;
                case 'power_control': $response = $this->controller->controlPowerOutput($sensorId, $form); break;
                default:
                    throw new HttpBadRequestException($this->request, "Unsupported sensor type $sensorType for edit. Possible types - digital_sensor|analog_sensor|power_control");
            }
            $this->addActionSuccess("sensors:edit_{$sensorType}", "Success edit $sensorType with ID {$sensorId} on device {$this->device->getName()}",  [
                'form' => $form,
                'sensor_type' => $sensorType,
                'sensor_id' => $sensorId,
                'device_id' => $this->device->getId(),
            ], $this->device);
            return $this->respondWithData($response);
        } catch (\Exception $e) {
            $this->addActionFailed("sensors:edit_{$sensorType}", "Failed edit $sensorType with ID {$sensorId} on device {$this->device->getName()}", $e, [
                'form' => $form,
                'sensor_type' => $sensorType,
                'sensor_id' => $sensorId,
                'device_id' => $this->device->getId(),
            ], $this->device);
            throw $e;
        }
    }
}