<?php

namespace WCAA\Api\Actions\System\Components;

use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Storage\SystemComponentsStorage;

abstract class AbstractComponent extends PrivateAction
{
    /**
     * @Inject
     * @var ComponentInjector
     */
    protected $injector;


    /**
     * @Inject
     * @var SystemComponentsStorage
     */
    protected $storage;

}