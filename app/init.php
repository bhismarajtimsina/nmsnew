<?php
declare(strict_types=1);

use WCAA\App;

$_ENV['ROOT_DIR'] = realpath(__DIR__ . '/../');
define('ROOT', $_ENV['ROOT_DIR']);

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message . " on file $file, line: $line", 0, $severity, $file, $line);
    }
});

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/helpers/global.funcs.php';

//Load envs
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

\WCAA\Infrastructure\Compiller::init(__DIR__ . '/../var/cache/');

//Cleanup stale php upload tmp files in this container (worker restarts periodically per .rr.yaml max_jobs/idle_ttl)
@shell_exec('find /tmp -maxdepth 1 -type f -name "php*" -mmin +180 -delete > /dev/null 2>&1 &');

//Set default timezone from system
$timezone = trim(file_get_contents('/etc/timezone'));
if($timezone == 'Europe/Kyiv') {
    $timezone = 'Europe/Kiev';
}
date_default_timezone_set($timezone);

$app = App::init();

