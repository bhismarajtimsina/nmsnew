<?php


namespace WCC\Console\Console;


use DI\Annotation\Inject;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Api\Auth;
use WCAA\App;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\UserStorage;
use WCC\Console\Controllers\ConsoleClient;

class OpenConsole extends BaseOpenConsole
{
    /**
     * @Inject
     * @var ConsoleClient
     */
    protected $swc;
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $devStorage;
    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;

    /**
     * @Inject
     * @var Auth
     */
    protected $auth;

    function config()
    {
        $this->setName("open")
            ->setDescription("Open device console")
            ->addOption("without-login", 'l', InputOption::VALUE_NEGATABLE, "Disable automatic logging", false)
            ->addOption("token", 't', InputOption::VALUE_OPTIONAL, "Required flag for web, will check permissions")
            ->addArgument("ip-id", InputArgument::REQUIRED, "Device ip address or ID");
    }

    function checkPermissionsAndSetUser()
    {
        $this->user = App::getInstance()->getConsoleUser();
    }


}