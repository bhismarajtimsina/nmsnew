<?php

namespace WCAA\Infrastructure;

use WCAA\App;
use WCAA\Interfaces\CacheInterface;

class CacheControl
{
    /**
     * @Inject
     * @var CacheInterface
     */
    protected $memcache;

    /**
     * @Inject
     * @var \Redis
     */
    protected $redisCache;

    /**
     * Used for updates
     * @return self
     */
    function refreshAll() {
        $this->flushMemcache();
        $this->clearSwCache();
        Compiller::getSelf()->flushAll();
        App::getInstance()->resetRRWorkers();
        return $this;
    }

    function clearSwCache()
    {
        exec('rm -f /www/var/cache/*.cache');
    }

    function flushMemcache() {
        $this->memcache->flush(0);
        return $this;
    }

    function flushRedis() {
        $this->redisCache->flushAll();
        return $this;
    }

    function flushCompiledObjects() {
        Compiller::getSelf()->flushAll();
        return $this;
    }

}