<?php

namespace WCAA\Api\Actions\System\Schedule;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\System\ScheduleStorage;

/**
 * @OA\Get(
 *   path="/system/schedule",
 *   tags={"system-schedule"},
 *   security={{"XAuthKey": {}}},
 *   summary="List schedules",
 *   @OA\Response(
 *     response=200,
 *     description="Schedules list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Schedule"))
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
            return $e->getAsArray();
        }, $this->scheduleStorage->fetchAll(false));
        return $this->respondWithData($response);
    }

}
