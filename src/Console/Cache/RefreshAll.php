<?php


namespace WCAA\Console\Cache;


use DI\Annotation\Inject;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Infrastructure\CacheControl;
use WCAA\Interfaces\CacheInterface;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RefreshAll extends AbstractCommand
{
    /**
     * @Inject
     * @var CacheControl
     */
    protected $cacheControl;

    protected function configure()
    {
        $this->setName("cache:flush")
            ->setDescription("Refresh all caches. Used after changing settings");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cacheControl->refreshAll();
        return self::SUCCESS;
    }
}