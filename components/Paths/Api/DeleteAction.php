<?php

namespace WCC\Paths\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Delete(
 *   path="/component/paths/{id}",
 *   tags={"paths"},
 *   security={{"XAuthKey": {}}},
 *   summary="Delete a transport path",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=1)),
 *   @OA\Response(response=200, description="Deleted"),
 *   @OA\Response(response=404, description="Not found"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class DeleteAction extends BaseApiAction
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $path = $this->controller->getById($this->resolveArg('id'));
        if (!$path) {
            throw new DomainRecordNotFoundException("Path not found");
        }
        $this->controller->delete($path);
        $this->addActionSuccess(
            'path:deleted',
            "Path '{$path->getName()}' deleted by user {$this->user->getName()}",
            $path->getAsArrayLite()
        );
        return $this->respondWithData(true);
    }
}
