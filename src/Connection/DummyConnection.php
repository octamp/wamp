<?php

declare(strict_types=1);

namespace Octamp\Wamp\Connection;

use JetBrains\PhpStorm\ArrayShape;
use Octamp\Server\Connection\Connection;
use Octamp\Server\ServerInterface;
use Octamp\Wamp\Connection\Event\SendMessageEvent;
use Octamp\Wamp\EventDispatcher\EventDispatcher;
use Octamp\Wamp\EventDispatcher\EventDispatcherInterface;
use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\Event;

class DummyConnection extends Connection implements WithEventDispatcherInterface
{
    protected EventDispatcherInterface $eventDispatcher;

    protected array $callable = [];

    public function __construct(Request $request, ServerInterface $server, ?string $id = null)
    {
        parent::__construct($request, $server, $id);

        $this->eventDispatcher = new EventDispatcher();
    }

    public function send(array|string|null $data, int $opcode = Server::WEBSOCKET_OPCODE_TEXT): void
    {
        $this->dispatch('SendMessage', new SendMessageEvent($data, $opcode));
    }

    public function on(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->eventDispatcher->addListener($eventName, $listener, $priority);
    }

    public function once(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->eventDispatcher->addListenerOnce($eventName, $listener, $priority);
    }

    public function dispatch(string $eventName, ?Event $event): Event
    {
        return $this->eventDispatcher->dispatch($eventName, $event);
    }

    public function removeListener(string $eventName, callable $listener): void
    {
        $this->eventDispatcher->removeListener($eventName, $listener);
    }

    public function removeListenersForEvent(string $eventName): void
    {
        $this->eventDispatcher->removeListenersForEvent($eventName);
    }
}
