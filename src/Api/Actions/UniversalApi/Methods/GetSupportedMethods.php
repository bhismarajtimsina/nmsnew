<?php

namespace WCAA\Api\Actions\UniversalApi\Methods;

use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\Action;

class GetSupportedMethods extends AbstractMethod
{

    protected $supportedMethods = [
        'get_device_list' => "Возвращает список устройств",
        'get_device_model' => "Список моделей для устройств",
        'get_device_type' => "Типы устройств",
        'get_supported_method_list' => "Список поддерживаемых модулей",
    ];

    protected function action()
    {
        return $this->supportedMethods;
    }

}