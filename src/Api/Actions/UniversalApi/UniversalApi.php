<?php

namespace WCAA\Api\Actions\UniversalApi;

use DI\Annotation\Inject;
use DI\Container;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\UniversalApi\Exceptions\IncorrectRequest;
use WCAA\Api\Actions\UniversalApi\Exceptions\ServerError;
use WCAA\Api\Actions\UniversalApi\Methods\GetDeviceList;
use WCAA\Api\Actions\UniversalApi\Methods\GetDeviceModel;
use WCAA\Api\Actions\UniversalApi\Methods\GetDeviceType;
use WCAA\Api\Actions\UniversalApi\Methods\GetSupportedMethods;
use WCAA\Api\Auth;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\User\User;

class UniversalApi extends Action
{
    /**
     * @Inject
     * @var Auth
     */
    protected $auth;

    /**
     * @Inject
     * @var Container
     */
    protected $container;

    protected $methods = [
      'get_device_list' => GetDeviceList::class,
      'get_device_model' => GetDeviceModel::class,
      'get_device_type' => GetDeviceType::class,
      'get_supported_method_list' => GetSupportedMethods::class,
    ];

    protected function action(): Response
    {
        try {
            $form = [
                'key' => '',
                'cat' => '',
                'request' => null,
            ];
            $this->replaceQueryParams($form, false);
            if(!$this->auth->isKeyValid($form['key'])) {
                throw new IncorrectRequest("Incorrect API key");
            }
            if(!$this->methods[$form['request']]) {
                throw new IncorrectRequest("Method {$form['request']} not supported");
            }

            $data = $this->container->get($this->methods[$form['request']])($this->request, $this->response, $this->args);

            $json = json_encode($data, JSON_PRETTY_PRINT  | JSON_UNESCAPED_UNICODE );
            $this->response->withStatus(400, "Bad request")->getBody()->write($json);
            return $this->response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $statusCode = 500;
            if($e instanceof IncorrectRequest) {
                $statusCode = 400;
            }
            $json = json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT  | JSON_UNESCAPED_UNICODE );
            $this->response->withStatus($statusCode, "Bad request")->getBody()->write($json);
            return $this->response->withHeader('Content-Type', 'application/json');
        }
    }
}