<?php

namespace WCC\Console\Api;

use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use WCAA\Api\Actions\Action;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCAA\Storage\UserStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Console\Controllers\Controller;
use WCC\Console\Storage\ConsoleHistoryStorage;

class GetLogDetail extends PrivateAction
{
    /**
     * @var ConsoleHistoryStorage
     */
    protected $consoleHistoryStorage;

    function __construct(ConsoleHistoryStorage $storage, Logger $logger)
    {
        $this->consoleHistoryStorage = $storage;
        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws HttpBadRequestException
     */
    protected function action(): Response
    {
        $response = $this->consoleHistoryStorage->getById($this->request->getAttribute('id'));
        return $this->respondWithData($response->getAsArray());
    }
}