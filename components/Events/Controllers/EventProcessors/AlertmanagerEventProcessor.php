<?php

namespace WCC\Events\Controllers\EventProcessors;

use Monolog\Logger;
use WCAA\App;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\UserStorage;
use WCC\Events\Controllers\EventProcessor;
use WCC\Events\Models\Event;
use WCC\Events\Storage\EventsStorage;

class AlertmanagerEventProcessor extends EventProcessor
{

    const RESOLVED_GRACE_PERIOD_SEC = 300;

    function process($eventName, $data) {
          $rrStart = @filemtime('/proc/' . posix_getppid());
          $uptime = $rrStart === false ? PHP_INT_MAX : time() - $rrStart;
          foreach ($data['alerts'] as $alert) {
              $device = $this->getDeviceByLabels($alert['labels']);
              $name = $alert['labels']['alertname'];
              unset($alert['labels']['alertname']);
              if (!isset($alert['labels']['severity'])) {
                  $alert['labels']['severity'] = 'critical';
              }
              if ($alert['status'] == 'firing') {
                  if($this->storage->isNotResolvedExist($name, null, null, $alert['fingerprint'])) {
                      $this->logger->warning("Duplicated alert from alertmanager ", $alert);
                      continue;
                  }
                  $this->createEvent(
                      (new Event())
                          ->setName($name)
                          ->setKey($alert['fingerprint'])
                          ->setDescription($alert['annotations']['description'])
                          ->setSeverity(strtoupper($alert['labels']['severity']))
                          ->setLabels($alert['labels'], false)
                          ->setCreator(App::getInstance()->getSysUser())
                          ->setDevice($device)
                  );
              } else {
                  if ($uptime < self::RESOLVED_GRACE_PERIOD_SEC) {
                      $this->logger->warning("Skip resolved alert during startup grace period (uptime {$uptime}s)", $alert);
                      continue;
                  }
                  $this->resolveEvent($name, null, null, $alert['fingerprint']);
              }
          }
    }


    protected function getDeviceByLabels($labels): ?Device {
        try {
            if(isset($labels['dev_id'])) {
                return  $this->devStorage->getById($labels['dev_id']);
            }
            if(isset($labels['host'])) {
                return $this->devStorage->getByIp($labels['host']);
            }
            if(isset($labels['ip'])) {
                return $this->devStorage->getByIp($labels['ip']);
            }
        } catch (\Throwable $e) {
            $this->logger->error("Error when searching device by labels", $labels);
            $this->logger->error($e->getMessage());
        }
        return null;
    }

}