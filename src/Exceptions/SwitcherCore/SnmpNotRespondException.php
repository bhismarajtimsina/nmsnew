<?php


namespace WCAA\Exceptions\SwitcherCore;


use Throwable;
use WCAA\Exceptions\SupportException;

class SnmpNotRespondException extends SupportException
{
    protected $type = "SWC_SNMP_NOT_RESPOND";
}