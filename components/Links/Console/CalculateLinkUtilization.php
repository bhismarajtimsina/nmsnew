<?php


namespace WCC\Links\Console;


use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Infrastructure\PrometheusMetrics;
use WCC\Links\Controllers\Controller;

class CalculateLinkUtilization extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $prom;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    function config()
    {
        //For all module console commands added prefix - module name
        $this->setName('calc-link-utilization')
            ->addOption("no-export", 'ne', InputOption::VALUE_NEGATABLE, "Do not export to prometheus storage", false)
            ->setDescription("Recalculate link-utilization in prometheus exporter");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $table = new Table($output);
        $table->setHeaders([
            'Link ID',
            'Source device',
            'Source interface',
            'Destination device',
            'Destination interface',
            'Speed (Mbits)',
            'Utilization (%)',
            'Utilization (Mbps)',
        ]);
        $links = $this->controller->getAllLinksData(_env('LINKS_UTILIZATION_CALCULATE_PERIOD', "15m"));
        $countExport = 0;
        foreach ($links as $link) {
            $table->addRow([
                $link['id'],
                $link['src_device']['ip'] . "\n" . $link['src_device']['name'],
                $link['src_iface'] ? "{$link['src_iface']['name']}\n{$link['src_iface']['description']}" : '',
                $link['dest_device']['ip'] . "\n" . $link['dest_device']['name'],
                $link['dest_iface'] ? "{$link['dest_iface']['name']}\n{$link['dest_iface']['description']}" : '',
                $link['speed'],
                $link['utilization'],
                $link['utilization_mbps'],
            ]);
            if (!$input->getOption('no-export')) {
                $countExport++;
                if ($link['utilization']) {
                    $this->prom->setGauge("link_utilization_prc", $link['utilization'], [
                        'link_id' => $link['id'],
                        'src_device_id' => $link['src_device']['id'],
                        'src_device_ip' => $link['src_device']['ip'],
                        'src_device_name' => $link['src_device']['name'],
                        'dest_device_id' => $link['dest_device']['id'],
                        'dest_device_ip' => $link['dest_device']['ip'],
                        'dest_device_name' => $link['dest_device']['name'],
                        'src_iface_bind_key' => $link['src_iface'] ? $link['src_iface']['bind_key'] : 'N/A',
                        'src_iface_name' => $link['src_iface'] ? $link['src_iface']['name'] : 'N/A',
                        'dest_iface_bind_key' => $link['dest_iface'] ? $link['dest_iface']['bind_key'] : 'N/A',
                        'dest_iface_name' => $link['dest_iface'] ? $link['dest_iface']['name'] : 'N/A',
                    ],
                        "Link utilization (%)",
                        600);
                }
                if ($link['utilization_mbps']) {
                    $this->prom->setGauge("link_utilization_mbps", $link['utilization_mbps'], [
                        'link_id' => $link['id'],
                        'src_device_id' => $link['src_device']['id'],
                        'src_device_ip' => $link['src_device']['ip'],
                        'src_device_name' => $link['src_device']['name'],
                        'dest_device_id' => $link['dest_device']['id'],
                        'dest_device_ip' => $link['dest_device']['ip'],
                        'dest_device_name' => $link['dest_device']['name'],
                        'src_iface_bind_key' => $link['src_iface'] ? $link['src_iface']['bind_key'] : 'N/A',
                        'src_iface_name' => $link['src_iface'] ? $link['src_iface']['name'] : 'N/A',
                        'dest_iface_bind_key' => $link['dest_iface'] ? $link['dest_iface']['bind_key'] : 'N/A',
                        'dest_iface_name' => $link['dest_iface'] ? $link['dest_iface']['name'] : 'N/A',
                    ],
                        "Link utilization Mbps",
                        600);
                }
                // Whether the link is up at all, emitted for every link on every
                // run — unlike the three gauges around it, which are only
                // written when the link has utilization to report. That made a
                // down link indistinguishable from a link that was never
                // exported, so "link down" could not be expressed as an alarm
                // at all: the series simply vanished, and PromQL cannot name
                // the link that is missing.
                //
                // 1 means both ends report Up. 0 means at least one end does
                // not, which is what a link being down looks like from here.
                $srcStatus = $link['src_iface']['status'] ?? null;
                $destStatus = $link['dest_iface']['status'] ?? null;
                $bothUp = ($srcStatus === 'Up' || $srcStatus === 'Online')
                    && ($destStatus === 'Up' || $destStatus === 'Online');
                $this->prom->setGauge("link_status", $bothUp ? 1 : 0, [
                    'link_id' => $link['id'],
                    'src_device_id' => $link['src_device']['id'],
                    'src_device_ip' => $link['src_device']['ip'],
                    'src_device_name' => $link['src_device']['name'],
                    'dest_device_id' => $link['dest_device']['id'],
                    'dest_device_ip' => $link['dest_device']['ip'],
                    'dest_device_name' => $link['dest_device']['name'],
                    'src_iface_bind_key' => $link['src_iface'] ? $link['src_iface']['bind_key'] : 'N/A',
                    'src_iface_name' => $link['src_iface'] ? $link['src_iface']['name'] : 'N/A',
                    'dest_iface_bind_key' => $link['dest_iface'] ? $link['dest_iface']['bind_key'] : 'N/A',
                    'dest_iface_name' => $link['dest_iface'] ? $link['dest_iface']['name'] : 'N/A',
                ],
                    "Link status, 1 up and 0 down",
                    600);

                if ($link['speed']) {
                    $this->prom->setGauge("link_utilization_speed", $link['speed'], [
                        'link_id' => $link['id'],
                        'src_device_id' => $link['src_device']['id'],
                        'src_device_ip' => $link['src_device']['ip'],
                        'src_device_name' => $link['src_device']['name'],
                        'dest_device_id' => $link['dest_device']['id'],
                        'dest_device_ip' => $link['dest_device']['ip'],
                        'dest_device_name' => $link['dest_device']['name'],
                        'src_iface_bind_key' => $link['src_iface'] ? $link['src_iface']['bind_key'] : 'N/A',
                        'src_iface_name' => $link['src_iface'] ? $link['src_iface']['name'] : 'N/A',
                        'dest_iface_bind_key' => $link['dest_iface'] ? $link['dest_iface']['bind_key'] : 'N/A',
                        'dest_iface_name' => $link['dest_iface'] ? $link['dest_iface']['name'] : 'N/A',
                    ],
                        "Link speed",
                        600);
                }

            }
        }
        $table->render();

        if ($countExport > 0) {
            $output->writeln("Exported {$countExport} rows to Prometheus");
        }

        return self::SUCCESS;
    }
}
