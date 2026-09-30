<?php


namespace WCAA\Api\Actions\Dashboard;


use DI\Annotation\Inject;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\ZteC320Interfaces\Controllers\ControllerGateway;
use WCC\ZteOntsRegistration\Controllers\ZteOntsRegistrationGateway;

class LatestSystemActions extends PrivateAction
{

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var ComponentInjector
     */
    protected $moduleInjector;


    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    protected function action(): Response
    {
        return $this->respondWithData($this->getLatestSystemActions());
    }

    protected function getLatestSystemActions() {
        if(!in_array('system_logs_actions', $this->user->getRole()->getPermissions())) {
            return null;
        }
        $stop = date("Y-m-d H:i:s");
        $start = (new \DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');
        $actions = $this->systemActionsStorage->getActionsByFilter($start, $stop, [], [], '', 100);
        $list = [];
        foreach ($actions as $act) {
            $action = $act->getAsArray();
            unset($action['user']['group']);
            unset($action['device']);
            unset($action['user']['settings']);
            unset($action['user']['last_activity']);
            unset($action['user']['created_at']);
            unset($action['user']['updated_at']);
            unset($action['user']['device_groups']);
            unset($action['user']['role']);
            unset($action['meta']);
            $list[] = $action;
        }
        return  $list;
    }
}