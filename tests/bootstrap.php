<?php
/**
 * Shared bootstrap for the test runner.
 *
 * Deliberately dependency-free: PHPUnit is not installed, and pulling dev
 * dependencies into a production install (composer.json uses minimum-stability:
 * dev with several dev-master constraints) is not worth the risk for these.
 * See tests/README.md for moving to PHPUnit later.
 */

define('TEST_ROOT', __DIR__);
define('PROJECT_ROOT', dirname(__DIR__));

require PROJECT_ROOT . '/vendor/autoload.php';

//_env() is used by the classes under test; the app normally defines it here.
if (file_exists(PROJECT_ROOT . '/src/helpers/global.funcs.php')) {
    require_once PROJECT_ROOT . '/src/helpers/global.funcs.php';
}

class Assert
{
    public static $passed = 0;
    public static $failures = [];
    private static $context = '';

    public static function context($name)
    {
        self::$context = $name;
    }

    public static function true($condition, $message)
    {
        if ($condition) {
            self::$passed++;
            return;
        }
        self::$failures[] = self::$context . ' :: ' . $message;
    }

    public static function false($condition, $message)
    {
        self::true(!$condition, $message);
    }

    public static function same($expected, $actual, $message)
    {
        self::true(
            $expected === $actual,
            $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
        );
    }

    public static function notSame($unexpected, $actual, $message)
    {
        self::true($unexpected !== $actual, $message);
    }
}
