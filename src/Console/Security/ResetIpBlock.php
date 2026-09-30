<?php

namespace WCAA\Console\Security;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Interfaces\CacheInterface;

class ResetIpBlock extends AbstractCommand
{

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName("security:reset:ipblock")
            ->addArgument("ip", InputArgument::OPTIONAL, "IP address for reset", "")
            ->setDescription("Reset blocked IP addresses");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        if($ip = $input->getArgument("ip")){
            $this->cache->delete("auth_attempts-{$ip}");
            $this->output->writeln("IP {$ip} has been reset.");
        } else {
            $this->cache->deleteByRegex('/^auth_attempts-.*/');
            $this->output->writeln("All ip addresses have been reset.");
        }
        return self::SUCCESS;
    }
}