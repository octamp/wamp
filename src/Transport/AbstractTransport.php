<?php

namespace Octamp\Wamp\Transport;

use Octamp\Client\Promise\Promise;
use Octamp\Server\Connection\Connection;
use Octamp\Wamp\Serializer\WampMessageSerializerInterface;
use OpenSwoole\WebSocket\Frame;
use Thruway\Exception\PingNotSupportedException;

abstract  class AbstractTransport
{
    protected ?WampMessageSerializerInterface $serializer = null;

    /*
     * @var boolean
     */
    protected bool $trusted = false;

    abstract public function getId(): string;

    abstract public function getForGenerationId(): string;

    public function ping(int $timeout = 10): ?Promise
    {
        throw new PingNotSupportedException();
    }

    public function onPong(Frame $frame): void
    {
    }

    public function __construct(protected ?Connection $connection = null)
    {
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function isTrusted(): bool
    {
        return $this->trusted;
    }

    public function setTrusted(bool $trusted): void
    {
        $this->trusted = $trusted;
    }

    public function setSerializer(WampMessageSerializerInterface $serializer): void
    {
        $this->serializer = $serializer;
    }

    public function getSerializer(): ?WampMessageSerializerInterface
    {
        return $this->serializer;
    }


    /**
     * Close transport
     */
    public function close()
    {

    }
}