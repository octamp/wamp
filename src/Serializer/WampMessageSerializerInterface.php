<?php

declare(strict_types=1);

namespace Octamp\Wamp\Serializer;

interface WampMessageSerializerInterface extends SerializerInterface
{
    public function protocolName(): string;

    public function opcode(): int;
}
