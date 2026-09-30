<?php

namespace WCC\Macros\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Macros\Models\Macros;
use WCC\Macros\Storage\MacrosStorage;
/**
 * @OA\Get(
 *   path="/component/macros/control/{id}",
 *   description="Get macros by ID (control)",
 *   tags={"macros-control"},
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
 *       @OA\Property(property="data", ref="#/components/schemas/Macros"),
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
        $data = [
            'id' => $macros->getId(),
            'name' => $macros->getName(),
            'description' => $macros->getDescription(),
            'display_for' => $macros->getDisplayFor(),
            'display_output' => $macros->getDisplayOutput(),
            'models' => array_map(function ($m) {
                return [
                    'id' => $m->getId(),
                    'key' => $m->getKey(),
                    'name' => $m->getName(),
                    'type' => $m->getType(),
                    'vendor' => $m->getVendor(),
                    'params' => $m->getParams(),
                ];
            }, $macros->getModels()),
            'user_roles' => array_map(function ($m) {
                return [
                    'id' => $m->getId(),
                    'name' => $m->getName(),
                ];
            }, $macros->getAllowedRoles()),
            'template' => $macros->getTemplate(),
            'parameters' => $macros->getParameters(),
        ];
        return $this->respondWithData($data);
    }


}
