<?php


namespace WCAA\Console\Components;

use DI\Container;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\CacheControl;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Storage\SystemComponentsStorage;

class ComponentsControl extends AbstractCommand
{
    /**
     * @Inject
     * @var ComponentInjector
     */
    protected $injector;

    /**
     * @Inject
     * @var CacheControl
     */
    protected $cacheControl;

    /**
     * @Inject
     * @var Container
     */
    protected $di;

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
        $this->setName("component:control")
            ->addArgument('module-key', InputArgument::REQUIRED, "Key name of module")
            ->addArgument('state', InputArgument::REQUIRED, "State of module. Variants: install|uninstall|enable|disable")
            ->setDescription("Components control");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $component = $this->injector->setOutputInterface($output)->getComponentByName($input->getArgument('module-key'));
        $state = $input->getArgument('state');
        $output->writeln("<info>Component control</info>");
        $output->writeln("Start $state component <info>{$component['name']}</info>");
        switch ($state) {
            case 'install':
                $this->injector->installComponent($component['name']);
                break;
            case 'uninstall':
                $this->injector->uninstallComponent($component['name']);
                $output->writeln("
<info>Component {$component['name']} success uninstalled</info>");
                break;
            case 'enable':
                $this->injector->enableComponent($component['name']);
                break;
            case 'disable':
                $this->injector->disableComponent($component['name']);
                break;
            default:
                throw new InvalidArgumentException("Incorrect state argument");
        }
        $this->cacheControl->refreshAll();
        return self::SUCCESS;
    }
}