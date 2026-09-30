<?php

namespace WCC\Pinger\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCC\Pinger\Controllers\Controller;
/**
 * @OA\Post(
 *   path="/component/pinger/pinger",
 *   tags={"pinger"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update pinger hosts statuses",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="array",
 *       @OA\Items(
 *         type="object",
 *         required={"ip"},
 *         @OA\Property(property="ip", type="string", example="192.168.1.10"),
 *         @OA\Property(property="status", type="boolean", example=true)
 *       )
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Update result",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="boolean", example=true)
 *     )
 *   )
 * )
 */

class PingerUpdateHostsStatusAction extends PrivateAction
{
        /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    protected function action(): Response
    {
        $data = $this->getFormData();
        $this->controller->updatePingerHosts($data);
        return  $this->respondWithData(true);
    }

}
