<?php

namespace WCAA\Infrastructure\Poller\Interfaces;

interface PollerSensorsInterface
{
    /**
     * Return sensors data
     * Must return
     * [
     *    [
     *      'value' => 1,
     *      'id' =>  10,
     *      'type' => 'string',
     *      'name' => 'name',
     *    ]
     * ]
     * @return mixed
     */
    function getAllSensorsData();
}