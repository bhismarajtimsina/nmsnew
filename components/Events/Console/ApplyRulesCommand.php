<?php

namespace WCC\Events\Console;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCC\Events\Controllers\Alertmanager;

class ApplyRulesCommand extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var Alertmanager
     */
    protected $alertmanager;

    function config()
    {
        $this->setName("apply-rules")
            ->setDescription("Update alertmanager rules based on event configuration");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
       $this->alertmanager->setModuleConfig($this->componentConfig);
       $rules = $this->alertmanager->listRules();
       $this->output->writeln("Found " . count($rules) . " rules in storage, try apply");
       $this->alertmanager->applyRules($rules);
       $this->output->writeln("<info>Success!</info>");
       return self::SUCCESS;
    }

}