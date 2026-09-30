<?php


namespace WCC\AutoDiscovery\Console;


use Cocur\BackgroundProcess\BackgroundProcess;
use DI\Annotation\Inject;
use Monolog\Logger;
use SwitcherCore\Modules\BDcom\InterfacesList;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Infrastructure\Poller\PollerProcessor;
use WCAA\Infrastructure\SystemActionLogger;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Storage\PollerData\PollerProcessingStorage;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\AutoDiscovery\Models\AutoDiscoveryNetwork;
use WCC\AutoDiscovery\Storage\AutoDiscoveryNetworksStorage;

class Discovery extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;


    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $modelStorage;

    /**
     * @Inject
     * @var SystemActionLogger
     */
    protected $sysLogger;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var AutoDiscoveryNetworksStorage
     */
    protected $autoDiscoveryStorage;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $deviceAccessStorage;


    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var array
     */
    public function config()
    {
        $this->setName("scan")
            ->addArgument("cidr", InputArgument::OPTIONAL, "Network in CIDR to scan")
            ->addOption("access","a", InputOption::VALUE_OPTIONAL, "Device access ID")
            ->addOption("group", "g",  InputOption::VALUE_OPTIONAL, "Device group ID for adding new devices")
            ->setDescription("Run autodiscovery scanner");
    }


    public function exec(InputInterface $input, OutputInterface $output)
    {
       $this->input = $input;
       $this->output = $output;
       $networks = [];
       if($cidr = $input->getArgument('cidr')) {
           if(!$input->getOption('access')) {
               throw new MissingInputException("Device access id is required");
           }
           if(!$input->getOption('group')) {
               throw new MissingInputException("Device group id is required");
           }
           $networks[] = (new AutoDiscoveryNetwork())
               ->setCidr($cidr)
               ->setDeviceAccess($this->deviceAccessStorage->getById($input->getOption('access')))
               ->setDeviceGroup($this->deviceGroupStorage->getById($input->getOption('group')));
       } else {
            $networks = $this->autoDiscoveryStorage->fetchAll();
       }
       $founded = [];
       foreach ($networks as $network) {
           $output->writeln("Start scan network {$network->getCidr()} with access {$network->getDeviceAccess()->getName()}");
           $founded[] = $this->scanNetwork($network);
       }
       $output->writeln("Scan finished");
       $this->addNewDevices($founded);
       $output->writeln("Finished!");
       return self::SUCCESS;
    }
    public function addNewDevices($founded) {
        foreach ($founded as $found) {
            /**
             * @var AutoDiscoveryNetwork $net
             */
            $net = $found['network'];
            foreach ($found['ips'] as $ip) {
                try {
                    $isNewDevice = false;
                    try {
                        $this->deviceStorage->getByIp($ip);
                    } catch (\Exception $e) {
                        $isNewDevice = true;
                        $this->output->writeln("Found new device with IP {$ip}");
                    }
                    if (!$isNewDevice) continue;

                    $device = (new Device())
                        ->setIp($ip)
                        ->setAccess($net->getDeviceAccess());

                    $system = $this->switcherCore->getCore($device)->action('system');
                    $model = $this->modelStorage->getByKey($system['meta']['key']);
                    if (!$model) {
                        throw new SupportException("device '{$system['meta']['name']}' not supported by agent at this time");
                    }
                    $device->setModel($model)
                        ->setGroup($net->getDeviceGroup())
                        ->setDescription("Autodiscovered at " . date("Y-m-d H:i:s"))
                        ->setLocation($system['location'])
                        ->setMac(isset($system['mac_addr']) ? $system['mac_addr'] : '')
                        ->setSerial(isset($system['serial_num']) ? $system['serial_num'] : '')
                        ->setName($system['name']);
                    $device = $this->deviceStorage->add($device);
                    $this->sysLogger->success(
                      'autodiscovery:added',
                      "Added new device with ip {$device->getIp()}",
                        [
                            'network' => $net->getCidr(),
                            'access' => $net->getDeviceAccess()->getName(),
                            'device_group' => $net->getDeviceGroup()->getName(),
                        ],
                        $device,
                        App::getInstance()->getSysUser(),
                    );
                } catch (\Throwable $t) {
                    $this->output->writeln("scan-err: {$t->getMessage()}");
                    $this->logger->error("scan-err: {$t->getMessage()}", [
                        'error' => [
                            'message' => $t->getMessage(),
                            'code'=> $t->getCode(),
                            'line' => "{$t->getFile()}:{$t->getLine()}",
                            'trace' => $t->getTraceAsString(),
                        ],
                        'device' => $device->getAsArray(),
                        'net' => $net->getAsArray(),
                    ]);
                }
            }
        }
    }

    public function scanNetwork(AutoDiscoveryNetwork $network)
    {
        $cmd = __DIR__ . "/../bin/scan --concurrency=700 snmp --community {$network->getDeviceAccess()->getPublicCommunity()} {$network->getCidr()}";
        $this->output->writeln("Start execute command '$cmd'");
        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(1800);
        $process->run();
        // executes after the command finishes
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        if($err = $process->getErrorOutput()) {
            throw new SupportException("Error execute command '{$cmd}': {$err}");
        };
        $output = $process->getOutput();
        $this->output->writeln("Execute command '$cmd' finished");
        $lines = explode("\n", $output);
        $result = [];
        foreach ($lines as $line) {
            if(!$line) continue;
            if(strpos($line, "#") !== false) {
                $line = str_replace("#", "", trim($line));
                $this->output->writeln($line);
                continue;
            }
            list($ip) = explode(";", $line);
            $result[] = $ip;
        }
        return [
            'ips' => $result,
            'network' => $network,
        ];
    }
}
