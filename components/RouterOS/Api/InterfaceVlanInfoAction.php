<?php

namespace WCC\RouterOS\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
/**
 * @OA\Get(
 *   path="/component/router_os/device/{id}/interface-vlans-list",
 *   tags={"router-os"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get RouterOS interface VLANs",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", example="101")),
 *   @OA\Response(response=200, description="Interface VLAN list", @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)), @OA\Property(property="meta", type="object", additionalProperties=true)))
 * )
 */

class InterfaceVlanInfoAction extends AbstractRouterOSAction
{
        protected function action(): Response
    {
        try {
            $request = $this->request->getQueryParams();
            unset($request['from']);
            $response = $this->controller
                ->setDevice($this->getDevice())
                ->getInterfaceVlanInfo($request, $this->getFrom());
            $this->addActionSuccess(
                'router-os:interfaces-vlan-list',
                "Success get info from device {$this->getDevice()->getName()} ({$this->getDevice()->getIp()})",
                [],
                $this->getDevice(),
            );
            return $this->respondWithData($response, $this->controller->getMeta());
        } catch (\Throwable $e) {
            $this->addActionFailed('router-os:interfaces-vlan-list', $e->getMessage(), $e, [
                'request' => $this->request->getQueryParams(),
                'attributes' => $this->request->getAttributes(),
            ]);
            throw $e;
        }
    }

}
