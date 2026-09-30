<?php

namespace WCC\Events\Controllers;

use DI\Container;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Infrastructure\Paginator\DbPagination;
use WCAA\Models\User\User;
use WCAA\Storage\SystemComponentsStorage;
use WCC\Events\Models\AlertmanagerRule;
use WCC\Events\Models\EventFilter;
use WCC\Events\Storage\AlertmanagerRulesStorage;
use WCC\Events\Storage\EventsStorage;
class Controller extends AbstractComponentController
{
    /**
     * @Inject
     * @var EventsStorage
     */
    protected $storage;

    /**
     * @Inject
     * @var Container
     */
    protected $container;

    /**
     * @Inject
     * @var EventObserverStorage
     */
    protected $observer;

    /**
     * @Inject
     * @var SystemComponentsStorage
     */
    protected $systemStorage;


    /**
     * @Inject
     * @var AlertmanagerRulesStorage
     */
    protected $alertRuleStorage;


    function getEventsByFilter(EventFilter $eventFilters, DbPagination $pag = null)
    {
        return $this->storage->getByEventFilter($eventFilters, $pag);
    }

    function getAnnotationsMap()
    {
        return $this->storage->getAnnotationsMap();
    }

    function getEventNames()
    {
        return $this->storage->getEventNames();
    }

    function resolveEvent(User $user, $eventId)
    {
        $event = $this->storage->getById($eventId);
        $event = $this->storage->update(
            $event
                ->setResolvedAt(date("Y-m-d H:i:s"))
                ->setResolvedBy($user)
        );
        $this->observer->notify("event:resolved", $event);
        return $event;
    }

    /**
     * @return mixed|Alertmanager
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    function getAlertManager() {
        $alertManager =  $this->container->get(Alertmanager::class);
        $alertManager->setModuleConfig($this->moduleConfig);
        return  $alertManager;
    }

    /**
     * @return array
     * @throws \Exception
     */
    function getConfiguration() {
        $config = $this->systemStorage->getByKey($this->moduleConfig['name']);
        return $config->getConfiguration();
    }

    function setDisabledEvents($config) {
        $component = $this->systemStorage->getByKey($this->moduleConfig['name']);
        $cfg = $component->getConfiguration();
        if(!is_array($cfg)) {
            $cfg = [];
        }
        $cfg['disabled_system_events'] = $config;
        $component->setConfiguration($cfg);
        $this->systemStorage->update($component);
        return $config;
    }

}
