<?php

namespace WCAA\Api\Actions\Dashboard\Widgets;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\ConsoleWidgetsStorage;

/**
 * Why subscribers are offline: their own power, or your fibre.
 *
 * Scoped to the caller's device groups by the storage, so a reseller sees
 * their own equipment and an unrestricted role sees everything.
 */
class OntOfflineSplit extends PrivateAction
{
    /**
     * @Inject
     * @var ConsoleWidgetsStorage
     */
    protected $widgets;

    protected function action(): Response
    {
        return $this->respondWithData($this->widgets->ontOfflineSplit($this->user));
    }
}
