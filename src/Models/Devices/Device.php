<?php


namespace WCAA\Models\Devices;


use WCAA\App;
use WCAA\Models\AbstractModel;

class Device extends AbstractModel
{
    /**
     * @morm.name=ip
     * @var string
     */
    protected $ip = '0.0.0.0';

    /**
     * @prop.display=root
     * @morm
     * @var string
     */
    protected $location = '';



    /**
     * @morm.name=name
     * @var string
     */
    protected $name = '';

    /**
     * @prop.display=root
     * @morm.name=description
     * @var string
     */
    protected $description = '';

    /**
     * @var DeviceModel
     */
    protected $model;

    /**
     * @morm.name=model_id
     * @prop.display=no
     * @var int
     */
    protected $model_id;

    /**
     * @prop.display=root
     * @var DeviceAccess
     */
    protected $access;

    /**
     * @prop.display=no
     * @morm.name=access_id
     * @var int
     */
    protected $access_id;

    /**
     * @prop.display=root
     * @morm.name=params
     * @var array
     */
    protected $params;

    /**
     * @prop.display=root
     * @morm.name=updated_at
     * @var string
     */
    protected $updated_at;

    /**
     * @prop.display=root
     * @morm.name=created_at
     * @var false|string
     */
    protected $created_at;


    /**
     * @prop.display=root
     * @morm
     * @var
     */
    protected $mac;

    /**
     * @prop.display=root
     * @morm
     * @var
     */
    protected $serial;

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $group_id = -1;

    /**
     * @var DeviceGroup
     */
    protected $group;

    /**
     * @morm
     * @prop.display=root
     * @var null | array
     */
    protected $pollers = null;

    /**
     * @morm
     * @prop.display=root
     * @var null | array
     */
    protected $coordinates = null;


    /**
     * @morm
     * @var bool
     */
    protected $enabled;


    /**
     * @return array|null
     */
    public function getPollers(): ?array
    {
        return $this->pollers;
    }

    /**
     * @param array|null $pollers
     * @return Device
     */
    public function setPollers(?array $pollers): Device
    {
        $this->pollers = $pollers;
        return $this;
    }



    /**
     * @return mixed
     */
    public function getMac()
    {
        return $this->mac;
    }

    /**
     * @param mixed $mac
     * @return Device
     */
    public function setMac($mac)
    {
        $this->mac = $mac;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getSerial()
    {
        return $this->serial;
    }

    /**
     * @param mixed $serial
     * @return Device
     */
    public function setSerial($serial)
    {
        $this->serial = $serial;
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
     * @return Device
     */
    public function setIp(string $ip): Device
    {
        $this->ip = $ip;
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
     * @return Device
     */
    public function setName(string $name): Device
    {
        $this->name = $name;
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
     * @return Device
     */
    public function setDescription(string $description): Device
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return null | DeviceModel
     */
    public function getModel(): ?DeviceModel
    {
        return $this->model;
    }

    /**
     * @param DeviceModel $model
     * @return Device
     */
    public function setModel(DeviceModel $model): Device
    {
        $this->model = $model;
        return $this;
    }

    /**
     * @return DeviceAccess
     */
    public function getAccess(): DeviceAccess
    {
        return $this->access;
    }

    /**
     * @param DeviceAccess $access
     * @return Device
     */
    public function setAccess(DeviceAccess $access): Device
    {
        $this->access = $access;
        return $this;
    }


    function getParamByName($name, $strict = false)
    {
        if (isset($this->params[$name])) {
            return $this->params[$name];
        }
        if($param = $this->model->getParamByName($name)) {
            return  $param;
        }
        if ($strict) {
            throw new \Exception("Param with name $name not found");
        }
        return null;
    }

    function setParamByName($name, $value)
    {
        $this->params[$name] = $value;
        return $this;
    }

    /**
     * @return array
     */
    public function getParams(): ?array
    {
        return $this->params;
    }

    /**
     * @param array $params
     * @return Device
     */
    public function setParams(?array $params): Device
    {
        $this->params = $params;
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
     * @return Device
     */
    public function setUpdatedAt($updated_at)
    {
        $this->updated_at = $updated_at;
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
     * @return Device
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
        $this->params = App::getInstance()->conf('devices.device_params');
        $this->enabled = true;
        parent::__construct($id);
    }

    /**
     * @return string
     */
    public function getLocation(): string
    {
        return $this->location;
    }

    /**
     * @param string $location
     * @return Device
     */
    public function setLocation(string $location): Device
    {
        $this->location = $location;
        return $this;
    }

    /**
     * @return DeviceGroup
     */
    public function getGroup(): DeviceGroup
    {
        return $this->group;
    }

    /**
     * @param DeviceGroup $group
     * @return Device
     */
    public function setGroup(DeviceGroup $group): Device
    {
        $this->group = $group;
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
     * @return Device
     */
    public function setCoordinates(?array $coordinates): Device
    {
        $this->coordinates = $coordinates;
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
     * @return Device
     */
    public function setEnabled(bool $enabled): Device
    {
        $this->enabled = $enabled;
        return $this;
    }





}
