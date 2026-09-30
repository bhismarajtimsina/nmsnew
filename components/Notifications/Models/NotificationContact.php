<?php

namespace WCC\Notifications\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;

class NotificationContact extends AbstractModel
{
    const TYPE_EMAIL = 'EMAIL';
    const TYPE_PHONE = 'PHONE';
    const TYPE_TELEGRAM_ID = 'TELEGRAM_ID';
    const TYPE_PHONE_FOR_TELEGRAM = 'PHONE_FOR_TELEGRAM';


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
     * @prop.display=no
     * @var int
     */
    protected $user_id;

    /**
     * @var User
     */
    protected $user;


    /**
     * @morm
     * @var string
     */
    protected $type = '';


    /**
     * @morm
     * @var string
     */
    protected $value;


    /**
     * @morm
     * @var bool
     */
    protected $enabled = true;

    /**
     * @morm
     * @var string
     */
    protected $description = '';

    /**
     * @morm
     * @var array | null
     */
    protected $params;

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
        $this->updated_at = date("Y-m-d H:i:s");
    }

    /**
     * @return string
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * @param string $created_at
     * @return NotificationContact
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return string
     */
    public function getUpdatedAt()
    {
        return $this->updated_at;
    }

    /**
     * @param string $updated_at
     * @return NotificationContact
     */
    public function setUpdatedAt($updated_at)
    {
        $this->updated_at = $updated_at;
        return $this;
    }

    /**
     * @return User
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @param User $user
     * @return NotificationContact
     */
    public function setUser(User $user): NotificationContact
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return NotificationContact
     */
    public function setType(string $type): NotificationContact
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @param string $value
     * @return NotificationContact
     */
    public function setValue(string $value): NotificationContact
    {
        $this->value = $value;
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
     * @return NotificationContact
     */
    public function setEnabled(bool $enabled): NotificationContact
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @param string $description
     * @return NotificationContact
     */
    public function setDescription(string $description): NotificationContact
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getParams(): ?array
    {
        return $this->params;
    }

    /**
     * @param array|null $params
     * @return NotificationContact
     */
    public function setParams(?array $params): NotificationContact
    {
        if($params) {
           if(!isset($params['severities'])) {
               $params['severities'] = ['INFO', 'WARNING', 'CRITICAL'];
           }
           if(!isset($params['send_only_by_devices'])) {
               $params['send_only_by_devices'] = false;
           }
           if(!isset($params['ignore_notifications'])) {
               $params['ignore_notifications'] = [];
           }
        }
        $this->params = $params;
        return $this;
    }



}
