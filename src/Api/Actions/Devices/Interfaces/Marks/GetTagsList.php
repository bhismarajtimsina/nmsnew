<?php

namespace WCAA\Api\Actions\Devices\Interfaces\Marks;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Get(
 *   path="/interface-marks/existed-tags",
 *   tags={"interface-marks"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get existed tags",
 *   @OA\Parameter(
 *     name="query",
 *     in="query",
 *     required=false,
 *     description="Tag search prefix",
 *     @OA\Schema(type="string", example="uplink")
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Tag list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="string"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetTagsList extends AbstractTaggedInterfacesAction
{
    protected function action(): Response
    {
        $tags = str_replace("#", "", isset($this->request->getQueryParams()['query']) ? $this->request->getQueryParams()['query'] : '');
        $resp = $this->tagsStorage->getExistedTags($tags);
        return  $this->respondWithData($resp);
    }

}
