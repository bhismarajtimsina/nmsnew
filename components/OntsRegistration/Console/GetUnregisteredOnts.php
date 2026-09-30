<?php


namespace WCC\OntsRegistration\Console;


use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\OntsRegistration\Controllers\MacrosGateway;

class GetUnregisteredOnts extends AbstractComponentCommand
{
    /**
     * @Inject
     * @var MacrosGateway
     */
    protected $control;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    function config()
    {
        $this->setName('get-unregistered-onts')
            ->addArgument("ip", InputArgument::OPTIONAL, "Device IP. Set device IP for search by one device")
            ->setDescription("Get unregistered onts");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $this->control->setUser(App::getInstance()->getSysUser());
        if ($input->getArgument('ip')) {
            $output->writeln("Start checking OLT with ip {$input->getArgument('ip')}");
            $responses = $this->control->getUnregistered($this->deviceStorage->getByIp($input->getArgument('ip')), 'device');
        } else {
            $output->writeln("Start checking all OLTs");
            $responses = $this->control->getUnregistered(null, 'device');
        }
        $table = new Table($output);
        $table->setHeaders([
            'Ip',
            'Name',
            'Interface',
            'Type',
            'Serial',
            'Mac',
            'Model',
            'Reg time',
        ]);
        foreach ($responses as $response) {
            $table->addRow([
               $response['device']['ip'],
               $response['device']['name'],
               $response['interface']['name'],
               isset($response['interface']['_technology']) ? $response['interface']['_technology'] : '',
               isset($response['serial']) ? $response['serial'] : '',
               isset($response['mac_address']) ? $response['mac_address'] : '',
               isset($response['model']) ? $response['model'] : '',
               isset($response['reg_time']) ? $response['reg_time'] : '',
            ]);
        }
        $table->render();
        return self::SUCCESS;
    }

}