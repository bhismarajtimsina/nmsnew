<?php

namespace WCC\Links\Exceptions;

use WCAA\Exceptions\SupportException;

class RootDeviceNotFound extends SupportException
{
    protected $type = "ROOT_DEVICE_NOT_FOUND";
}