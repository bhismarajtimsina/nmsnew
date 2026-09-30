<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\Device;
use WCC\Notifications\Models\NotificationActionConfig;

class UpdateActionsConfiguration extends AbstractNotificationAction
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $this->addActionSuccess("notifications:actions-config-updated", "User {$this->user->getName()} updated actions configuration");
        $actions = [];
        foreach ($this->getFormData() as $act) {
            if(!isset($act['created_at'])) $act['created_at'] = date("Y-m-d H:i:s");
            $actions[] = (new NotificationActionConfig())
                ->setId(isset($act['id']) && $act['id'] ? $act['id'] : null)
                ->setCreatedAt($act['created_at'])
                ->setActionName($act['action_name'])
                ->setEnabled($act['enabled'])
                ->setIgnoredDevices(array_map(function ($e) {return new Device($e['id']);}, $act['ignored_devices']))
                ->setSendOnStatusFailed($act['send_on_status_failed'])
                ->setSendOnStatusSuccess($act['send_on_status_success']);
        }

        return $this->respondWithData(
            array_map(function ($e) {
                $arr = $e->getAsArray();
                $arr['ignored_devices'] = array_map(function ($device) {
                    return [
                        'id' => $device->getId(),
                        'ip' => $device->getIp(),
                        'name' => $device->getName(),
                        'model' => [
                            'id' => $device->getModel()->getId(),
                            'name' => $device->getModel()->getName(),
                        ],
                    ];
                }, $e->getIgnoredDevices());
                return $arr;
            },$this->controller->updateActionsConfiguration($actions))
        );
    }
}