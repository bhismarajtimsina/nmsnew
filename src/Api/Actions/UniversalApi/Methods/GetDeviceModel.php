<?php

namespace WCAA\Api\Actions\UniversalApi\Methods;

use Psr\Http\Message\ResponseInterface as Response;
use WCAA\App;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;

class GetDeviceModel extends AbstractMethod
{
    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $modelStorage;

    /**
     * @Inject
     * @var App
     */
    protected $app;

    protected function action()
    {
        $resp = [];
        $modelMapTypes = $this->app->conf('universal_api.type_mapper');
        foreach ($this->modelStorage->fetchAll() as $model) {
            $resp[$model->getId()] = [
                'id' => $model->getId(),
                'type_id' => $modelMapTypes[$model->getType()],
                'name' => $model->getName(),
                'iface_count' => 0,
            ];
        }
        if(isset($this->request->getQueryParams()['device_type'])) {
            $types = explode(',', $this->request->getQueryParams()['device_type']);
            $resp = array_filter($resp, function ($e) use ($types) {
                return in_array($e['type_id'], $types);
            });
        }
        ksort($resp);
        return $resp;
    }

}