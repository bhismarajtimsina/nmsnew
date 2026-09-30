<?php

namespace WCC\QrGenerator;

use WCAA\Infrastructure\Components\Installer\InstallerAbstract;

class Installer extends InstallerAbstract
{
    function install()
    {
        exec("mkdir -p /www/var/qr-generator");
        $this->executeMigrations();
    }

    function uninstall()
    {
        $this->executeMigrations('down');
    }
}
