<?php


namespace SwitcherCore\Modules\HuaweiOLT;


use Exception;
use SwitcherCore\Config\Objects\Oid;
use SwitcherCore\Modules\AbstractModule;
use SwitcherCore\Modules\Helper;
use SwitcherCore\Switcher\Objects\WrappedResponse;

class OntConfiguration extends HuaweiOLTAbstractModule
{
    /**
     * @var WrappedResponse[]
     */
    protected $response = null;

    function getRaw()
    {
        return $this->response;
    }

    function getPretty()
    {
        return $this->response;
    }

    protected function formate($resp)
    {
        $return = [];
        try {
            $data = $this->getResponseByName('ont.gpon.config.authMethod', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['auth_method'] = $d->getParsedValue();
                }
            }
        } catch (\Exception $e) {
        }
        try {
            $data = $this->getResponseByName('ont.gpon.config.password', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['password'] = $this->convertHexToString($d->getHexValue());
                }
            }
        } catch (\Exception $e) {
        }

        try {
            $data = $this->getResponseByName('ont.gpon.config.timeout', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['timeout'] = $d->getParsedValue();
                }
            }
        } catch (\Exception $e) {
        }

        try {
            $data = $this->getResponseByName('ont.gpon.config.lineProfileName', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['line_profile'] = $d->getParsedValue();
                }
            }
        } catch (\Exception $e) {
        }
        try {
            $data = $this->getResponseByName('ont.gpon.config.serviceProfileName', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['service_profile'] = $d->getParsedValue();
                }
            }
        } catch (\Exception $e) {
        }
        try {
            $data = $this->getResponseByName('ont.gpon.config.isolationState', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['isolation'] = $d->getParsedValue();
                }
            }
        } catch (\Exception $e) {
        }
        try {
            // hwGponDeviceOntBatteryStatus — was defined in the OID catalog
            // (configs/oids/huawei/smartax.yml) but never actually queried
            // by any module. Real operational value: which ONTs have a
            // battery at all, whether one's currently on it (== a live
            // power outage at that address), and whether it's failed
            // (SupportButInvalid — installed but can't hold a charge).
            $data = $this->getResponseByName('ont.gpon.batteryStatus', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['battery_status'] = $d->getParsedValue();
                }
            }
        } catch (\Exception $e) {
        }
        try {
            // hwGponDeviceOntDiscoveryStatus — same story, unused OID.
            // Whether the ONT is still mid-negotiation with the OLT rather
            // than settled — useful for an ONT that's stuck half-registered.
            $data = $this->getResponseByName('ont.gpon.discoveryStatus', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['discovery_status'] = $d->getParsedValue();
                }
            }
        } catch (\Exception $e) {
        }



        try {
            $data = $this->getResponseByName('ont.epon.config.lineProfileName', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['line_profile'] = $d->getParsedValue();
                }
            }
        } catch (\Exception $e) {
        }
        try {
            $data = $this->getResponseByName('ont.epon.config.serviceProfileName', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['service_profile'] = $d->getParsedValue();
                }
            }
        } catch (\Exception $e) {
        }
        try {
            $data = $this->getResponseByName('ont.epon.config.authMethod', $resp);
            if (!$data->error()) {
                foreach ($data->fetchAll() as $d) {
                    $iface = $this->findIfaceByOid($d->getOid());
                    $return[$iface['id']]['interface'] = $iface;
                    $return[$iface['id']]['auth_method'] = $d->getParsedValue();
                }
            }
        } catch (\Exception $e) {
        }

        return array_values($return);
    }

    /**
     * @param array $filter
     * @return $this|AbstractModule
     * @throws Exception
     */
    public function run($filter = [])
    {

        $oidRequests = [];
        if ($filter['load_only']) {
            $loadOnly = array_map(function ($e) {
                return trim($e);
            }, explode(",", $filter['load_only']));
            if (in_array('auth_method', $loadOnly)) {
                if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.authMethod');
                if ($this->isHasEponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.epon.config.authMethod');
            }
            if (in_array('password', $loadOnly)) {
                if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.password');
            }
            if (in_array('timeout', $loadOnly)) {
                if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.timeout');
            }
            if (in_array('line_profile', $loadOnly)) {
                if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.lineProfileName');
                if ($this->isHasEponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.epon.config.lineProfileName');
            }
            if (in_array('service_profile', $loadOnly)) {
                if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.serviceProfileName');
                if ($this->isHasEponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.epon.config.serviceProfileName');
            }
            if (in_array('isolation', $loadOnly)) {
                if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.isolationState');
            }
            if (in_array('battery_status', $loadOnly)) {
                if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.batteryStatus');
            }
            if (in_array('discovery_status', $loadOnly)) {
                if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.discoveryStatus');
            }

        } else {
            $oidRequests = [];
            if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.authMethod');
            if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.password');
            if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.timeout');
            if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.lineProfileName');
            if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.serviceProfileName');
            if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.config.isolationState');
            if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.batteryStatus');
            if ($this->isHasGponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.gpon.discoveryStatus');

            if ($this->isHasEponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.epon.config.serviceProfileName');
            if ($this->isHasEponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.epon.config.lineProfileName');
            if ($this->isHasEponIfaces()) $oidRequests[] = $this->oids->getOidByName('ont.epon.config.authMethod');
        }
        if ($filter['interface']) {
            $iface = $this->parseInterface($filter['interface']);
            $oidRequests = array_filter($oidRequests, function (Oid $o) use ($iface) {
                return strpos($o->getName(), $iface['_technology']);
            });
            $oids = array_map(function ($e) use ($iface) {
                return \SnmpWrapper\Oid::init($e->getOid() . "." . $iface['xid']);
            }, $oidRequests);
            $this->response = $this->formate($this->formatResponse(
                $this->snmp->get($oids)
            ));
        } else {
            $oids = array_map(function ($e) {
                return \SnmpWrapper\Oid::init($e->getOid());
            }, $oidRequests);
            $this->response = $this->formate($this->formatResponse(
                $this->snmp->walk($oids)
            ));
        }
        return $this;
    }
}

