<?php


namespace WCC\TrapService\Console;


use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCAA\Interfaces\CacheInterface;
use WCC\TrapService\Controllers\Controller;

class Handler extends AbstractComponentCommand
{
    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;


    function config()
    {
        //For all module console commands added prefix - module name
        $this->setName('handler')
            ->setDescription("Trap handler");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {
        $this->controller->setIsDebug($output->isDebug());
        if($output->isDebug()) {
            $output->writeln("DEBUG ENABLED...");
        }
        $contents = file_get_contents("php://stdin");
        $data = json_decode($contents, true);

        if(json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON ERROR: ".json_last_error_msg());
        }
        $this->logger->info("trap handled", $data);
        $response = $this->controller
            ->setConsoleOutput($output)
            ->handleTrap($data);
        $output->writeln("Saved response from {$response->getDevice()->getIp()} object {$response->getObject()} with id = {$response->getId()}");
        return self::SUCCESS;
    }

}
