<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use InvalidArgumentException;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use WCAA\Console\AbstractCommand;
use WCAA\Exceptions\SupportException;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\UsersideIntegration\Controllers\UsersideApiClient;

class ImportDevicesCommand extends AbstractCommand
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;
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
     * @var DeviceModelStorage
     */
    protected $deviceModelStorage;

    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $modelStorage;

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;

    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("device:import")
            ->addArgument("path", InputArgument::REQUIRED, "Pathfile")
            ->addOption("group-id", 'g', InputOption::VALUE_OPTIONAL, "Group ID to add new devices", '-1')
            ->addOption("separator", 's', InputOption::VALUE_OPTIONAL, "CSV field separator", ',')
            ->setDescription("Import devices from CSV file")
            ->addUsage("wca device:import
            File must contain next format: 
            <IP>,<Login>,<Password>,<Public community>,<Private community>,<Name|can be empty>,<support model key (not required)>");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $deviceGroup = $this->deviceGroupStorage->getById($input->getOption('group-id'));
        $devices = $this->readCSV($input->getArgument('path'), $input->getOption('separator'));
        $accessesFromImport = $this->fetchDeviceAccessesFromAllDevices($devices);


        $this->log("INFO", "CSV: found " . count($devices) . " devices, " . count($accessesFromImport) . " unique accesses");

        $this->log("INFO", "Loading existing accesses from DB...");
        $existingAccesses = [];
        foreach ($this->deviceAccessStorage->fetchAll() as $access) {
            $key = sha1($access->getPublicCommunity() . '-' . $access->getPrivateCommunity() . '-' . $access->getLogin() . '-' . $access->getPassword());
            $existingAccesses[$key] = $access;
        }

        $this->log("INFO", "Existing accesses: " . count($existingAccesses));

        $this->log("INFO", "Creating missing accesses...");
        foreach ($accessesFromImport as $access) {
            $key = sha1($access->getPublicCommunity() . '-' . $access->getPrivateCommunity() . '-' . $access->getLogin() . '-' . $access->getPassword());

            if (!isset($existingAccesses[$key])) {
                try {
                    $this->log("INFO", "Adding new access: " . $access->getName());
                    $existingAccesses[$key] = $this->deviceAccessStorage->add($access);
                } catch (\Exception $e) {
                    $this->log("WARN", "Failed to add access: " . $e->getMessage());
                }
            }
        }

        $this->log("INFO", "Loading existing devices from DB...");
        $existingDevices = [];
        foreach ($this->deviceStorage->fetchAll() as $device) {
            $existingDevices[$device->getIp()] = $device;
        }
        $this->log("INFO", "Existing devices: " . count($existingDevices));

        $this->log("INFO", "Adding new devices from CSV...");
        foreach ($devices as $device) {
            $deviceIp = $device['ip'];

            if (isset($existingDevices[$deviceIp])) {
                $this->log("INFO", "Device {$deviceIp} already exists, skipping...");
                continue;
            }

            $accessKey = sha1($device['public_community'] . '-' . $device['private_community'] . '-' . $device['login'] . '-' . $device['password']);
            if (!isset($existingAccesses[$accessKey])) {
                $this->log("WARN", "Access not found for device {$deviceIp}, skipping...");
                continue;
            }

            try {
                $newDevice = (new Device())
                    ->setIp($deviceIp)
                    ->setAccess($existingAccesses[$accessKey])
                    ->setName($device['name'] ?: $deviceIp)
                    ->setGroup($deviceGroup);
                if (!empty($device['model_key'])) {
                    $model = $this->deviceModelStorage->getByKey($device['model_key']);
                    $newDevice->setModel($model);
                } else {
                    $this->log('INFO', "Device {$deviceIp} doesn't have a support model key, try to detect..." );
                    $system = $this->switcherCore->getCore($newDevice)->action('system');
                    $model = $this->deviceModelStorage->getByKey($system['meta']['key']);
                    if (!$model) {
                        throw new SupportException("device '{$system['meta']['name']}' not supported by agent at this time");
                    }
                    $newDevice->setModel($model)
                        ->setMac(isset($system['mac_addr']) ? $system['mac_addr'] : '')
                        ->setSerial(isset($system['serial_num']) ? $system['serial_num'] : '');
                }

                $this->deviceStorage->add($newDevice);
                $this->log("INFO", "Added new device {$deviceIp}");
            } catch (\Exception $e) {
                $this->log("ERROR", "Failed to add device {$deviceIp}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    function readCSV($filePath, $separator = ',')
    {
        $result = [];
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \Exception("File does not exist or is not readable: $filePath");
        }

        $lineNumber = 0;
        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);
                if ($line === '') continue;

                $fields = explode($separator, $line);

                // Allow 6 or 7 fields (7th is optional: model key)
                if (count($fields) < 6) {
                    throw new \Exception("Invalid format on line $lineNumber: '$line'");
                }

                $result[] = [
                    'ip' => trim($fields[0]),
                    'login' => trim($fields[1]),
                    'password' => trim($fields[2]),
                    'public_community' => trim($fields[3]),
                    'private_community' => trim($fields[4]),
                    'name' => trim($fields[5], ' "'),
                    'model_key' => isset($fields[6]) ? trim($fields[6], ' "') : null,
                ];
            }
            fclose($handle);
        } else {
            throw new \Exception("Failed to open the file: $filePath");
        }

        return $result;
    }


    protected function fetchDeviceAccessesFromAllDevices(array $devices)
    {
        $accesses = [];

        foreach ($devices as $device) {
            $accessKey = sha1("{$device['public_community']}-{$device['private_community']}-{$device['login']}-{$device['password']}");
            $uniq = substr(sha1($accessKey), 0, 6);

            $accesses[$accessKey] = (new DeviceAccess())
                ->setLogin($device['login'])
                ->setPassword($device['password'])
                ->setPublicCommunity($device['public_community'])
                ->setPrivateCommunity($device['private_community'])
                ->setName("{$device['public_community']} (sync from import - $uniq)");
        }

        return array_values($accesses);
    }


    function log($level, $msg, $tech = null)
    {
        if ($this->output) {
            $th = '';
            if ($tech) {
                $th = json_encode($tech, JSON_UNESCAPED_UNICODE);
            }
            $dateTime = date("Y-m-d H:i:s");
            switch ($level) {
                case 'INFO':
                    if ($this->output->isVerbose()) {
                        $this->output->writeln("$dateTime [INFO] $msg $th");
                    }
                    break;
                case 'DEBUG':
                    if ($this->output->isVerbose()) {
                        $this->output->writeln("$dateTime [DEBUG] $msg $th");
                    }
                    break;
                case 'ERR':
                case 'ERROR':
                    $this->output->writeln("<error>$dateTime [ERR] $msg $th</error>");
                    break;
                case 'WARN':
                case 'WARNING':
                    $this->output->writeln("<comment>$dateTime [WARN] $msg $th</comment>");
                    break;
                default:
                    $this->output->writeln("$dateTime [INFO] $msg $th");
            }
        }
    }
}