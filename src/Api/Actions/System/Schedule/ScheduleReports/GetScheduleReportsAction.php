<?php

namespace WCAA\Api\Actions\System\Schedule\ScheduleReports;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\System\ScheduleReportsStorage;
use WCAA\Storage\System\ScheduleStorage;

/**
 * @OA\Post(
 *   path="/logs/schedule/reports",
 *   tags={"system-schedule"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get schedule execution reports",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="start", type="string", nullable=true, example="2026-02-25 00:00:00"),
 *       @OA\Property(property="stop", type="string", nullable=true, example="2026-02-25 23:59:59"),
 *       @OA\Property(property="schedule_keys", type="array", nullable=true, @OA\Items(type="string")),
 *       @OA\Property(property="hide_finished", type="boolean", nullable=true),
 *       @OA\Property(property="only_failed", type="boolean", nullable=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Schedule reports",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ScheduleReport"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetScheduleReportsAction extends PrivateAction
{
    /**
     * @Inject
     * @var ScheduleReportsStorage
     */
    protected $scheduleReportsStorage;

    /**
     * @Inject
     * @var ScheduleStorage
     */
    protected $scheduleStorage;

    protected function action(): Response
    {
        $filter = $this->getFormData();
        if(!isset($filter['start']) || !$filter['start'])  {
            $filter['start'] = date("Y-m-d H:i:s", time() - 86000);
        }
        if(!isset($filter['stop']) || !$filter['stop'])  {
            $filter['stop'] = date("Y-m-d H:i:s" );
        }
        if(!isset($filter['schedule_keys']) || !$filter['schedule_keys']) {
            $schedule_keys = null;
        } else {
            $schedule_keys = $filter['schedule_keys'];
        }
        if(!isset($filter['hide_finished'])) {
            $filter['hide_finished'] = false;
        }
        if(!isset($filter['only_failed'])) {
            $filter['only_failed'] = false;
        }
        $data = $this->scheduleReportsStorage->getReportsByFilter($filter['start'], $filter['stop'], $schedule_keys, $filter['hide_finished'], $filter['only_failed']);
        return  $this->respondWithData(array_map(function ($e) {return $e->getAsArray();}, $data));
    }
}
