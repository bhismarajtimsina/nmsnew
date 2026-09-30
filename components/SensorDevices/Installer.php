<?php

namespace WCC\SensorDevices;

use WCAA\Infrastructure\Components\Installer\InstallerAbstract;

class Installer extends InstallerAbstract
{
    function install()
    {
        $this->executeMigrations();
    }

    function uninstall()
    {
        $this->executeMigrations('down');
    }
}