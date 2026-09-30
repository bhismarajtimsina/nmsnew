<?php


namespace WCAA\Console\System;


use Curl\Curl;
use DI\Annotation\Inject;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReloadExternalAppsConfiguration extends AbstractCommand
{
    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @Inject
     * @var Curl
     */
    protected $curl;

    protected function configure()
    {
        $this->setName("system:reload-external-apps")
            ->setDescription("Reload external apps configuration")
        ;
    }
    function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("Try reload prometheus configuration...");
        $output->writeln($this->curl->post($this->app->conf('prometheus.url') . '/-/reload'));

//        $output->writeln("Try reload alertmanager configuration...");
//        $output->writeln($this->curl->post($this->app->conf('prometheus.alertmanager_url') . '/-/reload'));

        $output->writeln("Sended to reload!");
        return self::SUCCESS;
    }
}
