<?php


namespace WCAA\Api\Actions\System\Pollers;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\Poller\PollerProcessor;
use WCAA\Models\Pollers\PollerProcessing;
use WCAA\Storage\PollerData\PollerProcessingStorage;
use WCAA\Storage\SystemActionsStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Get(
 *   path="/logs/poller/existed-pollers",
 *   tags={"system-pollers"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get supported poller names",
 *   @OA\Response(
 *     response=200,
 *     description="Supported pollers",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="string"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetSupportedPollers extends PrivateAction
{
    /**
     * @Inject
     * @var PollerProcessor
     */
    protected $pollerProcessor;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $processors = array_keys($this->pollerProcessor->getConfiguration());
        return  $this->respondWithData($processors);
    }

}
