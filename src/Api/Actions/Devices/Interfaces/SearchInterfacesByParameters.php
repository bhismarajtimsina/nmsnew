<?php


namespace WCAA\Api\Actions\Devices\Interfaces;


use DI\Annotation\Inject;
use OpenApi\Annotations as OA;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\PollerData\OntIdentStorage;

/**
 * @OA\Get(
 *   path="/device-interface/search",
 *   tags={"device-interface"},
 *   security={{"XAuthKey": {}}},
 *   summary="Search interfaces by combined parameters",
 *   @OA\Parameter(name="device_id", in="query", required=false, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="device_ip", in="query", required=false, @OA\Schema(type="string", example="192.168.1.10")),
 *   @OA\Parameter(name="interface_name", in="query", required=false, @OA\Schema(type="string", example="Gi1/0/1")),
 *   @OA\Parameter(name="interface_bind_key", in="query", required=false, @OA\Schema(type="string", example="1/0/1")),
 *   @OA\Parameter(name="ont_ident", in="query", required=false, @OA\Schema(type="string", example="4857544300001234")),
 *   @OA\Parameter(name="mac_address", in="query", required=false, @OA\Schema(type="string", example="AA:BB:CC:DD:EE:FF")),
 *   @OA\Parameter(name="only_active_mac", in="query", required=false, @OA\Schema(type="boolean", example=true)),
 *   @OA\Response(
 *     response=200,
 *     description="Search result",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/DeviceInterface"))
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class SearchInterfacesByParameters extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var OntIdentStorage
     */
    protected $ontIdentStorage;

    /**
     * @Inject
     * @var FdbHistoryStorage
     */
    protected $fdbHistoryStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $form = [
            'device_id' => null,
            'device_ip' => null,
            'interface_name' => null,
            'interface_bind_key' => null,
            'ont_ident' => null,
            'mac_address' => null,
            'only_active_mac' => false,
        ];
        $this->replaceQueryParams($form, false);

        $response = [];
        if (($form['device_ip'] || $form['device_id']) && ($form['interface_name'] || $form['interface_bind_key'])) {
            try {
                $device = $form['device_ip'] ? $this->deviceStorage->getByIp($form['device_ip']) : $this->deviceStorage->getById($form['device_id']);
                if ($form['interface_name']) {
                    $iface = $this->storage->getByDeviceAndName($device, $form['interface_name']);
                    $response[$iface->getId()] = $iface->getAsArray();
                }
                if ($form['interface_bind_key']) {
                    $iface = $this->storage->getByDeviceAndKey($device, $form['interface_bind_key']);
                    $response[$iface->getId()] = $iface->getAsArray();
                }
            } catch (\Exception $e) {
                $this->logger->error("Error find interface by device and interface, err: {$e->getMessage()}", $form);
            }
        }
        if ($form['ont_ident']) {
            $idents = $this->ontIdentStorage->getByIdent($form['ont_ident']);
            foreach ($idents as $ident) {
                $iface = $ident->getInterface();
                $response[$iface->getId()] = $iface->getAsArray();
            }
        }

        if ($form['mac_address']) {
            $macs = $this->fdbHistoryStorage->getByMac($form['mac_address'], $form['only_active_mac']);
            foreach ($macs as $mac) {
                $iface = $mac->getInterface();
                $response[$iface->getId()] = $iface->getAsArray();
            }
        }

        return $this->respondWithData(array_values($response));
    }


}
