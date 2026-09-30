<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use InvalidArgumentException;
use WCAA\Console\AbstractCommand;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AddDeviceCommand extends AbstractCommand
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $storage;
    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $accessStorage;
    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $modelStorage;

    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("device:add")
            ->setDescription("Create new device");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Device adding, please fill form");
        $output->writeln("-------------------------------------");
        $device = new Device();
        $device->setName($this->question("*Name (displayed)", '', function ($answer) {
            if (!$answer) throw new RuntimeException("Name can't be empty");
            return $answer;
        }));
        $device->setIp($this->question("*IP address", '', function ($answer) {
            if (!$answer) throw new RuntimeException("Name can't be empty");
            if (!preg_match('/[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}/', $answer)) throw new InvalidArgumentException("Incorrect IP address");
            return $answer;
        }));
        $device->setModel($this->choiseQuestion("*Choose model", $this->modelStorage->fetchAll()));
        $device->setAccess($this->choiseQuestion("*Choose access", $this->accessStorage->fetchAll()));
        $device->setMac($this->question("MAC address"));
        $device->setSerial($this->question("Serial"));
        $device->setDescription($this->question("Description"));

        if($this->confirm("Are you want add extra parameters")) {
            $params = [];
            while (true) {
                $key = $this->question("Parameter key (allow only A-Za-z0-9 and _)", '', function($answer) {
                    if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,}$/', $answer)) throw new RuntimeException("Allow only A-Za-z0-9 and _. First symbol must be letter");
                    return $answer;
                });
                $name = $this->question("Displayed name", '', function($answer) {
                    if(!trim($answer)) throw new RuntimeException("Name can't be empty");
                    return $answer;
                });
                $value = $this->question("Value", '', function($answer) {
                    if(!trim($answer)) throw new RuntimeException("Value can't be empty");
                    return $answer;
                });
                $params[$key] = [
                  'name' => $name,
                  'value' => $value,
                ];
                $output->writeln("Parameter $key success added!");
                if(!$this->confirm("Add another parameter?", false)) {
                    break;
                }
            }
            $device->setParams($params);
        }
        $this->storage->add($device);
        $output->writeln("Device success created");

        $output->writeln("-------------------------------------");
        $output->writeln($this->toJson($device->getAsArray()));
        $output->writeln("-------------------------------------");
        return self::SUCCESS;
    }
}