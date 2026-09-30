<?php

namespace WCC\Paths\Console;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Infrastructure\PrometheusMetrics;
use WCC\Paths\Controllers\Controller;
use WCC\Paths\Models\PathState;

class CalculatePathState extends AbstractComponentCommand
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
        //Component console commands are automatically prefixed with the module
        //name, so this registers as `paths:calc-state`.
        $this->setName('calc-state')
            ->addOption("no-export", 'ne', InputOption::VALUE_NEGATABLE, "Do not export to prometheus storage", false)
            ->setDescription("Recalculate transport path and redundancy group state");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $result = $this->controller->recalculateAll();
        $ttl = (int)_env('PATHS_STATE_METRIC_TTL_SEC', 300);
        $export = !$input->getOption('no-export');

        $pathTable = new Table($output);
        $pathTable->setHeaders(['Path ID', 'Name', 'Group', 'Prio', 'State', 'Changed']);
        foreach ($result['paths'] as $row) {
            $path = $row['path'];
            $pathTable->addRow([
                $path->getId(),
                $path->getName(),
                $path->getGroupKey() ?: '-',
                $path->getPriority(),
                $this->colourState($row['state']),
                $row['changed'] ? ($row['previous'] . ' -> ' . $row['state']) : '',
            ]);

            if ($export) {
                $this->prom->setGauge(
                    "path_state",
                    PathState::NUMERIC[$row['state']] ?? -1,
                    [
                        'path_id' => $path->getId(),
                        'path_name' => $path->getName(),
                        'group_key' => $path->getGroupKey() ?: 'none',
                        'endpoint_a_name' => $path->getEndpointA() ? $path->getEndpointA()->getName() : 'N/A',
                        'endpoint_b_name' => $path->getEndpointB() ? $path->getEndpointB()->getName() : 'N/A',
                    ],
                    "Transport path state (1=up, 0.5=degraded, 0=down, -1=unknown)",
                    $ttl
                );

                $downHops = 0;
                if (isset($row['detail']['hops'])) {
                    foreach ($row['detail']['hops'] as $hop) {
                        if ($hop['state'] === PathState::STATE_DOWN) {
                            $downHops++;
                        }
                    }
                }
                $this->prom->setGauge(
                    "path_segments_down",
                    $downHops,
                    [
                        'path_id' => $path->getId(),
                        'path_name' => $path->getName(),
                        'group_key' => $path->getGroupKey() ?: 'none',
                    ],
                    "Number of down segments on a transport path",
                    $ttl
                );
            }
        }
        $pathTable->render();

        $output->writeln("");
        $groupTable = new Table($output);
        $groupTable->setHeaders(['Group', 'State', 'Usable', 'Total', 'Protected']);
        foreach ($result['groups'] as $group) {
            //Standalone paths are reported per-path above; a group of one adds nothing.
            if (!$group['redundant']) {
                continue;
            }
            $groupTable->addRow([
                $group['group_key'],
                $this->colourGroupState($group['state']),
                $group['usable'],
                $group['total'],
                $group['protected'] ? 'yes' : 'NO',
            ]);

            if ($export) {
                $labels = ['group_key' => $group['group_key']];
                $this->prom->setGauge("path_group_protected", $group['protected'] ? 1 : 0, $labels,
                    "1 when every path in the redundancy group is usable", $ttl);
                $this->prom->setGauge("path_group_up_count", $group['usable'], $labels,
                    "Number of usable paths in the redundancy group", $ttl);
                $this->prom->setGauge("path_group_total", $group['total'], $labels,
                    "Total number of paths in the redundancy group", $ttl);
            }
        }
        $groupTable->render();

        $changed = count(array_filter($result['paths'], function ($e) {
            return $e['changed'];
        }));
        $output->writeln("");
        $output->writeln(sprintf(
            "Evaluated %d path(s), %d state change(s)%s",
            count($result['paths']),
            $changed,
            $export ? ", metrics exported" : ", export skipped"
        ));

        return self::SUCCESS;
    }

    private function colourState($state)
    {
        switch ($state) {
            case PathState::STATE_UP:
                return "<info>up</info>";
            case PathState::STATE_DEGRADED:
                return "<comment>degraded</comment>";
            case PathState::STATE_DOWN:
                return "<error>down</error>";
            default:
                return "<comment>unknown</comment>";
        }
    }

    private function colourGroupState($state)
    {
        switch ($state) {
            case 'protected':
                return "<info>protected</info>";
            case 'unprotected':
                return "<comment>UNPROTECTED</comment>";
            case 'outage':
                return "<error>OUTAGE</error>";
            default:
                return $state;
        }
    }
}
