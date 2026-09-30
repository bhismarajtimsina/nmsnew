<?php

namespace WCC\Events\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Events\Controllers\Controller;
use WCC\Events\Models\EventFilter;
use WCC\Events\Storage\EventsStorage;
/**
 * @OA\Get(
 *   path="/component/events/severity-stat",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get events severity statistics",
 *   @OA\Response(
 *     response=200,
 *     description="Severity stats",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   )
 * )
 */

class GetSeverityStat extends PrivateAction
{
    
    /**
     * @Inject
     * @var EventsStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    protected function action(): Response
    {
       return  $this->respondWithData($this->storage->getSeverityStat(true, $this->user));
    }


}
