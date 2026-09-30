<?php

namespace WCAA\Api\Actions\System;

use OpenApi\Annotations as OA;
use Prometheus\RenderTextFormat;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Infrastructure\PrometheusMetrics;

/**
 * @OA\Get(
 *   path="/metrics/{prefix}",
 *   tags={"system"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get Prometheus metrics with prefix",
 *   @OA\Parameter(name="prefix", in="path", required=true, @OA\Schema(type="string", example="support_")),
 *   @OA\Response(
 *     response=200,
 *     description="Prometheus text exposition format",
 *     @OA\MediaType(
 *       mediaType="text/plain",
 *       @OA\Schema(type="string")
 *     )
 *   )
 * )
 * @OA\Get(
 *   path="/metrics",
 *   tags={"system"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get Prometheus metrics",
 *   @OA\Response(
 *     response=200,
 *     description="Prometheus text exposition format",
 *     @OA\MediaType(
 *       mediaType="text/plain",
 *       @OA\Schema(type="string")
 *     )
 *   )
 * )
 */
class PrometheusMetricsAction extends PrivateAction
{
    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $promMetrics;

    protected function action(): Response
    {
        $this->promMetrics->incCounter('exporter_requested', [], 'Count of requested exporter');
        $prefix = '';
        if($prf = $this->request->getAttribute('prefix')) {
            $prefix = $prf ;
        }
        $result = $this->promMetrics->render($prefix);
        $this->response->getBody()->write($result);
        return $this->response->withHeader('Content-Type', RenderTextFormat::MIME_TYPE);
    }

}
