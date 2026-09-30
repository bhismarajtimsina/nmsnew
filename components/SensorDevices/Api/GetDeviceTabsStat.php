<?php


namespace WCC\SensorDevices\Api;


use DI\Annotation\Inject;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;

class GetDeviceTabsStat extends SensorApiControllerAbstract
{
    /**
     * @return Response
     */
    function call(): Response
    {
        return $this->respondWithData($this->controller->getDeviceStats());
    }


}