<?php

namespace WCC\SensorDevices\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

class ToggleSensor extends SensorApiControllerAbstract
{
    protected $forbiddenInDemo = true;

    function call()
    {
        $form = $this->getFormData();

        $sensorType = $this->request->getAttribute('type');
        $sensorId = $this->request->getAttribute('id');

        try {
            switch ($sensorType) {
                case 'digital_lines_list': $response = $this->controller->controlDigitalLine($sensorId, ['output' => $form['output']]); break;
                case 'power_control_output_list': $response = $this->controller->controlPowerOutput($sensorId, ['mode' => $form['mode']]); break;
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