<?php

namespace WCAA\Api\Actions\Devices\Interfaces\Marks;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Get(
 *   path="/interface-marks/marks/{interface_id}",
 *   tags={"interface-marks"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get marks for interface",
 *   @OA\Parameter(name="interface_id", in="path", required=true, @OA\Schema(type="integer", example=5001)),
 *   @OA\Response(
 *     response=200,
 *     description="Interface marks and tags",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/InterfaceMarks")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetInterfaceMarks extends AbstractTaggedInterfacesAction
{
    protected function action(): Response
    {
        $iface = $this->deviceInterfacesStorage->getById($this->request->getAttribute('interface_id'));
        return  $this->respondWithData([
            'interface' => $iface->getAsArray(),
            'favorite' => $this->tagsStorage->isInterfaceFavorite($iface),
            'tags' => $this->tagsStorage->getInterfaceTags($iface),
        ]);
    }

}
