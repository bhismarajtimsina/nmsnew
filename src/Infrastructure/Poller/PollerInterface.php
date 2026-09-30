<?php

namespace WCAA\Infrastructure\Poller;

use WCAA\Interfaces\ControllerInterface;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;

interface PollerInterface
{
    function poll(Device $device, ControllerInterface $controller);
    function setManualByInterface(Device $device, $data);
    function setManual(Device $device, $data = []);
    //    function getByInterface(DeviceInterface $interface);
//    function getByDevice(Device $device);
}