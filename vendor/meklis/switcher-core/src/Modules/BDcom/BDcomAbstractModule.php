<?php

namespace SwitcherCore\Modules\BDcom;

use DI\DependencyException;
use DI\NotFoundException;
use SwitcherCore\Config\Objects\Model;
use SwitcherCore\Config\Objects\Oid;
use SwitcherCore\Modules\AbstractModule;
use SwitcherCore\Modules\Helper;
use SwitcherCore\Switcher\Console\ConsoleInterface;

// EPON0/2 = 10200000 - PON port
// EPON0/2:3 = 10200300 - Port PORT with ONU
// EPON0/1:63 = 10106300 - Without UNI
// EPON0/1:63.1 = 10106301 - With UNI

abstract class BDcomAbstractModule extends AbstractModule
{
    /**
     * @Inject
     * @var \SwitcherCore\Switcher\Console\ConsoleInterface
     */
    protected $console;

    private $physicalInterfaces = [];
    private $interfacesIds = [];

    function getPretty()
    {
        return [];
    }

    function __construct(Model $model)
    {
    }

    function getPrettyFiltered($filter = [], $fromCache = false)
    {
        if ($fromCache && $ret = $this->getCache(json_encode($filter))) {
            return $ret;
        }
        $resp = $this->getPretty();
        $this->setCache(json_encode($filter), $resp, 15);
        return $resp;
    }

    protected function parseInterface($input, $parseBy = 'id')
    {
        $ifaces = $this->getInterfacesIds();
        if (is_numeric($input) && $parseBy == 'xid') {
            $filtered = array_values(array_filter($ifaces, function ($iface) use ($input) {
                return $input == $iface['xid'];
            }));
            if (count($filtered) > 0) {
                return $filtered[0];
            }
        }

        if (is_numeric($input) && isset($ifaces[$input])) {
            return $ifaces[$input];
        }
        if (is_numeric($input)) {
            $filtered = array_values(array_filter($ifaces, function ($iface) use ($input) {
                return $input == $iface['xid'];
            }));
            if (count($filtered) > 0) {
                return $filtered[0];
            }
        }
        if (is_string($input)) {
            $filtered = array_values(array_filter($ifaces, function ($iface) use ($input) {
                return $input == strtolower($iface['name']);
            }));
            if (count($filtered) > 0) {
                return $filtered[0];
            }
            $input_as_arr = str_split($input);
            foreach ($ifaces as $iface) {
                preg_match("/port-aggregator{$input_as_arr[count($input_as_arr)-1]}/", $iface['name'], $match);
                if (count($match) > 0) {
                    return $iface;
                }
            }
        }

        if (is_string($input) && strpos($input, ".") !== false) {
            $filtered = array_values(array_filter($ifaces, function ($iface) use ($input) {
                return $input == $iface['_llid_id'];
            }));
            if (count($filtered) > 0) {
                return $filtered[0];
            }
        }
        throw new \InvalidArgumentException("Error find interface by ident='{$input}'");
    }

    function getInterfacesIds($useCache = true)
    {
        if (!$this->interfacesIds) {
            $this->loadInterfaces($useCache);
        }
        return $this->interfacesIds;
    }

    protected function getPhysicalInterfaces($useCache = true)
    {
        if (!$this->physicalInterfaces) {
            $this->loadInterfaces($useCache);
        }
        return $this->physicalInterfaces;
    }

    private function loadInterfaces($useCache = true)
    {
        $data = $this->getCache("interfaces_list", true);
        if ($data && $useCache) {
            $this->interfacesIds = $data['ifaces_list'];
            $this->physicalInterfaces = $data['ifaces_physical'];
            return $this;
        }
        // `if.Descr` is required for every BDCOM model. `ont.llidSeqNumber`
        // (used elsewhere to number PON/ONT sub-interfaces) is only ever
        // present on the PON/OLT product line — on a plain switch (e.g.
        // BDCOM S5612) it's genuinely absent from the device's own MIB
        // implementation, not just empty, and this SNMP wrapper treats
        // that as a hard error rather than a benign empty walk. Confirmed
        // live: batching it together with if.Descr in one multi-OID walk
        // made the whole call throw, taking down if.Descr's data too — so
        // it's walked separately here and swallowed on failure instead.
        $walkOids = [\SnmpWrapper\Oid::init($this->oids->getOidByName('if.Descr')->getOid())];
        $data = $this->formatResponse($this->snmp->walk($walkOids));
        try {
            $llidOid = $this->oids->getOidByName('ont.llidSeqNumber');
            $llidData = $this->formatResponse($this->snmp->walk([\SnmpWrapper\Oid::init($llidOid->getOid())]));
            $data = array_merge($data, $llidData);
        } catch (\Throwable $e) {
            // Name not declared for this model, or the device itself has
            // no such object (e.g. a plain switch) — either way, this
            // module works fine without it; the data it would have fed
            // just isn't used anywhere in the physical-interfaces list
            // built below.
        }
        //Build physical interfaces list
        $this->physicalInterfaces = [];
        foreach ($this->getResponseByName('if.Descr', $data)->fetchAll() as $iface) {
            $xid = Helper::getIndexByOid($iface->getOid());
            if (preg_match('/^GigaEthernet(([0-9])\/([1-9]{1,3}))$/', $iface->getValue(), $m)) {
                $name = "g{$m[1]}";
                $this->physicalInterfaces[] = [
                    'id' =>  (int)$this->getIdByName($name),
                    'xid' => $xid,
                    'name' => $name,
                    'type' => 'GE',
                    '_slot' => (int)$m[2],
                    '_port' => (int)$m[3],
                    '_type' => 'GigaEthernet',
                ];
            }
            if (preg_match('/^TGigaEthernet(([0-9])\/([1-9]{1,3}))$/', $iface->getValue(), $m)) {
                $name = "tg{$m[1]}";
                $this->physicalInterfaces[] = [
                    'id' => (int)$this->getIdByName($name),
                    'xid' => $xid,
                    'name' => $name,
                    'type' => 'TGE',
                    '_slot' => (int)$m[2],
                    '_port' => (int)$m[3],
                    '_type' => 'TGigaEthernet',
                ];
            }
            if ($this->model->getKey() != 'bdcom_p3616_2te' && preg_match('/^FastEthernet(([0-9])\/([1-9]{1,3}))$/', $iface->getValue(), $m)) {
                $name = "fe{$m[1]}";
                $this->physicalInterfaces[] = [
                    'id' =>  (int)$this->getIdByName($name),
                    'xid' => $xid,
                    'name' => $name,
                    'type' => 'FE',
                    '_slot' => (int)$m[2],
                    '_port' => (int)$m[3],
                    '_type' => 'FastEthernet',
                ];
            }
            if (preg_match('/^EPON(([0-9])\/([0-9]{1,3}))$/', $iface->getValue(), $m)) {
                $name = "epon{$m[1]}";
                $this->physicalInterfaces[] = [
                    'id' =>  (int)$this->getIdByName($name),
                    'xid' => $xid,
                    'name' => $name,
                    'type' => 'PON',
                    '_slot' => (int)$m[2],
                    '_port' => (int)$m[3],
                    '_type' => 'EPON',
                    '_technology' => 'epon',
                ];
            }
            if (preg_match('/^g(([0-9])\/([1-9]{1,3}))$/', $iface->getValue(), $m)) {
                $name = "g{$m[1]}";
                $this->physicalInterfaces[] = [
                    'id' =>  (int)$this->getIdByName($name),
                    'xid' => $xid,
                    'name' => $name,
                    'type' => 'GE',
                    '_slot' => (int)$m[2],
                    '_port' => (int)$m[3],
                    '_type' => 'g',
                ];
            }
            if (preg_match('/^tg(([0-9])\/([1-9]{1,3}))$/', $iface->getValue(), $m)) {
                $name = "tg{$m[1]}";
                $this->physicalInterfaces[] = [
                    'id' =>  (int)$this->getIdByName($name),
                    'xid' => $xid,
                    'name' => $name,
                    'type' => 'TGE',
                    '_slot' => (int)$m[2],
                    '_port' => (int)$m[3],
                    '_type' => 'tg',
                ];
            }
            if (preg_match('/^epon(([0-9])\/([0-9]{1,3}))$/', $iface->getValue(), $m)) {
                $name = "epon{$m[1]}";
                $this->physicalInterfaces[] = [
                    'id' =>  (int)$this->getIdByName($name),
                    'xid' => $xid,
                    'name' => $name,
                    'type' => 'PON',
                    '_slot' => (int)$m[2],
                    '_port' => (int)$m[3],
                    '_type' => 'epon',
                    '_technology' => 'epon',
                ];
            }
            if (preg_match('/aggregator([0-9]{1,3})$/', $iface->getValue(), $m)) {
                $name = "po{$m[1]}";
                $this->physicalInterfaces[] = [
                    'id' =>  (int)$this->getIdByName($name),
                    'xid' => $xid,
                    'name' => $name,
                    'type' => 'LACP',
                    '_slot' => null,
                    '_port' => $m[1],
                    '_type' => 'aggregator',
                ];
            }
        }
        $ifaces = [];
        $llidSeqs = [];
        // Absent entirely on plain switches (no PON ports to number) —
        // see the walk above, which already swallows that case instead of
        // populating this key. Nothing downstream needs $llidSeqs to be
        // non-empty; it just means this device has no ONU sub-interfaces.
        foreach ((isset($data['ont.llidSeqNumber']) ? $this->getResponseByName('ont.llidSeqNumber', $data)->fetchAll() : []) as $item) {
            $xid = Helper::getIndexByOid($item->getOid(), 6);
            $parentIface = $this->findPhysicalInterface($xid, 'xid');
            if ($parentIface) {
                $llidSeqs["{$parentIface['name']}:{$item->getValue()}"] = "." .
                    Helper::getIndexByOid($item->getOid(), 6) . "." .
                    Helper::getIndexByOid($item->getOid(), 5) . "." .
                    Helper::getIndexByOid($item->getOid(), 4) . "." .
                    Helper::getIndexByOid($item->getOid(), 3) . "." .
                    Helper::getIndexByOid($item->getOid(), 2) . "." .
                    Helper::getIndexByOid($item->getOid(), 1) . "." .
                    Helper::getIndexByOid($item->getOid());
            }
        }
        foreach ($this->getResponseByName('if.Descr', $data)->fetchAll() as $iface) {
            $xid = Helper::getIndexByOid($iface->getOid());
            $parentIface = $this->findPhysicalInterface($xid, 'xid');
            $type = $parentIface ? $parentIface['type'] : 'ONU';
            //Fucking telnet - need to rename port
            $name = strtolower($iface->getValue());
            if (preg_match('/^GigaEthernet([0-9]\/[0-9]{1,3})/', $iface->getValue(), $m)) {
                $name = "g{$m[1]}";
            }
            if (preg_match('/^TGigaEthernet([0-9]\/[0-9]{1,3})$/', $iface->getValue(), $m)) {
                $name = "tg{$m[1]}";
            }
            if ($this->model->getKey() === 'bdcom_p3616_2te' && preg_match('/^FastEthernet([0-9]\/[1-9]{1,3})$/', $iface->getValue(), $m)) {
                continue;
            }
            if (preg_match('/^FastEthernet([0-9]\/[0-9]{1,3})$/', $iface->getValue(), $m)) {
                $name = "fe{$m[1]}";
            }
            if (preg_match('/^g([0-9]\/[0-9]{1,3})/', $iface->getValue(), $m)) {
                $name = "g{$m[1]}";
            }
            if (preg_match('/^tg([0-9]\/[0-9]{1,3})/', $iface->getValue(), $m)) {
                $name = "tg{$m[1]}";
            }
            if (strpos($name, "aggregator") !== false) {
                $type = 'LACP';
            }
            if (strpos($iface->getValue(), "VLAN") !== false) {
                $type = 'vlan';
                continue;
            }
            $slot = null;
            $port = null;
            $onuNum = null;
            if (preg_match('/.*?([0-9]{1,3})\/([0-9]{1,3}):?([0-9]{1,3})?$/', $iface->getValue(), $m)) {
                $slot = (int)$m[1];
                $port = (int)$m[2];
                $onuNum = isset($m[3]) ? (int)$m[3] : null;
            }

            $id = $this->getIdByName($name);
            $ifaces[$id] = [
                'id' =>  (int)$id,
                'xid' => $xid,
                'name' => $name,
                '_llid_id' => isset($llidSeqs[$name]) ? $llidSeqs[$name] : null,
                'type' => $type,
                'parent' => $this->findParentByName($name),
                '_slot' => $slot,
                '_port' => $port,
                '_onu' => $onuNum,
                '_technology' => 'epon',
            ];
        }
        ksort($ifaces);
        sort($this->physicalInterfaces);
        $this->interfacesIds = $ifaces;
        $this->setCache("interfaces_list", [
            'ifaces_physical' => $this->physicalInterfaces,
            'ifaces_list' => $this->interfacesIds,
        ], 60, true);
        return $this;
    }

    private function findParentByName($name)
    {
        //EPON0/2:48
        if (preg_match('/epon0\/([0-9]{1,2}):([0-9]{1,3})/', $name, $matches)) {
            $id = 10000000;
            $id += 100000 * $matches[1];
            return $id;
        }
        return null;
    }

    private function getIdByName($name)
    {
        //EPON0/2:48
        if (preg_match('/epon0\/([0-9]{1,2}):([0-9]{1,3})/', $name, $matches)) {
            $id = 10000000;
            $id += 100000 * $matches[1];
            $id += 100 * $matches[2];
            return $id;
        }
        if (preg_match('/epon0\/([0-9]{1,2})/', $name, $matches)) {
            $id = 10000000;
            $id += 100000 * $matches[1];
            return $id;
        }
        // Slot-aware: these used to hardcode slot "0" (`tg0/`, `g0/`,
        // `fe0/`), so any device with a second port bank on a non-zero
        // slot (confirmed live: a BDCOM S5612 switch whose 10G uplinks are
        // named tg1/1..tg1/12, not tg0/*) got `null` here for every one of
        // those ports, which (int) casts to 0 — colliding all of them onto
        // a single id=0 and making all but one of them invisible to the
        // app. The `* 1000`/`* 100` slot multiplier keeps slot 0's ids
        // byte-for-byte identical to before (0 contributes nothing), while
        // giving every other slot its own non-overlapping band — safe up
        // to several dozen slots per type, far beyond any real chassis.
        if (preg_match('/^tg([0-9]{1,2})\/([0-9]{1,2})/', $name, $matches)) {
            return (int)$matches[2] + 100000 + ((int)$matches[1]) * 1000;
        }
        if (preg_match('/^g([0-9]{1,2})\/([0-9]{1,2})/', $name, $matches)) {
            return (int)$matches[2] + 10000 + ((int)$matches[1]) * 1000;
        }
        if (preg_match('/^fe([0-9]{1,2})\/([0-9]{1,2})/', $name, $matches)) {
            return (int)$matches[2] + 1000 + ((int)$matches[1]) * 100;
        }
        if (strpos($name, "aggregator") !== false) {
            $input_as_arr = str_split($name);
            return 70000000 + $input_as_arr[count($input_as_arr) - 1];
        }
        if(preg_match('/^po/', $name)) {
            $input_as_arr = str_split($name);
            return 70000000 + $input_as_arr[count($input_as_arr) - 1];
        }
        return null;
    }

    private function findPhysicalInterface($findValue, $findKey)
    {
        $filtered = array_values(array_filter($this->physicalInterfaces, function ($val) use ($findValue, $findKey) {
            return isset($val[$findKey]) && $val[$findKey] == $findValue;
        }));
        if (count($filtered) > 0) {
            return $filtered[0];
        }
        return null;
    }
}
