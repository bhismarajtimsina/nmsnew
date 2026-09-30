<?php

namespace WCC\Notifications\Api;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\SystemAction;
use WCAA\Models\User\User;
use WCC\Events\Models\Event;
use WCC\Notifications\Models\NotificationContact;
use WCC\Notifications\Models\NotificationFilter;
use WCC\Notifications\Storage\NotificationsStorage;

class GetHistory extends AbstractNotificationAction
{
    /**
     * @Inject
     * @var NotificationsStorage
     */
    protected $notificationStorage;


    protected function action(): Response
    {
        $searchBy = $this->request->getAttribute('by');
        $id = $this->request->getAttribute('id');

        $filter = new NotificationFilter();
        switch ($searchBy) {
            case 'user': $filter->setUser(new User((int)$id)); break;
            case 'event': $filter->setEvent(new Event($id)); break;
            case 'action': $filter->setAction(new SystemAction($id)); break;
            case 'contact': $filter->setAlertContact(new NotificationContact($id)); break;
            default:
                throw new HttpBadRequestException($this->request, "Unknown search by '{$searchBy}'");
        }
        $data = $this->notificationStorage->getByEventFilter($filter, _env('LOGS_RETURN_RESULTS_LIMIT', 500));
        return $this->respondWithData(array_map(function ($item) {
            $dt = $item->getAsArrayLite();
            $dt['additional_info'] = [
                'error' => $item->getMeta()['error']['message'] ?? null,
                'cancel_reason' => $item->getMeta()['cancel_reason'] ?? null,
            ];
            unset(
                $dt['contact']['user']['role']['permissions'],
                $dt['contact']['user']['role']['display'],
                $dt['contact']['user']['settings'],
                $dt['contact']['user']['language'],
                $dt['contact']['user']['last_activity'],
                $dt['contact']['user']['login'],
                $dt['contact']['user']['device_groups'],
                $dt['contact']['user']['created_at'],
                $dt['contact']['user']['updated_at'],
                $dt['contact']['user']['status'],
                $dt['contact']['user']['is_twofa'],
                $dt['contact']['description'],
                $dt['contact']['params'],
                $dt['action']['user']['role']['permissions'],
                $dt['action']['user']['role']['display'],
                $dt['action']['user']['settings'],
                $dt['action']['user']['device_groups'],
                $dt['action']['user']['login'],
                $dt['action']['user']['created_at'],
                $dt['action']['user']['updated_at'],
                $dt['action']['user']['status'],
                $dt['action']['user']['is_twofa'],
                $dt['action']['user']['last_activity'],
                $dt['action']['user']['language'],
                $dt['action']['device'],
                $dt['meta'],
                $dt['event']['creator']['role']['permissions'],
                $dt['event']['creator']['settings'],
                $dt['event']['creator']['device_groups'],
                $dt['event']['creator']['login'],
                $dt['event']['creator']['created_at'],
                $dt['event']['creator']['updated_at'],
                $dt['event']['creator']['status'],
                $dt['event']['creator']['is_twofa'],
                $dt['event']['creator']['last_activity'],
                $dt['event']['creator']['language'],
                $dt['event']['resolved_by']['role']['permissions'],
                $dt['event']['resolved_by']['settings'],
                $dt['event']['resolved_by']['device_groups'],
                $dt['event']['resolved_by']['login'],
                $dt['event']['resolved_by']['created_at'],
                $dt['event']['resolved_by']['updated_at'],
                $dt['event']['resolved_by']['status'],
                $dt['event']['resolved_by']['is_twofa'],
                $dt['event']['resolved_by']['last_activity'],
                $dt['event']['resolved_by']['language'],
                $dt['previous_notification'],
                $dt['action']['meta']['error'],
            );
            return $dt;
            }, $data));
    }

}