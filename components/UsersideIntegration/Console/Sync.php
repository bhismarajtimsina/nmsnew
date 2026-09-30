<?php


namespace WCC\UsersideIntegration\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCC\UsersideIntegration\Controllers\Controller;

class Sync extends AbstractComponentCommand
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    function config()
    {
        //For all module console commands added prefix - module name
        $this->setName('sync')
            ->addArgument("load-only", \Symfony\Component\Console\Input\InputArgument::IS_ARRAY, "Sync only types", _env('USERSIDE_SYNC_DATA', ['devices', 'boxes']))
            ->setDescription("Sync data from userside");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $output->writeln("<info>Syncing userside</info>");
        $this->controller->setIsDebug($output->isDebug());
        if ($output->isDebug()) {
            $output->writeln("DEBUG ENABLED...");
        }
        $controller = $this->controller
            ->setConsoleOutput($output);
        $types = $input->getArgument('load-only');

        foreach ($types as $type) {
            try {
                $method = 'sync' . ucfirst($type);
                $controller->$method();
            } catch (\Throwable $e) {
                $output->writeln("<error>

 ERROR sync $type - {$e->getMessage()} 
</error>");
                $output->writeln($e->getTraceAsString());
            }
        }

        return self::SUCCESS;
    }

}
