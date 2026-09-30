<?php

namespace WCC\Diagnostic\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\SystemActionLogger;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\Diagnostic\Controllers\Controller;

class ArpPing extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    /**
     * @Inject
     * @var SystemActionLogger
     */
    protected $sysLogger;


    protected function action(): Response
    {
        $data = $this->getFormData();
        try {
            if (!isset($data['router'])) {
                throw new HttpBadRequestException($this->request, "Parameter 'router' is required");
            } elseif (is_numeric($data['router'])) {
                $router = $this->deviceStorage->getById($data['router']);
            } else {
                $router = $this->deviceStorage->getByIp($data['router']);
            }
            if (!isset($data['ip'])) {
                throw new HttpBadRequestException($this->request, "Parameter 'ip' is required");
            }
            $vlanId = null;
            if (isset($data['vlan_id'])) {
                $vlanId = $data['vlan_id'];
            }
            $vlanName = null;
            if (isset($data['vlan_name'])) {
                $vlanName = $data['vlan_name'];
            }
            $count = 4;
            if (isset($data['count'])) {
                $count = $data['count'];
            }
            $arpPing = $this->controller->arpPingIP($router, $data['ip'], $vlanId, $count, $vlanName);
            $this->sysLogger->success(
              'diagnostic:arp-ping',
                "ARP ping for IP={$data['ip']} on router={$router->getIp()}",
                [
                    'router' => $router->getIp(),
                    'parameters' => $data
                ],
                $router,
            );
            return $this->respondWithData($arpPing);
        } catch (\Throwable $e) {
            $this->sysLogger->failed(
                'diagnostic:arp-ping',
                "ARP ping for IP={$data['ip']} on router={$data['router']}. Err: {$e->getMessage()}",
                [
                    'router' => $data['router'],
                    'line' => "{$e->getFile()}:{$e->getLine()}",
                    'trace' => $e->getTraceAsString(),
                    'parameters' => $data,
                ],

            );
            throw $e;
        }
    }
}
