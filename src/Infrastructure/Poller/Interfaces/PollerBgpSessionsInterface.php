<?php

namespace WCAA\Infrastructure\Poller\Interfaces;

interface PollerBgpSessionsInterface
{
    function getBgpSessions($parameters = [], string $from = 'device');
}
