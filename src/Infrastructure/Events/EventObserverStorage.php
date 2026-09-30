<?php


namespace WCAA\Infrastructure\Events;



use Monolog\Logger;
use WCAA\App;

class EventObserverStorage implements \SplSubject
{
    /**
     * @var array
     */
    private $observers = [];
    protected static $self = null;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var App
     */
    protected $app;

    public static function getSelf()
    {
        if (self::$self == null) {
            throw new \Exception("event repository not initialized");
        }
        return self::$self;
    }

    public function __construct(Logger $logger, App $app)
    {
        // Специальная группа событий для наблюдателей, которые хотят слушать
        // все события.
        $this->app = $app;
        $this->observers["*"] = [];
        $this->logger = $logger;
        self::$self = $this;
    }

    private function initEventGroup(string $event = "*"): void
    {
        if (!isset($this->observers[$event])) {
            $this->observers[$event] = [];
        }
    }

    private function getEventObservers(string $event = "*"): array
    {
        $observers = [];
        foreach ($this->observers as $eventName => $observersByEvent) {
            if($eventName === $event) {
                $observers = array_merge($observers, $observersByEvent);
                continue;
            }
            if($eventName === "*") {
                $observers = array_merge($observers, $observersByEvent);
                continue;
            }
            if(strpos($eventName, "*") !== false) {
                $regex = '/^' . str_replace('*', ".*?", $eventName) . '$/';
                if (preg_match($regex, $event)) {
                    $observers = array_merge($observers, $observersByEvent);
                }
            }
        }
        return $observers;
    }

    public function attach(\SplObserver $observer, string $event = "*"): void
    {
        $this->initEventGroup($event);

        $this->observers[$event][] = $observer;
    }

    public function attachAllDirectoryEvents()
    {
        $data = $this->app->conf('event_listeners');
        foreach ($data as $d) {
            $event = $this->app->getContainer()->get($d);
            $this->attach($event, $event->getEventType());
        }
    }

    public function detach(\SplObserver $observer, string $event = "*"): void
    {
        foreach ($this->getEventObservers($event) as $key => $s) {
            if ($s === $observer) {
                unset($this->observers[$event][$key]);
            }
        }
    }

    public function notify(string $event = "*", $data = null): void
    {
        $observers = $this->getEventObservers($event);
        foreach ($observers as $observer) {
            try {
                $observer->notify($this, $event, $data);
            } catch (\Throwable $e) {
                $this->logger->error("except process with event {$event}: {$e->getMessage()}");
            }
        }
    }
}