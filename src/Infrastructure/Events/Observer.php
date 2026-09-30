<?php


namespace WCAA\Infrastructure\Events;


abstract class Observer implements \SplObserver
{
    abstract function getEventType();
    abstract function notify(\SplSubject $subject, $event, $data = null);
    public function update(\SplSubject $subject)
    {
        return null;
    }
}