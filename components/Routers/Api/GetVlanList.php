<?php


namespace WCC\Routers\Api;

use OpenApi\Annotations as OA;


use DI\Annotation\Inject;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\Routers\Controllers\RouterController;
/**
 * @OA\Get(
 *   path="/component/routers/vlans/{device}",
 *   tags={"routers"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get router VLANs",
 *   @OA\Parameter(name="device", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="from", in="query", required=false, @OA\Schema(type="string", enum={"device","cache","store"}, default="cache")),
 *   @OA\Response(response=200, description="VLANs list", @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)), @OA\Property(property="meta", type="object", additionalProperties=true)))
 * )
 */

class GetVlanList extends PrivateAction
{

    /**
     * @Inject
     * @var RouterController
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;


    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    

    protected function action(): Response
    {
        $queries = $this->request->getQueryParams();
        $from = isset($queries['from']) ? $queries['from'] : 'cache';
        $dev = new Device($this->request->getAttribute('device'));
        try {
            $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
            $this->controller->setDevice($dev)->setUser($this->user);
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'switches:vlans',
                SystemAction::STATUS_SUCCESS,
                "Requested vlans info on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev->getAsArray()]
            ));

            return $this->respondWithData($this->controller->getVlans($from), $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'switches:vlans',
                SystemAction::STATUS_FAILED,
                "Requested vlans info on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev->getAsArray(), 'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'trace' => $e->getTraceAsString(),
                ]]
            ));
            throw $e;
        }
    }

}
