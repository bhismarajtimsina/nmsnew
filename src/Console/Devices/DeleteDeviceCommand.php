<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use InvalidArgumentException;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\Devices\DeviceStorage;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteDeviceCommand extends AbstractCommand
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $storage;

    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("device:delete")
            ->setDescription("Delete device")
            ->addArgument("id", InputArgument::REQUIRED, "ID of device for delete");
    }
    function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('id');
        if($device = $this->storage->getById($id)) {
            if($this->confirm("WARNING!!!   This will remove all relations using this device!
            
Are you sure to delete device {$device->getName()} with ID {$id}?")) {
                $this->storage->delete($device);
                $output->writeln("Device success deleted!");
            }
        } else {
            throw new InvalidArgumentException("Device with ID $id not found");
        }

        return self::SUCCESS;
    }
}