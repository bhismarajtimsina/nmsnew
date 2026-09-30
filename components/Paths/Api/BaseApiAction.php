<?php

namespace WCC\Paths\Api;

use WCAA\Api\Actions\PrivateAction;
use WCC\Paths\Controllers\Controller;

abstract class BaseApiAction extends PrivateAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @return Controller
     */
    public function getController(): Controller
    {
        return $this->controller;
    }
}
