<?php

namespace WCAA\Models\System;

use WCAA\Models\AbstractModel;
use WCAA\Models\SystemComponent;

class ScheduleReport extends AbstractModel
{
    /**
     * @morm
     * @var string
     */
    protected $start_at;

    /**
     * @morm
     * @var  string | null
     */
    protected $stop_at;

    /**
     * @morm
     * @var string
     */
    protected $output;

    /**
     * @morm
     * @var
     */
    protected $error;

    /**
     * @morm
     * @var bool
     */
    protected $is_successful;

    /**
     * @morm
     * @prop.display=no
     * @var
     */
    protected $schedule_id;

    /**
     * @var Schedule
     */
    protected $schedule;

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->start_at = date("Y-m-d H:i:s");
    }

    /**
     * @return string
     */
    public function getStartAt()
    {
        return $this->start_at;
    }

    /**
     * @param string $start_at
     * @return ScheduleReport
     */
    public function setStartAt($start_at)
    {
        $this->start_at = $start_at;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getStopAt(): ?string
    {
        return $this->stop_at;
    }

    /**
     * @param string|null $stop_at
     * @return ScheduleReport
     */
    public function setStopAt(?string $stop_at): ScheduleReport
    {
        $this->stop_at = $stop_at;
        return $this;
    }

    /**
     * @return string
     */
    public function getOutput(): string
    {
        return $this->output;
    }

    /**
     * @param string $output
     * @return ScheduleReport
     */
    public function setOutput(string $output): ScheduleReport
    {
        $this->output = $output;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @param mixed $error
     * @return ScheduleReport
     */
    public function setError($error)
    {
        $this->error = $error;
        return $this;
    }

    /**
     * @return bool
     */
    public function isIsSuccessful(): bool
    {
        return $this->is_successful;
    }

    /**
     * @param bool $is_successful
     * @return ScheduleReport
     */
    public function setIsSuccessful(bool $is_successful): ScheduleReport
    {
        $this->is_successful = $is_successful;
        return $this;
    }

    /**
     * @return Schedule
     */
    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    /**
     * @param Schedule $schedule
     * @return ScheduleReport
     */
    public function setSchedule(Schedule $schedule): ScheduleReport
    {
        $this->schedule = $schedule;
        return $this;
    }




}