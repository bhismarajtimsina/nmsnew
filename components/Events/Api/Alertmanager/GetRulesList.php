<?php

namespace WCC\Events\Api\Alertmanager;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
/**
 * @OA\Get(
 *   path="/component/events/alertmanager",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get Alertmanager rules",
 *   @OA\Response(
 *     response=200,
 *     description="Rules list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true))
 *     )
 *   )
 * )
 */

class GetRulesList extends AlertmanagerAbstract
{
        protected function action(): Response
    {
        $rules = [];
        foreach ($this->alertmanager->listRules() as $rule) {
            $rules[] = $rule->getAsArray();
        }
        return $this->respondWithData($rules);
    }

}
