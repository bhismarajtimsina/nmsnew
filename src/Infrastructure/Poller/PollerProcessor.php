<?php

namespace WCAA\Infrastructure\Poller;

use Monolog\Logger;
use Prometheus\CollectorRegistry;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Infrastructure\Poller\Interfaces\PollerArpsInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerBgpSessionsInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerCardsStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerCountersInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerFdbInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerLldpInfoInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceListInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOntVendorInfoInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerResourcesInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSensorsInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSfpOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSystemInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerUnregisteredOntsInterface;
use WCAA\Infrastructure\Poller\Interfaces\PonPortLoadingInterface;
use WCAA\Infrastructure\Poller\Pollers\ArpsPoller;
use WCAA\Infrastructure\Poller\Pollers\BgpSessionsPoller;
use WCAA\Infrastructure\Poller\Pollers\CardsStatusPoller;
use WCAA\Infrastructure\Poller\Pollers\CountersPoller;
use WCAA\Infrastructure\Poller\Pollers\DeviceInterfacesList;
use WCAA\Infrastructure\Poller\Pollers\DeviceInterfacesStatus;
use WCAA\Infrastructure\Poller\Pollers\FdbHistory;
use WCAA\Infrastructure\Poller\Pollers\LldpInfoPoller;
use WCAA\Infrastructure\Poller\Pollers\OntIdentification;
use WCAA\Infrastructure\Poller\Pollers\OntVendorInfo;
use WCAA\Infrastructure\Poller\Pollers\OpticalStrengthHistory;
use WCAA\Infrastructure\Poller\Pollers\PonPortLoadingPoller;
use WCAA\Infrastructure\Poller\Pollers\ResourcesPoller;
use WCAA\Infrastructure\Poller\Pollers\SensorsDataPoller;
use WCAA\Infrastructure\Poller\Pollers\SfpOpticalStrengthHistory;
use WCAA\Infrastructure\Poller\Pollers\UnregisteredOntsPoller;
use WCAA\Infrastructure\Poller\Pollers\WalkSystemInfo;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Interfaces\ControllerInterface;
use WCAA\Models\Pollers\PollerProcessing;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCAA\Storage\PollerData\PollerProcessingStorage;

class PollerProcessor
{
    protected $configuration = [
        'system' => [
            'interface' => PollerSystemInterface::class,
            'walker' => WalkSystemInfo::class,
        ],
        'interfaces_list' => [
            'interface' => PollerInterfaceListInterface::class,
            'walker' => DeviceInterfacesList::class,
        ],
        'ont_ident' => [
            'interface' => PollerOpticalStrengthInterface::class,
            'walker' => OntIdentification::class,
        ],
        'sys_resources' => [
            'interface' => PollerResourcesInterface::class,
            'walker' => ResourcesPoller::class,
        ],
        'interfaces_status' => [
            'interface' => PollerInterfaceStatusInterface::class,
            'walker' => DeviceInterfacesStatus::class,
        ],
        'fdb_table' => [
            'interface' => PollerFdbInterface::class,
            'walker' => FdbHistory::class,
        ],
        'sfp_optical_strength' => [
            'interface' => PollerSfpOpticalStrengthInterface::class,
            'walker' => SfpOpticalStrengthHistory::class,
        ],
        'lldp_info' => [
            'interface' => PollerLldpInfoInterface::class,
            'walker' => LldpInfoPoller::class,
        ],
        'optical_strength' => [
            'interface' => PollerOpticalStrengthInterface::class,
            'walker' => OpticalStrengthHistory::class,
        ],
        'counters' => [
            'interface' => PollerCountersInterface::class,
            'walker' => CountersPoller::class,
        ],
        'bgp_sessions' => [
            'interface' => PollerBgpSessionsInterface::class,
            'walker' => BgpSessionsPoller::class,
        ],
        'arp_table' => [
            'interface' => PollerArpsInterface::class,
            'walker' => ArpsPoller::class,
        ],
        'pon_port_loading' => [
            'interface' => PonPortLoadingInterface::class,
            'walker' => PonPortLoadingPoller::class,
        ],
        'sensors_data' => [
            'interface' => PollerSensorsInterface::class,
            'walker' => SensorsDataPoller::class,
        ],
        'ont_vendor_info' => [
            'interface' => PollerOntVendorInfoInterface::class,
            'walker' => OntVendorInfo::class,
        ],
        'cards_status' => [
            'interface' => PollerCardsStatusInterface::class,
            'walker' => CardsStatusPoller::class,
        ],
        'unregistered_onts' => [
            'interface' => PollerUnregisteredOntsInterface::class,
            'walker' => UnregisteredOntsPoller::class,
        ],
    ];

    /**
     * @var App
     */
    protected $app;

    /**
     * @var Device
     */
    protected $device;

    /**
     * @var User
     */
    protected $user;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var OutputInterface
     */
    protected $consoleOutput;

    /**
     * @var \Throwable[]
     */
    protected $throwables;

    /**
     * @var PollerProcessingStorage
     */
    protected $pollerStorage;

    /**
     * @var EventObserverStorage
     */
    protected $events;

    /**
     * @var PrometheusMetrics
     */
    protected $metrics;

    /**
     * @return \string[][]
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    /**
     * @return \Throwable[]
     */
    public function getThrowables(): array
    {
        return $this->throwables;
    }

    /**
     * @param \string[][] $configuration
     * @return PollerProcessor
     */
    public function setConfiguration(array $configuration): PollerProcessor
    {
        $this->configuration = $configuration;
        return $this;
    }

    /**
     * @return App
     */
    public function getApp(): App
    {
        return $this->app;
    }

    /**
     * @param App $app
     * @return PollerProcessor
     */
    public function setApp(App $app): PollerProcessor
    {
        $this->app = $app;
        return $this;
    }


    function __construct(EventObserverStorage $events, PrometheusMetrics $promCollector, PollerProcessingStorage $storage, App $app, Logger $logger)
    {
        $this->events = $events;
        $this->metrics = $promCollector;
        $this->app = $app;
        $this->pollerStorage = $storage;
        $this->logger = $logger->withName('poller');
        $this->logger->info("PollerEvents initialized");
    }

    /**
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * @param Logger $logger
     * @return PollerProcessor
     */
    public function setLogger(Logger $logger): PollerProcessor
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * @return OutputInterface
     */
    public function getConsoleOutput(): OutputInterface
    {
        return $this->consoleOutput;
    }

    /**
     * @param OutputInterface $consoleOutput
     * @return PollerProcessor
     */
    public function setConsoleOutput(OutputInterface $consoleOutput): PollerProcessor
    {
        $this->consoleOutput = $consoleOutput;
        return $this;
    }


    /**
     * @return Device
     */
    public function getDevice(): Device
    {
        return $this->device;
    }

    /**
     * @param Device $device
     * @return PollerProcessor
     */
    public function setDevice(Device $device): PollerProcessor
    {
        $collect = clone $this;
        $collect->device = $device;
        return $collect;
    }

    /**
     * @return User
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @param User $user
     * @return PollerProcessor
     */
    public function setUser(User $user): PollerProcessor
    {

        $collect = clone $this;
        $collect->user = $user;
        return $collect;
    }

    /**
     * @return ControllerInterface
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function getController()
    {
        /**
         * @var  $controller ControllerInterface
         */
        if ($this->device->getModel()->getController() === null) {
            throw new \Exception("Device {$this->device->getName()} with model {$this->device->getModel()->getName()} doesn't have controller");
        }
        $controller = $this->app->getContainer()->get($this->device->getModel()->getController());
        if ($controller instanceof ControllerInterface) {
            return $controller
                ->setDevice($this->device)
                ->setUser($this->user);
        } else {
            throw new \Exception("Controller setted in device model must implement ControllerInterface");
        }
    }

    public function poll($pollers = [], $ignoreChecks = false)
    {
        $this->metrics->setGauge('system_poller_last_time', time(), [
            'ip' => $this->device->getIp(),
            'name' => $this->device->getName(),
            'model' => $this->device->getModel()->getName(),
        ]);

        $this->throwables = [];
        try {
            $controller = $this->getController();
        } catch (\Throwable $e) {
            $this->log('error', $e->getMessage());
            $this->processControllerException($e);
            return [];
        }
        $this->log("debug", "Controller for device {$this->device->getName()} success loaded");
        if ($pollers) {
            $this->log("debug", "Use pollers list from arguments", ['pollers' => $pollers]);
            $allowedPollers = $pollers;
        } else {
            $allowedPollers = $this->getPollersList($this->device, $ignoreChecks);
        }
        if (!$allowedPollers) {
            $this->log("warning", "Not avaliable pollers");
        }
        $this->log("debug", "Start poll info from device {$this->device->getIp()}", [
            'pollers' => $allowedPollers,
        ]);
        $startCollect = microtime(true);
        foreach ($this->configuration as $name => $objs) {
            if (!in_array($name, $allowedPollers)) {
                continue;
            }
            $this->log("debug", "Start walker {$name} for device {$this->getDevice()->getName()}");
            $collectData = $this->pollerStorage->addOrUpdate(
                (new PollerProcessing())
                    ->setDevice($this->device)
                    ->setStartAt(date("Y-m-d H:i:s"))
                    ->setPoller($name)
            );
            $this->events->notify('poller:started', [
                'name' => $name,
                'device' => $this->device->getAsArrayLite(),
                'id' => $collectData->getId(),
            ]);
            try {
                $startAt = microtime(true);
                if ($controller instanceof $objs['interface']) {
                    $this->app->getContainer()->get($objs['walker'])->poll($this->device, $controller);
                } else {
                    throw new \Exception("Controller " . get_class($controller) . " doesn't not implement interface {$objs['interface']}");
                }
                $this->pollerStorage->update($collectData
                    ->setStopAt(date("Y-m-d H:i:s"))
                    ->setStatus(PollerProcessing::STATUS_SUCCESS)
                );
                $sec = round(microtime(true) - $startAt, 3);
                if ($this->consoleOutput) $this->consoleOutput->writeln("Walker {$name} finished for device {$this->device->getIp()}. Spent time = {$sec} sec");
                $this->events->notify('poller:finished', [
                    'name' => $name,
                    'device' => $this->device->getAsArrayLite(),
                    'id' => $collectData->getId(),
                    'status' => 'success',
                    'worked_time' => $sec,
                ]);
            } catch (\Throwable $e) {
                $sec = round(microtime(true) - $startAt, 3);
                $this->pollerStorage->update($collectData
                    ->setStopAt(date("Y-m-d H:i:s"))
                    ->setStatus(PollerProcessing::STATUS_FAILED)
                    ->setError($e)
                );
                $this->processWalkException($name, $objs['walker'], $e);
                $this->events->notify('poller:finished', [
                    'name' => $name,
                    'device' => $this->device->getAsArrayLite(),
                    'status' => 'failed',
                    'id' => $collectData->getId(),
                    'worked_time' => $sec,
                ]);
            }
        }
        $finishedCollect = round(microtime(true) - $startCollect, 3);
        if ($this->consoleOutput) $this->consoleOutput->writeln("<info>NotificationSender info for device {$this->device->getIp()} finished! Spent time = {$finishedCollect} sec</info>");
        return $allowedPollers;
    }

    protected function processControllerException($e)
    {
        $this->throwables[] = $e;
        $this->logger
            ->error("Error get controller on device {$this->device->getName()} ({$this->device->getIp()}) with message: {$e->getMessage()}",
                [
                    'device' => $this->device->getAsArray(),
                    'error_trace' => $e->getTraceAsString(),
                ]
            );
        if ($this->consoleOutput) {
            $this->consoleOutput->writeln("<comment>Error get controller on device {$this->device->getName()} ({$this->device->getIp()}) with message: {$e->getMessage()}</comment>");
            if ($this->consoleOutput->isVerbose()) {
                $this->consoleOutput->writeln("Error: 
    message: {$e->getMessage()}
    code: {$e->getCode()}
    file: {$e->getFile()}
    line: {$e->getLine()}
                       ");
                $this->consoleOutput->writeln("StackTrace:");
                $this->consoleOutput->writeln($e->getTraceAsString());
            }
        }
    }

    protected function processWalkException($name, $walkerName, $e)
    {
        $this->throwables[] = $e;
        $this->logger
            ->error("Error execute poller {$name} on device {$this->device->getName()} ({$this->device->getIp()}) with message: {$e->getMessage()}",
                [
                    'device' => $this->device->getAsArray(),
                    'poller_name' => $name,
                    'poller_walker' => $walkerName,
                    'error_trace' => $e->getTraceAsString(),
                ]
            );
        if ($this->consoleOutput) {
            $this->consoleOutput->writeln("<error>Error execute poller {$name} on device {$this->device->getName()} ({$this->device->getIp()}) with message: {$e->getMessage()}</error>");
            if ($this->consoleOutput->isVerbose()) {
                $this->consoleOutput->writeln("Error: 
    message: {$e->getMessage()}
    code: {$e->getCode()}
    file: {$e->getFile()}
    line: {$e->getLine()}
                       ");
                $this->consoleOutput->writeln("StackTrace:");
                $this->consoleOutput->writeln($e->getTraceAsString());
            }
        }
    }

    protected function log($level, $message, $data = [])
    {
        switch ($level) {
            case 'debug':
                $this->logger->debug($message, $data);
                if ($this->consoleOutput && $this->consoleOutput->isVerbose())
                    $this->consoleOutput->writeln($message);
                break;
            case 'warning':
                $this->logger->warning($message, $data);
                if ($this->consoleOutput)
                    $this->consoleOutput->writeln("<comment>" . $message . "</comment>");
                break;
            case 'error':
                $this->logger->error($message, $data);
                if ($this->consoleOutput)
                    $this->consoleOutput->writeln("<error>" . $message . "</error>");
                break;
        }

    }


    function getPollersList(Device $device, $ignoreChecks = false)
    {
        if (isset($device->getParams()['poller_config']) && $device->getParams()['poller_config']) {
            $config = $device->getParams()['poller_config'];
        } else if (isset($device->getModel()->getParams()['poller_config']) && $device->getModel()->getParams()['poller_config']) {
            $config = $device->getModel()->getParams()['poller_config'];
        } else if ($pollers = $device->getModel()->getPollers()) {
            $config = [];
            foreach ($pollers as $poller => $interval) {
                $config[$poller] = [
                    'enabled' => true,
                    'interval' => $interval,
                ];
            }
        } else {
            $this->log("error", "Polling for model {$device->getModel()->getName()} not supported");
            return [];
        }

        $config = array_filter($config, function ($c) {
            return $c['enabled'];
        });

        if (!$config) {
            $this->log("warning", "NotificationSender for device {$device->getIp()} or model ({$device->getModel()->getName()}) disabled!");
            return [];
        }

        if ($ignoreChecks) {
            $pollList = [];
            foreach ($config as $pollName => $_) {
                $pollList[] = $pollName;
            }
            return $pollList;
        }

        //Exclude pollers by timeout
        foreach ($this->pollerStorage->getLatests($device) as $last) {
            $time = \DateTime::createFromFormat("Y-m-d H:i:s", $last->getStartAt())->getTimestamp();
            if (!isset($config[$last->getPoller()])) {
                $this->log("error", "Poller with name {$last->getPoller()} not found in supported pollers for device {$this->device->getIp()}");
                continue;
            }
            $interval = $config[$last->getPoller()]['interval'];
            if ($interval > (time() - $time)) {
                $after = $interval - (time() - $time);
                $this->log("warning", "Poller {$last->getPoller()} polled early, next poll after {$after}sec");
                unset($config[$last->getPoller()]);
            }
        }

        $pollList = [];
        foreach ($config as $pollName => $_) {
            $pollList[] = $pollName;
        }
        return $pollList;
    }
}