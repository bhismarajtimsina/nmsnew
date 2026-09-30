<?php

namespace WCC\OntsRegistration\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCC\OntsRegistration\Storage\UnregisteredOntMacroStorage;
/**
 * @OA\Get(
 *   path="/component/onts_registration/control",
 *   tags={"onts-registration-control"},
 *   security={{"XAuthKey": {}}},
 *   summary="List registration macros",
 *   @OA\Response(response=200, description="Macros list", @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)), @OA\Property(property="meta", type="object", additionalProperties=true)))
 * )
 */

class MacrosList extends PrivateAction
{
        /**
     * @Inject
     * @var UnregisteredOntMacroStorage
     */
    protected $macrosStorage;

    protected function action(): Response
    {
        $macroses = $this->macrosStorage->getAll();
        $data = [];
        foreach ($macroses as $macros) {
            $data[] = [
              'id' => $macros->getId(),
              'created_at' => $macros->getCreatedAt(),
              'updated_at' => $macros->getUpdatedAt(),
              'enabled' => $macros->isEnabled(),
              'name' => $macros->getName(),
              'models' => array_map(function ($m) {
                  return [
                    'id' => $m->getId(),
                    'key' => $m->getKey(),
                    'name' => $m->getName(),
                      'vendor' => $m->getVendor(),
                  ];
              }, $macros->getModels()),
            ];
        }
        $pagination = $this->paginationFromParams($data);
        return  $this->respondWithData($pagination['data'], $pagination['meta']);
    }


}
