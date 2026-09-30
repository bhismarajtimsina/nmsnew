<?php

namespace WCAA\Api\Actions\Dashboard\Template;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpInternalServerErrorException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

class ResetToDefaultsAction extends DashboardTemplateAction
{
    protected function action(): Response
    {

    }

}