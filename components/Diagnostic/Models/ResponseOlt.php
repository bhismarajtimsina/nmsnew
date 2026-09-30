<?php

namespace WCC\Diagnostic\Models;

class ResponseOlt  implements ResponseTypesInterface
{
    // Block
    protected $adminStatus = null;
    protected $device = null;
    protected $fdb = [];
    protected $interface = null;
    protected $lastDownReason = null;
    protected $lastReg = null;
    protected $lastRegSince = null;
    protected $macAddress = null;
    protected $optical = null;
    protected $serial = null;
    protected $status = null;
    protected $uni = null;
    protected $meta = null;

    function getAsArray() {
        return [
          'admin_status' => $this->adminStatus,
          'device' => $this->device,
          'fdb' => $this->fdb,
          'interface' => $this->interface,
          'last_down_reason' => $this->lastDownReason,
          'last_reg' => $this->lastReg,
          'last_reg_since' => $this->lastRegSince,
          'mac_address' => $this->macAddress,
          'serial' => $this->serial,
          'status' => $this->status,
          'uni' => $this->uni,
        ];
    }



}
