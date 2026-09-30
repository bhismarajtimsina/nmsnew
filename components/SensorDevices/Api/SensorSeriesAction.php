<?php

namespace WCC\SensorDevices\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCC\PrometheusWrapper\Controllers\Controller;

class SensorSeriesAction extends SensorApiControllerAbstract
{
    function call(): Response
    {
        $form = $this->getFormData();
        $this->fillDefaultKeys([
            'start' => null,
            'end' => null,
            'step' => null,
        ], $form);
        if(!$form['step']) $form['step'] = '10m';
        return  $this->respondWithData($this->controller->getSeriesForSensor($this->request->getAttribute('type'), $this->request->getAttribute('id'), $form['start'], $form['end'], $form['step']));
    }
}