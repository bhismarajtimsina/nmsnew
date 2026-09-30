<?php

namespace WCAA\Api\Actions\Devices\Interfaces;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\ConsoleWidgetsStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;

/**
 * How often this subscriber has dropped, and whose fault each time was.
 *
 * The raw history is already available. This answers the question support is
 * actually asked instead: whether the drops are the customer's own power or
 * the fibre.
 */
class GetDropSummary extends PrivateAction
{
    /**
     * @Inject
     * @var ConsoleWidgetsStorage
     */
    protected $widgets;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $interfaceStorage;

    protected function action(): Response
    {
        $id = (int)$this->request->getAttribute('interface_id');
        // Resolve through the interface storage first, so a caller cannot read
        // the history of an interface the rest of the app would not show them.
        $iface = $this->interfaceStorage->getById($id);
        $days = (int)($this->request->getQueryParams()['days'] ?? 30);
        $summary = $this->widgets->dropSummary($id, $days);
        $summary['interface'] = [
            'id' => $iface->getId(),
            'name' => $iface->getName(),
            'description' => $iface->getDescription(),
            'status' => $iface->getStatus(),
        ];
        return $this->respondWithData($summary);
    }
}
