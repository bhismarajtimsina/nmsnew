<?php

namespace WCC\OntsRegistration\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\DeviceModel;
use WCC\OntsRegistration\Models\UnregisteredOntMacro;
use WCC\OntsRegistration\Storage\UnregisteredOntMacroStorage;
/**
 * @OA\Put(
 *   path="/component/onts_registration/control/{id}",
 *   tags={"onts-registration-control"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update registration macro",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=1)),
 *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", additionalProperties=true)),
 *   @OA\Response(response=200, description="Updated macro", @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true)))
 * )
 */

class UpdateMacros extends PrivateAction
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
            $updatingData = $this->getFormData();
            if (isset($updatingData['name']) && $updatingData['name']) {
                $macros->setName($updatingData['name']);
            }
            if (isset($updatingData['models']) && $updatingData['models']) {
                $macros->setModels(array_map(function ($model) {
                    if (!isset($model['id'])) {
                        throw new \InvalidArgumentException("Parameter models must contain array of device models", 400);
                    }
                    return new DeviceModel($model['id']);
                }, $updatingData['models']));
            }
            if (isset($updatingData['template']) && $updatingData['template']) {
                $macros->setTemplate($updatingData['template']);
            }
            if (isset($updatingData['parameters']) && is_array($updatingData['parameters'])) {
                $macros->setParameters($updatingData['parameters']);
            }
            if (isset($updatingData['enabled'])) {
                $macros->setEnabled($updatingData['enabled']);
            }

            $macros = $this->macrosStorage->update($macros);

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
            $this->addActionSuccess("unregistered_onts:update", "User {$this->user->getName()} updated macros with name {$macros->getName()}", $macros->getAsArray());
            return $this->respondWithData($data);
        } catch (\Throwable $e) {
            $this->addActionFailed("unregistered_onts:update", "User {$this->user->getName()} try update macros", $e, [
                'form' => $this->getFormData()
            ]);
            throw $e;
        }
    }


}
