<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
/**
 * @OA\Get(
 *   path="/component/links/by-interface/{interface_id}",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get links by interface ID",
 *   @OA\Parameter(name="interface_id", in="path", required=true, @OA\Schema(type="integer", example=2435)),
 *   @OA\Response(
 *     response=200,
 *     description="Links list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/LinkLite"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetAllByInterfaceAction extends BaseApiAction
{
    protected function action(): Response
    {
        $interface = $this->controller->getDeviceInterfaceStorage()->getById($this->request->getAttribute('interface_id'));
        $links = array_map(function ($e) {return $e->getAsArrayLite();}, $this->controller->getByInterface($interface));
        return $this->respondWithData($links);
    }
}
