<?php


namespace WCC\AutoTopology\Console;


use Cocur\BackgroundProcess\BackgroundProcess;
use DI\Annotation\Inject;
use Monolog\Logger;
use SwitcherCore\Modules\BDcom\InterfacesList;
use Symfony\Component\Console\Exception\MissingInputException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Infrastructure\Poller\PollerProcessor;
use WCAA\Infrastructure\SystemActionLogger;
use WCAA\Models\Devices\Device;
use WCAA\Models\SystemAction;
use WCAA\Storage\PollerData\PollerProcessingStorage;
use WCAA\Storage\Devices\DeviceAccessStorage;
use WCAA\Storage\Devices\DeviceGroupStorage;
use WCAA\Storage\Devices\DeviceModelStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\AutoDiscovery\Models\AutoDiscoveryNetwork;
use WCC\AutoDiscovery\Storage\AutoDiscoveryNetworksStorage;

class ClearNotActualLinks extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var \PDO
     */
    protected $pdo;

    public function config()
    {
        $this->setName("clear-not-actual-links")
            ->addOption("days", "d", InputOption::VALUE_OPTIONAL, "Count days for clear", 3)
            ->setDescription("Clearing old topology links, added automatically");
    }

    public function exec(InputInterface $input, OutputInterface $output)
    {
       $this->input = $input;
       $this->output = $output;
       $days = $this->input->getOption("days");
       $this->output->writeln("Clearing old links (over $days days)");

       $deleted = $this->pdo->exec("DELETE FROM c_links WHERE updated_at <= NOW() - INTERVAL $days DAY and source != 'manual'");
       $this->output->writeln("Cleared $deleted links");

       return self::SUCCESS;
    }
}
