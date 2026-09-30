<?php

namespace WCAA\Models\User;

use WCAA\App;
use WCAA\Models\AbstractModel;


/**
 * Class UserRole
 * @package WCAA\Models\User
 */
class UserRole extends AbstractModel
{
    /**
     *
     * @morm.name=id
     * @var int
     */
    protected $id;

    /**
     * @morm
     * @var array
     */
    protected $params;

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * @param $params
     * @return UserRole
     */
    public function setParams($params): UserRole
    {
        $this->params = $params;
        return $this;
    }


    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param int $id
     * @return UserRole
     */
    public function setId($id): UserRole
    {
        $this->id = $id;
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
     * @return UserRole
     */
    public function setName(string $name): UserRole
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @return bool
     */
    public function isDisplay(): bool
    {
        return $this->display;
    }

    /**
     * @param bool $display
     * @return UserRole
     */
    public function setDisplay(bool $display): UserRole
    {
        $this->display = $display;
        return $this;
    }

    /**
     * @return array
     */
    public function getPermissions(): array
    {
        if (!$this->permissions) {
            return App::getInstance()->conf('api.auth.default_role_permissions');
        }
        return array_values(array_unique(array_merge($this->permissions, App::getInstance()->conf('api.auth.default_role_permissions', []))));
    }

    /**
     * @param array $permissions
     * @return UserRole
     */
    public function setPermissions(array $permissions): UserRole
    {
        $this->permissions = $permissions;
        return $this;
    }

    /**
     * @morm.name=name
     * @var string;
     */
    protected $name;

    /**
     * @morm
     * @var bool
     */
    protected $display;

    /**
     * @morm
     * @var array
     */
    protected $permissions;


    /**
     * @morm
     * @var string
     */
    protected $description;

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @param string $description
     * @return UserRole
     */
    public function setDescription(string $description): UserRole
    {
        $this->description = $description;
        return $this;
    }


    function __toString()
    {
        return $this->name;
    }

    public function isPermitted($permission)
    {
        return in_array($permission, $this->permissions);
    }

    function getAsArray($object = NULL, $displayAllProps = false)
    {
        $arr = parent::getAsArray($object, $displayAllProps);
        $arr['permissions'] = $this->getPermissions();
        return $arr;
    }

    function getAsArrayLite($object = NULL, $displayAllProps = false)
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
        ];
    }
}