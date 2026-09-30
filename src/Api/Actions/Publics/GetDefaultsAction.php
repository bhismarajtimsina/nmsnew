<?php

namespace WCAA\Api\Actions\Publics;

use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\Action;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\App;

class GetDefaultsAction extends Action
{
    /**
     * @var App
     */
    protected $app;

    function __construct(App $app, Logger $logger)
    {
        $this->app = $app;
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        return $this->respondWithData([
           'locales' => $this->app->conf('language'),
           'app_name' => $this->app->conf('system.app_name'),
           'basic_url' => _env('EXTERNAL_HTTP_ADDRESS', '/'),
           'is_whitelabel' => _env('IS_WHITELABEL', false),
           'is_demo' => $this->app->conf('demo.enabled'),
           'search2' => $this->app->conf('search2'),
           'check_password_strength' => _env('SECURE_CHECK_PASSWORD_STRENGTH', false),
        ]);
    }


}
