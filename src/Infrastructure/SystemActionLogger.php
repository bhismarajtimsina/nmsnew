<?php


namespace WCAA\Infrastructure;


use DI\Container;
use WCAA\App;
use WCAA\Models\SystemAction;
use WCAA\Models\User\User;
use WCAA\Storage\SystemActionsStorage;

class SystemActionLogger
{
    protected $systemActionStorage;
    /**
     * @var User|null
     */
    protected $user = null;
    function __construct(Container $container, SystemActionsStorage $storage) {
        if($container->has(User::class)) {
            $this->user = $container->get(User::class);
        }
        $this->systemActionStorage = $storage;
    }

    function success($actionName, $message, $meta = [], $device = null,  $user = null) {
        if(!$user) {
            $user = $this->user;
        }
        if(!$user) {
            throw new \Exception("User not setted, but required for logging");
        }
        return $this->systemActionStorage->add(
            (new SystemAction())
                ->setUser($user)
                ->setAction($actionName)
                ->setMessage($message)
                ->setMeta($meta)
                ->setDevice($device)
                ->setStatus(SystemAction::STATUS_SUCCESS)
        );
    }

    function failed($actionName, $message, $meta = [], $device = null, $user = null) {
        if(!$user) {
            $user = $this->user;
        }
        if(!$user) {
            throw new \Exception("User not setted, but required for logging");
        }
        return $this->systemActionStorage->add(
            (new SystemAction())
                ->setUser($user)
                ->setAction($actionName)
                ->setMessage($message)
                ->setDevice($device)
                ->setMeta($meta)
                ->setStatus(SystemAction::STATUS_FAILED)
        );
    }

}