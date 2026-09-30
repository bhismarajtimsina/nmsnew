<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use InvalidArgumentException;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceStorage;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteDeviceGroup extends AbstractCommand
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $groupStorage;

    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("device:delete-in-group")
            ->setDescription("Delete device group")
            ->addArgument("id", InputArgument::REQUIRED, "ID for delete");
    }
    function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('id');
        $group = $this->groupStorage->getById($id);

        $devices = $this->storage->fetchByDeviceGroup($group);
        $this->output->writeln("Found ".count($devices)." devices in group");
        foreach ($devices as $device) {
            $this->output->writeln("Deleting device with ID: ".$device->getId() .  " - ".$device->getIp());
            $this->storage->delete($device);
        }

        return self::SUCCESS;
    }
}