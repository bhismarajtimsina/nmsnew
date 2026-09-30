<?php

namespace WCAA\Api\Actions\System\Components;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Put(
 *   path="/system/component/{key}",
 *   tags={"system-components"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update component state/configuration",
 *   @OA\Parameter(name="key", in="path", required=true, @OA\Schema(type="string", example="maps")),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="enabled", type="boolean", nullable=true),
 *       @OA\Property(property="configuration", type="object", nullable=true, additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated component",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/SystemComponent")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class ComponentControl extends AbstractComponent
{

    protected $forbiddenInDemo = true;
    protected function action(): Response
    {
       $key = $this->request->getAttribute('key');
       $form = $this->getFormData();
       if(isset($form['enabled'])) {
           $componentState = $this->injector->isComponentEnabled($key);
           if($form['enabled'] && !$componentState) {
               $this->injector->enableComponent($key);
           } else if (!$form['enabled'] && $componentState){
               $this->injector->disableComponent($key);
           }
       }

        $storaged = $this->storage->getByKey($key);
       if(isset($form['enabled'])) {
           $storaged->setEnabled($form['enabled']);
       }
       if(isset($form['configuration']) && $form['configuration']) {
           $storaged->setConfiguration($form['configuration']);
       }
       $this->storage->update($storaged);
       return $this->respondWithData($storaged->getAsArray());
    }

}
