<?php


namespace WCC\OltsControl\Controllers;


use Monolog\Logger;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Poller\Interfaces\PollerCountersInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerFdbInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceListInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOntIdentificationInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerResourcesInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSystemInterface;
use WCAA\Interfaces\ControllerInterface;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\ResponsesWrapper;
use WCAA\SwitcherCore\SwitcherCore;

/**
 * Class Controller
 * @package WCC\OltsControl
 */
class Controller extends \WCC\Olts\Controllers\Controller {

}
