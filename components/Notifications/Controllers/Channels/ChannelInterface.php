<?php

namespace WCC\Notifications\Controllers\Channels;

use WCC\Notifications\Models\NotificationContact;
use WCC\Notifications\Models\Notification;
use WCC\Events\Models\Event;

interface ChannelInterface
{
    function send(Notification $alertEvent);
    function init();
    function isConfigured();
    function getTemplate($name);
    function buildTemplate($name);
    function getConfiguration();
    function getDefaultConfig();
    function updateConfiguration($config);
}