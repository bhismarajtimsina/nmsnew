<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateAccessCommand extends AbstractCommand
{
    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $storage;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    protected $question;

    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("device-access:edit")
            ->setDescription("Update device access")
            ->addArgument("id", InputArgument::REQUIRED, "Access ID for update");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareForQuestions($input, $output);
        $output->writeln("Device access updating");
        $output->writeln("-------------------------------------");
        $access = $this->storage->getById($input->getArgument('id'));
        $access->setName($this->question("Name (Default: {$access->getName()})", $access->getName()));
        $access->setPublicCommunity($this->question("Community (Default: {$access->getPublicCommunity()})", $access->getPublicCommunity()));
        $access->setLogin($this->question("Login (Default: {$access->getLogin()})", $access->getLogin()));
        $access->setPassword($this->question("Password (Default: {$access->getPassword()})", $access->getPassword()));
        $this->storage->update($access);
        $output->writeln("
Access success updated!        
        ");
        $output->writeln("-------------------------------------");
        $output->writeln($this->toJson($access->getAsArray()));
        $output->writeln("-------------------------------------");
        return self::SUCCESS;
    }
}