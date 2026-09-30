<?php


namespace WCAA\Api\Actions\Devices\Accesses;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Post(
 *   path="/device-access",
 *   tags={"device-access"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create device access profile",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"name","public_community","login","password"},
 *       @OA\Property(property="name", type="string", example="Default SNMP"),
 *       @OA\Property(property="public_community", type="string", example="public"),
 *       @OA\Property(property="private_community", type="string", nullable=true, example="private"),
 *       @OA\Property(property="login", type="string", example="admin"),
 *       @OA\Property(property="password", type="string", format="password", example="secret"),
 *       @OA\Property(property="params", type="object", nullable=true, additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Created access profile",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceAccess")
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class AddAccessAction extends PrivateAction
{
    protected $forbiddenInDemo = true;

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
        $access = new DeviceAccess();
        $data = $this->getFormData();
        if(isset($data['name']) && trim((string)$data['name']) !== '') {
            $access->setName(trim((string)$data['name']));
        } else {
            throw new HttpBadRequestException($this->request, "Name is required");
        }
        if(isset($data['public_community']) && trim((string)$data['public_community']) !== '') {
            $access->setPublicCommunity(trim((string)$data['public_community']));
        } else {
            throw new HttpBadRequestException($this->request, "Community is required");
        }
        if(isset($data['private_community'])) {
            $access->setPrivateCommunity(trim((string)$data['private_community']));
        }

        if(isset($data['login']) && trim((string)$data['login']) !== '') {
            $access->setLogin(trim((string)$data['login']));
        } else {
            throw new HttpBadRequestException($this->request, "Login is required");
        }
        if(isset($data['password']) && trim((string)$data['password']) !== '') {
            $access->setPassword(trim((string)$data['password']));
        } else {
            throw new HttpBadRequestException($this->request, "Password is required");
        }
        if(isset($data['params'])) {
            $access->setParams($data['params']);
        }
        $access = $this->storage->add($access);
        $this->addActionSuccess("device-access:added", "Access with name {$access->getName()} success added");
        return  $this->respondWithData($access->getAsArray());
    }
}
