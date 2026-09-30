<?php


namespace SwitcherCore\Modules\BDcom\Switches;


use Exception;
use SnmpWrapper\Oid;
use SwitcherCore\Config\Objects\Trap;
use SwitcherCore\Exceptions\IncompleteResponseException;
use SwitcherCore\Modules\AbstractModule;
use SwitcherCore\Switcher\Objects\WrappedResponse;

/**
 * @moduleKey power_status
 *
 * Power supply state for the BDCOM switch families, from NMS-POWER-MIB's
 * powerStatus (.1.3.6.1.4.1.3320.9.189.1): A-normal, B-normal, A-B-normal or
 * other, exactly as the OLT reads it.
 *
 * Separate from \SwitcherCore\Modules\BDcom\GP3600\PowerStatus because that
 * one reaches straight into fetchAll()[0] and assumes a reading is there. On
 * an OLT it always is — they are dual-feed rack units. Across the switch line
 * it often is not: the S5612 on this network returns "No Such Object" for the
 * whole .9.189 branch, and desktop models have one external supply and no
 * sensor at all. This version reports that as unknown rather than fatalling
 * on a null, so a family file can declare the OID for the models that have
 * the hardware without breaking the ones that do not.
 */
class PowerStatus extends AbstractModule
{
    /**
     * @var WrappedResponse[]
     */
    protected $response = null;

    function getPrettyFiltered($filter = [])
    {
        return $this->getPretty();
    }

    function getRaw()
    {
        return $this->response;
    }

    public function trap(Trap $trap, $data)
    {
        print_r($data);
    }

    function getPretty()
    {
        try {
            $resp = $this->getResponseByName('system.power.status')->fetchAll();
        } catch (IncompleteResponseException $e) {
            $resp = [];
        }
        if (!isset($resp[0])) {
            // No sensor on this unit. Saying so is useful; inventing a state
            // or throwing is not.
            return [
                '_pretty' => 'Not reported',
                'status' => 'Unknown',
                '_status' => null,
            ];
        }
        $raw = $resp[0];
        return [
            '_pretty' => $raw->getParsedValue(),
            'status' => $raw->getParsedValue() == 'A-B-normal' ? 'Good' : 'Alert',
            '_status' => $raw->getValue(),
        ];
    }

    /**
     * @param array $filter
     * @return $this|AbstractModule
     * @throws Exception
     */
    public function run($filter = [])
    {
        $oid = $this->oids->getOidByName('system.power.status');
        $oArray[] = Oid::init($oid->getOid(), false);
        $this->response = $this->formatResponse($this->snmp->get($oArray));
        return $this;
    }
}
