<?php

namespace WCC\OntsRegistration\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCC\OntsRegistration\Models\UnregisteredOntMacro;
use WCC\OntsRegistration\Storage\UnregisteredOntMacroStorage;
/**
 * @OA\Put(
 *   path="/component/onts_registration/control/clone/{id}",
 *   tags={"onts-registration-control"},
 *   security={{"XAuthKey": {}}},
 *   summary="Clone registration macro",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=1)),
 *   @OA\Response(response=200, description="Cloned macro", @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true)))
 * )
 */

class CloneMacros extends PrivateAction
{
        protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var UnregisteredOntMacroStorage
     */
    protected $macrosStorage;

    protected function action(): Response
    {
        try {
            $id = $this->request->getAttribute('id');
            $macros = $this->macrosStorage->fill(new UnregisteredOntMacro($id));

            $macros->setName($macros->getName() . " (clone)");
            $macros->setEnabled(false);
            $macros = $this->macrosStorage->add($macros);

            $data = [
                'id' => $macros->getId(),
                'name' => $macros->getName(),
                'models' => array_map(function ($m) {
                    return [
                        'id' => $m->getId(),
                        'key' => $m->getKey(),
                        'name' => $m->getName(),
                        'type' => $m->getType(),
                        'params' => $m->getParams(),
                    ];
                }, $macros->getModels()),
                'template' => $macros->getTemplate(),
                'parameters' => $macros->getParameters(),
            ];
            $this->addActionSuccess("unregistered_onts:created", "User {$this->user->getName()} cloned macros with name {$macros->getName()}", $macros->getAsArray());
            return $this->respondWithData($data);
        } catch (\Throwable $e) {
            $this->addActionFailed("unregistered_onts:created", "User {$this->user->getName()} try clone macros", $e, [
                'form' => $this->getFormData()
            ]);
            throw $e;
        }
    }


}
