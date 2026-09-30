<?php

namespace WCC\OntsRegistration\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCC\OntsRegistration\Models\UnregisteredOntMacro;
use WCC\OntsRegistration\Storage\UnregisteredOntMacroStorage;
/**
 * @OA\Get(
 *   path="/component/onts_registration/control/{id}",
 *   tags={"onts-registration-control"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get registration macro by ID",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=1)),
 *   @OA\Response(response=200, description="Macro", @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true)))
 * )
 */

class GetMacrosById extends PrivateAction
{
        /**
     * @Inject
     * @var UnregisteredOntMacroStorage
     */
    protected $macrosStorage;

    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $macros = $this->macrosStorage->fill(new UnregisteredOntMacro($id));
        $data = [
            'id' => $macros->getId(),
            'name' => $macros->getName(),
            'created_at' => $macros->getCreatedAt(),
            'updated_at' => $macros->getUpdatedAt(),
            'enabled' => $macros->isEnabled(),
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
            'template' => $macros->getTemplate(),
            'parameters' => $macros->getParameters(),
        ];
        return $this->respondWithData($data);
    }


}
