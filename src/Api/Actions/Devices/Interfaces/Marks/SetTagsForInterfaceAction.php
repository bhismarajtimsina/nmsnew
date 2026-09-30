<?php

namespace WCAA\Api\Actions\Devices\Interfaces\Marks;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Put(
 *   path="/interface-marks/tags/{interface_id}",
 *   tags={"interface-marks"},
 *   security={{"XAuthKey": {}}},
 *   summary="Set tags for interface",
 *   @OA\Parameter(name="interface_id", in="path", required=true, @OA\Schema(type="integer", example=5001)),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="array",
 *       @OA\Items(type="string", example="uplink")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated tags",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="string"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class SetTagsForInterfaceAction extends AbstractTaggedInterfacesAction
{
    protected function action(): Response
    {
        $tags = $this->getFormData();

        $iface = $this->deviceInterfacesStorage->getById($this->request->getAttribute('interface_id'));
        $resp = $this->tagsStorage->setTags($iface, $tags);
        return  $this->respondWithData($resp);
    }

}
