<?php


namespace WCAA\Infrastructure;


use WCAA\App;
use WCAA\Models\Permission;

class Permissions
{
    protected $rules;
    function __construct(App $app) {
        $this->rules = $app->conf('api.auth.rules');
    }
    function getPermissions() {
        $perms = [];
        foreach ($this->rules as $perm) {
            $perms[] = new Permission($perm['key'], $perm['name'], $perm['routes']);
        }
        return $perms;
    }

}