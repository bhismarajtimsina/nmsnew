<?php


namespace WCC\Analytics\Console;


use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\User\User;
use WCC\AllOkBilling\Controllers\Controller;
use WCC\Analytics\Controllers\DuplicatesStat;

class DuplicatedOntIdents extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $prom;

    /**
     * @Inject
     * @var DuplicatesStat
     */
    protected $duplicatesStat;

    function config()
    {
        //For all module console commands added prefix - module name
        $this->setName('duplicated-ont-idents')
            ->addOption("no-export", 'ne', InputOption::VALUE_NEGATABLE, "Do not export to prometheus storage", false)
            ->setDescription("Get list of duplicated ONT identification");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $duplicates = $this->duplicatesStat->getDuplicatedOntIdents();
        $table = new Table($output);
        $table->setHeaders([
           'first',
           'last',
           'Ident',
           'ifaces',
        ]);
        $countExport = 0;
        foreach ($duplicates as $duplicated) {
            $ifaces = '';
            foreach ($duplicated['ifaces'] as $iface) {
                $ifaces .= "{$iface['device']['ip']} - {$iface['name']} ({$iface['id']})\n";
            }
            $table->addRow([
                $duplicated['first'],
                $duplicated['last'],
                "{$duplicated['ident']} ({$duplicated['type']})",
                $ifaces,
            ]);
            if(!$input->getOption('no-export')) {
                $countExport++;
                $this->prom->setGauge("analytics_duplicated_ont_ident", $duplicated['count_duplicates'], [
                    'ident' => $duplicated['ident'],
                    'type' => $duplicated['type'],
                ],
                "Duplicated ONT idents in system"
                , 300);
                foreach ($duplicated['ifaces'] as $iface) {
                    $this->prom->setGauge("analytics_duplicated_ont_ident_ifaces", $duplicated['count_duplicates'], [
                        'dev_id' => $iface['device']['id'],
                        'ip' => $iface['device']['ip'],
                        'ident' => $duplicated['ident'],
                        'type' => $duplicated['type'],
                        'iface_type' => $iface['type'],
                        'iface_id' => $iface['bind_key'],
                        'iface_name' => $iface['name'],
                    ],
                        "Duplicated ONTs in system with interfaces"
                        , 300);
                }
            }
        }
        $table->render();

        if($countExport > 0 ) {
            $output->writeln("Exported {$countExport} rows to Prometheus");
        }

        return self::SUCCESS;
    }
}
