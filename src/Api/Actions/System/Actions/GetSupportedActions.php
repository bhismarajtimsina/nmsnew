<?php


namespace WCAA\Api\Actions\System\Actions;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\SystemActionsStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

/**
 * @OA\Get(
 *   path="/logs/actions-list",
 *   tags={"system-logs"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get supported action keys",
 *   @OA\Response(
 *     response=200,
 *     description="Supported actions",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="string"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetSupportedActions extends PrivateAction
{
    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $actionStorage;

    /**
     * @return Response
     */
    protected function action(): Response
    {

        $resp = $this->actionStorage->getActionsList();
        return  $this->respondWithData($resp);
    }

}
