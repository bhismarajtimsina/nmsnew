<?php

namespace WCAA\Interfaces;

use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;

interface ControllerInterface
{
    /**
     * @param Device $device
     * @return self
     */
    function setDevice(Device $device);

    /**
     * @param User $user
     * @return self
     */
    function setUser(User $user);

}