<?php


namespace WCAA\Infrastructure\CacheSystems;


use WCAA\Interfaces\CacheInterface;

class PhpCache implements CacheInterface
{
    protected $cache = [];

    public function set( $key, $value, $timeout = 1) {
        $this->cache[$key] = [
            'value' => $value,
            'timeout' => $timeout + time(),
        ];
        return $this;
    }
    public function get( $key) {
        if(isset($this->cache[$key]) && $this->cache[$key]['timeout'] > time()) {
            return $this->cache[$key]['value'];
        } else {
            return null;
        }
    }

    function add( $key, $value, $expiration = 30)
    {
        if($this->get($key) === null) {
            return $this->set($key, $value, $expiration);
        }
        throw new \MemcachedException("Key $key is exist");
    }



    function flush($delay = 0)
    {
       $this->cache = [];
       return $this;
    }

    function fetchAll()
    {
        return $this->cache;
    }

    function getAllKeys()
    {
        return array_keys($this->cache);
    }

    function delete( $key)
    {
        unset($this->cache[$key]);
        return $this;
    }

    function isExist($key)
    {
        return $this->get($key) !== null;
    }

    function deleteByRegex($mask)
    {
        foreach ($this->cache as $key => $_) {
            if(preg_match($mask, $key)) {
                unset($this->cache[$key]);
            }
        }
        return $this;
    }

}
