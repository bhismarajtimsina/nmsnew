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

class GenerateUserKeyCommand extends AbstractCommand
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
        $this->setName("user:generate-key")
            ->setDescription("Generate auth key for user")
            ->addArgument("login", InputArgument::REQUIRED, "Login of user")
            ->addArgument("timeout", InputArgument::OPTIONAL, "Expired timeout (example: 10m, 1h, 3m)")
            ->addOption("output", "o", InputOption::VALUE_OPTIONAL, "Output format. support: table, yaml, json", "table");
    }
    function execute(InputInterface $input, OutputInterface $output)
    {
        if($login = $input->getArgument('login')) {
            if($user = $this->userStorage->getUserByLogin($login)) {
                $key = null;
                if ($timeout = $input->getArgument('timeout')) {
                    $duration = new Duration($timeout);
                    $key = $this->auth->generateKey($user, $duration->toSeconds());
                } else {
                    $key = $this->auth->generateKey($user);
                }

                switch ($input->getOption('output')) {
                    case 'table':
                        $table = new Table($output);
                        $table->setHeaders(["ID", "User", "Key", "Expired At"]);
                        $table->addRow([
                            $key->getId(),
                            "ID: {$user->getId()}\nLogin: {$user->getLogin()}\nName: {$user->getName()}",
                            $key->getKey(),
                            $key->getExpiredAt()
                        ]);
                        $table->render();
                        break;
                    case 'json':
                        $output->writeln($this->toJson($key->getAsArray()));
                        break;
                    case 'yaml':
                        $output->writeln($this->toYaml($key->getAsArray()));
                        break;
                }
            } else {
                throw new Exception("User with login $login not found");
            }
        }
        return self::SUCCESS;
    }
}