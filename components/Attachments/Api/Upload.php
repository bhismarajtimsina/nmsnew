<?php

namespace WCC\Attachments\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\SystemActionsStorage;
use WCC\Attachments\Controllers\Controller;
use WCC\Attachments\Models\Attachment;
/**
 * @OA\Post(
 *   path="/component/attachments/upload/{type}/{id}",
 *   tags={"attachments"},
 *   security={{"XAuthKey": {}}},
 *   summary="Upload attachment for object",
 *   @OA\Parameter(name="type", in="path", required=true, @OA\Schema(type="string", example="device")),
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\MediaType(
 *       mediaType="multipart/form-data",
 *       @OA\Schema(
 *         type="object",
 *         required={"file"},
 *         @OA\Property(property="file", type="string", format="binary")
 *       )
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Uploaded attachment metadata",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/Attachment")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class Upload extends AbstractAttachmentsApi
{
    protected function action(): Response
    {
        $this->controller->setUser($this->user);
        $objectType = $this->request->getAttribute('type');
        $id = $this->request->getAttribute('id');
        $files = $this->request->getUploadedFiles();
        $file = isset($files['file']) ? $files['file'] : null;
        if (!$file) {
            throw new HttpBadRequestException($this->request, "Incorrect files");
        }
        $attachment = $this->controller->uploadObject($objectType, $id, $file);
        return $this->respondWithData($attachment->getAsArrayLite());
    }

}
