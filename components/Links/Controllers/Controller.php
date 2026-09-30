<?php


namespace WCC\Links\Controllers;


use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Links\Exceptions\RootDeviceNotFound;
use WCC\Links\Models\Link;
use WCC\Links\Storage\LinkStorage;
use WCC\PrometheusWrapper\Controllers\Controller as PrometheusController;

/**
 * Class Controller
 * @package WCC\Links
 */
class Controller extends AbstractComponentController
{

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;


    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;


    /**
     * @Inject
     * @var LinkStorage
     */
    protected $linkStorage;


    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $promStorage;


    /**
     * @Inject
     * @var PrometheusController
     */
    protected $promMetrics;
    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @Inject
     * @var \WCAA\SwitcherCore\SwitcherCore
     */
    protected $switcherCore;

    /**
     * @Inject
     * @var \PDO
     */
    protected $pdo;


    /**
     * @return LinkStorage
     */
    public function getLinkStorage(): LinkStorage
    {
        return $this->linkStorage;
    }

    /**
     * @param LinkStorage $linkStorage
     * @return Controller
     */
    public function setLinkStorage(LinkStorage $linkStorage): Controller
    {
        $this->linkStorage = $linkStorage;
        return $this;
    }


    /**
     * @return DeviceStorage
     */
    public function getDeviceStorage(): DeviceStorage
    {
        return $this->deviceStorage;
    }

    /**
     * @param DeviceStorage $deviceStorage
     * @return Controller
     */
    public function setDeviceStorage(DeviceStorage $deviceStorage): Controller
    {
        $this->deviceStorage = $deviceStorage;
        return $this;
    }

    /**
     * @return DeviceInterfaceStorage
     */
    public function getDeviceInterfaceStorage(): DeviceInterfaceStorage
    {
        return $this->deviceInterfaceStorage;
    }

    /**
     * @param DeviceInterfaceStorage $deviceInterfaceStorage
     * @return Controller
     */
    public function setDeviceInterfaceStorage(DeviceInterfaceStorage $deviceInterfaceStorage): Controller
    {
        $this->deviceInterfaceStorage = $deviceInterfaceStorage;
        return $this;
    }

    /**
     * @param Link $link
     * @return Link|Link
     */
    function addLink(Link $link)
    {
        return $this->linkStorage->add($link);
    }

    /**
     * @param Link $link
     * @return Link|Link
     */
    function updateLink(Link $link)
    {
        return $this->linkStorage->update($link);
    }

    function deleteLink($linkId)
    {
        return $this->linkStorage->delete(new Link($linkId));
    }

    /**
     * @return Link[]
     */
    function getAll()
    {
        return $this->linkStorage->fetchAll();
    }

    /**
     * @param Device $device
     * @param $search_by
     * @return Link[]|void
     */
    function getByDevice(Device $device, $search_by = 'all')
    {
        if ($search_by == 'all') {
            return array_merge($this->linkStorage->getByDestDevice($device), $this->linkStorage->getBySourceDevice($device));
        } elseif ($search_by == 'src') {
            return $this->linkStorage->getBySourceDevice($device);
        } elseif ($search_by == 'dest') {
            return $this->linkStorage->getByDestDevice($device);
        }
        return null;
    }

    /**
     * @param DeviceInterface $interface
     * @param $search_by
     * @return Link[]|void
     */
    function getByInterface(DeviceInterface $interface, $search_by = 'all')
    {
        $device = $interface->getDevice();
        $data = [];
        if ($search_by == 'all') {
            $data = array_merge($this->linkStorage->getByDestDevice($device), $this->linkStorage->getBySourceDevice($device));
        } elseif ($search_by == 'src') {
            $data = $this->linkStorage->getBySourceDevice($device);
        } elseif ($search_by == 'dest') {
            $data = $this->linkStorage->getByDestDevice($device);
        }
        return array_values(array_filter($data, function (Link $link) use ($interface) {
            return
                ($link->getSrcIface() && $link->getSrcIface()->getId() == $interface->getId()) ||
                ($link->getDestIface() && $link->getDestIface()->getId() == $interface->getId());
        }));
    }

    function getAllLinksData($period = "15m")
    {
        $fullLinks = [];
        if ($links = $this->cache->get("FULL_LINKS_LIST_{$period}")) {
            return $links;
        }
        //Utilization block
        $ifacesStat = array_map(function ($e) {
            return [
                'speed_mbits' => $e,
                'in_mbps' => null,
                'out_mbps' => null,
                'in_util_prc' => null,
                'out_util_prc' => null,
            ];
        }, $this->getLinksSpeed());
        foreach ($this->getUtilization($period) as $key => $utilization) {
            if (!isset($ifacesStat[$key])) {
                continue;
            }
            $ifacesStat[$key] = array_merge($ifacesStat[$key], $utilization);
        }
        foreach ($this->linkStorage->fetchAll() as $link) {
            $lnk = $link->getAsArrayLite();
            $lnk['utilization'] = null;
            $lnk['speed'] = null;
            $lnk['utilization_mbps'] = null;

            if ($link->getSrcIface()) {
                $util = isset($ifacesStat["{$link->getSrcIface()->getDeviceId()}:{$link->getSrcIface()->getBindKey()}"]) ? $ifacesStat["{$link->getSrcIface()->getDeviceId()}:{$link->getSrcIface()->getBindKey()}"] : null;
                $lnk['src_iface'] = [
                    'id' => $link->getSrcIface()->getId(),
                    'name' => $link->getSrcIface()->getName(),
                    'description' => $link->getSrcIface()->getDescription(),
                    'type' => $link->getSrcIface()->getType(),
                    'bind_key' => $link->getSrcIface()->getBindKey(),
                    'status' => $link->getSrcIface()->getStatus(),
                    'last_status_changed' => $link->getSrcIface()->getStatusChanged(),
                    'utilization' => $util,
                ];
                if (!isset($lnk['utilization'])) {
                    if (isset($util['in_util_prc']) && $util['in_util_prc'] > $lnk['utilization']) {
                        $lnk['utilization'] = round($util['in_util_prc'], 1);
                        $lnk['utilization_mbps'] = round($util['in_mbps'], 3);
                        $lnk['speed'] = $util['speed_mbits'];
                    }
                    if (isset($util['out_util_prc']) && $util['out_util_prc'] > $lnk['utilization']) {
                        $lnk['utilization'] = round($util['out_util_prc'], 1);
                        $lnk['speed'] = $util['speed_mbits'];
                        $lnk['utilization_mbps'] = round($util['out_mbps'], 3);
                    }
                }
            }
            if ($link->getDestIface()) {
                $util = isset($ifacesStat["{$link->getDestIface()->getDeviceId()}:{$link->getDestIface()->getBindKey()}"]) ? $ifacesStat["{$link->getDestIface()->getDeviceId()}:{$link->getDestIface()->getBindKey()}"] : null;
                $lnk['dest_iface'] = [
                    'id' => $link->getDestIface()->getId(),
                    'name' => $link->getDestIface()->getName(),
                    'description' => $link->getDestIface()->getDescription(),
                    'type' => $link->getDestIface()->getType(),
                    'bind_key' => $link->getDestIface()->getBindKey(),
                    'status' => $link->getDestIface()->getStatus(),
                    'last_status_changed' => $link->getDestIface()->getStatusChanged(),
                    'utilization' => $util,
                ];
                if (!isset($lnk['utilization'])) {
                    if (isset($util['in_util_prc']) && $util['in_util_prc'] > $lnk['utilization']) {
                        $lnk['utilization'] = round($util['in_util_prc'], 1);
                        $lnk['speed'] = $util['speed_mbits'];
                        $lnk['utilization_mbps'] = round($util['in_mbps'], 3);
                    }
                    if (isset($util['out_util_prc']) && $util['out_util_prc'] > $lnk['utilization']) {
                        $lnk['utilization'] = round($util['out_util_prc'], 1);
                        $lnk['speed'] = $util['speed_mbits'];
                        $lnk['utilization_mbps'] = round($util['out_mbps'], 3);
                    }
                }

            }
            $lnk['design'] = [
                'size' => 1 + ceil($lnk['speed'] / 1024) > 42 ? 42 : 1 + ceil($lnk['speed'] / 1024),
                'color' => $lnk['utilization'] ? $this->getColorScale($lnk['utilization']) : 'gray',
            ];
            if ($lnk['speed'] > 1000) {
                $lnk['speed_humanize'] = ($lnk['speed'] / 1024) . "G";
            } else if ($lnk['speed']) {
                $lnk['speed_humanize'] = ($lnk['speed']) . "M";
            } else {
                $lnk['speed_humanize'] = null;
            }
            $fullLinks[] = $lnk;
        }
        $this->cache->set("FULL_LINKS_LIST_{$period}", $fullLinks, 60);
        return $fullLinks;
    }

    function getAllDataByLink(Link $link, $period = "15m")
    {
        $lnk = $link->getAsArrayLite();
        $lnk['utilization'] = null;
        $lnk['speed'] = null;
        $lnk['utilization_mbps'] = null;

        if ($link->getSrcIface()) {
            $utilizations  = array_values($this->getUtilization($period, 0, $link->getSrcIface()));
            if(count($utilizations) >= 1) {
                $util = $utilizations[0];
            } else {
                $util = null;
            }

            $speed  = array_values($this->getLinksSpeed($link->getSrcIface()));
            if(count($speed) >= 1 && $util) {
                $util['speed_mbits'] = $speed[0];
            }

            $lnk['src_iface'] = [
                'id' => $link->getSrcIface()->getId(),
                'name' => $link->getSrcIface()->getName(),
                'description' => $link->getSrcIface()->getDescription(),
                'type' => $link->getSrcIface()->getType(),
                'bind_key' => $link->getSrcIface()->getBindKey(),
                'status' => $link->getSrcIface()->getStatus(),
                'last_status_changed' => $link->getSrcIface()->getStatusChanged(),
                'utilization' => $util,
            ];
            if (!isset($lnk['utilization'])) {
                if (isset($util['in_util_prc']) && $util['in_util_prc'] > $lnk['utilization']) {
                    $lnk['utilization'] = round($util['in_util_prc'], 1);
                    $lnk['utilization_mbps'] = round($util['in_mbps'], 3);
                    $lnk['speed'] = isset($util['speed_mbits']) ?? $util['speed_mbits'];
                }
                if (isset($util['out_util_prc']) && $util['out_util_prc'] > $lnk['utilization']) {
                    $lnk['utilization'] = round($util['out_util_prc'], 1);
                    $lnk['speed'] = isset($util['speed_mbits']) ?? $util['speed_mbits'];
                    $lnk['utilization_mbps'] = round($util['out_mbps'], 3);
                }
            }
        }
        if ($link->getDestIface()) {
            $utilizations  = array_values($this->getUtilization($period, 0, $link->getDestIface()));
            if(count($utilizations) >= 1) {
                $util = $utilizations[0];
            } else {
                $util = null;
            }
            $speed  = array_values($this->getLinksSpeed($link->getDestIface()));
            if(count($speed) >= 1 && $util) {
                $util['speed_mbits'] = $speed[0];
            }

            $lnk['dest_iface'] = [
                'id' => $link->getDestIface()->getId(),
                'name' => $link->getDestIface()->getName(),
                'description' => $link->getDestIface()->getDescription(),
                'type' => $link->getDestIface()->getType(),
                'bind_key' => $link->getDestIface()->getBindKey(),
                'status' => $link->getDestIface()->getStatus(),
                'last_status_changed' => $link->getDestIface()->getStatusChanged(),
                'utilization' => $util,
            ];
            if (!isset($lnk['utilization'])) {
                if (isset($util['in_util_prc']) && $util['in_util_prc'] > $lnk['utilization']) {
                    $lnk['utilization'] = round($util['in_util_prc'], 1);
                    $lnk['speed'] = $util['speed_mbits'];
                    $lnk['utilization_mbps'] = round($util['in_mbps'], 3);
                }
                if (isset($util['out_util_prc']) && $util['out_util_prc'] > $lnk['utilization']) {
                    $lnk['utilization'] = round($util['out_util_prc'], 1);
                    $lnk['speed'] = $util['speed_mbits'];
                    $lnk['utilization_mbps'] = round($util['out_mbps'], 3);
                }
            }

        }
        $lnk['design'] = [
            'size' => 1 + ceil($lnk['speed'] / 1024) > 42 ? 42 : 1 + ceil($lnk['speed'] / 1024),
            'color' => $lnk['utilization'] ? $this->getColorScale($lnk['utilization']) : 'gray',
        ];
        if ($lnk['speed'] > 1000) {
            $lnk['speed_humanize'] = ($lnk['speed'] / 1024) . "G";
        } else if ($lnk['speed']) {
            $lnk['speed_humanize'] = ($lnk['speed']) . "M";
        } else {
            $lnk['speed_humanize'] = null;
        }
        return $lnk;
    }

    /**
     * @param DeviceInterface|null $deviceInterface
     * @return array
     */
    protected function getLinksSpeed(?DeviceInterface $deviceInterface = null)
    {
        $linksSpeed = [];
        if (!$deviceInterface) {
            foreach ($this->promStorage->getLastValues("device_interface_speed") as $metric) {
                $linksSpeed[$metric['labels']['dev_id'] . ":" . $metric['labels']['iface_id']] = $metric['value'];
            }
        } else {
            foreach ($this->promStorage->getLastValues("device_interface_speed", [
                'dev_id' => $deviceInterface->getDevice()->getId(),
                'iface_id' => $deviceInterface->getBindKey(),
            ]) as $metric) {
                $linksSpeed[$metric['labels']['dev_id'] . ":" . $metric['labels']['iface_id']] = $metric['value'];
            }
        }
        return $linksSpeed;
    }

    protected function getUtilization($period = "10m", $utilization = 0, ?DeviceInterface $deviceInterface = null)
    {
        $linksSpeed = [];

        if (!$deviceInterface) {
            $queries = $this->promMetrics->queries([
                ['labels' => ['name' => 'in_mbps'], 'query' => "sum(rate(iface_stat_in_octets[$period])) by (dev_id, iface_id) / 1024 / 1024 * 8 != 0",],
                ['labels' => ['name' => 'out_mbps'], 'query' => "sum(rate(iface_stat_out_octets[$period])) by (dev_id, iface_id) / 1024 / 1024 * 8 != 0",],
                ['labels' => ['name' => 'in_util_prc'], 'query' => "(sum(rate(iface_stat_in_octets[$period])) by (dev_id, iface_id) / 1024 / 1024 * 8) / sum(device_interface_speed) by (dev_id, iface_id) * 100 > $utilization",],
                ['labels' => ['name' => 'out_util_prc'], 'query' => "(sum(rate(iface_stat_out_octets[$period])) by (dev_id, iface_id) / 1024 / 1024 * 8) / sum(device_interface_speed) by (dev_id, iface_id) * 100 > $utilization",],
            ]);
        } else {
            $queries = $this->promMetrics->queries([
                ['labels' => ['name' => 'in_mbps'], 'query' => "sum(rate(iface_stat_in_octets{dev_id=\"{$deviceInterface->getDevice()->getId()}\", iface_id=\"{$deviceInterface->getBindKey()}\"}[$period])) by (dev_id, iface_id) / 1024 / 1024 * 8 != 0",],
                ['labels' => ['name' => 'out_mbps'], 'query' => "sum(rate(iface_stat_out_octets{dev_id=\"{$deviceInterface->getDevice()->getId()}\", iface_id=\"{$deviceInterface->getBindKey()}\"}[$period])) by (dev_id, iface_id) / 1024 / 1024 * 8 != 0",],
                ['labels' => ['name' => 'in_util_prc'], 'query' => "(sum(rate(iface_stat_in_octets{dev_id=\"{$deviceInterface->getDevice()->getId()}\", iface_id=\"{$deviceInterface->getBindKey()}\"}[$period])) by (dev_id, iface_id) / 1024 / 1024 * 8) / sum(device_interface_speed) by (dev_id, iface_id) * 100 > $utilization",],
                ['labels' => ['name' => 'out_util_prc'], 'query' => "(sum(rate(iface_stat_out_octets{dev_id=\"{$deviceInterface->getDevice()->getId()}\", iface_id=\"{$deviceInterface->getBindKey()}\"}[$period])) by (dev_id, iface_id) / 1024 / 1024 * 8) / sum(device_interface_speed) by (dev_id, iface_id) * 100 > $utilization",],
            ]);
        }

        foreach ($queries as $queriesResponse) {
            foreach ($queriesResponse as $metric) {
                $linksSpeed[$metric['metric']['dev_id'] . ":" . $metric['metric']['iface_id']][$metric['request']['name']] = round((float)$metric['value'][1], 3);
            }
        }
        return $linksSpeed;
    }

    protected function getColorScale($percentage)
    {
        if ($percentage < 0 || $percentage > 100) {
            return "gray";
        }

        // Convert percentage to a value between 0 and 255
        $green = (int)max(0, 255 - ($percentage * 2.55));
        $red = (int)min(255, $percentage * 2.55);

        if ($green > 200) {
            $green = 200;
        }

        // Return the color as a HEX value
        return sprintf("#%02x%02x%02x", $red, $green, 0);
    }


    /**
     * @param Device $device
     * @param $direction
     * @return array
     */
    function getDirectionTree(Device $device, $direction)
    {
        $data = [
          'searched_device' => $device->getAsArrayLite(),
          'tree' => null,
          'direction' => $direction,
          'build_from' => null,
        ];
        if($direction == 'up') {
            $coreDevice = $this->linkStorage->getCoreByDevice($device);
            if(!$coreDevice) {
                throw new RootDeviceNotFound("Not found core device by child device with IP={$device->getIp()}", 400);
            }
            $device = $coreDevice;
            $data['build_from'] = $device->getAsArrayLite();
            $data['tree'] = $this->linkStorage->buildDownArray($device->getId());
        } else {
            $data['build_from'] = $device->getAsArrayLite();
            $data['tree'] = $this->linkStorage->buildDownArray($device->getId());
        }
        return $data;
    }

    /**
     * LLDP neighbors that report a chassis MAC not matching ANY device we
     * manage — a shared uplink into another company's switch is the real
     * case this exists for: real hardware, really connected, but nothing
     * we have an IP/credentials for. Every other topology view (this
     * endpoint's own normal links, the Map page, the Links list) only ever
     * draws links between two Device rows, so a neighbor like this is
     * currently just silently invisible everywhere. Synthesized here as
     * read-only pseudo-nodes/pseudo-links — never written to the database,
     * never a real Device, never pollable — using only what LLDP plus our
     * OWN local interface can tell us, since that's genuinely the only
     * side of the connection we ever have visibility into. Utilization
     * comes from the exact same Prometheus query real links use, scoped to
     * this one local interface — a real, meaningful number, not a guess.
     * Strictly cache/store-only (fromStore with $catchErrors=true), same
     * as every other automated LLDP read in this app; never queries a
     * device live.
     *
     * @param array $devices id => device-array (already built/filtered by the caller)
     * @param string $period
     * @return array{devices: array, links: array}
     */
    function getExternalNeighbors(array $devices, string $period = "15m"): array
    {
        $extDevices = [];
        $extLinks = [];

        $knownMacs = [];
        foreach ($this->deviceStorage->fetchAll() as $d) {
            $norm = $this->normalizeMac($d->getMac());
            if ($norm) $knownMacs[$norm] = true;
        }

        $customNames = [];
        $stmt = $this->pdo->query("SELECT id, name FROM c_links_external_names");
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $customNames[$row['id']] = $row['name'];
        }

        foreach (array_keys($devices) as $devId) {
            $device = $this->deviceStorage->getById($devId);
            if (!$device) continue;

            try {
                $core = $this->switcherCore->getCore($device);
                if (!$core->isModuleExist('lldp_info')) continue;
            } catch (\Throwable $e) {
                continue;
            }

            try {
                $resp = $this->switcherCore->fromStore(
                    [\WCAA\SwitcherCore\Request::init($device, 'lldp_info')],
                    true,
                    true
                )->getFirstByModule('lldp_info');
            } catch (\Throwable $e) {
                continue;
            }
            if (!$resp || $resp->getError()) continue;
            $lldp = $resp->getData();

            foreach (($lldp['remotes'] ?? []) as $remote) {
                $mac = $this->normalizeMac($remote['rem_chassis_id'] ?? null);
                if (!$mac || isset($knownMacs[$mac])) continue; // a real Device already covers this one
                $locIface = $remote['loc_interface'] ?? null;
                if (!$locIface || !isset($locIface['id'])) continue;
                try {
                    $localIface = $this->deviceInterfaceStorage->getByDeviceAndKey($device, $locIface['id']);
                } catch (\Throwable $e) {
                    continue;
                }

                $extId = 'ext:' . $devId . ':' . $mac;
                if (!isset($extDevices[$extId])) {
                    $autoName = 'External device (' . ($remote['rem_chassis_id'] ?? $mac) . ')';
                    $extDevices[$extId] = [
                        'id' => $extId,
                        'name' => $customNames[$extId] ?? $autoName,
                        'auto_name' => $autoName,
                        'chassis_id' => $remote['rem_chassis_id'] ?? null,
                        'named' => isset($customNames[$extId]),
                        'ip' => null,
                        'model' => ['id' => null, 'name' => 'Unmanaged / external device', 'type' => 'EXTERNAL'],
                        'group' => null,
                        'pinger' => ['status' => null, 'latency' => null, 'last_change' => null],
                        'design' => ['size' => 8, 'color' => 'gray'],
                        'level' => -1,
                        'external' => true,
                    ];
                }

                $util = array_values($this->getUtilization($period, 0, $localIface));
                $speed = array_values($this->getLinksSpeed($localIface));
                $u = $util[0] ?? null;
                $s = $speed[0] ?? null;
                $utilPrc = null;
                $utilMbps = null;
                if ($u) {
                    if (isset($u['in_util_prc']) && (!$utilPrc || $u['in_util_prc'] > $utilPrc)) {
                        $utilPrc = round($u['in_util_prc'], 1);
                        $utilMbps = round($u['in_mbps'] ?? 0, 3);
                    }
                    if (isset($u['out_util_prc']) && (!$utilPrc || $u['out_util_prc'] > $utilPrc)) {
                        $utilPrc = round($u['out_util_prc'], 1);
                        $utilMbps = round($u['out_mbps'] ?? 0, 3);
                    }
                }

                $extLinks[] = [
                    'id' => 'ext-link:' . $devId . ':' . $mac,
                    'src_device' => [
                        'id' => $device->getId(),
                        'name' => $device->getName(),
                        'ip' => $device->getIp(),
                    ],
                    'dest_device' => [
                        'id' => $extId,
                        'name' => $extDevices[$extId]['name'],
                        'ip' => null,
                    ],
                    'src_iface' => [
                        'name' => $localIface->getName(),
                        'status' => $localIface->getStatus(),
                        'utilization' => $u,
                    ],
                    'dest_iface' => $remote['rem_interface'] ? ['name' => $remote['rem_interface'], 'status' => null, 'utilization' => null] : null,
                    'source' => 'lldp',
                    'external' => true,
                    'utilization' => $utilPrc,
                    'utilization_mbps' => $utilMbps,
                    'speed' => $s,
                    'speed_humanize' => $s > 1000 ? (($s / 1024) . 'G') : ($s ? ($s . 'M') : null),
                    'design' => ['size' => 1, 'color' => $utilPrc ? $this->getColorScale($utilPrc) : 'gray'],
                ];
            }
        }

        return ['devices' => array_values($extDevices), 'links' => $extLinks];
    }

    /**
     * Normalizes a MAC-like string (strips separators, uppercases) so
     * LLDP's own formatting (colons/dashes/case can vary by vendor module)
     * can be compared against however this system stores `devices.mac`.
     * Same logic already used by AutoTopology's discovery and
     * LldpNeighborsAction.
     */
    private function normalizeMac(?string $mac): ?string
    {
        if (!$mac) return null;
        $clean = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
        return strlen($clean) === 12 ? $clean : null;
    }

    /**
     * Sets (or clears, with an empty name) the custom display name for an
     * external-neighbor pseudo-device. `$id` is only ever accepted in the
     * exact "ext:<device_id>:<mac>" shape getExternalNeighbors() itself
     * generates — never free-form — since this table has no foreign key
     * to anchor it against (the id isn't a real device).
     */
    function setExternalNeighborName(string $id, string $name): void
    {
        if (!preg_match('/^ext:\d+:[0-9A-F]{12}$/', $id)) {
            throw new \InvalidArgumentException("Not a valid external-neighbor id");
        }
        $name = trim($name);
        if ($name === '') {
            $stmt = $this->pdo->prepare("DELETE FROM c_links_external_names WHERE id = ?");
            $stmt->execute([$id]);
            return;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO c_links_external_names (id, name) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name)"
        );
        $stmt->execute([$id, $name]);
    }
}
