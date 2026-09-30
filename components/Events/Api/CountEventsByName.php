<?php

namespace WCC\Events\Api;

use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Interfaces\CacheInterface;
use WCC\Events\Storage\EventsStorage;
/**
 * @OA\Get(
 *   path="/component/events/count-by-name",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get events count grouped by name",
 *   @OA\Response(
 *     response=200,
 *     description="Event counts",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   )
 * )
 */

class CountEventsByName extends PrivateAction
{
        /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventsStorage;

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    protected function action(): Response
    {
        return $this->respondWithData($this->eventsStorage->getStatByEventName(true, $this->user));
    }

}
