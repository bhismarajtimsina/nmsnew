<?php

namespace WCAA\Infrastructure\Dashboard;

use DI\Annotation\Inject;
use WCAA\Models\User\User;
use WCAA\Storage\UserStorage;

class DashboardTemplate
{
    /**
     * @Inject
     * @var UserStorage
     */
    protected $userStorage;
    function getDefaultDashboard() {
        $dashboardJson = file_get_contents(ROOT . '/config/default_dashboard.json');
        if($dashboard = json_decode($dashboardJson)) {
            return $dashboard;
        }
        throw new \Exception(json_last_error_msg());
    }
    function getCustomDashboard() {
        if(!file_exists(ROOT . '/var/configuration/dashboard.json')) {
            throw new \Exception("Custom dashboard not exist");
        }
        $dashboardJson = file_get_contents(ROOT . '/var/configuration/dashboard.json');
        if($dashboard = json_decode($dashboardJson)) {
            return $dashboard;
        }
        throw new \Exception(json_last_error_msg());
    }
    function resetAllUserDashboards() {
        foreach ($this->userStorage->fetchAll() as $user) {
            $this->updateUserDashboard($user, null);
        }
    }
    /**
     * The layout a role opens on.
     *
     * Sits between the user's own layout and the global one, so a new account
     * lands on a screen built for the job it does instead of on whatever the
     * shipped default happens to be. An ISP account opens on what is down; a
     * reseller opens on their subscribers.
     *
     * Stored in the role's own params, which is a JSON column that was already
     * there and empty, so this needs no schema of its own.
     */
    function getRoleDashboard(User $user) {
        $role = $user->getRole();
        if (!$role) {
            throw new \Exception("User has no role");
        }
        $params = $role->getParams();
        if (is_string($params)) {
            $params = json_decode($params, true);
        }
        if (isset($params['dashboard_template']) && $params['dashboard_template']) {
            return $params['dashboard_template'];
        }
        throw new \Exception("Role dashboard not found");
    }

    function getUserDashboard(User $user) {
        $settings = $user->getSettings();
        if(isset($settings['dashboard_template']) && $settings['dashboard_template']) {
            return $settings['dashboard_template'];
        } else {
            throw new \Exception("User dashboard not found");
        }
    }
    function updateCustomDashboard($template) {
        if(!$template) {
            unlink(ROOT . '/var/configuration/dashboard.json');
            return $template;
        }
        file_put_contents(ROOT . '/var/configuration/dashboard.json', json_encode($template, JSON_PRETTY_PRINT));
        return $template;
    }

    function updateUserDashboard(User $user, $template) {
        $settings = $user->getSettings();
        $settings['dashboard_template'] = $template;
        $user->setSettings($settings);
        $this->userStorage->update($user);
        return $settings;
    }
}