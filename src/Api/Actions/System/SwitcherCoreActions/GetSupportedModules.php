<?php


namespace WCAA\Api\Actions\System\SwitcherCoreActions;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\SwitcherCoreActionStorage;
use WCAA\Storage\SystemActionsStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Get(
 *   path="/logs/switcher-core/supported-modules",
 *   tags={"system-switcher-core"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get switcher-core modules available in logs",
 *   @OA\Response(
 *     response=200,
 *     description="Supported modules",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="string"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetSupportedModules extends PrivateAction
{
    /**
     * @Inject
     * @var SwitcherCoreActionStorage
     */
    protected $actionStorage;

    /**
     * @return Response
     */
    protected function action(): Response
    {

        $resp = $this->actionStorage->getModulesList();
        return  $this->respondWithData($resp);
    }

}
