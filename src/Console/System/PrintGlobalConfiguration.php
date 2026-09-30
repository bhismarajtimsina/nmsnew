<?php


namespace WCAA\Console\System;


use DI\Annotation\Inject;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PrintGlobalConfiguration extends AbstractCommand
{
    /**
     * @Inject
     * @var App
     */
    protected $app;

    protected function configure()
    {
        $this->setName("system:configuration")
            ->setDescription("Print global configuration")
        ;
    }
    function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->app->getConfig();
        $output->writeln(json_encode($config, JSON_NUMERIC_CHECK|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }
}
