<?php

use Monolog\Logger;

$trustedHostList = function () {
    $ips = explode(",", _env('TRUSTED_HOST_NETWORK_LIST'));
    return array_map(function ($e) {
        return trim($e);
    }, $ips);
};
$disabledDemoForSubnet = function () {
    $ips = explode(",", _env('DEMO_MODE_DISABLE_FOR_SUBNET'));
    return array_map(function ($e) {
        return trim($e);
    }, $ips);
};

$getCoordinates = function () {
    $elems = explode(",", _env('MAP_COORDINATES', ''));
    if (count($elems) < 2) {
        $elems = [
            '27.7007',
            '85.3001',
        ];
    }
    return [
        'lat' => (float)$elems[0],
        'lon' => (float)$elems[1],
    ];
};

$eventListeners = [
    \WCAA\Infrastructure\Events\EventLogToRedisQueue::class,
];

if(_env('LOG_ALL_EVENTS', false)) {
    $eventListeners[] = \WCAA\Infrastructure\Events\EventLogger::class;
}

return [
    'search2' => [
        'enabled' => _env('SEARCH2_ENABLED', false),
        'filter' => _env('SEARCH2_FILTER', 'agreement'),
    ],
    'rate_limiter' => [
        'enabled' => _env('RATE_LIMITER_ENABLED', true),
        'time' => _env('RATE_LIMITER_TIME', 60*60),
        'attempts' => _env('RATE_LIMITER_ATTEMPTS', 3),
    ],
    'demo' => [
        'enabled' => _env('DEMO_MODE', false),
        'disable_for' => $disabledDemoForSubnet(),
    ],
    'system' => [
        'app_name' => _env('APP_NAME', 'My application'),
        'allow_send_stat' => _env('ALLOW_SEND_STAT', true),
        'http_address'  => _env('EXTERNAL_HTTP_ADDRESS', 'http://127.0.0.1:8088'),
    ],
    'supervisor' => [
        //Internal RPC to the schedule-executor container. Defaults match the
        //supervisord config shipped in docker/schedule-executor; override both
        //sides together via env when exposing this beyond the compose network.
        'url' => _env('SUPERVISOR_RPC_URL', 'http://wca-schedule-executor:9001/RPC2'),
        'username' => _env('SUPERVISOR_RPC_USER', 'user'),
        'password' => _env('SUPERVISOR_RPC_PASSWORD', 'user'),
    ],
    'panel' => [
        'map' => [
            'coordinates' => $getCoordinates(),
            'default_tile' => _env('MAP_TILE_LAYER', 'Google (street)'),
        ],
        'logs_return_result_limit' => _env('LOGS_RETURN_RESULTS_LIMIT', 500),
        'default_user_settings' => yaml_parse_file(__DIR__ . '/user-default-parameters.yml'),
    ],
    'poller' => [
        'proc_concurrency' => _env('POLLER_COUNT_PROCS', 10),
        'ignore_down_devices' => _env('POLLER_IGNORE_DOWN', true),
        'do_not_clear_interfaces' => _env('POLLER_DO_NOT_CLEAR_INTERFACES', false),
    ],
    'switcher_core' => [
        'console_connection_type' => _env('SWC_CONSOLE_CONN_TYPE', 'telnet'),
        'console_port' => _env('SWC_CONSOLE_PORT', 23),
        'console_timeout_sec' => _env('SWC_CONSOLE_TIMEOUT_SEC', 60),
        'console_wait_byte_sec' => _env('SWC_CONSOLE_WAIT_BYTE_SEC', 10),
        'snmp_repeats' => _env('SWC_SNMP_REPEATS', 3),
        'snmp_version' => _env('SWC_SNMP_VERSION', '2c'),
        'snmp_timeout_sec' => _env('SWC_SNMP_TIMEOUT_SEC', 3),
        'snmp_port' => _env('SWC_SNMP_PORT', 161),
        'cuncurrency' => _env('SWC_CALL_CONCURRENCY', 3),
        'mikrotik_api_port' => _env('SWC_MIKROTIK_API_PORT', 3),
        'cache_system' => _env('SWC_CACHE_SYSTEM', 'memcache'),
        'cache_actualize_timeout_sec' => _env('SWC_CACHE_ACTUALIZE_TIMEOUT_SEC', 300),
        'cache_timeout_sec' => _env('SWC_CACHE_TIMEOUT_SEC', 36000),
        'swc_url' => _env('SWC_URL_PATH', 'http://wca-nginx/api/switcher-core'),
        'check_icmp_ping' => _env('SWC_CHECK_ICMP_PING', true),
        'enable_splitting' => _env('SWC_ENABLE_SPLITTING', true),
        'split_by_interface_methods' => [
          'interface_counters',
          'pon_onts_serial',
          'pon_onts_mac_addr',
          'pon_onts_optical',
          'pon_onts_status',
          'pon_onts_vendor',
          'interface_descriptions',
          'interface_counters',
          'fdb',
          'pon_onts_reasons',
          'link_info',
          'vlans_by_port',
          'errors',
          'interfaces_list',
          'rmon',
          'sfp_optical',
          'sfp_media',
        ],
    ],
    'api' => [
        'proxy' => [
            'enabled' => _env('PROXY_ENABLED', false),
            'real_ip_header' => _env('PROXY_REAL_IP_HEADER', 'X-Forwarded-For')
        ],
        'auth' => [
            'trusted_networks' => $trustedHostList(),
            'key_expired_sec' => _env('API_KEY_EXPIRATION'),
            'rules' => yaml_parse_file(__DIR__ . '/rules.yml'),
            // Per-channel WebSocket subscribe permissions — enforced in
            // src/Console/WebSocketServerCommand.php, separate from the
            // REST 'rules' above since WS channels aren't HTTP routes.
            'ws_permissions' => yaml_parse_file(__DIR__ . '/ws-permissions.yml'),
            'strict_rules' => _env('API_STRICT_RULES', true),
            'check_pair_method' => _env('AUTH_CHECK_PAIR_METHOD', WCAA\Api\Actions\Auth\UserAuthAction::class),
            'check_auth_middleware' => WCAA\Api\Middleware\AuthCheckMiddleware::class,
            'default_role_permissions' => [
                'system_info',
                'dashboard_edit',
                'global_search',
                'user_self',
                'device_show',
                'user_self_control',
                'analytics',
                'fdb_history_by_interface',
                'events_show',
                'live_traffic_info',
                'prom_chart_info',
                'notifications_configure_contacts',
                'switches_info',
                'olts_info',
                'sensor_devices_view',
                'poller_info_by_device',
            ],
        ],
        'base_path' => _env('API_BASE_PATH', '/api/v1'),
    ],
    'language' => [
        'default' => _env('DEFAULT_LANGUAGE', 'en'),
        'locales' => [
            'en' => 'English',
            'ru' => 'Русский',
            'ua' => 'Українська'
        ]
    ],
    'icons' => [
        'upload_dir' => __DIR__ . '/../public/upload/icons',
        'url_prefix' => _env('IMAGE_BASE_URL'),
    ],
    'console' => yaml_parse_file(__DIR__ . '/console.yml'),
    'production' => strtolower(_env('ENVIRONMENT')) === 'production',
    'logger' => [
        'name' => 'system',
        'path' => _env('LOG_FILES_PATH', '/log'),
        'level' => Logger::toMonologLevel(_env('LOG_LEVEL')),
    ],
    'memcache' => [
        'enabled' => _env('MEMCACHE_ENABLED', true),
        'server' => _env('MEMCACHE_SERVER'),
        'port' => _env('MEMCACHE_PORT'),
        'storage_timeout' => _env('MEMCACHE_CACHING_QUERY_TIMEOUT_SEC', 360),
    ],
    'migrations' => [
        'system_path' => realpath(__DIR__ . '/../migrations'),
        'scan_modules' => true,
    ],
    //prometheus.exporter.storage
    'prometheus' => [
        'exporter' => [
            'storage' => _env('REDIS_ENABLED', true) ? 'redis' : 'apc',
            'options' => [
                'host' => _env('REDIS_HOST', 'wca-redis'),
                'port' => _env('REDIS_PORT', '6379'),
                'password' => _env('REDIS_PASSWORD', '') === '' ? null : _env('REDIS_PASSWORD'),
                'timeout' => 5.0, // in seconds
                'read_timeout' => '15', // in seconds
                'persistent_connections' => false
            ]
        ],

        'url' => _env('PROMETHEUS_URL', 'http://wca-prometheus:9090/prometheus'),
        'alertmanager_url' => _env('ALERTMANAGER_URL', 'http://wca-alertmanager:9093/alertmanager')
    ],
    'env_params' => yaml_parse_file(__DIR__ . '/env-params.yml'),
    'event_listeners' => $eventListeners,
    'roadrunner' => [
        'rpc_address' => _env('ROADRUNNER_RPC_ADDRESS', 'tcp://wca:6001'),
    ],
    'types' => [
        'interfaces' => [
            'FE',
            'GE',
            'TGE',
            'ETH',
            'PON',
            'ONU',
            '1G-SFP',
            '10G-SFP',
            'UNKNOWN',
        ],
    ],
    'universal_api' => [
        'types' => [
            "switch" => [
                "id" => "switch",
                "name" => "Switch"
            ],
            "radio" => [
                "id" => "radio",
                "name" => "Radio"
            ],
            "olt" => [
                "id" => "olt",
                "name" => "OLT"
            ],
            "onu" => [
                "id" => "onu",
                "name" => "ONU/ONT"
            ],
            "other" => [
                "id" => "other",
                "name" => "Other"
            ]
        ],
        'type_mapper' => [
            'OLT' => 'olt',
            'SWITCH' => 'switch',
            'ROUTER' => 'other',
        ]
    ]
];
