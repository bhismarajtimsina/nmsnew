<?php

function _env($key, $default_value = null)
{
    if (isset($_ENV[$key])) {
        $value = $_ENV[$key];
    } else {
        $value = $default_value;
    }
    if(is_array($value)) {
        return $value;
    }
    if (preg_match('/^\s*\[.*\]\s*$/', $value)) {
        $decoded =  json_decode($value, true);
        if(json_last_error() == JSON_ERROR_NONE) {
            return $decoded;
        }
    }
    if (strpos($value, "\${") !== false) {
        foreach ($_ENV as $key => $val) {
            $value = str_replace("\$\{$key\}", $val, $value);
        }
    }
    if (strtolower($value) === 'true') return true;
    if (strtolower($value) === 'yes') return true;
    if (strtolower($value) === 'false') return false;
    if (strtolower($value) === 'no') return false;
    return $value;
}

function _envs()
{
    return $_ENV;
}

/**
 * Open the application database connection, retrying briefly on connect failure.
 *
 * RoadRunner allocates its whole worker pool eagerly at boot, and every worker
 * opens a connection here. If the database is not answering yet - the usual case
 * being the app container starting alongside MySQL, which needs ~30s to finish
 * InnoDB init - a single failure aborts pool allocation, RoadRunner has no
 * workers, and nginx serves 502 for the whole stack.
 *
 * Retrying turns that fatal race into a short delay, and also lets an already
 * running worker survive a brief database blip instead of taking the pool down.
 * Only connection-level failures are retried; bad credentials or a missing
 * schema fail immediately, since waiting cannot fix them.
 */
function NewPdoConnection()
{
    $attempts = (int)_env('DATABASE_CONNECT_RETRIES', 10);
    $delayMs = (int)_env('DATABASE_CONNECT_RETRY_DELAY_MS', 1000);
    if ($attempts < 1) {
        $attempts = 1;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, //turn on errors in the form of exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, //make the default fetch be an associative array
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => (int)_env('DATABASE_CONNECT_TIMEOUT_SEC', 5),
    ];

    //Driver errors that mean "cannot reach the server", as opposed to "the
    //server rejected this": 2002 connect failure, 2003 can't connect,
    //2006 server gone away, 2013 lost connection during handshake.
    $retryableCodes = [2002, 2003, 2006, 2013];

    $lastError = null;
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            $sql = new PDO(_env('DATABASE_URL'), _env('DATABASE_USER'), _env('DATABASE_PASSWD'), $options);
            $sql->exec("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
            return $sql;
        } catch (PDOException $e) {
            $lastError = $e;
            $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
            //PDO reports connect failures with errorInfo unset on some builds,
            //so fall back to matching the SQLSTATE used for connection errors.
            $isRetryable = in_array($driverCode, $retryableCodes, true)
                || strpos((string)$e->getCode(), 'HY000') === 0;

            if (!$isRetryable || $attempt === $attempts) {
                break;
            }
            @error_log(sprintf(
                'NewPdoConnection: attempt %d/%d failed (%s), retrying in %dms',
                $attempt, $attempts, $e->getMessage(), $delayMs
            ));
            usleep($delayMs * 1000);
        }
    }
    throw $lastError;
}

function fillArrayFromSkeleton(array $skeletonArray, array $fillingArray) {
    foreach ($skeletonArray as $key=>$skeletonData) {
        if(isset($fillingArray[$key]) && is_array($skeletonData)) {
            $skeletonArray[$key] = fillArrayFromSkeleton($skeletonData, $fillingArray[$key]);
        } elseif (isset($fillingArray[$key])) {
            $skeletonArray[$key] = $fillingArray[$key];
        }
    }
    foreach ($fillingArray as $key=>$fillingData) {
        if(!isset($skeletonArray[$key])) {
            $skeletonArray[$key] = $fillingData;
        }
    }
    return $skeletonArray;
}

function getArrayElementByKey($array, $key)
{
    $key = str_replace(["\$", ";", "'", '"', "(", ")"], "", $key);
    if($key == '.') {
        return $array;
    }
    $elements = explode(".", $key);
    $arrayKey = join('', array_map(function ($e) {
        if(preg_match('/\[[0-9]{1,6}\]/', $e, $m)) {
            return $e;
        }
        return "['{$e}']";
    }, $elements));
    $return = null;
    $evalArrayBlock = "if(isset(\$array{$arrayKey})) {\$return = \$array{$arrayKey}; }";
    eval($evalArrayBlock);
    return $return;
}

function cutStr($str, $left, $right) {
    $str = mb_substr(@mb_stristr($str, $left), mb_strlen($left));
    $leftLen = mb_strlen(@mb_stristr($str, $right));
    $leftLen = $leftLen ? -($leftLen) : mb_strlen($str);
    $str = mb_substr($str, 0, $leftLen);
    return $str;
}

function isPositiveParameter($answer)
{
    if($answer === 'yes') return true;
    if($answer === '1') return true;
    if($answer === 1) return true;
    if($answer === 'true') return true;
    return false ;
}