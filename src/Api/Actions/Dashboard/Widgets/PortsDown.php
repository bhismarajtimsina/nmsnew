<?php

namespace WCAA\Api\Actions\Dashboard\Widgets;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\ConsoleWidgetsStorage;

/**
 * Ports down right now, longest first. ONTs are not included.
 *
 * Scoped to the caller's device groups by the storage, so a reseller sees
 * their own equipment and an unrestricted role sees everything.
 */
class PortsDown extends PrivateAction
{
    /**
     * @Inject
     * @var ConsoleWidgetsStorage
     */
    protected $widgets;

    protected function action(): Response
    {
        return $this->respondWithData($this->widgets->portsDown($this->user, (int)($this->request->getQueryParams()["limit"] ?? 50)));
    }
}
