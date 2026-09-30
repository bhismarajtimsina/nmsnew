<?php

namespace WCAA\Api\Actions\Poller;

use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\CacheControl;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\PollerData\OntIdentStorage;

class ClearHistoryAction extends PrivateAction
{

    protected $forbiddenInDemo = true;
    /**
     * @Inject
     * @var FdbHistoryStorage
     */
    protected $fdbHistoryStorage;
    /**
     * @Inject
     * @var OntIdentStorage
     */
    protected $ontIdentStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaces;

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $lastProm;

    /**
     * @Inject
     * @var CacheControl
     */
    protected $cache;

    protected function action(): Response
    {
        $device = $this->deviceStorage->getById($this->request->getAttribute('device-id'));
        $this->lastProm->removeByLabel(['dev_id' => $device->getId()]);
        $this->lastProm->removeByLabel(['ip' => $device->getIp()]);
        $this->ontIdentStorage->deleteByDevice($device);
        $this->fdbHistoryStorage->deleteByDevice($device);
        $this->deviceInterfaces->deleteByDevice($device);
        $this->cache->flushMemcache();
        $this->cache->flushCompiledObjects();
        return  $this->respondWithData(true);
    }

}