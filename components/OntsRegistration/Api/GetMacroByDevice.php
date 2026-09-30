<?php

namespace WCC\OntsRegistration\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\Exceptions\RecordNotFoundException;
use WCC\OntsRegistration\Models\UnregisteredOntMacro;
use WCC\OntsRegistration\Storage\UnregisteredOntMacroStorage;
/**
 * @OA\Get(
 *   path="/component/onts_registration/by-device/{device_id}",
 *   tags={"onts-registration"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get registration macro by device",
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(response=200, description="Macro data", @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true))),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class GetMacroByDevice extends PrivateAction
{
        /**
     * @Inject
     * @var UnregisteredOntMacroStorage
     */
    protected $macrosStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    protected function action(): Response
    {
        $id = $this->request->getAttribute('device_id');
        try {
            $device = $this->deviceStorage->getById($id);
            $macros = $this->macrosStorage->getByDeviceModel($device->getModel());
        } catch (RecordNotFoundException $e) {
            throw new HttpNotFoundException($this->request, $e);
        }
        $data = [
            'created_at' => $macros->getCreatedAt(),
            'id' => $macros->getId(),
            'name' => $macros->getName(),
            'models' => array_map(function ($m) {
                return $m->getAsArrayLite();
            }, $macros->getModels()),
            'template' => $macros->getTemplate(),
            'parameters' => $macros->getParameters(),
        ];
        return $this->respondWithData($data);
    }
}
