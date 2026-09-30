<?php


namespace WCAA\Console\Logs;


use DI\Annotation\Inject;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Interfaces\CacheInterface;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ClearLogsActions extends AbstractCommand
{
    /**
     * @Inject
     * @var \PDO
     */
    protected $pdo;

    protected function configure()
    {
        $this->setName("logs:clear")
            ->addArgument('target', InputArgument::REQUIRED, "Log tag to clear. Variants: switcher-core|collector|actions|crontab-reports")
            ->addArgument('days', InputArgument::OPTIONAL, "Days to clear", 14)
            ->setDescription("Clear switcher_core_actions table")
        ;

    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $days = $input->getArgument('days');
        $output->writeln("Start clear with target={$input->getArgument('target')}, days={$days}");
        switch ($input->getArgument('target')) {
            case 'switcher-core':
                $this->pdo->prepare("DELETE FROM switcher_core_actions WHERE time < NOW() - INTERVAL ? DAY ")->execute([$days]);
                break;
            case 'collector':
                $this->pdo->prepare("DELETE FROM poller_processing WHERE start_at < NOW() - INTERVAL ? DAY ")->execute([$days]);
                break;
            case 'actions':
                $this->pdo->prepare("DELETE FROM system_actions WHERE created_at < NOW() - INTERVAL ? DAY ")->execute([$days]);
                break;
            case 'crontab-reports':
                $this->pdo->prepare("DELETE FROM system_schedule_reports WHERE start_at < NOW() - INTERVAL ? DAY ")->execute([$days]);
                break;
            case 'expired-sessions':
                $this->pdo->prepare("DELETE FROM `user_auth_keys` WHERE expired_at < NOW() - INTERVAL ? DAY")->execute([$days]);
                break;
            case 'notifications':
                $this->pdo->prepare("DELETE FROM `c_notifications` WHERE sent_at < NOW() - INTERVAL 30 DAY;")->execute([$days]);
                break;
            default:
                throw new InvalidArgumentException("Argument target has incorrect value!");
        }
        $output->writeln("Success cleared");
        return self::SUCCESS;
    }

}
