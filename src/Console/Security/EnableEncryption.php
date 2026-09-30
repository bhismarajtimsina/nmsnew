<?php

namespace WCAA\Console\Security;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\EnvParamsEditor;
use WCAA\Infrastructure\Security\Encryption;
use WCAA\Interfaces\CacheInterface;
use WCAA\Storage\Devices\DeviceAccessStorage;

class EnableEncryption extends AbstractCommand
{

    /**
     * @Inject
     * @var Encryption
     */
    protected $deviceAccessesEncryption;

    /**
     * @Inject
     * @var EnvParamsEditor
     */
    protected $envParamsEditor;


    /**
     * @Inject
     * @var DeviceAccessStorage
     */
    protected $deviceAccessStorage;

    /**
     * @return void
     */
    protected function configure()
    {
        $this->setName("security:enable-encryption")
            ->addOption("force", "f",  InputOption::VALUE_NEGATABLE, "Force encrypt without prompts", false)
            ->setDescription("Enable encryption for device accesses. This command will make changes to the database and this operation cannot be undone");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        if(!$input->getOption("force")){
            $this->output->writeln("<error>
                
    This command will encrypt access to the equipment in the database!
    The action is irreversible!!
    If you lose your decryption key, you will lose you passwords!!!
</error>
        ");
            if(!$this->confirm("Are you sure you want to proceed?", false)) {
                $this->output->writeln("Canceled!");
                return 0;
            }
        }
        if(!$this->deviceAccessesEncryption->isEncryptPasswordExists()) {
            $this->output->writeln("Encryption key not generated, trying to create");
            $key = $this->deviceAccessesEncryption->createKey();
            $this->output->writeln("<info>Encrypt password created - {$key}</info>");
        } else {
            $this->deviceAccessesEncryption->loadKey();
        }
        if($this->deviceAccessesEncryption->isEncryptEnabled()) {
            $this->output->writeln("Encryption already enabled!");
            return 0;
        }

        $accesses = $this->deviceAccessStorage->fetchAll();
        foreach($accesses as $access) {
            $access = $access
                ->setLogin($this->deviceAccessesEncryption->encryptValue($access->getLogin()))
                ->setPassword($this->deviceAccessesEncryption->encryptValue($access->getPassword()))
                ->setPrivateCommunity($this->deviceAccessesEncryption->encryptValue($access->getPrivateCommunity()))
                ->setPublicCommunity($this->deviceAccessesEncryption->encryptValue($access->getPublicCommunity()));
            $this->deviceAccessStorage->update($access);
            $this->output->writeln("Access '{$access->getName()}' encrypted!");
        }

        $this->envParamsEditor->writeParams([
            'SECURE_ENCRYPT_ACCESSES' => 'yes'
        ]);
        $this->output->writeln("Encryption success enabled!");

        return self::SUCCESS;
    }
}