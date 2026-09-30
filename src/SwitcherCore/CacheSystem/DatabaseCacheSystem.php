<?php
namespace WCAA\SwitcherCore\CacheSystem;


use DI\Annotation\Inject;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Models\Devices\Device;
use WCAA\Models\SwitcherCoreAction;
use WCAA\Models\User\User;
use WCAA\Storage\SwitcherCoreActionStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\Response;

class DatabaseCacheSystem implements CacheSystemInterface
{
    /**
     * @Inject
     * @var SwitcherCoreActionStorage
     */
    protected $actionStorage;

    function __construct(SwitcherCoreActionStorage $actionStorage)
    {
        $this->actionStorage = $actionStorage;
    }

    function get(Request $request) {
        $storage = $this->actionStorage->getLastByHash($request->getHash());
        $resp =  (new Response())
            ->setUser($storage->getUser())
            ->setDevice($storage->getDevice())
            ->setArguments($storage->getArguments())
            ->setData($storage->getData())
            ->setTime($storage->getTime())
            ->setModule($storage->getModule())
            ->setMeta($storage->getMeta())
            ->setSource('database')
            ->setStatus($storage->getStatus())
            ->setArguments($storage->getArguments());
        if($resp->getData() === null && isset($storage->getMeta()['error'])) {
            $exception = new SwitcherCoreException($storage->getMeta()['error']['message']);
            $resp->setError($exception);
        }
        return $resp;
    }
    function getWithoutTimeout(Request $request) {
        $storage = $this->actionStorage->getLastWithoutTimeoutByHash($request->getHash());

        $resp = (new Response())
            ->setUser($storage->getUser())
            ->setDevice($storage->getDevice())
            ->setArguments($storage->getArguments())
            ->setData($storage->getData())
            ->setTime($storage->getTime())
            ->setModule($storage->getModule())
            ->setMeta($storage->getMeta())
            ->setSource('database')
            ->setStatus($storage->getStatus())
            ->setArguments($storage->getArguments());
        if($resp->getData() === null && isset($storage->getMeta()['error'])) {
            $exception = new SwitcherCoreException($storage->getMeta()['error']['message']);
            $resp->setError($exception);
        }
        return $resp;
    }
    function write(Response $data)
    {
        $this->actionStorage->add(
            (new SwitcherCoreAction())
                ->setDevice($data->getDevice())
                ->setUser($data->getUser())
                ->setHash($data->getHash())
                ->setMeta($data->getMeta())
                ->setArguments($data->getArguments())
                ->setModule($data->getModule())
                ->setStatus($data->getStatus())
                ->setData($data->getDataAsArray())
        );
        return $data->getHash();
    }



}