<?php

namespace WCAA\Infrastructure\Poller\Interfaces;

interface PollerResourcesInterface
{
    function getResources($from = 'device');
}