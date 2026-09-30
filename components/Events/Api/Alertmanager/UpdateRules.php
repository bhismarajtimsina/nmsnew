<?php

namespace WCC\Events\Api\Alertmanager;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Events\Models\AlertmanagerRule;
/**
 * @OA\Put(
 *   path="/component/events/alertmanager",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update Alertmanager rules",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(type="array", @OA\Items(type="object", additionalProperties=true))
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated rules",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true))
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request")
 * )
 */

class UpdateRules extends AlertmanagerAbstract
{
        protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $rulesArr = $this->getFormData();
        $rules = [];
        foreach ($rulesArr as $rule) {
            $rules[] = (new AlertmanagerRule())
                ->setEnabled($rule['enabled'])
                ->setAlertName($rule['alert_name'])
                ->setGroupName($rule['group_name'])
                ->setAnnotationDescription($rule['annotation_description'])
                ->setAnnotationSummary($rule['annotation_summary'])
                ->setExpression($rule['expression'])
                ->setFor($rule['for'])
                ->setSeverity($rule['severity']);
        }
        $rulesArr = [];
        try {
            foreach ($this->alertmanager->updateRules($rules) as $rule) {
                $rulesArr[] = $rule->getAsArray();
            }
        } catch (\InvalidArgumentException $e) {
            throw new HttpBadRequestException($this->request, $e->getMessage(), $e);
        }
        return $this->respondWithData($rulesArr);
    }

}
