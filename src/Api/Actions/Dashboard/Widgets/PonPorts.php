<?php

namespace WCAA\Api\Actions\Dashboard\Widgets;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\ConsoleWidgetsStorage;

/**
 * Every PON port: what is on it, what is working, and what is left to sell.
 *
 * Scoped to the caller's device groups by the storage.
 */
class PonPorts extends PrivateAction
{
    /**
     * @Inject
     * @var ConsoleWidgetsStorage
     */
    protected $widgets;

    protected function action(): Response
    {
        $capacity = (int)($this->request->getQueryParams()['capacity'] ?? 128);
        return $this->respondWithData($this->widgets->ponPorts($this->user, $capacity));
    }
}
