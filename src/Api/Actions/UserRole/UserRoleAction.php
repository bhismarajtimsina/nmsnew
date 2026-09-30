<?php


namespace WCAA\Api\Actions\UserRole;


use DI\Annotation\Inject;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Interfaces\CacheInterface;
use WCAA\Storage\UserRoleStorage;

abstract class UserRoleAction extends PrivateAction
{
    /**
     * @Inject
     * @var UserRoleStorage
     */
    protected $groupStorage;

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;
}