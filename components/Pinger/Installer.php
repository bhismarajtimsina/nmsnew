<?php

namespace WCC\Pinger;

use WCAA\Infrastructure\Components\Installer\InstallerAbstract;

class Installer extends InstallerAbstract
{
    function install()
    {
        $this
            ->output
            ->writeln("<comment>ICMP/TCP pinger for your devices. Component requires all-ok-pinger</comment>");
        $this->executeMigrations();
        $port = _env('NGINX_EXPOSE');
        if(strpos($port, ":") !== false) {
            $port = explode(":", $port)[1];
        }
        $this->output->writeln("<comment>Please, configure your pinger  
    Host list URL(api.host_list_addr): http://127.0.0.1:{$port}/api/v1/component/pinger/pinger
    Update device statuses(api.report_addr): http://127.0.0.1:{$port}/api/v1/component/pinger/pinger
</comment>");
    }

    function uninstall()
    {
        $this->executeMigrations('down');
    }
}
