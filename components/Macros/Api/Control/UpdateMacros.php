<?php

namespace WCC\Macros\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\UserRoleStorage;
use WCC\Macros\Models\Macros;
use WCC\Macros\Storage\MacrosStorage;
/**
 * @OA\Put(
 *   path="/component/macros/control/{id}",
 *   description="Update macros (control)",
 *   tags={"macros-control"},
 *   security={{"XAuthKey":{}}},
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer")
 *   ),
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

class UpdateMacros extends PrivateAction
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
            $id = $this->request->getAttribute('id');
            $macros = $this->macrosStorage->fill(new Macros($id));
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
                // Real lookups, not bare `new DeviceModel($id)` stubs — this
                // object is what gets pushed to WebSocket clients on update
                // (see MacrosStorage::update()); a bare stub only has its id
                // set, so getAsArray() on it would serialize as {id, name:
                // null, ...}, silently blanking every macro's model list on
                // any live-connected MacrosListPage. Matches the same
                // pattern EditDeviceAction/Links UpdateAction already use.
                $macros->setModels(array_map(function ($model) {
                    if (!isset($model['id'])) {
                        throw new \InvalidArgumentException("Parameter models must contain array of device models", 400);
                    }
                    return $this->deviceModelStorage->getById($model['id']);
                }, $updatingData['models']));
            }
            if (isset($updatingData['user_roles']) && $updatingData['user_roles']) {
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
            if (isset($updatingData['parameters']) && is_array($updatingData['parameters'])) {
                $macros->setParameters($updatingData['parameters']);
            }
            if (isset($updatingData['display_output']) && $updatingData['display_output']) {
                $macros->setDisplayOutput($updatingData['display_output']);
            }

            $macros = $this->macrosStorage->update($macros);

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
            $this->addActionSuccess("macros:update", "User {$this->user->getName()} updated macros with name {$macros->getName()}", $macros->getAsArray());
            return $this->respondWithData($data);
        } catch (\Throwable $e) {
            $this->addActionFailed("macros:update", "User {$this->user->getName()} try update macros", $e, [
                'form' => $this->getFormData()
            ]);
            throw $e;
        }
    }


}
