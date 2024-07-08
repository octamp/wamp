<?php

namespace Octamp\Wamp\EventDispatcher;

interface EventDispatcherInterface extends \Symfony\Component\EventDispatcher\EventDispatcherInterface
{
    public function addListenerOnce(string $eventName, callable $listener, int $priority = 0): void;
    public function removeListenersForEvent(string $eventName): void;
}