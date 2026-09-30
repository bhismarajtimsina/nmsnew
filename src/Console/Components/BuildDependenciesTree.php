<?php


namespace WCAA\Console\Components;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Storage\SystemComponentsStorage;

class BuildDependenciesTree extends AbstractCommand
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
        $this->setName("component:dependencies")
            ->addOption("json", 'json', InputOption::VALUE_NEGATABLE, 'Print as json')
            ->setDescription("Component dependencies");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $components = $this->injector->fetchComponents();
        $tree = [];
        foreach ($components as $component) {
            $tree[$component['name']]['weight'] = 0;
            $tree[$component['name']]['all_deps_exists'] = true;
        }
        foreach ($components as $component) {
            if($component['dependencies']) {
                foreach ($component['dependencies'] as $dep) {
                    $tree[$component['name']]['weight'] += 10;
                    if(!$this->injector->isComponentExist($dep)) {
                        $tree[$component['name']]['all_deps_exists'] = false;
                    }
                }
            }
        }
        uasort($tree, fn($a, $b) => $a['weight'] > $b['weight']);
        if($input->getOption('json')) {
            $output->writeln(json_encode($tree, JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK));
        } else {
            foreach ($tree as $k=>$v) {
                $allDeps = $v['all_deps_exists'] ? 'yes' : 'no';
                $output->writeln("{$k};{$v['weight']};{$allDeps}");
            }
        }
        return self::SUCCESS;
    }
}