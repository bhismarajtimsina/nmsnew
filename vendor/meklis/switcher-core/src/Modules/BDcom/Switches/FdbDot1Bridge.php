<?php


namespace SwitcherCore\Modules\BDcom\Switches;


use SnmpWrapper\Oid;
use SwitcherCore\Modules\Helper;

/**
 * @moduleKey fdb
 *
 * MAC address table for BDCOM switches, read over SNMP from the standard
 * Q-BRIDGE table (dot1qTpFdbPort/dot1qTpFdbStatus) that the general
 * \SwitcherCore\Modules\General\Switches\FdbDot1Bridge already walks.
 *
 * BDCOM's other FDB module, \SwitcherCore\Modules\BDcom\FdbTableConsole,
 * drives the console instead, and on the switch line that is not usable: the
 * S5612 here accepts a telnet connection and immediately closes it with no
 * prompt, and the console client blocks waiting for a login prompt that never
 * arrives, tying up a worker for 90+ seconds per call. That is why `fdb` was
 * left unwired on every BDCOM switch and MAC lookup simply did not work on
 * them.
 *
 * Confirmed against the real S5612 at 172.30.64.5 before this class existed:
 * it answers .1.3.6.1.2.1.17.7.1.2.2.1.2 and .1.3.6.1.2.1.17.7.1.2.2.1.3 with
 * real learned entries, indexed by VLAN and MAC, and the port column holds
 * ifIndex values that match the interface list's own xid.
 *
 * Interfaces are resolved through the model's own `interfaces_list` module
 * rather than by walking again here, so this agrees with every other BDCOM
 * module about what a port is called.
 *
 * Important operational guard: this switch line is sensitive to broad SNMP
 * walks. Q-BRIDGE cannot be narrowed by interface without first reading the
 * whole table, so this module only permits exact MAC+VLAN lookups.
 */
class FdbDot1Bridge extends \SwitcherCore\Modules\General\Switches\FdbDot1Bridge
{
    private $ifaces = null;

    function getInterfacesIds()
    {
        if ($this->ifaces === null) {
            $this->ifaces = [];
            foreach ($this->getModule('interfaces_list')->run()->getPretty() as $iface) {
                if (isset($iface['id'])) {
                    $this->ifaces[$iface['id']] = $iface;
                }
            }
        }
        return $this->ifaces;
    }

    /**
     * The FDB's port column carries an ifIndex, which the interface list calls
     * `xid`, while filters coming from the API use the internal `id` or the
     * port name. All three are accepted.
     */
    function parseInterface($iface, $parseBy = 'id')
    {
        $ifaces = $this->getInterfacesIds();
        if (isset($ifaces[$iface])) {
            return $ifaces[$iface];
        }
        foreach (['xid', 'id'] as $field) {
            foreach ($ifaces as $candidate) {
                if (isset($candidate[$field]) && (string)$candidate[$field] === (string)$iface) {
                    return $candidate;
                }
            }
        }
        if (is_string($iface)) {
            foreach ($ifaces as $candidate) {
                if (isset($candidate['name']) && strtolower($candidate['name']) === strtolower($iface)) {
                    return $candidate;
                }
            }
        }
        throw new \InvalidArgumentException("Error find interface by ident='{$iface}'");
    }

    public function run($filter = [])
    {
        Helper::prepareFilter($filter);
        if (!$filter['vlan_id'] || !$filter['mac']) {
            throw new \InvalidArgumentException(
                'BDCOM switch FDB requires exact vlan_id and mac filters; full/interface FDB walks are disabled to protect the device'
            );
        }

        $suffix = ".{$filter['vlan_id']}." . Helper::mac2oid($filter['mac']);
        $this->response = $this->formatResponse($this->snmp->get([
            Oid::init($this->oids->getOidByName('dot1q.FdbStatus')->getOid() . $suffix),
            Oid::init($this->oids->getOidByName('dot1q.FdbPort')->getOid() . $suffix),
        ]));

        return $this;
    }
}
