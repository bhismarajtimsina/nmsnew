<?php

namespace WCAA\Api\Actions\Devices\Interfaces\Marks;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Put(
 *   path="/interface-marks/favorite/{interface_id}",
 *   tags={"interface-marks"},
 *   security={{"XAuthKey": {}}},
 *   summary="Set interface favorite status",
 *   @OA\Parameter(name="interface_id", in="path", required=true, @OA\Schema(type="integer", example=5001)),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="favorite", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Favorite status",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class SetStarredStatusAction extends AbstractTaggedInterfacesAction
{
    protected function action(): Response
    {
       $form = $this->getFormData();
       $this->fillDefaultKeys([
           'favorite' => false,
       ], $form);

       $iface = $this->deviceInterfacesStorage->getById($this->request->getAttribute('interface_id'));
       $starred = $this->tagsStorage->setFavoriteStatusByInterface($iface, $form['favorite']);
       return  $this->respondWithData($starred);
    }

}
