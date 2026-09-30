<?php
declare(strict_types=1);

namespace WCAA\Api\Actions;

use Monolog\Logger;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Infrastructure\Pagination;
use WCAA\Storage\Exceptions\RecordNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

abstract class ModuleAction extends PrivateAction
{
    protected $method;
    protected $path;

    /**
     * @return mixed
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @param mixed $method
     * @return ModuleAction
     */
    public function setMethod($method)
    {
        $this->method = $method;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @param mixed $path
     * @return ModuleAction
     */
    public function setPath($path)
    {
        $this->path = $path;
        return $this;
    }
}
