<?php

namespace WCC\RouterOS\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
/**
 * @OA\Get(
 *   path="/component/router_os/device/{id}/arp-list",
 *   tags={"router-os"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get RouterOS ARP list",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string", example="101")),
 *   @OA\Response(response=200, description="ARP list", @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)), @OA\Property(property="meta", type="object", additionalProperties=true)))
 * )
 */

class ArpInfoAction extends AbstractRouterOSAction
{
        protected function action(): Response
    {
        try {
            $request = $this->request->getQueryParams();
            unset($request['from']);
            $response = $this->controller
                ->setDevice($this->getDevice())
                ->getArps($request, $this->getFrom());
            $this->addActionSuccess(
                'router-os:arps-list',
                "Success get arps from device {$this->getDevice()->getName()} ({$this->getDevice()->getIp()})",
                [],
                $this->getDevice(),
            );
            return $this->respondWithData($response, $this->controller->getMeta());
        } catch (\Throwable $e) {
            $this->addActionFailed('router-os:arps-list', $e->getMessage(), $e, [
                'request' => $this->request->getQueryParams(),
                'attributes' => $this->request->getAttributes(),
            ],
                $this->getDevice(),
            );
            throw $e;
        }
    }

}
