<?php
declare(strict_types=1);

use WCAA\Api\Middleware\CorsMiddleware;
use WCAA\Api\Middleware\SessionMiddleware;
use Slim\App;
return function (App $app) {
    $app->add(CorsMiddleware::class);
    $app->add(SessionMiddleware::class);
};
