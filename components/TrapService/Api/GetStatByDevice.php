<?php

namespace WCC\TrapService\Api;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Interfaces\CacheInterface;
use WCC\TrapService\Storage\TrapLogStorage;

class GetStatByDevice extends PrivateAction
{
    /**
     * @Inject
     * @var TrapLogStorage
     */
    protected $TrapServiceStorage;

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    protected function action(): Response
    {
        return $this->respondWithData($this->TrapServiceStorage->getStatByEventName(true, $this->user));
    }

}