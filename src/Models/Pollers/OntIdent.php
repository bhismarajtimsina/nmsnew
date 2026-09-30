<?php


namespace WCAA\Models\Pollers;


use WCAA\App;
use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;

class OntIdent extends AbstractModel
{
    const TYPE_MAC = 'MAC';
    const TYPE_SERIAL = 'SERIAL';

    /**
     * @morm
     * @prop.display=no
     * @var int
     */
    protected $interface_id;

    /**
     * @var DeviceInterface
     */
    protected $interface;

    /**
     * @morm
     * @var string
     */
    protected $created_at;

    /**
     * @morm
     * @var string
     */
    protected $type;

    /**
     * @morm
     * @var string
     */
    protected $ident;

    /**
     * @morm
     * @var array|null
     */
    protected $vendor_info = null;

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
    }

    /**
     * @return DeviceInterface
     */
    public function getInterface(): DeviceInterface
    {
        return $this->interface;
    }

    /**
     * @param DeviceInterface $interface
     * @return OntIdent
     */
    public function setInterface(DeviceInterface $interface): OntIdent
    {
        $this->interface = $interface;
        return $this;
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
     * @return OntIdent
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return string | null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return OntIdent
     */
    public function setType(string $type): OntIdent
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return string | null
     */
    public function getIdent(): ?string
    {
        return $this->ident;
    }

    /**
     * @param string $ident
     * @return OntIdent
     */
    public function setIdent(string $ident): OntIdent
    {
        $this->ident = $ident;
        return $this;
    }

    /**
     * @return array|null
     */
    public function getVendorInfo(): ?array
    {
        return $this->vendor_info;
    }

    public function setVendorInfo(?array $vendor_info): OntIdent
    {
        $this->vendor_info = $vendor_info;
        return $this;
    }



}