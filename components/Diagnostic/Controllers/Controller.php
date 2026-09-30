<?php


namespace WCC\Diagnostic\Controllers;


use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Interfaces\ControllerInterface;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Diagnostic\Models\DiagnosticInterface;
use WCC\Diagnostic\Models\Response;

/**
 * Class Controller
 * @package WCC\Diagnostic
 */
class Controller extends AbstractComponentController
{

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;

    /**
     * @Inject
     * @var User
     */
    protected $user;

    /**
     * @param Device $device
     * @param $interface
     * @return Response
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    function diagnostic(Device $device, $interface) {
        /**
         * @var DiagnosticInterface $controller
         */
        $controller = $this->getController($device);
        if(!($controller instanceof DiagnosticInterface)) {
            throw new \Exception("Controller for diagnostic must implement DiagnosticInterface");
        }
        $interface = $controller->parseInterface($interface);
        return $controller->diagInterface($interface);
    }


    function arpPingIP(Device $router, string $ip, $vlanId = null, $count = 4, $vlanName = null) {
        if(!$vlanId) {
            $arp = $this->getArpByIP($router, $ip);
            $vlanId = $arp['vlan_id'];
        }
        $arpPingResponse = $this->switcherCore->fromDevice([
            (new Request())
                ->setDevice($router)
                ->setModule('arp_ping')
                ->setArguments(['ip' => $ip, 'vlan_id' => $vlanId, 'vlan_name' => $vlanName, 'count' => $count])
        ])->getFirstByModule('arp_ping');
        if($err = $arpPingResponse->getError()) {
            $this->logger->error("Error ARP ping for ip={$ip} on router={$router->getIp()}", $err);
            throw new \Exception("Error ARP ping for ip={$ip} on router={$router->getIp()} - {$err['description']}");
        }
        return $arpPingResponse->getData();
    }

    function getArpByIP(Device $router, string $ip) {
        $this->logger->info("Start searching ARP on router {$router->getIp()} by IP - $ip");
        $arpResponse = $this->switcherCore->fromDevice([
            (new Request())->setDevice($router)->setModule('arp_info')->setArguments([
               'ip' => $ip,
            ])
        ])->getFirstByModule('arp_info');
        if($err = $arpResponse->getError()) {
            $this->logger->error("Error get ARP", ['device'=>$router->getAsArray(), 'ip'=>$ip, 'error'=>$err]);
            throw new \Exception("Error get ARP for IP=$ip on router={$router->getIp()}, with error={$err['description']}", $err);
        }
        if(count($arpResponse->getData()) === 0) {
            throw new \Exception("ARP not found on router={$router->getIp()} for ip={$ip}");
        }
        $this->logger->info("ARP success found for ip={$ip} on router={$router->getIp()}", $arpResponse->getDataAsArray()[0]);
        return $arpResponse->getData()[0];
    }

    /**
     * @return ControllerInterface
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    public function getController(Device $device)
    {
        /**
         * @var  $controller ControllerInterface
         */
        if($device->getModel()->getController() === null) {
            throw new \Exception("Device {$device->getName()} with model {$device->getModel()->getName()} doesn't have controller");
        }
        $controller = $this->app->getContainer()->get($device->getModel()->getController());
        if($controller instanceof ControllerInterface) {
            return $controller
                ->setDevice($device)
                ->setUser($this->user);
        } else {
            throw new \Exception("Controller setted in device model must implement ControllerInterface");
        }
    }
}
