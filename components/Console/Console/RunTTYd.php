<?php


namespace WCC\Console\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCC\Console\Controllers\Controller;

class RunTTYd extends AbstractComponentCommand
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    function config()
    {
        //For all module console commands added prefix - module name
        $this->setName('run-ttyd-server')
            ->setDescription("Run TTYd service");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("<info>Try to run ttyd as service</info>");
        if(!_env('CONSOLE_ENABLE_WEB', false)) {
            $output->writeln("<error>TTYd is disabled. Please, enable parameter CONSOLE_ENABLE_WEB=1</error>");
            return self::INVALID;
        }
        $size = _env('CONSOLE_FONT_SIZE', 14);
        passthru("exec /www/components/Console/bin/ttyd -t fontSize=$size -W -b /ttyd/console -a  wca -v console:open-for-web 2>&1", $exit);

        return self::SUCCESS;
    }

}
