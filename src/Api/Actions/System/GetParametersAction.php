<?php


namespace WCAA\Api\Actions\System;


use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\EnvParamsEditor;

/**
 * @OA\Get(
 *   path="/system/configuration",
 *   tags={"system"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get system configuration parameters",
 *   @OA\Response(
 *     response=200,
 *     description="Configuration params",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetParametersAction extends PrivateAction
{
    /**
     * @Inject
     * @var \WCAA\Infrastructure\EnvParamsEditor
     */
    protected $paramEditor;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        return $this->respondWithData($this->paramEditor->getParams());
    }
}
