<?php

namespace WCAA\Infrastructure;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use WCAA\Models\Devices\Device;

class PrometheusMetrics
{
    /**
     * @Inject
     * @var CollectorRegistry
     */
    protected $collector;

    /**
     * @Inject
     * @var \Redis
     */
    protected $redis;

    function setGauge($name, $value,  $labels = [], $help = '', $timeoutSec = -1) {
            $this->collector
                ->getOrRegisterGauge('',
                    $name,
                    $help,
                    array_map(function ($e) {
                        return (string)$e;
                    }, array_keys($labels))
                )->set($value, $labels, $timeoutSec);
        return $this;
    }
    function incCounter($name, $labels = [], $help = '', $incBy = 1) {
        $this->collector
            ->getOrRegisterCounter('',
                $name,
                $help,
                array_map(function ($e) {return (string) $e; }, array_keys($labels))
            )->incBy($incBy, $labels);
        return $this;
    }

    function render($prefix = '') {
        $renderer = new RenderTextFormat();
        return $renderer->render($this->collector->getMetricFamilySamples($prefix));
    }
    function getLastValues($name, $labels = []) {
        $values = [];
        foreach ($this->redis->keys("*:" . $name) as $key) {
            foreach ($this->redis->hGetAll($key) as $labelJson => $value) {
                if(strpos($value, "{") !== false) {
                    $vl = json_decode($value, true);
                    if(!isset($vl['value'])) {
                        continue;
                    }
                    $value = $vl['value'];
                    if(time() > $vl['expired_at']) {
                        continue;
                    }
                }
                $match = false;
                $existed = json_decode($labelJson, true);
                if(json_last_error() === JSON_ERROR_NONE) {
                    $match = true;
                    foreach ($labels as $name => $labelValue) {
                        if(isset($existed[$name]) && $labelValue != $existed[$name]) {
                            $match = false;
                        }
                    }
                }
                if($match) {
                    $values[] = [
                        'key' => $key,
                        'labels' => $existed,
                        'value' => $value,
                    ];
                }
            }
        }
        return $values;
    }
    function remove($name, $labels = []) {
        $deleted = 0;
        foreach ($this->redis->keys("*:" . $name) as $key) {
           foreach ($this->redis->hGetAll($key) as $labelJson => $value) {
               $mustBeDeleted = false;
               $existed = json_decode($labelJson, true);
                if(json_last_error() === JSON_ERROR_NONE) {
                    $mustBeDeleted = true;
                    foreach ($labels as $name => $labelValue) {
                        if(isset($existed[$name]) && $labelValue !== $existed[$name]) {
                            $mustBeDeleted = false;
                        }
                    }
                }
                if($mustBeDeleted) {
                    $deleted++;
                    $this->redis->hDel($key, $labelJson);
                }
           }
        }
        return $deleted;
    }
    function removeByLabel($labels = []) {
        $deleted = 0;
        foreach ($this->redis->keys("*:*") as $key) {
           $dataArr = $this->redis->hGetAll($key);
           if(!is_array($dataArr)) continue;
           foreach ($this->redis->hGetAll($key) as $labelJson => $value) {
               $mustBeDeleted = false;
               $existed = json_decode($labelJson, true);
                if(json_last_error() === JSON_ERROR_NONE) {
                    $mustBeDeleted = true;
                    foreach ($labels as $name => $labelValue) {
                        if(isset($existed[$name]) && $labelValue !== $existed[$name]) {
                            $mustBeDeleted = false;
                        }
                    }
                }
                if($mustBeDeleted) {
                    $deleted++;
                    $this->redis->hDel($key, $labelJson);
                }
           }
        }
        return $deleted;
    }
    function clearByDevice(Device $device) {
        $this->removeByLabel([
            'dev_id' => $device->getId(),
        ]);
        $this->removeByLabel([
            'ip' => $device->getIp(),
        ]);
    }
}