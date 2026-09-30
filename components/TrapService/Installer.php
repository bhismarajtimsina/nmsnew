<?php

namespace WCC\TrapService;

use DI\Annotation\Inject;
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

    function enable()
    {
        $this->pdo->query("UPDATE system_schedule SET state = 'ENABLED' WHERE `key` = 'trapservice_clear_old_logs'");
    }

    function disable()
    {
        $this->pdo->query("UPDATE system_schedule SET state = 'DISABLED' WHERE `key` = 'trapservice_clear_old_logs'");
    }

    function uninstall()
    {
        $this->executeMigrations('down');
    }
}
