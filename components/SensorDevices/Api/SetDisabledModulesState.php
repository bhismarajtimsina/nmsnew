<?php

namespace WCC\SensorDevices\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

class SetDisabledModulesState extends SensorApiControllerAbstract
{
    protected $forbiddenInDemo = true;

    function call()
    {
        $form = $this->getFormData();
        $sensorType = $this->request->getAttribute('type');
        $config = $this->controller->setModuleDisabled($sensorType, $form['disabled']);
        return $this->respondWithData($config);
    }
}