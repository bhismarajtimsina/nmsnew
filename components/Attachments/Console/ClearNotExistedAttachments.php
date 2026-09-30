<?php


namespace WCC\Attachments\Console;


use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;

class ClearNotExistedAttachments extends AbstractComponentCommand
{
    function config()
    {
        //For all module console commands added prefix - module name
        $this->setName('clear-not-existed')
            ->setDescription("Check all attachments and delete not existed files");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {

        return self::SUCCESS;
    }
}
