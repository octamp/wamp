<?php

namespace Octamp\Wamp\Transport;

use Octamp\Server\Connection\Connection;
use Thruway\Message\Message;

abstract  class AbstractTransport extends \Thruway\Transport\AbstractTransport
{
    abstract public function getId(): string;

    abstract public function getForGenerationId(): string;

    public function __construct(protected ?Connection $connection = null)
    {
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }
}