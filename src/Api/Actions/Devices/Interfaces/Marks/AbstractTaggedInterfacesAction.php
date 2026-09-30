<?php

namespace WCAA\Api\Actions\Devices\Interfaces\Marks;

use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceInterfaceTagStorage;
use WCAA\Storage\Devices\DeviceStorage;

abstract class AbstractTaggedInterfacesAction extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfacesStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceTagStorage
     */
    protected $tagsStorage;
}