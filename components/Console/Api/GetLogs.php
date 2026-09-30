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

class GetLogs extends PrivateAction
{
    /**
     * @var ConsoleHistoryStorage
     */
    protected $consoleHistoryStorage;

    /**
     * @var DeviceStorage
     */
    protected $devStorage;
    /**
     * @var UserStorage
     */
    protected $userStorage;

    function __construct(ConsoleHistoryStorage $storage, Logger $logger, DeviceStorage $devStorage, UserStorage $userStorage)
    {
        $this->consoleHistoryStorage = $storage;
        $this->devStorage = $devStorage;
        $this->userStorage = $userStorage;
        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws HttpBadRequestException
     */
    protected function action(): Response
    {
        $data = $this->getFormData();
        if (!isset($data['start']) || !isset($data['stop'])) {
            throw new HttpBadRequestException($this->request, "Start and stop period is required");
        }
        $params = [
            'devices' => [],
            'users' => [],
        ];
        if (isset($data['users']))  {
             $params['users'] = array_map(function ($user) {
                  return new User($user['id']);
             }, $data['users']);
        }
        if (isset($data['devices']))  {
            $params['devices'] = array_map(function ($device) {
                return new Device($device['id']);
            }, $data['devices']);
        }
        if (!isset($data['status'])) {
            $data['status'] = '';
        }
        $data['stop'] = date('Y-m-d', strtotime($data['stop'])).' 23:59:59';
        $resp = $this->consoleHistoryStorage->getConsoleHistory(
            $data['start'], $data['stop'],
            $params['devices'],
            $params['users'],
            isset($data['only_has_error']) ? $data['only_has_error'] : false,
            isset($data['only_open_sessions']) ? $data['only_open_sessions'] : false,
        );
        $response = [];
        foreach ($resp as $d) {
            if (
                isset($data['query']) &&
                trim($data['query']) !== '' &&
                strpos(json_encode($d->getAsArray(), JSON_UNESCAPED_UNICODE), $data['query']) !== false
            ) {
                $action = $d->getAsArray();
            } elseif (
                !isset($data['query']) ||
                trim($data['query']) === ''
            ) {
                $action = $d->getAsArray();
            } else {
                continue;
            }
            $action['device']['model'] = [
                'id' => $action['device']['model']['id'],
                'name' => $action['device']['model']['name'],
            ];
            $action['user'] = [
                'id' => $action['user']['id'],
                'name' => $action['user']['name'],
                'role' => [
                    'id' => $action['user']['role']['id'],
                    'name' => $action['user']['role']['name'],
                ],
            ];
            $response[] = $action;
        }
        return $this->respondWithData($response);
    }
}