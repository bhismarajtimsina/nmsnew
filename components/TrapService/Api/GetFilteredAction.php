<?php

namespace WCC\TrapService\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\Paginator\DbPagination;
use WCAA\Infrastructure\Paginator\Paginator;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\TrapService\Controllers\Controller;
use WCC\TrapService\Models\TrapFilter;

class GetFilteredAction extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $interfaceStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    protected function action(): Response
    {
        try {
            $form = $this->getFormData();
        } catch (\Throwable $e) {
            $form = $this->request->getQueryParams();
        }
        $filter = $this->createFilter($form);
        $paginator = new DbPagination(Paginator::initFromRequest($this->request));
        $resp = [];
        foreach ($this->controller->getFilteredActions($filter, $paginator) as $r) {
            $dt = $r->getAsArray();
            if ($dt['device']) {
                $dt['device'] = [
                    'id' => $dt['device']['id'],
                    'name' => $dt['device']['name'],
                    'ip' => $dt['device']['ip'],
                    'model' => [
                        'id' => $dt['device']['model']['id'],
                        'name' => $dt['device']['model']['name'],
                    ],
                ];
            }
            if ($dt['interface']) {
                $dt['interface'] = [
                    'id' => $dt['interface']['id'],
                    'name' => $dt['interface']['name'],
                    'bind_key' => $dt['interface']['bind_key'],
                    'type' => $dt['interface']['type'],
                ];
            }
            $resp[] = $dt;
        }
        return $this->respondWithData($resp, $paginator->getPaginator()->getMeta());
    }

    protected function createFilter($form)
    {
        $filter = new TrapFilter();
        $filter->setUser($this->user);
        if (isset($form['device_id']) && $form['device_id']) {
            $filter->setDevice($this->deviceStorage->getById($form['device_id']));
        }
        if (isset($form['device']['id']) && $form['device']['id']) {
            $filter->setDevice($this->deviceStorage->getById($form['device']['id']));
        }
        if (isset($form['interface']['id']) && $form['interface']['id']) {
            $filter->setInterface($this->interfaceStorage->getById($form['interface']['id']));
        }
        if (isset($form['names'])) {
            $filter->setNames($form['names']);
        }
        if (isset($form['object'])) {
            $filter->setObject($form['object']);
        }
        if (isset($form['start']) && $form['start'] && isset($form['end']) && $form['end']) {
            $filter->setTimePeriod($form['start'], $form['end']);
        }
        return $filter;
    }
}