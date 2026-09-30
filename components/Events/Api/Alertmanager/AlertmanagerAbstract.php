<?php

namespace WCC\Events\Api\Alertmanager;

use Monolog\Logger;
use WCAA\Api\Actions\PrivateAction;
use WCC\Events\Controllers\Alertmanager;
use WCC\Events\Controllers\Controller;

abstract class AlertmanagerAbstract extends PrivateAction
{
    /**
     * @var Alertmanager
     */
    protected $alertmanager;

    function __construct(Controller $controller, Logger $logger)
    {
        $this->alertmanager = $controller->getAlertManager();
        parent::__construct($logger);
    }

}