<?php

namespace WCAA\Api\Actions\Maps;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Devices\DeviceGroupStorage;

class GetDeviceGroups extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;
    protected function action(): Response
    {
        $allowedGroups = $this->getDeviceGroupsIdsFromUser();
        $response = [];
        foreach ($this->deviceGroupStorage->fetchAll() as $gr) {
            if(in_array($gr->getId(), $allowedGroups)) {
                $response[] = $gr->getAsArrayLite();
            }
        }
        return  $this->respondWithData($response);
    }

}