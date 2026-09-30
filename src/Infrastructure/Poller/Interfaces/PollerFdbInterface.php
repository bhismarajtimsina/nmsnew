<?php

namespace WCAA\Infrastructure\Poller\Interfaces;

interface PollerFdbInterface
{
    /**
     * Return FDB table
     * Must return
     * [
     *   interface => [
     *      id => 1234
     *   ]
     *   mac_address => 'AA:BB:CC:DD:EE:FF',
     *   vlan_id => 1,
     * ]
     *
     * @return mixed
     */
    function getFdbTable();
}