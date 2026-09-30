<?php


namespace WCAA\Api\Actions\System\Actions;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Storage\UserStorage;

/**
 * @OA\Post(
 *   path="/logs/action",
 *   tags={"system-logs"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create system action log record",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="action", type="string", nullable=true),
 *       @OA\Property(property="message", type="string", nullable=true),
 *       @OA\Property(property="status", type="string", nullable=true),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(property="device", type="object", nullable=true, @OA\Property(property="id", type="integer")),
 *       @OA\Property(property="user", type="object", nullable=true, @OA\Property(property="id", type="integer"))
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Created action log",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/SystemAction")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class AddNewAction extends PrivateAction
{
    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $actionStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;
    /**
     * @return Response
     * @throws HttpBadRequestException
     */
    protected function action(): Response
    {
        $data = $this->getFormData();
        $action = new SystemAction();
        if(isset($data['action'])) {
            $action->setAction($data['action']);
        }
        if(isset($data['device']['id'])) {
            $action->setDevice($this->deviceStorage->getById($data['device']['id']));
        }
        if(isset($data['user']['id'])) {
            $action->setUser($this->userStorage->getById($data['user']['id']));
        }
        if(isset($data['message'])) {
            $action->setMessage($data['message']);
        }
        if(isset($data['status'])) {
            $action->setStatus($data['status']);
        }
        if(isset($data['meta'])) {
            $action->setMeta($data['meta']);
        }
        $action = $this->actionStorage->add($action);
        return $this->respondWithData($action->getAsArray());
    }

}
