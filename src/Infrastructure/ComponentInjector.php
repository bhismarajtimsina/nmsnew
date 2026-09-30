<?php


namespace WCAA\Infrastructure;


use DI\Container;
use DI\DependencyException;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Components\Installer\InstallerAbstract;
use WCAA\Interfaces\CacheInterface;
use WCAA\Storage\SystemComponentsStorage;

class ComponentInjector
{

    protected $componentsDirectory;

    /**
     * @var OutputInterface
     */
    protected $output;

    protected $routeUrlPrefix = "/api/v1/component";

    /**
     * @var SystemComponentsStorage
     */
    protected $systemComponentsStorage;

    /**
     * @var CacheInterface
     */
    protected $cacheInterface;

    /**
     * @var Container
     */
    protected $di;

    /**
     * @var \Monolog\Logger
     */
    protected $logger;

    function setOutputInterface(OutputInterface $output)
    {
        $this->output = $output;
        return $this;
    }

    function __construct(\Monolog\Logger $logger, Container $di, SystemComponentsStorage $componentsStorage)
    {
        $this->logger = $logger;
        $this->routeUrlPrefix = App::getInstance()->getConfig()['api']['base_path'] . '/component';
        $this->componentsDirectory = realpath(__DIR__ . '/../../components');
        $this->systemComponentsStorage = $componentsStorage;
        $this->cacheInterface = $di->get(CacheInterface::class);
        $this->di = $di;
    }

    public static $FETCHED_COMPONENTS = null;

    /**
     * @return null
     */
    public static function getFetchedComponents()
    {
        return self::$FETCHED_COMPONENTS;
    }

    /**
     * @param null $FETCHED_COMPONENTS
     */
    public static function setFetchedComponents($FETCHED_COMPONENTS): void
    {
        self::$FETCHED_COMPONENTS = $FETCHED_COMPONENTS;
    }

    private function getComponents()
    {
        if(self::$FETCHED_COMPONENTS) {
            return  self::$FETCHED_COMPONENTS;
        }
        $components = $this->fetchComponents();
        self::$FETCHED_COMPONENTS = $components;
        return $components;
    }

    function isComponentExist($componentName)
    {
        return isset($this->getComponents()[$componentName]);
    }

    function isComponentEnabled($componentName)
    {
        return isset($this->getComponents()[$componentName]) && $this->getComponents()[$componentName]['enabled'];
    }

    /**
     * @param $componentName
     * @return \WCAA\Infrastructure\Components\AbstractComponentController
     * @throws DependencyException
     * @throws \DI\NotFoundException
     * @throws \ErrorException
     */
    function getController($componentName)
    {
        if (!$this->isComponentEnabled($componentName)) {
            throw new \Exception("Component $componentName not enabled");
        }
        $config = $this->getEnabledComponentConfig($componentName);
        if (!isset($config['controller'])) {
            throw new \ErrorException("Component $componentName doesn't have controller");
        }
        if (!$this->di->has($config['controller'])) {
            throw new DependencyException("error inject {$config['controller']} in component {$componentName}");
        }
        return $this->di->get($config['controller']);
    }

    function getEnabledComponentConfig($componentName)
    {
        if (!$this->isComponentEnabled($componentName)) {
            throw new \Exception("Component $componentName not enabled");
        }
        if (!isset($this->getComponents()[$componentName])) {
            throw new \Exception("Component with name $componentName not found");
        }
        $conf = $this->getComponents()[$componentName];
        unset($conf['routes']);
        return $conf;
    }

    /**
     * @return array
     */
    function fetchComponents()
    {
        $components = [];
        $installedComponents = array_map(function ($e) {
            return $e->getKey();
        }, $this->systemComponentsStorage->fetchAll());
        $enabledComponentKeys = $this->getEnabledComponentKeys();
        foreach (scandir($this->componentsDirectory) as $componentDir) {
            if (file_exists($this->componentsDirectory . '/' . $componentDir . '/config.php')) {
                $conf = require $this->componentsDirectory . '/' . $componentDir . '/config.php';
                if (!isset($conf['rules'])) {
                    $conf['rules'] = [];
                }
                if (!isset($conf['controller'])) {
                    $conf['controller'] = null;
                }
                if (!isset($conf['console'])) {
                    $conf['console'] = [];
                }
                if (!isset($conf['description'])) {
                    $conf['description'] = '';
                }
                if (!isset($conf['installer'])) {
                    $conf['installer'] = null;
                }
                if (!isset($conf['dependencies'])) {
                    $conf['dependencies'] = [];
                }
                $components[$conf['name']] = $conf;
                $components[$conf['name']]['installed'] = in_array($conf['name'], $installedComponents);
                $components[$conf['name']]['enabled'] = isset($enabledComponentKeys[$conf['name']]) && $enabledComponentKeys[$conf['name']]->isEnabled();
                $components[$conf['name']]['path'] = $this->componentsDirectory . '/' . $componentDir;
                $components[$conf['name']]['store'] = isset($enabledComponentKeys[$conf['name']]) ? $enabledComponentKeys[$conf['name']] : null;
            }
        }
        return $components;
    }

    function getComponentByName($name)
    {
        $filtered = array_values(array_filter($this->getComponents(), function ($e) use ($name) {
            return $name === $e['name'];
        }));
        if (isset($filtered[0])) {
            return $filtered[0];
        }
        throw new \InvalidArgumentException("Component by name $name not found");
    }

    /**
     * @return array
     */
    function getEnabledComponentKeys()
    {
        $enabled = [];
        try {
            $components = array_filter($this->systemComponentsStorage->fetchAll(), function ($e) {
                return $e->isEnabled();
            });
            foreach ($components as $en) {
                $enabled[$en->getKey()] = $en;
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), [$e->getMessage(), $e->getLine(), $e->getTraceAsString()]);
            return [];
        }
        return $enabled;
    }

    function addRoutes(Group $group)
    {
        foreach ($this->getComponents() as $data) {
            if (!$data['enabled']) continue;
            if (isset($data['routes']) && $data['routes']) {
                $group->group("/{$data['name']}", function (Group $group) use ($data) {
                    foreach ($data['routes'] as $route) {
                        $group->map($route['methods'], $route['pattern'], $route['callable']);
                    }
                });
            }
        }
        return $group;
    }

    /**
     * Adding rules to API
     *
     * @param App $app
     * @return $this
     */
    function addRules(App $app)
    {
        $config = $app->getConfig();
        $rules = [];
        foreach ($config['api']['auth']['rules'] as $rule) {
            $rules[$rule['key']] = $rule;
        }
        foreach ($this->getComponents() as $component) {
            if (!$component['enabled']) continue;
            foreach ($component['rules'] as $rule) {
                if(!isset($rule['routes'])) continue;
                if(!is_array($rule['routes'])) continue;
                $rule['routes'] = array_map(function ($e) use ($component) {
                    return str_replace(':', ":{$this->routeUrlPrefix}/{$component['name']}", $e);
                }, $rule['routes']);
                if(isset($rules[$rule['key']]['routes'])) {
                    $rules[$rule['key']]['routes'] = array_merge($rules[$rule['key']]['routes'], $rule['routes']);
                } else {
                    $rules[$rule['key']] = $rule;
                }
            }
        }
        $config['api']['auth']['rules'] = $rules;
        $app->setConfig($config);
        return $this;
    }

    /**
     * Adding rules to API
     *
     * @param App $app
     * @return $this
     */
    function addConsoles(App $app)
    {
        $config = $app->getConfig();
        foreach ($this->getComponents() as $data) {
            if (!$data['enabled']) continue;
            if (isset($data['console']) && is_array($data['console'])) {
                $config['console']['handlers'] = array_merge($config['console']['handlers'], $data['console']);
            }
        }
        $app->setConfig($config);
        return $this;
    }

    /**
     * Adding event listeners
     *
     * @param App $app
     * @return $this
     */
    function addEventListeners(App $app)
    {
        $config = $app->getConfig();
        foreach ($this->getComponents() as $data) {
            if (!$data['enabled']) continue;
            if (isset($data['event_listeners']) && is_array($data['event_listeners'])) {
                $config['event_listeners'] = array_merge($config['event_listeners'], $data['event_listeners']);
            }
        }
        $app->setConfig($config);
        return $this;
    }

    /**
     * Adding params to system
     *
     * @param App $app
     * @return $this
     */
    function addEnvParams(App $app)
    {
        $config = $app->getConfig();
        foreach ($this->getComponents() as $data) {
            if(!$this->isComponentExist($data['name'])) continue;
            if(!$this->isComponentEnabled($data['name'])) continue;
            if (isset($data['env_params']) && is_array($data['env_params'])) {
                foreach ($data['env_params'] as $key => $val) {
                    $config['env_params'][$key] = $val;
                }
            }
        }
        $app->setConfig($config);
        return $this;
    }

    function getComponentsPath()
    {
        $response = [];
        foreach ($this->getComponents() as $componentName => $componentConfig) {
            $response[] = [
                'component' => $componentName,
                'path' => $componentConfig['path'],
            ];
        }
        return $response;
    }

    function getDependentComponentKeys($componentName)
    {
        $dependents = [];
        foreach ($this->getComponents() as $component) {
            if (!$component['dependencies']) continue;
            if (array_filter($component['dependencies'], function ($e) use ($componentName) {
                return $e == $componentName;
            })) {
                $dependents[] = $component['name'];
            }
        }
        return $dependents;
    }

    function getDependenciesComponentKeys($componentName)
    {
        return $this->getComponentByName($componentName)['dependencies'];
    }

    function disableComponent($componentName, $disableDependences = true)
    {
        $storeComponent = $this->systemComponentsStorage->getByKey($componentName);
        if ($storeComponent === null) {
            throw new InvalidArgumentException("Component not installed or migration not executed");
        }

        try {
            $component = $this->getComponents()[$componentName];
            if ($component['installer']) {
                /**
                 * @var $installer InstallerAbstract
                 */
                $installer = $this->di->get($component['installer'])(null,$this->output);
                $installer->disable();
            } else {
                throw new InvalidArgumentException("Component doesn't have installer");
            }
        } catch (\Exception $e) {
            $this->logger->error("Error calling installer for component $componentName");
        }

        if ($dependents = $this->getDependentComponentKeys($componentName)) {
            foreach ($dependents as $dependent) {
                $this->writeOut("<info>Component $componentName has dependents component $dependent</info>");
                if ($this->isComponentEnabled($dependent) && $disableDependences) {

                    $this->disableComponent($dependent);
                } elseif ($this->isComponentEnabled($dependent)) {
                    throw new SupportException("Component $componentName can't be disabled, because used in $dependent");
                }
            }
        }
        $this->writeOut("<info>Disable component $componentName</info>");
        $this->systemComponentsStorage->update($storeComponent->setEnabled(false));
        $this->cacheInterface->flush();
        $this->reloadHttpWorkers();

        return true;
    }

    function enableComponent($componentName, $enableDependencies = true)
    {
        $storeComponent = $this->systemComponentsStorage->getByKey($componentName);
        if ($storeComponent === null) {
            throw new InvalidArgumentException("Component not installed or migration not executed");
        }
        if ($dependencies = $this->getDependenciesComponentKeys($componentName)) {
            $this->writeOut("<info>Component $componentName has dependencies</info>");
            foreach ($dependencies as $dependency) {
                $dependencyComponent = $this->getComponentByName($dependency);
                if (!$dependencyComponent) {
                    throw new SupportException("Component $componentName depends from $dependency, but $dependency not found (not installed)");
                }
                if (!$dependencyComponent['enabled'] && $enableDependencies) {
                    $this->enableComponent($dependency);
                } elseif (!$dependencyComponent['enabled']) {
                    throw new SupportException("Component $componentName can't be enabled, because dependency component $dependency disabled. Please, enable $dependency first");
                } else {
                    $this->writeOut("<info>Dependency with name $dependency already enabled</info>");
                }
            }
        }
        $this->writeOut("<info>Enable component $componentName</info>");
        $this->systemComponentsStorage->update($storeComponent->setEnabled(true));
        $this->cacheInterface->flush();

        try {
            $component = $this->getComponents()[$componentName];
            if ($component['installer']) {
                /**
                 * @var $installer InstallerAbstract
                 */
                $installer = $this->di->get($component['installer'])(null,$this->output);
                $installer->enable();
            } else {
                throw new InvalidArgumentException("Component doesn't have installer");
            }
        } catch (\Exception $e) {
            $this->logger->error("Error calling installer for component $componentName");
        }
        $this->reloadHttpWorkers();

        return true;
    }

    function installComponent($componentName)
    {
        if (!isset($this->getComponents()[$componentName])) {
            throw new InvalidArgumentException("Component with name $componentName not found ");
        }
        $component = $this->getComponents()[$componentName];
        if ($component['installer']) {
            if (!$component['installed']) {
                $this->di->get($component['installer'])(null,$this->output)->install();
            } else {
                $this->writeOut("<info>Component {$component['name']} already installed</info>");
            }
        } else {
            throw new InvalidArgumentException("Component doesn't have installer");
        }
        $this->writeOut("<info>Component {$component['name']} success installed, next you can enable component from web panel or use command wca component:control {$component['name']} enable</info>");
        $this->cacheInterface->flush();
    }

    function uninstallComponent($componentName)
    {
        if (!isset($this->getComponents()[$componentName])) {
            throw new InvalidArgumentException("Component with name $componentName not found ");
        }
        $component = $this->getComponents()[$componentName];
        $storeComponent = $this->systemComponentsStorage->getByKey($componentName);
        if ($storeComponent === null) {
            throw new InvalidArgumentException("Component not installed or migration not executed");
        }
        $this->disableComponent($componentName, false);
        if ($component['installer']) {
            $this->di->get($component['installer'])(null, $this->output)->uninstall();
        } else {
            throw new InvalidArgumentException("Component doesn't have installer");
        }
        $this->writeOut("<info>Component {$component['name']} success uninstalled</info>");
        $this->cacheInterface->flush();
    }

    private function writeOut($message)
    {
        if ($this->output) {
            $this->output->writeln($message);
        }
    }

    /**
     * Ask the HTTP workers to restart after a component's state changed.
     *
     * Component routes are registered once per worker, at boot, from the
     * component list cached in self::$FETCHED_COMPONENTS. Flushing the shared
     * cache does not touch that per-process snapshot, so workers started before
     * the change keep serving their old route table - producing intermittent
     * 404s on a freshly enabled component, depending on which worker answers.
     *
     * Failure here is not fatal: the change is already persisted, and a manual
     * `wca system:reload-http-workers` (or any restart) still applies it.
     */
    private function reloadHttpWorkers()
    {
        try {
            App::getInstance()->resetRRWorkers();
            $this->writeOut("<info>Requested HTTP worker reload so route changes take effect</info>");
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Component state changed but HTTP workers were not reloaded - " .
                "run 'wca system:reload-http-workers' to apply: {$e->getMessage()}"
            );
            $this->writeOut("<comment>Could not reload HTTP workers automatically - run 'wca system:reload-http-workers'</comment>");
        }
    }

}