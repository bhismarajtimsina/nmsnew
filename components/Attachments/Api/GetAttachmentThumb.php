<?php

namespace WCC\Attachments\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\SystemActionsStorage;
use WCC\Attachments\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/attachments/thumb/{uuid}",
 *   tags={"attachments"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get attachment thumbnail by UUID",
 *   @OA\Parameter(name="uuid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
 *   @OA\Response(
 *     response=200,
 *     description="Thumbnail stream",
 *     @OA\MediaType(
 *       mediaType="application/octet-stream",
 *       @OA\Schema(type="string", format="binary")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class GetAttachmentThumb extends AbstractAttachmentsApi
{
    protected function action(): Response
    {
        $uuid = $this->request->getAttribute('uuid');
        $path = $this->controller->getThumbPathByUuid($uuid);
        if (!$path || !is_file($path)) {
            throw new HttpNotFoundException($this->request, sprintf('Thumb not found for %s', $uuid));
        }

        // Имя файла для клиента (безопасное)
        $name = basename($path);
        return $this->serveFile($this->request, $this->response, $path, $name);
    }

}
