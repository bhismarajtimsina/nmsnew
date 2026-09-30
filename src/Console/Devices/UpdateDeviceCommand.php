<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use InvalidArgumentException;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateDeviceCommand extends AbstractCommand
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
        $this->setName("device:update")
            ->addArgument("id")
            ->setDescription("Edit device");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Device editing");
        $output->writeln("-------------------------------------");
        $device = $this->storage->getById($input->getArgument('id'));
        $device->setName($this->question("*Name (Current: {$device->getName()})", $device->getName(), function ($answer) {
            if (!$answer) throw new RuntimeException("Name can't be empty");
            return $answer;
        }));
        $device->setIp($this->question("*IP address (Current: {$device->getIp()})", $device->getIp(), function ($answer) {
            if (!$answer) throw new RuntimeException("Name can't be empty");
            if (!preg_match('/[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}/', $answer)) throw new InvalidArgumentException("Incorrect IP address");
            return $answer;
        }));
        $device->setModel($this->choiseQuestion("*Choose model (Current: {$device->getModel()->getName()})", $this->modelStorage->fetchAll(), $device->getModel()));
        $device->setAccess($this->choiseQuestion("*Choose access (Current: {$device->getAccess()->getName()})", $this->accessStorage->fetchAll(), $device->getAccess()));
        $device->setMac($this->question("MAC address (Current: {$device->getMac()})", $device->getMac()));
        $device->setSerial($this->question("Serial (Current: {$device->getSerial()})", $device->getSerial()));
        $device->setDescription($this->question("Description"));

        if($this->confirm("WARNING!!!
Old parameters will be erased!!! 
Are you want add extra parameters")) {
            $output->writeln("Current parameters:");
            foreach ($device->getParams() as $key=>$param) {
                $output->writeln("{$key} => name={$param['name']}, value={$param['value']}");
            }
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
        $this->storage->update($device);
        $output->writeln("Access success updated");
        $output->writeln("-------------------------------------");
        $output->writeln($this->toJson($device->getAsArray()));
        $output->writeln("-------------------------------------");
        return self::SUCCESS;
    }
}