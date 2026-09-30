<?php

namespace WCC\SensorDevices\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

class GetDisabledModulesState extends SensorApiControllerAbstract
{
    function call()
    {
        $form = $this->getFormData();
        $sensorType = $this->request->getAttribute('type');
        $config = $this->controller->getModulesDisabled();
        return $this->respondWithData($config);
    }
}