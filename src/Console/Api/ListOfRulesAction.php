<?php


namespace WCAA\Console\Api;

use DI\Annotation\Inject;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\Devices\DeviceStorage;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListOfRulesAction extends AbstractCommand
{
    /**
     * @Inject
     * @var App
     */
    protected $app;


    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("api:rules-list")
            ->setDescription("List of rules")
            ->addOption("output", "o", InputOption::VALUE_OPTIONAL, "Output format. support: table, yaml, json", "table");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $rules = $this->app->getConfig()['api']['auth']['rules'];
        usort($rules, function($a, $b) {
            return $a['key'] <=> $b['key'];
        });
        usort($rules, function($a, $b) {
            return $a['logic_group'] <=> $b['logic_group'];
        });
        switch ($input->getOption('output')) {
            case 'table':
                $table = new Table($output);
                $table->setHeaders([
                    'Logic group',
                    'Key',
                    'Routes regex',
                ]);
                foreach ($rules as $rule) {
                    $regex = '';
                    if(isset($rule['routes']) && is_array($rule['routes'])) {
                        foreach ($rule['routes'] as $route) {
                            $regex .= "{$route}\n";
                        }
                    }
                    $regex = trim($regex);
                    $table->addRow([
                        $rule['logic_group'],
                        $rule['key'],
                        $regex
                    ]);
                }
                $table->render();
                break;
            case 'json':
                $output->writeln($this->toJson($rules));
                break;
            case 'yaml':
                $output->writeln($this->toYaml($rules));
                break;
        }
        return self::SUCCESS;
    }
}