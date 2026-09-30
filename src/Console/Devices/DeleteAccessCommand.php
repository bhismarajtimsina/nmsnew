<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use InvalidArgumentException;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteAccessCommand extends AbstractCommand
{
    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $storage;

    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("device-access:delete")
            ->setDescription("Delete access device")
            ->addArgument("id", InputArgument::REQUIRED, "ID for delete access");
    }
    function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('id');
        if($access = $this->storage->getById( $id)) {
            if($this->confirm("WARNING!!!   This will remove all devices using this access!
            
Are you sure to delete access '{$access->getName()}' with ID {$id}?")) {
                $this->storage->delete($access);
                $output->writeln("Access success deleted!");
            }
        } else {
            throw new InvalidArgumentException("Access with ID $id not found");
        }

        return self::SUCCESS;
    }
}