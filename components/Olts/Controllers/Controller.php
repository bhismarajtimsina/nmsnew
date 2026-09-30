<?php


namespace WCC\Olts\Controllers;


use DI\Annotation\Inject;
use Monolog\Logger;
use WCAA\App;
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
use WCAA\Infrastructure\Poller\Interfaces\PollerUnregisteredOntsInterface;
use WCAA\Infrastructure\Poller\Interfaces\PonPortLoadingInterface;
use WCAA\Infrastructure\Poller\ResponseToPollerWriter;
use WCAA\Interfaces\ControllerInterface;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\ResponsesWrapper;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Olts\Controllers\ModelProcessors\AbstractProcessor;
use WCC\Olts\Controllers\ModelProcessors\DefaultProcessor;
use WCC\Olts\Controllers\ModelProcessors\HuaweiProcessor;

/**
 * Class Controller
 * @method getCardsList($from = 'cache')
 * @method getCardsStatus($from = 'cache')
 * @method getUnregisteredOnts($from = 'cache')
 * @method getGponProfiles($from = 'cache')
 * @method getOntConfiguration($from = 'cache')
 * @method getPonInterfacesList($from = 'cache')
 * @method getOntsListInfo($from = 'cache')
 * @method getPhysicalInterfacesStatus($from = 'cache')
 * @method getOnuInfo($interface, $from = 'cache')
 * @method getLastMeta($from = 'cache')
 * @method rebootOnu($interface)
 * @method resetOnu($interface)
 * @method ctrlOnuDisable($interface, $state = 'enable')
 * @method ctrlOnuDescription($interface, $state = 'enable')
 * @method deregOnu($interface)
 * @method clearPonPort($interface)
 * @method parseInterface($interface)
 * @method getDeviceCoreMeta()
 * @method getSupportedModules()
 * @method isModuleSupported($moduleName)
 * @method getDeviceStats()
 * @method resetPort()
 *
 * /**
 *
 *  Возвращает массив в виде
 *    [
 *       '1' => [
 *                 'interface' => [id=>, name=>],
 *                 '<module_name>' => ['data' => <module_data - any>, 'error' => <error - string> // В зависимости от вызываемых модулей, поля будут соответствующие
 *              ]
 *    ]
 *
 * @param $interfaces array Должен быть массив из ID интерфейсов
 * @param $modules array Должен быть массив из модулей
 * @return array
 * /
 * @method callModulesByInterfaces($interfaces, $modules, $from = 'cache')
 * @package WCC\Olts
 */
class Controller extends AbstractComponentController
    implements PollerSystemInterface,
    PollerFdbInterface,
    ControllerInterface,
    PollerInterfaceListInterface,
    PollerOntIdentificationInterface,
    PollerInterfaceStatusInterface,
    PollerOpticalStrengthInterface,
    PollerCountersInterface,
    PonPortLoadingInterface,
    PollerResourcesInterface,
    PollerUnregisteredOntsInterface
{

    protected $processorList = [
        [
            'model_keys' => [
                //ZTE devices
                'zte_c320_fw_1_2','zte_c320','zte_c300_fw_1_2','zte_c300','zte_c220',
                'zte_zxpon_olt_series','zte_c610_fw_12','zte_c600_fw_12',

                //V-Sol devices
                'v_solution_v1600d8','v_solution_v1600d16','v_solution_v1600d',
                'v_solution_v1600g', 'v_solution_v1600g1b',


                //GCOM OLTs
                'gcom_el5610_series','gcom_el5610_16p','gcom_el5610_08p','gcom_el5610_04p', 'gcom_el5610_series_old',

                //C-Data devices
                'c_data_fd1616','c_data_fd1608','c_data_fd1216s_r1','c_data_fd1208s',
                'c_data_fd1204sn','c_data_fd1108s','c_data_fd1104sn', 'c_data_fd1608_fw3', 'c_data_fd1616_fw3',
                'c_data_fd1604', 'c_data_fd1604_fw3', 'c_data_fd1601', 'c_data_fd1601_fw3',
                'c_data_fd1700s_fw3',

                //BDcom devices
                'bdcom_p3616_2te','bdcom_p3612_2te','bdcom_p3608b','bdcom_p3310d','bdcom_p3310c',
                'bdcom_p3310b','bdcom_p3310','bdcom_gp3600_series','bdcom_gp3600_16', 'bdcom_gp3600_08',
                'bdcom_gp3600_04', 'bdcom_p36xx_series',
            ],
            'processor' => DefaultProcessor::class,
        ],
        [
            'model_keys' => [
                //Huawei OLTs
                'huawei_ma5683t','huawei_ma5680t','huawei_ma5608t','huawei_ma5603t', 'huawei_ma5801', 'huawei_smart_ax',

            ],
            'processor' => HuaweiProcessor::class,
        ],
    ];

    /**
     * @var AbstractProcessor
     */
    protected $processor;


    function __construct(ComponentInjector $componentInjector, Logger $logger)
    {
        parent::__construct($componentInjector, $logger);
    }

    function setProcessor(Device $device)
    {
        foreach ($this->processorList as $procs) {
            if (in_array($device->getModel()->getKey(), $procs['model_keys'])) {
                $this->processor = App::getInstance()->getContainer()->get($procs['processor']);
                break;
            }
        }
        if(!$this->processor) {
            $this->processor = App::getInstance()->getContainer()->get(DefaultProcessor::class);
        }
        return $this;
    }

    function __get($name) {
        return $this->processor->$name;
    }
    function __set($name, $val) {
        $this->processor->$name = $val;
    }

    function __call($name, $arguments = [])
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return call_user_func_array(array($this->processor, $name), $arguments);
    }

    function setDevice(Device $device)
    {
        $this->setProcessor($device);
        return $this->processor->setDevice($device);
    }

    function setUser(User $user)
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->setUser($user);
    }

    function getCounters()
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->getCounters();
    }

    function getFdbTable()
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->getFdbTable();
    }

    function getInterfacesList(): array
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->getInterfacesList();
    }

    function getInterfaceStatuses()
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->getInterfaceStatuses();
    }

    function getOntIdentification()
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->getOntIdentification();
    }

    function getOpticalStrength()
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->getOpticalStrength();
    }

    function getResources($from = 'device')
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->getResources($from);
    }

    function getSystemInfo()
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->getSystemInfo();
    }
    function getPonPortLoadingStat()
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->getPonPortLoadingStat();
    }

    /**
     * Polled on a schedule now (see PollerUnregisteredOntsInterface) so the
     * "Unregistered ONTs" tab's default `from=store` read actually has
     * something to find, instead of the tab's old `from=cache` silently
     * firing a live device query on every open (confirmed live this
     * session — fromCache() falls back to fromDevice() on any cache miss,
     * and this module was never scheduled anywhere, so it missed every time).
     */
    function getUnregisteredOnts()
    {
        if(!$this->processor) {
            throw new \Exception("Device must be set first");
        }
        return $this->processor->getUnregisteredOnts('device');
    }

}
