<?php

namespace WCAA\Infrastructure\Poller;

use Monolog\Logger;
use WCAA\Infrastructure\Poller\Pollers\CardsStatusPoller;
use WCAA\Infrastructure\Poller\Pollers\CountersPoller;
use WCAA\Infrastructure\Poller\Pollers\DeviceInterfacesList;
use WCAA\Infrastructure\Poller\Pollers\DeviceInterfacesStatus;
use WCAA\Infrastructure\Poller\Pollers\FdbHistory;
use WCAA\Infrastructure\Poller\Pollers\OntIdentification;
use WCAA\Infrastructure\Poller\Pollers\OntVendorInfo;
use WCAA\Infrastructure\Poller\Pollers\OpticalStrengthHistory;
use WCAA\Infrastructure\Poller\Pollers\ResourcesPoller;
use WCAA\Infrastructure\Poller\Pollers\SfpOpticalStrengthHistory;
use WCAA\Infrastructure\Poller\Pollers\WalkSystemInfo;
use WCAA\Models\Devices\Device;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\SwitcherCore\Response;
use WCAA\SwitcherCore\ResponsesWrapper;

class ResponseToPollerWriter
{

    /**
     * @Inject
     * @var OntIdentification
     */
    protected $ontIdentPoller;

    /**
     * @Inject
     * @var FdbHistory
     */
    protected $fdbTablePoller;

    /**
     * @Inject
     * @var CountersPoller
     */
    protected $countersPoller;

    /**
     * @Inject
     * @var DeviceInterfacesStatus
     */
    protected $interfaceStatusPoller;
    /**
     * @Inject
     * @var DeviceInterfacesList
     */
    protected $deviceInterfacesList;

    /**
     * @Inject
     * @var OpticalStrengthHistory
     */
    protected $opticalStrenghPoller;

    /**
     * @Inject
     * @var ResourcesPoller
     */
    protected $resourcesPoller;
    /**
     * @Inject
     * @var WalkSystemInfo
     */
    protected $systemInfo;

    /**
     * @Inject
     * @var CardsStatusPoller
     */
    protected $cardsStatusPoller;

    /**
     * @Inject
     * @var Logger
     */
    protected $logger;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var OntVendorInfo
     */
    protected $ontVendorInfo;
    /**
     * @Inject
     * @var SfpOpticalStrengthHistory
     */
    protected $sfpOpticalInfo;


    function process(ResponsesWrapper $responses)  {
        $fills = [
          'system'  => null,
          'sys_resources'  => null,
          'pon_ports_list'  => null,
          'interfaces_list'  => null,
          'link_info'  => null,
          'pon_onts_status'  => null,
          'interface_descriptions'  => null,
          'vlans_by_port' => null,
        ];
        foreach ($responses->getAllResponses() as $respons) {
            if($respons->getError()) {
                $this->logger->withName("poller_writer")->error($respons->getError()['message']);
                continue;
            }
            if($respons->getSource() !== 'device') {
                continue;
            }
            $fills[$respons->getModule()][] = $respons;
        }
        foreach ($fills as  $respons) {
            if(!$respons) continue;
            foreach ($respons as $resp) {
                if(!$resp) continue;
                $interface = null;
                if($args = $resp->getArguments()) {
                    $interface = isset($args['interface']) && $args['interface'] ? $args['interface'] : null;
                }
                if($interface) {
                    $this->setDataByInterface($resp->getDevice(), $resp->getModule(), $resp->getDataAsArray(), $fills);
                } else {
                    $this->setDataByDevice($resp->getDevice(), $resp->getModule(),$resp->getDataAsArray(), $fills);
                }
            }
        }
    }
    function setDataByInterface(Device $device, $module, $data, $fills) {

        if($module === 'fdb') {
            if($device->getModel()->getType() === 'SWITCH') {
                if(!isset($fills['vlans_by_port'][0])) return;
                if($fills['vlans_by_port'][0]->getError()) return;
                $vlans = [];
                $pvidData = $fills['vlans_by_port'][0]->getData();
                foreach ($pvidData as $vlan) {
                    $vlans[$vlan['interface']['id']] = count($vlan['tagged']) == 0;
                }
                $data = array_filter($data, function ($e) use ($vlans) {
                    return $vlans[$e['interface']['id']];
                });
            }
            $this->fdbTablePoller->setManualByInterface($device, $data);
        }
        if($module === 'interface_descriptions') {
            $this->deviceInterfacesList->setManual($device, array_map(function ($e) {
                $dt = $e['interface'];
                $dt['description'] = $e['description'];
                return $dt;
            }, $data));
        }
        if($module === 'pon_onts_mac_addr' || $module === 'pon_onts_serial') {
            $this->ontIdentPoller->setManualByInterface($device, array_map(function ($e) {
                return [
                    'interface' => $e['interface'],
                    'type' => isset($e['serial']) ? 'SERIAL' : 'MAC',
                    'ident' => isset($e['serial']) ? $e['serial'] : $e['mac_address'],
                ];
            }, $data));
        }
        if(isset($data[0]) && $module === 'interface_counters') {
            $this->countersPoller->setManual($device, $data);
        }
        if(isset($data[0]) && $module === 'pon_onts_status') {
            $this->interfaceStatusPoller->setManualByInterface($device, $data);
        }
        if(isset($data[0]) && $module === 'pon_onts_optical') {
            $this->opticalStrenghPoller->setManualByInterface($device, $data);
        }
        if(isset($data[0]) && $module === 'pon_onts_vendor') {
            $this->ontVendorInfo->setManualByInterface($device, $data);
        }
        if(isset($data[0]) && $module === 'sfp_optical') {
            $this->sfpOpticalInfo->setManualByInterface($device, $data);
        }
    }

    /**
     * @param Device $device
     * @param $module
     * @param $data
     * @param $fills
     * @return void
     */
    function setDataByDevice(Device $device, $module, $data, $fills) {
         if($module === 'fdb') {
             if($device->getModel()->getType() === 'SWITCH') {
                if(!isset($fills['vlans_by_port'][0])) return;
                if($fills['vlans_by_port'][0]->getError()) return;
                $vlans = [];
                $pvidData = $fills['vlans_by_port'][0]->getData();
                foreach ($pvidData as $vlan) {
                     $vlans[$vlan['interface']['id']] = count($vlan['tagged']) == 0;
                }
                $data = array_filter($data, function ($e) use ($vlans) {
                     if(!isset($vlans[$e['interface']['id']])) return false;
                     return $vlans[$e['interface']['id']];
                });
             }
             $this->fdbTablePoller->setManual($device, $data);

         }
         if($module === 'system') {
             $this->systemInfo->setManual($device, $data);
         }
         if($module === 'sys_resources') {
             $this->resourcesPoller->setManual($device, $data);
         }

         if($module === 'pon_onts_mac_addr' || $module === 'pon_onts_serial') {
             $this->ontIdentPoller->setManual($device, array_map(function ($e) {
                 return [
                     'interface' => $e['interface'],
                     'type' => isset($e['serial']) ? 'SERIAL' : 'MAC',
                     'ident' => isset($e['serial']) ? $e['serial'] : $e['mac_address'],
                 ];
             }, $data));
         }
         if($module === 'interface_descriptions') {
             $this->deviceInterfacesList->setManual($device, array_map(function ($e) {
                 $dt = $e['interface'];
                 if(!isset($dt['type'])) {
                     $dt['type'] = 'UNKNOWN';
                 }
                 $dt['description'] = $e['description'];
                 return $dt;
             }, $data));
         }
         if($module === 'pon_ports_list') {
             $this->deviceInterfacesList->setManual($device, $data);
         }
         if($module === 'interface_counters') {
             $this->countersPoller->setManual($device, $data);
         }
         if($module === 'pon_onts_status') {
             $this->interfaceStatusPoller->setManual($device, $data);
         }
         if($module === 'link_info') {
             $this->interfaceStatusPoller->setManual($device, array_map(function ($e) {
                 $e['status'] = $e['oper_status'];
                 return  $e;
             }, $data));
         }
         if($module === 'pon_onts_optical') {
             $this->opticalStrenghPoller->setManual($device, $data);
         }
         if($module === 'pon_onts_vendor') {
             $this->ontVendorInfo->setManual($device, $data);
         }
         if($module === 'card_status') {
             $this->cardsStatusPoller->setManual($device, $data);
         }
        if($module === 'sfp_optical') {
            $this->sfpOpticalInfo->setManual($device, $data);
        }
    }
}