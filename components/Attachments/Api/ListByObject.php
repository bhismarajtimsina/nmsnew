<?php

namespace WCC\Attachments\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\SystemActionsStorage;
use WCC\Attachments\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/attachments/list/{type}/{id}",
 *   tags={"attachments"},
 *   security={{"XAuthKey": {}}},
 *   summary="List attachments by object",
 *   @OA\Parameter(name="type", in="path", required=true, @OA\Schema(type="string", example="device")),
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Attachment list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/AttachmentListItem"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class ListByObject extends AbstractAttachmentsApi
{
    protected function action(): Response
    {
        $this->controller->setUser($this->user);

        $objectType = $this->request->getAttribute('type');
        $objectId = $this->request->getAttribute('id');
        return $this->respondWithData($this->controller->getAttachmentsListByObject($objectType, $objectId));
    }

}
