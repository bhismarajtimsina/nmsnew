<?php

namespace WCAA\Api\Actions\Dashboard\Template;

use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\Dashboard\DashboardTemplate;

abstract class DashboardTemplateAction extends PrivateAction
{

    /**
     * @Inject
     * @var DashboardTemplate
     */
    protected $dashboard;
}