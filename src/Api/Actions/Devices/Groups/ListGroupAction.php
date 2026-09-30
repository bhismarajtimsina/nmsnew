<?php


namespace WCAA\Api\Actions\Devices\Groups;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Storage\Devices\DeviceGroupStorage;

/**
 * @OA\Get(
 *   path="/device-group",
 *   tags={"device-group"},
 *   security={{"XAuthKey": {}}},
 *   summary="List device groups",
 *   @OA\Response(
 *     response=200,
 *     description="Device groups list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DeviceGroup"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 * @OA\Get(
 *   path="/device-group/{id}",
 *   tags={"device-group"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get device group by id",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer", example=4)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Device group",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceGroup")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class ListGroupAction extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $storage;

    /**
     * @return Response
     */
    protected function action(): Response
    {

        if ($id = $this->request->getAttribute('id')) {
            return $this->respondWithData($this->storage->getById($id)->getAsArray());
        }
        $groups = [];
        foreach ($this->storage->fetchAll() as $group) {
             $groups[] = $group->getAsArray();
        }
        return  $this->respondWithData($groups);
    }
}
