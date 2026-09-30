<?php


namespace WCAA\Models\Devices;


use WCAA\App;
use WCAA\Models\AbstractModel;

class DeviceInterface extends AbstractModel
{

    const TYPE_ETHER = 'ETH';
    const TYPE_SFP = 'SFP';
    const TYPE_PON = 'PON';
    const TYPE_ONU = 'ONU';
    const TYPE_UNI = 'UNI';
    const TYPE_GE = 'GE';
    const TYPE_SFP_1G = '1G-SFP';
    const TYPE_SFP_10G = '10G-SFP';
    const TYPE_UNKNOWN = 'UNKNOWN';
    const TYPE_VLAN = 'VLAN';

    /**
     * @morm
     * @prop.display=root
     * @morm string
     */
    protected $created_at;

    /**
     * @morm
     * @prop.display=root
     * @morm string
     */
    protected $updated_at;

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $device_id;

    /**
     * @var Device
     */
    protected $device = null;

    /**
     * @morm
     * @var int
     */
    protected $bind_key = -1;

    /**
     * @morm
     * @var string
     */
    protected $name = '';

    /**
     * @morm
     * @var string
     */
    protected $type = '';

    /**
     * @morm
     * @prop.display=root
     * @var array
     */
    protected $params = null;

    /**
     * @morm
     * @prop.display=root
     * @var string
     */
    protected $billing_link = '';

    /**
     * @morm
     * @prop.display=root
     * @var string
     */
    protected $ip = '';

    /**
     * @morm
     * @prop.display=root
     * @var string
     */
    protected $agreement = '';

    /**
     * @morm
     * @prop.display=root
     * @var string
     */
    protected $description = '';

    /**
     * @morm
     * @var string
     */
    protected $status = '';


    /**
     * @morm
     * @prop.display=root
     * @var bool
     */
    protected $poll_enabled = true;

    /**
     * @morm
     * @var string | null
     */
    protected $parent_bind_key = null;

    /**
     * @morm
     * @prop.display=root
     * @var null | array
     */
    protected $coordinates = null;


    /**
     * @morm
     * @prop.display=root
     * @var string
     */
    protected $comment = '';


    /**
     * @var string
     * @morm
     */
    protected $status_changed  = null;


    /**
     * @return string
     */
    public function getComment(): string
    {
        return $this->comment;
    }

    /**
     * @param string $comment
     * @return DeviceInterface
     */
    public function setComment(string $comment): DeviceInterface
    {
        $this->comment = $comment;
        return $this;
    }



    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
        $this->updated_at = date("Y-m-d H:i:s");
        $this->status_changed = date("Y-m-d H:i:s");
    }

    /**
     * @return string | null
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return int
     */
    public function getDeviceId(): int
    {
        return $this->device_id;
    }

    /**
     * @param int $device_id
     * @return DeviceInterface
     */
    public function setDeviceId(int $device_id): DeviceInterface
    {
        $this->device_id = $device_id;
        return $this;
    }

    /**
     * @return bool
     */
    public function isPollEnabled(): bool
    {
        return $this->poll_enabled;
    }

    /**
     * @param bool $poll_enabled
     * @return DeviceInterface
     */
    public function setPollEnabled(bool $poll_enabled): DeviceInterface
    {
        $this->poll_enabled = $poll_enabled;
        return $this;
    }



    /**
     * @param string|null $status
     * @return DeviceInterface
     */
    public function setStatus(?string $status): DeviceInterface
    {
        if($status && $status != $this->status) {
            $this->status_changed = date("Y-m-d H:i:s");
        }
        $this->status = $status;
        return $this;
    }



    /**
     * @return false|string
     */
    public function getUpdatedAt()
    {
        return $this->updated_at;
    }

    /**
     * @param false|string $updated_at
     * @return DeviceInterface
     */
    public function setUpdatedAt($updated_at)
    {
        $this->updated_at = $updated_at;
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
     * @return DeviceInterface
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return Device
     */
    public function getDevice(): Device
    {
        return $this->device;
    }

    /**
     * @param Device $device
     * @return DeviceInterface
     */
    public function setDevice(Device $device): DeviceInterface
    {
        $this->device = $device;
        return $this;
    }

    public function getBindKey()
    {
        return $this->bind_key;
    }

    /**
     * @param int $bind_key
     * @return DeviceInterface
     */
    public function setBindKey($bind_key): DeviceInterface
    {
        $this->bind_key = $bind_key;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return DeviceInterface
     */
    public function setName(string $name): DeviceInterface
    {
        $this->name = $name;
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
     * @return DeviceInterface
     */
    public function setType(string $type): DeviceInterface
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * @param array $params
     * @return DeviceInterface
     */
    public function setParams($params): DeviceInterface
    {
        $this->params = $params;
        return $this;
    }

    /**
     * @return string
     */
    public function getBillingLink(): string
    {
        return $this->billing_link;
    }

    /**
     * @param string $billing_link
     * @return DeviceInterface
     */
    public function setBillingLink(string $billing_link): DeviceInterface
    {
        $this->billing_link = $billing_link;
        return $this;
    }

    /**
     * @return string
     */
    public function getIp(): string
    {
        return $this->ip;
    }

    /**
     * @param string $ip
     * @return DeviceInterface
     */
    public function setIp(string $ip): DeviceInterface
    {
        $this->ip = $ip;
        return $this;
    }

    /**
     * @return string
     */
    public function getAgreement(): string
    {
        return $this->agreement;
    }

    /**
     * @param string $agreement
     * @return DeviceInterface
     */
    public function setAgreement(string $agreement): DeviceInterface
    {
        $this->agreement = $agreement;
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
     * @return DeviceInterface
     */
    public function setDescription(string $description): DeviceInterface
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return string
     */
    public function getParentBindKey()
    {
        return $this->parent_bind_key;
    }

    /**
     * @param string|null $parent_bind_key
     * @return DeviceInterface
     */
    public function setParentBindKey($parent_bind_key): DeviceInterface
    {
        $this->parent_bind_key = $parent_bind_key;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getCoordinates(): ?array
    {
        return $this->coordinates;
    }

    /**
     * @param array|null $coordinates
     * @return DeviceInterface
     */
    public function setCoordinates(?array $coordinates): DeviceInterface
    {
        $this->coordinates = $coordinates;
        return $this;
    }

    /**
     * @return false|string|null
     */
    public function getStatusChanged()
    {
        return $this->status_changed;
    }

    /**
     * @param false|string|null $status_changed
     * @return DeviceInterface
     */
    public function setStatusChanged($status_changed)
    {
        $this->status_changed = $status_changed;
        return $this;
    }

}
