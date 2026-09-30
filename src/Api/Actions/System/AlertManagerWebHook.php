<?php

namespace WCAA\Api\Actions\System;

use OpenApi\Annotations as OA;
use Prometheus\RenderTextFormat;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Infrastructure\PrometheusMetrics;

/**
 * @OA\Post(
 *   path="/system/webhook/alertmanager",
 *   tags={"system"},
 *   security={{"XAuthKey": {}}},
 *   summary="Alertmanager webhook handler",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(type="object", additionalProperties=true)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Webhook accepted",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class AlertManagerWebHook extends PrivateAction
{

    /**
     * @Inject
     * @var EventObserverStorage
     */
    protected $events;

    protected function action(): Response
    {
        $this->events->notify("webhook:alertmanager", $this->getFormData());
        return $this->respondWithData(true);
    }

}
