<?php

namespace WCC\Notifications\Console;


use DI\Container;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\RegionCode;
use Longman\TelegramBot\Entities\Contact;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentCommand;
use WCC\Notifications\Controllers\Channels\Telegram;
use WCC\Notifications\Models\NotificationContact;
use WCC\Notifications\Storage\NotificationsContactsStorage;

class TelegramBotListener extends AbstractComponentCommand
{

    /**
     * @Inject
     * @var Container
     */
    protected $container;

    /**
     * @Inject
     * @var NotificationsContactsStorage
     */
    protected $alertContacts;

    /**
     * @Inject
     * @var Telegram
     */
    protected $telegram;

    function config()
    {
        $this->setName('telegram-bot')
            ->setDescription("Bot incoming messages listener");
    }

    function exec(InputInterface $input, OutputInterface $output)
    {

        $this->botListener($output);
        return self::FAILURE;
    }

    function botListener(OutputInterface $output)
    {
        while (true) {
            try {
                $this->longPollingDbReconector();
                if (!$this->telegram->isConfigured()) {
                    $output->writeln("<error>
    Telegram bot not configured! 
    Please, go to web panel and configure bot 
</error>");
                    break;
                }
                $config = $this->telegram->getConfiguration();
                // Create Telegram API object
                $telegram = new \Longman\TelegramBot\Telegram($config['bot_api_key'], $config['bot_username']);
                $result = $telegram->deleteWebhook();
                $telegram->useGetUpdatesWithoutDatabase();

                // Handle telegram getUpdates request
                while (true) {
                    $this->longPollingDbReconector();
                    $update = $telegram->handleGetUpdates();
                    if ($update->getOk() && $update->getResult()) {
                        foreach ($update->getResult() as $update) {
                            $this->update($update);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage());
                echo $e->getMessage();
                sleep(3);
            }
        }
    }

    function update(Update $update)
    {
        if ($update->getMessage()->getType() == 'contact') {
            $this->contactRegistration($update);
        } elseif (!$this->isUserRegistered($update->getMessage()->getFrom()->getId())) {
            $this->userNotRegisteredMessage($update);
        } else {
            Request::sendMessage([
                'chat_id' => $update->getMessage()->getFrom()->getId(),
                'text' => 'Current bot used only for send messages.',
                'parse_mode' => 'HTML',
            ]);
        }
    }

    function tryRegister(Contact $contact, $chatId)
    {
        $number = $contact->getPhoneNumber();
        if (strpos($number, "+") === false) {
            $number = "+" . $number;
        }
        $phone = PhoneNumberUtil::getInstance()
            ->format(
                \libphonenumber\PhoneNumberUtil::getInstance()
                    ->parse($number, RegionCode::US),
                \libphonenumber\PhoneNumberFormat::E164
            );
        $alertContact = $this->alertContacts->getByValue($phone, NotificationContact::TYPE_PHONE_FOR_TELEGRAM);
        if (!$alertContact) {
            throw new \Exception("Your phone number ($phone) not found. Please, go to web panel and add own phone number to contacts first");
        }
        $this->alertContacts->add((new NotificationContact())
            ->setParams([
                "severities"=> ["NOTIFICATION", "INFO", "WARNING", "CRITICAL"],
                "ignore_events"=> [],
                "ignore_actions"=> [],
                "send_only_by_devices"=> false,
                'extra' => [
                    'phone' => $number,
                ]
            ])
            ->setUser($alertContact[0]->getUser())
            ->setDescription("Registered over bot")
            ->setEnabled(true)
            ->setType(NotificationContact::TYPE_TELEGRAM_ID)
            ->setValue($chatId)
        );
        return null;
    }

    protected function isUserRegistered($chatId)
    {
        $contacts = $this->alertContacts->getByValue($chatId);
        return count($contacts) > 0;
    }

    protected function userNotRegisteredMessage(Update $update)
    {
        $keyboard = (new Keyboard([
            ['text' => 'Send my contact', 'request_contact' => true],
        ]))->setOneTimeKeyboard(true)
            ->setSelective(false)
            ->setResizeKeyboard(true);
        $this->sendMessage($update, "
You are not registered in system.
Please, send you phone number for try registration
", ['reply_markup' => $keyboard]);
    }

    protected function contactRegistration(Update $update)
    {
        if ($update->getMessage()->getContact()->getVcard()) {
            $this->sendMessage($update, 'Allowed to sharing only own contact');
        } else {
            try {
                $this->tryRegister($update->getMessage()->getContact(), $update->getMessage()->getFrom()->getId());
                $this->sendMessage($update, "<b>Success registered!</b>

Now, you can receive events to current chat. 
Also, you can configure filters for events in web panel Support", ['reply_markup' => Keyboard::remove()]);
            } catch (\Throwable $e) {
                $this->logger->error("error register contact: " . $e->getMessage());
                $this->sendMessage($update, "<b>Error register</b>:\n\n" . $e->getMessage());
            }
        }
    }

    function sendMessage(Update $update, $message, $extras = [])
    {
        $args = [
            'chat_id' => $update->getMessage()->getFrom()->getId(),
            'text' => $message,
            'parse_mode' => 'HTML',
        ];
        $args = array_merge($args, $extras);
        Request::sendMessage($args);
    }


    function longPollingDbReconector()
    {
        $pdo = $this->container->get(\PDO::class);
        try {
            $pdo->query("SELECT 1")->fetchAll();
        } catch (\Throwable $e) {
            sleep(2);
            $this->container->set(\PDO::class, NewPdoConnection());
        }
    }
}
