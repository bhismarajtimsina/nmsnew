<?php

namespace WCAA\Api\Actions\Maps;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\DeviceGroup;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Links\Models\Link;
use WCC\Links\Storage\LinkStorage;
use WCC\Pinger\Models\PingerDeviceStatus;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;

class GetDeviceLinks extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;


    /**
     * @Inject
     * @var LinkStorage
     */
    protected $linkStorage;


    protected function action(): Response
    {
        $allowedGroups = $this->getDeviceGroupsIdsFromUser();

        $data = $this->getFormData();
        $groups = [];
        if (isset($data['groups'])) {
            foreach ($data['groups'] as $gr) {
                if (in_array($gr['id'], $allowedGroups)) {
                    $groups[]  = $gr['id'];
                }
            }
        } else {
            foreach ($allowedGroups as $gr) {
                $groups[] = $gr;
            }
        }
        $response = [];

        foreach ($this->linkStorage->fetchAll() as $link) {
            if(!$link->getSrcDevice()->getCoordinates())  continue;
            if(!$link->getDestDevice()->getCoordinates())  continue;
            if(!in_array($link->getSrcDevice()->getGroup()->getId(), $groups)) continue;
            if(!in_array($link->getDestDevice()->getGroup()->getId(), $groups)) continue;
            $response[] = [
               'coordinates' =>  [
                    'src' => $link->getSrcDevice()->getCoordinates(),
                    'dest' => $link->getDestDevice()->getCoordinates(),
               ],
               'id' => $link->getId(),
               'source' => $link->getSource(),
               'params' => $link->getParams(),
               'devices' => [
                 'src' => [
                     'id' => $link->getSrcDevice()->getId(),
                     'ip' => $link->getSrcDevice()->getIp(),
                     'name' => $link->getSrcDevice()->getName(),
                 ],
                 'dest' => [
                     'id' => $link->getDestDevice()->getId(),
                     'ip' => $link->getDestDevice()->getIp(),
                     'name' => $link->getDestDevice()->getName(),
                 ]
               ],
               'interfaces' => [
                 'src' => $link->getSrcIface() ? $link->getSrcIface()->getAsArrayLite() : null,
                 'dest' => $link->getDestIface() ? $link->getDestIface()->getAsArrayLite() : null,
               ],
            ];
        }

        return $this->respondWithData($response);
    }

}