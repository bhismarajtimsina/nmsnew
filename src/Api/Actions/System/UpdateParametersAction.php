<?php


namespace WCAA\Api\Actions\System;


use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\EnvParamsEditor;

/**
 * @OA\Put(
 *   path="/system/configuration",
 *   tags={"system"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update system configuration parameters",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(type="object", additionalProperties=true)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated params",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class UpdateParametersAction extends PrivateAction
{
    protected $forbiddenInDemo = true;

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
        $params = $this->getFormData() && is_array($this->getFormData()) ? $this->getFormData() : [];
        $this->paramEditor->writeParams($params);
        return $this->respondWithData($params);
    }
}
