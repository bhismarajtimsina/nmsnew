<?php


namespace SwitcherCore\Modules\HuaweiOLT;


use Exception;
use SnmpWrapper\Oid; 
use SwitcherCore\Modules\Helper;
class LldpInfo extends HuaweiOLTAbstractModule
{
    protected function formate() {
        $response = [
            'local' => [
                'chassis_id' => null,
                'ports' => null,
            ],
            'remotes' => []
        ];
        // Confirmed live (a different Huawei OLT sub-model than the
        // MA5683T this comment used to describe): the local chassis ID
        // lookup itself can legitimately come back empty too — not just
        // the remote-neighbor table below — so treating it as a hard
        // error here was wrong; made this defensive to match the general
        // pattern (and BDCOM's own LldpInfo, fixed the same way) instead
        // of throwing and aborting the whole call over a genuinely normal
        // "this device doesn't populate this OID" state.
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

        // An empty remote-neighbor table (no device currently attached with
        // LLDP enabled on any port) is a normal, expected state — confirmed
        // live on a real MA5683T, where this walk legitimately comes back
        // with zero rows most of the time. The underlying SNMP wrapper
        // apparently flags that as an "error" on the response object same
        // as it would a real fault, so unlike loc_chassis/loc_ports above
        // (where an error really does mean something's wrong), errors here
        // are swallowed rather than thrown — matching the more defensive
        // pattern General\LldpInfo already uses for the same two OIDs.
        $remotes = [];
        if(isset($this->response['lldp.remChassisId']) && !$this->getResponseByName('lldp.remChassisId')->error()) {
            foreach ($this->getResponseByName('lldp.remChassisId')->fetchAll() as $dt) {
                $id = Helper::getIndexByOid($dt->getOid());
                $port = Helper::getIndexByOid($dt->getOid(), 1);
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
                $id = Helper::getIndexByOid($dt->getOid());
                $port = Helper::getIndexByOid($dt->getOid(), 1);
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

