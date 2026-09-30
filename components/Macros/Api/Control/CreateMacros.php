<?php

namespace WCC\Macros\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\UserRoleStorage;
use WCC\Macros\Models\Macros;
use WCC\Macros\Storage\MacrosStorage;
/**
 * @OA\Post(
 *   path="/component/macros/control",
 *   description="Create macros (control)",
 *   tags={"macros-control"},
 *   security={{"XAuthKey":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(ref="#/components/schemas/MacrosUpsert")
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

class CreateMacros extends PrivateAction
{
    protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var MacrosStorage
     */
    protected $macrosStorage;

    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $deviceModelStorage;

    /**
     * @Inject
     * @var UserRoleStorage
     */
    protected $userRoleStorage;

        protected function action(): Response
    {
        try {
            $macros = new Macros();
            $updatingData = $this->getFormData();
            if (isset($updatingData['name']) && $updatingData['name']) {
                $macros->setName($updatingData['name']);
            }
            if (isset($updatingData['description']) && $updatingData['description']) {
                $macros->setDescription($updatingData['description']);
            }
            if (isset($updatingData['display_for']) && $updatingData['display_for']) {
                $macros->setDisplayFor($updatingData['display_for']);
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
            if (isset($updatingData['user_roles']) && $updatingData['user_roles']) {
                // Real lookup, not a bare `new UserRole($id)` stub — see the
                // matching comment in UpdateMacros.php.
                $macros->setAllowedRoles(array_map(function ($role) {
                    if (!isset($role['id'])) {
                        throw new \InvalidArgumentException("Parameter user_roles must contain array of user roles", 400);
                    }
                    return $this->userRoleStorage->getById($role['id']);
                }, $updatingData['user_roles']));
            }
            if (isset($updatingData['template']) && $updatingData['template']) {
                $macros->setTemplate($updatingData['template']);
            }
            if (isset($updatingData['parameters']) && $updatingData['parameters']) {
                $macros->setParameters($updatingData['parameters']);
            }
            if (isset($updatingData['display_output']) && $updatingData['display_output']) {
                $macros->setDisplayOutput($updatingData['display_output']);
            }

            $macros = $this->macrosStorage->add($macros);
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
            $this->addActionSuccess("macros:added", "User {$this->user->getName()} added new macros with name {$macros->getName()}", $macros->getAsArray());
            return $this->respondWithData($data);
        } catch (\Throwable $e) {
            $this->addActionFailed("macros:added", "User {$this->user->getName()} added new macros with name {$macros->getName()}", $e, [
                'form' => $this->getFormData(),
            ]);
            throw $e;
        }
    }


}
