<?php

namespace WCC\Macros;

use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\Installer\InstallerAbstract;

class Installer extends InstallerAbstract
{
    /**
     * @Inject
     * @var ComponentInjector
     */
    protected $injector;

    function install()
    {
        if(!$this->injector->isComponentEnabled('olts')) {
            throw new SupportException("Component  macros requires olts for working!");
        }
        $this->executeMigrations();
    }

    function uninstall()
    {
        $this->executeMigrations('down');
    }
}
