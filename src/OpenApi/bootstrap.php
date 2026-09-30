<?php

if (!defined('WCAA_OPENAPI_VERSION')) {
    if (!function_exists('_env')) {
        require_once dirname(__DIR__) . '/helpers/global.funcs.php';
    }
    define('WCAA_OPENAPI_VERSION', trim((string)_env('VERSION', '0.0.0')));
}
