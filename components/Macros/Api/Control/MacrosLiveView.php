<?php

namespace WCC\Macros\Api\Control;

use OpenApi\Annotations as OA;
use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Macros\Controllers\MacrosGateway;
use WCC\Macros\Storage\MacrosStorage;
/**
 * @return Response
 *
 * @OA\Post(
 *   path="/component/macros/control/preview",
 *   description="Preview macros template with live variables",
 *   tags={"macros-control"},
 *   security={{"XAuthKey":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="device", type="object", @OA\Property(property="id", type="integer", description="Device ID from database")),
 *       @OA\Property(property="interface", type="object", @OA\Property(property="id", type="integer")),
 *       @OA\Property(property="params", type="object", nullable=true, additionalProperties={}),
 *       @OA\Property(property="from", type="string", enum={"device","cache","store"}, description="Data source: device — read from device; cache — read from cache, if stale then query device; store — read from cache, if missing then error."),
 *       @OA\Property(property="template", type="string")
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

class MacrosLiveView extends PrivateAction
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
        $device = null;
        $iface = null;
        $parameters = [];
        $from = 'cache';
        $template = '';
        if(!isset($data['device']['id'])) {
            throw new \Exception("Choose device is required");
        }
        if(isset($data['interface']['id'])) {
            $iface = $this->deviceInterfaceStorage->getById($data['interface']['id']);
        }
        if(isset($data['params'])) {
            $parameters = $data['params'];
        }
        if(isset($data['from'])) {
            $from = $data['from'];
        }
        if(isset($data['template'])) {
            $template = $data['template'];
        }

        $variables = $this->gateway->generateVariables($this->deviceStorage->getById($data['device']['id']), $iface, $parameters, $from);
        $template = $this->gateway->buildTemplate($template, $variables);
        return  $this->respondWithData($template);
    }

}
