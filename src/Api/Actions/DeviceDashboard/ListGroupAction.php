<?php


namespace WCAA\Api\Actions\DeviceDashboard;


use DI\Annotation\Inject;
use Monolog\Logger;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Pinger\Controllers\Controller;

class ListGroupAction extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $interfacesStorage;


    /**
     * @var Controller
     */
    protected $pingerStatuses;


    function __construct(ComponentInjector $componentController, Logger $logger)
    {
        parent::__construct($logger);
        if ($componentController->isComponentEnabled('pinger')) {
            $this->pingerStatuses = $componentController->getController('pinger');
        }
    }

    /**
     * @return Response
     */
    protected function action(): Response
    {
        if ($id = $this->request->getAttribute('id')) {
            return $this->respondWithData($this->storage->getById($id)->getAsArray());
        }
        $groups = [];
        foreach ($this->user->getDeviceGroups() as $group) {
            $gr = $group->getAsArray();
            $groups[] = $gr;
        }
        return $this->respondWithData($groups);
    }
}
