<?php


namespace WCAA\Models;


class Permission
{
    protected $key;
    protected $routes;
    protected $name;

    /**
     * @return mixed
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param mixed $key
     * @return Permission
     */
    public function setKey($key)
    {
        $this->key = $key;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getRoutes()
    {
        return $this->routes;
    }

    /**
     * @param mixed $routes
     * @return Permission
     */
    public function setRoutes($routes)
    {
        $this->routes = $routes;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param mixed $name
     * @return Permission
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    function __construct($key, $name, $routes = []) {
        $this->key = $key;
        $this->name = $name;
        $this->routes = $routes;
    }
    function __toString() {
        return "{$this->name} ($this->key)" ;
    }
}