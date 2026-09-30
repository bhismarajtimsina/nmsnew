<?php

namespace WCAA\Models\System;

use WCAA\Models\AbstractModel;
use WCAA\Models\SystemComponent;

class Schedule extends AbstractModel
{
    /**
     * @morm
     * @var
     */
    protected $created_at;

    /**
     * @morm
     * @var
     */
    protected $command;

    /**
     * @morm
     * @var
     */
    protected $key;

    /**
     *
     * @var SystemComponent|null
     */
    protected $component;

    /**
     * @prop.display=no
     * @morm
     * @var
     */
    protected $component_id;

    /**
     * @morm
     * @var
     */
    protected $latest;

    /**
     * @morm
     * @var
     */
    protected $crontab;

    /**
     * @morm
     * @var
     */
    protected $state;

    /**
     * @morm
     * @var bool
     */
    protected $editable;


    public function isEditable()
    {
        return $this->editable;
    }

    public function setEditable($editable)
    {
        $this->editable = $editable;
        return $this;
    }

     /**
     * @return mixed
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * @param mixed $created_at
     * @return Schedule
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * @param mixed $command
     * @return Schedule
     */
    public function setCommand($command)
    {
        $this->command = $command;
        return $this;
    }

    /**
     * @return SystemComponent|null
     */
    public function getComponent(): ?SystemComponent
    {
        return $this->component;
    }

    /**
     * @param SystemComponent|null $component
     * @return Schedule
     */
    public function setComponent(?SystemComponent $component): Schedule
    {
        $this->component = $component;
        return $this;
    }



    /**
     * @return mixed
     */
    public function getLatest()
    {
        return $this->latest;
    }

    /**
     * @param mixed $latest
     * @return Schedule
     */
    public function setLatest($latest)
    {
        $this->latest = $latest;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCrontab()
    {
        return $this->crontab;
    }

    /**
     * @param mixed $crontab
     * @return Schedule
     */
    public function setCrontab($crontab)
    {
        $this->crontab = $crontab;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @param mixed $state
     * @return Schedule
     */
    public function setState($state)
    {
        $this->state = $state;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param mixed $key
     * @return Schedule
     */
    public function setKey($key)
    {
        $this->key = $key;
        return $this;
    }



    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
    }

}
