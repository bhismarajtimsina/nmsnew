<?php
namespace SwitcherCore\Modules\BDcom;

use SnmpWrapper\Oid;
use SwitcherCore\Modules\Helper;
class LldpInfo extends BDcomAbstractModule
{
    protected function formate() {
        $response = [
            'local' => [
                'chassis_id' => null,
                'ports' => null,
            ],
            'remotes' => []
        ];
        // Confirmed live (BDCOM S5612): this device's own local chassis ID
        // is itself missing under the standard IETF LLDP-MIB branch this
        // module queries ("No Such Instance" at lldpLocChassisId,
        // .1.0.8802.1.1.2.1.3.2) — cross-checked against LibreNMS's real
        // BDCOM driver, which confirms BDCOM switches implement their OWN
        // proprietary NMS-LLDP-MIB instead of the standard one. So unlike
        // HuaweiOLT's LldpInfo (where a missing LOCAL chassis id really
        // would mean something's wrong), a failure on ANY of these four
        // fields for BDCOM just means "this vendor doesn't populate the
        // generic LLDP-MIB" — normal, not an error worth throwing over.
        if(isset($this->response['lldp.locChassisId']) && !$this->getResponseByName('lldp.locChassisId')->error()) {
            $response['local']['chassis_id'] = $this->formateValue($this->getResponseByName('lldp.locChassisId')->fetchOne()->getValue());
        }
        if(isset($this->response['lldp.locPortId']) && !$this->getResponseByName('lldp.locPortId')->error()) {
            $ports = [];
            foreach ($this->getResponseByName('lldp.locPortId')->fetchAll() as $dt) {
                $id = Helper::getIndexByOid($dt->getOid());
                $ports[] = [
                    'port_id' => $id,
                    'name' => $this->formateValue($dt->getValue()),
                    'interface' => $this->parseInterface($id),
                ];
            }
            $response['local']['ports'] = $ports;
        }

        // Confirmed live: BDCOM's private lldpRemTable uses a 2-part index
        // (a sequence number, then the local port number LAST) — not the
        // standard IETF LLDP-MIB's 3-part {timeMark, localPortNum, index}
        // key this code was originally written against. Real captured OID
        // ".5.1.31" is (sequence=1, localPort=31 — a genuine interface
        // xid); the original offsets (0 for "$id", 1 for "$port") had this
        // backwards, extracting the sequence number as if it were the
        // port and crashing parseInterface() with things like ident='3'.
        $remotes = [];
        if(isset($this->response['lldp.remChassisId']) && !$this->getResponseByName('lldp.remChassisId')->error()) {
            foreach ($this->getResponseByName('lldp.remChassisId')->fetchAll() as $dt) {
                $id = Helper::getIndexByOid($dt->getOid(), 1);
                $port = Helper::getIndexByOid($dt->getOid());
                $remotes["{$port}.{$id}"] = [
                    'loc_interface' => $this->parseInterface($port),
                    'rem_chassis_id' => $this->formateValue($dt->getValue()),
                    'rem_interface' => null,
                    '_rem_port_id' => null,
                ];
            }
        }
        if(isset($this->response['lldp.remPortId']) && !$this->getResponseByName('lldp.remPortId')->error()) {
            foreach ($this->getResponseByName('lldp.remPortId')->fetchAll() as $dt) {
                $id = Helper::getIndexByOid($dt->getOid(), 1);
                $port = Helper::getIndexByOid($dt->getOid());
                $remotes["{$port}.{$id}"]['loc_interface'] = $this->parseInterface($port);
                $remotes["{$port}.{$id}"]['rem_interface'] = $this->formateValue($dt->getValue());
                $remotes["{$port}.{$id}"]['_rem_port_id'] = $this->getPortId($remotes["{$port}.{$id}"]['rem_chassis_id'], $this->formateValue($dt->getValue()));
                if(!isset($remotes["{$port}.{$id}"]['rem_chassis_id'])) {
                    $remotes["{$port}.{$id}"]['rem_chassis_id'] = null;
                }

            }
        }
        $response['remotes'] = array_values($remotes);
        return $response;
    }
    function getPretty()
    {
        return $this->formate();
    }

    function getPortId($chassisId, $portIdent)
    {
        if(!$chassisId) return null;
        if(!$portIdent) return null;
        try {
            return hexdec(str_replace(':', '', $portIdent)) - hexdec(str_replace(':', '', $chassisId));
        } catch (\Exception $e) {
            return null;
        }
    }

    function getPrettyFiltered($filter = [], $fromCache = false)
    {
        return $this->formate();
    }

    function formateValue($value)
    {
        $value = trim($value);
        if(preg_match('/^([[:xdigit:]]{2}) ([[:xdigit:]]{2}) ([[:xdigit:]]{2}) ([[:xdigit:]]{2}) ([[:xdigit:]]{2}) ([[:xdigit:]]{2})$/', $value, $m)) {
            return "{$m[1]}:{$m[2]}:{$m[3]}:{$m[4]}:{$m[5]}:{$m[6]}";
        }
        return  $value;
    }

    public function run($filter = [])
    {
        Helper::prepareFilter($filter);
        $oids = [];
        if(isset($filter['load_only'])) {
            $loadOnly = explode(",", $filter['load_only']);
            if(in_array("loc_chassis", $loadOnly)) {
                $oids[] = $this->oids->getOidByName('lldp.locChassisId');
            }
            if(in_array("loc_ports", $loadOnly)) {
                $oids[] = $this->oids->getOidByName('lldp.locPortId');
            }
            if(in_array("rem_chassis", $loadOnly)) {
                $oids[] = $this->oids->getOidByName('lldp.remChassisId');
            }
            if(in_array("rem_port", $loadOnly)) {
                $oids[] = $this->oids->getOidByName('lldp.remPortId');
            }
        } else {
            $oids = $this->oids->getOidsByRegex('lldp\..*');
        }

        $oidObjects = [];
        foreach ($oids as $oid) {
            $oidObjects[] = Oid::init($oid->getOid());
        }
        $this->response = $this->formatResponse($this->snmp->walk($oidObjects));
        return $this;
    }
}

