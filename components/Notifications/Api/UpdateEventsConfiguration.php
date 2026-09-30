<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\Device;
use WCC\Notifications\Models\NotificationActionConfig;
use WCC\Notifications\Models\NotificationEventConfig;

class UpdateEventsConfiguration extends AbstractNotificationAction
{
    protected $forbiddenInDemo = true;

    protected function action(): Response
    {
        $this->addActionSuccess("notifications:event-config-updated", "User {$this->user->getName()} updated event configuration");
        $events = [];
        foreach ($this->getFormData() as $act) {
            if(!isset($act['created_at'])) $act['created_at'] = date("Y-m-d H:i:s");
            if(!isset($act['check_uplink'])) {
                $act['check_uplink'] = false;
            }
            if($act['check_uplink'] && $act['delay_before_send'] < 10) {
                throw new HttpBadRequestException($this->request, "For notifications with check uplinks, delay must be 10sec minimum");
            }
            $events[] = (new NotificationEventConfig())
                ->setId(isset($act['id']) && $act['id'] ? $act['id'] : null)
                ->setCreatedAt($act['created_at'])
                ->setIgnoredDevices(array_map(function ($e) {return new Device($e['id']);}, $act['ignored_devices']))
                ->setEventName($act['event_name'])
                ->setEnabled($act['enabled'])
                ->setSendResolved($act['send_resolved'])
                ->setCheckUplink($act['check_uplink'])
                ->setDelayBeforeSend($act['delay_before_send']);
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
            },$this->controller->updateEventsConfiguration($events))
        );
    }
}