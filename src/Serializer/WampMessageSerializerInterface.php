<?php

namespace Octamp\Wamp\Serializer;

interface WampMessageSerializerInterface extends SerializerInterface
{
    public function protocolName(): string;

    public function opcode(): int;
}