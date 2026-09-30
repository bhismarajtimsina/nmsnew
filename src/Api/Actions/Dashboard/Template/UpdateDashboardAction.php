<?php

namespace WCAA\Api\Actions\Dashboard\Template;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpInternalServerErrorException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;

class UpdateDashboardAction extends DashboardTemplateAction
{
    protected function action(): Response
    {
        $form = $this->getFormData();
        if(!$this->user->getRole()->isPermitted('dashboard_edit')) {
            throw new \Exception("Edit dashboard not permitted");
        }
        if($this->user->getRole()->isPermitted('dashboard_global_edit') && isset($form['is_global']) && $form['is_global'] && $this->isAllowedByDemoRules($this->request)) {
            $this->dashboard->resetAllUserDashboards();
            $this->dashboard->updateCustomDashboard($form['layout']);
        }
        $this->dashboard->updateUserDashboard($this->user, $form['layout']);
        return $this->respondWithData($form);
    }

}