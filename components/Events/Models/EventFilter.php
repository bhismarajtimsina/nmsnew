<?php

namespace WCC\Events\Models;

use WCAA\Models\User\User;
use WCAA\Storage\UserStorage;

class EventFilter
{
    protected $device;
    protected $labels;
    protected $key;
    protected $onlyNotResolved;
    protected $name;
    protected $names;
    protected $severity;
    /**
     * @var User | null
     */
    protected $user;

    public function setKeyByLabels() {
        if($this->labels) {
            ksort($this->labels);
            $this->key = md5(json_encode($this->labels));
        }
        return $this;
    }

    /**
     * @return User|null
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * @param User|null $user
     * @return EventFilter
     */
    public function setUser(?User $user): EventFilter
    {
        $this->user = $user;
        return $this;
    }




    /**
     * @return mixed
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * @param mixed $device
     * @return EventFilter
     */
    public function setDevice($device)
    {
        $this->device = $device;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getLabels()
    {
        return $this->labels;
    }

    /**
     * @param mixed $labels
     * @return EventFilter
     */
    public function setLabels($labels)
    {
        $this->labels = $labels;
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
     * @return EventFilter
     */
    public function setKey($key)
    {
        $this->key = $key;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getOnlyNotResolved()
    {
        return $this->onlyNotResolved;
    }

    /**
     * @param mixed $onlyNotResolved
     * @return EventFilter
     */
    public function setOnlyNotResolved($onlyNotResolved)
    {
        $this->onlyNotResolved = $onlyNotResolved;
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
     * @return EventFilter
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getSeverity()
    {
        return $this->severity;
    }

    /**
     * @param mixed $severity
     * @return EventFilter
     */
    public function setSeverity($severity)
    {
        $this->severity = $severity;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getNames()
    {
        return $this->names;
    }

    /**
     * @param mixed $names
     * @return EventFilter
     */
    public function setNames($names)
    {
        $this->names = $names;
        return $this;
    }


    protected $start;
    protected $stop;

    /**
     * @return mixed
     */
    /**
     * Which console's ranking to read, and which tiers of it to keep.
     *
     * Every alarm rule records how it ranks on each console — primary opens the
     * shift, secondary sits in a second panel, muted stays off both. Filtering
     * on that is what lets one event list serve two audiences: the ISP asks for
     * its primary tier and gets device, link and port faults, while the same
     * endpoint gives a reseller their subscribers' optical alarms.
     */
    protected $focusConsole;
    protected $focusTiers = [];

    public function getFocusConsole()
    {
        return $this->focusConsole;
    }

    public function getFocusTiers(): array
    {
        return $this->focusTiers;
    }

    /**
     * @param string $console 'isp' or 'reseller'
     * @param array $tiers any of 'primary', 'secondary', 'muted'
     */
    public function setFocus($console, array $tiers): EventFilter
    {
        $this->focusConsole = $console;
        $this->focusTiers = $tiers;
        return $this;
    }

    public function getStart()
    {
        return $this->start;
    }

    /**
     * @param mixed $start
     * @return EventFilter
     */
    public function setStart($start)
    {
        $this->start = $start;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getStop()
    {
        return $this->stop;
    }

    /**
     * @param mixed $stop
     * @return EventFilter
     */
    public function setStop($stop)
    {
        $this->stop = $stop;
        return $this;
    }



}