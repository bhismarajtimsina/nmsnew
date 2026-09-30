<?php


namespace WCAA\Models\User;


use IPv4\SubnetCalculator;
use WCAA\App;
use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\DeviceGroup;

/**
 * Class User
 * @package WCAA\Models
 */
class User extends AbstractModel
{

    const STATUS_ENABLED = 'ENABLED';
    const STATUS_DISABLED = 'DISABLED';

    const LANG_EN = 'en';
    const LANG_RU = 'ru';
    const LANG_UA = 'ua';


    /**
     * @morm.name=id
     * @var int
     */
    protected $id;
    /**
     * @morm.name=name
     * @var string
     */
    protected $name;

    /**
     * @var UserRole
     */
    protected $role;

    /**
     * @prop.display=no
     * @morm.name=role_id
     * @var int
     */
    protected $role_id;

    /**
     * @morm.name=password
     * @prop.display=no
     * @var string
     */
    protected $password;

    /**
     * @morm.name=created_at
     * @var string
     */
    protected $created_at;

    /**
     * @morm.name=updated_at
     * @var string
     */
    protected $updated_at;

    /**
     * @var string
     * @morm.name=login
     */
    protected $login;

    /**
     * @var array
     */
    protected $last_activity;

    /**
     * @morm
     * @var
     */
    protected $status;

    /**
     * @morm
     * @var
     */
    protected $language;


    /**
     * @morm
     * @var array
     */
    protected $settings;

    /**
     * @prop.name=device_groups
     * @var DeviceGroup[]
     */
    protected $deviceGroups = [];

    /**
     * @morm
     * @var
     */
    protected $is_twofa;

    /**
     * @morm
     * @prop.display=no
     * @var
     */
    protected $twofa_token;

    /**
     * @return mixed
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * @param mixed $language
     * @return User
     */
    public function setLanguage($language)
    {
        $this->language = $language;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getId() : ?int
    {
        return $this->id;
    }

    function __construct($id = null)
    {
        parent::__construct($id);
        $this->created_at = date("Y-m-d H:i:s");
        $this->updated_at = date("Y-m-d H:i:s");
    }

    /**
     * @param int $id
     * @return User
     */
    public function setId($id): User
    {
        $this->id = $id;
        return $this;
    }

    public function isRulePermitted($ruleName) {
        if($this->role->getId() < 0) {
            return  true;
        }
        if($this->getId() < 0 ) {
            return  true;
        }
        return in_array($ruleName , $this->role->getPermissions());
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
     * @return User
     */
    public function setName(string $name): User
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return UserRole
     */
    public function getRole(): UserRole
    {
        return $this->role;
    }

    /**
     * @param UserRole $role
     * @return User
     */
    public function setRole(UserRole $role): User
    {
        $this->role = $role;
        $this->role_id = $role->getId();
        return $this;
    }

    /**
     * @return string
     */
    public function getLogin(): string
    {
        return $this->login;
    }

    /**
     * @param string $login
     * @return User
     */
    public function setLogin(string $login): User
    {
        $this->login = $login;
        return $this;
    }


    /**
     * @return string | null
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * @param string|null $password
     * @return User
     */
    public function setPassword(?string $password): User
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->created_at;
    }

    /**
     * @param string $created_at
     * @return User
     */
    public function setCreatedAt(string $created_at): User
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return string
     */
    public function getUpdatedAt(): string
    {
        return $this->updated_at;
    }

    /**
     * @param string $updated_at
     * @return User
     */
    public function setUpdatedAt(string $updated_at): User
    {
        $this->updated_at = $updated_at;
        return $this;
    }
    /**
     * @return array
     */
    public function getSettings()
    {
        if(!is_array($this->settings)) {
            $this->settings = [];
        }
        return fillArrayFromSkeleton(App::getInstance()->conf('panel.default_user_settings'),$this->settings);
    }

    /**
     * @param array $settings
     * @return User
     */
    public function setSettings($settings): User
    {
        if(!is_array($settings)) {
            $settings = [];
        }
        $this->settings = fillArrayFromSkeleton(App::getInstance()->conf('panel.default_user_settings'),$settings);
        return $this;
    }

    /**
     * @return array
     */
    public function getLastActivity(): array
    {
        return $this->last_activity;
    }

    /**
     * @param array $last_activity
     * @return User
     */
    public function setLastActivity(array $last_activity): User
    {
        $this->last_activity = $last_activity;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @param mixed $status
     * @return User
     */
    public function setStatus($status)
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return DeviceGroup[]
     */
    public function getDeviceGroups(): array
    {
        return $this->deviceGroups;
    }

    /**
     * @param $twofaToken
     * @return User
     */
    public function setTwofaToken($twofaToken): User
    {
        $this->twofa_token = $twofaToken;
        
        return $this;
    }

    public function setIsTwofa(bool $isTwofa): User
    {
        $this->is_twofa = $isTwofa;

        return $this;
    }

    public function getIsTwofa()
    {
        return $this->is_twofa;
    }

    public function getTwofaToken()
    {
        return $this->twofa_token;
    }

    /**
     * @param DeviceGroup[] $deviceGroups
     * @return User
     */
    public function setDeviceGroups(array $deviceGroups = []): User
    {
        if(array_filter($deviceGroups, function ($e) {
            return !($e instanceof DeviceGroup); }
            )) {
            throw new \InvalidArgumentException("Device groups must be as instance of DeviceGroup");
        }
        $this->deviceGroups = $deviceGroups;
        return $this;
    }

    function getAsArray($object = null, $displayAllProps = false)
    {
        $resp = parent::getAsArray($object, $displayAllProps); // TODO: Change the autogenerated stub
        $resp['device_groups'] = [];
        foreach ($this->getDeviceGroups() as $group) {
            $resp['device_groups'][] = $group->getAsArray();
        }
        $resp['settings'] = $this->getSettings();
        return $resp;
    }

    function getAsArrayLite($object = null, $displayAllProps = false)
    {
        $array = $this->getAsArray($object, $displayAllProps);
        return [
            'id' => $array['id'],
            'name' => $array['name'],
            'login' => $array['login'],
            'role' => $array['role'],
            'last_activity' => $array['last_activity'],
        ];
    }

    function isPermitByIp($ipAddress)
    {
        if(isset($this->getSettings()['strict_access_by_ip']) && $this->getSettings()['strict_access_by_ip']['enabled']) {
            foreach (array_filter($this->getSettings()['strict_access_by_ip']['networks'], function ($e) {
                return strpos($e, "/");
            }) as $network) {
                list($neth, $cidr) = explode("/", $network);
                if ((new SubnetCalculator($neth, $cidr))->isIPAddressInSubnet($ipAddress)) {
                    return true;
                }
            }
            return  false;
        }
        return true;
    }
}
