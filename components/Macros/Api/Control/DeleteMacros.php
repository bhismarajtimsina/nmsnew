<?php

namespace WCC\Macros\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\DeviceModel;
use WCAA\Models\User\UserRole;
use WCC\Macros\Models\Macros;
use WCC\Macros\Storage\MacrosStorage;
/**
 * @OA\Delete(
 *   path="/component/macros/control/{id}",
 *   description="Delete macros (control)",
 *   tags={"macros-control"},
 *   security={{"XAuthKey":{}}},
 *   @OA\Parameter(
 *     name="id",
 *     in="path",
 *     required=true,
 *     @OA\Schema(type="integer")
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="boolean"),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */

class DeleteMacros extends PrivateAction
{
    protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var MacrosStorage
     */
    protected $macrosStorage;

        protected function action(): Response
    {
        try {
            $id = $this->request->getAttribute('id');
            $this->macrosStorage->delete(new Macros($id));
            $this->addActionFailed("macros:delete", "User {$this->user->getName()} deleted macros with ID={$this->request->getAttribute('id')}");
            return $this->respondWithData(true);
        } catch (\Throwable $e) {
            $this->addActionFailed("macros:delete", "User {$this->user->getName()} deleted macros with ID={$this->request->getAttribute('id')}", $e);
            throw $e;
        }
    }


}
