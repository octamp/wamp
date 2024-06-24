<?php

namespace Octamp\Wamp\Serializer;

use OpenSwoole\WebSocket\Server;
use Thruway\Message\Message;

class JsonSerializer implements WampMessageSerializerInterface
{
    public function serialize(Message $msg): string
    {
        return json_encode($msg);
    }

    public function deserialize(string $serializedData): Message
    {
        if (null === ($data = @json_decode($serializedData))) {
            throw new DeserializationException("Error decoding json \"" . $serializedData . "\"");
        }

        return Message::createMessageFromArray($data);
    }

    public function protocolName(): string
    {
        return 'wamp.2.json';
    }

    public function opcode(): int
    {
        return Server::WEBSOCKET_OPCODE_TEXT;
    }
}