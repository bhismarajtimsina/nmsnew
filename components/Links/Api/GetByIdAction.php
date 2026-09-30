<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
/**
 * @OA\Get(
 *   path="/component/links/{id}",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get link by ID",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=12)),
 *   @OA\Parameter(name="with_stat", in="query", required=false, @OA\Schema(type="string", enum={"yes"})),
 *   @OA\Response(
 *     response=200,
 *     description="Link details",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class GetByIdAction extends BaseApiAction
{
    protected function action(): Response
    {
        $linkId = $this->request->getAttribute('id');
        $link = $this->controller->getLinkStorage()->getById($linkId);

        if(isset($this->request->getQueryParams()['with_stat']) && $this->request->getQueryParams()['with_stat'] == 'yes') {
            $data = $this->controller->getAllDataByLink($link);
        } else {
            $data = $link->getAsArrayLite();
        }
        return $this->respondWithData($data);
    }
}
