<?php

namespace WCAA\Infrastructure;

use Supervisor\Process;
use WCAA\App;

class Supervisor
{
    /**
     * @var \Supervisor\Supervisor
     */
    protected $supervisor;

    function __construct(App $app) {
            $guzzleClient = new \GuzzleHttp\Client([
                'auth' => [
                    $app->conf('supervisor.username'),
                    $app->conf('supervisor.password'),
                ],
            ]);
            $client = new \fXmlRpc\Client(
                $app->conf('supervisor.url'),
                new \fXmlRpc\Transport\PsrTransport(
                    new \GuzzleHttp\Psr7\HttpFactory(),
                    $guzzleClient
                )
            );
            $this->supervisor = new \Supervisor\Supervisor($client);
    }

    /**
     * @return Process[]
     */
    function getProcesses() {
        return $this->supervisor->getAllProcesses();
    }

    function restart() {
         $this->supervisor->restart();
         return $this;
    }

    /**
     * @return array
     */
    function getProcessesInfo() {
        return $this->supervisor->getAllProcessInfo();
    }

    /**
     * @param Process $process
     * @return Supervisor
     */
    function restartProcess(Process $process) {
        return $this->stopProcess($process)->startProcess($process);
    }

    /**
     * @param Process $process
     * @return $this
     */
    function stopProcess(Process $process) {
        $this->supervisor->stopProcess($process->getName());
        return $this;
    }

    /**
     * @param Process $process
     * @return $this
     */
    function startProcess(Process $process) {
        $this->supervisor->startProcess($process->getName());
        return $this;
    }
}