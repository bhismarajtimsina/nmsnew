<?php


namespace WCAA\Console\User;


use Exception;
use Khill\Duration\Duration;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;
use WCAA\Api\Auth;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\Security\Passwords;
use WCAA\Storage\UserStorage;

class ResetUser extends AbstractCommand
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
        $this->setName("user:reset-user")
            ->addArgument("username", InputArgument::REQUIRED, "Username for reset")
            ->setDescription("Reset user accesses");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $username = $input->getArgument("username");
        if ($user = $this->userStorage->getUserByLogin($username)) {
            $settings = $user->getSettings();
            $settings['strict_access_by_ip']['enabled'] = false;
            $user->setSettings($settings)->setIsTwofa(false)->setTwofaToken('')->setPassword((new Passwords())->hash($username));
            $this->userStorage->update($user);
            $output->writeln("<info>Password success reset!</info>");
            $output->writeln("<info>You can try login with - {$username}/{$username}</info>");
        } else {
            throw new Exception("User with login {$username} not found");
        }
        return self::SUCCESS;
    }
}