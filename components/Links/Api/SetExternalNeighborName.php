<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Exceptions\SupportException;

/**
 * @OA\Put(
 *   path="/component/links/external-neighbor-name",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Set (or clear, with an empty name) a custom display name for an unmanaged/external LLDP-neighbor pseudo-device",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"id"},
 *       @OA\Property(property="id", type="string", example="ext:25:AABBCCDDEEFF"),
 *       @OA\Property(property="name", type="string", example="ISP-X border switch")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Saved",
 *     @OA\JsonContent(type="object", @OA\Property(property="statusCode", type="integer", example=200))
 *   ),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class SetExternalNeighborName extends BaseApiAction
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $form = $this->getFormData();
        $id = $form['id'] ?? null;
        if (!$id) {
            throw new SupportException("id is required", 400);
        }
        $name = (string)($form['name'] ?? '');

        try {
            $this->controller->setExternalNeighborName($id, $name);
        } catch (\InvalidArgumentException $e) {
            throw new SupportException($e->getMessage(), 400);
        }

        $this->actionLog->success(
            'link:external_neighbor_name_set',
            "Set external-neighbor name by user {$this->user->getName()}",
            ['id' => $id, 'name' => $name]
        );

        return $this->respondWithData(['id' => $id, 'name' => $name]);
    }
}
