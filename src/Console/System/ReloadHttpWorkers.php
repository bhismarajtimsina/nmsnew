<?php


namespace WCAA\Console\System;

use DI\Annotation\Inject;
use Spiral\Goridge\RPC\RPC;
use Spiral\Goridge\RPC\RPCInterface;
use Superbalist\PubSub\Redis\RedisPubSubAdapter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Interfaces\CacheInterface;

class ReloadHttpWorkers extends AbstractCommand
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
        $this->setName("system:http:reset")
            ->setDescription("Reseting all HTTP workers.");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $this->reloadWebWorkers();
        return self::SUCCESS;
    }


    /**
     * Функция приводит к перезагрузке веб-воркеров
     * В новых версиях road runner этот функционал нужно будет переписать
     *
     * @return void
     */
    function reloadWebWorkers()
    {
        $this->output->writeln("Start reloading web workers...");
        $this->rpc->call("resetter.Reset", 'http', null);
        $this->output->writeln("Workers reloaded successfully.");
    }
}