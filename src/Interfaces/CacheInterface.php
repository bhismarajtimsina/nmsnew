<?php


namespace WCAA\Interfaces;


interface CacheInterface
{
    function add(string $key, $value, $timeout = 30);
    function get(string $key);
    function set(string $key, $value, $timeout = 30);
    function flush($delay = 0);
    function deleteByRegex($mask);
    function fetchAll();
    function getAllKeys();
    function isExist($key);
    function delete(string $key);
}
