<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use WCAA\Console\AbstractCommand;
use WCAA\Models\Devices\DeviceAccess;
use WCAA\Storage\Devices\DeviceAccessStorage;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AddAccessCommand extends AbstractCommand
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
        $this->setName("device-access:add")
            ->setDescription("Create new device access");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Device access adding, please fill form");
        $output->writeln("-------------------------------------");
        $access = new DeviceAccess();
        $access->setName($this->question("Name (displayed)", '', function ($answer) {
            if (!$answer) throw new RuntimeException("Name can't be empty");
            return $answer;
        }));
        $access->setPublicCommunity($this->question("SNMP community", '', function ($answer) {
            if (!$answer) throw new RuntimeException("community can't be empty");
            return $answer;
        }));
        $access->setLogin($this->question("Login", '', function ($answer) {
            if (!$answer) throw new RuntimeException("login can't be empty");
            return $answer;
        }));
        $access->setPassword($this->question("Password", '', function ($answer) {
            if (!$answer) throw new RuntimeException("password can't be empty");
            return $answer;
        }));
        $this->storage->add($access);
        $output->writeln("Access success created");

        $output->writeln("-------------------------------------");
        $output->writeln($this->toJson($access->getAsArray()));
        $output->writeln("-------------------------------------");
        return self::SUCCESS;
    }
}