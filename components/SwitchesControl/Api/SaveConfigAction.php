<?php


namespace WCC\SwitchesControl\Api;


use DI\Annotation\Inject;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\SwitchesControl\Controllers\SwitchesController;

class SaveConfigAction extends PrivateAction
{
    protected $forbiddenInDemo = true;


    /**
     * @Inject
     * @var SwitchesController
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;


    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    /**
     * @return Response
     */

    protected function action(): Response
    { 
        $dev = new Device($this->request->getAttribute('device'));
        try {
            $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
            $this->controller->setDevice($dev)->setUser($this->user);
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'switches:save_config',
                SystemAction::STATUS_SUCCESS,
                "Request save_config on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev->getAsArray()]
            ));

            return $this->respondWithData($this->controller->saveConfig(), $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'switches:save_config',
                SystemAction::STATUS_FAILED,
                "Request save_config on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev->getAsArray(), 'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'trace' => $e->getTraceAsString(),
                ]]
            ));
            throw $e;
        }
    }

}