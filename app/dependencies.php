<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Prometheus\Exception\StorageException;
use WCAA\App;

return function (ContainerBuilder $containerBuilder) {
    $app = App::getInstance();
    $containerBuilder->addDefinitions([
        Monolog\Logger::class => function () {
            $loggerSettings = App::getInstance()->conf('logger');
            $logger = new Logger($loggerSettings['name']);
            $processor = new UidProcessor();
            $logger->pushProcessor($processor);
            $logger->pushProcessor(new \WCAA\Infrastructure\LogProcessor);
            $logger->setHandlers([new \Monolog\Handler\StreamHandler(
                    App::getInstance()->conf('logger.path') . '/global.log',
                    App::getInstance()->conf('logger.level')
                )]
            );
            return $logger;
        },
        \WCAA\Infrastructure\TrustedIps::class => function () use ($app) {
            return new \WCAA\Infrastructure\TrustedIps($app->conf('api.auth.trusted_networks'));
        },
        PDO::class => function () {
            return NewPdoConnection();
        },
        /**
         * Switcher core client dependencies
         */
        \SwitcherCore\Switcher\CoreConnector::class => function (\Psr\Container\ContainerInterface $c) use ($app) {
            $cachePath = _env('ENVIRONMENT') === 'PRODUCTION' ? __DIR__ . '/../var/cache/switcher-core.cache' : '';
            $connector = (new \SwitcherCore\Switcher\CoreConnector(\SwitcherCore\Modules\Helper::getBuildInConfig(), $cachePath));
            $connector->setLogger($c->get(Logger::class));
            return $connector;
        },
        \SwitcherCore\Config\ModelCollector::class => function (\Psr\Container\ContainerInterface $c) use ($app) {
//            if (_env('ENVIRONMENT') === 'PRODUCTION' && $collector = \WCAA\Infrastructure\Compiller::getSelf()->get('swc-model-collector')) {
//                return $collector;
//            }
            $modelCollector = \SwitcherCore\Config\ModelCollector::init(
                (new \SwitcherCore\Config\Reader(\SwitcherCore\Modules\Helper::getBuildInConfig()))
            );
           // \WCAA\Infrastructure\Compiller::getSelf()->set('swc-model-collector'я , $modelCollector);
            return $modelCollector;
        },
        \SwitcherCore\Config\ModuleCollector::class => function (\Psr\Container\ContainerInterface $c) use ($app) {
//            if (_env('ENVIRONMENT') === 'PRODUCTION' && $collector = \WCAA\Infrastructure\Compiller::getSelf()->get('swc-module-collector')) {
//                return $collector;
//            }
            $collector = \SwitcherCore\Config\ModuleCollector::init(
                (new \SwitcherCore\Config\Reader(\SwitcherCore\Modules\Helper::getBuildInConfig()))
            );
            //\WCAA\Infrastructure\Compiller::getSelf()->set('swc-module-collector', $collector);
            return $collector;
        },

    ]);
    if ($app->conf('memcache.enabled')) {
        $containerBuilder->addDefinitions([
            \WCAA\Interfaces\CacheInterface::class => function () use ($app) {
                return new \WCAA\Infrastructure\CacheSystems\MemCache(
                    $app->conf('memcache.server'),
                    $app->conf('memcache.port')
                );
            },
            \SwitcherCore\Switcher\CacheInterface::class => function () use ($app) {
                return new \WCAA\Infrastructure\CacheSystems\MemCacheSwitcherCore(
                    $app->conf('memcache.server'),
                    $app->conf('memcache.port')
                );
            }
        ]);
    } else {
        $containerBuilder->addDefinitions([
            \WCAA\Interfaces\CacheInterface::class => function () {
                return new \WCAA\Infrastructure\CacheSystems\PhpCache();
            },
            \SwitcherCore\Switcher\CacheInterface::class => function () {
                return new \WCAA\Infrastructure\CacheSystems\PhpCacheSwitcherCore();
            }
        ]);
    }
    $containerBuilder->addDefinitions([
        \Redis::class => function () use ($app) {
            $redis = new Redis();
            $options = $app->conf('prometheus.exporter.options');
            try {
                $connection_successful = false;
                if ($options['persistent_connections'] !== false) {
                    $connection_successful = $redis->pconnect(
                        $options['host'],
                        (int)$options['port'],
                        (float)$options['timeout']
                    );
                } else {
                    $connection_successful = $redis->connect($options['host'], (int)$options['port'], (float)$options['timeout']);
                }
                if (!$connection_successful) {
                    throw new StorageException("Can't connect to Redis server {$options['host']}:{$options['port']}", 0);
                }
                if ($options['password'] !== null) {
                    $redis->auth($this->options['password']);
                }
                if (isset($options['database'])) {
                    $redis->select($this->options['database']);
                }
            } catch (\RedisException $e) {
                throw new StorageException("Can't connect to Redis server {$options['host']}:{$options['port']} with message: {$e->getMessage()}", 0, $e);
            }
            return $redis;
        }
    ]);
    $containerBuilder->addDefinitions([
        \Prometheus\CollectorRegistry::class => function (\Psr\Container\ContainerInterface $c) use ($app) {
            if ($storageType = $app->conf('prometheus.exporter.storage')) {
                switch ($storageType) {
                    case 'redis':
                        $storage = \Prometheus\Storage\Redis::fromExistingConnection($c->get(\Redis::class));
                        break;
                    case 'apc':
                        $storage = new \Prometheus\Storage\APCng();
                        break;
                    default:
                        $storage = new \Prometheus\Storage\InMemory();
                }
            } else {
                $storage = new \Prometheus\Storage\InMemory();
            }
            return new \Prometheus\CollectorRegistry($storage);
        },
    ]);
    $containerBuilder->addDefinitions([
        \WCAA\Infrastructure\Events\EventObserverStorage::class => function (\Psr\Container\ContainerInterface $c) {
            $observer = new \WCAA\Infrastructure\Events\EventObserverStorage($c->get(Logger::class), $c->get(App::class));
            return $observer;
        }
    ]);
    $containerBuilder->addDefinitions([
        \Spiral\Goridge\RPC\RPCInterface::class => function () use ($app) {
            return \Spiral\Goridge\RPC\RPC::create((string)$app->conf('roadrunner.rpc_address'));
        },
    ]);
    $containerBuilder->addDefinitions([
        \WCAA\Infrastructure\SystemInfo::class => function () {
            if (!\WCAA\Infrastructure\SystemInfo::isDataSetted()) {
                \WCAA\Infrastructure\SystemInfo::setSelfHostedData();
            }
            return new \WCAA\Infrastructure\SystemInfo();
        }
    ]);
    $containerBuilder->addDefinitions([
        \Superbalist\PubSub\Redis\RedisPubSubAdapter::class => function() {
            $predis = new Predis\Client(
                [
                    'host' => _env('REDIS_HOST', 'wca-redis'),
                    'port' => _env('REDIS_PORT', 6379),
                    'database' => 0,
                    'read_write_timeout' => 0
                ]
            );
            return new \Superbalist\PubSub\Redis\RedisPubSubAdapter($predis);
        }
    ]);
};
