<?php

namespace WCC\UsersideIntegration;

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
        $this->pdo->exec("UPDATE system_schedule SET state = 'ENABLED' WHERE `key` = 'userside_integration_sync'");
    }

    function disable()
    {
        $this->pdo->exec("UPDATE system_schedule SET state = 'ENABLED' WHERE `key` = 'userside_integration_sync'");
    }
}
