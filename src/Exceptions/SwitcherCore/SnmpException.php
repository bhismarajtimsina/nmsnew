<?php


namespace WCAA\Exceptions\SwitcherCore;


use Throwable;
use WCAA\Exceptions\SupportException;

class SnmpException extends SupportException
{
    protected $type = "SWC_SNMP_EXCEPTION";
}