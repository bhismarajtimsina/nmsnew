<?php


namespace WCAA\Api\Actions\System\SwitcherCoreActions;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\SwitcherCoreActionStorage;
use WCAA\Storage\SystemActionsStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Post(
 *   path="/logs/switcher-core/actions",
 *   tags={"system-switcher-core"},
 *   security={{"XAuthKey": {}}},
 *   summary="Filter switcher-core action logs",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"start","stop"},
 *       @OA\Property(property="start", type="string", example="2026-02-25 00:00:00"),
 *       @OA\Property(property="stop", type="string", example="2026-02-25 23:59:59"),
 *       @OA\Property(property="modules", type="array", @OA\Items(type="string")),
 *       @OA\Property(property="users", type="array", @OA\Items(type="integer")),
 *       @OA\Property(property="device_id", type="integer", nullable=true),
 *       @OA\Property(property="status", type="string", nullable=true),
 *       @OA\Property(property="query", type="string", nullable=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Switcher-core action logs",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/SwitcherCoreActionLog"))
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetSwitcherCoreAction extends PrivateAction
{
    /**
     * @Inject
     * @var SwitcherCoreActionStorage
     */
    protected $actionStorage;

    /**
     * @return Response
     * @throws HttpBadRequestException
     */
    protected function action(): Response
    {
        $data = $this->getFormData();
        if (!isset($data['start']) || !isset($data['stop'])) {
            throw new HttpBadRequestException($this->request, "Start and stop period is required");
        }
        if (!isset($data['modules'])) {
            $data['modules'] = [];
        }
        if (!isset($data['users'])) {
            $data['users'] = [];
        }
        if (!isset($data['device_id'])) {
            $data['device_id'] = null;
        }
        if (!isset($data['status'])) {
            $data['status'] = '';
        }
        $resp = $this->actionStorage->getActionsByFilter($data['start'], $data['stop'], $data['modules'], $data['users'], $data['status'], $data['device_id']);
        $response = [];
        foreach ($resp as $d) {
            if (
                isset($data['query']) &&
                trim($data['query']) !== '' &&
                strpos(json_encode($d->getAsArray(), JSON_UNESCAPED_UNICODE), $data['query']) !== false
            ) {
                $action = $d->getAsArray();
            } elseif (
                !isset($data['query']) ||
                trim($data['query']) === ''
            ) {
                $action = $d->getAsArray();
            } else {
                continue;
            }
           $action['device']['model'] = [
               'id' => $action['device']['model']['id'],
               'name' => $action['device']['model']['name'],
           ];
            $action['user'] = [
                'id' => $action['user']['id'],
                'name' => $action['user']['name'],
                'role' => [
                    'id' => $action['user']['role']['id'],
                    'name' => $action['user']['role']['name'],
                ],
            ];
            $response[] = $action;
        }
        return $this->respondWithData($response);
    }

}
