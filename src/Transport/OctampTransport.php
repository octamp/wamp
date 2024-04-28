<?php

namespace Octamp\Wamp\Transport;

use Octamp\Server\Connection\Connection;
use Thruway\Message\Message;

class OctampTransport extends AbstractTransport
{
    public function getTransportDetails(): array
    {
        return [
            'type' => 'octamp',
            'headers' => $this->connection->getRequest()->header,
            'server' => $this->connection->getRequest()->server,
        ];
    }

    public function sendMessage(Message $msg): void
    {
        $this->connection->send($this->getSerializer()->serialize($msg));
    }

    public function close(): void
    {
        $this->connection->close();
    }

    /**
     * @throws PingNotSupportedException
     */
    public function ping(): void
    {
        $this->connection->ping();
    }

    public function getId(): string
    {
        return $this->connection->getId();
    }

    public function getForGenerationId(): string
    {
        return $this->connection->getServerId();
    }
}