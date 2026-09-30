<?php

namespace WCAA\Api\Actions\Dashboard\Widgets;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\UserRoleStorage;
use WCAA\Storage\UserStorage;

class SystemStat extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

    /**
     * @Inject
     * @var UserRoleStorage
     */
    protected $roleStorage;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    protected function action(): Response
    {
        $data = [
           'users' => $this->userStorage->count("id > 0"),
           'roles' => $this->roleStorage->count("display = 1"),
           'devices' => $this->deviceStorage->count(),
           'device_groups' => $this->deviceGroupStorage->count(),
           'interfaces' => $this->deviceInterfaceStorage->count(),
        ];
        return $this->respondWithData($data);
    }

}