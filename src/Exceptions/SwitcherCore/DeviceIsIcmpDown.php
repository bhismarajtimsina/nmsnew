<?php


namespace WCAA\Exceptions\SwitcherCore;


use Throwable;
use WCAA\Exceptions\SupportException;

class DeviceIsIcmpDown extends SupportException
{
    protected $type = "SWC_ICMP_NOT_RESPOND";
}