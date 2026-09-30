<?php


namespace WCAA;


use DI\Container;
use DI\ContainerBuilder;
use DI\DependencyException;
use DI\NotFoundException;
use Monolog\Logger;
use WCAA\Api\Handlers\HttpErrorHandler;
use Spiral\Goridge\RPC\RPC;
use WCAA\Infrastructure\Compiller;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\User\User;
use WCAA\Storage\SwitcherCoreActionStorage;
use WCAA\Storage\SystemComponentsStorage;
use WCAA\Storage\UserStorage;
use Slim\Factory\AppFactory;
use WCAA\SwitcherCore\CacheSystem\CacheSystemInterface;
use WCAA\SwitcherCore\CacheSystem\DatabaseCacheSystem;
use WCAA\SwitcherCore\CacheSystem\MemCacheSystem;

class App
{
    /**
     * @var self
     */
    static protected $app;

    /**
     * @var array
     */
    protected $config;


    /**
     * @var ComponentInjector
     */
    protected $componentInjector;

    /**
     * @var Container
     */
    protected $container;

    public static function init()
    {
        $app = new self();
        self::$app = $app;
                $app->config = require __DIR__ . '/../config/global.php';

        // Instantiate PHP-DI ContainerBuilder
        $containerBuilder = new ContainerBuilder();

//        if ($app->config['production']) { // Should be set to true in production
//            $containerBuilder->writeProxiesToFile(true, __DIR__ . '/../var/cache');
//        }
        $containerBuilder->useAnnotations(true);
        $containerBuilder->useAutowiring(true);

        // Set up settings
        $settings = require __DIR__ . '/../app/settings.php';
        $settings($containerBuilder);

        // Set up dependencies
        $dependencies = require __DIR__ . '/../app/dependencies.php';
        $dependencies($containerBuilder);

        // Build PHP-DI Container instance
        /**
         * @var Container
         */
        $container = $containerBuilder->build();
        $container->set(App::class, $app);

        if ($app->conf('switcher_core.cache_system') == 'memcache') {
            $container->set(CacheSystemInterface::class, function () use ($app, $container) {
                return new MemCacheSystem($app, $container->get(CacheInterface::class));
            });

        } else {
            $container->set(CacheSystemInterface::class, function () use ($container) {
                return new DatabaseCacheSystem($container->get(SwitcherCoreActionStorage::class));
            });
        }

        $app->container = $container;

        //Add Module injector
        $componentInjector = new ComponentInjector($container->get(Logger::class), $container, $container->get(SystemComponentsStorage::class));

        $componentInjector->addRules($app)
            ->addConsoles($app)
            ->addEnvParams($app)
            ->addEventListeners($app);
        $app->componentInjector = $componentInjector;

        $container->get(EventObserverStorage::class)->attachAllDirectoryEvents();
        $container->set(ComponentInjector::class, $componentInjector);


        return $app;
    }


    /**
     * @return ComponentInjector
     */
    public function getComponentInjector()
    {
        return $this->componentInjector;
    }


    public function getContainer()
    {
        return self::$app->container;
    }

    public static function getInstance()
    {
        return self::$app;
    }

    /**
     * Read a config value by dot-path, e.g. conf('api.auth.strict_rules').
     *
     * @param string $propertyName
     * @return mixed|null
     */
    public function conf($propertyName)
    {
        $value = $this->config;
        foreach (explode(".", $propertyName) as $element) {
            if (!is_array($value) || !isset($value[$element])) {
                return null;
            }
            $value = $value[$element];
        }
        return $value;
    }

    public function setConfig($config)
    {
        $this->config = $config;
        return $this;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function resetRRWorkers() {
        $this->getContainer()->get(EventObserverStorage::class)->notify("system:reload-web-workers", true);
        return true;
    }

    /**
     * @return \Slim\App
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function buildSlimApp()
    {
        // Instantiate the app
        AppFactory::setContainer($this->container);
        $app = AppFactory::create();
        $this->container->set(\Slim\App::class, $app);
        $callableResolver = $app->getCallableResolver();
        // Register middleware
        $middleware = require __DIR__ . '/../app/middleware.php';
        $middleware($app);
        // Register routes
        $routes = require __DIR__ . '/../app/routes.php';
        $routes($app);
        $app->addRoutingMiddleware();
        //Stack traces and file paths must not be returned to API clients in production.
        //They are still written to the log in both modes.
        $displayErrorDetails = !$this->conf('production');
        $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
        // HttpErrorHandler exists fully built (matches the {error:{type,
        // description,...}} shape the frontend already parses everywhere
        // via err.response.data.error.description) but was never actually
        // registered — addErrorMiddleware()'s return value (the one place
        // you attach a custom handler) was being discarded, so Slim's own
        // bare default handler caught everything instead: any exception
        // that isn't a DomainRecordNotFoundException/RecordNotFoundException
        // (the only two Action::__invoke() catches itself) or a Slim
        // HttpException produced a blank "Slim Application Error" HTML
        // page with no JSON body at all, confirmed live on a real ONT
        // delete failure. Wiring it in is a strict improvement: same
        // response shape the frontend already handles, just actually
        // populated instead of an unparseable HTML page.
        // Built directly (not via the DI container's autowiring) — its
        // constructor needs Slim's own CallableResolverInterface/
        // ResponseFactoryInterface, which aren't things our container
        // knows how to build; $app already has both.
        $httpErrorHandler = new HttpErrorHandler($app->getCallableResolver(), $app->getResponseFactory());
        $httpErrorHandler->setDisplayStackTrace($displayErrorDetails);
        $httpErrorHandler->setLogger($this->container->get(Logger::class));
        $errorMiddleware->setDefaultErrorHandler($httpErrorHandler);
        return $app;
    }


    /**
     * @return User
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function getSysUser()
    {
        return $this->container->get(UserStorage::class)->getById(-1);
    }

    public function getConsoleUser()
    {
        return $this->container->get(UserStorage::class)->getById(-2);
    }
}
