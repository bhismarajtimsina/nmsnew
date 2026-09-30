<?php

namespace WCC\OntsRegistration\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCC\OntsRegistration\Models\UnregisteredOntMacro;
use WCC\OntsRegistration\Storage\UnregisteredOntMacroStorage;
/**
 * @OA\Post(
 *   path="/component/onts_registration/control",
 *   tags={"onts-registration-control"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create registration macro",
 *   @OA\RequestBody(required=true, @OA\JsonContent(type="object", additionalProperties=true)),
 *   @OA\Response(response=200, description="Created macro", @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true)))
 * )
 */

class CreateMacros extends PrivateAction
{
        protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var UnregisteredOntMacroStorage
     */
    protected $macrosStorage;

    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $deviceModelStorage;

    protected function action(): Response
    {
        try {
            $macros = new UnregisteredOntMacro();
            $updatingData = $this->getFormData();
            if (isset($updatingData['name']) && $updatingData['name']) {
                $macros->setName($updatingData['name']);
            }
            if (isset($updatingData['models']) && $updatingData['models']) {
                $models = [];
                foreach ($updatingData['models'] as $model) {
                    try {
                        if (isset($model['key'])) {
                            $models[] = $this->deviceModelStorage->getByKey($model['key']);
                        } else if (isset($model['id'])) {
                            $models[] = $this->deviceModelStorage->getById($model['id']);
                        } else {
                            throw new \InvalidArgumentException("Parameter models must contain array of device models", 400);
                        }
                    } catch (\Exception $e) {}
                }
                $macros->setModels($models);
            }
            if (isset($updatingData['template']) && $updatingData['template']) {
                $macros->setTemplate($updatingData['template']);
            }
            if (isset($updatingData['parameters']) && $updatingData['parameters']) {
                $macros->setParameters($updatingData['parameters']);
            }

            $macros = $this->macrosStorage->add($macros);
            $data = [
                'id' => $macros->getId(),
                'name' => $macros->getName(),
                'enabled' => $macros->isEnabled(),
                'created_at' => $macros->getCreatedAt(),
                'updated_at' => $macros->getUpdatedAt(),
                'models' => array_map(function ($m) {
                    return [
                        'id' => $m->getId(),
                        'key' => $m->getKey(),
                        'name' => $m->getName(),
                        'vendor' => $m->getVendor(),
                        'model' => $m->getModel(),
                        'type' => $m->getType(),
                        'params' => $m->getParams(),
                    ];
                }, $macros->getModels()),
                'template' => $macros->getTemplate(),
                'parameters' => $macros->getParameters(),
            ];
            $this->addActionSuccess("unregistered_onts:added", "User {$this->user->getName()} added new macros with name {$macros->getName()}", $macros->getAsArray());
            return $this->respondWithData($data);
        } catch (\Throwable $e) {
            $this->addActionFailed("unregistered_onts:added", "User {$this->user->getName()} added new macros with name {$macros->getName()}", $e, [
                'form' => $this->getFormData(),
            ]);
            throw $e;
        }
    }


}
