<?php

namespace WCC\SearchDevice\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\SystemActionLogger;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\SearchDevice\Controllers\Controller;

class SearchIpWithFdbAction extends PrivateAction
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
        try {
            $data = $this->getFormData();
            if (!isset($data['devices']) || !is_array($data['devices'])) {
                $devices = $this->deviceStorage->fetchAll();
            } else {
                $devices = $this->prepareDevicesByIdents($data['devices']);
            }
            if (!isset($data['routers']) || !is_array($data['routers'])) {
                $routers = $this->deviceStorage->fetchAll();
            } else {
                $routers = $this->prepareDevicesByIdents($data['routers']);
            }
            if (!isset($data['ip'])) {
                throw new HttpBadRequestException($this->request, "Field IP is required");
            }
            $arp = $this->controller->searchIp($routers, $data['ip']);
            if (count($arp) === 0) {
                throw new \Exception("ARP for IP={$data['ip']} not found");
            } elseif (count($arp) > 1) {
                throw new \Exception("Found multiple arp for IP={$data['ip']}, but allowed only uniq");
            }
            $fdb = $this->controller->searchMac($devices, $arp[0]['mac'], $arp[0]['vlan_id']);
            $this->sysLogger->success(
                "search_device:arp-fdb-by-ip-search",
                "Found IP={$data['ip']} by ARP-FDB searching",
                ['ip'=>$data['ip'], 'parameters'=>$data, 'found'=>['fdb'=>$fdb,'arp'=>$arp]],
            );

            if (count($fdb) === 0) {
                throw new \Exception("FDB for IP={$data['ip']}, MAC={$arp[0]['mac']}, VLANID={$arp[0]['vlan_id']} not found");
            } elseif (count($fdb) > 1) {
                throw new \Exception("Found multiple FDB for IP={$data['ip']}, MAC={$arp[0]['mac']}, VLANID={$arp[0]['vlan_id']}, but allowed only uniq");
            }
            return $this->respondWithData([
                'arp' => $arp[0],
                'fdb' => $fdb[0],
            ]);
        } catch (\Throwable $e) {
            $this->sysLogger->failed(
                "search_device:arp-fdb-by-ip-search",
                "Error search IP={$data['ip']} with error: {$e->getMessage()}",
                [
                    'line' => "{$e->getMessage()}:{$e->getLine()}",
                    'trace' => $e->getTraceAsString(),
                    'ip'=>$data['ip'], 'parameters'=>$data, 'found'=>['fdb'=>$fdb,'arp'=>$arp]],

            );
            throw $e;
        }
    }
    /**
     * @param string[] $deviceIdents
     * @return Device[]
     */
    protected function prepareDevicesByIdents($deviceIdents)
    {
        $devices = [];
        foreach ($deviceIdents as $deviceIdent) {
            try {
                if(is_numeric($deviceIdent)) {
                    $devices[] = $this->deviceStorage->getById($deviceIdent);
                } else {
                    $devices[] = $this->deviceStorage->getByIp($deviceIdent);
                }
            } catch (\Throwable $e) {
                $this->logger->error("Device with ident '{$deviceIdent}' not found in storage");
            }
        }
        return $devices;
    }
}
