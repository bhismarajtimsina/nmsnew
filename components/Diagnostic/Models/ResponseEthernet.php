<?php

namespace WCC\Diagnostic\Models;

class ResponseEthernet implements ResponseTypesInterface
{
    protected $cableDiag = null;
    protected $sfpDiag = null;
    protected $counters = null;
    protected $errors = null;
    protected $fdb = null;
    protected $link = null;
    protected $rmon = null;
    protected $vlans = null;
    protected $interface = null;

    function getAsArray() {
        return [
          'fdb' => $this->fdb,
          'interface' => $this->interface,
          'vlans' => $this->vlans,
          'counters' => $this->counters,
          'errors' => $this->errors,
          'link' => $this->link,
          'rmon' => $this->rmon,
          'cableDiag' => $this->cableDiag,
          'sfpDiag' => $this->sfpDiag,
        ];
    }
}
