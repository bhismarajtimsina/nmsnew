<?php


namespace WCAA\Console\User;


use Exception;
use Khill\Duration\Duration;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Api\Auth;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\UserStorage;

class ResetIpStrict extends AbstractCommand
{
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

    protected function configure()
    {
        $this->setName("user:reset-ip-strict")
            ->setDescription("Reset ip strict access")
            ->addArgument("login", InputArgument::REQUIRED, "Login of user");
    }
    function execute(InputInterface $input, OutputInterface $output)
    {
        if($login = $input->getArgument('login')) {
            if($user = $this->userStorage->getUserByLogin($login)) {
                 $settings = $user->getSettings();
                 $settings['strict_access_by_ip']['enabled'] = false;
                 $this->userStorage->update($user->setSettings($settings));
                 $output->writeln("<info>Strict by IP success disabled!</info>");
            } else {
                throw new Exception("User with login $login not found");
            }
        }
        return self::SUCCESS;
    }
}