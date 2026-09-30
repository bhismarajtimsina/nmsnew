<?php


namespace WCAA\Api\Actions\Devices\Accesses;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * @OA\Get(
 *   path="/device-access",
 *   tags={"device-access"},
 *   security={{"XAuthKey": {}}},
 *   summary="List device access profiles",
 *   @OA\Response(
 *     response=200,
 *     description="Access profiles list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DeviceAccess"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 * @OA\Get(
 *   path="/device-access/{id}",
 *   tags={"device-access"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get device access profile by id",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer", example=5)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Access profile",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceAccess")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class ListAccessAction extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $storage;

    /**
     * @return Response
     */
    protected function action(): Response
    {

        if ($id = $this->request->getAttribute('id')) {
            $access = $this->storage->getById($id);
            if($this->user->isRulePermitted('device_access_management')) {
                return $this->respondWithData(
                    [
                        'id' => $access->getId(),
                        'name' => $access->getName(),
                        'public_community' => $access->getPublicCommunity(),
                        'private_community' => "HIDDEN",
                        'login' =>  "HIDDEN",
                        'password' =>  "HIDDEN",
                        'params' => $access->getParams() ? $access->getParams() : null,
                    ]
                );
            } else {
                return $this->respondWithData(
                    [
                        'id' => $access->getId(),
                        'name' => $access->getName(),
                    ]
                );
            }
        }
        $accesses = [];
        foreach ($this->storage->fetchAll() as $access) {
            if($this->user->isRulePermitted('device_access_management')) {
                $accesses[] = [
                    'id' => $access->getId(),
                    'name' => $access->getName(),
                    'public_community' => $access->getPublicCommunity(),
                    'private_community' => "HIDDEN",
                    'login' =>  "HIDDEN",
                    'password' =>  "HIDDEN",
                    'params' => $access->getParams() ? $access->getParams() : null,
                ];
            } else {
                $accesses[] = [
                    'id' => $access->getId(),
                    'name' => $access->getName(),
                    'public_community' => '',
                    'private_community' => '',
                    'login' => '',
                    'password' => '',
                ];
            }
        }
        return  $this->respondWithData($accesses);
    }
}
