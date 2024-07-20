<?php

declare(strict_types=1);

namespace Octamp\Wamp\Connection;

use Symfony\Component\EventDispatcher\Event;

interface WithEventDispatcherInterface
{
    public function on(string $eventName, callable $listener, int $priority = 0): void;

    public function once(string $eventName, callable $listener, int $priority = 0): void;

    public function dispatch(string $eventName, ?Event $event): Event;

    public function removeListener(string $eventName, callable $listener): void;

    public function removeListenersForEvent(string $eventName): void;
}
