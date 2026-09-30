<?php

namespace WCC\AutoDiscovery\Api;

use DI\Annotation\Inject;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCC\AutoDiscovery\Controllers\Controller;
use WCC\AutoDiscovery\Storage\AutoDiscoveryNetworksStorage;

abstract class AbstractAutoDiscovery extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupsStorage;
    /**
     * @Inject
     * @var AutoDiscoveryNetworksStorage
     */
    protected $storage;
}