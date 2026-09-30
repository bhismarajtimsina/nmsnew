<?php


namespace WCAA\Api\Actions\User;


use DI\Annotation\Inject;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\App;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\UserRoleStorage;
use WCAA\Storage\UserStorage;

abstract class UserAction extends PrivateAction
{


    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupsStorage;

    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;
    /**
     * @Inject
     * @var UserRoleStorage
     */
    protected $groupStorage;

}
