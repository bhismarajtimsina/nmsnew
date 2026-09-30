<?php

namespace WCC\Olts\Controllers\ModelProcessors;

use WCAA\Infrastructure\Poller\Interfaces\PollerCardsStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerCountersInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerFdbInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceListInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOntIdentificationInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOntVendorInfoInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerResourcesInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSfpOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSystemInterface;
use WCAA\Infrastructure\Poller\Interfaces\PonPortLoadingInterface;
use WCAA\Infrastructure\Poller\Pollers\PonPortLoadingPoller;
use WCAA\Interfaces\ControllerInterface;

class DefaultProcessor extends AbstractProcessor implements PollerSystemInterface,
    PollerFdbInterface,
    ControllerInterface,
    PollerInterfaceListInterface,
    PollerOntIdentificationInterface,
    PollerInterfaceStatusInterface,
    PollerOpticalStrengthInterface,
    PollerCountersInterface,
    PollerResourcesInterface,
    PonPortLoadingInterface,
    PollerOntVendorInfoInterface,
    PollerCardsStatusInterface,
    PollerSfpOpticalStrengthInterface
{

}