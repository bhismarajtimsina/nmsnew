<?php

namespace WCC\OntsRegistration\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCC\OntsRegistration\Models\UnregisteredOntMacro;
use WCC\OntsRegistration\Storage\UnregisteredOntMacroStorage;
/**
 * @OA\Delete(
 *   path="/component/onts_registration/control/{id}",
 *   tags={"onts-registration-control"},
 *   security={{"XAuthKey": {}}},
 *   summary="Delete registration macro",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=1)),
 *   @OA\Response(response=200, description="Delete result", @OA\JsonContent(type="object", @OA\Property(property="data", type="boolean", example=true)))
 * )
 */

class DeleteMacros extends PrivateAction
{
        protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var UnregisteredOntMacroStorage
     */
    protected $macrosStorage;

    protected function action(): Response
    {
        try {
            $id = $this->request->getAttribute('id');
            $this->macrosStorage->delete(new UnregisteredOntMacro($id));
            $this->addActionFailed("unregistered_onts:delete", "User {$this->user->getName()} deleted macros with ID={$this->request->getAttribute('id')}");
            return $this->respondWithData(true);
        } catch (\Throwable $e) {
            $this->addActionFailed("unregistered_onts:delete", "User {$this->user->getName()} deleted macros with ID={$this->request->getAttribute('id')}", $e);
            throw $e;
        }
    }


}
