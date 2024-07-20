<?php

declare(strict_types=1);

namespace Octamp\Wamp\Connection\Event;

use OpenSwoole\WebSocket\Server;
use Symfony\Component\EventDispatcher\Event;

class SendMessageEvent extends Event
{
    public function __construct(public readonly array|string|null $data, public readonly int $opcode = Server::WEBSOCKET_OPCODE_TEXT)
    {
    }
}
