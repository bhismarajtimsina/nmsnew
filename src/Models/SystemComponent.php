<?php


namespace WCAA\Models;


/**
 * Class User
 * @package WCAA\Models
 */
class SystemComponent extends AbstractModel
{
    /**
     * @morm
     * @var string
     */
        protected $name;

    /**
     * @morm
     * @var string
     */
        protected $key;

    /**
     * @morm
     * @var string
     */
        protected $namespace;

    /**
     * @morm
     * @var boolean
     */
        protected $enabled;

    /**
     * @prop.display=built_in
     * @morm.name=built_in
     * @var boolean
     */
     protected $builtIn;

    /**
     * @morm
     * @var array
     */
     protected $configuration;



    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return SystemComponent
     */
    public function setName(string $name): SystemComponent
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @param string $key
     * @return SystemComponent
     */
    public function setKey(string $key): SystemComponent
    {
        $this->key = $key;
        return $this;
    }

    /**
     * @return string
     */
    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    /**
     * @param string $namespace
     * @return SystemComponent
     */
    public function setNamespace(string $namespace): SystemComponent
    {
        $this->namespace = $namespace;
        return $this;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param bool $enabled
     * @return SystemComponent
     */
    public function setEnabled(bool $enabled): SystemComponent
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * @return bool
     */
    public function isBuiltIn(): bool
    {
        return $this->builtIn;
    }

    /**
     * @param bool $builtIn
     * @return SystemComponent
     */
    public function setBuiltIn(bool $builtIn): SystemComponent
    {
        $this->builtIn = $builtIn;
        return $this;
    }

    /**
     * @return array
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }

    /**
     * @param array $configuration
     * @return SystemComponent
     */
    public function setConfiguration(array $configuration): SystemComponent
    {
        $this->configuration = $configuration;
        return $this;
    }



}