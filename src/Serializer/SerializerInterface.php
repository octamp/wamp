<?php

namespace Octamp\Wamp\Serializer;

use Thruway\Message\Message;

interface SerializerInterface
{
    public function serialize(Message $msg): string;

    public function deserialize(string $serializedData): Message;
}