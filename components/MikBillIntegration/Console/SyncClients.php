<?php


namespace WCC\MikBillIntegration\Console;


use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\User\User;
use WCC\MikBillIntegration\Controllers\Controller;

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
            ->addOption('force', 'f', InputOption::VALUE_NEGATABLE, 'Force update billing information', false)
            ->setDescription("Sync clients with MikBill billing");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $this->controller->setIsDebug($output->isDebug());
        if($output->isDebug()) {
            $output->writeln("DEBUG ENABLED...");
        }
        if($input->getOption('force')) {
            $output->writeln("Force update info from billing...");
        }

        $this->controller
            ->setConsoleOutput($output)
            ->syncAllClients($input->getOption('force'));
        return self::SUCCESS;
    }

}
