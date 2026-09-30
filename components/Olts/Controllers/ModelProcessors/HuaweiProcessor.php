<?php

namespace WCC\Olts\Controllers\ModelProcessors;

use WCAA\Infrastructure\Poller\Interfaces\PollerCardsStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerCountersInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerFdbInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceListInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerInterfaceStatusInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOntIdentificationInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOntVendorInfoInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerResourcesInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSfpOpticalStrengthInterface;
use WCAA\Infrastructure\Poller\Interfaces\PollerSystemInterface;
use WCAA\Infrastructure\Poller\Interfaces\PonPortLoadingInterface;
use WCAA\Interfaces\ControllerInterface;
use WCAA\SwitcherCore\Request;

class HuaweiProcessor extends AbstractProcessor implements PollerSystemInterface,
    PollerFdbInterface,
    ControllerInterface,
    PollerInterfaceListInterface,
    PollerOntIdentificationInterface,
    PollerInterfaceStatusInterface,
    PollerOpticalStrengthInterface,
    PollerCountersInterface,
    PollerResourcesInterface,
    PonPortLoadingInterface,
    PollerOntVendorInfoInterface,
    PollerCardsStatusInterface,
    PollerSfpOpticalStrengthInterface
{
    function getOpticalStrength()
    {
        $reqsSplitted = [];
        foreach ($this->getPonInterfacesList() as $port) {
            $reqsSplitted[substr($port['id'], 0, 5)][] = (new Request())
                ->setDevice($this->device)
                ->setModule('pon_onts_optical')
                ->setArguments(['interface' => $port['id'], 'load_only' => 'olt_rx,rx,distance']);
        }
        $reqsSplitted = array_values($reqsSplitted);
        for ($portId = 0; $portId < 16; $portId++) {
            for ($slotId = 0; $slotId < count($reqsSplitted); $slotId++) {
                if (isset($reqsSplitted[$slotId][$portId])) {
                    $reqs[] = $reqsSplitted[$slotId][$portId];
                }
            }
        }
        $responses = $this->core->fromDeviceMultiCall($reqs, 3);
        $data = [];
        foreach ($responses->getAllResponses() as $respons) {
            if ($respons->getError()) {
                $this->logger->error("Error load signal levels from device={$this->device->getIp()} on interface={$respons->getArguments()['interface']}");
                continue;
            }
            $data = array_merge($data, $respons->getDataAsArray());
        }


        return array_map(function ($e) {
            return [
                'interface' => $e['interface'],
                'rx' => isset($e['rx']) ? $e['rx'] : null,
                'tx' => isset($e['tx']) ? $e['tx'] : null,
                'voltage' => isset($e['voltage']) ? $e['voltage'] : null,
                'temperature' => isset($e['temp']) ? $e['temp'] : null,
                'attenuation' => null,
                'distance' => isset($e['distance']) ? $e['distance'] : null,
                'olt_rx' => isset($e['olt_rx']) ? $e['olt_rx'] : null,
                'olt_tx' => null,
            ];
        }, $data);
    }
}