<?php


namespace WCAA\Console\System;

use DI\Annotation\Inject;
use Spiral\Goridge\RPC\RPC;
use Spiral\Goridge\RPC\RPCInterface;
use Superbalist\PubSub\Redis\RedisPubSubAdapter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Exceptions\SupportException;
use WCAA\Interfaces\CacheInterface;

class GetHttpWorkers extends AbstractCommand
{

    /**
     * @Inject
     * @var RedisPubSubAdapter
     */
    protected $psa;

    /**
     * @Inject
     * @var RPCInterface
     */
    protected $rpc;


    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    protected function configure()
    {
        $this->setName("system:http:stat")
            ->setDescription("Get HTTP workers stat");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $this->runCommand("/www/rr workers");
        return self::SUCCESS;
    }


    public function runCommand($cmd)
    {
        if($this->output->isVerbose()) {
            $this->output->writeln($cmd);
        }
        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(0);
        $process->run(function ($type, $buffer) {
            if (Process::ERR === $type) {
                echo 'ERR: ' . $buffer;
            } else {
                echo $buffer;
            }
        });
        // executes after the command finishes
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
        if($err = $process->getErrorOutput()) {
            throw new SupportException("Error execute command '{$cmd}': {$err}");
        };
    }
}