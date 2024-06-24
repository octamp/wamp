<?php

namespace Octamp\Wamp\Helper;

use Octamp\Wamp\Serializer\JsonSerializer;
use Octamp\Wamp\Serializer\MessagePackSerializer;
use Octamp\Wamp\Serializer\WampMessageSerializerInterface;

class SerializerHelper
{
    public static function getSerializer(string $protocol): ?WampMessageSerializerInterface
    {
        if ($protocol === 'wamp.2.json') {
            return new JsonSerializer();
        }

        if ($protocol === 'wamp.2.msgpack') {
            return new MessagePackSerializer();
        }

        return null;
    }

    public static function supportedProtocols(): array
    {
        return [
            'wamp.2.msgpack',
            'wamp.2.json',
        ];
    }

    public static function getFirstSupportedProtocols(array $userProtocols): ?string
    {
        $supportedProtocols = static::supportedProtocols();
        foreach ($supportedProtocols as $protocol) {
            if (in_array($protocol, $userProtocols)) {
                return trim($protocol);
            }
        }

        return null;
    }
}