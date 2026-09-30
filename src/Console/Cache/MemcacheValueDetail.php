<?php


namespace WCAA\Console\Cache;


use DI\Annotation\Inject;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use WCAA\App;
use WCAA\Console\AbstractCommand;
use WCAA\Interfaces\CacheInterface;
use WCAA\Storage\UserAuthKeyStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MemcacheValueDetail extends AbstractCommand
{
    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    protected function configure()
    {
        $this->setName("cache:memcache:key-value")
            ->setDescription("Print key value")
            ->addArgument("key", InputArgument::REQUIRED)
        ;

    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $key = trim($input->getArgument('key'));
        $cache = $this->cache->get($key);
        if($key === null) {
            $output->writeln("Key $key not found");
            return self::INVALID;
        }
        $output->writeln(var_export($cache));
        return self::SUCCESS;
    }
}