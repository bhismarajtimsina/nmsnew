<?php

namespace WCC\Notifications;

use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\Installer\InstallerAbstract;

class Installer extends InstallerAbstract
{
    /**
     * @Inject
     * @var ComponentInjector
     */
    protected $componentInjector;

    function install()
    {
        $this->executeMigrations();
        if(!$this->componentInjector->isComponentEnabled('events')) {
            $this->output->writeln("<comment>
Notifications required events, but not installed/not enabled!
</comment>");
            $this->componentInjector->disableComponent('notifications');
        }
    }

    function uninstall()
    {
        $this->executeMigrations('down');
    }
}
