<?php

namespace WCC\Paths\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Put(
 *   path="/component/paths/{id}/segments",
 *   tags={"paths"},
 *   security={{"XAuthKey": {}}},
 *   summary="Replace the ordered hop list of a path",
 *   description="Segments are positional, so the complete ordered chain of link ids must be submitted.",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=1)),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"link_ids"},
 *       @OA\Property(property="link_ids", type="array", @OA\Items(type="integer"), example={4,7,11})
 *     )
 *   ),
 *   @OA\Response(response=200, description="Updated path"),
 *   @OA\Response(response=400, description="Bad Request"),
 *   @OA\Response(response=404, description="Not found"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class SetSegmentsAction extends BaseApiAction
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $path = $this->controller->getById($this->resolveArg('id'));
        if (!$path) {
            throw new DomainRecordNotFoundException("Path not found");
        }
        $form = $this->getFormData();
        if (!isset($form['link_ids']) || !is_array($form['link_ids'])) {
            throw new HttpBadRequestException($this->request, "Field 'link_ids' must be an array of link ids");
        }
        foreach ($form['link_ids'] as $linkId) {
            if (!is_numeric($linkId)) {
                throw new HttpBadRequestException($this->request, "link_ids must contain only numeric link ids");
            }
        }

        $this->controller->setSegments($path->getId(), $form['link_ids']);

        $this->addActionSuccess(
            'path:segments-updated',
            "Segments of path '{$path->getName()}' updated by user {$this->user->getName()}",
            ['path_id' => $path->getId(), 'link_ids' => $form['link_ids']]
        );

        return $this->respondWithData($this->controller->getById($path->getId())->getAsArray());
    }
}
