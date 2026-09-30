<?php

namespace WCC\Console;

use WCAA\Infrastructure\Components\Installer\InstallerAbstract;

class Installer extends InstallerAbstract
{
    /**
     * @Inject
     * @var \PDO
     */
    protected $pdo;
    function install()
    {
        $this->executeMigrations();
    }

    function uninstall()
    {
        $this->executeMigrations('down');
    }

    function enable()
    {

    }

    function disable()
    {
    }
}
