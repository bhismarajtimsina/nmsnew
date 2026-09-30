<?php


namespace WCAA\Api\Actions\SwitcherCore;


use Monolog\Logger;
use OpenApi\Annotations as OA;
use SwitcherCore\Config\ModelCollector;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;

/**
 * @OA\Get(
 *   path="/switcher-core/{device_id}/modules",
 *   tags={"switcher-core"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get available SwitcherCore modules for device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Modules list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="string", example="system"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class GetModulesList extends PrivateAction
{
    /**
     * @Inject
     * @var ModelCollector
     */
    protected $modelCollector;

    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $devStorage;


    protected function action(): Response
    {
        $id = $this->request->getAttribute('device_id');
        $device = $this->devStorage->getById($id);
        return $this->respondWithData($this->modelCollector->getModelByKey($device->getModel()->getKey())->getModulesList());
    }
}
