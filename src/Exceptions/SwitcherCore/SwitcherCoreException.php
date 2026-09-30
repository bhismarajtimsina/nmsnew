<?php


namespace WCAA\Exceptions\SwitcherCore;


use Throwable;
use WCAA\Exceptions\SupportException;

class SwitcherCoreException extends SupportException
{
    protected $type = "SWC_ERROR_WORK_WITH_DEVICE";
}