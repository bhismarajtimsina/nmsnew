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

class RunAutoTopology extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;


    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $modelStorage;

    /**
     * @Inject
     * @var SystemActionLogger
     */
    protected $sysLogger;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var AutoDiscoveryNetworksStorage
     */
    protected $autoDiscoveryStorage;

    /**
     * @Inject
     * @var DeviceGroupStorage
     */
    protected $deviceGroupStorage;

    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $deviceAccessStorage;


    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @Inject
     * @var \WCC\AutoTopology\Controllers\Controller
     */
    protected $controller;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var array
     */
    public function config()
    {
        $this->setName("scan")
            ->addOption("schedule", "schedule", InputOption::VALUE_NEGATABLE, "Flag for starting over schedule", false)
            ->setDescription("Run auto-topology builder");
    }

    public function exec(InputInterface $input, OutputInterface $output)
    {
       $this->input = $input;
       $this->output = $output;

       if($input->getOption("schedule") && !_env("AUTO_TOPOLOGY_SCHEDULE_ENABLED", true)) {
            $this->output->writeln("Schedule auto-topology disabled, you can run it's command manually or enable scheduled in settings");
            return self::SUCCESS;
       } else {
           $this->output->writeln("Running auto-topology builder manually");
       }
       $this->runBuilder();

       return self::SUCCESS;
    }
    /**
     * Native PHP implementation — the vendor's own `bin/auto-topology`
     * binary this used to shell out to panics on every single invocation
     * ("panic: Input not correct" in its own main.getInput()), confirmed
     * via this schedule's run history going back to before this fix,
     * meaning NO links had ever been auto-created anywhere in this system.
     * Since that binary is closed-source (no way to patch the actual
     * bug), this reimplements the same 'fdb'/'lldp' discovery the DB
     * schema already anticipated (`links.source` enum) directly here,
     * reusing this system's own already-working LLDP module support and
     * FDB history storage instead of depending on the broken external
     * tool and its own separate device-polling path.
     */
    public function runBuilder()
    {
        $this->output->writeln("Running native auto-topology discovery (fdb + lldp)");
        $stats = $this->controller->discoverLinks(function ($msg) {
            $this->output->writeln($msg);
        });
        $this->output->writeln(sprintf(
            "Finished: %d link(s) via LLDP, %d via FDB, %d error(s)",
            $stats['lldp_created'],
            $stats['fdb_created'],
            $stats['errors']
        ));
    }
}
