<?php


namespace WCAA\Api\Actions\Devices\Accesses;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Put(
 *   path="/device-access/{id}",
 *   tags={"device-access"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update device access profile",
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer", example=5)
 *   ),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="name", type="string", example="Default SNMP"),
 *       @OA\Property(property="public_community", type="string", example="public"),
 *       @OA\Property(property="private_community", type="string", example="HIDDEN"),
 *       @OA\Property(property="login", type="string", example="HIDDEN"),
 *       @OA\Property(property="password", type="string", example="HIDDEN"),
 *       @OA\Property(property="params", type="object", nullable=true, additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated access profile",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/DeviceAccess")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class EditAccessAction extends PrivateAction
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
        $id = $this->request->getAttribute('id');
        $access = $this->storage->getById( $id);
        $data = $this->getFormData();
        if(isset($data['name'])) {
            $value = trim((string)$data['name']);
            if ($value === '') {
                throw new HttpBadRequestException($this->request, "Name is required");
            }
            $access->setName($value);
        }
        if(isset($data['public_community']) && $data['public_community'] != 'HIDDEN') {
            $value = trim((string)$data['public_community']);
            if ($value === '') {
                throw new HttpBadRequestException($this->request, "Community is required");
            }
            $access->setPublicCommunity($value);
        }
        if(isset($data['private_community']) && $data['private_community'] != 'HIDDEN') {
            $access->setPrivateCommunity(trim((string)$data['private_community']));
        }
        if(isset($data['login']) && $data['login'] != 'HIDDEN') {
            $value = trim((string)$data['login']);
            if ($value === '') {
                throw new HttpBadRequestException($this->request, "Login is required");
            }
            $access->setLogin($value);
        }
        if(isset($data['password']) && $data['password'] != 'HIDDEN') {
            $value = trim((string)$data['password']);
            if ($value === '') {
                throw new HttpBadRequestException($this->request, "Password is required");
            }
            $access->setPassword($value);
        }
        if(isset($data['params'])) {
            $access->setParams($data['params']);
        }
        $access = $this->storage->update($access);
        $this->addActionSuccess("device-access:edited", "Access with name {$access->getName()} success updated" );

        $access->setParams($access->getParams() ? $access->getParams() : null);
        $acc =  [
            'id' => $access->getId(),
            'name' => $access->getName(),
            'public_community' => $access->getPublicCommunity(),
            'private_community' => "HIDDEN",
            'login' =>  "HIDDEN",
            'password' =>  "HIDDEN",
            'params' => $access->getParams() ? $access->getParams() : null,
        ];
        return  $this->respondWithData($acc);
    }

}
