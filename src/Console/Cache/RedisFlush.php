<?php


namespace WCAA\Console\Cache;


use DI\Annotation\Inject;
use Prometheus\Storage\Redis;
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

class RedisFlush extends AbstractCommand
{
    /**
     * @Inject
     * @var \Redis
     */
    protected $cache;


    protected function configure()
    {
        $this->setName("cache:redis:flush-all")
            ->setDescription("Flush all keys in redis cache")
        ;

    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $this->cache->flushAll();
        $output->writeln("Success flushed!");
        return self::SUCCESS;
    }
}