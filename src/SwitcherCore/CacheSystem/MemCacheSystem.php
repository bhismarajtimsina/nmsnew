<?php
namespace WCAA\SwitcherCore\CacheSystem;


use DI\Annotation\Inject;
use WCAA\App;
use WCAA\Exceptions\SwitcherCore\CacheNotFound;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\Devices\Device;
use WCAA\Models\SwitcherCoreAction;
use WCAA\Models\User\User;
use WCAA\Storage\SwitcherCoreActionStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\Response;

class MemCacheSystem implements CacheSystemInterface
{

    /**
     * @var CacheInterface
     */
    protected $cacheSystem;

    /**
     * @var App
     */
    protected $app;

    function __construct(App $app, CacheInterface $cache)
    {
        $this->cacheSystem = $cache;
        $this->app = $app;
    }

    function get(Request $request) {
        /**
         * @var Response $storage
         */
        $storage = $this->cacheSystem->get("SWC:".$request->getHash());
        if(!$storage) {
            throw new CacheNotFound("Cache not found by hash={$request->getHash()}");
        }
        $liveSeconds = time() - \DateTime::createFromFormat("Y-m-d H:i:s",$storage->getTime())->getTimestamp();
        if($liveSeconds > $this->app->conf('switcher_core.cache_actualize_timeout_sec')) {
               throw new CacheNotFound("Cache not found by hash={$request->getHash()}");
        }
        return  $storage;
    }
    function getWithoutTimeout(Request $request) {
       $storage = $this->cacheSystem->get("SWC:".$request->getHash());
       if(!$storage) {
           throw new CacheNotFound("Cache not found by hash={$request->getHash()}");
       }
       return $storage;
    }
    function write(Response $data)
    {
        $this->cacheSystem->set("SWC:{$data->getHash()}",
            $data,
            $this->app->conf('switcher_core.cache_timeout_sec')
        );
        return $data->getHash();
    }



}