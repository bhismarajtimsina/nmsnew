<?php


namespace WCC\SwitchesControl\Api;


use DI\Annotation\Inject;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\SwitchesControl\Controllers\SwitchesController;

class UpdatePortAction extends PrivateAction
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
        $interface = $this->request->getAttribute('interface');
        try {
            $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
            $this->controller->setDevice($dev)->setUser($this->user);

            $oldData = $this->controller->getInterfaceFullInfo('device', $interface, ['interfaces_list', 'link_info', 'interface_descriptions', 'vlans_by_port']);
            if (!isset($oldData[0])) {
                throw new SwitcherCoreException("Device not returned any information about port");
            } else {
                $oldData = $oldData[0];
            }
            $data = $this->getFormData();
            $mustBeSave = false;
            if ($data['description'] !== null && $data['description'] !== $oldData['description']['description']) {
                if ($this->user->isRulePermitted('switches_set_port_description')) {
                    $this->controller->setDescription($interface, trim($data['description']));
                    $this->systemActionsStorage->add(SystemAction::init(
                        $this->user,
                        'switches:set_port_description',
                        SystemAction::STATUS_SUCCESS,
                        "Update description on port $interface from '{$oldData['description']['description']}' to '{$data['description']}' on device {$dev->getName()} ({$dev->getIp()})",
                        ['device' => $dev->getAsArray(), 'interface' => $interface, 'old' => $oldData['description']['description'], 'new' => $data['description']]
                    ));
                    $mustBeSave = true;
                } else {
                    $this->systemActionsStorage->add(SystemAction::init(
                        $this->user,
                        'switches:set_port_description',
                        SystemAction::STATUS_FAILED,
                        "Not permitted operation for current user, device {$dev->getName()} ({$dev->getIp()})",
                        ['device' => $dev->getAsArray(), 'interface' => $interface, 'old' => $oldData['description']['description'], 'new' => $data['description']]
                    ));
                }
            }
            if ($oldData['link_info'][0]['admin_state'] !== 'Disabled') {
                $state = 'enable';
            } else {
                $state = 'disable';
            }

            if ($data['admin_state'] !== null && $data['admin_state'] !== $state) {
                if ($this->user->isRulePermitted('switches_set_port_admin_state')) {
                    $this->controller->setPortState($interface, trim($data['admin_state']));
                    $this->systemActionsStorage->add(SystemAction::init(
                        $this->user,
                        'switches:set_port_state',
                        SystemAction::STATUS_SUCCESS,
                        "Update admin state on port $interface from '$state' to '{$data['admin_state']}' on device {$dev->getName()} ({$dev->getIp()})",
                        ['device' => $dev->getAsArray(), 'interface' => $interface, 'old' => $state, 'new' => $data['admin_state']]
                    ));
                    $mustBeSave = true;
                } else {
                    $this->systemActionsStorage->add(SystemAction::init(
                        $this->user,
                        'switches:set_port_state',
                        SystemAction::STATUS_FAILED,
                        "Not permitted operation for current user, device {$dev->getName()} ({$dev->getIp()})",
                        ['device' => $dev->getAsArray(), 'interface' => $interface, 'old' => $state, 'new' => $data['admin_state']]
                    ));
                }
            }

            if ($data['admin_speed'] !== null && $data['admin_speed'] !== $oldData['link_info'][0]['admin_state'] && $oldData['link_info'][0]['admin_state'] !== 'Disabled') {
                if ($this->user->isRulePermitted('switches_set_port_admin_speed')) {
                    if ($oldData['link_info'][0]['type'] === 'FE' && $data['admin_speed'] === '1000-Full') {
                        throw new \Exception("Incorrect speed value for fast ethernet interface");
                    }
                    $this->controller->setPortSpeed($interface, trim($data['admin_speed']));
                    $this->systemActionsStorage->add(SystemAction::init(
                        $this->user,
                        'switches:set_port_nway',
                        SystemAction::STATUS_SUCCESS,
                        "Update admin speed on port $interface from '{$oldData['link_info'][0]['admin_state']}' to '{$data['admin_speed']}' on device {$dev->getName()} ({$dev->getIp()})",
                        ['device' => $dev->getAsArray(), 'interface' => $interface, 'old' => $oldData['link_info'][0]['admin_state'], 'new' => $data['admin_speed']]
                    ));
                    $mustBeSave = true;
                } else {
                    $this->systemActionsStorage->add(SystemAction::init(
                        $this->user,
                        'switches:set_port_nway',
                        SystemAction::STATUS_FAILED,
                        "Not permitted operation for current user, device {$dev->getName()} ({$dev->getIp()})",
                        ['device' => $dev->getAsArray(), 'interface' => $interface, 'old' => $oldData['link_info'][0]['admin_state'], 'new' => $data['admin_speed']]
                    ));
                }
            }
            if ($mustBeSave) {
                $this->controller->saveConfig();
            }
            $response = $this->controller->getInterfaceFullInfo('device', $interface);
            if (!isset($response[0])) {
                throw new SwitcherCoreException("Device not returned any information about port");
            }
            return $this->respondWithData($response);
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'switches:updated_port',
                SystemAction::STATUS_FAILED,
                "Requested cable diagnostic on device {$dev->getName()} ({$dev->getIp()}) on port $interface",
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

    function getFormData($associative = true)
    {
        $data = parent::getFormData($associative); // TODO: Change the autogenerated stub
        if (!isset($data['description'])) $data['description'] = null;
        if (!isset($data['admin_state'])) $data['admin_state'] = null;
        if (!isset($data['admin_speed'])) $data['admin_speed'] = null;
        return $data;
    }

}