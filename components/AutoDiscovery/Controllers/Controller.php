<?php


namespace WCC\AutoDiscovery\Controllers;

use DI\Annotation\Inject;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\DeviceGroup;
use WCC\AutoDiscovery\Models\AutoDiscoveryNetwork;
use WCC\AutoDiscovery\Storage\AutoDiscoveryNetworksStorage;

/**
 * Class Controller
 * @package WCC\AutoDiscovery
 */
class Controller extends AbstractComponentController
{

    /**
     * @Inject
     * @var AutoDiscoveryNetworksStorage
     */
    protected $discoveryStorage;

    /**
     * @return AutoDiscoveryNetwork[]
     */
    public function getDiscoveryNetworks()
    {
        return $this->discoveryStorage->fetchAll();
    }

    /**
     * @param AutoDiscoveryNetwork[]
     * @return AutoDiscoveryNetwork[]
     */
    public function updateDiscoveryNetworks(array $networks)
    {
        return $this->discoveryStorage->updateAll($networks);
    }
}
