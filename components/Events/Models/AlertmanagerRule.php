<?php

namespace WCC\Events\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;




class AlertmanagerRule extends AbstractModel
{

    /**
     * @morm
     * @var bool
     */
    protected $enabled;

    /**
     * @morm
     * @var bool
     */
    protected $internal;

    /**
     * @morm
     * @var string
     */
    protected $created_at;

    /**
     * @morm
     * @var string
     */
    protected $updated_at;

    /**
     * @morm
     * @var string
     */
    protected $group_name;

    /**
     * @morm
     * @var string
     */
    protected $alert_name;

    /**
     * @morm
     * @var string
     */
    protected $expression;

    /**
     * @morm
     * @var string
     */
    protected $for;

    /**
     * @morm
     * @var string
     */
    protected $severity;

    /**
     * @morm
     * @var string
     */
    protected $annotation_summary;

    /**
     * @morm
     * @var string
     */
    protected $annotation_description;

    /**
     * @return mixed
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * @param mixed $created_at
     * @return AlertmanagerRule
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    function __construct($id = null)
    {
        $this->created_at = date("Y-m-d H:i:s");
        $this->updated_at = date("Y-m-d H:i:s");
        $this->internal = false;
        parent::__construct($id);
    }

    /**
     * @return mixed
     */
    public function getUpdatedAt()
    {
        return $this->updated_at;
    }

    /**
     * @param mixed $updated_at
     * @return AlertmanagerRule
     */
    public function setUpdatedAt($updated_at)
    {
        $this->updated_at = $updated_at;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getGroupName()
    {
        return $this->group_name;
    }

    /**
     * @param mixed $group_name
     * @return AlertmanagerRule
     */
    public function setGroupName($group_name)
    {
        $this->group_name = $group_name;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getAlertName()
    {
        return $this->alert_name;
    }

    /**
     * @param mixed $alert_name
     * @return AlertmanagerRule
     */
    public function setAlertName($alert_name)
    {
        $this->alert_name = $alert_name;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getExpression()
    {
        return $this->expression;
    }

    /**
     * @param mixed $expression
     * @return AlertmanagerRule
     */
    public function setExpression($expression)
    {
        $this->expression = $expression;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getFor()
    {
        return $this->for;
    }

    /**
     * @param mixed $for
     * @return AlertmanagerRule
     */
    public function setFor($for)
    {
        $this->for = $for;
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
     * @return AlertmanagerRule
     */
    public function setSeverity($severity)
    {
        $this->severity = $severity;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getAnnotationSummary()
    {
        return $this->annotation_summary;
    }

    /**
     * @param mixed $annotation_summary
     * @return AlertmanagerRule
     */
    public function setAnnotationSummary($annotation_summary)
    {
        $this->annotation_summary = $annotation_summary;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getAnnotationDescription()
    {
        return $this->annotation_description;
    }

    /**
     * @param mixed $annotation_description
     * @return AlertmanagerRule
     */
    public function setAnnotationDescription($annotation_description)
    {
        $this->annotation_description = $annotation_description;
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
     * @return AlertmanagerRule
     */
    public function setEnabled(bool $enabled): AlertmanagerRule
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function isInternal(): bool
    {
        return $this->internal;
    }

    public function setInternal(bool $internal): AlertmanagerRule
    {
        $this->internal = $internal;
        return $this;
    }



}
