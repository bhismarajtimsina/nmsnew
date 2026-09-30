<?php

namespace WCAA\Api\Actions\System\Components;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

/**
 * @OA\Get(
 *   path="/system/component",
 *   tags={"system-components"},
 *   security={{"XAuthKey": {}}},
 *   summary="List system components",
 *   @OA\Response(
 *     response=200,
 *     description="Components list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/SystemComponent"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class ComponentList extends AbstractComponent
{
    protected function action(): Response
    {
       $cmpList = $this->storage->fetchAll();
       $components = [];
       foreach ($cmpList as $component) {
           $storage = $component->getAsArray();
           $storage['initialized_config'] = null;
           if(!$this->injector->isComponentExist($component->getKey())) {
               continue;
           }
           if($this->injector->isComponentExist($component->getKey()) && $component->isEnabled()) {
               $storage['initialized_config'] = $this->injector->getEnabledComponentConfig($component->getKey());
           }
           $components[] = $storage;
       }
       return  $this->respondWithData($components);
    }

}
