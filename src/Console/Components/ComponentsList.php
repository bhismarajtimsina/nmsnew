<?php


namespace WCAA\Console\Components;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Storage\SystemComponentsStorage;

class ComponentsList extends AbstractCommand
{
    /**
     * @Inject
     * @var ComponentInjector
     */
    protected $injector;


    /**
     * @Inject
     * @var SystemComponentsStorage
     */
    protected $storage;

    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("component:list")
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output type. Variants: table, json', 'table')
            ->setDescription("Return list of supported components");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {

        $components = $this->injector->fetchComponents();
        if($input->getOption('output') === 'table') {
            $table = new Table($output);
            $table->setHeaders(
                ['Key', 'Description', 'Enabled', 'Installed', 'Has installer', 'Has controller']
            );
            foreach ($components as $component) {
                $table->addRow([
                    $component['name'],
                    $component['description'],
                    $component['enabled'] ? 'Yes': 'No',
                    $component['installed'] ? 'Yes': 'No',
                    $component['installer'] ? 'Yes' : 'No',
                    $component['controller'] ? 'Yes' : 'No',
                ]);
            }
            $table->render();
        } else {
            $output->writeln(json_encode(array_values($components), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

}