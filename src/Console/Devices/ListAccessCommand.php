<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\Devices\DeviceAccessStorage;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListAccessCommand extends AbstractCommand
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
        $this->setName("device-access:list")
            ->setDescription("Table of device accesses")
            ->addOption("output", "o", InputOption::VALUE_OPTIONAL, "Output format. support: table, yaml, json", "table");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $accesses = $this->storage->fetchAll();
        switch ($input->getOption('output')) {
            case 'table':
                $table = new Table($output);
                $table->setHeaders([
                    'Id',
                    'Name',
                    'RO',
                    'RW',
                    'Login',
                    'Password',
                ]);
                foreach ($accesses as $access) {
                    $table->addRow([
                        $access->getId(),
                        $access->getName(),
                        $access->getPublicCommunity(),
                        $access->getPrivateCommunity(),
                        $access->getLogin(),
                        $access->getPassword()
                    ]);
                }
                $table->render();
                break;
            case 'json':
                $accesses = array_map(function ($e) {
                    return $e->getAsArray();
                }, $accesses);
                $output->writeln($this->toJson($accesses));
                break;
            case 'yaml':
                $accesses = array_map(function ($e) {
                    return $e->getAsArray();
                }, $accesses);
                $output->writeln($this->toYaml($accesses));
                break;
        }
        return self::SUCCESS;
    }
}