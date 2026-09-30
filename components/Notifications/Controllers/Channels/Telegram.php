<?php

namespace WCC\Notifications\Controllers\Channels;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use WCC\Notifications\Models\NotificationContact;
use WCC\Notifications\Models\Notification;
use WCC\Events\Models\Event;

class Telegram extends AbstractChannel
{



    function send(Notification $alertEvent)
    {
        $message = $this->buildTemplate($alertEvent->getType(), [
            'event' => $alertEvent->getEvent() ? $alertEvent->getEvent()->getAsArray() : null,
            'action' => $alertEvent->getAction() ? $alertEvent->getAction()->getAsArray() : null,
            'recipient' => $alertEvent->getContact()->getAsArray(),
        ]);
        if(!$alertEvent->getPreviousnotification()) {
            $response = Request::sendMessage([
                'chat_id' => $alertEvent->getContact()->getValue(),
                'text' => $message,
                'parse_mode' => 'HTML',
            ]);
        } else {
            //reply_to_message
            $response = Request::sendMessage([
                'chat_id' => $alertEvent->getContact()->getValue(),
                'text' => $message,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => isset($alertEvent->getPreviousnotification()->getMeta()['message_id']) ? $alertEvent->getPreviousnotification()->getMeta()['message_id'] : null,
            ]);
        }
        if(!$response->isOk()) {
            throw new \Exception("{$response->getDescription()} ({$response->getErrorCode()})");
        }
        /**
         * @var Message $message
         */
        $message =  $response->getResult();
        return [
            'message_id' => $message->getMessageId(),
            'chat' => $message->getChat()->getRawData(),
            'from' => $message->getFrom()->getRawData(),
        ];
    }

    function isConfigured()
    {
        return isset($this->config)
            && !empty($this->config['bot_api_key'])
            && !empty($this->config['bot_username']);
    }

    function prepareConfig($config)
    {
        $preparedConf = [
            'templates' => [
                'alert' => file_get_contents(__DIR__ . '/../../files/telegram_alert.html'),
                'resolved' =>  file_get_contents(__DIR__ . '/../../files/telegram_resolved.html'),
                'notification' =>  file_get_contents(__DIR__ . '/../../files/telegram_notification.html'),
            ],
            'bot_api_key' => '',
            'bot_username' => '',
        ];
        if($config == null) {
            return  $preparedConf;
        }
        foreach ($preparedConf as $key=>$value) {
            if(isset($config[$key]) && $config[$key]) {
                $preparedConf[$key] = $config[$key];
            }
        }
        return $preparedConf;
    }

    function init() {
        if(!$this->isConfigured()) {
            throw new \Exception("Configuration telegram not setted");
        }
        new \Longman\TelegramBot\Telegram($this->config['bot_api_key'], $this->config['bot_username']);

        return $this;
    }

    function getSourceName() {
        return 'telegram';
    }
}