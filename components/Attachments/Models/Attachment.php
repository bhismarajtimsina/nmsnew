<?php

namespace WCC\Attachments\Models;

use Ramsey\Uuid\Uuid;
use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\User\User;

class Attachment extends AbstractModel
{
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
    protected $created_at;


    /**
     * @morm
     * @var string
     */
    protected $object_type;

    /**
     * @morm
     * @var int
     */
    protected $object_id;

    /**
     * @morm
     * @var string
     */
    protected $uuid;

    /**
     * @morm
     * @var string
     */
    protected $extension;

    /**
     * @morm
     * @var null|array
     */
    protected $extra = null;

    public function getExtra(): ?array
    {
        return $this->extra;
    }

    public function setExtra(?array $extra): Attachment
    {
        $this->extra = $extra;
        return $this;
    }



    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
        $this->uuid = Uuid::uuid4()->toString();

    }

    public function getUserId(): int
    {
        return $this->user_id;
    }

    public function setUserId(int $user_id): Attachment
    {
        $this->user_id = $user_id;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): Attachment
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @return false|string
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * @param false|string $created_at
     * @return Attachment
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    public function getObjectType(): string
    {
        return $this->object_type;
    }

    public function setObjectType(string $object_type): Attachment
    {
        $this->object_type = $object_type;
        return $this;
    }

    public function getObjectId(): int
    {
        return $this->object_id;
    }

    public function setObjectId(int $object_id): Attachment
    {
        $this->object_id = $object_id;
        return $this;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function setUuid(string $uuid): Attachment
    {
        $this->uuid = $uuid;
        return $this;
    }


    public function getExtension(): string
    {
        return $this->extension;
    }

    public function setExtension(string $extension): Attachment
    {
        $this->extension = $extension;
        return $this;
    }


    public function getDestinationPath(): string
    {
        return "/www/var/attachments/{$this->getObjectType()}/{$this->getObjectId()}/{$this->getUuid()}.{$this->getExtension()}";
    }

    public function getDestinationDir(): string
    {
        return "/www/var/attachments/{$this->getObjectType()}/{$this->getObjectId()}";
    }
    public function getFilename(): string
    {
        return "{$this->getUuid()}.{$this->getExtension()}";
    }

    public function prepareDestinationPath() {
        exec("mkdir -p /www/var/attachments/{$this->getObjectType()}/{$this->getObjectId()}");
        return $this;
    }

}
