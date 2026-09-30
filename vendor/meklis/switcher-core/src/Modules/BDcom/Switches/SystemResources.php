<?php


namespace SwitcherCore\Modules\BDcom\Switches;


use Exception;
use SnmpWrapper\Oid;
use SwitcherCore\Exceptions\IncompleteResponseException;
use SwitcherCore\Modules\AbstractModule;
use SwitcherCore\Switcher\Objects\WrappedResponse;

/**
 * @moduleKey sys_resources
 *
 * Same shape as \SwitcherCore\Modules\BDcom\SystemResources, but for the
 * BDCOM switch family (e.g. S5612), which doesn't expose the OLT's fan
 * (resources.fanStatus) or CPU-temperature (sensors.temperature.cpu)
 * OIDs — confirmed live, both return "No Such Object"/"No Such Instance".
 *
 * The OLT module reads those two unconditionally, so reusing it here would
 * make the whole card fail (an IncompleteResponseException, since the
 * names are never even declared in this model's own oid file) even though
 * CPU/mem are genuinely available. This version reads fan/temp only if
 * declared for the current model, and simply omits them (rather than
 * failing the whole response) when they aren't.
 */
class SystemResources extends AbstractModule
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

    /**
     * Reads a resources.* value if that name was actually walked (i.e. is
     * declared in the model's own oid file) — returns null instead of
     * throwing when it isn't, so an optional sensor missing on a given
     * unit doesn't take the whole card down with it.
     */
    private function optionalValue($name, $asParsed = false)
    {
        try {
            $resp = $this->getResponseByName($name)->fetchAll();
        } catch (IncompleteResponseException $e) {
            return null;
        }
        if (!isset($resp[0])) return null;
        return $asParsed ? $resp[0]->getParsedValue() : $resp[0]->getValue();
    }

    function getPretty()
    {
        $fanStatus = $this->optionalValue('resources.fanStatus', true);
        return [
            'cpu' => [
                'util' => (int)$this->optionalValue('resources.cpuUtil'),
            ],
            'disk' => null,
            'interfaces' => null,
            'cards' => null,
            'fans' => $fanStatus !== null ? ['status' => $fanStatus] : null,
            'memory' => [
                'util' => (int)$this->optionalValue('resources.memUtil'),
            ],
        ];
    }

    /**
     * @param array $filter
     * @return $this|AbstractModule
     * @throws Exception
     */
    public function run($filter = [])
    {
        $oids = $this->oids->getOidsByRegex('^resources\..*');
        $oArray = [];
        foreach ($oids as $oid) {
            $oArray[] = Oid::init($oid->getOid(), false);
        }
        $this->response = $this->formatResponse($this->snmp->walk($oArray));
        return $this;
    }
}
