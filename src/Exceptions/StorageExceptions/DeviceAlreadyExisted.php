<?php


namespace WCAA\Exceptions\StorageExceptions;


use WCAA\Exceptions\ApiExceptionCodes;
use WCAA\Exceptions\SupportException;

class DeviceAlreadyExisted extends SupportException
{
    protected $type  = 'DEVICE_ALREADY_EXIST';
}