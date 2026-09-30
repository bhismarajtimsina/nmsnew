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
 *   path="/component/attachments/meta/{uuid}",
 *   tags={"attachments"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get attachment metadata by UUID",
 *   @OA\Parameter(name="uuid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
 *   @OA\Response(
 *     response=200,
 *     description="Attachment metadata",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/Attachment")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class GetAttachmentMeta extends AbstractAttachmentsApi
{
    protected function action(): Response
    {
        return $this->respondWithData($this->controller->getObjectMetaByUUID($this->request->getAttribute('uuid')));
    }

}
