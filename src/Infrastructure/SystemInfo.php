<?php

namespace WCAA\Infrastructure;

class SystemInfo
{
    static protected $data = [];

    public static function setSelfHostedData(): void
    {
        self::$data = [
            'systemStatus' => [
                'status' => 'SELF_HOSTED',
                'instance_id' => 'self-hosted',
                'mode' => 'self_hosted',
            ],
            'usageStat' => null,
        ];
    }

    public static function isDataSetted() {
        return isset(self::$data['systemStatus']);
    }

    public static function setData($data): void
    {
        self::setSelfHostedData();
    }

    function getAsArray() {
        self::setSelfHostedData();
        return [
            'status' => self::$data['systemStatus'],
            'statistic' => self::$data['usageStat'] ?? null,
        ];
    }
}
