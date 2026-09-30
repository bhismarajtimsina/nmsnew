<?php

namespace WCC\Macros\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Macros\Models\Macros;
use WCC\Macros\Storage\MacrosStorage;
/**
 * @OA\Get(
 *   path="/component/macros/macro/{id}",
 *   description="Get macros by ID for current user role",
 *   tags={"macros"},
 *   security={{"XAuthKey":{}}},
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer")
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", ref="#/components/schemas/MacrosPublic"),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */

class GetMacrosById extends PrivateAction
{
    /**
     * @Inject
     * @var MacrosStorage
     */
    protected $macrosStorage;

        protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $macros = $this->macrosStorage->fill(new Macros($id));

        if(!array_filter($macros->getAllowedRoles(), function ($role) {
            return $this->user->getRole()->getId() == $role->getId();
        })) {
            throw new HttpBadRequestException($this->request, "Forbidden macros for current user role");
        }

        $data = [
            'id' => $macros->getId(),
            'name' => $macros->getName(),
            'description' => $macros->getDescription(),
            'template' => $macros->getTemplate(),
            'display_output' => $macros->getDisplayOutput(),
            'parameters' => $macros->getParameters(),
        ];
        return $this->respondWithData($data);
    }
}
