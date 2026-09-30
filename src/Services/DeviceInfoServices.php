<?php

namespace WCAA\Services;

use SwitcherCore\Exceptions\ModuleErrorLoadException;
use SwitcherCore\Exceptions\ModuleNotFoundException;
use SwitcherCore\Switcher\Core;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\SwitcherCore\SwitcherCore;
class DeviceInfoServices
{
    protected DeviceStorage $deviceStorage;
    protected SwitcherCore $core;

    public function __construct(SwitcherCore $core)
    {
        $this->core = $core;
    }

    /**
     * Создаёт подключение к устройству без указания модели.
     * Метод инициализирует объект SwitcherCore\Switcher\Device только на основе
     * параметров доступа (SNMP, логин, пароль, порты), но не назначает modelKey.
     * Вызов `action('system')` выполнит SNMP-запросы и SwitcherCore попытается определить
     * модель устройства автоматически на основе sysObjectID и других параметров.
     * @param $dev
     * @return Core
     * @throws \ErrorException
     * @throws ModuleErrorLoadException
     * @throws ModuleNotFoundException
     */
    public function buildCoreWithoutModel($dev): \SwitcherCore\Switcher\Core
    {
        // устанавливаем параметры которые берём с конфига
        $params = \WCAA\App::getInstance()->getConfig()['switcher_core'];
        $access = $dev->getAccess();
        $device = (new \SwitcherCore\Switcher\Device())
            ->setIp($dev->getIp())
            ->setPublicCommunity($access->getPublicCommunity())
            ->setPrivateCommunity($access->getPrivateCommunity())
            ->setLogin($access->getLogin())
            ->setPassword($access->getPassword());

        // Параметры SW Core из Access
        $acParam = $access->getParams();
        if (isset($acParam['sw_core_connection'])) {
            foreach ($acParam['sw_core_connection'] as $key => $value) {
                $params[$key] = $value;
            }
        }
        // Назначаем параметры
        $device->consolePort = $params['console_port'] ?? null;
        $device->consoleTimeout = $params['console_timeout_sec'] ?? null;
        $device->consoleWaitByteSec = $params['console_wait_byte_sec'] ?? null;
        $device->consoleConnectionType = $params['console_connection_type'] ?? null;
        $device->mikrotikApiPort = $params['mikrotik_api_port'] ?? null;
        $device->snmpTimeoutSec = $params['snmp_timeout_sec'] ?? null;
        $device->snmpRepeats = $params['snmp_repeats'] ?? null;
        $device->snmpPort = $params['snmp_port'] ?? null;
        $device->snmpVersion = $params['snmp_version'] ?? null;

        return $this->core->getClient()->getOrInit($device);
    }



}
