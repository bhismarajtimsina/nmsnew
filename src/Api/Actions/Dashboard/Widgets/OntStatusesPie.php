<?php

namespace WCAA\Api\Actions\Dashboard\Widgets;

use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\UserRoleStorage;
use WCAA\Storage\UserStorage;

class OntStatusesPie extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;


    protected function action(): Response
    {
        $groups = $this->getDeviceGroupsIdsFromUser();
        $stat = $this->deviceInterfaceStorage->getOntStatuses($groups);
        $labels = [];
        $data = [];
        $backgroundColors = [];
        $raw = [];
        foreach ($stat as $st) {
            $labels[] = $st['status'];
            $data[] = $st['count'];
            $raw[$st['status']] = $st['count'];
            switch ($st['status']) {
                case 'LOS': $backgroundColors[] = 'rgba(139, 0, 0, 1)'; break;
                case 'Offline':  $backgroundColors[] = 'rgba(150, 150, 150, 1)'; break;
                case 'Online':  $backgroundColors[] = 'rgba(10, 115, 24, 1)'; break;
                case 'PowerOff':  $backgroundColors[] = 'rgba(0, 49, 128, 1)'; break;
            }
        }
        if(isset($this->request->getQueryParams()['raw']) && isPositiveParameter($this->request->getQueryParams()['raw'])) {
            return $this->respondWithData($raw);
        }
        return  $this->respondWithData([
            'labels' =>  $labels,
            'datasets' => [
                [
                    'label' => '',
                    'data' => $data,
                    'backgroundColor' => $backgroundColors,
                ]
            ],
        ]);
    }

}