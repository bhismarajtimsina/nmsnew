<?php

namespace WCC\Events\Api\Alertmanager;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Events\Models\AlertmanagerRule;
/**
 * @OA\Put(
 *   path="/component/events/alertmanager/validate-rule",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Validate Alertmanager rule object",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(type="object", additionalProperties=true)
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

class ValidateRule extends AlertmanagerAbstract
{
        protected function action(): Response
    {
        $rule = $this->getFormData();
        $ruleObj = (new AlertmanagerRule())
            ->setEnabled($rule['enabled'])
            ->setAlertName($rule['alert_name'])
            ->setGroupName($rule['group_name'])
            ->setAnnotationDescription($rule['annotation_description'])
            ->setAnnotationSummary($rule['annotation_summary'])
            ->setExpression($rule['expression'])
            ->setFor($rule['for'])
            ->setSeverity($rule['severity']);
        try {
            $this->alertmanager->validateRule($ruleObj);
            $this->alertmanager->validateExpr($ruleObj->getExpression());
            return $this->respondWithData(true);
        } catch (\InvalidArgumentException $e) {
            throw new HttpBadRequestException($this->request, $e->getMessage(), $e);
        }
    }

}
