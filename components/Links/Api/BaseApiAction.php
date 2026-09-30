<?php

namespace WCC\Links\Api;

use WCAA\Api\Actions\PrivateAction;
use WCC\Links\Controllers\Controller;

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

    /**
     * @param Controller $controller
     * @return BaseApiAction
     */
    public function setController(Controller $controller): BaseApiAction
    {
        $this->controller = $controller;
        return $this;
    }



}