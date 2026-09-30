<?php


namespace SwitcherCore\Modules\BDcom\GP3600;


use Exception;
use SnmpWrapper\Oid;
use SnmpWrapper\Response\PoollerResponse;
use SwitcherCore\Modules\AbstractModule;
use SwitcherCore\Modules\Helper;
use SwitcherCore\Switcher\Objects\WrappedResponse;

class SfpOpticalInfo extends BDcomAbstractModule
{
    /**
     * @var WrappedResponse[]
     */
    protected $response = null;

    function getRaw()
    {
        return $this->response;
    }

    /**
     * Walks one named response and applies $setter($ifaces, $xid, $value) per row.
     *
     * Each row gets its OWN try/catch around parseInterface() — confirmed
     * live this matters a lot: getPhysicalInterfaces() can return xids that
     * getInterfacesIds()/parseInterface() then fails to resolve (e.g. a
     * phantom/duplicate physical-port entry produced by the device
     * exposing the same port under more than one ifDescr naming
     * convention). Previously a single such row threw out of the *whole*
     * foreach, which — since the try/catch wrapped the entire loop — threw
     * away every already-collected reading for that metric, not just the
     * bad row. That's the actual reason this module had returned an empty
     * optical array for every port on this device, not a data-availability
     * limitation.
     */
    private function collectByName(array &$ifaces, $name)
    {
        try {
            $data = $this->getResponseByName($name);
        } catch (\Exception $e) {
            return;
        }
        foreach ($data->fetchAll() as $r) {
            try {
                $xid = Helper::getIndexByOid($r->getOid());
                $iface = $this->parseInterface($xid);
            } catch (\Throwable $inner) {
                continue;
            }
            $ifaces[$xid]['interface'] = $iface;
            $ifaces[$xid][$name] = $r->getValue();
        }
    }

    function getPretty()
    {
        $ifaces = [];

        $this->collectByName($ifaces, 'pon.port.optical.temp');
        $this->collectByName($ifaces, 'pon.port.optical.voltage');
        $this->collectByName($ifaces, 'pon.port.optical.bias');
        $this->collectByName($ifaces, 'pon.port.optical.txPower');
        $this->collectByName($ifaces, 'sfp.ddm.txPower');
        $this->collectByName($ifaces, 'sfp.ddm.rxPower');
        $this->collectByName($ifaces, 'sfp.ddm.temp');
        $this->collectByName($ifaces, 'sfp.ddm.voltage');
        $this->collectByName($ifaces, 'sfp.ddm.present');

        return array_values(array_map(function ($e) {
            if (isset($e['pon.port.optical.temp'])) $e['temp'] = round($e['pon.port.optical.temp'] / 256, 2);
            if (isset($e['sfp.ddm.temp'])) $e['temp'] = round($e['sfp.ddm.temp'] / 256, 2);
            if (isset($e['pon.port.optical.voltage'])) $e['vcc'] = round($e['pon.port.optical.voltage'] / 10000, 2);
            if (isset($e['sfp.ddm.voltage'])) $e['vcc'] = round($e['sfp.ddm.voltage'] / 10000, 2);
            if (isset($e['pon.port.optical.bias'])) $e['tx_bias'] = (int)$e['pon.port.optical.bias'];
            if (isset($e['pon.port.optical.txPower'])) $e['tx_power'] = round($e['pon.port.optical.txPower'] / 10, 2);
            if (isset($e['sfp.ddm.txPower'])) $e['tx_power'] = round($e['sfp.ddm.txPower'] / 100, 2);
            if (isset($e['sfp.ddm.rxPower'])) $e['rx_power'] = round($e['sfp.ddm.rxPower'] / 100, 2);
            // online(1)/offline(2) — whether a transceiver is physically
            // present. Not declared for every model (e.g. PON ports on
            // GP3600 OLTs don't have this concept), so left null when absent.
            if (isset($e['sfp.ddm.present'])) $e['present'] = ((int)$e['sfp.ddm.present']) === 1;
            foreach (['pon.port.optical.temp', 'sfp.ddm.temp', 'pon.port.optical.voltage', 'sfp.ddm.voltage', 'pon.port.optical.bias', 'pon.port.optical.txPower', 'sfp.ddm.txPower', 'sfp.ddm.rxPower', 'sfp.ddm.present'] as $raw) {
                unset($e[$raw]);
            }
            if (isset($e['tx_power']) && $e['tx_power'] < -40) {
                $e['tx_power'] = null;
            }
            if (isset($e['rx_power']) && $e['rx_power'] <= -40) {
                $e['rx_power'] = null;
            }
            if (isset($e['temp']) && $e['temp'] < -40) {
                $e['temp'] = null;
            }
            if (isset($e['vcc']) && $e['vcc'] < 0) {
                $e['vcc'] = null;
            }
            if (!isset($e['tx_bias'])) $e['tx_bias'] = null;
            if (!isset($e['vcc'])) $e['vcc'] = null;
            if (!isset($e['temp'])) $e['temp'] = null;
            if (!isset($e['tx_power'])) $e['tx_power'] = null;
            if (!isset($e['rx_power'])) $e['rx_power'] = null;
            if (!isset($e['present'])) $e['present'] = null;
            return $e;
        }, $ifaces));
    }


    /**
     * @param array $filter
     * @return $this|AbstractModule
     * @throws Exception
     */
    public function run($filter = [])
    {
        Helper::prepareFilter($filter);
        $info = [];
        $loadOnly = [];
        if ($filter['load_only']) {
            $loadOnly = explode(",", $filter['load_only']);
        }
        if (!$loadOnly || in_array("temp", $loadOnly)) {
            // pon.port.optical.temp is PON-port-only (GP3600 OLT); a plain
            // switch model (e.g. bdcom_s5612) never declares it, so this
            // must not be allowed to abort the whole module the way an
            // unwrapped getOidByName() would — fall through to sfp.ddm.temp.
            try {
                $info[] = $this->oids->getOidByName('pon.port.optical.temp');
            } catch (\Exception $e) {
            }
            try {
                $info[] = $this->oids->getOidByName('sfp.ddm.temp');
            } catch (\Exception $e) {
            }
        }
        if (!$loadOnly || in_array("voltage", $loadOnly)) {
            try {
                $info[] = $this->oids->getOidByName('pon.port.optical.voltage');
            } catch (\Exception $e) {
            }
            try {
                $info[] = $this->oids->getOidByName('sfp.ddm.voltage');
            } catch (\Exception $e) {
            }
        }
        if (!$loadOnly || in_array("bias", $loadOnly)) {
            try {
                $info[] = $this->oids->getOidByName('pon.port.optical.bias');
            } catch (\Exception $e) {
            }
        }
        if (!$loadOnly || in_array("tx", $loadOnly)) {
            try {
                $info[] = $this->oids->getOidByName('pon.port.optical.txPower');
            } catch (\Exception $e) {
            }
            try {
                $info[] = $this->oids->getOidByName('sfp.ddm.txPower');
            } catch (\Exception $e) {
            }
        }
        if (!$loadOnly || in_array("rx", $loadOnly)) {
            try {
                $info[] = $this->oids->getOidByName('sfp.ddm.rxPower');
            } catch (\Exception $e) {
            }
        }
        if (!$loadOnly || in_array("present", $loadOnly)) {
            try {
                $info[] = $this->oids->getOidByName('sfp.ddm.present');
            } catch (\Exception $e) {
            }
        }
        $oids = [];
        foreach ($info as $oid) {
            $oids[] = $oid->getOid();
        }
        if ($filter['interface']) {
            $iface = $this->parseInterface($filter['interface']);
            $oids = array_map(function ($e) use ($iface) {
                return $e . "." . $iface['xid'];
            }, $oids);
            $oids = array_map(function ($e) {
                return Oid::init($e);
            }, $oids);
            $this->response = $this->formatResponse($this->snmp->get($oids));
        } else {
            $preparedRequestedOids = [];
            foreach ($this->getPhysicalInterfaces() as $interface) {
                $preparedRequestedOids = array_merge($preparedRequestedOids, array_map(function ($e) use ($interface) {
                    return Oid::init($e . "." . $interface['xid']);
                }, $oids));
            }
            $this->response = $this->formatResponse($this->snmp->get($preparedRequestedOids));
        }
        return $this;
    }
}

