<?php

namespace WCC\Macros\Models;

use WCAA\Models\AbstractModel;
use WCAA\Models\Devices\DeviceModel;
use WCAA\Models\User\UserRole;

class Macros extends AbstractModel
{
    const DISPLAY_FOR_DEVICE = 'DEVICE';
    const DISPLAY_FOR_PORT = 'PORT';
    const DISPLAY_FOR_PON_PORT = 'PON';
    const DISPLAY_FOR_ONU = 'ONU';

    const DISPLAY_OUTPUT_NO = 'no';
    const DISPLAY_OUTPUT_ALL = 'all';
    const DISPLAY_OUTPUT_LAST = 'last';
    /**
     * Список разрешенных моделей, где отображать
     *
     * @var DeviceModel[]
     */
    protected $models = [];
    /**
     * Список пользовательских ролей, которым разрещено
     * @var UserRole[]
     */
    protected $allowed_roles = [];
    /**
     * Оторбражать шаблон для: DEVICE|PORT|PON|ONU
     *
     * @var array
     */
    protected $display_for = [];
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
    protected $display_output = 'no';

    /**
     * @var string
     */
    protected $name = '';

    /**
     * @var string
     */
    protected $description = '';

    public function getModels(): array
    {
        return $this->models;
    }

    public function setModels(array $models): Macros
    {
        $this->models = $models;
        return $this;
    }

    public function getAllowedRoles(): array
    {
        return $this->allowed_roles;
    }

    public function setAllowedRoles(array $allowed_roles): Macros
    {
        $this->allowed_roles = $allowed_roles;
        return $this;
    }

    public function getDisplayFor(): array
    {
        return $this->display_for;
    }

    public function setDisplayFor(array $display_for): Macros
    {
        $this->display_for = $display_for;
        return $this;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function setParameters(array $parameters): Macros
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

    public function setTemplate(string $template): Macros
    {
        $this->template = $template;
        return $this;
    }


    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): Macros
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): Macros
    {
        $this->description = $description;
        return $this;
    }

    public function getDisplayOutput(): string
    {
        return $this->display_output;
    }

    public function setDisplayOutput(string $display_output): Macros
    {
        $this->display_output = $display_output;
        return $this;
    }




}