<?php


namespace WCC\NoDenyPlus\Console;


use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\User\User;
use WCC\NoDenyPlus\Controllers\Controller;

class SyncClients extends AbstractComponentCommand
{
    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @Inject
     * @var User
     */
    protected $user;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    function config()
    {
        //For all module console commands added prefix - module name
        $this->setName('sync-clients')
            ->setDescription("Sync clients with NoDeny billing");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $this->controller
            ->setConsoleOutput($output)
            ->syncAllClients();
        return self::SUCCESS;
    }

}
