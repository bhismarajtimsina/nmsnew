<?php

namespace  WCAA\Infrastructure\Poller\Interfaces;

interface PollerInterfaceListInterface
{

    /**
     * Must return array of interfaces
     * Example: [
     *    ['name' => '0/1', 'id' => 1001, 'type' => 'ETHERNET']
     * ]
     * @return array
     */
    function getInterfacesList(): array;
}