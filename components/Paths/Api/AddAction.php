<?php

namespace WCC\Paths\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCC\Paths\Models\Path;

/**
 * @OA\Post(
 *   path="/component/paths",
 *   tags={"paths"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create a transport path",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"name","endpoint_a_id","endpoint_b_id"},
 *       @OA\Property(property="name", type="string", example="Kathmandu -> Belbari -> Pathari"),
 *       @OA\Property(property="group_key", type="string", example="ktm-pathari"),
 *       @OA\Property(property="priority", type="integer", example=10),
 *       @OA\Property(property="endpoint_a_id", type="integer", example=1),
 *       @OA\Property(property="endpoint_b_id", type="integer", example=9),
 *       @OA\Property(property="enabled", type="boolean", example=true),
 *       @OA\Property(property="link_ids", type="array", @OA\Items(type="integer"), example={4,7,11})
 *     )
 *   ),
 *   @OA\Response(response=200, description="Created path"),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class AddAction extends BaseApiAction
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $form = $this->getFormData();
        foreach (['name', 'endpoint_a_id', 'endpoint_b_id'] as $required) {
            if (!isset($form[$required]) || $form[$required] === '') {
                throw new HttpBadRequestException($this->request, "Field '{$required}' is required");
            }
        }

        $path = (new Path())
            ->setName((string)$form['name'])
            ->setEndpointAId((int)$form['endpoint_a_id'])
            ->setEndpointBId((int)$form['endpoint_b_id'])
            ->setGroupKey(isset($form['group_key']) && $form['group_key'] !== '' ? (string)$form['group_key'] : null)
            ->setPriority(isset($form['priority']) ? (int)$form['priority'] : 100)
            ->setEnabled(isset($form['enabled']) ? (bool)$form['enabled'] : true);

        if (isset($form['params']) && is_array($form['params'])) {
            $path->setParams($form['params']);
        }

        $path = $this->controller->add($path);

        //Segments are optional at creation so a path can be drawn first and
        //wired up afterwards.
        if (isset($form['link_ids']) && is_array($form['link_ids'])) {
            $this->controller->setSegments($path->getId(), $form['link_ids']);
        }

        $this->addActionSuccess(
            'path:added',
            "Path '{$path->getName()}' created by user {$this->user->getName()}",
            $path->getAsArrayLite()
        );

        return $this->respondWithData($this->controller->getById($path->getId())->getAsArray());
    }
}
