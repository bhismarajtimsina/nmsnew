<?php

namespace WCC\OntsRegistration\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\DeviceModel;
use WCAA\Models\User\UserRole;

class UnregisteredOntMacro extends AbstractModel
{

    /**
     * Список разрешенных моделей, где отображать
     *
     * @var DeviceModel[]
     */
    protected $models = [];

    /**
     * @var string
     */
    protected $created_at;

    /**
     * @var string
     */
    protected $updated_at;
    
    
    /**
     * Список переменных
     *
     * @var array
     */
    protected $parameters = [];

    /**
     * Список команд, шаблон
     *
     * @var string
     * @morm
     */
    protected $template = '';

    /**
     * @var string
     */
    protected $name = '';

    /**
     * @var bool
     * @morm
     */
    protected $enabled = true;


    public function getModels(): array
    {
        return $this->models;
    }

    public function setModels(array $models): UnregisteredOntMacro
    {
        $this->models = $models;
        return $this;
    }


    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function setParameters(array $parameters): UnregisteredOntMacro
    {
        foreach ($parameters as $key=>$parameter) {
            $parameters[$key]['variants'] = null;
        }
        $this->parameters = $parameters;
        return $this;
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function setTemplate(string $template): UnregisteredOntMacro
    {
        $this->template = $template;
        return $this;
    }


    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): UnregisteredOntMacro
    {
        $this->name = $name;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): UnregisteredOntMacro
    {
        $this->enabled = $enabled;
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
     * @return UnregisteredOntMacro
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
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
     * @return UnregisteredOntMacro
     */
    public function setUpdatedAt($updated_at)
    {
        $this->updated_at = $updated_at;
        return $this;
    }


    public function __construct($id = null)
    {
        $this->created_at = date('Y-m-d H:i:s');
        $this->updated_at = date('Y-m-d H:i:s');
        parent::__construct($id);
    }


}