<?php

namespace WCAA\Infrastructure\Poller\Pollers;

use WCAA\Infrastructure\Poller\Interfaces\PollerBgpSessionsInterface;
use WCAA\Infrastructure\Poller\PollerAbstract;
use WCAA\Infrastructure\Poller\PollerInterface;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\Pollers\BgpSession;
use WCAA\Models\Devices\Device;
use WCAA\Storage\PollerData\BgpSessionStorage;

class BgpSessionsPoller extends PollerAbstract implements PollerInterface
{

    /**
     * @var PrometheusMetrics
     */
    protected $metrics;

    /**
     * @var BgpSessionStorage
     */
    protected $storage;


    /**
     * @var EventObserverStorage
     */
    protected $events;

    function __construct(EventObserverStorage $events, BgpSessionStorage $storage, PrometheusMetrics $prometheusMetrics)
    {
        $this->storage = $storage;
        $this->events = $events;
        $this->metrics = $prometheusMetrics;
    }

    function poll(Device $device, $controller)
    {
        if (!$controller instanceof PollerBgpSessionsInterface) {
            throw new \Exception("Controller " . get_class($controller) . " not implemented PollerBgpSessionsInterface");
        }
        if ($sessions = $controller->getBgpSessions([], 'device')) {
            $this->sync($device, $sessions);
        }
    }

    function setManual(Device $device, $data = [])
    {
        if ($data) {
            $this->sync($device, $data);
        }
        $this->notifyPolledNow($device, 'bgp_sessions');
    }

    function getByDevice(Device $device) {
        $sessions = [];
        foreach ($this->storage->getByDevice($device, true) as $session) {
            $sessions[] = [
                'started_at' => $session->getStartedAt(),
                'name' => $session->getName(),
                'disabled' => $session->isDisabled(),
                'remote_id' => $session->getRemoteId(),
                'instance' => $session->getInstance(),
                'local_address' => $session->getLocalAddress(),
                'remote_address' => $session->getRemoteAddress(),
                'remote_as' => $session->getRemoteAs(),
                'state' => $session->getState(),
            ];
        }
        return $sessions;
    }

    function getByInterface(DeviceInterface $interface) {
        throw new \Exception("Not implemented");
    }
    function sync(Device $device, $sessions = [])
    {
        /**
         * @var $storedSessions BgpSession[]
         */
        $storedSessions = [];
        foreach ($this->storage->getByDevice($device) as $stored) {
            $storedSessions["{$stored->getRemoteAddress()}-{$stored->getRemoteAs()}"] = $stored;
        }

        $currentSessions = [];
        foreach ($sessions as $session) {
            $current = (new BgpSession())
                ->setUpdatedAt(date("Y-m-d H:i:s"))
                ->setStartedAt($session['started_at'])
                ->setDevice($device)
                ->setName($session['name'])
                ->setDisabled($session['disabled'])
                ->setRemoteId($session['remote_id'])
                ->setInstance(isset($session['instance']) ? $session['instance'] : '')
                ->setLocalAddress($session['local_address'] ? $session['local_address'] : '')
                ->setRemoteAddress($session['remote_address'] ? $session['remote_address'] : '')
                ->setRemoteAs(isset($session['remote_as']) ? $session['remote_as'] : -1)
                ->setState($session['state']);
            $currentSessions["{$session['remote_address']}-{$session['remote_as']}"] = $current;


            if (isset($storedSessions["{$session['remote_address']}-{$session['remote_as']}"])) {
                //Session exist
                $last = $storedSessions["{$session['remote_address']}-{$session['remote_as']}"];

                //Check state changed
                if ($last->getState() !== $current->getState()) {
                    $this->logger->alert("Session {$last->getName()} changed state from {$last->getState()} to {$current->getState()}");
                    $this->events->notify('bgp-session:state-changed', ['last' => $last, 'current' => $current]);
                    $last->setUpdatedAt(date("Y-m-d H:i:s"));
                } elseif ($current->getStartedAt() != null && $last->getStartedAt() > $current->getStartedAt() + 10) {
                    $this->logger->alert("Session {$last->getName()} blinked");
                    $this->events->notify('bgp-session:session-blink', ['last' => $last, 'current' => $current]);
                    $last->setUpdatedAt(date("Y-m-d H:i:s"));
                } elseif ($current->getName() != $last->getName()) {
                    $this->logger->alert("Session {$last->getName()} was renamed to {$current->getName()}");
                    $this->events->notify('bgp-session:session-updated', ['last' => $last, 'current' => $current]);
                    $last->setUpdatedAt(date("Y-m-d H:i:s"));
                    $this->metrics->remove('router_bgp_session_state_established', [
                        'dev_id' => $last->getId(),
                        'session_name' => $last->getName(),
                    ]);
                    $this->metrics->remove('bgp_session_up_time', [
                        'dev_id' => $last->getId(),
                        'session_name' => $last->getName(),
                    ]);
                }

                if ($current->getState()) $last->setState($current->getState());
                if ($current->getRemoteAddress()) $last->setRemoteAddress($current->getRemoteAddress());
                if ($current->getRemoteAs()) $last->setRemoteAs($current->getRemoteAs());
                if ($current->getRemoteId()) $last->setRemoteId($current->getRemoteId());
                if ($current->getLocalAddress()) $last->setLocalAddress($current->getLocalAddress());
                if ($current->getName()) $last->setName($current->getName());
                $last->setStartedAt($current->getStartedAt());
                $last->setDisabled($current->isDisabled());
                $this->storage->update($last);
            } else {
                //Session not exists - must be create
                $this->logger->alert("Session with name {$current->getName()} not found in local storage");
                $this->storage->add($current);
            }


            if ($current->getStartedAt()) {
                $this->metrics->setGauge('router_bgp_session_uptime', time() - $current->getStartedAt(), [
                    'dev_id' => $device->getId(),
                    'router_ip' => $device->getIp(),
                    'remote_address' => $current->getRemoteAddress(),
                    'remote_as' => $current->getRemoteAs(),
                    'session_name' => $current->getName(),
                ], 'Bgp sessions uptime');
            } else {
                $this->metrics->remove('router_bgp_session_uptime', [
                    'dev_id' => $device->getId(),
                    'session_name' => $current->getName(),
                ]);
            }
            $this->metrics->setGauge('router_bgp_session_state_established', $current->getState() === 'established' ? 1 : 0,
                [
                    'dev_id' => $device->getId(),
                    'router_ip' => $device->getIp(),
                    'remote_address' => $current->getRemoteAddress(),
                    'remote_as' => $current->getRemoteAs(),
                    'session_name' => $current->getName(),
                ]
                , 'Bgp sessions established state');

            $this->metrics->setGauge('router_bgp_session_state_disabled', $current->getState() === 'disabled' ? 1 : 0,
                [
                    'dev_id' => $device->getId(),
                    'router_ip' => $device->getIp(),
                    'remote_address' => $current->getRemoteAddress(),
                    'remote_as' => $current->getRemoteAs(),
                    'session_name' => $current->getName(),
                ]
                , 'Bgp sessions disabled state');

        }

        foreach ($storedSessions as $stored) {
            if (!isset($currentSessions["{$stored->getRemoteAddress()}-{$stored->getRemoteAs()}"])) {
                $this->logger->alert("Session not found on device {$device->getIp()} and must be deleted from local storage");
                $this->events->notify('bgp-session:session-deleted', $stored);
                $this->storage->delete($stored);
                $this->metrics->remove('router_bgp_session_state_established', [
                    'dev_id' => $device->getId(),
                    'session_name' => $stored->getName(),
                ]);
                $this->metrics->remove('router_bgp_session_state_disabled', [
                    'dev_id' => $device->getId(),
                    'session_name' => $stored->getName(),
                ]);
                $this->metrics->remove('router_bgp_session_uptime', [
                    'dev_id' => $device->getId(),
                    'session_name' => $stored->getName(),
                ]);
            }
        }
    }
}
