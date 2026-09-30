<?php

namespace WCAA\Api\Actions\UniversalApi\Methods;

use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\Action;
use WCAA\App;

class GetDeviceType extends AbstractMethod
{
    /**
     * @Inject
     * @var App
     */
    protected $app;
    protected function action()
    {
       return  $this->app->conf('universal_api.types');
    }

}