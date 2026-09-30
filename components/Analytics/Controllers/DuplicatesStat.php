<?php

namespace WCC\Analytics\Controllers;

use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\PollerData\OntIdentStorage;

class DuplicatesStat extends AbstractComponentController
{
    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $prom;

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
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var \PDO
     */
    protected $pdo;


    function calculate()
    {

    }

    function getDuplicatedMacAddresses($deviceIds = null, $macAddress = '')
    {
        $ignorePortsWithMoreThan = _env('ANALYTICS_IGNORE_IFACES_WITH_MORE_THAN', 10);
        $WHERE = '';
        if ($macAddress) {
            $WHERE .= " and mac_address = '{$this->pdo->quote($macAddress)}'";
        }

        $data = $this->pdo->prepare("SELECT
                min(start_at) first,
                max(start_at) last,
                mac_address,
                vlan_id,
                GROUP_CONCAT(DISTINCT device_id) devices,
                GROUP_CONCAT(DISTINCT interface_id) ifaces,
                count(*) count_duplicates
            FROM poll_fdb_history
            WHERE stop_at is null 
              and interface_id not in (
            SELECT interface_id FROM poll_fdb_history WHERE stop_at is null GROUP BY interface_id HAVING count(*) > ?
            ) $WHERE 
            GROUP BY  mac_address, vlan_id
            HAVING count_duplicates > 1");
        $data->execute([$ignorePortsWithMoreThan]);
        $duplicated = $data->fetchAll();
        $returningData = [];
        foreach ($duplicated as $dupl) {
            if($deviceIds !== null) {
                $displayedDevices = array_filter($deviceIds, function ($deviceId) use ($dupl) {
                    $devicesList = explode(",", $dupl['devices']);
                    return in_array($deviceId, $devicesList);
                });
                if (count($displayedDevices) == 0) {
                    continue;
                }
            }

            $ifaces = [];
            foreach (explode(",", $dupl['ifaces']) as $ifaceId) {
                $ifaces[] = $this->deviceInterfaceStorage->getById($ifaceId)->getAsArrayLite();
            }
            $dupl['ifaces'] = $ifaces;
            unset($dupl['devices']);
            $returningData[] = $dupl;
        }
        return $returningData;
    }


    function getDuplicatedOntIdents($deviceIds = null, $ontIdent = '')
    {
        $WHERE = '';
        if ($ontIdent) {
            $WHERE .= " WHERE i.ident = {$this->pdo->quote($ontIdent)}";
        }

        $data = $this->pdo->prepare("
                SELECT
            min(i.created_at) first,
            max(i.created_at) last,
            ident,
            i.type,
            GROUP_CONCAT(DISTINCT di.device_id) devices,
            GROUP_CONCAT(DISTINCT i.interface_id) ifaces,
            count(*) count_duplicates
        FROM poll_ont_ident i
        JOIN device_interfaces di on di.id = i.interface_id
        $WHERE
        GROUP BY i.ident, i.type
        HAVING count_duplicates > 1");

        $data->execute([]);
        $duplicated = $data->fetchAll();
        $returningData = [];
        foreach ($duplicated as $dupl) {
            if($deviceIds !== null) {
                $displayedDevices = array_filter($deviceIds, function ($deviceId) use ($dupl) {
                    $devicesList = explode(",", $dupl['devices']);
                    return in_array($deviceId, $devicesList);
                });
                if (count($displayedDevices) == 0) {
                    continue;
                }
            }

            $ifaces = [];
            foreach (explode(",", $dupl['ifaces']) as $ifaceId) {
                $ifaces[] = $this->deviceInterfaceStorage->getById($ifaceId)->getAsArrayLite();
            }
            $dupl['ifaces'] = $ifaces;
            unset($dupl['devices']);
            $returningData[] = $dupl;
        }
        return $returningData;
    }
}