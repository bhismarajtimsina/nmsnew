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
 * @OA\Delete(
 *   path="/component/attachments/object/{uuid}",
 *   tags={"attachments"},
 *   security={{"XAuthKey": {}}},
 *   summary="Delete attachment by UUID",
 *   @OA\Parameter(name="uuid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
 *   @OA\Response(
 *     response=200,
 *     description="Deletion status",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class DeleteAttachment extends AbstractAttachmentsApi
{
    protected function action(): Response
    {
        $this->controller->deleteAttachment($this->request->getAttribute("uuid"));
        return $this->respondWithData(true);
    }

}
