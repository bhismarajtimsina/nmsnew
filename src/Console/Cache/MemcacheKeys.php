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

class MemcacheKeys extends AbstractCommand
{
    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    protected function configure()
    {
        $this->setName("cache:memcache:keys")
            ->setDescription("Listing of keys by mask")
            ->addArgument("key_mask", InputArgument::OPTIONAL)
            ->addOption("output", "o", InputOption::VALUE_OPTIONAL, "Output format. support: table, yaml, json", "table");
        ;

    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $key = trim($input->getArgument('key_mask'));
        $key = str_replace('*', ".*", $key);
        $keys = array_filter($this->cache->getAllKeys(), function ($e) use ($key) {
            if(!$key) return true;
            return preg_match("/^{$key}$/", $e);
        });
        switch ($input->getOption('output')) {
            case 'table':
                $table = new Table($output);
                $table->setHeaders([
                    'Key',
                ]);
                foreach ($keys as $key) {
                    $table->addRow([
                        $key,
                    ]);
                }
                $table->render();
                break;
            case 'json':
                $output->writeln($this->toJson($keys));
                break;
            case 'yaml':
                $output->writeln($this->toYaml($keys));
                break;
        }
        return self::SUCCESS;
    }
}