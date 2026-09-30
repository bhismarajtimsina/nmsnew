<?php

namespace WCC\Notifications\Controllers\Channels;

use WCAA\Storage\SystemComponentsStorage;

abstract class AbstractChannel implements ChannelInterface
{
    protected $config = null;

    /**
     * @var SystemComponentsStorage
     */
    protected $componentsStorage;

    function __construct(SystemComponentsStorage $componentsStorage) {
        $this->componentsStorage = $componentsStorage;
        $config = $componentsStorage->getByKey('notifications')->getConfiguration();
        if(isset($config[$this->getSourceName()])) {
            $this->config = $config[$this->getSourceName()];
        } else {
            $this->config = $this->prepareConfig([]);
        }
    }

    function isConfigured() {
        return $this->config !== null;
    }

    abstract function getSourceName();

    function buildTemplate($name, $params = []) {
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'template' => $this->config['templates'][$name]
        ]), [
            'strict_variables' => false,
        ]);
        return $twig->render(
            'template',
            $params
        );
    }

    function getTemplate($name)
    {
        return $this->config['templates'][$name];
    }

    function getConfiguration()
    {
       return $this->prepareConfig($this->config);
    }
    function getDefaultConfig()
    {
        return $this->prepareConfig(null);
    }

    function updateConfiguration($config) {
        $component = $this->componentsStorage->getByKey('notifications');
        $cfg = $component->getConfiguration();
        $cfg[$this->getSourceName()] = $this->prepareConfig($config);
        $this->componentsStorage->update($component->setConfiguration($cfg));
        return $this->componentsStorage->getByKey('notifications')->getConfiguration()[$this->getSourceName()];
    }

    abstract function prepareConfig($config);
}