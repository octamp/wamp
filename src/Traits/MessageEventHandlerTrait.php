<?php

namespace Octamp\Wamp\Traits;

use Octamp\Wamp\Event\EventInterface;
use Octamp\Wamp\Event\MessageEvent;
use Octamp\Wamp\Session\Session;

trait MessageEventHandlerTrait
{
    public function onMessageEvent(Session $session, MessageEvent $event): void
    {
        $messageClass = (new \ReflectionClass($event->message))->getShortName();
        $handlerName = 'on' . $messageClass;

        if (method_exists($this, $handlerName)) {
            call_user_func([$this, $handlerName], $session, $event->message, $event);
        }
    }

    public function onAfterMessageEvent(Session $session, MessageEvent $event): void
    {
        $messageClass = (new \ReflectionClass($event->message))->getShortName();
        $handlerName = 'onAfter' . $messageClass;

        if (method_exists($this, $handlerName)) {
            call_user_func([$this, $handlerName], $session, $event->message, $event);
        }
    }
}