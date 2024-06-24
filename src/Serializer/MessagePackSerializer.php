<?php

namespace Octamp\Wamp\Serializer;

use MessagePack\MessagePack;
use MessagePack\Packer;
use OpenSwoole\WebSocket\Server;
use Thruway\Message\Message;

class MessagePackSerializer implements WampMessageSerializerInterface
{

    public function serialize(Message $msg): string
    {
        $data = json_encode($msg->getMessageParts());
        $data = json_decode($data, true);

        return MessagePack::pack($data);
    }

    public function deserialize(string $serializedData): Message
    {
        $data = MessagePack::unpack($serializedData);

        return Message::createMessageFromArray($data);
    }

    public function protocolName(): string
    {
        return 'wamp.2.msgpack';
    }

    public function opcode(): int
    {
        return Server::WEBSOCKET_OPCODE_BINARY;
    }
}