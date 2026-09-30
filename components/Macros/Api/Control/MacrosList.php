<?php

namespace WCC\Macros\Api\Control;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Macros\Storage\MacrosStorage;
/**
 * @OA\Get(
 *   path="/component/macros/control",
 *   description="List all macros (control)",
 *   tags={"macros-control"},
 *   security={{"XAuthKey":{}}},
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Macros")),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */

class MacrosList extends PrivateAction
{
    /**
     * @Inject
     * @var MacrosStorage
     */
    protected $macrosStorage;

        protected function action(): Response
    {
        $macroses = $this->macrosStorage->getAll();
        $data = [];
        foreach ($macroses as $macros) {
            $data[] = [
              'id' => $macros->getId(),
              'name' => $macros->getName(),
              'description' => $macros->getDescription(),
              'display_for' => $macros->getDisplayFor(),
                'display_output' => $macros->getDisplayOutput(),
              'models' => array_map(function ($m) {
                  return [
                    'id' => $m->getId(),
                    'key' => $m->getKey(),
                    'name' => $m->getName(),
                      'vendor' => $m->getVendor(),
                  ];
              }, $macros->getModels()),
              'user_roles' => array_map(function ($m) {
                  return [
                      'id' => $m->getId(),
                      'name' => $m->getName(),
                  ];
              }, $macros->getAllowedRoles()),
            ];
        }
        $pagination = $this->paginationFromParams($data);
        return  $this->respondWithData($pagination['data'], $pagination['meta']);
    }


}
