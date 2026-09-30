<?php

namespace WCAA\Infrastructure\Poller\Interfaces;

interface PollerArpsInterface
{
    function getArps($parameters = [], string $from = 'device');
}

