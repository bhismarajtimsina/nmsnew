<?php


namespace WCAA\Api\Actions\Devices;


use DI\Annotation\Inject;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceStorage;
use Psr\Http\Message\ResponseInterface as Response;

class DeleteDeviceAction extends PrivateAction
{
    /**
     * @OA\Delete(
     *   path="/device/{id}",
     *   tags={"device"},
     *   security={{"XAuthKey": {}}},
     *   summary="Delete device by ID",
     *   description="Deletes a device by its ID. Requires permission to device group.",
     *
     *   @OA\Parameter(
     *     name="id",
     *     in="path",
     *     required=true,
     *     description="Device ID",
     *     @OA\Schema(
     *       type="integer",
     *       example=8
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=200,
     *     description="Device successfully deleted",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="statusCode", type="integer", example=200),
     *       @OA\Property(
     *         property="data",
     *         type="boolean",
     *         example=true
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=400,
     *     description="Bad Request / No permissions",
     *     @OA\JsonContent(
     *       type="object",
     *       @OA\Property(property="statusCode", type="integer", example=400),
     *       @OA\Property(property="error", type="string", example="Bad Request"),
     *       @OA\Property(
     *         property="message",
     *         type="string",
     *         example="You dont have permission for delete choosed device! Check role permissions"
     *       )
     *     )
     *   ),
     *
     *   @OA\Response(
     *     response=401,
     *     description="Unauthorized"
     *   )
     * )
     */

    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $storage;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $device = $this->storage->fill(new Device($id));
        if(!array_filter($this->user->getDeviceGroups(), function ($e) use ($device){
                return $device->getGroup()->getId() === $e->getId();
            }) && ($this->user->getId() > 0  && $this->user->getRole()->getId() > 0)) {
            throw new HttpBadRequestException($this->request, "You dont have permission for delete choosed device! Check role permissions");
        }
        $this->storage->delete($device);

        $this->addActionSuccess('device:deleted', "Device {$device->getIp()} ({$device->getName()}) success deleted", [
            'device' => $device->getAsArray(),
        ]);
        return  $this->respondWithData(true);
    }

}
