<?php


namespace WCAA\Exceptions\StorageExceptions;


use WCAA\Exceptions\ApiExceptionCodes;
use WCAA\Exceptions\SupportException;

class NotEnoughtRightsException extends SupportException
{
    protected $type  = 'NOT_ENOUTH_RIGHTS';
}