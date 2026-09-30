<?php


namespace WCAA\Exceptions;


use Throwable;

class SupportException extends \Exception
{

    protected $type  = ApiExceptionCodes::GENERAL_SERVER_ERROR;
    function __construct($message = "", $code = 500, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
    function setType($type) {
        $this->type = $type;
        return $this;
    }
    function getType() {
        return $this->type;
    }

}