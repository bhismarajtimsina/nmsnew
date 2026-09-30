<?php

namespace WCC\Macros\Api;

use OpenApi\Annotations as OA;
use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Macros\Controllers\MacrosGateway;
use WCC\Macros\Models\Macros;
use WCC\Macros\Storage\MacrosStorage;
/**
 * @return Response
 *
 * @OA\Post(
 *   path="/component/macros/variables",
 *   description="Preview variables for macros execution",
 *   tags={"macros"},
 *   security={{"XAuthKey":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="device", type="object", @OA\Property(property="id", type="integer", description="Device ID from database")),
 *       @OA\Property(property="macros", type="object", @OA\Property(property="id", type="integer")),
 *       @OA\Property(property="interface", type="object", @OA\Property(property="id", type="integer"), @OA\Property(property="bind_key", type="string")),
 *       @OA\Property(property="params", type="object", nullable=true, additionalProperties={}),
 *       @OA\Property(property="from", type="string", enum={"device","cache","store"}, description="Data source: device — read from device; cache — read from cache, if stale then query device; store — read from cache, if missing then error.")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", additionalProperties={}),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */

class PreviewVariables extends PrivateAction
{


    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var MacrosStorage
     */
    protected $macrosStorage;

    /**
     * @Inject
     * @var MacrosGateway
     */
    protected $gateway;

        protected function action(): Response
    {
        $this->gateway->setUser($this->user);
        $data = $this->getFormData();

        if (!isset($data['device']['id'])) {
            throw new \Exception("Choose device is required");
        }
        if (!isset($data['macros'])) {
            throw new HttpBadRequestException($this->request, "macros is required");
        }

        $macros = $this->macrosStorage->fill(new Macros($data['macros']['id']));
        if (!array_filter($macros->getAllowedRoles(), function ($role) {
            return $role->getId() == $this->user->getRole()->getId();
        })) {
            throw new HttpForbiddenException($this->request, "Insufficient permissions to execute. Please, contact your administrator");
        }

        $iface = null;
        $parameters = [];
        $from = 'cache';
        $device = $this->deviceStorage->getById($data['device']['id']);
        if (isset($data['interface']['id'])) {
            $iface = $this->deviceInterfaceStorage->getById($data['interface']['id']);
        } elseif (isset($data['interface']['bind_key'])) {
            $iface = $this->deviceInterfaceStorage->getByDeviceAndKey($device, $data['interface']['bind_key']);
        }
        if (isset($data['params'])) {
            $parameters = $data['params'];
        }
        if (isset($data['from'])) {
            $from = $data['from'];
        }

        $variables = $this->gateway->generateVariables($device, $iface, $parameters, $from);
        return $this->respondWithData($variables );
    }

}
