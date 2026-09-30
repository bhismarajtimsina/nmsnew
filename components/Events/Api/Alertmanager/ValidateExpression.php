<?php

namespace WCC\Events\Api\Alertmanager;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Events\Models\AlertmanagerRule;
/**
 * @OA\Put(
 *   path="/component/events/alertmanager/validate-expression",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Validate Alertmanager expression",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="expression", type="string", example="rate(if_in_errors_total[5m]) > 0")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Validation result",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request")
 * )
 */

class ValidateExpression extends AlertmanagerAbstract
{
        protected function action(): Response
    {
        $rule = $this->getFormData();
        try {
            return $this->respondWithData($this->alertmanager->validateExpr($rule['expression']));
        } catch (\InvalidArgumentException $e) {
            throw new HttpBadRequestException($this->request, $e->getMessage(), $e);
        }
    }

}
