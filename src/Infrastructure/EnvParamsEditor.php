<?php

namespace WCAA\Infrastructure;

use WCAA\App;
use WCAA\Infrastructure\Events\EventObserverStorage;

class EnvParamsEditor
{
    /**
     * @var App
     */
    protected $app;

    function __construct(App $app) {
        $this->app = $app;
    }

    function getParams() {
        $paramGroups = $this->app->conf('env_params');

        foreach ($paramGroups as $groupName=>$params) {
            foreach ($params as $id=>$param) {
                $setted = _env($param['param_name'], 'NO_EXIST');
                if(!isset($param['default'])) {
                    $paramGroups[$groupName][$id]['default']  = null;
                }
                if($setted == 'NO_EXIST' && isset($paramGroups[$groupName][$id]['default'])) {
                    $setted = $paramGroups[$groupName][$id]['default'];
                }
                $paramGroups[$groupName][$id]['value'] = $setted;
            }
        }
        return  $paramGroups;
    }
    function writeParams($params) {
        $variables = explode("\n", file_get_contents(__DIR__ . '/../../.env'));
        foreach ($params as $key=>$val) {
            $found = false;
            foreach ($variables as $lineNum => $line) {
                if (preg_match("/^{$key}\s?=.*?/", $line)) {
                    $found = true;
                    if($val === null) {
                        unset($variables[$lineNum]);
                        continue;
                    }
                    if(is_array($val)) {
                        $val = json_encode($val, JSON_UNESCAPED_SLASHES);
                    }
                    $variables[$lineNum] = "{$key}='{$val}'";
                }
            }
            if(!$found && $val !== null) {
                if(is_array($val)) {
                    $val = json_encode($val, JSON_UNESCAPED_SLASHES);
                }
                $variables[] = "{$key}='{$val}'";
            }
        }
        $newEnv = join("\n", $variables);
        file_put_contents(__DIR__ . '/../../.env', trim($newEnv) . "\n");
        App::getInstance()->getContainer()->get(EventObserverStorage::class)->notify("system:env-parameters-updated", $params);
        App::getInstance()->resetRRWorkers();
        return $this;
    }
}
