<?php

namespace WCC\AutoDiscovery\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

class GetDiscoveryNetworks extends AbstractAutoDiscovery
{
    protected function action(): Response
    {
        $response = [];
        foreach ($this->controller->getDiscoveryNetworks() as $gr) {
            $response[] = $gr->getAsArray();
        }
        return $this->respondWithData($response);
    }
}