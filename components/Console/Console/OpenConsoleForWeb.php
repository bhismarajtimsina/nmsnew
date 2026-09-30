<?php


namespace WCC\Console\Console;


use DI\Annotation\Inject;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Api\Auth;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\UserStorage;
use WCC\Console\Controllers\ConsoleClient;

class OpenConsoleForWeb extends BaseOpenConsole
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
        $this->setName("open-for-web")
            ->setDescription("Open for web browser console (Required user token)")
            ->addOption("without-login", 'l', InputOption::VALUE_NEGATABLE, "Disable automatic logging", false)
            ->addOption("token", 't', InputOption::VALUE_OPTIONAL, "Required flag for web, will check permissions")
            ->addArgument("ip-id", InputArgument::REQUIRED, "Device ip address or ID");
    }

    function checkPermissionsAndSetUser()
    {
        $token = $this->input->getOption("token");
        if(!$token) {
            throw new SupportException("Incorrect token");
        }
        if(!$this->auth->isKeyValid($token)) {
            throw new \Exception("Invalid token");
        }
        $user = $this->auth->getUserByKey($token);

        if($this->input->getOption("without-login") && !$user->isRulePermitted('external_apps_console')) {
            throw new SupportException("You don't have permission to use console without auto login");
        } elseif (!$this->input->getOption("without-login") && !$user->isRulePermitted('external_apps_console_with_auto_auth')) {
            throw new SupportException("You don't have permission to use console with auto login");
        }
        $this->user = $user;
    }

}