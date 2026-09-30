<?php


namespace WCC\FdbHistory\Api;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\SystemAction;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
/**
 * @OA\Post(
 *   path="/component/fdb_history/filter",
 *   tags={"fdb-history"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get FDB history by filter",
 *   @OA\Parameter(name="active", in="query", required=false, @OA\Schema(type="string", enum={"yes","no"}, example="yes")),
 *   @OA\RequestBody(
 *     required=false,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="device", type="integer", example=101),
 *       @OA\Property(property="interface", type="string", example="10001")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="FDB history records",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true))
 *     )
 *   )
 * )
 */

class GetByFilterAction extends PrivateAction
{
    
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $interfaceStorage;

    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    /**
     * @Inject
     * @var FdbHistoryStorage
     */
    protected $historyStorage;

    /**
     * @return Response
     */

    protected function action(): Response
    {
        $queries = $this->request->getQueryParams();
        $onlyActive = isset($queries['active']) && $queries['active'] == 'yes';
        $data = $this->getFormData(true);

        $deviceId = $data['device'] ?? null;
        $interfaceKey  = $data['interface'] ?? null;

        if (!$deviceId || !$interfaceKey) {
            throw new \Slim\Exception\HttpBadRequestException($this->request, "Fields `device` and `interface` are required.");
        }

        $dev = $this->deviceStorage->getById((int)$deviceId);
        $interface = $this->interfaceStorage->getByDeviceAndKey($dev, $interfaceKey);
       try {
            $response = [];
            foreach ($this->historyStorage->getByInterface($interface, $onlyActive) as $d)  {
                $response[] = $d->getAsArray();
            }
            return $this->respondWithData($response);
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'fdb_history:interface_info',
                SystemAction::STATUS_FAILED,
                "Requested interfaces list on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev, 'error' => [
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