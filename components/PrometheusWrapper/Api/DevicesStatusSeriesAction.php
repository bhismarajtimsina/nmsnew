<?php

namespace WCC\PrometheusWrapper\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\PrometheusWrapper\Controllers\Controller;

class DevicesStatusSeriesAction extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    protected function action(): Response
    {
       $form = $this->getFormData();
       $this->fillDefaultKeys([
           'start' => null,
           'end' => null,
           'step' => null,
       ], $form);
       if(!$form['step']) $form['step'] = '10m';
       return  $this->respondWithData($this->controller->convertToChartFormat($this->controller->deviceStatusesSeries($form['start'], $form['end'], $form['step'])));
    }


}