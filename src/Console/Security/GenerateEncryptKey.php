<?php

namespace WCAA\Console\Security;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\Security\Encryption;
use WCAA\Interfaces\CacheInterface;

class GenerateEncryptKey extends AbstractCommand
{

    /**
     * @Inject
     * @var Encryption
     */
    protected $deviceAccessEcnryption;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName("security:generate-encrypt-key")
            ->addOption("force", "f",  InputOption::VALUE_NEGATABLE, "Force regenerate password", false)
            ->setDescription("(Re-)Generate encrypt password (for encrypt device accesses");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        if(!$input->getOption("force") && $this->deviceAccessEcnryption->isEncryptPasswordExists()) {
            $this->output->writeln("<error>Encrypt password already exists
Please, add option -f for regenerate password.</error>");
            return 0;
        }
        $newKey = $this->deviceAccessEcnryption->createKey();
        $this->output->writeln("<info>Encrypt password created - {$newKey}</info>");
        return self::SUCCESS;
    }
}