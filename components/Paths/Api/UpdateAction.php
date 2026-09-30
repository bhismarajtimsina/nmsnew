<?php

namespace WCC\Paths\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Put(
 *   path="/component/paths/{id}",
 *   tags={"paths"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update a transport path",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=1)),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="name", type="string", example="Kathmandu -> Damak -> Pathari"),
 *       @OA\Property(property="group_key", type="string", example="ktm-pathari"),
 *       @OA\Property(property="priority", type="integer", example=20),
 *       @OA\Property(property="enabled", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(response=200, description="Updated path"),
 *   @OA\Response(response=404, description="Not found"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class UpdateAction extends BaseApiAction
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $path = $this->controller->getById($this->resolveArg('id'));
        if (!$path) {
            throw new DomainRecordNotFoundException("Path not found");
        }
        $form = $this->getFormData();

        if (isset($form['name']) && $form['name'] !== '') {
            $path->setName((string)$form['name']);
        }
        if (array_key_exists('group_key', $form)) {
            $path->setGroupKey($form['group_key'] === '' ? null : (string)$form['group_key']);
        }
        if (isset($form['priority'])) {
            $path->setPriority((int)$form['priority']);
        }
        if (isset($form['endpoint_a_id'])) {
            $path->setEndpointAId((int)$form['endpoint_a_id']);
        }
        if (isset($form['endpoint_b_id'])) {
            $path->setEndpointBId((int)$form['endpoint_b_id']);
        }
        if (array_key_exists('enabled', $form)) {
            $path->setEnabled((bool)$form['enabled']);
        }
        if (isset($form['params']) && is_array($form['params'])) {
            $path->setParams($form['params']);
        }

        $this->controller->update($path);

        if (isset($form['link_ids']) && is_array($form['link_ids'])) {
            $this->controller->setSegments($path->getId(), $form['link_ids']);
        }

        $this->addActionSuccess(
            'path:updated',
            "Path '{$path->getName()}' updated by user {$this->user->getName()}",
            $path->getAsArrayLite()
        );

        return $this->respondWithData($this->controller->getById($path->getId())->getAsArray());
    }
}
