<?php

namespace WCC\SensorDevices\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

class SensorEditConfig extends SensorApiControllerAbstract
{
    protected $forbiddenInDemo = true;

    function call()
    {
        $form = $this->getFormData();
        $sensorType = $this->request->getAttribute('type');
        $sensorId = $this->request->getAttribute('id');
        $config = $this->controller->updateSensorConfiguration($sensorType, $sensorId, $form);
        return $this->respondWithData($config);
    }
}