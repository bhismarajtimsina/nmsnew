<?php

namespace WCAA\Api\Actions\Dashboard\Template;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpInternalServerErrorException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

class GetDashboardAction extends DashboardTemplateAction
{
    protected function action(): Response
    {
        try {
            return $this->respondWithData($this->dashboard->getUserDashboard($this->user), ['source' => 'user']);
        } catch (\Throwable $eu) {}

        // Between the user's own layout and the global one: what this role
        // opens on. Without this step every new account, whatever its job,
        // lands on the same shipped default.
        try {
            return $this->respondWithData($this->dashboard->getRoleDashboard($this->user), ['source' => 'role']);
        } catch (\Throwable $er) {}

        try {
            return $this->respondWithData($this->dashboard->getCustomDashboard(), ['source' => 'custom']);
        } catch (\Throwable $ec) {}

        try {
            return $this->respondWithData($this->dashboard->getDefaultDashboard(), ['source' => 'default']);
        } catch (\Throwable $ed) {}

        throw new HttpInternalServerErrorException($this->request, "Error load dashboard, not found");
    }

}