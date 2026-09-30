<?php

namespace WCAA\Api\Actions\System\Schedule\ScheduleReports;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\System\ScheduleStorage;

/**
 * @OA\Get(
 *   path="/logs/schedule/keys",
 *   tags={"system-schedule"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get schedule keys list",
 *   @OA\Response(
 *     response=200,
 *     description="Schedule keys",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="array",
 *         @OA\Items(
 *           type="object",
 *           @OA\Property(property="id", type="integer"),
 *           @OA\Property(property="key", type="string"),
 *           @OA\Property(property="latest", type="string", nullable=true),
 *           @OA\Property(property="state", type="string")
 *         )
 *       )
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetScheduleListAction extends PrivateAction
{
    /**
     * @Inject
     * @var ScheduleStorage
     */
    protected $scheduleStorage;

    protected function action(): Response
    {
        $response = array_map(function ($e) {
            return [
                'id' => $e->getId(),
                'key' => $e->getKey(),
                'latest' => $e->getLatest(),
                'state' => $e->getState(),
            ];
        }, $this->scheduleStorage->fetchAll(false));
        return $this->respondWithData($response);
    }
}
