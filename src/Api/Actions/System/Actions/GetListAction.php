<?php


namespace WCAA\Api\Actions\System\Actions;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\SystemActionsStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Post(
 *   path="/logs/actions",
 *   tags={"system-logs"},
 *   security={{"XAuthKey": {}}},
 *   summary="Filter system action logs",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"start","stop"},
 *       @OA\Property(property="start", type="string", example="2026-02-25 00:00:00"),
 *       @OA\Property(property="stop", type="string", example="2026-02-25 23:59:59"),
 *       @OA\Property(property="actions", type="array", @OA\Items(type="string")),
 *       @OA\Property(property="users", type="array", @OA\Items(type="integer")),
 *       @OA\Property(property="status", type="string", nullable=true),
 *       @OA\Property(property="query", type="string", nullable=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Actions list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/SystemAction"))
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetListAction extends PrivateAction
{
    /**
     * @Inject
     * @var SystemActionsStorage
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
        if (!isset($data['actions'])) {
            $data['actions'] = [];
        }
        if (!isset($data['users'])) {
            $data['users'] = [];
        }
        if (!isset($data['status'])) {
            $data['status'] = '';
        }
        $resp = $this->actionStorage->getActionsByFilter($data['start'], $data['stop'], $data['actions'], $data['users'], $data['status']);
        $response = [];
        foreach ($resp as $d) {
            if (
                isset($data['query']) &&
                trim($data['query']) !== '' &&
                strpos(json_encode($d->getAsArray(), JSON_UNESCAPED_UNICODE), $data['query']) !== false
            ) {
                $response[] = $d->getAsArray();
            } elseif (
                !isset($data['query']) ||
                trim($data['query']) === ''
            ) {
                $response[] = $d->getAsArray();
            }
        }
        return $this->respondWithData($response);
    }

}
