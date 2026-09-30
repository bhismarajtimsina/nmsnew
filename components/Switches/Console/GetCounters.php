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

class GetCounters extends AbstractComponentCommand
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
        $this->setName('counters')
            ->addArgument("device_ip", InputArgument::REQUIRED, "Dlink device IP")
            ->setDescription("Return traffic statistic by ports");
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
                'in_octets',
                'out_octets',
                'in_discards',
                'out_discards',
                'crc_align_errors',
                'undersize_pkts',
                'oversize_pkts',
                'fragments',
                'jabber',
                'collisions',
                'drop_events',
            ]
        );
        if ($data = $this->controller->getCounters()) {
            foreach ($data as $d) {
                $table->addRow([
                    $d['interface']['name'],
                    isset($d['in_errors']) ? $d['in_errors'] : 'U',
                    isset($d['out_errors']) ? $d['out_errors'] : 'U',
                    isset($d['in_discards'])  ? $d['in_discards'] : 'U',
                    isset($d['out_discards'])  ? $d['out_discards'] : 'U',
                    isset($d['in_octets'])  ? $d['in_octets'] : 'U',
                    isset($d['out_octets'])  ? $d['out_octets'] : 'U',

                    $d['in_crc_pkts'],
                    $d['in_undersize_pkts'],
                    $d['in_oversize_pkts'],
                    $d['in_fragments_pkts'],
                    $d['in_jabber_pkts'],
                    $d['in_collision_pkts'],
                    $d['in_drop_pkts'],
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
    protected function getDeviceByIp($ip)
    {
        return $this->deviceStorage->getByIp($ip);
    }
}