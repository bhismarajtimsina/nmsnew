<?php


namespace WCAA\Exceptions\SwitcherCore;


use Throwable;
use WCAA\Exceptions\SupportException;

class CacheNotFound extends SupportException
{
    protected $type = "SWC_CACHE_NOT_FOUND";
}