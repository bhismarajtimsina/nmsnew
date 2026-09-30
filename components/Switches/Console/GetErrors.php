<?php


namespace WCC\Switches\Console;


use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Switches\Controllers\SwitchesController;

class GetErrors extends AbstractComponentCommand
{
    /**
     * @Inject
     * @var User
     */
    protected $user;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var SwitchesController
     */
    protected $controller;


    function config()
    {
        //For all module console commands added prefix - module name
        $this->setName('errors')
            ->addArgument("device_ip", InputArgument::REQUIRED, "Dlink device IP")
            ->setDescription("Return errors statistic by ports");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $device = $this->getDeviceByIp($input->getArgument('device_ip'));
        $this->controller->setUser($this->user)->setDevice($device);

        $table = new Table($output);
        $table->setHeaders(
            [
                'interface',
                'in_errors',
                'out_errors',
                'in_discards',
                'out_discards',
            ]
        );
        if($data = $this->controller->getErrors()) {
            foreach ($data as $d) {
                $table->addRow([
                   $d['interface']['name'],
                    $d['in_errors'],
                    $d['out_errors'],
                    $d['in_discards'],
                    $d['out_discards'],
                ]);
            }
        }
       $table->render();
        return self::SUCCESS;
    }

    /**
     * @param $ip
     * @return \WCAA\Models\Devices\Device
     * @throws \WCAA\Storage\Exceptions\RecordNotFoundException
     */
    protected function getDeviceByIp($ip) {
        return $this->deviceStorage->getByIp($ip);
    }
}